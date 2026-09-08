<?php
/**
 * VladAero Flight Computer & E6B Aviation Calculator Library
 * Standard mathematical algorithms for flight mechanics and navigation.
 */

class E6B {
    /**
     * Calculate Crosswind and Headwind / Tailwind components.
     * @param float $runwayHeadingDeg (0-360)
     * @param float $windDirDeg (0-360)
     * @param float $windSpeedKnots
     * @return array
     */
    public static function calculateWindComponents(float $runwayHeadingDeg, float $windDirDeg, float $windSpeedKnots): array {
        $angleRad = deg2rad($windDirDeg - $runwayHeadingDeg);
        $crosswind = round(abs(sin($angleRad) * $windSpeedKnots), 1);
        $headwindRaw = cos($angleRad) * $windSpeedKnots;

        $isCrossFromRight = (sin($angleRad) > 0);
        $isTailwind = ($headwindRaw < 0);

        return [
            'crosswind_kt' => $crosswind,
            'crosswind_ms' => round($crosswind * 0.514444, 1),
            'headwind_kt' => round(abs($headwindRaw), 1),
            'headwind_ms' => round(abs($headwindRaw) * 0.514444, 1),
            'is_tailwind' => $isTailwind,
            'cross_side' => $isCrossFromRight ? 'справа' : 'слева',
            'angle_diff' => round(abs($windDirDeg - $runwayHeadingDeg))
        ];
    }

    /**
     * Calculate Pressure Altitude and Density Altitude.
     */
    public static function calculateDensityAltitude(float $elevationFt, float $qnhHpa, float $tempC): array {
        // Standard Pressure: 1013.25 hPa
        // Pressure Altitude = Elevation + (1013.25 - QNH) * 27.3
        $pressureAlt = $elevationFt + (1013.25 - $qnhHpa) * 27.3;

        // Standard Temperature at Pressure Altitude = 15 - 1.98 * (PA / 1000)
        $isaTemp = 15 - (1.98 * ($pressureAlt / 1000));

        // Density Altitude = Pressure Altitude + [118.8 * (OAT - ISA)]
        $densityAlt = $pressureAlt + (118.8 * ($tempC - $isaTemp));

        return [
            'pressure_altitude_ft' => round($pressureAlt),
            'pressure_altitude_m' => round($pressureAlt * 0.3048),
            'density_altitude_ft' => round($densityAlt),
            'density_altitude_m' => round($densityAlt * 0.3048),
            'isa_temp_c' => round($isaTemp, 1),
            'isa_dev_c' => round($tempC - $isaTemp, 1)
        ];
    }

    /**
     * Calculate True Airspeed (TAS) from Indicated Airspeed (IAS), Altitude and Temp.
     */
    public static function calculateTas(float $iasKnots, float $altitudeFt, float $tempC): array {
        // Rule of thumb: TAS increases ~2% per 1,000 ft altitude
        // Precise formula using density ratio:
        $tempK = $tempC + 273.15;
        $standardTempK = 288.15 - (0.0019812 * $altitudeFt);
        $pressureRatio = pow(1 - (0.0000068756 * $altitudeFt), 5.2559);
        $densityRatio = $pressureRatio * (288.15 / max(1, $tempK));
        $densityRatio = max(0.01, $densityRatio);

        $tasKnots = $iasKnots / sqrt($densityRatio);
        $tasKmh = $tasKnots * 1.852;

        // Speed of Sound (Knots): a = 38.967 * sqrt(Temp in Kelvin)
        $speedOfSoundKnots = 38.967 * sqrt(max(1, $tempK));
        $machNumber = $tasKnots / max(1, $speedOfSoundKnots);

        return [
            'tas_knots' => round($tasKnots, 1),
            'tas_kmh' => round($tasKmh, 1),
            'mach' => round($machNumber, 3),
            'speed_of_sound_kt' => round($speedOfSoundKnots, 1)
        ];
    }

    /**
     * Calculate Top of Descent (TOD) and Rate of Descent (RoD) for standard 3 degree glideslope.
     */
    public static function calculateDescent(float $cruiseAltFt, float $targetAltFt, float $groundSpeedKnots): array {
        $altToLoseFt = max(0, $cruiseAltFt - $targetAltFt);

        // Standard 3 deg: ~3 nm per 1000 ft
        $todDistanceNm = ($altToLoseFt / 1000) * 3;
        $todDistanceKm = $todDistanceNm * 1.852;

        // Required Vertical Speed (FPM) = Ground Speed * 5.3 (approx GS * 5)
        $rodFpm = $groundSpeedKnots * 5.303;
        $rodMs = $rodFpm * 0.00508;

        // Time to descend in minutes
        $timeMin = ($rodFpm > 0) ? ($altToLoseFt / $rodFpm) : 0;

        return [
            'alt_to_lose_ft' => round($altToLoseFt),
            'tod_distance_nm' => round($todDistanceNm, 1),
            'tod_distance_km' => round($todDistanceKm, 1),
            'rod_fpm' => round($rodFpm),
            'rod_ms' => round($rodMs, 1),
            'time_min' => round($timeMin, 1)
        ];
    }

    /**
     * Calculate Glide Range without engine power.
     */
    public static function calculateGlide(float $altitudeFtAboveGround, float $glideRatio = 15.0): array {
        // Range in Nautical Miles = (Alt in ft / 6076.12) * Glide Ratio
        $rangeNm = ($altitudeFtAboveGround / 6076.12) * $glideRatio;
        $rangeKm = $rangeNm * 1.852;

        return [
            'altitude_ft' => round($altitudeFtAboveGround),
            'glide_ratio' => $glideRatio,
            'glide_range_nm' => round($rangeNm, 1),
            'glide_range_km' => round($rangeKm, 1)
        ];
    }

    /**
     * Calculate Fuel Uplift weight and volume conversions.
     */
    public static function calculateFuel(float $amount, string $fuelType = 'jet_a1', string $inputUnit = 'liters'): array {
        // Densities in kg/liter at 15°C
        $densities = [
            'jet_a1' => 0.804,
            'ts1'    => 0.780,
            'avgas'  => 0.720
        ];
        $density = $densities[$fuelType] ?? 0.804;

        // Normalize input to liters
        $liters = 0;
        switch ($inputUnit) {
            case 'kg':
                $liters = $amount / $density;
                break;
            case 'lbs':
                $liters = ($amount * 0.453592) / $density;
                break;
            case 'us_gallons':
                $liters = $amount * 3.78541;
                break;
            case 'liters':
            default:
                $liters = $amount;
                break;
        }

        $kg = $liters * $density;
        $lbs = $kg * 2.20462;
        $usGallons = $liters / 3.78541;

        return [
            'fuel_type' => $fuelType,
            'density_kg_l' => $density,
            'liters' => round($liters, 1),
            'kg' => round($kg, 1),
            'lbs' => round($lbs, 1),
            'us_gallons' => round($usGallons, 1)
        ];
    }

    /**
     * Calculate Great Circle distance between two points (Haversine formula).
     */
    public static function calculateGreatCircle(float $lat1, float $lon1, float $lat2, float $lon2): array {
        $earthRadiusKm = 6371.0;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        $distanceKm = $earthRadiusKm * $c;
        $distanceNm = $distanceKm / 1.852;

        // Initial bearing / Track angle
        $y = sin($dLon) * cos(deg2rad($lat2));
        $x = cos(deg2rad($lat1)) * sin(deg2rad($lat2)) -
             sin(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos($dLon);
        $initialBearing = fmod((rad2deg(atan2($y, $x)) + 360), 360);

        return [
            'distance_km' => round($distanceKm),
            'distance_nm' => round($distanceNm),
            'initial_bearing_deg' => round($initialBearing)
        ];
    }
}
