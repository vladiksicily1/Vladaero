<?php
namespace App\Core;

class View {
    public static function e(?string $str): string {
        return htmlspecialchars($str ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function url(string $path = '/', string $locale = 'ru'): string {
        $cleanPath = '/' . ltrim($path, '/');
        if ($locale === 'en') {
            return '/en' . ($cleanPath === '/' ? '' : $cleanPath);
        }
        return $cleanPath;
    }

    public static function asset(string $path): string {
        $cleanPath = '/' . ltrim($path, '/');
        $fullPath = __DIR__ . '/../../' . ltrim($path, '/');
        $ver = file_exists($fullPath) ? filemtime($fullPath) : '1.0';
        return $cleanPath . '?v=' . $ver;
    }

    public static function formatNumber(float|int|null $num, int $decimals = 0): string {
        if ($num === null) return '—';
        return number_format($num, $decimals, '.', ' ');
    }

    public static function formatSpeed(?int $kmh): string {
        if (!$kmh) return '—';
        $kts = round($kmh * 0.539957);
        $mach = round($kmh / 1234.8, 2);
        return number_format($kmh, 0, '.', ' ') . " км/ч ({$kts} kts / M{$mach})";
    }

    public static function formatAltitude(?int $meters): string {
        if (!$meters) return '—';
        $feet = round($meters * 3.28084);
        $fl = round($feet / 100);
        return number_format($meters, 0, '.', ' ') . " м ({$feet} ft / FL{$fl})";
    }

    public static function formatDistance(?int $km): string {
        if (!$km) return '—';
        $nm = round($km * 0.539957);
        return number_format($km, 0, '.', ' ') . " км ({$nm} nm)";
    }

    public static function timeAgo(?string $datetime): string {
        if (!$datetime) return 'недавно';
        $time = strtotime($datetime);
        $diff = time() - $time;

        if ($diff < 60) return 'только что';
        if ($diff < 3600) return floor($diff / 60) . ' мин назад';
        if ($diff < 86400) return floor($diff / 3600) . ' ч назад';
        if ($diff < 604800) return floor($diff / 86400) . ' дн назад';
        return date('d.m.Y', $time);
    }
}
