<?php
/**
 * VladAero E6B Calculators Async Calculation API
 */

header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/includes/e6b.php';

$action = trim($_POST['action'] ?? $_GET['action'] ?? '');

switch ($action) {
    case 'crosswind':
        $rwy = (float)($_POST['runway_heading'] ?? 0);
        $wdir = (float)($_POST['wind_dir'] ?? 0);
        $wspeed = (float)($_POST['wind_speed'] ?? 0);
        echo json_encode(['success' => true, 'result' => E6B::calculateWindComponents($rwy, $wdir, $wspeed)]);
        break;

    case 'density_altitude':
        $elev = (float)($_POST['elevation_ft'] ?? 0);
        $qnh = (float)($_POST['qnh_hpa'] ?? 1013.25);
        $temp = (float)($_POST['temp_c'] ?? 15);
        echo json_encode(['success' => true, 'result' => E6B::calculateDensityAltitude($elev, $qnh, $temp)]);
        break;

    case 'tas':
        $ias = (float)($_POST['ias_kt'] ?? 120);
        $alt = (float)($_POST['alt_ft'] ?? 5000);
        $temp = (float)($_POST['temp_c'] ?? 15);
        echo json_encode(['success' => true, 'result' => E6B::calculateTas($ias, $alt, $temp)]);
        break;

    case 'descent':
        $cruise = (float)($_POST['cruise_alt'] ?? 35000);
        $target = (float)($_POST['target_alt'] ?? 3000);
        $gs = (float)($_POST['ground_speed'] ?? 450);
        echo json_encode(['success' => true, 'result' => E6B::calculateDescent($cruise, $target, $gs)]);
        break;

    case 'glide':
        $alt = (float)($_POST['alt_ft'] ?? 5000);
        $ratio = (float)($_POST['glide_ratio'] ?? 15);
        echo json_encode(['success' => true, 'result' => E6B::calculateGlide($alt, $ratio)]);
        break;

    case 'fuel':
        $amount = (float)($_POST['amount'] ?? 1000);
        $type = $_POST['fuel_type'] ?? 'jet_a1';
        $unit = $_POST['unit'] ?? 'liters';
        echo json_encode(['success' => true, 'result' => E6B::calculateFuel($amount, $type, $unit)]);
        break;

    case 'great_circle':
        $lat1 = (float)($_POST['lat1'] ?? 55.9726);
        $lon1 = (float)($_POST['lon1'] ?? 37.4145);
        $lat2 = (float)($_POST['lat2'] ?? 51.4775);
        $lon2 = (float)($_POST['lon2'] ?? -0.4613);
        echo json_encode(['success' => true, 'result' => E6B::calculateGreatCircle($lat1, $lon1, $lat2, $lon2)]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Неизвестное действие калькулятора']);
        break;
}
