<?php
namespace VladAero\Controllers;

use VladAero\Core\{Controller, Session, Database};

/**
 * TelegramController - Telegram-бот webhook
 */
class TelegramController extends Controller
{
    public function webhook()
    {
        $token = $this->config['telegram']['bot_token'] ?? '';
        if (!$token) {
            echo 'Bot token not configured';
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || !isset($input['message'])) {
            echo 'ok';
            return;
        }

        $chatId = $input['message']['chat']['id'];
        $text = trim($input['message']['text'] ?? '');

        $command = strtolower(explode(' ', $text)[0] ?? '');

        $response = match ($command) {
            '/start' => $this->cmdStart(),
            '/help' => $this->cmdHelp(),
            '/aircraft' => $this->cmdAircraft($text),
            '/airport' => $this->cmdAirport($text),
            '/metar' => $this->cmdMetar($text),
            '/news' => $this->cmdNews(),
            default => "Неизвестная команда. Отправьте /help для справки.",
        };

        $this->sendMessage($token, $chatId, $response);
        echo 'ok';
    }

    private function cmdStart(): string
    {
        return "✈️ Добро пожаловать на VladAero!\n\n"
            . "Доступные команды:\n"
            . "/help — справка\n"
            . "/aircraft A320 — информация о самолёте\n"
            . "/airport UUEE — информация об аэропорте\n"
            . "/metar UUEE — текущий METAR\n"
            . "/news — последние новости\n";
    }

    private function cmdHelp(): string { return $this->cmdStart(); }

    private function cmdAircraft(string $text): string
    {
        $parts = explode(' ', $text);
        $code = strtoupper($parts[1] ?? '');
        if (!$code) return "Укажите код: /aircraft A320";

        $prefix = $this->db->prefix();
        $a = $this->db->fetchOne(
            "SELECT * FROM {$prefix}aircraft WHERE type_code = :c OR name LIKE :l",
            ['c' => $code, 'l' => "%{$code}%"]
        );
        if (!$a) return "Самолёт {$code} не найден.";

        return "✈️ {$a['name']} ({$a['type_code']})\n"
            . "🏭 {$a['manufacturer']}\n"
            . "👥 Пассажиров: {$a['passengers']}\n"
            . "🚀 Макс. скорость: {$a['max_speed_knots']} узлов\n"
            . "📏 Дальность: {$a['range_km']} км\n";
    }

    private function cmdAirport(string $text): string
    {
        $parts = explode(' ', $text);
        $code = strtoupper($parts[1] ?? '');
        if (!$code) return "Укажите код: /airport UUEE";

        $prefix = $this->db->prefix();
        $a = $this->db->fetchOne(
            "SELECT * FROM {$prefix}airports WHERE icao_code = :icao OR iata_code = :iata",
            ['icao' => $code, 'iata' => $code]
        );
        if (!$a) return "Аэропорт {$code} не найден.";

        return "🏢 {$a['name']} ({$a['icao_code']})\n"
            . "📍 {$a['city']}, {$a['country']}\n"
            . "🛬 {$a['latitude']}, {$a['longitude']}\n"
            . "⛰️ Высота: {$a['elevation']} м\n";
    }

    private function cmdMetar(string $text): string
    {
        $parts = explode(' ', $text);
        $icao = strtoupper($parts[1] ?? '');
        if (!$icao) return "Укажите ICAO: /metar UUEE";

        $url = "https://aviationweather.gov/api/data/metar?ids={$icao}&format=raw";
        $ctx = stream_context_create(['http' => ['timeout' => 5]]);
        $raw = @file_get_contents($url, false, $ctx);
        if (!$raw) return "METAR для {$icao} не доступен.";
        return "🌤️ METAR {$icao}:\n{$raw}";
    }

    private function cmdNews(): string
    {
        $prefix = $this->db->prefix();
        $articles = $this->db->fetchAll(
            "SELECT title, slug FROM {$prefix}news WHERE status = 'published' ORDER BY published_at DESC LIMIT 5"
        );
        if (!$articles) return "Новостей пока нет.";

        $lines = ["📰 Последние новости:\n"];
        foreach ($articles as $a) {
            $lines[] = "• {$a['title']}\nhttps://vladaero.ru/news/{$a['slug']}";
        }
        return implode("\n\n", $lines);
    }

    private function sendMessage(string $token, int $chatId, string $text): void
    {
        $url = "https://api.telegram.org/bot{$token}/sendMessage";
        @file_get_contents($url, false, stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/json',
                'content' => json_encode([
                    'chat_id' => $chatId,
                    'text' => $text,
                ]),
            ],
        ]));
    }
}
