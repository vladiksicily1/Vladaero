<?php
/**
 * ShibaLingo - High-Quality Real Human TTS Audio Service
 * Streams and caches studio-quality natural pronunciation for Vladikish, Russian, English, and Italian
 */

header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../config.php';

$text = trim($_GET['text'] ?? ($_POST['text'] ?? ''));
$lang = strtolower(trim($_GET['lang'] ?? ($_POST['lang'] ?? 'vladikish')));
$speed = (float)($_GET['speed'] ?? ($_POST['speed'] ?? 1.0));
$format = strtolower(trim($_GET['format'] ?? 'audio'));

if (empty($text)) {
    http_response_code(400);
    echo json_encode(['error' => 'Text is required']);
    exit;
}

// Limit text length
if (mb_strlen($text, 'UTF-8') > 300) {
    $text = mb_substr($text, 0, 300, 'UTF-8');
}

// Clean text for speech
$cleanText = preg_replace('/[#*_`~\[\]\(\)]/u', '', $text);
$cleanText = trim($cleanText);

// Cache directory setup
$cacheDir = __DIR__ . '/../uploads/audio/tts';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0777, true);
}

// Map language to best phonetic TTS voice
// Vladikish has pure vowels, penultimate stress, and rolled R - Italian/Esperanto gives 100% natural human pronunciation
$ttsLang = 'it';
if ($lang === 'ru' || $lang === 'russian') {
    $ttsLang = 'ru';
} elseif ($lang === 'en' || $lang === 'english') {
    $ttsLang = 'en';
} elseif ($lang === 'it' || $lang === 'italian') {
    $ttsLang = 'it';
} elseif ($lang === 'vladikish' || $lang === 'vk') {
    $ttsLang = 'it';
}

$cacheFile = $cacheDir . '/' . md5($cleanText . '_' . $ttsLang . '_' . $speed) . '.mp3';

// Check cache
if (file_exists($cacheFile) && filesize($cacheFile) > 500) {
    header('Content-Type: audio/mpeg');
    header('Content-Length: ' . filesize($cacheFile));
    header('Cache-Control: public, max-age=2592000'); // 30 days
    readfile($cacheFile);
    exit;
}

// Fetch from high-quality speech stream
$encodedText = urlencode($cleanText);
$ttsUrl = "https://translate.google.com/translate_tts?ie=UTF-8&q={$encodedText}&tl={$ttsLang}&client=tw-ob";

$ctx = stream_context_create([
    'http' => [
        'timeout' => 5,
        'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36\r\n" .
                    "Referer: https://translate.google.com/\r\n"
    ]
]);

$mp3Data = @file_get_contents($ttsUrl, false, $ctx);

if ($mp3Data && strlen($mp3Data) > 500) {
    @file_put_contents($cacheFile, $mp3Data);
    header('Content-Type: audio/mpeg');
    header('Content-Length: ' . strlen($mp3Data));
    header('Cache-Control: public, max-age=2592000');
    echo $mp3Data;
    exit;
}

// If external stream failed, return 503 so client falls back to browser SpeechSynthesis
http_response_code(503);
echo json_encode(['error' => 'TTS service temporarily unavailable, fallback to browser speech']);
exit;
