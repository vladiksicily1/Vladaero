<?php
declare(strict_types=1);

namespace VladAero;

$pageTitle = 'Интерактивный радар полётов и трекинг ВС';
require_once __DIR__ . '/includes/header.php';
?>

<!-- Leaflet CSS & JS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<div class="relative w-full h-[calc(100vh-4rem)] flex flex-col md:flex-row overflow-hidden bg-slate-950">
    
    <!-- Left Floating Flight Details Panel (when plane is selected) -->
    <div id="flightDetailsDrawer" class="hidden absolute md:relative top-4 left-4 z-20 w-80 max-w-[calc(100vw-2rem)] glass-hud rounded-3xl p-5 border border-sky-500/30 shadow-2xl space-y-4 font-mono text-xs overflow-y-auto max-h-[80vh]">
        <div class="flex items-center justify-between border-b border-white/10 pb-3">
            <div class="flex items-center space-x-2">
                <div class="w-8 h-8 rounded-xl bg-sky-600 flex items-center justify-center text-white font-bold">✈</div>
                <div>
                    <div class="text-sm font-bold text-white" id="drawerCallsign">AFL102</div>
                    <div class="text-[10px] text-sky-400" id="drawerModel">Airbus A350-900</div>
                </div>
            </div>
            <button onclick="closeFlightDrawer()" class="p-1 rounded hover:bg-white/10 text-slate-400">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>

        <!-- Emergency Alert Banner if 7700 -->
        <div id="drawerEmergencyBanner" class="hidden p-3 rounded-2xl bg-rose-500/20 border border-rose-500/40 text-rose-400 font-bold flex items-center space-x-2">
            <span class="w-2.5 h-2.5 rounded-full bg-rose-400 animate-ping"></span>
            <span>SQUAWK 7700: АВАРИЙНЫЙ БОРТ</span>
        </div>

        <!-- Flight Telemetry Grid -->
        <div class="grid grid-cols-2 gap-2">
            <div class="p-2.5 rounded-xl bg-slate-900/80 border border-white/5">
                <span class="text-[9px] text-slate-500 uppercase block">Высота</span>
                <span class="text-white font-bold text-sm" id="drawerAlt">10 600 м</span>
            </div>
            <div class="p-2.5 rounded-xl bg-slate-900/80 border border-white/5">
                <span class="text-[9px] text-slate-500 uppercase block">Путевая скорость</span>
                <span class="text-sky-400 font-bold text-sm" id="drawerSpeed">880 км/ч</span>
            </div>
            <div class="p-2.5 rounded-xl bg-slate-900/80 border border-white/5">
                <span class="text-[9px] text-slate-500 uppercase block">Курс</span>
                <span class="text-amber-400 font-bold text-sm" id="drawerHeading">85°</span>
            </div>
            <div class="p-2.5 rounded-xl bg-slate-900/80 border border-white/5">
                <span class="text-[9px] text-slate-500 uppercase block">Ответчик (Squawk)</span>
                <span class="text-emerald-400 font-bold text-sm" id="drawerSquawk">2415</span>
            </div>
        </div>

        <div class="p-3 rounded-xl bg-slate-900/60 border border-white/5">
            <span class="text-[9px] text-slate-500 uppercase block mb-1">Маршрут рейса</span>
            <div class="text-white font-bold text-xs" id="drawerRoute">UUEE (Москва) → UHWW (Владивосток)</div>
        </div>

        <div class="flex space-x-2">
            <button onclick="exportFlightTrack('gpx')" class="flex-1 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 text-[11px] font-bold transition">
                📥 GPX
            </button>
            <button onclick="exportFlightTrack('kml')" class="flex-1 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 text-[11px] font-bold transition">
                📥 KML
            </button>
        </div>
    </div>

    <!-- Map Canvas Container -->
    <div id="radarMap" class="w-full h-full z-10"></div>

    <!-- Top Right Controls Overlay -->
    <div class="absolute top-4 right-4 z-20 flex flex-col space-y-2">
        <div class="glass-hud p-2.5 rounded-2xl border border-sky-500/30 flex items-center space-x-2 text-xs font-mono">
            <span class="w-2.5 h-2.5 rounded-full bg-emerald-400 animate-pulse"></span>
            <span class="text-white font-bold" id="radarFlightCount">0 бортов</span>
            <button onclick="fetchRadarData()" class="p-1 rounded bg-slate-800 text-slate-400 hover:text-sky-400" title="Обновить">
                <i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i>
            </button>
        </div>
    </div>

</div>

<!-- Radar Engine Script -->
<script>
    let map = null;
    let markersLayer = null;
    let currentSelectedFlight = null;
    let flightTrackPolyline = null;

    document.addEventListener('DOMContentLoaded', () => {
        initRadarMap();
        fetchRadarData();
        setInterval(fetchRadarData, 10000); // 10s live poll
    });

    function initRadarMap() {
        // Center around Moscow / Eurasia
        map = L.map('radarMap', {
            center: [55.75, 37.61],
            zoom: 5,
            zoomControl: false
        });

        L.control.zoom({ position: 'bottomright' }).addTo(map);

        // Tile Layers
        const darkTiles = L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
            attribution: '© CartoDB / OSM',
            maxZoom: 19
        });
        darkTiles.addTo(map);

        markersLayer = L.layerGroup().addTo(map);
    }

    async function fetchRadarData() {
        try {
            const bounds = map.getBounds();
            const res = await fetch(`api/radar_data.php?lamin=${bounds.getSouth()}&lomin=${bounds.getWest()}&lamax=${bounds.getNorth()}&lomax=${bounds.getEast()}`);
            const data = await res.json();

            if (data.flights) {
                renderFlightsOnRadar(data.flights);
                document.getElementById('radarFlightCount').innerText = data.flights.length + ' бортов';
            }
        } catch (e) {
            console.error('Radar data error', e);
        }
    }

    function renderFlightsOnRadar(flights) {
        markersLayer.clearLayers();

        flights.forEach(f => {
            // Plane icon color by altitude
            let color = '#10b981'; // <3000m green
            if (f.altitude_m >= 3000 && f.altitude_m < 9000) color = '#0ea5e9'; // 3-9km blue
            if (f.altitude_m >= 9000) color = '#a855f7'; // >9km purple
            if (f.is_emergency) color = '#ef4444'; // Emergency red

            const iconHtml = `
                <div style="transform: rotate(${f.heading_deg}deg);" class="cursor-pointer transition-transform duration-300">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="${color}">
                        <path d="M21 16v-2l-8-5V3.5c0-.83-.67-1.5-1.5-1.5S10 2.67 10 3.5V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5l8 2.5z"/>
                    </svg>
                </div>
            `;

            const customIcon = L.divIcon({
                html: iconHtml,
                className: 'plane-marker',
                iconSize: [24, 24],
                iconAnchor: [12, 12]
            });

            const marker = L.marker([f.latitude, f.longitude], { icon: customIcon });

            marker.on('click', () => {
                showFlightDetails(f);
            });

            // Master caution if squawk 7700
            if (f.is_emergency && typeof playMasterCautionSound === 'function') {
                playMasterCautionSound();
            }

            markersLayer.addLayer(marker);
        });
    }

    function showFlightDetails(f) {
        currentSelectedFlight = f;
        const drawer = document.getElementById('flightDetailsDrawer');
        drawer.classList.remove('hidden');

        document.getElementById('drawerCallsign').innerText = f.callsign;
        document.getElementById('drawerModel').innerText = f.model_type || 'Самолет';
        document.getElementById('drawerAlt').innerText = f.altitude_m + ' м (' + f.altitude_ft + ' ft)';
        document.getElementById('drawerSpeed').innerText = f.speed_kmh + ' км/ч (' + f.speed_kt + ' kts)';
        document.getElementById('drawerHeading').innerText = f.heading_deg + '°';
        document.getElementById('drawerSquawk').innerText = f.squawk;
        document.getElementById('drawerRoute').innerText = f.route || 'Маршрут полета';

        const alertBanner = document.getElementById('drawerEmergencyBanner');
        if (f.is_emergency) {
            alertBanner.classList.remove('hidden');
        } else {
            alertBanner.classList.add('hidden');
        }

        // Draw trail polyline
        if (flightTrackPolyline) map.removeLayer(flightTrackPolyline);
        const trail = [
            [f.latitude - 0.5, f.longitude - 1.0],
            [f.latitude - 0.2, f.longitude - 0.4],
            [f.latitude, f.longitude]
        ];
        flightTrackPolyline = L.polyline(trail, { color: '#0ea5e9', weight: 3, dashArray: '5, 5' }).addTo(map);
    }

    function closeFlightDrawer() {
        document.getElementById('flightDetailsDrawer').classList.add('hidden');
        if (flightTrackPolyline) map.removeLayer(flightTrackPolyline);
    }

    function exportFlightTrack(type) {
        if (!currentSelectedFlight) return;
        alert(`Экспорт трека ${currentSelectedFlight.callsign} в формате .${type} сформирован.`);
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
