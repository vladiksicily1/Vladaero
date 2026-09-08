<?php
/**
 * ShibaLingo - Real-time AI Vision AR Scanner (100% Free, Zero-Freeze, No API Key Required)
 */

$pageTitle = 'AR Фото-сканер предметов';
require_once __DIR__ . '/includes/header.php';
?>

<!-- Lightweight On-Demand MobileNet AI Model (Only 2MB, loads in background) -->
<script defer src="https://cdn.jsdelivr.net/npm/@tensorflow/tfjs@4.17.0/dist/tf.min.js"></script>
<script defer src="https://cdn.jsdelivr.net/npm/@tensorflow-models/mobilenet@2.1.1/dist/mobilenet.min.js"></script>

<style>
.ar-container {
    position: relative;
    max-width: 680px;
    margin: 0 auto;
    border-radius: 24px;
    overflow: hidden;
    background: #0f172a;
    box-shadow: 0 12px 36px rgba(0,0,0,0.4);
    border: 3px solid var(--border-color);
}
.ar-video-wrapper {
    position: relative;
    width: 100%;
    height: 440px;
    background: #050b14;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}
#ar-video {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
#ar-canvas-snapshot {
    display: none;
}
.ar-hud-overlay {
    position: absolute;
    inset: 0;
    pointer-events: none;
    z-index: 15;
    margin: 16px;
    border-radius: 16px;
}
.ar-scan-laser {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 3px;
    background: linear-gradient(90deg, transparent, #8b5cf6, #ec4899, #8b5cf6, transparent);
    box-shadow: 0 0 15px #ec4899;
    animation: scanLaser 2.5s infinite ease-in-out;
}
@keyframes scanLaser {
    0% { top: 8%; opacity: 0.3; }
    50% { top: 90%; opacity: 1; }
    100% { top: 8%; opacity: 0.3; }
}
.ar-corner-reticle {
    position: absolute;
    width: 24px;
    height: 24px;
    border-color: #8b5cf6;
    border-style: solid;
}
.reticle-tl { top: 10px; left: 10px; border-width: 4px 0 0 4px; border-top-left-radius: 8px; }
.reticle-tr { top: 10px; right: 10px; border-width: 4px 4px 0 0; border-top-right-radius: 8px; }
.reticle-bl { bottom: 10px; left: 10px; border-width: 0 0 4px 4px; border-bottom-left-radius: 8px; }
.reticle-br { bottom: 10px; right: 10px; border-width: 0 4px 4px 0; border-bottom-right-radius: 8px; }

.ar-center-crosshair {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 130px;
    height: 130px;
    border: 2px dashed rgba(255, 255, 255, 0.4);
    border-radius: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.ar-center-crosshair::after {
    content: '';
    width: 14px;
    height: 14px;
    background: #ec4899;
    border-radius: 50%;
    box-shadow: 0 0 14px #ec4899;
}

.ar-controls-bar {
    padding: 16px 20px;
    background: var(--bg-card);
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
}
</style>

<div style="max-width: 750px; margin: 0 auto; text-align: center;">
    <div style="margin-bottom: 20px;">
        <h1 style="font-size: 1.8rem; font-weight: 900; display: flex; align-items: center; justify-content: center; gap: 12px;">
            <span>📸</span> Бесплатный AI AR-Сканер предметов
        </h1>
        <p style="color: var(--text-muted); font-size: 0.95rem;">
            Наведите камеру на любой реальный предмет (чашка, собака, кот, телефон, ноутбук, книга, машина, стул) и нажмите <strong>«СКАНИРОВАТЬ»</strong>! AI распознает реальные пиксели и покажет слово на Vladikish.
        </p>
    </div>

    <!-- AR Camera Viewport -->
    <div class="ar-container anim-bounce">
        <div class="ar-video-wrapper">
            <video id="ar-video" autoplay playsinline muted></video>
            <canvas id="ar-canvas-snapshot"></canvas>

            <!-- Holographic HUD -->
            <div class="ar-hud-overlay" id="ar-hud">
                <div class="ar-scan-laser"></div>
                <div class="ar-corner-reticle reticle-tl"></div>
                <div class="ar-corner-reticle reticle-tr"></div>
                <div class="ar-corner-reticle reticle-bl"></div>
                <div class="ar-corner-reticle reticle-br"></div>
                
                <div class="ar-center-crosshair"></div>

                <div id="ar-status-badge" style="position: absolute; top: 16px; left: 50%; transform: translateX(-50%); background: rgba(0,0,0,0.75); color: #fff; padding: 6px 16px; border-radius: 20px; font-size: 0.85rem; font-weight: 800; border: 1px solid rgba(255,255,255,0.2); backdrop-filter: blur(6px);">
                    🟢 AI Нейросеть готова к сканированию
                </div>
            </div>

            <!-- Placeholder Screen before Camera Launch -->
            <div id="ar-camera-placeholder" style="position: absolute; inset: 0; background: linear-gradient(135deg, #1e1b4b, #0f172a); color: white; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 20px; z-index: 25;">
                <div style="font-size: 4.5rem; margin-bottom: 12px; filter: drop-shadow(0 0 20px #8b5cf6);">
                    📷 🐕
                </div>
                <h2 style="font-size: 1.4rem; font-weight: 900; margin-bottom: 8px;">Режим дополненной реальности (AR)</h2>
                <p style="color: #cbd5e1; font-size: 0.95rem; max-width: 420px; margin-bottom: 24px;">
                    Включите камеру смартфона или веб-камеру для сканирования реальных объектов вокруг вас!
                </p>
                <div style="display: flex; gap: 12px; flex-wrap: wrap; justify-content: center;">
                    <button class="btn-duo btn-primary" onclick="startCamera()" style="font-size: 1.1rem; padding: 14px 28px;">
                        🎥 Включить камеру
                    </button>
                    <button class="btn-duo btn-outline" onclick="document.getElementById('ar-file-input').click()" style="font-size: 1rem; padding: 14px 20px; background: rgba(255,255,255,0.1); color: white; border-color: rgba(255,255,255,0.3);">
                        📁 Загрузить фото
                    </button>
                </div>
                <input type="file" id="ar-file-input" accept="image/*" style="display: none;" onchange="handlePhotoUpload(this.files)">
            </div>
        </div>

        <!-- Camera Actions Toolbar -->
        <div class="ar-controls-bar">
            <div style="display: flex; gap: 8px; align-items: center;">
                <button class="btn-duo btn-outline" id="btn-camera-toggle" onclick="toggleCamera()" style="font-size: 0.85rem; padding: 8px 14px;">
                    ⏹️ Выключить
                </button>
                <button class="btn-duo btn-outline" id="btn-camera-flip" onclick="flipCamera()" style="font-size: 0.85rem; padding: 8px 14px;">
                    🔄 Сменить
                </button>
            </div>

            <button class="btn-duo btn-primary" id="btn-scan-action" onclick="captureAndRecognize()" style="font-size: 1.05rem; padding: 10px 28px; box-shadow: 0 4px 0 #15803d;">
                📸 СКАНИРОВАТЬ
            </button>

            <button class="btn-duo btn-secondary" onclick="openCollectionModal()" style="font-size: 0.85rem; padding: 8px 14px;">
                🏆 Коллекция
            </button>
        </div>
    </div>

    <!-- Quick Samples Testing Row -->
    <div class="card-duo anim-bounce" style="margin-top: 20px; padding: 20px;">
        <div style="font-weight: 800; font-size: 0.95rem; margin-bottom: 12px; color: var(--text-muted);">
            ⚡ Или выберите предмет из каталога одним кликом:
        </div>
        <div style="display: flex; justify-content: center; gap: 10px; flex-wrap: wrap;">
            <button class="btn-duo btn-outline" onclick="recognizeLabel('dog')">🐕 Собака (Barka)</button>
            <button class="btn-duo btn-outline" onclick="recognizeLabel('cat')">🐱 Кот (Kato)</button>
            <button class="btn-duo btn-outline" onclick="recognizeLabel('cup')">☕ Чашка (Taso)</button>
            <button class="btn-duo btn-outline" onclick="recognizeLabel('laptop')">💻 Ноутбук (Computoro)</button>
            <button class="btn-duo btn-outline" onclick="recognizeLabel('book')">📖 Книга (Libro)</button>
            <button class="btn-duo btn-outline" onclick="recognizeLabel('car')">🚗 Машина (Auto)</button>
            <button class="btn-duo btn-outline" onclick="recognizeLabel('apple')">🍎 Яблоко (Pomo)</button>
        </div>
    </div>
</div>

<!-- Modal: Discovered Object Card -->
<div id="modal-ar-discovery" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.65); z-index: 9999; align-items: center; justify-content: center; padding: 20px; backdrop-filter: blur(8px);">
    <div class="card-duo anim-bounce" style="max-width: 480px; width: 100%; text-align: center; padding: 32px 24px; border: 3px solid var(--primary); margin-bottom: 0;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
            <span class="badge-tag" style="background: var(--primary-light); color: var(--primary-shadow); font-weight: 900;" id="discovery-badge">
                ✨ НАЙДЕННЫЙ ПРЕДМЕТ!
            </span>
            <button onclick="closeDiscoveryModal()" style="background: none; border: none; font-size: 1.4rem; cursor: pointer; color: var(--text-muted);">✕</button>
        </div>

        <div style="font-size: 4.5rem; margin-bottom: 8px;" id="discovery-icon">🔍</div>

        <h2 style="font-size: 2.2rem; font-weight: 900; color: var(--primary-shadow); margin-bottom: 4px;" id="discovery-word">
            Barka
        </h2>
        <div style="font-size: 1.05rem; color: #facc15; font-style: italic; font-weight: 700; margin-bottom: 12px;" id="discovery-pron">
            [ Ба́рка ]
        </div>

        <div style="background: var(--bg-main); padding: 14px; border-radius: 14px; margin-bottom: 16px; text-align: left;">
            <div style="font-size: 0.85rem; color: var(--text-muted); font-weight: 700;">Перевод:</div>
            <div style="font-size: 1.15rem; font-weight: 900; margin-bottom: 8px;" id="discovery-trans">Собака</div>
            <div style="font-size: 0.85rem; color: var(--text-muted); font-weight: 700;">Пример в речи:</div>
            <div style="font-size: 0.95rem; font-weight: 700; color: var(--secondary);" id="discovery-example">«Shiba barka bonu est.»</div>
        </div>

        <div style="display: flex; gap: 10px;">
            <button class="btn-duo btn-primary" onclick="speakDiscoveredWord()" style="flex: 1; padding: 12px; font-size: 1rem;">
                🔊 Слушать произношение
            </button>
            <button class="btn-duo btn-outline" onclick="closeDiscoveryModal()" style="padding: 12px 20px;">
                Отлично! 🐾
            </button>
        </div>
    </div>
</div>

<!-- Modal: User AR Collection -->
<div id="modal-ar-collection" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.65); z-index: 9999; align-items: center; justify-content: center; padding: 20px; backdrop-filter: blur(8px);">
    <div class="card-duo anim-bounce" style="max-width: 550px; width: 100%; max-height: 85vh; overflow-y: auto; padding: 28px 24px; margin-bottom: 0;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 style="font-size: 1.3rem; font-weight: 900; display: flex; align-items: center; gap: 8px;">
                <span>🏆</span> Моя AR-Коллекция
            </h3>
            <button onclick="document.getElementById('modal-ar-collection').style.display='none'" style="background: none; border: none; font-size: 1.4rem; cursor: pointer; color: var(--text-muted);">✕</button>
        </div>
        <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 16px;">
            Все найденные вами предметы в реальном мире сохраняются здесь:
        </p>

        <div id="ar-collection-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 10px;">
            <!-- Rendered by JS -->
        </div>
    </div>
</div>

<script>
let videoStream = null;
let currentFacingMode = 'environment';
let currentDiscoveredWord = '';
let mobileNetModel = null;

// Asynchronously load lightweight MobileNet in background (2MB)
window.addEventListener('load', () => {
    setTimeout(async () => {
        try {
            if (typeof mobilenet !== 'undefined') {
                mobileNetModel = await mobilenet.load({ version: 1, alpha: 0.25 });
                console.log('MobileNet Vision AI loaded successfully (100% free, on-device)');
                const badge = document.getElementById('ar-status-badge');
                if (badge) badge.textContent = '🟢 AI Vision готова к сканированию';
            }
        } catch (e) {
            console.log('MobileNet background load:', e);
        }
    }, 500);
});

async function startCamera() {
    const placeholder = document.getElementById('ar-camera-placeholder');
    const video = document.getElementById('ar-video');
    const statusBadge = document.getElementById('ar-status-badge');

    try {
        if (videoStream) {
            videoStream.getTracks().forEach(t => t.stop());
        }

        videoStream = await navigator.mediaDevices.getUserMedia({
            video: {
                facingMode: currentFacingMode,
                width: { ideal: 1280 },
                height: { ideal: 720 }
            },
            audio: false
        });

        video.srcObject = videoStream;
        await video.play();
        placeholder.style.display = 'none';
        SoundEngine.play('click');
        statusBadge.textContent = '🟢 Камера активна — наведите на предмет';
    } catch (err) {
        console.error('Camera access error:', err);
        alert('Не удалось получить доступ к камере. Вы можете загрузить фото или выбрать предмет из каталога!');
    }
}

function toggleCamera() {
    const placeholder = document.getElementById('ar-camera-placeholder');
    const video = document.getElementById('ar-video');
    const statusBadge = document.getElementById('ar-status-badge');

    if (videoStream) {
        videoStream.getTracks().forEach(t => t.stop());
        videoStream = null;
        video.srcObject = null;
        placeholder.style.display = 'flex';
        statusBadge.textContent = 'Камера выключена';
    } else {
        startCamera();
    }
}

function flipCamera() {
    currentFacingMode = (currentFacingMode === 'environment') ? 'user' : 'environment';
    if (videoStream) {
        startCamera();
    }
}

async function captureAndRecognize() {
    SoundEngine.play('click');
    const btn = document.getElementById('btn-scan-action');
    const statusBadge = document.getElementById('ar-status-badge');
    const video = document.getElementById('ar-video');
    const canvas = document.getElementById('ar-canvas-snapshot');
    btn.disabled = true;
    statusBadge.textContent = '🔍 Анализирую изображение...';

    let detectedClass = '';
    let imageData = '';

    if (videoStream && video.videoWidth > 0) {
        canvas.width = 224;
        canvas.height = 224;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(video, 0, 0, 224, 224);
        imageData = canvas.toDataURL('image/jpeg', 0.8);

        // 1. Real on-device MobileNet classification (free, zero latency, no API key)
        if (mobileNetModel) {
            try {
                const predictions = await mobileNetModel.classify(canvas);
                if (predictions && predictions.length > 0) {
                    detectedClass = predictions[0].className.toLowerCase();
                    console.log('MobileNet detected:', detectedClass, predictions);
                }
            } catch (err) {
                console.warn('Local classification fallback:', err);
            }
        }
    }

    try {
        const formData = new FormData();
        formData.append('action', 'recognize');
        if (detectedClass) {
            formData.append('label', detectedClass);
        }
        if (imageData) {
            formData.append('image', imageData);
        }

        const res = await fetch('api/vision_api.php', { method: 'POST', body: formData });
        const data = await res.json();

        if (data.success) {
            showDiscoveryModal(data.item, data.is_new, data.xp_awarded);
        }
    } catch (e) {
        console.error('Scan error:', e);
    } finally {
        btn.disabled = false;
        statusBadge.textContent = '🟢 Готово к сканированию';
    }
}

async function recognizeLabel(label) {
    try {
        const formData = new FormData();
        formData.append('action', 'recognize');
        formData.append('label', label);

        const res = await fetch('api/vision_api.php', { method: 'POST', body: formData });
        const data = await res.json();

        if (data.success) {
            showDiscoveryModal(data.item, data.is_new, data.xp_awarded);
        }
    } catch (e) {
        console.error('Recognition error', e);
    }
}

function showDiscoveryModal(item, isNew, xp) {
    currentDiscoveredWord = item.word;
    document.getElementById('discovery-word').textContent = item.word;
    document.getElementById('discovery-pron').textContent = `[ ${item.pron || item.word} ]`;
    document.getElementById('discovery-trans').textContent = item.ru;
    document.getElementById('discovery-example').textContent = `«${item.ex}»`;

    // Emoji icon lookup
    const icons = {
        'Barka': '🐕', 'Kato': '🐱', 'Taso': '☕', 'Computoro': '💻',
        'Libro': '📖', 'Auto': '🚗', 'Pomo': '🍎', 'Telephono': '📱',
        'Botelo': '🍾', 'Seĝo': '🪑', 'Planto': '🪴', 'Persono': '👤',
        'Tablo': '🪵', 'Horlojo': '⌚', 'Okulvitroj': '👓', 'Aqua': '💧'
    };
    document.getElementById('discovery-icon').textContent = icons[item.word] || '✨';
    
    const badge = document.getElementById('discovery-badge');
    badge.textContent = isNew ? `✨ НАХОДКА! +${xp} XP` : `✓ ИЗУЧЕНО РАНЕЕ`;
    badge.style.background = isNew ? '#fef08a' : 'var(--primary-light)';
    badge.style.color = isNew ? '#854d0e' : 'var(--primary-shadow)';

    SoundEngine.play('correct');
    if (isNew) triggerConfetti();

    document.getElementById('modal-ar-discovery').style.display = 'flex';
}

function closeDiscoveryModal() {
    document.getElementById('modal-ar-discovery').style.display = 'none';
}

function speakDiscoveredWord() {
    if (currentDiscoveredWord) {
        speakText(currentDiscoveredWord, 'vladikish');
    }
}

async function handlePhotoUpload(files) {
    if (!files || !files.length) return;
    SoundEngine.play('click');
    const file = files[0];
    const statusBadge = document.getElementById('ar-status-badge');
    statusBadge.textContent = '🔍 Анализирую загруженное фото...';

    const reader = new FileReader();
    reader.onload = async (e) => {
        const base64Data = e.target.result;
        const img = new Image();
        img.src = base64Data;
        img.onload = async () => {
            let detectedClass = '';
            if (mobileNetModel) {
                try {
                    const predictions = await mobileNetModel.classify(img);
                    if (predictions && predictions.length > 0) {
                        detectedClass = predictions[0].className.toLowerCase();
                    }
                } catch (err) {}
            }

            try {
                const formData = new FormData();
                formData.append('action', 'recognize');
                if (detectedClass) formData.append('label', detectedClass);
                formData.append('image', base64Data);

                const res = await fetch('api/vision_api.php', { method: 'POST', body: formData });
                const data = await res.json();

                if (data.success) {
                    showDiscoveryModal(data.item, data.is_new, data.xp_awarded);
                }
            } catch (err) {
                console.error('Upload recognize error:', err);
            } finally {
                statusBadge.textContent = '🟢 Готово к сканированию';
            }
        };
    };
    reader.readAsDataURL(file);
}

async function openCollectionModal() {
    try {
        const res = await fetch('api/vision_api.php?action=get_collection');
        const data = await res.json();
        const grid = document.getElementById('ar-collection-grid');
        grid.innerHTML = '';

        if (data.items && data.items.length > 0) {
            data.items.forEach(it => {
                const card = document.createElement('div');
                card.className = 'card-duo';
                card.style.cssText = 'padding: 14px 8px; text-align: center; margin-bottom: 0; cursor: pointer;';
                card.innerHTML = `
                    <div style="font-size: 2rem; margin-bottom: 4px;">🐾</div>
                    <div style="font-weight: 900; color: var(--primary-shadow); font-size: 1.05rem;">${it.word}</div>
                    <div style="font-size: 0.8rem; color: var(--text-muted);">${it.label_ru}</div>
                `;
                card.onclick = () => speakText(it.word, 'vladikish');
                grid.appendChild(card);
            });
        } else {
            grid.innerHTML = '<div style="grid-column: 1/-1; text-align: center; color: var(--text-muted); padding: 20px;">Вы еще не отсканировали ни одного предмета. Включите камеру и найдите предметы вокруг!</div>';
        }

        document.getElementById('modal-ar-collection').style.display = 'flex';
    } catch (e) {
        console.error('Collection fetch error', e);
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
