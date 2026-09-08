<?php /** @var array $config */ ?>

<section class="radar-section">
    <div class="radar-header">
        <h1>📡 Интерактивный радар полётов</h1>
        <div class="radar-controls">
            <label><input type="checkbox" id="radarLabels" checked> Метки</label>
            <label><input type="checkbox" id="radarTrails" checked> Следы</label>
            <button id="radarCenter" class="btn btn--sm btn--outline">Центрировать</button>
        </div>
    </div>
    <div id="radarMap" class="radar-map"></div>
    <div id="radarInfo" class="radar-info hidden">
        <div class="radar-info__header">
            <span id="radarCallsign" class="radar-info__callsign"></span>
            <button id="radarClose" class="radar-info__close">✕</button>
        </div>
        <div class="radar-info__body">
            <div class="info-item"><span class="info-label">ICAO24</span><span id="radarIcao"></span></div>
            <div class="info-item"><span class="info-label">Страна</span><span id="radarCountry"></span></div>
            <div class="info-item"><span class="info-label">Высота</span><span id="radarAlt"></span></div>
            <div class="info-item"><span class="info-label">Скорость</span><span id="radarSpeed"></span></div>
            <div class="info-item"><span class="info-label">Курс</span><span id="radarHeading"></span></div>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const map = L.map('radarMap', { zoomControl: true }).setView([55.75, 37.62], 5);
    L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
        attribution: '&copy; CartoDB &copy; OSM',
        maxZoom: 19
    }).addTo(map);

    const markers = {};
    let updateInterval;

    function loadFlights() {
        const bounds = map.getBounds();
        const params = new URLSearchParams({
            min_lat: bounds.getSouth(),
            max_lat: bounds.getNorth(),
            min_lon: bounds.getWest(),
            max_lon: bounds.getEast()
        });

        fetch('/radar/api?' + params.toString())
            .then(r => r.json())
            .then(data => {
                Object.keys(markers).forEach(k => map.removeLayer(markers[k]));
                (data.states || []).forEach(f => {
                    if (!f.lat || !f.lon) return;
                    const icon = L.divIcon({
                        className: 'radar-plane',
                        html: `<div style="transform:rotate(${f.heading || 0}deg)">✈</div>`,
                        iconSize: [20, 20]
                    });
                    const m = L.marker([f.lat, f.lon], {icon}).addTo(map);
                    m.bindTooltip(f.callsign || f.icao24, {permanent: false});
                    m.on('click', () => showFlightInfo(f));
                    markers[f.icao24] = m;
                });
            })
            .catch(() => {});
    }

    function showFlightInfo(f) {
        document.getElementById('radarInfo').classList.remove('hidden');
        document.getElementById('radarCallsign').textContent = f.callsign || 'N/A';
        document.getElementById('radarIcao').textContent = f.icao24;
        document.getElementById('radarCountry').textContent = f.country;
        document.getElementById('radarAlt').textContent = f.alt ? Math.round(f.alt * 0.3048) + ' м' : 'N/A';
        document.getElementById('radarSpeed').textContent = f.velocity ? Math.round(f.velocity * 1.852) + ' км/ч' : 'N/A';
        document.getElementById('radarHeading').textContent = f.heading ? Math.round(f.heading) + '°' : 'N/A';
    }

    map.on('moveend', loadFlights);
    loadFlights();
    updateInterval = setInterval(loadFlights, 10000);

    document.getElementById('radarClose').addEventListener('click', () => {
        document.getElementById('radarInfo').classList.add('hidden');
    });
});
</script>
