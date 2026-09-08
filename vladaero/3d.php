<?php
declare(strict_types=1);

namespace VladAero;

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$slug = trim($_GET['slug'] ?? 'tu-154m');
$plane = DB::fetchOne("SELECT * FROM `va_aircraft` WHERE `slug` = :s", ['s' => $slug]);
if (!$plane) {
    $plane = DB::fetchOne("SELECT * FROM `va_aircraft` LIMIT 1");
}

$pageTitle = "3D Модель ВС: " . ($plane ? $plane['model_name'] : 'Самолёт');
require_once __DIR__ . '/includes/header.php';
?>

<!-- Three.js & GLTF Loader & OrbitControls CDN -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/GLTFLoader.js"></script>
<script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.js"></script>

<div class="relative w-full h-[calc(100vh-4rem)] bg-slate-950 flex flex-col overflow-hidden select-none">
    
    <!-- Top HUD Controls -->
    <div class="absolute top-4 left-4 right-4 z-20 flex flex-wrap items-center justify-between gap-4 pointer-events-none">
        <div class="glass-hud p-3.5 rounded-2xl border border-sky-500/30 pointer-events-auto flex items-center space-x-3 text-xs font-mono shadow-2xl">
            <div class="w-8 h-8 rounded-xl bg-sky-600/30 border border-sky-500/40 text-sky-400 flex items-center justify-center font-bold">
                ✈
            </div>
            <div>
                <div class="font-bold text-white text-sm"><?= e($plane ? $plane['model_name'] : 'Авиалайнер') ?></div>
                <div class="text-[10px] text-sky-400 font-mono">3D WebGL Real glTF Engine • Scale 1:1</div>
            </div>
        </div>

        <div class="glass-hud p-2 rounded-2xl border border-sky-500/30 pointer-events-auto flex items-center space-x-2 text-xs font-mono shadow-2xl">
            <button onclick="toggleWireframe()" id="wireframeBtn" class="px-3.5 py-2 rounded-xl bg-slate-900/90 hover:bg-slate-800 text-sky-300 border border-white/5 transition flex items-center space-x-1.5">
                <i data-lucide="box" class="w-3.5 h-3.5"></i>
                <span>Каркас (X-Ray)</span>
            </button>
            <button onclick="toggleAutoRotate()" id="autoRotateBtn" class="px-3.5 py-2 rounded-xl bg-slate-900/90 hover:bg-slate-800 text-amber-400 border border-white/5 transition flex items-center space-x-1.5">
                <i data-lucide="rotate-cw" class="w-3.5 h-3.5"></i>
                <span>Вращение: ВКЛ</span>
            </button>
            <button onclick="reset3DCamera()" class="px-3.5 py-2 rounded-xl bg-slate-900/90 hover:bg-slate-800 text-slate-300 border border-white/5 transition">
                Сброс камеры
            </button>
        </div>
    </div>

    <!-- 3D Canvas -->
    <div id="threeCanvasContainer" class="w-full h-full cursor-grab active:cursor-grabbing"></div>

    <!-- Hotspots & Telemetry Overlay -->
    <div class="absolute bottom-4 left-4 z-20 glass-hud p-4 rounded-2xl border border-sky-500/20 text-xs font-mono text-slate-300 pointer-events-none max-w-md shadow-2xl space-y-1.5">
        <div class="font-bold text-sky-400 uppercase text-[10px] flex items-center space-x-1.5">
            <span class="w-2 h-2 rounded-full bg-emerald-400 animate-ping"></span>
            <span>Интерактивная 3D Модель</span>
        </div>
        <div class="text-slate-400 text-[11px]">
            Управление: Вращение — зажатая ЛКМ, перемещение — ПКМ, зум — колесо мыши.
        </div>
    </div>

</div>

<!-- Three.js Real 3D GLTF Loading & Interactive Orbit Script -->
<script>
    let scene, camera, renderer, controls, loadedModel;
    let isWireframe = false;
    let autoRotate = true;

    document.addEventListener('DOMContentLoaded', () => {
        init3DScene();
    });

    function init3DScene() {
        const container = document.getElementById('threeCanvasContainer');
        const width = container.clientWidth;
        const height = container.clientHeight;

        scene = new THREE.Scene();
        scene.fog = new THREE.FogExp2(0x0b132b, 0.012);

        camera = new THREE.PerspectiveCamera(45, width / height, 0.1, 1000);
        camera.position.set(15, 8, 20);

        renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
        renderer.setSize(width, height);
        renderer.setPixelRatio(window.devicePixelRatio);
        renderer.shadowMap.enabled = true;
        renderer.shadowMap.type = THREE.PCFSoftShadowMap;
        container.appendChild(renderer.domElement);

        // OrbitControls
        controls = new THREE.OrbitControls(camera, renderer.domElement);
        controls.enableDamping = true;
        controls.dampingFactor = 0.05;
        controls.maxDistance = 60;
        controls.minDistance = 3;

        // Lighting
        const ambLight = new THREE.AmbientLight(0xffffff, 0.9);
        scene.add(ambLight);

        const dirLight = new THREE.DirectionalLight(0x38bdf8, 1.4);
        dirLight.position.set(20, 30, 20);
        dirLight.castShadow = true;
        scene.add(dirLight);

        const fillLight = new THREE.DirectionalLight(0xf59e0b, 0.5);
        fillLight.position.set(-20, -10, -20);
        scene.add(fillLight);

        // Runway Grid
        const grid = new THREE.GridHelper(60, 60, 0x0ea5e9, 0x1e293b);
        grid.position.y = -3;
        scene.add(grid);

        // Try Loading Real GLB Aircraft Model
        const loader = new THREE.GLTFLoader();
        loader.load(
            'assets/models/airplane.glb',
            (gltf) => {
                loadedModel = gltf.scene;
                loadedModel.scale.set(0.012, 0.012, 0.012);
                loadedModel.position.set(0, 0, 0);
                scene.add(loadedModel);
            },
            undefined,
            (error) => {
                // Fallback to high-fidelity procedural airliner
                createProceduralAirliner();
            }
        );

        // Window Resize Listener
        window.addEventListener('resize', onWindowResize);

        // Animation Render Loop
        function animate() {
            requestAnimationFrame(animate);
            controls.update();

            if (autoRotate && loadedModel) {
                loadedModel.rotation.y += 0.004;
            }

            renderer.render(scene, camera);
        }
        animate();
    }

    function createProceduralAirliner() {
        const planeGroup = new THREE.Group();

        // 1. Fuselage
        const fuseGeo = new THREE.CylinderGeometry(1.6, 1.2, 26, 32);
        const fuseMat = new THREE.MeshStandardMaterial({ color: 0xf8fafc, roughness: 0.2, metalness: 0.3 });
        const fuseMesh = new THREE.Mesh(fuseGeo, fuseMat);
        fuseMesh.rotation.z = Math.PI / 2;
        planeGroup.add(fuseMesh);

        // Cockpit Nose Cone
        const noseGeo = new THREE.ConeGeometry(1.6, 5, 32);
        const noseMesh = new THREE.Mesh(noseGeo, fuseMat);
        noseMesh.rotation.z = -Math.PI / 2;
        noseMesh.position.set(15.5, 0, 0);
        planeGroup.add(noseMesh);

        // 2. Wings (Swept)
        const wingGeo = new THREE.BoxGeometry(24, 0.25, 4.5);
        const wingMat = new THREE.MeshStandardMaterial({ color: 0x0284c7, roughness: 0.4, metalness: 0.2 });
        const wingMesh = new THREE.Mesh(wingGeo, wingMat);
        wingMesh.position.set(2, 0, 0);
        planeGroup.add(wingMesh);

        // 3. T-Tail Fin & Stabilizer
        const finGeo = new THREE.BoxGeometry(3.5, 6, 0.3);
        const finMesh = new THREE.Mesh(finGeo, wingMat);
        finMesh.position.set(-11, 3, 0);
        planeGroup.add(finMesh);

        const stabGeo = new THREE.BoxGeometry(2.5, 0.2, 8);
        const stabMesh = new THREE.Mesh(stabGeo, wingMat);
        stabMesh.position.set(-12, 5.8, 0);
        planeGroup.add(stabMesh);

        // 4. Turbofan Engines
        const engGeo = new THREE.CylinderGeometry(0.85, 0.85, 4.5, 24);
        const engMat = new THREE.MeshStandardMaterial({ color: 0x334155, roughness: 0.2, metalness: 0.8 });
        
        const engL = new THREE.Mesh(engGeo, engMat);
        engL.rotation.z = Math.PI / 2;
        engL.position.set(-7, 1.2, 2.5);
        planeGroup.add(engL);

        const engR = new THREE.Mesh(engGeo, engMat);
        engR.rotation.z = Math.PI / 2;
        engR.position.set(-7, 1.2, -2.5);
        planeGroup.add(engR);

        loadedModel = planeGroup;
        scene.add(loadedModel);
    }

    function onWindowResize() {
        const container = document.getElementById('threeCanvasContainer');
        camera.aspect = container.clientWidth / container.clientHeight;
        camera.updateProjectionMatrix();
        renderer.setSize(container.clientWidth, container.clientHeight);
    }

    function toggleWireframe() {
        isWireframe = !isWireframe;
        if (loadedModel) {
            loadedModel.traverse((child) => {
                if (child.isMesh) child.material.wireframe = isWireframe;
            });
        }
        document.getElementById('wireframeBtn').classList.toggle('bg-sky-600', isWireframe);
    }

    function toggleAutoRotate() {
        autoRotate = !autoRotate;
        document.getElementById('autoRotateBtn').innerText = 'Вращение: ' + (autoRotate ? 'ВКЛ' : 'ВЫКЛ');
    }

    function reset3DCamera() {
        camera.position.set(15, 8, 20);
        controls.target.set(0, 0, 0);
        if (loadedModel) {
            loadedModel.rotation.set(0, 0, 0);
        }
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
