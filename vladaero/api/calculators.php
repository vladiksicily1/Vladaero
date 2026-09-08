<?php
declare(strict_types=1);

namespace VladAero;

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/e6b.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'wind':
        $rwy = (int)($_POST['runway_heading'] ?? 0);
        $wDir = (int)($_POST['wind_direction'] ?? 0);
        $wSpd = (int)($_POST['wind_speed'] ?? 0);
        echo json_encode(E6B::calculateWindComponents($rwy, $wDir, $wSpd));
        break;

    case 'density_altitude':
        $pAlt = (int)($_POST['pressure_alt'] ?? 0);
        $temp = (float)($_POST['temp_c'] ?? 15);
        $qnh = (float)($_POST['qnh_hpa'] ?? 1013.25);
        echo json_encode(E6B::calculateDensityAltitude($pAlt, $temp, $qnh));
        break;

    case 'descent':
        $gs = (int)($_POST['ground_speed'] ?? 400);
        $cAlt = (int)($_POST['current_alt'] ?? 35000);
        $tAlt = (int)($_POST['target_alt'] ?? 3000);
        $slope = (float)($_POST['glide_slope'] ?? 3.0);
        echo json_encode(E6B::calculateDescent($gs, $cAlt, $tAlt, $slope));
        break;

    case 'great_circle':
        $lat1 = (float)($_POST['lat1'] ?? 55.97);
        $lon1 = (float)($_POST['lon1'] ?? 37.41);
        $lat2 = (float)($_POST['lat2'] ?? 43.39);
        $lon2 = (float)($_POST['lon2'] ?? 132.14);
        echo json_encode(E6B::calculateGreatCircle($lat1, $lon1, $lat2, $lon2));
        break;

    case 'compensation':
        $delay = (int)($_POST['delay_hours'] ?? 3);
        $dist = (int)($_POST['distance_km'] ?? 1500);
        $jur = $_POST['jurisdiction'] ?? 'eu261';
        $cause = $_POST['cause'] ?? 'airline_fault';
        echo json_encode(E6B::calculateCompensation($delay, $dist, $jur, $cause));
        break;

    default:
        echo json_encode(['error' => 'Unknown calculator action']);
        break;
}
