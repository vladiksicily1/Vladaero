/**
 * VladInc Ecosystem Client Engine
 * Domain: vladinc.ru
 */

document.addEventListener('DOMContentLoaded', () => {
    initLauncher();
    initUserMenu();
    initTheme();
    initToasts();
    initLikeButtons();
    initPostComposer();
    initComments();
    initMessenger();
    initWallet();
    initArcade();
});

// 1. 9-Dots Ecosystem Launcher Grid
function initLauncher() {
    const btn = document.getElementById('launcher-btn');
    const dropdown = document.getElementById('launcher-dropdown');
    if (!btn || !dropdown) return;

    btn.addEventListener('click', (e) => {
        e.stopPropagation();
        dropdown.classList.toggle('open');
        const userMenu = document.getElementById('user-menu-dropdown');
        if (userMenu) userMenu.classList.remove('open');
    });

    document.addEventListener('click', (e) => {
        if (!dropdown.contains(e.target) && e.target !== btn) {
            dropdown.classList.remove('open');
        }
    });
}

// 2. User Menu Dropdown
function initUserMenu() {
    const btn = document.getElementById('user-menu-btn');
    const dropdown = document.getElementById('user-menu-dropdown');
    if (!btn || !dropdown) return;

    btn.addEventListener('click', (e) => {
        e.stopPropagation();
        dropdown.classList.toggle('open');
        const launcher = document.getElementById('launcher-dropdown');
        if (launcher) launcher.classList.remove('open');
    });

    document.addEventListener('click', (e) => {
        if (!dropdown.contains(e.target) && e.target !== btn) {
            dropdown.classList.remove('open');
        }
    });
}

// 3. Theme Toggle (Dark / Light)
function initTheme() {
    const btn = document.getElementById('theme-toggle-btn');
    const savedTheme = localStorage.getItem('vladinc_theme') || 'dark';
    document.documentElement.setAttribute('data-theme', savedTheme);

    if (btn) {
        btn.innerHTML = savedTheme === 'dark' ? '☀️' : '🌙';
        btn.addEventListener('click', () => {
            const current = document.documentElement.getAttribute('data-theme');
            const next = current === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            localStorage.setItem('vladinc_theme', next);
            btn.innerHTML = next === 'dark' ? '☀️' : '🌙';
            
            // Sync with backend if logged in
            fetch(baseUrl + '/api/index.php?action=set_theme', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ theme: next })
            }).catch(() => {});
        });
    }
}

// 4. Toast Notifications
function showToast(message, type = 'info') {
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    
    let icon = 'ℹ️';
    if (type === 'success') icon = '✅';
    if (type === 'error') icon = '❌';

    toast.innerHTML = `<span>${icon}</span> <span>${message}</span>`;
    container.appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(50px)';
        toast.style.transition = 'all 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}

function initToasts() {
    window.showToast = showToast;
}

// 5. Like Reactions
function initLikeButtons() {
    document.body.addEventListener('click', async (e) => {
        const btn = e.target.closest('.react-btn');
        if (!btn) return;

        const postId = btn.getAttribute('data-post-id');
        const countSpan = btn.querySelector('.like-count');
        const isLiked = btn.classList.contains('liked');

        // Optimistic UI update
        btn.classList.toggle('liked');
        let currentCount = parseInt(countSpan.innerText || '0', 10);
        countSpan.innerText = isLiked ? Math.max(0, currentCount - 1) : currentCount + 1;

        try {
            const res = await fetch(baseUrl + '/api/index.php?action=like_post', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ post_id: postId })
            });
            const data = await res.json();
            if (data.success) {
                countSpan.innerText = data.likes_count;
                if (data.is_liked) {
                    btn.classList.add('liked');
                } else {
                    btn.classList.remove('liked');
                }
            } else {
                showToast(data.error || 'Ошибка лайка', 'error');
            }
        } catch (err) {
            showToast('Сбой соединения', 'error');
        }
    });
}

// 6. Post Composer
function initPostComposer() {
    const form = document.getElementById('post-composer-form');
    if (!form) return;

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const textarea = form.querySelector('textarea[name="content"]');
        const content = textarea.value.trim();
        const mediaInput = form.querySelector('input[name="media"]');

        if (!content && (!mediaInput || !mediaInput.files.length)) {
            showToast('Напишите что-нибудь перед публикацией', 'error');
            return;
        }

        const fd = new FormData(form);
        const submitBtn = form.querySelector('button[type="submit"]');
        submitBtn.disabled = true;
        submitBtn.innerText = 'Публикация...';

        try {
            const res = await fetch(baseUrl + '/api/index.php?action=create_post', {
                method: 'POST',
                body: fd
            });
            const data = await res.json();
            if (data.success) {
                showToast('🎉 Опубликовано! +' + (data.reward_coins || 10) + ' VladCoins', 'success');
                textarea.value = '';
                if (mediaInput) mediaInput.value = '';
                const preview = document.getElementById('media-preview');
                if (preview) preview.innerHTML = '';
                
                // Prepend new post HTML to feed if available
                const feed = document.getElementById('feed-stream');
                if (feed && data.post_html) {
                    const temp = document.createElement('div');
                    temp.innerHTML = data.post_html;
                    feed.prepend(temp.firstElementChild);
                } else {
                    setTimeout(() => location.reload(), 800);
                }
            } else {
                showToast(data.error || 'Ошибка публикации', 'error');
            }
        } catch (err) {
            showToast('Сетевой сбой при отправке поста', 'error');
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerText = 'Опубликовать';
        }
    });
}

// 7. Comments
function initComments() {
    // Toggle comments box
    document.body.addEventListener('click', (e) => {
        const toggleBtn = e.target.closest('.toggle-comments-btn');
        if (!toggleBtn) return;
        const postId = toggleBtn.getAttribute('data-post-id');
        const box = document.getElementById('comments-box-' + postId);
        if (box) {
            box.style.display = (box.style.display === 'none' || !box.style.display) ? 'block' : 'none';
        }
    });

    // Submit comment
    document.body.addEventListener('submit', async (e) => {
        const form = e.target.closest('.comment-form');
        if (!form) return;
        e.preventDefault();

        const postId = form.getAttribute('data-post-id');
        const input = form.querySelector('input[name="content"]');
        const fileInput = form.querySelector('input[name="comment_media"]');
        const text = input ? input.value.trim() : '';
        const hasFile = fileInput && fileInput.files && fileInput.files.length > 0;

        if (!text && !hasFile) {
            showToast('Напишите комментарий или прикрепите фото', 'error');
            return;
        }

        const btn = form.querySelector('button[type="submit"]');
        if (btn) btn.disabled = true;

        try {
            let res;
            if (hasFile) {
                const fd = new FormData(form);
                fd.append('post_id', postId);
                res = await fetch(baseUrl + '/api/index.php?action=add_comment', {
                    method: 'POST',
                    body: fd
                });
            } else {
                res = await fetch(baseUrl + '/api/index.php?action=add_comment', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ post_id: postId, content: text })
                });
            }

            const data = await res.json();
            if (data.success) {
                if (input) input.value = '';
                if (fileInput) {
                    fileInput.value = '';
                    if (fileInput.parentElement) fileInput.parentElement.style.borderColor = 'var(--border-color)';
                }
                const list = document.getElementById('comments-list-' + postId);
                if (list && data.comment_html) {
                    list.insertAdjacentHTML('beforeend', data.comment_html);
                }
                const countEls = document.querySelectorAll(`.comments-count-${postId}`);
                countEls.forEach(el => el.innerText = data.comments_count);
                showToast('Комментарий добавлен', 'success');
            } else {
                showToast(data.error || 'Не удалось отправить', 'error');
            }
        } catch (err) {
            showToast('Ошибка сети', 'error');
        } finally {
            if (btn) btn.disabled = false;
        }
    });
}

// 8. Real-time Messenger Polling
let activeChatId = null;
let messengerInterval = null;

function initMessenger() {
    const chatContainer = document.getElementById('active-chat-window');
    if (!chatContainer) return;

    activeChatId = chatContainer.getAttribute('data-chat-id');
    const msgForm = document.getElementById('chat-send-form');
    const msgInput = document.getElementById('chat-message-input');
    const msgList = document.getElementById('chat-messages-scroll');

    function scrollToBottom() {
        if (msgList) msgList.scrollTop = msgList.scrollHeight;
    }
    scrollToBottom();

    // Send Message
    if (msgForm) {
        msgForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const text = msgInput.value.trim();
            if (!text || !activeChatId) return;

            msgInput.value = '';
            try {
                const res = await fetch(baseUrl + '/api/index.php?action=send_message', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ chat_id: activeChatId, message: text })
                });
                const data = await res.json();
                if (data.success && data.message_html) {
                    msgList.insertAdjacentHTML('beforeend', data.message_html);
                    scrollToBottom();
                } else if (!data.success) {
                    showToast(data.error || 'Ошибка отправки', 'error');
                }
            } catch (err) {
                showToast('Сбой сети при отправке сообщения', 'error');
            }
        });
    }

    // Polling for incoming messages every 3.5s
    if (activeChatId) {
        messengerInterval = setInterval(async () => {
            const lastMsgEl = msgList ? msgList.querySelector('.chat-msg:last-child') : null;
            const lastId = lastMsgEl ? lastMsgEl.getAttribute('data-msg-id') : 0;

            try {
                const res = await fetch(`${baseUrl}/api/index.php?action=get_new_messages&chat_id=${activeChatId}&last_id=${lastId}`);
                const data = await res.json();
                if (data.success && data.messages && data.messages.length) {
                    data.messages.forEach(m => {
                        msgList.insertAdjacentHTML('beforeend', m.html);
                    });
                    scrollToBottom();
                }
            } catch (err) {}
        }, 3500);
    }
}

// 9. Wallet & Daily Bonus
function initWallet() {
    // Transfer coins
    const transferForm = document.getElementById('transfer-coins-form');
    if (transferForm) {
        transferForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const targetUser = document.getElementById('transfer-recipient').value.trim();
            const amount = parseInt(document.getElementById('transfer-amount').value, 10);
            const note = document.getElementById('transfer-note').value.trim();

            if (!targetUser || !amount || amount <= 0) {
                showToast('Укажите корректного получателя и сумму', 'error');
                return;
            }

            try {
                const res = await fetch(baseUrl + '/api/index.php?action=transfer_coins', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ to_user: targetUser, amount: amount, note: note })
                });
                const data = await res.json();
                if (data.success) {
                    showToast(`🪙 Успешно переведено ${amount} VladCoins пользователю @${targetUser}!`, 'success');
                    setTimeout(() => location.reload(), 1200);
                } else {
                    showToast(data.error || 'Ошибка перевода', 'error');
                }
            } catch (err) {
                showToast('Сетевой сбой при переводе', 'error');
            }
        });
    }

    // Daily Bonus claim button
    const claimBtn = document.getElementById('claim-bonus-btn');
    if (claimBtn) {
        claimBtn.addEventListener('click', async () => {
            claimBtn.disabled = true;
            try {
                const res = await fetch(baseUrl + '/api/index.php?action=claim_daily_bonus', {
                    method: 'POST'
                });
                const data = await res.json();
                if (data.success) {
                    showToast(`🎁 Вы получили ежедневный бонус +${data.amount} VladCoins!`, 'success');
                    const balanceEl = document.getElementById('nav-user-coins');
                    if (balanceEl) balanceEl.innerText = data.new_balance;
                    claimBtn.innerText = '✓ Бонус получен на сегодня';
                } else {
                    showToast(data.error || 'Бонус уже получен', 'error');
                }
            } catch (err) {
                showToast('Ошибка связи с сервером', 'error');
            }
        });
    }
}

// 10. VladArcade Mini-Game / Miner
function initArcade() {
    const tapTarget = document.getElementById('arcade-tap-target');
    if (!tapTarget) return;

    const scoreDisplay = document.getElementById('arcade-score');
    let sessionCoins = 0;
    let pendingSync = 0;

    tapTarget.addEventListener('click', (e) => {
        sessionCoins++;
        pendingSync++;
        if (scoreDisplay) scoreDisplay.innerText = '+' + sessionCoins;

        // Visual click effect
        tapTarget.style.transform = 'scale(0.92)';
        setTimeout(() => { tapTarget.style.transform = 'scale(1)'; }, 100);

        // Float +1 coin animation
        const floatEl = document.createElement('div');
        floatEl.innerText = '+1 🪙';
        floatEl.style.position = 'absolute';
        floatEl.style.left = e.clientX + 'px';
        floatEl.style.top = e.clientY + 'px';
        floatEl.style.color = '#fbbf24';
        floatEl.style.fontWeight = '800';
        floatEl.style.pointerEvents = 'none';
        floatEl.style.zIndex = '9999';
        floatEl.style.transition = 'all 0.6s ease-out';
        document.body.appendChild(floatEl);

        requestAnimationFrame(() => {
            floatEl.style.transform = 'translateY(-40px)';
            floatEl.style.opacity = '0';
        });
        setTimeout(() => floatEl.remove(), 600);
    });

    // Batch sync clicks to backend every 4 seconds
    setInterval(async () => {
        if (pendingSync > 0) {
            const syncAmount = pendingSync;
            pendingSync = 0;
            try {
                const res = await fetch(baseUrl + '/api/index.php?action=arcade_tap', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ clicks: syncAmount })
                });
                const data = await res.json();
                if (data.success) {
                    const balanceEl = document.getElementById('nav-user-coins');
                    if (balanceEl) balanceEl.innerText = data.new_balance;
                }
            } catch (err) {}
        }
    }, 4000);
}

// 11. Multi-Photo Carousel Slider
function nextSlide(postId) {
    const track = document.getElementById('carousel-track-' + postId);
    if (!track) return;
    const slides = track.querySelectorAll('.carousel-slide');
    let currentIdx = parseInt(track.getAttribute('data-current-index') || '0', 10);
    currentIdx = (currentIdx + 1) % slides.length;
    updateCarousel(postId, track, currentIdx, slides.length);
}

function prevSlide(postId) {
    const track = document.getElementById('carousel-track-' + postId);
    if (!track) return;
    const slides = track.querySelectorAll('.carousel-slide');
    let currentIdx = parseInt(track.getAttribute('data-current-index') || '0', 10);
    currentIdx = (currentIdx - 1 + slides.length) % slides.length;
    updateCarousel(postId, track, currentIdx, slides.length);
}

function updateCarousel(postId, track, idx, total) {
    track.setAttribute('data-current-index', idx);
    track.style.transform = `translateX(-${idx * 100}%)`;
    const counter = document.getElementById('carousel-counter-' + postId);
    if (counter) counter.innerText = `${idx + 1} / ${total}`;
    const dots = document.querySelectorAll(`#carousel-indicators-${postId} .carousel-dot`);
    dots.forEach((d, i) => d.classList.toggle('active', i === idx));
}

// 12. Global Sticky Audio Player (VK Music)
let globalAudio = new Audio();
let isAudioPlaying = false;

function playAudioTrack(url, title, artist) {
    const playerEl = document.getElementById('global-audio-player');
    const titleEl = document.getElementById('gap-title');
    const artistEl = document.getElementById('gap-artist');
    const playBtn = document.getElementById('gap-play-btn');

    if (!playerEl) return;
    playerEl.classList.add('active');

    if (globalAudio.src !== url) {
        globalAudio.src = url;
    }

    if (titleEl) titleEl.innerText = title;
    if (artistEl) artistEl.innerText = artist;

    globalAudio.play().then(() => {
        isAudioPlaying = true;
        if (playBtn) playBtn.innerText = '⏸';
    }).catch(() => {});

    globalAudio.ontimeupdate = () => {
        const progressBar = document.getElementById('gap-progress');
        if (progressBar && globalAudio.duration) {
            const pct = (globalAudio.currentTime / globalAudio.duration) * 100;
            progressBar.style.width = pct + '%';
        }
    };

    globalAudio.onended = () => {
        isAudioPlaying = false;
        if (playBtn) playBtn.innerText = '▶';
    };
}

function toggleGlobalPlay() {
    const playBtn = document.getElementById('gap-play-btn');
    if (!globalAudio.src) return;
    if (isAudioPlaying) {
        globalAudio.pause();
        isAudioPlaying = false;
        if (playBtn) playBtn.innerText = '▶';
    } else {
        globalAudio.play();
        isAudioPlaying = true;
        if (playBtn) playBtn.innerText = '⏸';
    }
}

// 13. Voice Notes Recorder (Instagram Direct)
let mediaRecorder = null;
let audioChunks = [];
let recordInterval = null;
let recordSeconds = 0;

async function toggleVoiceRecording(chatId) {
    const recordBtn = document.getElementById('voice-record-btn');
    const timerLabel = document.getElementById('voice-timer-label');

    if (mediaRecorder && mediaRecorder.state === 'recording') {
        // Stop recording and send
        mediaRecorder.stop();
        clearInterval(recordInterval);
        if (recordBtn) recordBtn.style.color = 'inherit';
        if (timerLabel) timerLabel.style.display = 'none';
    } else {
        // Start recording
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            mediaRecorder = new MediaRecorder(stream);
            audioChunks = [];

            mediaRecorder.ondataavailable = (e) => {
                if (e.data.size > 0) audioChunks.push(e.data);
            };

            mediaRecorder.onstop = async () => {
                const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
                const fd = new FormData();
                fd.append('chat_id', chatId);
                fd.append('voice_audio', audioBlob, 'voice_' + Date.now() + '.webm');
                fd.append('duration', recordSeconds);

                try {
                    const res = await fetch(baseUrl + '/api/index.php?action=send_voice_message', {
                        method: 'POST',
                        body: fd
                    });
                    const data = await res.json();
                    if (data.success && data.message_html) {
                        const msgList = document.getElementById('chat-messages-scroll');
                        if (msgList) {
                            msgList.insertAdjacentHTML('beforeend', data.message_html);
                            msgList.scrollTop = msgList.scrollHeight;
                        }
                    } else {
                        showToast(data.error || 'Ошибка отправки голосового', 'error');
                    }
                } catch(e) {
                    showToast('Сетевой сбой при отправке голосового', 'error');
                }
            };

            mediaRecorder.start();
            recordSeconds = 0;
            if (recordBtn) recordBtn.style.color = '#ef4444';
            if (timerLabel) {
                timerLabel.style.display = 'inline';
                timerLabel.innerText = '0:00';
            }
            recordInterval = setInterval(() => {
                recordSeconds++;
                const mins = Math.floor(recordSeconds / 60);
                const secs = recordSeconds % 60;
                if (timerLabel) timerLabel.innerText = `${mins}:${secs < 10 ? '0' : ''}${secs}`;
            }, 1000);

        } catch (err) {
            showToast('Доступ к микрофону отклонен', 'error');
        }
    }
}

// 14. Virtual Gifts for VladCoins (VK Gifts)
async function sendGift(targetUserId, targetUsername, giftId, giftName, price) {
    const msg = prompt(`Отправить подарок «${giftName}» пользователю @${targetUsername} за ${price} VladCoins?\nНапишите пожелание (необязательно):`, "Отличного настроения!");
    if (msg === null) return;

    try {
        const res = await fetch(baseUrl + '/api/index.php?action=send_gift', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                user_id: targetUserId,
                gift_id: giftId,
                message: msg
            })
        });
        const data = await res.json();
        if (data.success) {
            showToast(`🎁 Вы подарили «${giftName}» пользователю @${targetUsername}!`, 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast(data.error || 'Ошибка отправки подарка', 'error');
        }
    } catch(e) {
        showToast('Ошибка сети', 'error');
    }
}

