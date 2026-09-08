/**
 * ShibaLingo - Core Application Logic & Audio Engine
 */

// Sound Engine using Web Audio API (Zero external MP3 dependencies)
const SoundEngine = {
    ctx: null,
    
    init() {
        if (!this.ctx) {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (AudioCtx) this.ctx = new AudioCtx();
        }
    },

    play(type) {
        try {
            this.init();
            if (!this.ctx) return;
            if (this.ctx.state === 'suspended') {
                this.ctx.resume();
            }

            const now = this.ctx.currentTime;

            if (type === 'correct') {
                // Happy ascending chords (C5 -> E5 -> G5)
                const notes = [523.25, 659.25, 783.99];
                notes.forEach((freq, i) => {
                    const osc = this.ctx.createOscillator();
                    const gain = this.ctx.createGain();
                    osc.type = 'triangle';
                    osc.frequency.setValueAtTime(freq, now + i * 0.08);
                    
                    gain.gain.setValueAtTime(0.18, now + i * 0.08);
                    gain.gain.exponentialRampToValueAtTime(0.001, now + i * 0.08 + 0.35);
                    
                    osc.connect(gain);
                    gain.connect(this.ctx.destination);
                    osc.start(now + i * 0.08);
                    osc.stop(now + i * 0.08 + 0.4);
                });
            } else if (type === 'wrong') {
                // Sad descending buzz (F3 -> C#3)
                const osc = this.ctx.createOscillator();
                const gain = this.ctx.createGain();
                osc.type = 'sawtooth';
                osc.frequency.setValueAtTime(180, now);
                osc.frequency.linearRampToValueAtTime(110, now + 0.3);
                
                gain.gain.setValueAtTime(0.2, now);
                gain.gain.exponentialRampToValueAtTime(0.001, now + 0.35);
                
                osc.connect(gain);
                gain.connect(this.ctx.destination);
                osc.start(now);
                osc.stop(now + 0.4);
            } else if (type === 'click') {
                const osc = this.ctx.createOscillator();
                const gain = this.ctx.createGain();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(800, now);
                gain.gain.setValueAtTime(0.08, now);
                gain.gain.exponentialRampToValueAtTime(0.001, now + 0.05);
                osc.connect(gain);
                gain.connect(this.ctx.destination);
                osc.start(now);
                osc.stop(now + 0.06);
            } else if (type === 'win') {
                // Victory Fanfare
                const notes = [523.25, 659.25, 783.99, 1046.50];
                notes.forEach((freq, i) => {
                    const osc = this.ctx.createOscillator();
                    const gain = this.ctx.createGain();
                    osc.type = 'triangle';
                    osc.frequency.setValueAtTime(freq, now + i * 0.12);
                    gain.gain.setValueAtTime(0.22, now + i * 0.12);
                    gain.gain.exponentialRampToValueAtTime(0.001, now + i * 0.12 + 0.5);
                    osc.connect(gain);
                    gain.connect(this.ctx.destination);
                    osc.start(now + i * 0.12);
                    osc.stop(now + i * 0.12 + 0.6);
                });
            }
        } catch (e) {
            console.log('Audio error:', e);
        }
    }
};

// Text-to-Speech Engine
function speakText(text, langCode = 'en', isSlow = false) {
    if (!('speechSynthesis' in window)) return;
    
    window.speechSynthesis.cancel();
    const utterance = new SpeechSynthesisUtterance(text);
    
    // Map lang codes
    const langMap = {
        'en': 'en-US',
        'it': 'it-IT',
        'ru': 'ru-RU',
        'vladikish': 'it-IT' // Vladikish sounds melodic and clear with Italian phonetics!
    };
    
    utterance.lang = langMap[langCode] || 'en-US';
    utterance.rate = isSlow ? 0.55 : 0.9;
    utterance.pitch = isSlow ? 1.0 : 1.1; // cheerful voice
    window.speechSynthesis.speak(utterance);
}

// Confetti Effect
function triggerConfetti() {
    const wrapper = document.createElement('div');
    wrapper.className = 'confetti-wrapper';
    document.body.appendChild(wrapper);

    const colors = ['#58cc02', '#1cb0f6', '#ff9600', '#ff4b4b', '#ce82ff', '#ffd900'];
    const count = 50;

    for (let i = 0; i < count; i++) {
        const p = document.createElement('div');
        p.className = 'confetti-particle';
        p.style.left = Math.random() * 100 + 'vw';
        p.style.backgroundColor = colors[Math.floor(Math.random() * colors.length)];
        p.style.width = (Math.random() * 8 + 6) + 'px';
        p.style.height = (Math.random() * 8 + 6) + 'px';
        p.style.animationDuration = (Math.random() * 2 + 1.5) + 's';
        p.style.animationDelay = (Math.random() * 0.4) + 's';
        wrapper.appendChild(p);
    }

    setTimeout(() => {
        wrapper.remove();
    }, 3500);
}

// Theme Toggle
function toggleTheme() {
    const current = document.documentElement.getAttribute('data-theme') || 'light';
    const next = current === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    localStorage.setItem('shibalingo_theme', next);
    
    const icon = document.getElementById('theme-icon');
    if (icon) icon.textContent = next === 'dark' ? '☀️' : '🌙';
}

// Init theme on load
(function() {
    const saved = localStorage.getItem('shibalingo_theme') || 'light';
    document.documentElement.setAttribute('data-theme', saved);
})();

// Language Dropdown Toggle
document.addEventListener('DOMContentLoaded', () => {
    const langBtn = document.getElementById('lang-select-btn');
    const langDropdown = document.getElementById('lang-dropdown-menu');

    if (langBtn && langDropdown) {
        langBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            langDropdown.classList.toggle('show');
        });

        document.addEventListener('click', () => {
            langDropdown.classList.remove('show');
        });
    }

    // Attach click sound to buttons
    document.querySelectorAll('.btn-duo, .choice-card, .word-tile').forEach(el => {
        el.addEventListener('click', () => SoundEngine.play('click'));
    });
});

// Sidebar Toggle Handlers (Mobile & Small Screens)
function toggleAppSidebar(forceState = null) {
    const sidebar = document.getElementById('app-sidebar');
    const backdrop = document.getElementById('app-sidebar-backdrop');
    if (!sidebar) return;

    const isOpen = (forceState !== null) ? !forceState : sidebar.classList.contains('open');
    if (isOpen) {
        sidebar.classList.remove('open');
        if (backdrop) backdrop.classList.remove('active');
        document.body.style.overflow = '';
    } else {
        sidebar.classList.add('open');
        if (backdrop) backdrop.classList.add('active');
        if (window.innerWidth <= 992) {
            document.body.style.overflow = 'hidden';
        }
    }
}

function toggleAdminSidebar(forceState = null) {
    const sidebar = document.getElementById('admin-sidebar');
    const backdrop = document.getElementById('admin-sidebar-backdrop');
    if (!sidebar) return;

    const isOpen = (forceState !== null) ? !forceState : sidebar.classList.contains('open');
    if (isOpen) {
        sidebar.classList.remove('open');
        if (backdrop) backdrop.classList.remove('active');
        document.body.style.overflow = '';
    } else {
        sidebar.classList.add('open');
        if (backdrop) backdrop.classList.add('active');
        if (window.innerWidth <= 992) {
            document.body.style.overflow = 'hidden';
        }
    }
}

// Close sidebar on Escape key
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        toggleAppSidebar(false);
        toggleAdminSidebar(false);
    }
});

// Auto-close sidebar on mobile navigation click
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.sidebar .nav-item a, .admin-sidebar .nav-item a').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth <= 992) {
                toggleAppSidebar(false);
                toggleAdminSidebar(false);
            }
        });
    });
});

window.SoundEngine = SoundEngine;
window.speakText = speakText;
window.triggerConfetti = triggerConfetti;
window.toggleTheme = toggleTheme;
window.toggleAppSidebar = toggleAppSidebar;
window.toggleAdminSidebar = toggleAdminSidebar;

