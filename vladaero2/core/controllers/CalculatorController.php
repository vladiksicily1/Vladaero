<?php
namespace VladAero\Controllers;

use VladAero\Core\{Controller, Session, Database};

/**
 * CalculatorController - Калькуляторы и конвертеры
 */
class CalculatorController extends Controller
{
    public function index()
    {
        $this->view('pages.calculators.index');
    }

    public function speed()
    {
        $result = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $value = (float)($this->input('value') ?? 0);
            $from = $this->input('from') ?? 'knots';
            $result = $this->convertSpeed($value, $from);
        }
        $this->view('pages.calculators.speed', compact('result'));
    }

    public function altitude()
    {
        $result = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $value = (float)($this->input('value') ?? 0);
            $from = $this->input('from') ?? 'feet';
            $result = $this->convertAltitude($value, $from);
        }
        $this->view('pages.calculators.altitude', compact('result'));
    }

    public function fuel()
    {
        $result = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $distance = (float)($this->input('distance') ?? 0);
            $consumption = (float)($this->input('consumption') ?? 0);
            $reservePct = (float)($this->input('reserve') ?? 15);
            $totalGallons = $distance * ($consumption / 100) * (1 + $reservePct / 100);
            $totalLiters = $totalGallons * 3.785;
            $result = ['gallons' => round($totalGallons, 2), 'liters' => round($totalLiters, 2)];
        }
        $this->view('pages.calculators.fuel', compact('result'));
    }

    public function weight()
    {
        $result = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $value = (float)($this->input('value') ?? 0);
            $from = $this->input('from') ?? 'kg';
            $result = ($from === 'kg')
                ? ['lbs' => round($value * 2.20462, 2), 'kg' => $value]
                : ['lbs' => $value, 'kg' => round($value / 2.20462, 2)];
        }
        $this->view('pages.calculators.weight', compact('result'));
    }

    public function temperature()
    {
        $result = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $value = (float)($this->input('value') ?? 0);
            $from = $this->input('from') ?? 'celsius';
            $result = ($from === 'celsius')
                ? ['celsius' => $value, 'fahrenheit' => round($value * 9 / 5 + 32, 1)]
                : ['celsius' => round(($value - 32) * 5 / 9, 1), 'fahrenheit' => $value];
        }
        $this->view('pages.calculators.temperature', compact('result'));
    }

    private function convertSpeed(float $value, string $from): array
    {
        $knots = match ($from) {
            'knots' => $value,
            'kmh' => $value / 1.852,
            'mph' => $value / 1.15078,
            'ms' => $value * 1.94384,
            default => $value,
        };
        return [
            'knots' => round($knots, 2),
            'kmh' => round($knots * 1.852, 2),
            'mph' => round($knots * 1.15078, 2),
            'ms' => round($knots / 1.94384, 2),
            'mach' => round($knots / 574.44, 4),
        ];
    }

    private function convertAltitude(float $value, string $from): array
    {
        $feet = match ($from) {
            'feet' => $value,
            'meters' => $value * 3.28084,
            'fl' => $value * 100,
            default => $value,
        };
        return [
            'feet' => round($feet),
            'meters' => round($feet / 3.28084),
            'fl' => round($feet / 100),
        ];
    }
}
