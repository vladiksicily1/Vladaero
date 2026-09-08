/**
 * ShibaLingo - Interactive Lesson Engine (Duolingo Mechanics)
 */

class LessonEngine {
    constructor(lessonData, lessonId, xpReward, langCode, nextLesson = null) {
        this.questions = lessonData;
        this.lessonId = lessonId;
        this.xpReward = xpReward;
        this.langCode = langCode;
        this.nextLesson = nextLesson;
        this.currentIndex = 0;
        this.hearts = 5;
        this.totalQuestions = lessonData.length;
        this.selectedAnswer = null;
        this.wordBankSelection = [];
        this.matchPairsState = { selectedLeft: null, solvedCount: 0 };
        this.isAnswerChecked = false;
        
        this.init();
    }

    init() {
        this.renderQuestion();
        this.updateProgress();
        this.setupKeyboardShortcuts();
    }

    setupKeyboardShortcuts() {
        document.addEventListener('keydown', (e) => {
            const isTyping = (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA');

            if (e.key === 'Enter') {
                const feedbackBanner = document.getElementById('feedback-banner');
                const btnNext = document.getElementById('btn-next-step');
                const btnCheck = document.getElementById('btn-check-answer');

                if (feedbackBanner && feedbackBanner.classList.contains('show')) {
                    e.preventDefault();
                    if (btnNext) btnNext.click();
                } else if (btnCheck && !btnCheck.disabled) {
                    e.preventDefault();
                    btnCheck.click();
                }
                return;
            }

            if (!isTyping) {
                if (['1', '2', '3', '4'].includes(e.key)) {
                    const idx = parseInt(e.key) - 1;
                    const choices = document.querySelectorAll('.choice-card');
                    if (choices[idx]) {
                        choices[idx].click();
                    }
                }
                if (e.key === ' ' || e.code === 'Space') {
                    e.preventDefault();
                    const speakBtn = document.querySelector('.prompt-box button');
                    if (speakBtn) speakBtn.click();
                }
            }
        });
    }

    updateProgress() {
        const percent = (this.currentIndex / this.totalQuestions) * 100;
        const bar = document.getElementById('lesson-progress-fill');
        if (bar) bar.style.width = `${percent}%`;
    }

    renderQuestion() {
        const q = this.questions[this.currentIndex];
        this.selectedAnswer = null;
        this.wordBankSelection = [];
        this.isAnswerChecked = false;
        
        const container = document.getElementById('question-stage');
        const footerAction = document.getElementById('lesson-footer-action');
        const feedbackBanner = document.getElementById('feedback-banner');
        
        if (feedbackBanner) {
            feedbackBanner.className = 'feedback-banner';
        }
        
        if (footerAction) {
            footerAction.innerHTML = `
                <button class="btn-duo btn-primary" id="btn-check-answer" disabled style="width: 180px;">
                    Проверить
                </button>
            `;
            document.getElementById('btn-check-answer').addEventListener('click', () => this.checkAnswer());
        }

        // Mascot set thinking/idle
        ShibaMascot.update('lesson-shiba-mascot', 'idle');

        if (q.type === 'multiple_choice') {
            this.renderMultipleChoice(q, container);
        } else if (q.type === 'word_bank') {
            this.renderWordBank(q, container);
        } else if (q.type === 'match_pairs') {
            this.renderMatchPairs(q, container);
        } else if (q.type === 'translate') {
            this.renderTranslate(q, container);
        } else if (q.type === 'listening_dictation') {
            this.renderListeningDictation(q, container);
        } else if (q.type === 'speak') {
            this.renderSpeak(q, container);
        } else if (q.type === 'fill_blank' || q.type === 'tap_cloze') {
            this.renderFillBlank(q, container);
        } else if (q.type === 'listen_tap') {
            this.renderListenTap(q, container);
        } else if (q.type === 'find_error') {
            this.renderFindError(q, container);
        } else if (q.type === 'judge') {
            this.renderJudge(q, container);
        } else if (q.type === 'dialogue_fill') {
            this.renderDialogueFill(q, container);
        }
    }

    renderMultipleChoice(q, container) {
        let optionsHtml = '';
        q.options.forEach((opt, idx) => {
            optionsHtml += `
                <div class="choice-card" data-index="${idx}">
                    <span style="color: var(--text-muted); margin-right: 8px;">${idx + 1}.</span> ${opt}
                </div>
            `;
        });

        container.innerHTML = `
            <h2 class="question-title">${q.question}</h2>
            <div class="prompt-box">
                <div style="display: flex; gap: 8px;">
                    <button class="btn-duo btn-outline" style="padding: 10px 14px; border-radius: 50%;" onclick="speakText('${q.prompt}', '${this.langCode}', false)" title="Обычная скорость">
                        🔊
                    </button>
                    <button class="btn-duo btn-outline" style="padding: 10px 14px; border-radius: 50%; font-size: 1.1rem;" onclick="speakText('${q.prompt}', '${this.langCode}', true)" title="Замедленно (Черепаха 🐢)">
                        🐢
                    </button>
                </div>
                <div class="prompt-text">${q.prompt}</div>
            </div>
            <div class="choices-grid">
                ${optionsHtml}
            </div>
        `;

        container.querySelectorAll('.choice-card').forEach(card => {
            card.addEventListener('click', (e) => {
                if (this.isAnswerChecked) return;
                container.querySelectorAll('.choice-card').forEach(c => c.classList.remove('selected'));
                card.classList.add('selected');
                this.selectedAnswer = parseInt(card.dataset.index);
                document.getElementById('btn-check-answer').disabled = false;
                SoundEngine.play('click');
            });
        });
    }

    renderListeningDictation(q, container) {
        const specialChars = ['á', 'é', 'í', 'ó', 'ú', 'ž', 'š', 'ō', "'"];
        let charsHtml = specialChars.map(c => `<button type="button" class="badge-tag btn-char-insert" style="font-size: 1.1rem; padding: 6px 12px; cursor: pointer; border: 1px solid var(--border-color); background: var(--bg-card);">${c}</button>`).join(' ');

        container.innerHTML = `
            <h2 class="question-title">${q.question || 'Напишите услышанное:'}</h2>
            <div class="prompt-box" style="justify-content: center; padding: 24px;">
                <button class="btn-duo btn-primary" style="padding: 16px 24px; border-radius: 20px; font-size: 1.4rem; display: flex; align-items: center; gap: 10px;" onclick="speakText('${q.prompt}', '${this.langCode}', false)">
                    <span>🔊</span> <span>Прослушать</span>
                </button>
                <button class="btn-duo btn-outline" style="padding: 16px 20px; border-radius: 20px; font-size: 1.4rem;" onclick="speakText('${q.prompt}', '${this.langCode}', true)" title="Замедленно 🐢">
                    🐢
                </button>
            </div>
            <div style="margin-top: 16px;">
                <div style="display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 8px;">
                    <span style="font-size: 0.8rem; font-weight: 700; color: var(--text-muted); align-self: center;">Символы:</span>
                    ${charsHtml}
                </div>
                <textarea id="dictation-input" class="chat-input" rows="3" placeholder="Введите фразу на слух..." style="width: 100%; border-radius: 16px; font-size: 1.2rem;"></textarea>
            </div>
        `;

        // Automatically play on load
        setTimeout(() => speakText(q.prompt, this.langCode, false), 300);

        const textarea = document.getElementById('dictation-input');
        textarea.addEventListener('input', () => {
            document.getElementById('btn-check-answer').disabled = textarea.value.trim().length === 0;
        });

        container.querySelectorAll('.btn-char-insert').forEach(btn => {
            btn.addEventListener('click', () => {
                textarea.value += btn.textContent.trim();
                textarea.focus();
                document.getElementById('btn-check-answer').disabled = false;
            });
        });
    }

    renderWordBank(q, container) {
        let poolHtml = '';
        q.word_pool.forEach((word, idx) => {
            poolHtml += `<div class="word-tile" data-idx="${idx}" data-word="${word}">${word}</div>`;
        });

        container.innerHTML = `
            <h2 class="question-title">${q.question}</h2>
            <div class="prompt-box">
                <button class="btn-duo btn-outline" style="padding: 10px 14px; border-radius: 50%;" onclick="speakText('${q.prompt}', '${this.langCode}')">
                    🔊
                </button>
                <div class="prompt-text">${q.prompt}</div>
            </div>
            <div class="word-bank-answer-area" id="wb-answer-area">
                <span id="wb-placeholder" style="color: var(--text-sub); font-weight: 700;">Нажмите на слова, чтобы составить фразу</span>
            </div>
            <div class="word-bank-pool" id="wb-pool-area">
                ${poolHtml}
            </div>
        `;

        const answerArea = document.getElementById('wb-answer-area');
        const poolArea = document.getElementById('wb-pool-area');
        const placeholder = document.getElementById('wb-placeholder');

        poolArea.querySelectorAll('.word-tile').forEach(tile => {
            tile.addEventListener('click', () => {
                if (this.isAnswerChecked || tile.classList.contains('disabled')) return;
                
                tile.classList.add('disabled');
                const word = tile.dataset.word;
                const idx = tile.dataset.idx;
                
                placeholder.style.display = 'none';

                const answerTile = document.createElement('div');
                answerTile.className = 'word-tile anim-bounce';
                answerTile.textContent = word;
                answerTile.dataset.sourceIdx = idx;

                answerTile.addEventListener('click', () => {
                    if (this.isAnswerChecked) return;
                    answerTile.remove();
                    tile.classList.remove('disabled');
                    this.wordBankSelection = this.wordBankSelection.filter(w => w.idx !== idx);
                    
                    if (this.wordBankSelection.length === 0) {
                        placeholder.style.display = 'block';
                        document.getElementById('btn-check-answer').disabled = true;
                    }
                    SoundEngine.play('click');
                });

                answerArea.appendChild(answerTile);
                this.wordBankSelection.push({ word, idx });
                document.getElementById('btn-check-answer').disabled = false;
                SoundEngine.play('click');
            });
        });
    }

    renderMatchPairs(q, container) {
        let leftItems = q.pairs.map((p, i) => ({ text: p.left, id: i, type: 'left' }));
        let rightItems = q.pairs.map((p, i) => ({ text: p.right, id: i, type: 'right' }));
        
        // Shuffle right side
        rightItems.sort(() => Math.random() - 0.5);

        let pairsHtml = `
            <h2 class="question-title">${q.question}</h2>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 24px;">
                <div style="display: flex; flex-direction: column; gap: 12px;">
                    ${leftItems.map(item => `
                        <div class="choice-card pair-tile" data-type="left" data-id="${item.id}">
                            ${item.text}
                        </div>
                    `).join('')}
                </div>
                <div style="display: flex; flex-direction: column; gap: 12px;">
                    ${rightItems.map(item => `
                        <div class="choice-card pair-tile" data-type="right" data-id="${item.id}">
                            ${item.text}
                        </div>
                    `).join('')}
                </div>
            </div>
        `;

        container.innerHTML = pairsHtml;
        this.matchPairsState = { selectedLeft: null, solvedCount: 0, total: q.pairs.length };

        const allTiles = container.querySelectorAll('.pair-tile');
        allTiles.forEach(tile => {
            tile.addEventListener('click', () => {
                if (tile.classList.contains('matched') || this.isAnswerChecked) return;

                const type = tile.dataset.type;
                const id = tile.dataset.id;

                if (type === 'left') {
                    container.querySelectorAll('.pair-tile[data-type="left"]').forEach(t => t.classList.remove('selected'));
                    tile.classList.add('selected');
                    this.matchPairsState.selectedLeft = { el: tile, id: id };
                    SoundEngine.play('click');
                } else if (type === 'right' && this.matchPairsState.selectedLeft) {
                    if (this.matchPairsState.selectedLeft.id === id) {
                        // Correct Pair!
                        tile.classList.add('matched');
                        this.matchPairsState.selectedLeft.el.classList.add('matched');
                        tile.style.background = 'var(--primary-light)';
                        tile.style.borderColor = 'var(--primary)';
                        this.matchPairsState.selectedLeft.el.style.background = 'var(--primary-light)';
                        this.matchPairsState.selectedLeft.el.style.borderColor = 'var(--primary)';
                        
                        this.matchPairsState.selectedLeft = null;
                        this.matchPairsState.solvedCount++;
                        SoundEngine.play('correct');

                        if (this.matchPairsState.solvedCount === this.matchPairsState.total) {
                            document.getElementById('btn-check-answer').disabled = false;
                            this.checkAnswer(true);
                        }
                    } else {
                        // Wrong Match
                        SoundEngine.play('wrong');
                        tile.classList.add('anim-shake');
                        this.matchPairsState.selectedLeft.el.classList.add('anim-shake');
                        setTimeout(() => {
                            tile.classList.remove('anim-shake', 'selected');
                            if (this.matchPairsState.selectedLeft) {
                                this.matchPairsState.selectedLeft.el.classList.remove('anim-shake', 'selected');
                                this.matchPairsState.selectedLeft = null;
                            }
                        }, 500);
                    }
                }
            });
        });
    }

    renderTranslate(q, container) {
        const specialChars = ['á', 'é', 'í', 'ó', 'ú', 'ž', 'š', 'ō', "'"];
        let charsHtml = specialChars.map(c => `<button type="button" class="badge-tag btn-char-insert" style="font-size: 1.1rem; padding: 6px 12px; cursor: pointer; border: 1px solid var(--border-color); background: var(--bg-card);">${c}</button>`).join(' ');

        container.innerHTML = `
            <h2 class="question-title">${q.question}</h2>
            <div class="prompt-box">
                <button class="btn-duo btn-outline" style="padding: 10px 14px; border-radius: 50%;" onclick="speakText('${q.prompt}', '${this.langCode}')">
                    🔊
                </button>
                <div class="prompt-text">${q.prompt}</div>
            </div>
            <div style="margin-top: 16px;">
                <div style="display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 8px;">
                    <span style="font-size: 0.8rem; font-weight: 700; color: var(--text-muted); align-self: center;">Символы:</span>
                    ${charsHtml}
                </div>
                <textarea id="translate-input" class="chat-input" rows="3" placeholder="Введите перевод..." style="width: 100%; border-radius: 16px; font-size: 1.2rem;"></textarea>
            </div>
        `;

        const textarea = document.getElementById('translate-input');
        textarea.addEventListener('input', () => {
            document.getElementById('btn-check-answer').disabled = textarea.value.trim().length === 0;
        });

        container.querySelectorAll('.btn-char-insert').forEach(btn => {
            btn.addEventListener('click', () => {
                textarea.value += btn.textContent.trim();
                textarea.focus();
                document.getElementById('btn-check-answer').disabled = false;
            });
        });
    }

    // -------------------------------------------------------------------------
    // 1. Speak Exercise (Speech Recognition)
    // -------------------------------------------------------------------------
    renderSpeak(q, container) {
        this.spokenText = '';
        const targetText = q.prompt || q.target_text || '';

        container.innerHTML = `
            <h2 class="question-title">${q.question || 'Произнесите фразу в микрофон:'}</h2>
            <div class="prompt-box" style="justify-content: center; padding: 20px;">
                <button class="btn-duo btn-outline" style="padding: 10px 14px; border-radius: 50%;" onclick="speakText('${targetText}', '${this.langCode}')">
                    🔊
                </button>
                <div class="prompt-text" style="font-size: 1.5rem;">${targetText}</div>
            </div>
            
            <div style="text-align: center; margin: 30px 0;">
                <button type="button" class="speak-mic-btn" id="btn-speak-record" title="Нажмите, чтобы говорить">
                    🎙️
                </button>
                <div id="speak-status-text" style="margin-top: 14px; font-weight: 800; color: var(--text-muted);">
                    Нажмите на микрофон и произнесите фразу
                </div>
                <div id="spoken-transcript-box" style="margin-top: 12px; font-size: 1.2rem; font-weight: 800; min-height: 28px; color: var(--secondary);"></div>
            </div>

            <div style="text-align: center; margin-top: 16px;">
                <button type="button" class="btn-duo btn-outline" id="btn-skip-speak" style="font-size: 0.85rem; padding: 6px 14px;">
                    🔇 Не могу говорить сейчас
                </button>
            </div>
        `;

        const micBtn = document.getElementById('btn-speak-record');
        const statusText = document.getElementById('speak-status-text');
        const transcriptBox = document.getElementById('spoken-transcript-box');
        const skipBtn = document.getElementById('btn-skip-speak');

        skipBtn.addEventListener('click', () => {
            this.checkAnswer(true); // Pass without penalty
        });

        const SpeechRec = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SpeechRec) {
            statusText.textContent = 'Распознавание речи не поддерживается браузером. Нажмите «Проверить» или используйте Chrome/Edge.';
            document.getElementById('btn-check-answer').disabled = false;
            this.spokenText = targetText;
            return;
        }

        const recognition = new SpeechRec();
        recognition.lang = (this.langCode === 'ru') ? 'ru-RU' : (this.langCode === 'en' ? 'en-US' : 'it-IT');
        recognition.interimResults = true;
        recognition.continuous = false;

        let isRecording = false;

        micBtn.addEventListener('click', () => {
            if (isRecording) {
                recognition.stop();
                return;
            }
            try {
                recognition.start();
                isRecording = true;
                micBtn.classList.add('recording');
                statusText.textContent = 'Слушаю... Говорите!';
                statusText.style.color = 'var(--danger)';
            } catch (err) {
                console.error(err);
            }
        });

        recognition.onresult = (event) => {
            const transcript = Array.from(event.results).map(r => r[0].transcript).join('');
            this.spokenText = transcript;
            transcriptBox.textContent = `«${transcript}»`;
            document.getElementById('btn-check-answer').disabled = false;
        };

        recognition.onend = () => {
            isRecording = false;
            micBtn.classList.remove('recording');
            statusText.textContent = 'Запись завершена. Нажмите «Проверить»!';
            statusText.style.color = 'var(--primary-shadow)';
        };

        recognition.onerror = (e) => {
            isRecording = false;
            micBtn.classList.remove('recording');
            statusText.textContent = 'Микрофон не распознал звук. Попробуйте еще раз!';
            statusText.style.color = 'var(--danger)';
        };
    }

    // -------------------------------------------------------------------------
    // 2. Fill-in-the-Blank (Cloze)
    // -------------------------------------------------------------------------
    renderFillBlank(q, container) {
        this.selectedAnswer = null;
        const sentence = q.sentence || `${q.before_text || ''} ___ ${q.after_text || ''}`;
        const parts = sentence.split('___');

        let optionsHtml = '';
        (q.options || []).forEach((opt, idx) => {
            optionsHtml += `<button type="button" class="btn-duo btn-outline cloze-word-btn" data-word="${opt}" data-idx="${idx}" style="font-size: 1.1rem; padding: 10px 18px;">${opt}</button>`;
        });

        container.innerHTML = `
            <h2 class="question-title">${q.question || 'Заполните пропуск в предложении:'}</h2>
            <div class="cloze-sentence-box">
                <span>${parts[0] || ''}</span>
                <span class="cloze-blank-slot" id="cloze-slot">___</span>
                <span>${parts[1] || ''}</span>
            </div>
            ${q.translation ? `<div style="color: var(--text-muted); font-size: 0.95rem; margin-bottom: 20px; font-weight: 700;">Перевод: «${q.translation}»</div>` : ''}
            <div style="display: flex; gap: 10px; flex-wrap: wrap; justify-content: center;">
                ${optionsHtml}
            </div>
        `;

        const slot = document.getElementById('cloze-slot');
        const btns = container.querySelectorAll('.cloze-word-btn');

        btns.forEach(btn => {
            btn.addEventListener('click', () => {
                if (this.isAnswerChecked) return;
                btns.forEach(b => b.classList.remove('btn-primary'));
                btns.forEach(b => b.classList.add('btn-outline'));
                
                btn.classList.remove('btn-outline');
                btn.classList.add('btn-primary');

                this.selectedAnswer = btn.dataset.word;
                slot.textContent = btn.dataset.word;
                slot.classList.add('filled');
                document.getElementById('btn-check-answer').disabled = false;
                SoundEngine.play('click');
            });
        });
    }

    // -------------------------------------------------------------------------
    // 3. Listen & Tap (Blind Listening Builder)
    // -------------------------------------------------------------------------
    renderListenTap(q, container) {
        let poolHtml = '';
        (q.word_pool || []).forEach((word, idx) => {
            poolHtml += `<div class="word-tile" data-idx="${idx}" data-word="${word}">${word}</div>`;
        });

        container.innerHTML = `
            <h2 class="question-title">${q.question || 'Соберите услышанное предложение:'}</h2>
            <div class="prompt-box" style="justify-content: center; padding: 20px;">
                <button class="btn-duo btn-primary" style="padding: 14px 22px; border-radius: 18px; font-size: 1.3rem; display: flex; align-items: center; gap: 8px;" onclick="speakText('${q.prompt}', '${this.langCode}', false)">
                    <span>🔊</span> <span>Прослушать</span>
                </button>
                <button class="btn-duo btn-outline" style="padding: 14px 18px; border-radius: 18px; font-size: 1.3rem;" onclick="speakText('${q.prompt}', '${this.langCode}', true)" title="Замедленно 🐢">
                    🐢
                </button>
            </div>
            <div class="word-bank-answer-area" id="wb-answer-area">
                <span id="wb-placeholder" style="color: var(--text-sub); font-weight: 700;">Нажмите на слова, чтобы собрать фразу на слух</span>
            </div>
            <div class="word-bank-pool" id="wb-pool-area">
                ${poolHtml}
            </div>
        `;

        // Auto play
        setTimeout(() => speakText(q.prompt, this.langCode, false), 300);

        const answerArea = document.getElementById('wb-answer-area');
        const poolArea = document.getElementById('wb-pool-area');
        const placeholder = document.getElementById('wb-placeholder');

        poolArea.querySelectorAll('.word-tile').forEach(tile => {
            tile.addEventListener('click', () => {
                if (this.isAnswerChecked || tile.classList.contains('disabled')) return;
                
                tile.classList.add('disabled');
                const word = tile.dataset.word;
                const idx = tile.dataset.idx;
                
                placeholder.style.display = 'none';

                const answerTile = document.createElement('div');
                answerTile.className = 'word-tile anim-bounce';
                answerTile.textContent = word;
                answerTile.dataset.sourceIdx = idx;

                answerTile.addEventListener('click', () => {
                    if (this.isAnswerChecked) return;
                    answerTile.remove();
                    tile.classList.remove('disabled');
                    this.wordBankSelection = this.wordBankSelection.filter(w => w.idx !== idx);
                    
                    if (this.wordBankSelection.length === 0) {
                        placeholder.style.display = 'block';
                        document.getElementById('btn-check-answer').disabled = true;
                    }
                    SoundEngine.play('click');
                });

                answerArea.appendChild(answerTile);
                this.wordBankSelection.push({ word, idx });
                document.getElementById('btn-check-answer').disabled = false;
                SoundEngine.play('click');
            });
        });
    }

    // -------------------------------------------------------------------------
    // 4. Find Error in Sentence
    // -------------------------------------------------------------------------
    renderFindError(q, container) {
        this.selectedAnswer = null;
        const words = q.sentence_words || (q.sentence ? q.sentence.split(/\s+/) : []);

        let tokensHtml = '';
        words.forEach((w, idx) => {
            tokensHtml += `<div class="error-word-token" data-word="${w}" data-idx="${idx}">${w}</div>`;
        });

        container.innerHTML = `
            <h2 class="question-title">${q.question || 'Найдите и нажмите на ошибочное слово в предложении:'}</h2>
            <div class="find-error-sentence">
                ${tokensHtml}
            </div>
            ${q.translation ? `<div style="color: var(--text-muted); font-size: 0.95rem; text-align: center; font-weight: 700;">Правильный смысл: «${q.translation}»</div>` : ''}
        `;

        container.querySelectorAll('.error-word-token').forEach(token => {
            token.addEventListener('click', () => {
                if (this.isAnswerChecked) return;
                container.querySelectorAll('.error-word-token').forEach(t => t.classList.remove('selected'));
                token.classList.add('selected');
                this.selectedAnswer = token.dataset.word;
                this.selectedAnswerIdx = parseInt(token.dataset.idx);
                document.getElementById('btn-check-answer').disabled = false;
                SoundEngine.play('click');
            });
        });
    }

    // -------------------------------------------------------------------------
    // 5. Judge (True / False Statement)
    // -------------------------------------------------------------------------
    renderJudge(q, container) {
        this.selectedAnswer = null;

        container.innerHTML = `
            <h2 class="question-title">${q.question || 'Оцените утверждение:'}</h2>
            <div class="prompt-box">
                <button class="btn-duo btn-outline" style="padding: 10px 14px; border-radius: 50%;" onclick="speakText('${q.prompt}', '${this.langCode}')">
                    🔊
                </button>
                <div class="prompt-text">${q.prompt}</div>
            </div>
            <div style="font-size: 1.15rem; font-weight: 800; text-align: center; margin: 18px 0; color: var(--text-main);">
                «${q.statement}»
            </div>
            <div class="judge-cards-grid">
                <div class="judge-card" data-val="1">
                    <span style="font-size: 2rem;">👍</span>
                    <span>Правда / Да</span>
                </div>
                <div class="judge-card" data-val="0">
                    <span style="font-size: 2rem;">👎</span>
                    <span>Ложь / Нет</span>
                </div>
            </div>
        `;

        container.querySelectorAll('.judge-card').forEach(card => {
            card.addEventListener('click', () => {
                if (this.isAnswerChecked) return;
                container.querySelectorAll('.judge-card').forEach(c => c.classList.remove('selected'));
                card.classList.add('selected');
                this.selectedAnswer = parseInt(card.dataset.val);
                document.getElementById('btn-check-answer').disabled = false;
                SoundEngine.play('click');
            });
        });
    }

    // -------------------------------------------------------------------------
    // 6. Dialogue Fill (In-Lesson Conversation)
    // -------------------------------------------------------------------------
    renderDialogueFill(q, container) {
        this.selectedAnswer = null;
        let optionsHtml = '';
        (q.options || []).forEach((opt, idx) => {
            optionsHtml += `
                <div class="choice-card" data-index="${idx}">
                    <span style="color: var(--text-muted); margin-right: 8px;">${idx + 1}.</span> ${opt}
                </div>
            `;
        });

        container.innerHTML = `
            <h2 class="question-title">${q.question || 'Выберите подходящую реплику в диалоге:'}</h2>
            <div class="dialogue-bubble-box">
                <div style="font-size: 2.4rem;">${q.speaker_avatar || '🐕'}</div>
                <div>
                    <div style="font-weight: 800; font-size: 0.9rem; color: var(--text-muted); margin-bottom: 4px;">${q.speaker_name || 'Сиба'}:</div>
                    <div style="font-size: 1.25rem; font-weight: 800; color: var(--text-main);">${q.prompt}</div>
                    ${q.prompt_translation ? `<div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 4px;">«${q.prompt_translation}»</div>` : ''}
                </div>
                <button class="btn-duo btn-outline" style="padding: 8px 12px; border-radius: 50%; margin-left: auto;" onclick="speakText('${q.prompt}', '${this.langCode}')">
                    🔊
                </button>
            </div>
            <div class="choices-grid">
                ${optionsHtml}
            </div>
        `;

        container.querySelectorAll('.choice-card').forEach(card => {
            card.addEventListener('click', () => {
                if (this.isAnswerChecked) return;
                container.querySelectorAll('.choice-card').forEach(c => c.classList.remove('selected'));
                card.classList.add('selected');
                this.selectedAnswer = parseInt(card.dataset.index);
                document.getElementById('btn-check-answer').disabled = false;
                SoundEngine.play('click');
            });
        });
    }

    checkAnswer(isAutoPass = false) {
        if (this.isAnswerChecked) return;
        this.isAnswerChecked = true;

        const q = this.questions[this.currentIndex];
        let isCorrect = false;
        let explanation = q.explanation || '';

        if (isAutoPass) {
            isCorrect = true;
        } else if (q.type === 'multiple_choice' || q.type === 'dialogue_fill') {
            isCorrect = (this.selectedAnswer === q.correct);
            if (!isCorrect) {
                explanation = `Правильный ответ: «${q.options[q.correct]}». ` + explanation;
            }
        } else if (q.type === 'word_bank' || q.type === 'listen_tap') {
            const userSeq = this.wordBankSelection.map(w => w.word).join(' ');
            const correctSeq = q.correct_sequence.join(' ');
            isCorrect = (userSeq.toLowerCase().replace(/[.,!]/g, '') === correctSeq.toLowerCase().replace(/[.,!]/g, ''));
            if (!isCorrect) {
                explanation = `Правильный порядок: «${correctSeq}». ` + explanation;
            }
        } else if (q.type === 'translate') {
            const inputVal = document.getElementById('translate-input').value.trim().toLowerCase();
            isCorrect = (q.correct_answers || []).some(ans => ans.toLowerCase() === inputVal);
            if (!isCorrect) {
                explanation = `Один из правильных переводов: «${(q.correct_answers || [])[0] || ''}». ` + explanation;
            }
        } else if (q.type === 'listening_dictation') {
            const inputVal = document.getElementById('dictation-input').value.trim().toLowerCase().replace(/[.,!]/g, '');
            const targetPrompt = q.prompt.trim().toLowerCase().replace(/[.,!]/g, '');
            isCorrect = (inputVal === targetPrompt);
            if (!isCorrect) {
                explanation = `Правильно: «${q.prompt}». ` + explanation;
            }
        } else if (q.type === 'speak') {
            const target = (q.prompt || q.target_text || '').toLowerCase().replace(/[.,!]/g, '');
            const spoken = (this.spokenText || '').toLowerCase().replace(/[.,!]/g, '');
            // Fuzzy similarity check
            isCorrect = (spoken.length > 0 && (spoken === target || target.includes(spoken) || spoken.includes(target)));
            if (!isCorrect) {
                explanation = `Вы сказали: «${this.spokenText || '(тишина)'}». Нужно было: «${q.prompt}». ` + explanation;
            }
        } else if (q.type === 'fill_blank' || q.type === 'tap_cloze') {
            const targetWord = q.correct_word || q.correct_answer || (q.options ? q.options[q.correct || 0] : '');
            isCorrect = (this.selectedAnswer && this.selectedAnswer.toLowerCase() === targetWord.toLowerCase());
            if (!isCorrect) {
                explanation = `Правильно: «${targetWord}». ` + explanation;
            }
        } else if (q.type === 'find_error') {
            const errorWord = (q.error_word || (q.sentence_words ? q.sentence_words[q.error_index || 0] : '')).toLowerCase();
            isCorrect = (this.selectedAnswer && this.selectedAnswer.toLowerCase() === errorWord);
            if (!isCorrect) {
                explanation = `Ошибочное слово было «${errorWord}». ` + explanation;
            }
        } else if (q.type === 'judge') {
            const correctVal = (q.is_true === true || q.correct === 1 || q.correct === true) ? 1 : 0;
            isCorrect = (this.selectedAnswer === correctVal);
            if (!isCorrect) {
                explanation = (correctVal === 1 ? 'Это утверждение было правдивым.' : 'Это утверждение было ложным.') + ' ' + explanation;
            }
        }

        this.showFeedback(isCorrect, explanation);
    }

    showFeedback(isCorrect, explanation) {
        const banner = document.getElementById('feedback-banner');
        const title = document.getElementById('feedback-title');
        const desc = document.getElementById('feedback-desc');
        const btnNext = document.getElementById('btn-next-step');

        if (isCorrect) {
            SoundEngine.play('correct');
            banner.className = 'feedback-banner correct show';
            title.innerHTML = '🎉 Отлично! Верно!';
            title.style.color = 'var(--primary-shadow)';
            desc.textContent = explanation;
            ShibaMascot.update('lesson-shiba-mascot', 'happy', 'Отличная работа! Гав!');
        } else {
            SoundEngine.play('wrong');
            this.hearts = Math.max(0, this.hearts - 1);
            this.updateHeartsUI();

            banner.className = 'feedback-banner wrong show';
            title.innerHTML = '💔 Не совсем так...';
            title.style.color = 'var(--danger-shadow)';
            desc.textContent = explanation;
            ShibaMascot.update('lesson-shiba-mascot', 'sad', 'Ничего страшного, на ошибках учатся!');

            if (this.hearts === 0) {
                setTimeout(() => {
                    if (typeof openHeartsModal === 'function') {
                        openHeartsModal();
                    } else {
                        alert('У вас закончились сердца ❤️! Потренируйтесь, чтобы восстановить их.');
                        window.location.href = 'practice.php';
                    }
                }, 800);
            }
        }

        btnNext.onclick = () => {
            banner.className = 'feedback-banner';
            this.nextQuestion();
        };
    }

    updateHeartsUI() {
        const heartsBadge = document.getElementById('lesson-hearts-badge');
        if (heartsBadge) {
            heartsBadge.textContent = `❤️ ${this.hearts}`;
            heartsBadge.classList.add('anim-pulse');
            setTimeout(() => heartsBadge.classList.remove('anim-pulse'), 400);
        }
    }

    nextQuestion() {
        this.currentIndex++;
        this.updateProgress();

        if (this.currentIndex < this.totalQuestions) {
            this.renderQuestion();
        } else {
            this.finishLesson();
        }
    }

    async finishLesson() {
        SoundEngine.play('win');
        triggerConfetti();

        const stage = document.getElementById('question-stage');
        const footer = document.querySelector('.lesson-footer');
        if (footer) footer.style.display = 'none';

        let nextActionHtml = '';
        if (this.nextLesson) {
            const badgeText = this.nextLesson.is_same_skill 
                ? '🐾 Следующее занятие темы' 
                : '🏆 Следующий раздел: ' + this.nextLesson.skill_title;

            nextActionHtml = `
                <a href="lesson.php?id=${this.nextLesson.id}" class="btn-duo btn-primary anim-bounce" style="font-size: 1.15rem; padding: 16px 36px; display: inline-flex; align-items: center; justify-content: center; gap: 12px; text-decoration: none; max-width: 480px; width: 100%;">
                    <span style="font-size: 1.6rem;">🐾</span>
                    <div style="text-align: left;">
                        <div style="font-size: 0.75rem; text-transform: uppercase; font-weight: 900; opacity: 0.9;">${badgeText}</div>
                        <div style="font-weight: 900; font-size: 1.15rem;">${this.nextLesson.title} →</div>
                    </div>
                </a>
            `;
        } else {
            nextActionHtml = `
                <a href="index.php" class="btn-duo btn-primary" style="font-size: 1.2rem; padding: 16px 48px; text-decoration: none;">
                    🎉 Весь раздел пройден! В меню
                </a>
            `;
        }

        stage.innerHTML = `
            <div style="text-align: center; padding: 30px 20px;" class="anim-bounce">
                <div id="finish-shiba" style="margin-bottom: 16px;"></div>
                <h1 style="font-size: 2.2rem; font-weight: 900; color: var(--primary); margin-bottom: 8px;">
                    Урок успешно завершен! 🎉
                </h1>
                <p style="font-size: 1.1rem; color: var(--text-muted); margin-bottom: 24px;">
                    Вы сделали отличный шаг в изучении языка!
                </p>

                <div style="display: flex; justify-content: center; gap: 20px; margin-bottom: 30px; flex-wrap: wrap;">
                    <div class="card-duo" style="padding: 16px 28px; text-align: center; margin-bottom: 0;">
                        <div style="font-size: 1.8rem; font-weight: 900; color: #eab308;">+${this.xpReward}</div>
                        <div style="font-weight: 700; color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase;">Очков XP</div>
                    </div>
                    <div class="card-duo" style="padding: 16px 28px; text-align: center; margin-bottom: 0;">
                        <div style="font-size: 1.8rem; font-weight: 900; color: var(--streak-color);">🔥 100%</div>
                        <div style="font-weight: 700; color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase;">Точность</div>
                    </div>
                </div>

                <div style="display: flex; flex-direction: column; align-items: center; gap: 12px; max-width: 480px; margin: 0 auto;">
                    ${nextActionHtml}
                    <div style="display: flex; gap: 10px; width: 100%; justify-content: center;">
                        <a href="lesson.php?id=${this.lessonId}" class="btn-duo btn-outline" style="font-size: 0.9rem; padding: 10px 16px; text-decoration: none; flex: 1; text-align: center;">
                            🔄 Повторить урок
                        </a>
                        <a href="index.php" class="btn-duo btn-outline" style="font-size: 0.9rem; padding: 10px 16px; text-decoration: none; flex: 1; text-align: center;">
                            🏠 Дерево навыков
                        </a>
                    </div>
                </div>
            </div>
        `;

        ShibaMascot.update('finish-shiba', 'celebrate', 'Ты просто супер! Держи лапу! 🐾');

        // Save progress to database
        try {
            await fetch('api/lesson_complete.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    lesson_id: this.lessonId,
                    xp: this.xpReward
                })
            });
        } catch (e) {
            console.error('Failed to save progress', e);
        }
    }
}

window.LessonEngine = LessonEngine;
