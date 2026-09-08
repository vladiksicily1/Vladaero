/**
 * ShibaLingo - Custom High-Quality Voice & Audio Engine
 * Plays crystal-clear, natural human pronunciation for Vladikish, Russian, English, and Italian
 * Uses server-cached studio MP3 streams with instant client-side WebSpeech fallback.
 */

class VladikishVoiceEngine {
    constructor() {
        this.currentAudio = null;
        this.isPlaying = false;
    }

    /**
     * Vladikish Syllabification for Phonetics
     */
    static syllabify(word) {
        const w = word.toLowerCase().trim();
        const vowels = ['a', 'e', 'i', 'o', 'u', 'y'];
        const chars = Array.from(w);
        const len = chars.length;

        if (len <= 2) return [w];

        const syllables = [];
        let cur = '';
        let hasVowel = false;

        for (let i = 0; i < len; i++) {
            const c = chars[i];
            cur += c;

            if (vowels.includes(c)) {
                hasVowel = true;
                if (i + 1 < len) {
                    const next = chars[i + 1];
                    const afterNext = (i + 2 < len) ? chars[i + 2] : '';

                    // Break after vowel if single consonant + vowel follows
                    if (!vowels.includes(next) && afterNext && vowels.includes(afterNext)) {
                        syllables.push(cur);
                        cur = '';
                        hasVowel = false;
                    }
                    // Break after first consonant if two consonants follow (e.g. "bar-ka")
                    else if (!vowels.includes(next) && afterNext && !vowels.includes(afterNext)) {
                        cur += next;
                        i++;
                        syllables.push(cur);
                        cur = '';
                        hasVowel = false;
                    }
                }
            }
        }

        if (cur) {
            if (syllables.length > 0 && !hasVowel) {
                syllables[syllables.length - 1] += cur;
            } else {
                syllables.push(cur);
            }
        }

        return syllables.length > 0 ? syllables : [w];
    }

    /**
     * Convert Vladikish text to Russian phonetic transcription with stress marks
     */
    static getPhoneticRussian(text) {
        const words = text.split(/\s+/);
        const map = {
            'a': 'а', 'b': 'б', 'c': 'ч', 'd': 'д', 'e': 'э',
            'f': 'ф', 'g': 'г', 'h': 'х', 'i': 'и', 'j': 'ж',
            'k': 'к', 'l': 'л', 'm': 'м', 'n': 'н', 'o': 'о',
            'p': 'п', 'q': 'к', 'r': 'р', 's': 'с', 't': 'т',
            'u': 'у', 'v': 'в', 'w': 'в', 'x': 'кс', 'y': 'й', 'z': 'з'
        };

        const res = words.map(w => {
            const clean = w.replace(/[^\p{L}\-]/gu, '');
            if (!clean) return w;
            const syls = this.syllabify(clean);
            const count = syls.length;
            const stressedIdx = Math.max(0, count - 2);

            const sylsRu = syls.map((s, idx) => {
                let r = Array.from(s).map(c => map[c.toLowerCase()] || c).join('');
                if (idx === stressedIdx && count > 1) {
                    r = r.replace(/([аэиоу])/u, '$1́');
                }
                return r;
            });
            return sylsRu.join('-');
        });

        return res.join(' ');
    }

    /**
     * Stop active audio
     */
    stop() {
        if (this.currentAudio) {
            try {
                this.currentAudio.pause();
                this.currentAudio.currentTime = 0;
            } catch (e) {}
            this.currentAudio = null;
        }
        if (window.speechSynthesis) {
            window.speechSynthesis.cancel();
        }
        this.isPlaying = false;
        this.hideSubtitle();
    }

    /**
     * Main Vocalization Entry Point
     */
    speak(text, langCode = 'vladikish', isSlow = false, onComplete = null) {
        if (!text || typeof text !== 'string') return;
        const cleanText = text.trim();
        if (!cleanText) return;

        this.stop();
        this.isPlaying = true;

        const effectiveLang = (langCode || 'vladikish').toLowerCase();
        this.showSubtitle(cleanText, effectiveLang);

        // 1. Try High-Quality Real MP3 Audio from Server TTS
        const audioUrl = `api/tts.php?text=${encodeURIComponent(cleanText)}&lang=${encodeURIComponent(effectiveLang)}&speed=${isSlow ? 0.7 : 1.0}`;
        const audio = new Audio(audioUrl);
        audio.playbackRate = isSlow ? 0.75 : 1.0;

        audio.onended = () => {
            this.isPlaying = false;
            this.currentAudio = null;
            this.hideSubtitle();
            if (onComplete) onComplete();
        };

        audio.onerror = () => {
            console.log('Audio stream fallback to browser speech synthesis');
            this.speakWebSpeechFallback(cleanText, effectiveLang, isSlow, onComplete);
        };

        const playPromise = audio.play();
        if (playPromise !== undefined) {
            playPromise.then(() => {
                this.currentAudio = audio;
            }).catch((err) => {
                console.log('Autoplay blocked or stream error, falling back to WebSpeech:', err);
                this.speakWebSpeechFallback(cleanText, effectiveLang, isSlow, onComplete);
            });
        }
    }

    /**
     * Fallback to Web Speech API (natural human voices on device)
     */
    speakWebSpeechFallback(text, langCode, isSlow, onComplete) {
        if (!('speechSynthesis' in window)) {
            this.hideSubtitle();
            if (onComplete) onComplete();
            return;
        }

        window.speechSynthesis.cancel();
        const utterance = new SpeechSynthesisUtterance(text);
        
        const langMap = {
            'ru': 'ru-RU',
            'en': 'en-US',
            'it': 'it-IT',
            'vladikish': 'it-IT',
            'vk': 'it-IT'
        };

        const targetLang = langMap[langCode] || 'it-IT';
        utterance.lang = targetLang;

        // Try to pick natural human voice
        const voices = window.speechSynthesis.getVoices();
        const voice = voices.find(v => v.lang.startsWith(targetLang.slice(0, 2)) && (v.name.includes('Google') || v.name.includes('Natural') || v.name.includes('Alice') || v.name.includes('Siri') || v.name.includes('Premium'))) 
                   || voices.find(v => v.lang.startsWith(targetLang.slice(0, 2)))
                   || voices[0];

        if (voice) {
            utterance.voice = voice;
        }

        utterance.rate = isSlow ? 0.6 : 0.95;
        utterance.pitch = 1.05;

        utterance.onend = () => {
            this.isPlaying = false;
            this.hideSubtitle();
            if (onComplete) onComplete();
        };

        utterance.onerror = () => {
            this.isPlaying = false;
            this.hideSubtitle();
            if (onComplete) onComplete();
        };

        window.speechSynthesis.speak(utterance);
    }

    /**
     * Show cute floating phonetic subtitle bubble
     */
    showSubtitle(text, langCode) {
        let badge = document.getElementById('shiba-speech-subtitle');
        if (!badge) {
            badge = document.createElement('div');
            badge.id = 'shiba-speech-subtitle';
            badge.style.cssText = `
                position: fixed;
                bottom: 85px;
                left: 50%;
                transform: translateX(-50%) translateY(20px);
                background: rgba(30, 27, 75, 0.95);
                color: #ffffff;
                padding: 10px 20px;
                border-radius: 20px;
                font-size: 0.95rem;
                font-weight: 800;
                box-shadow: 0 10px 25px rgba(0,0,0,0.3);
                z-index: 10000;
                display: flex;
                align-items: center;
                gap: 10px;
                pointer-events: none;
                transition: all 0.25s cubic-bezier(0.175, 0.885, 0.32, 1.275);
                opacity: 0;
                backdrop-filter: blur(8px);
                border: 2px solid #8b5cf6;
            `;
            document.body.appendChild(badge);
        }

        const isVladikish = (langCode === 'vladikish' || langCode === 'vk');
        const phoneticRu = isVladikish ? VladikishVoiceEngine.getPhoneticRussian(text) : text;

        badge.innerHTML = `
            <span style="font-size: 1.4rem;">🐕 🔊</span>
            <div>
                <div style="color: #c084fc; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px;">
                    ${isVladikish ? 'Произношение Vladikish' : 'Озвучка'}
                </div>
                <div style="font-size: 1.05rem; font-weight: 900; color: #ffffff;">
                    «${text}»
                </div>
                ${isVladikish ? `<div style="font-size: 0.85rem; color: #facc15; font-style: italic;">[ ${phoneticRu} ]</div>` : ''}
            </div>
        `;

        badge.style.opacity = '1';
        badge.style.transform = 'translateX(-50%) translateY(0)';
    }

    hideSubtitle() {
        const badge = document.getElementById('shiba-speech-subtitle');
        if (badge) {
            badge.style.opacity = '0';
            badge.style.transform = 'translateX(-50%) translateY(20px)';
        }
    }
}

// Global Voice Singleton
window.vladikishVoice = new VladikishVoiceEngine();

// Seamless drop-in replacement for window.speakText across the entire platform
window.speakText = function(text, langCode = 'vladikish', isSlow = false, onComplete = null) {
    window.vladikishVoice.speak(text, langCode, isSlow, onComplete);
};

// Pre-load voices on load
if (window.speechSynthesis) {
    window.speechSynthesis.onvoiceschanged = () => {
        window.speechSynthesis.getVoices();
    };
}
