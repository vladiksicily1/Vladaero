/**
 * ShibaLingo - Vladikish Conlang Studio (Manual & AI Word/Grammar Generator)
 */

document.addEventListener('DOMContentLoaded', () => {
    // Live Search in Dictionary
    const searchInput = document.getElementById('dict-search-input');
    if (searchInput) {
        searchInput.addEventListener('input', (e) => {
            const query = e.target.value.toLowerCase().trim();
            document.querySelectorAll('.dict-row').forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(query) ? '' : 'none';
            });
        });
    }

    // AI Generator Modal Handlers
    const btnAiGenerate = document.getElementById('btn-open-ai-words');
    const aiModal = document.getElementById('modal-ai-words');
    const btnCloseModal = document.getElementById('btn-close-ai-modal');
    const btnSubmitAi = document.getElementById('btn-run-ai-generator');

    if (btnAiGenerate && aiModal) {
        btnAiGenerate.addEventListener('click', () => {
            aiModal.style.display = 'flex';
        });

        if (btnCloseModal) {
            btnCloseModal.addEventListener('click', () => {
                aiModal.style.display = 'none';
            });
        }

        if (btnSubmitAi) {
            btnSubmitAi.addEventListener('click', async () => {
                const topic = document.getElementById('ai-word-topic').value.trim();
                const count = document.getElementById('ai-word-count').value;
                const statusDiv = document.getElementById('ai-gen-status');

                if (!topic) {
                    alert('Пожалуйста, введите тему для новых слов!');
                    return;
                }

                btnSubmitAi.disabled = true;
                statusDiv.style.display = 'block';
                statusDiv.innerHTML = `
                    <div style="color: var(--secondary); font-weight: 700;">
                        🤖 NVIDIA AI генерирует новые слова для Vladikish по теме «${topic}»...
                    </div>
                `;

                try {
                    const res = await fetch('api/ai.php?action=generate_words', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ topic, count })
                    });

                    const data = await res.json();
                    if (data.success) {
                        SoundEngine.play('win');
                        triggerConfetti();
                        statusDiv.innerHTML = `
                            <div style="color: var(--primary); font-weight: 800;">
                                🎉 Успешно добавлено ${data.added_count} новых слов в словарь Vladikish!
                            </div>
                        `;
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        SoundEngine.play('wrong');
                        statusDiv.innerHTML = `
                            <div style="color: var(--danger); font-weight: 700;">
                                Ошибка: ${data.error}
                            </div>
                        `;
                        btnSubmitAi.disabled = false;
                    }
                } catch (e) {
                    SoundEngine.play('wrong');
                    statusDiv.innerHTML = `<div style="color: var(--danger);">Ошибка запроса к серверу.</div>`;
                    btnSubmitAi.disabled = false;
                }
            });
        }
    }
});
