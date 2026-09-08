<?php
declare(strict_types=1);

/**
 * VladAero - E6B Flight Computer & Navigation Calculators
 */

namespace VladAero;

class E6B {
    /**
     * 1. Calculate Crosswind and Headwind/Tailwind components
     */
    public static function calculateWindComponents(int $runwayHeadingDeg, int $windDirDeg, int $windSpeed): array {
        $angleDiff = abs($runwayHeadingDeg - $windDirDeg) % 360;
        if ($angleDiff > 180) {
            $angleDiff = 360 - $angleDiff;
        }

        $rad = deg2rad($angleDiff);
        $headwind = round($windSpeed * cos($rad), 1);
        $crosswind = round($windSpeed * sin($rad), 1);

        // Determine if left or right crosswind
        $dirDiff = ($windDirDeg - $runwayHeadingDeg + 360) % 360;
        $crossDirection = ($dirDiff > 0 && $dirDiff < 180) ? 'справа' : 'слева';

        return [
            'runway_heading'  => $runwayHeadingDeg,
            'wind_direction'   => $windDirDeg,
            'wind_speed'       => $windSpeed,
            'angle_difference' => $angleDiff,
            'headwind'         => max(0.0, $headwind),
            'tailwind'         => $headwind < 0 ? abs($headwind) : 0.0,
            'crosswind'        => $crosswind,
            'cross_side'       => $crossDirection,
            'is_within_limits' => $crosswind <= 30.0 // Standard commercial limit
        ];
    }

    /**
     * 2. Density Altitude and ISA Deviation
     */
    public static function calculateDensityAltitude(int $pressureAltFt, float $tempC, float $qnhHpa = 1013.25): array {
        // ISA Temperature at this altitude: 15°C - 2°C per 1000 ft
        $isaTempC = 15.0 - (1.98 * ($pressureAltFt / 1000.0));
        $tempDelta = $tempC - $isaTempC;

        // Density Altitude approx: Pressure Alt + (120 * (OAT - ISA Temp))
        $densityAltFt = (int)round($pressureAltFt + (118.8 * $tempDelta));

        // Pressure altitude adjusted for QNH
        $qnhDeltaHpa = 1013.25 - $qnhHpa;
        $actualPressureAlt = (int)round($pressureAltFt + ($qnhDeltaHpa * 27.3));

        return [
            'pressure_altitude_ft' => $actualPressureAlt,
            'density_altitude_ft'  => $densityAltFt,
            'density_altitude_m'   => (int)round($densityAltFt * 0.3048),
            'isa_temp_c'           => round($isaTempC, 1),
            'isa_deviation_c'      => round($tempDelta, 1),
            'performance_loss_pct' => round(max(0.0, ($densityAltFt - $pressureAltFt) / 1000.0 * 3.5), 1)
        ];
    }

    /**
     * 3. 3° Glide Slope Descent Rate & Top of Descent (TOD)
     */
    public static function calculateDescent(int $groundSpeedKt, int $currentAltFt, int $targetAltFt, float $glideSlopeDeg = 3.0): array {
        $altToLoseFt = max(0, $currentAltFt - $targetAltFt);

        // Required FPM (Vertical Speed) for 3 deg slope: Ground Speed * 5 (or GS * 101.27 * tan(3))
        $vsiFpm = (int)round($groundSpeedKt * 101.27 * tan(deg2rad($glideSlopeDeg)));

        // TOD Distance: (Alt to lose / 1000) * 3 (Rule of 3)
        $todDistanceNm = round($altToLoseFt / (tan(deg2rad($glideSlopeDeg)) * 6076.12), 1);
        $timeMinutes = $groundSpeedKt > 0 ? round(($todDistanceNm / $groundSpeedKt) * 60, 1) : 0;

        return [
            'ground_speed_kt'   => $groundSpeedKt,
            'altitude_loss_ft'  => $altToLoseFt,
            'glide_slope_deg'   => $glideSlopeDeg,
            'required_fpm'      => $vsiFpm,
            'required_mps'      => round($vsiFpm * 0.00508, 1),
            'tod_distance_nm'   => $todDistanceNm,
            'tod_distance_km'   => round($todDistanceNm * 1.852, 1),
            'time_to_descend_m' => $timeMinutes
        ];
    }

    /**
     * 4. Great Circle Distance (Ортодромия) and Flight Estimator
     */
    public static function calculateGreatCircle(float $lat1, float $lon1, float $lat2, float $lon2, int $cruiseSpeedKmh = 850, int $fuelBurnKgH = 2600): array {
        $earthRadiusKm = 6371.0;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        $distanceKm = (int)round($earthRadiusKm * $c);
        $distanceNm = (int)round($distanceKm * 0.539957);

        // Initial Bearing / True Heading
        $y = sin($dLon) * cos(deg2rad($lat2));
        $x = cos(deg2rad($lat1)) * sin(deg2rad($lat2)) -
             sin(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos($dLon);
        $bearingDeg = (int)round((rad2deg(atan2($y, $x)) + 360) % 360);

        // Flight Time & Fuel
        $flightHours = $cruiseSpeedKmh > 0 ? ($distanceKm / $cruiseSpeedKmh) : 0;
        $totalMinutes = (int)round($flightHours * 60) + 25; // +25 min taxi, climb, descent
        $hoursPart = floor($totalMinutes / 60);
        $minPart = $totalMinutes % 60;

        $fuelRequiredKg = (int)round(($totalMinutes / 60.0) * $fuelBurnKgH);
        $fuelContingencyKg = (int)round($fuelRequiredKg * 1.15); // +15% reserve

        return [
            'distance_km'       => $distanceKm,
            'distance_nm'       => $distanceNm,
            'initial_bearing'   => $bearingDeg,
            'flight_time_min'   => $totalMinutes,
            'flight_time_str'   => sprintf('%d ч %02d мин', $hoursPart, $minPart),
            'fuel_trip_kg'      => $fuelRequiredKg,
            'fuel_total_kg'     => $fuelContingencyKg
        ];
    }

    /**
     * 5. Flight Delay Compensation Calculator (EU261 & Russian Air Code)
     */
    public static function calculateCompensation(int $delayHours, int $distanceKm, string $jurisdiction = 'eu261', string $cause = 'airline_fault'): array {
        if ($cause !== 'airline_fault') {
            return [
                'eligible' => false,
                'amount_str' => '0 ₽ / 0 €',
                'reason' => 'Форс-мажорные обстоятельства (погода, решение властей) не покрываются компенсацией.'
            ];
        }

        if ($jurisdiction === 'eu261') {
            if ($delayHours < 3) {
                return ['eligible' => false, 'amount_str' => '0 €', 'reason' => 'Задержка менее 3 часов.'];
            }
            if ($distanceKm <= 1500) {
                $amount = 250;
            } elseif ($distanceKm <= 3500) {
                $amount = 400;
            } else {
                $amount = 600;
            }
            return [
                'eligible' => true,
                'amount_eur' => $amount,
                'amount_str' => "{$amount} € на одного пассажира",
                'law' => 'Регламент ЕС № 261/2004'
            ];
        } else {
            // Russian Air Code (Воздушный кодекс РФ, 100 руб/час или штраф 25% стоимости билета)
            $rubPerHour = 100;
            $rubAmount = min(delayHours_cap($delayHours * $rubPerHour), 10000);
            return [
                'eligible' => true,
                'amount_rub' => $rubAmount,
                'amount_str' => "от {$rubAmount} ₽ (100 руб/час) + возврат питания и гостиницы",
                'law' => 'Статья 120 Воздушного Кодекса РФ'
            ];
        }
    }
}

function delayHours_cap(int $val): int {
    return max($val, 500);
}
