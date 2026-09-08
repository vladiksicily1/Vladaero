<?php
$pageTitle = '3D WebGL Визуализатор Самолетов и Систем';
$metaDescription = 'Интерактивный 3D-просмотрщик конструкции самолетов на Three.js. Анатомия планера, механизация крыла, работа двигателей и аэродинамические зоны.';
require_once __DIR__ . '/includes/header.php';
?>

<!-- Three.js CDN -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.js"></script>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

    <!-- Header -->
    <div class="va-card p-6 sm:p-8 mb-8">
        <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl sm:text-3xl font-bold text-white flex items-center space-x-3">
                    <i data-lucide="box" class="w-8 h-8 text-purple-400"></i>
                    <span>Интерактивная 3D Анатомия Самолета</span>
                </h1>
                <p class="text-xs text-slate-400 mt-1">
                    Исследуйте конструкцию современного магистрального лайнера, аэродинамические поверхности и работу механизации в 3D
                </p>
            </div>

            <!-- View Mode Toggles -->
            <div class="flex flex-wrap items-center gap-2 text-xs font-mono">
                <button onclick="setRenderMode('solid')" id="btn-mode-solid" class="px-3 py-1.5 rounded-lg bg-sky-600 border border-sky-500 text-white font-bold transition">
                    Сплошной (Solid)
                </button>
                <button onclick="setRenderMode('wireframe')" id="btn-mode-wireframe" class="px-3 py-1.5 rounded-lg bg-slate-950 border border-slate-800 text-slate-400 hover:text-white transition">
                    Каркас (Wireframe)
                </button>
                <button onclick="setRenderMode('aero')" id="btn-mode-aero" class="px-3 py-1.5 rounded-lg bg-slate-950 border border-slate-800 text-slate-400 hover:text-white transition">
                    Аэродинамика (Airflow)
                </button>
                <button onclick="toggleGear()" id="btn-gear" class="px-3 py-1.5 rounded-lg bg-slate-950 border border-slate-800 text-amber-400 font-bold transition">
                    Шасси: Выпущено
                </button>
            </div>
        </div>
    </div>

    <!-- 3D Canvas & Hotspot Detail Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">

        <!-- 3D WebGL Canvas Container -->
        <div class="lg:col-span-3 h-[600px] rounded-2xl overflow-hidden border border-slate-800 relative bg-slate-950 shadow-2xl">
            <div id="three-container" class="w-full h-full"></div>

            <!-- Canvas Overlay Controls Hint -->
            <div class="absolute bottom-4 left-4 z-10 bg-slate-950/80 backdrop-blur-md p-3 rounded-xl border border-slate-800 text-[11px] font-mono text-slate-400 space-y-0.5 pointer-events-none">
                <div>Вращение: <strong>ЛКМ + Движение</strong></div>
                <div>Приближение: <strong>Колесо мыши</strong></div>
                <div>Панорама: <strong>ПКМ + Движение</strong></div>
            </div>

            <!-- Reset Camera Button -->
            <button onclick="resetCamera()" class="absolute top-4 right-4 z-10 bg-slate-900/90 hover:bg-slate-800 p-2.5 rounded-xl border border-slate-700 text-xs font-mono text-slate-200 transition flex items-center space-x-1.5">
                <i data-lucide="compass" class="w-4 h-4 text-sky-400"></i>
                <span>Сброс камеры</span>
            </button>
        </div>

        <!-- Hotspots & Interactive Info Card -->
        <div class="lg:col-span-1 space-y-4">
            <div class="va-card p-5">
                <h3 class="text-xs font-mono text-slate-400 uppercase tracking-wider mb-3 flex items-center justify-between">
                    <span>Точки интереса (Hotspots)</span>
                    <i data-lucide="info" class="w-4 h-4 text-sky-400"></i>
                </h3>

                <div class="space-y-2 text-xs font-mono">
                    <button onclick="focusHotspot('wing')" class="w-full p-2.5 rounded-xl bg-slate-950 hover:bg-slate-900 border border-slate-800 text-left transition flex items-center justify-between">
                        <span>1. Закрылки и интерцепторы</span>
                        <i data-lucide="chevron-right" class="w-3.5 h-3.5 text-sky-400"></i>
                    </button>
                    <button onclick="focusHotspot('engine')" class="w-full p-2.5 rounded-xl bg-slate-950 hover:bg-slate-900 border border-slate-800 text-left transition flex items-center justify-between">
                        <span>2. Турбовентиляторный двигатель</span>
                        <i data-lucide="chevron-right" class="w-3.5 h-3.5 text-sky-400"></i>
                    </button>
                    <button onclick="focusHotspot('pitot')" class="w-full p-2.5 rounded-xl bg-slate-950 hover:bg-slate-900 border border-slate-800 text-left transition flex items-center justify-between">
                        <span>3. Приемник ПВД (Pitot Tube)</span>
                        <i data-lucide="chevron-right" class="w-3.5 h-3.5 text-sky-400"></i>
                    </button>
                    <button onclick="focusHotspot('winglet')" class="w-full p-2.5 rounded-xl bg-slate-950 hover:bg-slate-900 border border-slate-800 text-left transition flex items-center justify-between">
                        <span>4. Законцовки крыла (Винглеты)</span>
                        <i data-lucide="chevron-right" class="w-3.5 h-3.5 text-sky-400"></i>
                    </button>
                    <button onclick="focusHotspot('tail')" class="w-full p-2.5 rounded-xl bg-slate-950 hover:bg-slate-900 border border-slate-800 text-left transition flex items-center justify-between">
                        <span>5. Хвостовое оперение и РН</span>
                        <i data-lucide="chevron-right" class="w-3.5 h-3.5 text-sky-400"></i>
                    </button>
                </div>
            </div>

            <!-- Active Description Box -->
            <div id="hotspot-desc-card" class="va-card p-5 space-y-2">
                <h4 id="hotspot-title" class="text-sm font-bold text-sky-400 font-mono">Общий обзор самолета</h4>
                <p id="hotspot-text" class="text-xs text-slate-300 leading-relaxed">
                    Нажмите на любую деталь выше для фокусировки камеры и подробного объяснения аэродинамического назначения элемента.
                </p>
            </div>
        </div>

    </div>
</div>

<script>
    let scene, camera, renderer, controls;
    let planeGroup;
    let landingGearGroup;
    let isGearDown = true;
    let currentMode = 'solid';

    const hotspotsData = {
        wing: {
            title: 'Механизация крыла (Закрылки и спойлеры)',
            text: 'Закрылки (Flaps) увеличивают площадь и кривизну профиля крыла, обеспечивая высокую подъемную силу на малых скоростях при взлете и посадке. Спойлеры (Spoilers) гасят подъемную силу при касании полосы, увеличивая сцепление колес с ВПП.',
            camPos: [15, 8, 12]
        },
        engine: {
            title: 'Двухконтурный турбовентиляторный двигатель',
            text: 'Обеспечивает тягу до 140-500 кН. Большая часть воздуха проходит через внешний контур вентилятора в обход камеры сгорания (степень двухконтурности до 11:1), что дает колоссальную экономию топлива и тишину.',
            camPos: [8, -2, 10]
        },
        pitot: {
            title: 'Приемник воздушного давления (ПВД)',
            text: 'Трубка Пито измеряет полное динамическое давление встречного потока воздуха. Совместно со статическими портами вычисляет приборную скорость (IAS), истинную скорость (TAS) и барометрическую высоту.',
            camPos: [3, 2, 22]
        },
        winglet: {
            title: 'Законцовки крыла (Винглеты / Sharklets)',
            text: 'Препятствуют перетеканию воздуха высокого давления из-под крыла в зону разрежения сверху, разрушая концевые вихри. Это снижает индуктивное сопротивление крыла и экономит 4-6% керосина.',
            camPos: [24, 6, 2]
        },
        tail: {
            title: 'Хвостовое оперение и киль',
            text: 'Вертикальный киль с рулем направления (Rudder) обеспечивает путевую устойчивость и парирует боковой снос. Переставной стабилизатор (THS) с рулем высоты задает продольную балансировку по тангажу.',
            camPos: [6, 12, -22]
        }
    };

    function init3D() {
        const container = document.getElementById('three-container');
        const width = container.clientWidth;
        const height = container.clientHeight;

        scene = new THREE.Scene();
        scene.background = new THREE.Color(0x06090e);
        scene.fog = new THREE.FogExp2(0x06090e, 0.015);

        camera = new THREE.PerspectiveCamera(45, width / height, 0.1, 1000);
        camera.position.set(22, 14, 26);

        renderer = new THREE.WebGLRenderer({ antialias: true });
        renderer.setSize(width, height);
        renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
        renderer.shadowMap.enabled = true;
        container.appendChild(renderer.domElement);

        controls = new THREE.OrbitControls(camera, renderer.domElement);
        controls.enableDamping = true;
        controls.dampingFactor = 0.05;
        controls.maxDistance = 80;
        controls.minDistance = 5;

        // Lighting
        const ambientLight = new THREE.AmbientLight(0xffffff, 0.7);
        scene.add(ambientLight);

        const dirLight = new THREE.DirectionalLight(0x38bdf8, 1.2);
        dirLight.position.set(20, 40, 20);
        scene.add(dirLight);

        const fillLight = new THREE.DirectionalLight(0xffffff, 0.5);
        fillLight.position.set(-20, -10, -20);
        scene.add(fillLight);

        // Ground Grid
        const grid = new THREE.GridHelper(80, 40, 0x0284c7, 0x1e293b);
        grid.position.y = -5;
        scene.add(grid);

        // Build 3D Plane Geometry
        buildPlaneModel();

        window.addEventListener('resize', onWindowResize);
        animate();
    }

    function buildPlaneModel() {
        planeGroup = new THREE.Group();

        // 1. Fuselage
        const fuseGeo = new THREE.CylinderGeometry(2, 1.8, 32, 32);
        fuseGeo.rotateX(Math.PI / 2);
        const fuseMat = new THREE.MeshStandardMaterial({ color: 0xe2e8f0, roughness: 0.3, metalness: 0.4 });
        const fuselage = new THREE.Mesh(fuseGeo, fuseMat);
        fuselage.name = 'fuselage';
        planeGroup.add(fuselage);

        // Nose Cone
        const noseGeo = new THREE.ConeGeometry(1.9, 6, 32);
        noseGeo.rotateX(Math.PI / 2);
        const noseMat = new THREE.MeshStandardMaterial({ color: 0x0284c7, roughness: 0.2, metalness: 0.5 });
        const nose = new THREE.Mesh(noseGeo, noseMat);
        nose.position.z = 19;
        planeGroup.add(nose);

        // Cockpit Glass
        const glassGeo = new THREE.SphereGeometry(1.8, 16, 16, 0, Math.PI, 0, Math.PI / 3);
        glassGeo.rotateX(Math.PI / 3);
        const glassMat = new THREE.MeshStandardMaterial({ color: 0x0f172a, roughness: 0.1, metalness: 0.9 });
        const glass = new THREE.Mesh(glassGeo, glassMat);
        glass.position.set(0, 0.8, 14);
        planeGroup.add(glass);

        // 2. Wings
        const wingShape = new THREE.Shape();
        wingShape.moveTo(0, 0);
        wingShape.lineTo(16, -6);
        wingShape.lineTo(15, -9);
        wingShape.lineTo(0, -5);
        wingShape.closePath();

        const extrudeSettings = { depth: 0.4, bevelEnabled: true, bevelSegments: 2, steps: 1, bevelSize: 0.1, bevelThickness: 0.1 };
        const wingGeo = new THREE.ExtrudeGeometry(wingShape, extrudeSettings);
        wingGeo.rotateX(Math.PI / 2);

        // Right Wing
        const rightWing = new THREE.Mesh(wingGeo, fuseMat);
        rightWing.position.set(1.5, 0, 2);
        planeGroup.add(rightWing);

        // Left Wing
        const leftWing = new THREE.Mesh(wingGeo, fuseMat);
        leftWing.scale.set(-1, 1, 1);
        leftWing.position.set(-1.5, 0, 2);
        planeGroup.add(leftWing);

        // Winglets
        const wingletGeo = new THREE.BoxGeometry(0.3, 2.5, 1.5);
        const wingletMat = new THREE.MeshStandardMaterial({ color: 0x0284c7 });
        const rightWinglet = new THREE.Mesh(wingletGeo, wingletMat);
        rightWinglet.position.set(17.5, 1.2, -4.5);
        planeGroup.add(rightWinglet);

        const leftWinglet = new THREE.Mesh(wingletGeo, wingletMat);
        leftWinglet.position.set(-17.5, 1.2, -4.5);
        planeGroup.add(leftWinglet);

        // 3. Engines
        const engineGeo = new THREE.CylinderGeometry(1.2, 1.1, 5, 24);
        engineGeo.rotateX(Math.PI / 2);
        const engineMat = new THREE.MeshStandardMaterial({ color: 0x64748b, metalness: 0.8, roughness: 0.2 });

        const rightEngine = new THREE.Mesh(engineGeo, engineMat);
        rightEngine.position.set(6.5, -1.8, 1);
        planeGroup.add(rightEngine);

        const leftEngine = new THREE.Mesh(engineGeo, engineMat);
        leftEngine.position.set(-6.5, -1.8, 1);
        planeGroup.add(leftEngine);

        // 4. Tail Stabilizers
        const vertTailShape = new THREE.Shape();
        vertTailShape.moveTo(0, 0);
        vertTailShape.lineTo(0, 7);
        vertTailShape.lineTo(-3, 6.5);
        vertTailShape.lineTo(-5, 0);
        vertTailShape.closePath();

        const vertTailGeo = new THREE.ExtrudeGeometry(vertTailShape, extrudeSettings);
        const tailMat = new THREE.MeshStandardMaterial({ color: 0x0284c7 });
        const vertTail = new THREE.Mesh(vertTailGeo, tailMat);
        vertTail.position.set(-0.2, 1.5, -11);
        planeGroup.add(vertTail);

        const horizTailShape = new THREE.Shape();
        horizTailShape.moveTo(0, 0);
        horizTailShape.lineTo(6, -2.5);
        horizTailShape.lineTo(5, -4);
        horizTailShape.lineTo(0, -2);
        horizTailShape.closePath();

        const horizTailGeo = new THREE.ExtrudeGeometry(horizTailShape, extrudeSettings);
        horizTailGeo.rotateX(Math.PI / 2);

        const rightHStabilizer = new THREE.Mesh(horizTailGeo, fuseMat);
        rightHStabilizer.position.set(0.5, 0.5, -13);
        planeGroup.add(rightHStabilizer);

        const leftHStabilizer = new THREE.Mesh(horizTailGeo, fuseMat);
        leftHStabilizer.scale.set(-1, 1, 1);
        leftHStabilizer.position.set(-0.5, 0.5, -13);
        planeGroup.add(leftHStabilizer);

        // 5. Landing Gear Group
        landingGearGroup = new THREE.Group();
        const strutGeo = new THREE.CylinderGeometry(0.15, 0.15, 3.5, 12);
        const wheelGeo = new THREE.CylinderGeometry(0.6, 0.6, 0.5, 16);
        wheelGeo.rotateZ(Math.PI / 2);
        const wheelMat = new THREE.MeshStandardMaterial({ color: 0x1e293b, roughness: 0.9 });

        // Nose Gear
        const noseStrut = new THREE.Mesh(strutGeo, engineMat);
        noseStrut.position.set(0, -3.2, 14);
        const noseWheel = new THREE.Mesh(wheelGeo, wheelMat);
        noseWheel.position.set(0, -4.8, 14);
        landingGearGroup.add(noseStrut, noseWheel);

        // Main Gears
        [-5, 5].forEach(x => {
            const mainStrut = new THREE.Mesh(strutGeo, engineMat);
            mainStrut.position.set(x, -3.2, -1);
            const mainWheel = new THREE.Mesh(wheelGeo, wheelMat);
            mainWheel.position.set(x, -4.8, -1);
            landingGearGroup.add(mainStrut, mainWheel);
        });

        planeGroup.add(landingGearGroup);
        scene.add(planeGroup);
    }

    function setRenderMode(mode) {
        currentMode = mode;
        ['solid', 'wireframe', 'aero'].forEach(m => {
            const btn = document.getElementById('btn-mode-' + m);
            if (m === mode) {
                btn.className = 'px-3 py-1.5 rounded-lg bg-sky-600 border border-sky-500 text-white font-bold transition';
            } else {
                btn.className = 'px-3 py-1.5 rounded-lg bg-slate-950 border border-slate-800 text-slate-400 hover:text-white transition';
            }
        });

        planeGroup.traverse(child => {
            if (child.isMesh) {
                if (mode === 'wireframe') {
                    child.material.wireframe = true;
                } else if (mode === 'aero') {
                    child.material.wireframe = false;
                    child.material.color.setHex(child.position.x !== 0 ? 0x10b981 : 0x00f2fe);
                } else {
                    child.material.wireframe = false;
                    child.material.color.setHex(0xe2e8f0);
                }
            }
        });
    }

    function toggleGear() {
        isGearDown = !isGearDown;
        landingGearGroup.visible = isGearDown;
        document.getElementById('btn-gear').innerText = isGearDown ? 'Шасси: Выпущено' : 'Шасси: Убрано';
        document.getElementById('btn-gear').className = isGearDown 
            ? 'px-3 py-1.5 rounded-lg bg-slate-950 border border-slate-800 text-amber-400 font-bold transition'
            : 'px-3 py-1.5 rounded-lg bg-slate-950 border border-slate-800 text-slate-400 font-bold transition';
    }

    function focusHotspot(name) {
        const item = hotspotsData[name];
        if (!item) return;

        document.getElementById('hotspot-title').innerText = item.title;
        document.getElementById('hotspot-text').innerText = item.text;

        // Smooth camera transition
        const targetPos = new THREE.Vector3(...item.camPos);
        animateCameraTo(targetPos);
    }

    function animateCameraTo(target) {
        const start = camera.position.clone();
        let progress = 0;
        const interval = setInterval(() => {
            progress += 0.05;
            camera.position.lerpVectors(start, target, progress);
            controls.update();
            if (progress >= 1) clearInterval(interval);
        }, 16);
    }

    function resetCamera() {
        animateCameraTo(new THREE.Vector3(22, 14, 26));
        document.getElementById('hotspot-title').innerText = 'Общий обзор самолета';
        document.getElementById('hotspot-text').innerText = 'Нажмите на любую деталь для фокусировки камеры.';
    }

    function onWindowResize() {
        const container = document.getElementById('three-container');
        if (!container) return;
        camera.aspect = container.clientWidth / container.clientHeight;
        camera.updateProjectionMatrix();
        renderer.setSize(container.clientWidth, container.clientHeight);
    }

    function animate() {
        requestAnimationFrame(animate);
        controls.update();
        renderer.render(scene, camera);
    }

    window.addEventListener('DOMContentLoaded', init3D);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
