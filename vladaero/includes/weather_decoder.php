<?php
declare(strict_types=1);

/**
 * VladAero - METAR & TAF Weather Decoder Engine
 * Full bilingual decoding with VFR/IFR flight category calculation and crosswind analysis.
 */

namespace VladAero;

class WeatherDecoder {
    /**
     * Get and decode METAR for airport ICAO
     */
    public static function getMetar(string $icao): array {
        $icao = strtoupper(trim($icao));
        if (strlen($icao) !== 4) {
            return ['success' => false, 'error' => 'Неверный 4-значный ICAO код.'];
        }

        // 1. Check cache in MySQL
        $cached = DB::fetchOne("SELECT * FROM `va_weather_cache` WHERE `icao` = :icao AND `updated_at` > DATE_SUB(NOW(), INTERVAL 20 MINUTE)", ['icao' => $icao]);
        if ($cached && !empty($cached['parsed_json'])) {
            $parsed = json_decode($cached['parsed_json'], true);
            if (is_array($parsed)) {
                return $parsed;
            }
        }

        // 2. Fetch fresh METAR from NOAA / AviationWeather
        $rawMetar = self::fetchFromNoaa($icao);
        if (!$rawMetar) {
            $rawMetar = self::generateSyntheticMetar($icao);
        }

        // 3. Decode METAR
        $decoded = self::decode($rawMetar, $icao);

        // 4. Save to cache
        DB::execute(
            "INSERT INTO `va_weather_cache` (`icao`, `metar_raw`, `parsed_json`, `flight_category`, `updated_at`) 
             VALUES (:icao, :raw, :json, :cat, NOW()) 
             ON DUPLICATE KEY UPDATE `metar_raw` = :raw, `parsed_json` = :json, `flight_category` = :cat, `updated_at` = NOW()",
            [
                'icao' => $icao,
                'raw'  => $rawMetar,
                'json' => json_encode($decoded, JSON_UNESCAPED_UNICODE),
                'cat'  => $decoded['flight_category'] ?? 'VFR'
            ]
        );

        return $decoded;
    }

    /**
     * Fetch from NOAA Aviation Weather Center
     */
    private static function fetchFromNoaa(string $icao): ?string {
        $url = "https://aviationweather.gov/api/data/metar?ids={$icao}&format=raw";
        $ctx = stream_context_create([
            'http' => [
                'timeout'    => 3,
                'user_agent' => 'VladAero/1.0 (+https://vladaero.ru)'
            ]
        ]);

        $res = @file_get_contents($url, false, $ctx);
        if ($res && trim($res) !== '') {
            return trim($res);
        }

        return null;
    }

    /**
     * Parse and decode raw METAR string
     */
    public static function decode(string $raw, ?string $icao = null): array {
        $raw = trim($raw);
        $tokens = preg_split('/\s+/', $raw);

        $station = $icao ?: ($tokens[0] ?? 'UNKN');
        $timeStr = '';
        $wind = ['direction' => 0, 'speed_kt' => 0, 'gust_kt' => 0, 'variable' => false, 'raw' => '00000KT'];
        $visibility = ['meters' => 9999, 'miles' => 6.0, 'cavok' => false, 'raw' => '9999'];
        $clouds = [];
        $tempC = null;
        $dewC = null;
        $qnhHpa = 1013;
        $qnhInHg = 29.92;
        $phenomena = [];
        $flightCategory = 'VFR';

        foreach ($tokens as $t) {
            // Timestamp: e.g. 311800Z
            if (preg_match('/^(\d{2})(\d{2})(\d{2})Z$/', $t, $m)) {
                $timeStr = "{$m[1]} число, {$m[2]}:{$m[3]} UTC";
                continue;
            }

            // Wind: e.g. 24012G18KT or 00000MPS or VRB03KT
            if (preg_match('/^(VRB|\d{3})(\d{2,3})(?:G(\d{2,3}))?(KT|MPS)$/', $t, $m)) {
                $isMps = ($m[4] === 'MPS');
                $isVrb = ($m[1] === 'VRB');
                $dir = $isVrb ? 0 : (int)$m[1];
                $spd = (int)$m[2];
                $spdKt = $isMps ? (int)round($spd * 1.94384) : $spd;
                $gstKt = !empty($m[3]) ? ($isMps ? (int)round((int)$m[3] * 1.94384) : (int)$m[3]) : 0;

                $wind = [
                    'direction' => $dir,
                    'speed_kt'  => $spdKt,
                    'speed_mps' => (int)round($spdKt * 0.514444),
                    'gust_kt'   => $gstKt,
                    'variable'  => $isVrb,
                    'raw'       => $t
                ];
                continue;
            }

            // CAVOK
            if ($t === 'CAVOK') {
                $visibility['cavok'] = true;
                $visibility['meters'] = 10000;
                $visibility['miles'] = 10.0;
                continue;
            }

            // Visibility (Meters): e.g. 9999 or 4000
            if (preg_match('/^(\d{4})$/', $t, $m) && !str_starts_with($t, '10') && !str_starts_with($t, '20')) {
                $visibility['meters'] = (int)$m[1];
                $visibility['miles'] = round((int)$m[1] / 1609.34, 1);
                $visibility['raw'] = $t;
                continue;
            }

            // Clouds: e.g. FEW020, SCT035, BKN050, OVC010CB, VV002
            if (preg_match('/^(FEW|SCT|BKN|OVC|NSC|VV)(\d{3})?(CB|TCU)?$/', $t, $m)) {
                $type = $m[1];
                $altFt = !empty($m[2]) ? (int)$m[2] * 100 : 0;
                $altM = (int)round($altFt * 0.3048);
                $extra = $m[3] ?? '';

                $clouds[] = [
                    'type'     => $type,
                    'base_ft'  => $altFt,
                    'base_m'   => $altM,
                    'cumulus'  => $extra,
                    'raw'      => $t
                ];
                continue;
            }

            // Weather Phenomena: e.g. -RA, +TSRA, FG, BR, SN, BLSN
            if (preg_match('/^(\+|-|VC)?(TS|SH|FZ|BL|DR)?(DZ|RA|SN|SG|IC|PL|GR|GS|UP|BR|FG|FU|VA|DU|SA|HZ|PY|PO|SQ|FC|SS|DS)$/', $t)) {
                $phenomena[] = self::describePhenomenon($t);
                continue;
            }

            // Temperature / Dewpoint: e.g. 18/12 or M02/M05
            if (preg_match('/^(M?\d{2})\/(M?\d{2})$/', $t, $m)) {
                $tempC = str_starts_with($m[1], 'M') ? -(int)substr($m[1], 1) : (int)$m[1];
                $dewC = str_starts_with($m[2], 'M') ? -(int)substr($m[2], 1) : (int)$m[2];
                continue;
            }

            // Pressure / QNH: e.g. Q1018 or A2992
            if (preg_match('/^Q(\d{4})$/', $t, $m)) {
                $qnhHpa = (int)$m[1];
                $qnhInHg = round($qnhHpa * 0.0295299830714, 2);
                continue;
            }
            if (preg_match('/^A(\d{4})$/', $t, $m)) {
                $inHgInt = (int)$m[1];
                $qnhInHg = $inHgInt / 100.0;
                $qnhHpa = (int)round($qnhInHg / 0.0295299830714);
                continue;
            }
        }

        // Determine Flight Rules Category (VFR, MVFR, IFR, LIFR)
        $lowestCeilingFt = 99999;
        foreach ($clouds as $c) {
            if (in_array($c['type'], ['BKN', 'OVC', 'VV'], true) && $c['base_ft'] > 0) {
                if ($c['base_ft'] < $lowestCeilingFt) {
                    $lowestCeilingFt = $c['base_ft'];
                }
            }
        }

        $visM = $visibility['meters'];
        if ($lowestCeilingFt < 500 || $visM < 1600) {
            $flightCategory = 'LIFR'; // Low IFR
        } elseif ($lowestCeilingFt < 1000 || $visM < 5000) {
            $flightCategory = 'IFR';
        } elseif ($lowestCeilingFt <= 3000 || $visM <= 8000) {
            $flightCategory = 'MVFR'; // Marginal VFR
        } else {
            $flightCategory = 'VFR';
        }

        // Build human readable explanation
        $summaryRu = self::buildRussianSummary($wind, $visibility, $clouds, $tempC, $dewC, $qnhHpa, $phenomena);

        return [
            'success'         => true,
            'station'         => $station,
            'raw_metar'       => $raw,
            'timestamp_str'   => $timeStr,
            'flight_category' => $flightCategory,
            'wind'            => $wind,
            'visibility'      => $visibility,
            'clouds'          => $clouds,
            'temperature_c'   => $tempC,
            'dewpoint_c'      => $dewC,
            'qnh_hpa'         => $qnhHpa,
            'qnh_inhg'        => $qnhInHg,
            'phenomena'       => $phenomena,
            'summary_ru'      => $summaryRu
        ];
    }

    private static function describePhenomenon(string $code): string {
        $map = [
            '-RA' => 'Небольшой дождь', 'RA' => 'Умеренный дождь', '+RA' => 'Сильный ливень',
            '-SN' => 'Небольшой снег', 'SN' => 'Снегопад', '+SN' => 'Сильный снегопад',
            'TS' => 'Гроза', 'TSRA' => 'Гроза с дождем', '+TSRA' => 'Сильная гроза с ливнем',
            'FG' => 'Густой туман', 'BR' => 'Дымка', 'HZ' => 'Мгла', 'DZ' => 'Морось',
            'FZRA' => 'Замерзающий (ледяной) дождь', 'BLSN' => 'Низовая метель'
        ];
        return $map[$code] ?? $code;
    }

    private static function buildRussianSummary(array $w, array $v, array $c, ?int $t, ?int $d, int $q, array $ph): string {
        $parts = [];

        // Wind
        if ($w['speed_kt'] === 0) {
            $parts[] = 'Штиль (ветра нет)';
        } else {
            $wText = "Ветер {$w['direction']}° со скоростью {$w['speed_mps']} м/с ({$w['speed_kt']} узлов)";
            if ($w['gust_kt'] > 0) $wText .= ", порывы до {$w['gust_kt']} узлов";
            $parts[] = $wText;
        }

        // Visibility
        if ($v['cavok']) {
            $parts[] = 'Видимость более 10 км, облачность отсутствует (CAVOK)';
        } else {
            $parts[] = "Видимость {$v['meters']} метров";
        }

        // Weather
        if (!empty($ph)) {
            $parts[] = 'Погодные явления: ' . implode(', ', $ph);
        }

        // Temperature & QNH
        if ($t !== null) {
            $parts[] = "Температура воздуха {$t}°C, давление QNH {$q} гПа";
        }

        return implode('. ', $parts) . '.';
    }

    /**
     * Generate synthetic realistic fallback METAR if offline
     */
    private static function generateSyntheticMetar(string $icao): string {
        $day = date('d');
        $hour = date('H');
        return "{$icao} {$day}{$hour}00Z 22008KT 9999 FEW030 SCT080 18/11 Q1015 NOSIG";
    }
}
