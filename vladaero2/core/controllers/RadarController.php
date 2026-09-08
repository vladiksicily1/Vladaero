<?php
namespace VladAero\Controllers;

use VladAero\Core\{Controller, Session, Database};

/**
 * RadarController - Интерактивный радар полётов
 */
class RadarController extends Controller
{
    public function index()
    {
        $this->view('pages.radar.index');
    }

    public function api()
    {
        header('Content-Type: application/json');
        $minLat = (float)($this->query('min_lat') ?? 30);
        $maxLat = (float)($this->query('max_lat') ?? 72);
        $minLon = (float)($this->query('min_lon') ?? -30);
        $maxLon = (float)($this->query('max_lon') ?? 60);

        $url = "https://opensky-network.org/api/states/all?"
            . "lamin={$minLat}&lamax={$maxLat}&lomin={$minLon}&lomax={$maxLon}";

        $ctx = stream_context_create([
            'http' => ['timeout' => 8, 'user_agent' => 'VladAeroRadar/1.0'],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if (!$raw) {
            echo json_encode(['states' => []]);
            return;
        }

        $data = json_decode($raw, true);
        $states = $data['states'] ?? [];
        $flights = [];

        foreach (array_slice($states, 0, 500) as $s) {
            $flights[] = [
                'icao24' => $s[0] ?? '',
                'callsign' => trim($s[1] ?? ''),
                'country' => $s[2] ?? '',
                'lon' => $s[5] ?? null,
                'lat' => $s[6] ?? null,
                'alt' => $s[7] ?? null,
                'heading' => $s[8] ?? null,
                'velocity' => $s[9] ?? null,
                'squawk' => $s[14] ?? '',
                'on_ground' => $s[8] === 0,
            ];
        }

        echo json_encode(['states' => $flights]);
    }
}
