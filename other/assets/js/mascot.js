/**
 * Enhanced Shiba Inu Mascot SVG & Skin Manager for ShibaLingo
 */

const ShibaMascot = {
    currentSkin: 'classic',

    setSkin(skin) {
        this.currentSkin = skin;
    },

    renderSVG(state = 'idle', size = 120, skin = null) {
        skin = skin || this.currentSkin || 'classic';

        let eyeLeft = `<circle cx="48" cy="55" r="4" fill="#2d1d0f"/>`;
        let eyeRight = `<circle cx="72" cy="55" r="4" fill="#2d1d0f"/>`;
        let mouth = `<path d="M 54 67 Q 60 72 66 67" stroke="#2d1d0f" stroke-width="2.5" fill="none" stroke-linecap="round"/>`;
        let blush = `<ellipse cx="40" cy="62" rx="5" ry="3" fill="#ff7f7f" opacity="0.6"/><ellipse cx="80" cy="62" rx="5" ry="3" fill="#ff7f7f" opacity="0.6"/>`;
        let extra = '';

        if (state === 'happy' || state === 'celebrate') {
            eyeLeft = `<path d="M 44 56 Q 48 50 52 56" stroke="#2d1d0f" stroke-width="3" fill="none" stroke-linecap="round"/>`;
            eyeRight = `<path d="M 68 56 Q 72 50 76 56" stroke="#2d1d0f" stroke-width="3" fill="none" stroke-linecap="round"/>`;
            mouth = `<path d="M 53 66 Q 60 77 67 66 Z" fill="#e04f5f" stroke="#2d1d0f" stroke-width="2"/>`;
            if (state === 'celebrate') {
                extra += `
                    <!-- Party Hat -->
                    <polygon points="60,10 46,36 74,36" fill="#ce82ff" stroke="#a855f7" stroke-width="2"/>
                    <circle cx="60" cy="8" r="4" fill="#ffdf00"/>
                    <circle cx="55" cy="24" r="2.5" fill="#58cc02"/>
                `;
            }
        } else if (state === 'sad') {
            eyeLeft = `<path d="M 44 52 Q 48 56 52 52" stroke="#2d1d0f" stroke-width="3" fill="none" stroke-linecap="round"/>`;
            eyeRight = `<path d="M 68 52 Q 72 56 76 52" stroke="#2d1d0f" stroke-width="3" fill="none" stroke-linecap="round"/>`;
            mouth = `<path d="M 54 70 Q 60 64 66 70" stroke="#2d1d0f" stroke-width="2.5" fill="none" stroke-linecap="round"/>`;
            blush = '';
            extra += `<path d="M 78 46 Q 84 50 82 56 Q 76 56 78 46" fill="#60a5fa" opacity="0.8"/>`;
        }

        // Custom Skins Rendering
        if (skin === 'pilot') {
            extra += `
                <!-- Pilot Goggles & Scarf -->
                <rect x="36" y="44" width="20" height="14" rx="4" fill="#60a5fa" stroke="#374151" stroke-width="2.5" opacity="0.85"/>
                <rect x="64" y="44" width="20" height="14" rx="4" fill="#60a5fa" stroke="#374151" stroke-width="2.5" opacity="0.85"/>
                <line x1="56" y1="51" x2="64" y2="51" stroke="#374151" stroke-width="3"/>
                <!-- Silk Flight Scarf -->
                <path d="M 46 82 Q 60 92 74 82 Q 86 96 74 98 Q 60 88 46 82" fill="#f3f4f6" stroke="#9ca3af" stroke-width="2"/>
            `;
        } else if (skin === 'samurai') {
            extra += `
                <!-- Samurai Headband / Crest -->
                <path d="M 30 38 Q 60 28 90 38 L 90 44 Q 60 34 30 44 Z" fill="#dc2626" stroke="#991b1b" stroke-width="2"/>
                <circle cx="60" cy="34" r="5" fill="#facc15" stroke="#b45309" stroke-width="1.5"/>
            `;
        } else if (skin === 'cyberpunk') {
            extra += `
                <!-- Cyber Visor -->
                <polygon points="34,50 86,50 82,62 38,62" fill="#06b6d4" stroke="#0891b2" stroke-width="2" opacity="0.9"/>
                <line x1="38" y1="56" x2="82" y2="56" stroke="#ec4899" stroke-width="2"/>
            `;
        } else if (skin === 'wizard') {
            extra += `
                <!-- Wizard Starry Hat -->
                <polygon points="60,6 40,36 80,36" fill="#3b82f6" stroke="#1d4ed8" stroke-width="2"/>
                <circle cx="60" cy="18" r="2.5" fill="#facc15"/>
                <circle cx="50" cy="28" r="2" fill="#facc15"/>
            `;
        } else if (skin === 'professor') {
            extra += `
                <!-- Professor Glasses -->
                <circle cx="48" cy="54" r="9" fill="none" stroke="#eab308" stroke-width="2.5"/>
                <circle cx="72" cy="54" r="9" fill="none" stroke="#eab308" stroke-width="2.5"/>
                <line x1="57" y1="54" x2="63" y2="54" stroke="#eab308" stroke-width="2.5"/>
            `;
        }

        return `
        <svg class="shiba-svg anim-float" width="${size}" height="${size}" viewBox="0 0 120 120" fill="none" xmlns="http://www.w3.org/2000/svg">
            <!-- Ears -->
            <polygon points="32,20 18,48 44,46" fill="#d97724" stroke="#b45309" stroke-width="2.5"/>
            <polygon points="30,26 23,45 40,44" fill="#fde68a"/>
            <polygon points="88,20 76,46 102,48" fill="#d97724" stroke="#b45309" stroke-width="2.5"/>
            <polygon points="90,26 80,44 97,45" fill="#fde68a"/>
            
            <!-- Head Main -->
            <ellipse cx="60" cy="60" rx="38" ry="34" fill="#e68a2e" stroke="#b45309" stroke-width="2.5"/>
            
            <!-- White Cheeks / Muzzle -->
            <path d="M 32 66 C 30 76 42 86 60 86 C 78 86 90 76 88 66 C 88 56 78 52 60 52 C 42 52 32 56 32 66 Z" fill="#ffffff"/>
            
            <!-- Eyebrow dots -->
            <circle cx="46" cy="44" r="3.5" fill="#ffffff"/>
            <circle cx="74" cy="44" r="3.5" fill="#ffffff"/>
            
            <!-- Eyes -->
            ${eyeLeft}
            ${eyeRight}
            
            <!-- Nose -->
            <polygon points="60,59 55,64 65,64" fill="#2d1d0f"/>
            
            <!-- Mouth -->
            ${mouth}
            
            <!-- Cheeks Blush -->
            ${blush}
            
            <!-- Skin Props -->
            ${extra}
        </svg>
        `;
    },

    update(containerId, state = 'idle', message = null, skin = null) {
        const container = document.getElementById(containerId);
        if (!container) return;

        let html = `<div class="shiba-avatar-wrapper">${this.renderSVG(state, 120, skin)}</div>`;
        if (message) {
            html += `<div class="shiba-bubble anim-bounce">${message}</div>`;
        }
        container.innerHTML = html;
    }
};

window.ShibaMascot = ShibaMascot;
