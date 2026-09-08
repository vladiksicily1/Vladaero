<?php
$pageTitle = 'Радар полетов онлайн (Live Flight Radar ADS-B)';
$metaDescription = 'Интерактивный радар живых полетов гражданской авиации в реальном времени. Эшелоны, путевая скорость, позывные и траектории полета.';
require_once __DIR__ . '/includes/header.php';
?>

<!-- Leaflet CSS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

    <!-- Top Radar Control Bar -->
    <div class="va-card p-4 mb-6 flex flex-col md:flex-row items-center justify-between gap-4">
        <div class="flex items-center space-x-3">
            <div class="w-10 h-10 rounded-xl bg-emerald-500/10 border border-emerald-500/30 flex items-center justify-center text-emerald-400">
                <i data-lucide="radar" class="w-5 h-5 animate-spin [animation-duration:8s]"></i>
            </div>
            <div>
                <h1 class="text-lg font-bold text-white flex items-center space-x-2">
                    <span>Радар полетов ADS-B</span>
                    <span class="px-2 py-0.5 rounded text-[10px] font-mono bg-emerald-950 text-emerald-400 border border-emerald-800">LIVE</span>
                </h1>
                <div class="text-xs text-slate-400 font-mono">
                    Бортов в воздухе: <strong id="radar-count" class="text-sky-400">Загрузка...</strong> | Источник: <span class="text-slate-300">OpenSky Network</span>
                </div>
            </div>
        </div>

        <!-- Filter Controls -->
        <div class="flex flex-wrap items-center gap-3 text-xs font-mono">
            <div class="flex items-center space-x-2 bg-slate-950 px-3 py-1.5 rounded-xl border border-slate-800">
                <span class="text-slate-400">Позывной:</span>
                <input type="text" id="callsign-filter" placeholder="AFL, SBI..." class="bg-transparent text-slate-100 placeholder-slate-600 focus:outline-none w-24 uppercase font-bold text-sky-400">
            </div>

            <div class="flex items-center space-x-2 bg-slate-950 px-3 py-1.5 rounded-xl border border-slate-800">
                <span class="text-slate-400">Слой:</span>
                <select id="map-tile-select" onchange="changeMapLayer(this.value)" class="bg-transparent text-slate-200 focus:outline-none cursor-pointer">
                    <option value="dark">Dark Matter (Авиационный)</option>
                    <option value="light">CartoDB Positron</option>
                    <option value="satellite">ESRI Satellite (Спутник)</option>
                    <option value="osm">OpenStreetMap</option>
                </select>
            </div>

            <button onclick="fetchRadarData(true)" class="p-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-sky-400 border border-slate-700 transition" title="Принудительное обновление">
                <i data-lucide="refresh-cw" class="w-4 h-4"></i>
            </button>
        </div>
    </div>

    <!-- Map & Flight Inspector Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">

        <!-- Main Leaflet Map Viewport -->
        <div class="lg:col-span-3 h-[650px] rounded-2xl overflow-hidden border border-slate-800 relative shadow-2xl">
            <div id="radar-map" class="w-full h-full bg-slate-950"></div>
            
            <!-- Map Overlay Legend -->
            <div class="absolute bottom-4 left-4 z-[400] bg-slate-950/90 backdrop-blur-md p-3 rounded-xl border border-slate-800 text-[11px] font-mono text-slate-400 space-y-1 hidden sm:block">
                <div class="flex items-center space-x-2">
                    <span class="w-2.5 h-2.5 rounded-full bg-emerald-400"></span>
                    <span>Эшелон FL300+ (Крейсерский полет)</span>
                </div>
                <div class="flex items-center space-x-2">
                    <span class="w-2.5 h-2.5 rounded-full bg-sky-400"></span>
                    <span>Эшелон FL100 - FL300 (Набор / Снижение)</span>
                </div>
                <div class="flex items-center space-x-2">
                    <span class="w-2.5 h-2.5 rounded-full bg-amber-400"></span>
                    <span>Ниже 10 000 ft (Взлет / Заход на посадку)</span>
                </div>
            </div>
        </div>

        <!-- Selected Flight Inspector Card -->
        <div class="lg:col-span-1 space-y-4">
            <div id="flight-card" class="va-card p-5">
                <div class="text-xs font-mono text-slate-400 uppercase tracking-wider mb-2 flex items-center justify-between">
                    <span>Карточка борта</span>
                    <i data-lucide="plane" class="w-4 h-4 text-sky-400"></i>
                </div>
                <div id="flight-card-content" class="text-center py-10 text-xs text-slate-500">
                    Кликните по любому самолету на карте для просмотра параметров полета
                </div>
            </div>

            <!-- Top Active Airports -->
            <div class="va-card p-5">
                <div class="text-xs font-mono text-slate-400 uppercase tracking-wider mb-3 flex items-center justify-between">
                    <span>Ключевые Аэропорты</span>
                    <i data-lucide="map-pin" class="w-4 h-4 text-amber-400"></i>
                </div>
                <div class="space-y-2 text-xs font-mono">
                    <button onclick="flyToAirport(55.9726, 37.4145, 10)" class="w-full p-2 rounded-lg bg-slate-950 hover:bg-slate-900 border border-slate-800 text-left flex items-center justify-between transition">
                        <span>Шереметьево (SVO)</span>
                        <span class="text-sky-400 font-bold">UUEE</span>
                    </button>
                    <button onclick="flyToAirport(55.4086, 37.9061, 10)" class="w-full p-2 rounded-lg bg-slate-950 hover:bg-slate-900 border border-slate-800 text-left flex items-center justify-between transition">
                        <span>Домодедово (DME)</span>
                        <span class="text-sky-400 font-bold">UUDD</span>
                    </button>
                    <button onclick="flyToAirport(59.8002, 30.2625, 10)" class="w-full p-2 rounded-lg bg-slate-950 hover:bg-slate-900 border border-slate-800 text-left flex items-center justify-between transition">
                        <span>Пулково (LED)</span>
                        <span class="text-sky-400 font-bold">ULLI</span>
                    </button>
                    <button onclick="flyToAirport(51.4775, -0.4613, 10)" class="w-full p-2 rounded-lg bg-slate-950 hover:bg-slate-900 border border-slate-800 text-left flex items-center justify-between transition">
                        <span>Лондон Хитроу (LHR)</span>
                        <span class="text-sky-400 font-bold">EGLL</span>
                    </button>
                    <button onclick="flyToAirport(25.2527, 55.3644, 10)" class="w-full p-2 rounded-lg bg-slate-950 hover:bg-slate-900 border border-slate-800 text-left flex items-center justify-between transition">
                        <span>Дубай (DXB)</span>
                        <span class="text-sky-400 font-bold">OMDB</span>
                    </button>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- Leaflet JS -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    let map;
    let planeMarkers = {};
    let tileLayer;
    let activeTrail = null;

    const tileUrls = {
        dark: 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',
        light: 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png',
        satellite: 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
        osm: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png'
    };

    function initRadarMap() {
        map = L.map('radar-map', {
            center: [55.75, 37.61],
            zoom: 6,
            minZoom: 3,
            maxZoom: 14
        });

        tileLayer = L.tileLayer(tileUrls.dark, {
            attribution: '&copy; CartoDB &copy; OpenStreetMap'
        }).addTo(map);

        map.on('moveend', () => {
            fetchRadarData();
        });

        fetchRadarData();
        setInterval(fetchRadarData, 12000); // Polling every 12s
    }

    function changeMapLayer(type) {
        if (tileLayer) map.removeLayer(tileLayer);
        tileLayer = L.tileLayer(tileUrls[type] || tileUrls.dark, {
            attribution: '&copy; Map Providers'
        }).addTo(map);
    }

    function flyToAirport(lat, lon, zoom) {
        map.flyTo([lat, lon], zoom, { duration: 1.5 });
    }

    function createPlaneIcon(heading, altFt) {
        let color = '#38bdf8'; // Blue
        if (altFt >= 30000) color = '#10b981'; // Green
        else if (altFt < 10000) color = '#ffb703'; // Amber

        const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="28" height="28" style="transform: rotate(${heading}deg); filter: drop-shadow(0 2px 4px rgba(0,0,0,0.8));">
            <path fill="${color}" d="M21 16v-2l-8-5V3.5c0-.83-.67-1.5-1.5-1.5S10 2.67 10 3.5V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5l8 2.5z"/>
        </svg>`;

        return L.divIcon({
            html: svg,
            className: 'plane-marker',
            iconSize: [28, 28],
            iconAnchor: [14, 14]
        });
    }

    function fetchRadarData(force = false) {
        const bounds = map.getBounds();
        const url = `<?= url('/api/radar_data.php') ?>?lamin=${bounds.getSouth()}&lamax=${bounds.getNorth()}&lomin=${bounds.getWest()}&lomax=${bounds.getEast()}`;

        fetch(url)
            .then(r => r.json())
            .then(data => {
                if (!data.flights) return;

                document.getElementById('radar-count').innerText = data.flights.length + ' бортов';
                const callsignFilter = document.getElementById('callsign-filter').value.trim().toUpperCase();

                const currentIcaos = new Set();

                data.flights.forEach(f => {
                    if (callsignFilter && !f.callsign.includes(callsignFilter)) {
                        return;
                    }

                    currentIcaos.add(f.icao24);

                    if (planeMarkers[f.icao24]) {
                        // Smoothly update position
                        planeMarkers[f.icao24].setLatLng([f.lat, f.lon]);
                        planeMarkers[f.icao24].setIcon(createPlaneIcon(f.heading, f.alt_ft));
                        planeMarkers[f.icao24].flightData = f;
                    } else {
                        const marker = L.marker([f.lat, f.lon], {
                            icon: createPlaneIcon(f.heading, f.alt_ft)
                        }).addTo(map);

                        marker.flightData = f;
                        marker.on('click', () => selectFlight(marker.flightData));
                        planeMarkers[f.icao24] = marker;
                    }
                });

                // Remove stale markers outside viewport
                Object.keys(planeMarkers).forEach(icao => {
                    if (!currentIcaos.has(icao)) {
                        map.removeLayer(planeMarkers[icao]);
                        delete planeMarkers[icao];
                    }
                });
            })
            .catch(err => console.log('Radar fetch error:', err));
    }

    function selectFlight(f) {
        const content = document.getElementById('flight-card-content');
        content.innerHTML = `
            <div class="space-y-4 text-left">
                <div class="p-3 bg-slate-950 rounded-xl border border-sky-500/30 flex items-center justify-between">
                    <div>
                        <div class="text-[10px] text-slate-400 font-mono">ПОЗЫВНОЙ // CALLSIGN</div>
                        <div class="text-xl font-bold font-mono text-sky-400">${f.callsign}</div>
                    </div>
                    <div class="text-right">
                        <div class="text-[10px] text-slate-400 font-mono">ICAO24</div>
                        <div class="text-xs font-mono text-slate-200">${f.icao24.toUpperCase()}</div>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2 text-xs font-mono">
                    <div class="p-2 rounded-lg bg-slate-950 border border-slate-800">
                        <div class="text-slate-400 text-[10px]">Высота</div>
                        <div class="font-bold text-emerald-400">${f.alt_ft.toLocaleString()} ft (${Math.round(f.alt_m)} м)</div>
                    </div>
                    <div class="p-2 rounded-lg bg-slate-950 border border-slate-800">
                        <div class="text-slate-400 text-[10px]">Скорость</div>
                        <div class="font-bold text-sky-400">${f.velocity_kt} kt (${f.velocity_kmh} км/ч)</div>
                    </div>
                    <div class="p-2 rounded-lg bg-slate-950 border border-slate-800">
                        <div class="text-slate-400 text-[10px]">Курс (Heading)</div>
                        <div class="font-bold text-amber-400">${Math.round(f.heading)}°</div>
                    </div>
                    <div class="p-2 rounded-lg bg-slate-950 border border-slate-800">
                        <div class="text-slate-400 text-[10px]">Страна</div>
                        <div class="font-bold text-slate-200 truncate">${f.country}</div>
                    </div>
                </div>

                <div class="pt-2">
                    <a href="<?= url('/weather.php') ?>" class="block w-full py-2 bg-sky-600 hover:bg-sky-500 text-white text-center font-mono font-bold rounded-xl text-xs transition">
                        Проверить метеоусловия
                    </a>
                </div>
            </div>`;
        lucide.createIcons();
    }

    document.getElementById('callsign-filter').addEventListener('input', () => fetchRadarData(true));

    window.addEventListener('DOMContentLoaded', initRadarMap);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
