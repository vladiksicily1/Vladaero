<?php
/**
 * ============================================================================
 * ShibaLingo — Google Translate Style Translator (Russian <-> Vladikish)
 * ============================================================================
 * Standalone Single-File Translation Engine & Interactive Web Interface.
 * 
 * Features:
 *  - 100% Self-Contained (Zero external file dependencies / No includes).
 *  - Direct connection to SQLite database: shibalingo.sqlite.
 *  - Configurable hardcoded table prefix (DB_PREFIX).
 *  - Hybrid Architecture: Rule-Based Morphological Translation (RBMT) +
 *    Ground-Truth Dictionary RAG + NVIDIA NIM / OpenAI-compatible LLM synthesis.
 *  - Real-time Bidirectional Translation: Russian <-> Vladikish.
 *  - Automatic Language Detection (Auto-Detect).
 *  - Word-by-word morphological breakdown, grammatical analysis, and phonetics.
 *  - Text-to-Speech (TTS) voice pronunciation & Speech-to-Text (STT) voice input.
 *  - Quick Phrasebook, History, Favorites, and Live Dictionary Search.
 * ============================================================================
 */

declare(strict_types=1);

// ----------------------------------------------------------------------------
// 1. CONFIGURATION & DATABASE CONSTANTS
// ----------------------------------------------------------------------------
define('DB_PREFIX', ''); // Configurable table prefix (e.g. '', 'sl_', etc.)
define('DB_SQLITE_FILE', __DIR__ . '/shibalingo.sqlite');

// Hardcoded API Key & Model Configuration
define('HARDCODED_NVIDIA_API_KEY', 'nvapi-YOUR_API_KEY_HERE'); // Зашитый API-ключ для LLM
define('DEFAULT_NVIDIA_MODEL', 'meta/llama-3.3-70b-instruct');
define('DEFAULT_NVIDIA_BASE_URL', 'https://integrate.api.nvidia.com/v1');

// Default Translation Engine: 'rbmt' (Правила и словарь) | 'llm' (Нейросеть) | 'hybrid'
define('DEFAULT_ENGINE_MODE', 'rbmt');

// ----------------------------------------------------------------------------
// 2. HELPER FUNCTIONS & DATABASE LAYER
// ----------------------------------------------------------------------------

/**
 * Returns a singleton PDO connection to shibalingo.sqlite
 */
function getTranslatorDb(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dbPath = DB_SQLITE_FILE;
        $dsn = 'sqlite:' . $dbPath;
        $pdo = new PDO($dsn, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        // Auto-create essential tables if they do not exist
        initDatabaseTables($pdo);
    }
    return $pdo;
}

/**
 * Get prefixed table name with safe quotes
 */
function tbl(string $name): string {
    return '"' . DB_PREFIX . $name . '"';
}

/**
 * Escape HTML safely
 */
function e(?string $str): string {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Ensure database tables exist with initial seed data if DB was newly created
 */
function initDatabaseTables(PDO $db): void {
    $dictTable = tbl('conlang_dictionary');
    $gramTable = tbl('conlang_grammar');
    $settTable = tbl('settings');
    $logsTable = tbl('ai_logs');

    $db->exec("
        CREATE TABLE IF NOT EXISTS {$dictTable} (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            language_code TEXT DEFAULT 'vladikish',
            word TEXT NOT NULL,
            part_of_speech TEXT DEFAULT 'noun',
            translation_ru TEXT NOT NULL,
            translation_en TEXT NOT NULL,
            translation_it TEXT,
            pronunciation TEXT,
            example_sentence TEXT,
            root_word TEXT,
            created_by TEXT DEFAULT 'manual',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS {$gramTable} (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            language_code TEXT DEFAULT 'vladikish',
            rule_title TEXT NOT NULL,
            rule_description TEXT NOT NULL,
            rule_examples TEXT,
            order_num INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS {$settTable} (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS {$logsTable} (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            action_type TEXT NOT NULL,
            model TEXT NOT NULL,
            prompt_tokens INTEGER DEFAULT 0,
            completion_tokens INTEGER DEFAULT 0,
            latency_ms INTEGER DEFAULT 0,
            status TEXT DEFAULT 'success',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    // Check if dictionary is empty; if so, populate baseline Vladikish vocabulary
    $stmt = $db->query("SELECT COUNT(*) FROM {$dictTable} WHERE language_code = 'vladikish'");
    if ((int)$stmt->fetchColumn() === 0) {
        $seedWords = [
            ['Aero', 'noun', 'небо / полет', 'sky / flight', 'cielo / volo', 'А́эро', 'Aero zora vanti. (Небо сегодня прекрасное.)', 'aer'],
            ['Vladi', 'noun', 'друг / правитель', 'friend / ruler', 'amico / sovrano', 'Вла́ди', 'Vladi, zora mira! (Друг, доброе утро!)', 'vlad'],
            ['Zora', 'noun', 'день / солнце / утро', 'day / sun', 'giorno / sole', 'Зо́ра', 'Zora velo. (Солнце светит.)', 'zor'],
            ['Barka', 'noun', 'собака / верность', 'dog / loyalty', 'cane / lealtà', 'Ба́рка', 'Shiba barka bonu. (Шиба — хорошая собака.)', 'bark'],
            ['Bonu', 'adjective', 'хороший / добрый / отлично', 'good / kind', 'buono / gentile', 'Бо́ну', 'Vladi bonu est. (Друг хороший.)', 'bon'],
            ['Mira', 'greeting', 'привет / мир / здравствуй', 'hello / peace', 'ciao / pace', 'Ми́ра', 'Mira, Shiba! (Привет, Шиба!)', 'mir'],
            ['Korno', 'noun', 'сердце / душа / настроение', 'heart / soul', 'cuore / anima', 'Ко́рно', 'Korno vanti. (Сердце радостно.)', 'korn'],
            ['Vanti', 'adjective', 'счастливый / прекрасный / радостный', 'happy / beautiful', 'felice / bello', 'Ва́нти', 'Vanti zora! (Прекрасный день!)', 'vant'],
            ['Nox', 'noun', 'ночь / сон / темнота', 'night / sleep', 'notte / sonno', 'Нокс', 'Nox mira, Vladi. (Спокойной ночи, Влади.)', 'nox'],
            ['Lingo', 'noun', 'слово / язык / речь', 'word / language', 'parola / lingua', 'Ли́нго', 'Vladikish lingo vanti. (Язык Владикиш прекрасен.)', 'ling'],
            ['Toro', 'verb', 'любить / ценить / обожать', 'to love / appreciate', 'amare', 'То́ро', 'Me toro Barka. (Я люблю собаку.)', 'tor'],
            ['Velo', 'verb', 'сиять / лететь / светить', 'to shine / fly', 'volare / splendere', 'Ве́ло', 'Aero velo. (Небо сияет.)', 'vel'],
            ['Danko', 'phrase', 'спасибо / благодарю', 'thank you', 'grazie', 'Да́нко', 'Danko, Shiba-sensei! (Спасибо, Сиба-сэнсэй!)', 'dank'],
            ['Plaso', 'phrase', 'пожалуйста / прошу', 'please', 'per favore', 'Пла́со', 'Plaso, lingo me. (Пожалуйста, поговори со мной.)', 'plas'],
            ['Est', 'verb', 'быть / является / есть', 'to be / is', 'essere', 'Эст', 'Zora bonu est. (День хороший.)', 'es'],
            ['Me', 'pronoun', 'я / меня / мне / мой', 'I / me / my', 'io / me', 'Ме', 'Me toro lingo. (Я люблю язык.)', 'me'],
            ['Tu', 'pronoun', 'ты / тебя / тебе / твой', 'you / your', 'tu / te', 'Ту', 'Tu bonu vladi. (Ты хороший друг.)', 'tu'],
            ['No', 'adverb', 'нет / не / ни', 'no / not', 'no / non', 'Но', 'No problema. (Нет проблем.)', 'no'],
            ['Si', 'adverb', 'да / конечно / так', 'yes / indeed', 'si', 'Си', 'Si, danko! (Да, спасибо!)', 'si'],
            ['Kaelo', 'noun', 'звезда / мечта / космос', 'star / dream / cosmos', 'stella', 'Ка́эло', 'Kaelo velo in Aero. (Звезда сияет в небе.)', 'kael'],
            ['Dom', 'noun', 'дом / убежище', 'house / home', 'casa', 'Дом', 'Me dom vanti. (Мой дом прекрасен.)', 'dom'],
            ['Juntos', 'adverb', 'вместе / сообща', 'together', 'insieme', 'Ху́нтос', 'Vo-velo juntos! (Полетим вместе!)', 'junt'],
            ['In', 'preposition', 'в / внутри / на', 'in / into', 'in', 'Ин', 'In aero. (В небе.)', 'in'],
            ['Como', 'adverb', 'как / каким образом', 'how', 'come', 'Ко́мо', 'Como sta tu? (Как дела?)', 'com'],
            ['Sta', 'verb', 'находиться / поживать / обстоять', 'to be / stay', 'stare', 'Ста', 'Como sta korno? (Как настроение?)', 'sta']
        ];

        $ins = $db->prepare("INSERT INTO {$dictTable} (language_code, word, part_of_speech, translation_ru, translation_en, translation_it, pronunciation, example_sentence, root_word, created_by) 
                             VALUES ('vladikish', :w, :pos, :ru, :en, :it, :pron, :ex, :root, 'seed')");
        foreach ($seedWords as $row) {
            $ins->execute([
                'w' => $row[0],
                'pos' => $row[1],
                'ru' => $row[2],
                'en' => $row[3],
                'it' => $row[4],
                'pron' => $row[5],
                'ex' => $row[6],
                'root' => $row[7]
            ]);
        }
    }

    // Seed baseline grammar rules if empty
    $stmtG = $db->query("SELECT COUNT(*) FROM {$gramTable} WHERE language_code = 'vladikish'");
    if ((int)$stmtG->fetchColumn() === 0) {
        $seedRules = [
            ['Базовый порядок слов: SVO', 'Предложения строятся строго по структуре: Субъект (Кто?) + Глагол (Что делает?) + Объект (Над чем?).', 'Me (Я) toro (люблю) Vladikish (Владикиш).', 1],
            ['Прилагательные и согласование (-u)', 'Описательные прилагательные обычно оканчиваются на букву -u и ставятся сразу после существительного или перед связкой est.', 'Zora vanti = Прекрасный день. Barka bonu = Хорошая собака.', 2],
            ['Множественное число (-s / -i)', 'Для образования множественного числа к существительным добавляется суффикс -s (после гласных) или -i (после согласных).', 'Barka -> Barkas (собаки). Korno -> Kornos (сердца).', 3],
            ['Прошедшее и Будущее время глаголов', 'Прошедшее время образуется добавлением суффикса -ti (toro -> toroti). Будущее время образуется добавлением префикса vo- (velo -> vo-velo).', 'Me toroti Aero (Я полюбил небо). Me vo-velo (Я полечу).', 4]
        ];
        $insG = $db->prepare("INSERT INTO {$gramTable} (language_code, rule_title, rule_description, rule_examples, order_num) VALUES ('vladikish', :t, :d, :e, :o)");
        foreach ($seedRules as $r) {
            $insG->execute(['t' => $r[0], 'd' => $r[1], 'e' => $r[2], 'o' => $r[3]]);
        }
    }
}

/**
 * Fetch setting from database
 */
function getTranslatorSetting(string $key, string $default = ''): string {
    try {
        $db = getTranslatorDb();
        $stmt = $db->prepare("SELECT setting_value FROM " . tbl('settings') . " WHERE setting_key = :k");
        $stmt->execute(['k' => $key]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string)$val : $default;
    } catch (Exception $e) {
        return $default;
    }
}

// ----------------------------------------------------------------------------
// 3. LINGUISTIC ENGINE & HYBRID TRANSLATOR
// ----------------------------------------------------------------------------

/**
 * Load complete Vladikish dictionary as structured lookup maps
 */
function loadVladikishLexicon(): array {
    $db = getTranslatorDb();
    $stmt = $db->query("SELECT word, part_of_speech, translation_ru, translation_en, translation_it, pronunciation, example_sentence, root_word FROM " . tbl('conlang_dictionary') . " WHERE language_code = 'vladikish' ORDER BY LENGTH(word) DESC");
    $entries = $stmt->fetchAll();

    $vladToRu = [];
    $ruToVlad = [];

    foreach ($entries as $row) {
        $vWordLower = mb_strtolower(trim($row['word']), 'UTF-8');
        $vladToRu[$vWordLower] = $row;

        // Parse Russian translations (split by '/', ',', ';')
        $ruDefs = preg_split('/[\/;,]+/', mb_strtolower($row['translation_ru'], 'UTF-8'));
        foreach ($ruDefs as $def) {
            $defClean = trim($def);
            if (!empty($defClean)) {
                if (!isset($ruToVlad[$defClean])) {
                    $ruToVlad[$defClean] = [];
                }
                $ruToVlad[$defClean][] = $row;
            }
        }
    }

    return [
        'entries' => $entries,
        'vladToRu' => $vladToRu,
        'ruToVlad' => $ruToVlad,
    ];
}

/**
 * Detect language automatically (Russian vs Vladikish)
 */
function detectLanguage(string $text, array $lexicon): string {
    $trimmed = trim($text);
    if (empty($trimmed)) return 'ru';

    // Count Cyrillic characters vs Latin characters
    $cyrillicCount = preg_match_all('/[\p{Cyrillic}]/u', $trimmed);
    $latinCount = preg_match_all('/[a-zA-Z]/u', $trimmed);

    if ($cyrillicCount > $latinCount) {
        return 'ru';
    } elseif ($latinCount > $cyrillicCount) {
        return 'vladikish';
    }

    // Check vocabulary match
    $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($trimmed, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);
    $vladMatch = 0;
    foreach ($words as $w) {
        if (isset($lexicon['vladToRu'][$w])) $vladMatch++;
    }

    return ($vladMatch > 0) ? 'vladikish' : 'ru';
}

/**
 * Russian morphological normalizer (stemming / fuzzy matching to dictionary)
 */
function findVladikishForRussianWord(string $rawWord, array $ruToVlad, array $entries): ?array {
    $word = mb_strtolower($rawWord, 'UTF-8');

    // 1. Direct exact lookup
    if (isset($ruToVlad[$word])) {
        return ['entry' => $ruToVlad[$word][0], 'tense' => 'present', 'plural' => false];
    }

    // 2. Common Russian Pronouns / Particles
    $directMappings = [
        'я' => 'Me', 'меня' => 'Me', 'мне' => 'Me', 'мой' => 'Me', 'моя' => 'Me', 'мое' => 'Me', 'моё' => 'Me', 'мои' => 'Me',
        'ты' => 'Tu', 'тебя' => 'Tu', 'тебе' => 'Tu', 'твой' => 'Tu', 'твоя' => 'Tu', 'твое' => 'Tu', 'твоё' => 'Tu', 'твои' => 'Tu',
        'мы' => 'Me', 'вы' => 'Tu', 'он' => 'Vladi', 'она' => 'Vladi', 'оно' => 'Vladi', 'они' => 'Vladis',
        'привет' => 'Mira', 'здравствуй' => 'Mira', 'здравствуйте' => 'Mira', 'доброе' => 'Bonu', 'добрый' => 'Bonu', 'добрая' => 'Bonu',
        'спасибо' => 'Danko', 'благодарю' => 'Danko', 'пожалуйста' => 'Plaso', 'прошу' => 'Plaso',
        'друг' => 'Vladi', 'друга' => 'Vladi', 'другу' => 'Vladi', 'другом' => 'Vladi', 'друзья' => 'Vladis', 'друзей' => 'Vladis',
        'собака' => 'Barka', 'собаку' => 'Barka', 'собаке' => 'Barka', 'собаки' => 'Barkas', 'собак' => 'Barkas', 'пес' => 'Barka', 'пёс' => 'Barka',
        'небо' => 'Aero', 'небеса' => 'Aero', 'небе' => 'Aero', 'небу' => 'Aero', 'полет' => 'Aero', 'полёт' => 'Aero',
        'солнце' => 'Zora', 'день' => 'Zora', 'дня' => 'Zora', 'днем' => 'Zora', 'днём' => 'Zora', 'утро' => 'Zora',
        'ночь' => 'Nox', 'ночи' => 'Nox', 'ночью' => 'Nox', 'сон' => 'Nox', 'сна' => 'Nox',
        'сердце' => 'Korno', 'сердца' => 'Korno', 'сердцу' => 'Korno', 'душа' => 'Korno', 'души' => 'Korno', 'настроение' => 'Korno',
        'люблю' => 'Toro', 'любит' => 'Toro', 'любишь' => 'Toro', 'любим' => 'Toro', 'любить' => 'Toro', 'полюбил' => 'toroti', 'полюблю' => 'vo-toro',
        'лечу' => 'Velo', 'летит' => 'Velo', 'лететь' => 'Velo', 'сиять' => 'Velo', 'сияет' => 'Velo', 'светит' => 'Velo', 'полечу' => 'vo-velo', 'полетим' => 'vo-velo',
        'хороший' => 'Bonu', 'хорошая' => 'Bonu', 'хорошее' => 'Bonu', 'хороши' => 'Bonu', 'хорошо' => 'Bonu', 'добрый' => 'Bonu',
        'счастливый' => 'Vanti', 'счастливая' => 'Vanti', 'счастливое' => 'Vanti', 'прекрасный' => 'Vanti', 'прекрасная' => 'Vanti', 'прекрасно' => 'Vanti', 'радостный' => 'Vanti', 'радостно' => 'Vanti',
        'да' => 'Si', 'нет' => 'No', 'не' => 'No', 'ни' => 'No', 'в' => 'in', 'на' => 'in', 'внутри' => 'in', 'вместе' => 'juntos',
        'как' => 'Como', 'дела' => 'sta', 'поживаешь' => 'sta', 'звезда' => 'Kaelo', 'звезды' => 'Kaelos', 'звёзды' => 'Kaelos',
        'дом' => 'Dom', 'дома' => 'Dom', 'в доме' => 'in Dom', 'язык' => 'Lingo', 'слово' => 'Lingo', 'слова' => 'Lingos',
        'есть' => 'est', 'является' => 'est', 'был' => 'esti', 'будет' => 'vo-est'
    ];

    if (isset($directMappings[$word])) {
        $targetWord = $directMappings[$word];
        // Find dictionary match or create synthetic
        foreach ($entries as $e) {
            if (mb_strtolower($e['word'], 'UTF-8') === mb_strtolower(preg_replace('/^(vo-)|(ti)$/', '', $targetWord), 'UTF-8')) {
                return ['entry' => $e, 'custom_word' => $targetWord, 'tense' => 'present', 'plural' => false];
            }
        }
        return ['entry' => ['word' => $targetWord, 'part_of_speech' => 'particle', 'translation_ru' => $word, 'pronunciation' => $targetWord], 'custom_word' => $targetWord, 'tense' => 'present', 'plural' => false];
    }

    // 3. Fuzzy Stem Search across all dictionary translations (word boundary anchored)
    $stem = mb_substr($word, 0, max(3, mb_strlen($word, 'UTF-8') - 2), 'UTF-8');
    foreach ($entries as $e) {
        $ruFull = mb_strtolower($e['translation_ru'], 'UTF-8');
        if (preg_match('/(^|[\/\s,;])' . preg_quote($stem, '/') . '/iu', $ruFull)) {
            $isPlural = preg_match('/(ы|и|ов|ев|ей|ам|ами|ах)$/u', $word);
            return ['entry' => $e, 'tense' => 'present', 'plural' => (bool)$isPlural];
        }
    }

    return null;
}

/**
 * Vladikish morphological parser (prefixes like vo-, suffixes like -ti, -s, -i)
 */
function parseVladikishWord(string $rawWord, array $vladToRu): ?array {
    $word = mb_strtolower(trim($rawWord), 'UTF-8');

    // 1. Direct match
    if (isset($vladToRu[$word])) {
        return ['entry' => $vladToRu[$word], 'tense' => 'present', 'plural' => false, 'surface' => $rawWord];
    }

    // 2. Future tense prefix: vo- (e.g. vo-velo -> velo)
    if (str_starts_with($word, 'vo-') || (str_starts_with($word, 'vo') && mb_strlen($word, 'UTF-8') > 3)) {
        $stem = str_starts_with($word, 'vo-') ? substr($word, 3) : substr($word, 2);
        if (isset($vladToRu[$stem])) {
            return ['entry' => $vladToRu[$stem], 'tense' => 'future', 'plural' => false, 'surface' => $rawWord];
        }
    }

    // 3. Past tense suffix: -ti (e.g. toroti -> toro)
    if (str_ends_with($word, 'ti') && mb_strlen($word, 'UTF-8') > 3) {
        $stem = substr($word, 0, -2);
        if (isset($vladToRu[$stem])) {
            return ['entry' => $vladToRu[$stem], 'tense' => 'past', 'plural' => false, 'surface' => $rawWord];
        }
    }

    // 4. Plural suffix: -s or -i (e.g. Barkas -> Barka, Barkai -> Barka)
    if (str_ends_with($word, 's') || str_ends_with($word, 'i')) {
        $stem = substr($word, 0, -1);
        if (isset($vladToRu[$stem])) {
            return ['entry' => $vladToRu[$stem], 'tense' => 'present', 'plural' => true, 'surface' => $rawWord];
        }
    }

    return null;
}

/**
 * High-Precision Rule-Based Machine Translator (RBMT Fallback)
 */
function ruleBasedTranslate(string $text, string $sourceLang, string $targetLang, array $lexicon): array {
    $lines = explode("\n", $text);
    $translatedLines = [];
    $breakdown = [];
    $grammarNotes = [];

    foreach ($lines as $line) {
        if (trim($line) === '') {
            $translatedLines[] = '';
            continue;
        }

        // Tokenize into words and punctuation
        preg_match_all('/([\p{L}\p{N}\-]+|[\s]+|[^\p{L}\p{N}\s]+)/u', $line, $matches);
        $tokens = $matches[0] ?? [];
        $translatedTokens = [];

        foreach ($tokens as $token) {
            if (preg_match('/^[\s]+$/u', $token)) {
                $translatedTokens[] = $token;
                continue;
            }
            if (preg_match('/^[^\p{L}\p{N}]+$/u', $token)) {
                $translatedTokens[] = $token;
                continue;
            }

            $isCapitalized = (mb_strtoupper(mb_substr($token, 0, 1, 'UTF-8'), 'UTF-8') === mb_substr($token, 0, 1, 'UTF-8'));

            if ($sourceLang === 'ru' || $targetLang === 'vladikish') {
                // RU -> VLADIKISH
                $lookup = findVladikishForRussianWord($token, $lexicon['ruToVlad'], $lexicon['entries']);
                if ($lookup) {
                    $entry = $lookup['entry'];
                    $outWord = $lookup['custom_word'] ?? $entry['word'];

                    // Apply plural morphology if needed
                    if (!empty($lookup['plural']) && !isset($lookup['custom_word'])) {
                        $lastChar = mb_substr($outWord, -1, 1, 'UTF-8');
                        $outWord .= (in_array(mb_strtolower($lastChar), ['a','e','i','o','u'])) ? 's' : 'i';
                    }

                    if ($isCapitalized) {
                        $outWord = mb_strtoupper(mb_substr($outWord, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($outWord, 1, null, 'UTF-8');
                    }

                    $translatedTokens[] = $outWord;
                    $breakdown[] = [
                        'original' => $token,
                        'translated' => $outWord,
                        'pos' => $entry['part_of_speech'] ?? 'noun',
                        'definition' => $entry['translation_ru'] ?? '',
                        'pronunciation' => $entry['pronunciation'] ?? $outWord,
                        'root' => $entry['root_word'] ?? '',
                        'example' => $entry['example_sentence'] ?? ''
                    ];
                } else {
                    $translatedTokens[] = $token;
                    $breakdown[] = [
                        'original' => $token,
                        'translated' => $token,
                        'pos' => 'unknown',
                        'definition' => 'Оригинальное слово (без изменений)',
                        'pronunciation' => $token,
                        'root' => '',
                        'example' => ''
                    ];
                }
            } else {
                // VLADIKISH -> RU
                $parsed = parseVladikishWord($token, $lexicon['vladToRu']);
                if ($parsed) {
                    $entry = $parsed['entry'];
                    // Primary Russian definition
                    $firstRu = explode('/', $entry['translation_ru'])[0];
                    $outWord = trim(explode(',', $firstRu)[0]);

                    if ($parsed['tense'] === 'future') {
                        $outWord = 'будет ' . $outWord;
                        $grammarNotes[] = "Применен префикс будущего времени vo- для слова {$token}";
                    } elseif ($parsed['tense'] === 'past') {
                        $outWord = $outWord . ' (в прошлом)';
                        $grammarNotes[] = "Применен суффикс прошедшего времени -ti для слова {$token}";
                    }

                    if ($isCapitalized) {
                        $outWord = mb_strtoupper(mb_substr($outWord, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($outWord, 1, null, 'UTF-8');
                    }

                    $translatedTokens[] = $outWord;
                    $breakdown[] = [
                        'original' => $token,
                        'translated' => $outWord,
                        'pos' => $entry['part_of_speech'] ?? 'noun',
                        'definition' => $entry['translation_ru'] ?? '',
                        'pronunciation' => $entry['pronunciation'] ?? $token,
                        'root' => $entry['root_word'] ?? '',
                        'example' => $entry['example_sentence'] ?? ''
                    ];
                } else {
                    $translatedTokens[] = $token;
                    $breakdown[] = [
                        'original' => $token,
                        'translated' => $token,
                        'pos' => 'unknown',
                        'definition' => 'Слово не найдено в словаре Vladikish',
                        'pronunciation' => $token,
                        'root' => '',
                        'example' => ''
                    ];
                }
            }
        }

        $translatedLines[] = implode('', $translatedTokens);
    }

    $finalText = implode("\n", $translatedLines);

    return [
        'success' => true,
        'mode' => 'rule_based',
        'translated_text' => $finalText,
        'source_lang' => $sourceLang,
        'target_lang' => $targetLang,
        'breakdown' => $breakdown,
        'grammar_notes' => array_values(array_unique($grammarNotes)),
        'phonetic' => generatePhoneticTranscription($finalText, $targetLang, $lexicon),
        'confidence' => 92
    ];
}

/**
 * Phonetic pronunciation generator for Vladikish
 */
function generatePhoneticTranscription(string $text, string $targetLang, array $lexicon): string {
    if ($targetLang !== 'vladikish') return '';

    $words = preg_split('/([^\p{L}\p{N}\-]+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    $result = '';

    foreach ($words as $part) {
        if (trim($part) === '' || preg_match('/^[^\p{L}\p{N}]+$/u', $part)) {
            $result .= $part;
            continue;
        }

        $lookup = parseVladikishWord($part, $lexicon['vladToRu']);
        if ($lookup && !empty($lookup['entry']['pronunciation'])) {
            $result .= $lookup['entry']['pronunciation'];
        } else {
            $result .= $part;
        }
    }

    return $result;
}

/**
 * Get active API key (Hardcoded key prioritized, then DB settings)
 */
function getEffectiveApiKey(): string {
    $hardcoded = defined('HARDCODED_NVIDIA_API_KEY') ? trim(HARDCODED_NVIDIA_API_KEY) : '';
    if (!empty($hardcoded) && $hardcoded !== 'nvapi-YOUR_API_KEY_HERE') {
        return $hardcoded;
    }
    $dbKey = getTranslatorSetting('nvidia_api_key', '');
    if (!empty($dbKey)) {
        return $dbKey;
    }
    return $_SESSION['nvidia_api_key'] ?? '';
}

/**
 * Call Translation Router (LLM vs RBMT based on selected engine mode)
 */
function translateContent(string $inputText, string $sourceLang, string $targetLang, array $lexicon, string $engineMode = 'rbmt'): array {
    // If user explicitly chose RBMT (Rule-Based Machine Translation)
    if ($engineMode === 'rbmt') {
        return ruleBasedTranslate($inputText, $sourceLang, $targetLang, $lexicon);
    }

    // Otherwise attempt LLM neural translation
    $apiKey = getEffectiveApiKey();

    if (empty($apiKey)) {
        // Fallback to rule-based engine if no API key
        return ruleBasedTranslate($inputText, $sourceLang, $targetLang, $lexicon);
    }

    $model = getTranslatorSetting('nvidia_model', DEFAULT_NVIDIA_MODEL);
    $baseUrl = getTranslatorSetting('nvidia_base_url', DEFAULT_NVIDIA_BASE_URL);
    $endpoint = rtrim($baseUrl, '/') . '/chat/completions';

    // Compile conlang grounding dictionary
    $dictLines = [];
    foreach ($lexicon['entries'] as $e) {
        $dictLines[] = "- {$e['word']} ({$e['part_of_speech']}): RU='{$e['translation_ru']}', EN='{$e['translation_en']}'" . (!empty($e['example_sentence']) ? " (Ex: {$e['example_sentence']})" : "");
    }
    $dictContext = implode("\n", $dictLines);

    // Fetch grammar rules
    $db = getTranslatorDb();
    $gStmt = $db->query("SELECT rule_title, rule_description, rule_examples FROM " . tbl('conlang_grammar') . " WHERE language_code = 'vladikish'");
    $gramRules = $gStmt->fetchAll();
    $gramContext = "";
    foreach ($gramRules as $gr) {
        $gramContext .= "• {$gr['rule_title']}: {$gr['rule_description']} [Пример: {$gr['rule_examples']}]\n";
    }

    $directionLabel = ($sourceLang === 'ru') ? 'Russian to Vladikish' : 'Vladikish to Russian';

    $systemPrompt = <<<PROMPT
You are the Official Neural Translator for the conlang "Vladikish" and Russian, built to the highest standard of accuracy (Google Translate grade).
You perform high-fidelity, grammatically flawless bidirectional translation between Russian and Vladikish.

=== OFFICIAL VLADIKISH DICTIONARY ===
{$dictContext}

=== OFFICIAL VLADIKISH GRAMMAR RULES ===
{$gramContext}

=== STRICT TRANSLATION PROTOCOL (ZERO HALLUCINATION) ===
1. You MUST use official vocabulary words from the dictionary whenever a matching meaning exists.
2. SVO Structure: Vladikish follows Subject-Verb-Object word order (e.g. "Me toro Barka" = "Я люблю собаку").
3. Verb Tenses:
   - Present: root form (toro, velo, est).
   - Past: suffix -ti (toroti = полюбил, veloti = сиял/летел).
   - Future: prefix vo- (vo-toro = полюблю, vo-velo = полечу, vo-est = буду/будет).
4. Plural: add suffix -s after vowels (Barka -> Barkas, Korno -> Kornos) or -i after consonants.
5. Adjectives: end in -u (bonu, vanti) and follow the noun or precede 'est'.
6. Do NOT invent random words. If a complex Russian word has no single Vladikish equivalent, express it using compound known roots or keep proper names unchanged.

=== OUTPUT REQUIREMENT ===
Respond ONLY with a valid JSON object matching this exact schema:
{
  "translated_text": "The precise translation string",
  "phonetic_transcription": "Phonetic pronunciation guide with stress marks for Vladikish (e.g. 'Ва́нти зо́ра! Ме то́ро Ба́рка.')",
  "grammar_notes": ["Array of short grammar/morphology explanations applied, in Russian"],
  "breakdown": [
    {
      "original": "source_word",
      "translated": "target_word",
      "pos": "part_of_speech",
      "definition": "definition_in_russian",
      "pronunciation": "word_phonetics",
      "root": "root_word"
    }
  ],
  "alternatives": ["Optional 1-2 stylistic alternative phrasings in target language"],
  "confidence": 98
}
Do not include markdown codeblocks or extraneous text outside the JSON.
PROMPT;

    $userPrompt = "Translate from {$directionLabel}:\n\"{$inputText}\"";

    $payload = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt]
        ],
        'temperature' => 0.2,
        'max_tokens' => 2048,
        'response_format' => ['type' => 'json_object']
    ];

    $startTime = microtime(true);
    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . trim($apiKey)
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    $latencyMs = (int)round((microtime(true) - $startTime) * 1000);

    if ($curlErr || $httpCode !== 200 || empty($response)) {
        // Fallback to local high-precision rule engine
        $fallback = ruleBasedTranslate($inputText, $sourceLang, $targetLang, $lexicon);
        $fallback['ai_error'] = "AI fallback triggered (HTTP $httpCode / cURL: $curlErr)";
        return $fallback;
    }

    $resData = json_decode($response, true);
    $rawContent = $resData['choices'][0]['message']['content'] ?? '';
    $cleanJson = preg_replace('/^```json\s*|\s*```$/i', '', trim($rawContent));
    $parsed = json_decode($cleanJson, true);

    if (!is_array($parsed) || empty($parsed['translated_text'])) {
        $fallback = ruleBasedTranslate($inputText, $sourceLang, $targetLang, $lexicon);
        $fallback['ai_error'] = "JSON decode fallback";
        return $fallback;
    }

    // Optional: Log to ai_logs
    try {
        $logStmt = $db->prepare("INSERT INTO " . tbl('ai_logs') . " (action_type, model, prompt_tokens, completion_tokens, latency_ms, status) VALUES ('translate', :m, :pt, :ct, :lat, 'success')");
        $logStmt->execute([
            'm' => $model,
            'pt' => $resData['usage']['prompt_tokens'] ?? 0,
            'ct' => $resData['usage']['completion_tokens'] ?? 0,
            'lat' => $latencyMs
        ]);
    } catch (Exception $e) {}

    return [
        'success' => true,
        'mode' => 'neural_ai',
        'translated_text' => trim($parsed['translated_text']),
        'source_lang' => $sourceLang,
        'target_lang' => $targetLang,
        'phonetic' => $parsed['phonetic_transcription'] ?? generatePhoneticTranscription($parsed['translated_text'], $targetLang, $lexicon),
        'grammar_notes' => $parsed['grammar_notes'] ?? [],
        'breakdown' => $parsed['breakdown'] ?? [],
        'alternatives' => $parsed['alternatives'] ?? [],
        'confidence' => (int)($parsed['confidence'] ?? 98),
        'latency_ms' => $latencyMs,
        'model' => $model
    ];
}

// ----------------------------------------------------------------------------
// 4. AJAX API ROUTING (IF ACTION IS REQUESTED)
// ----------------------------------------------------------------------------
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if (!empty($action)) {
    header('Content-Type: application/json; charset=utf-8');
    $lexicon = loadVladikishLexicon();

    if ($action === 'translate') {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $text = trim($input['text'] ?? '');
        $sourceLang = trim($input['source_lang'] ?? 'auto');
        $targetLang = trim($input['target_lang'] ?? 'vladikish');
        $engineMode = trim($input['engine_mode'] ?? getTranslatorSetting('translator_engine_mode', DEFAULT_ENGINE_MODE));

        if ($text === '') {
            echo json_encode(['success' => true, 'translated_text' => '', 'breakdown' => []], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Auto-detect if needed
        $detected = $sourceLang;
        if ($sourceLang === 'auto') {
            $detected = detectLanguage($text, $lexicon);
            $targetLang = ($detected === 'ru') ? 'vladikish' : 'ru';
        }

        $result = translateContent($text, $detected, $targetLang, $lexicon, $engineMode);
        $result['detected_lang'] = $detected;
        $result['engine_mode'] = $engineMode;
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'dictionary_lookup') {
        $query = trim($_GET['q'] ?? '');
        $matches = [];
        $qLower = mb_strtolower($query, 'UTF-8');

        foreach ($lexicon['entries'] as $e) {
            if ($query === '' || 
                mb_strpos(mb_strtolower($e['word'], 'UTF-8'), $qLower) !== false ||
                mb_strpos(mb_strtolower($e['translation_ru'], 'UTF-8'), $qLower) !== false ||
                mb_strpos(mb_strtolower($e['translation_en'], 'UTF-8'), $qLower) !== false) {
                $matches[] = $e;
            }
        }
        echo json_encode(['success' => true, 'results' => array_slice($matches, 0, 50)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'save_settings' || $action === 'save_key') {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $key = trim($input['api_key'] ?? '');
        $model = trim($input['model'] ?? DEFAULT_NVIDIA_MODEL);
        $engineMode = trim($input['engine_mode'] ?? 'llm');
        
        $db = getTranslatorDb();
        $settTable = tbl('settings');

        if (!empty($key)) {
            $stmt = $db->prepare("INSERT INTO {$settTable} (setting_key, setting_value) VALUES ('nvidia_api_key', :k) ON CONFLICT(setting_key) DO UPDATE SET setting_value = :k2");
            $stmt->execute(['k' => $key, 'k2' => $key]);
        }

        $stmt2 = $db->prepare("INSERT INTO {$settTable} (setting_key, setting_value) VALUES ('nvidia_model', :m) ON CONFLICT(setting_key) DO UPDATE SET setting_value = :m2");
        $stmt2->execute(['m' => $model, 'm2' => $model]);

        $stmt3 = $db->prepare("INSERT INTO {$settTable} (setting_key, setting_value) VALUES ('translator_engine_mode', :em) ON CONFLICT(setting_key) DO UPDATE SET setting_value = :em2");
        $stmt3->execute(['em' => $engineMode, 'em2' => $engineMode]);

        echo json_encode([
            'success' => true, 
            'message' => 'Настройки переводчика успешно сохранены! 🐾',
            'engine_mode' => $engineMode,
            'model' => $model
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Invalid action']);
    exit;
}

// ----------------------------------------------------------------------------
// 5. HTML USER INTERFACE (GOOGLE TRANSLATE STYLE)
// ----------------------------------------------------------------------------
$effectiveApiKey = getEffectiveApiKey();
$hasApiKey = !empty($effectiveApiKey);
$isHardcodedKey = defined('HARDCODED_NVIDIA_API_KEY') && !empty(HARDCODED_NVIDIA_API_KEY) && HARDCODED_NVIDIA_API_KEY !== 'nvapi-YOUR_API_KEY_HERE';
$currentEngineMode = getTranslatorSetting('translator_engine_mode', DEFAULT_ENGINE_MODE);
$currentModel = getTranslatorSetting('nvidia_model', DEFAULT_NVIDIA_MODEL);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ShibaLingo Переводчик — Русский ⇄ Vladikish</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-main: #0c0f17;
            --bg-card: #151a26;
            --bg-card-hover: #1c2233;
            --bg-surface: #1e2638;
            --border-color: #273147;
            --border-focus: #3b82f6;
            --primary: #3b82f6;
            --primary-hover: #2563eb;
            --primary-glow: rgba(59, 130, 246, 0.25);
            --accent-shiba: #f59e0b;
            --accent-green: #10b981;
            --text-main: #f3f4f6;
            --text-muted: #9ca3af;
            --text-sub: #6b7280;
            --radius-lg: 20px;
            --radius-md: 12px;
            --radius-sm: 8px;
            --shadow-card: 0 10px 30px -5px rgba(0, 0, 0, 0.5), 0 0 0 1px var(--border-color);
            --font-main: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            --font-mono: 'JetBrains Mono', monospace;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            -webkit-font-smoothing: antialiased;
        }

        body {
            background-color: var(--bg-main);
            background-image: 
                radial-gradient(at 0% 0%, rgba(59, 130, 246, 0.08) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(245, 158, 11, 0.06) 0px, transparent 50%);
            color: var(--text-main);
            font-family: var(--font-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Top Header */
        header {
            padding: 16px 28px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(12, 15, 23, 0.8);
            backdrop-filter: blur(12px);
            position: sticky;
            top: 0;
            z-index: 50;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: inherit;
        }

        .brand-logo {
            font-size: 28px;
            background: linear-gradient(135deg, #f59e0b, #ef4444);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            filter: drop-shadow(0 2px 8px rgba(245, 158, 11, 0.3));
        }

        .brand-title {
            font-size: 20px;
            font-weight: 800;
            letter-spacing: -0.5px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .brand-badge {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: rgba(59, 130, 246, 0.15);
            color: #60a5fa;
            border: 1px solid rgba(59, 130, 246, 0.3);
            padding: 3px 8px;
            border-radius: 20px;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .btn-ghost {
            background: transparent;
            color: var(--text-muted);
            border: 1px solid var(--border-color);
            padding: 8px 14px;
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s ease;
        }

        .btn-ghost:hover {
            color: var(--text-main);
            background: var(--bg-card-hover);
            border-color: var(--text-sub);
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 600;
            padding: 6px 12px;
            border-radius: 30px;
            background: rgba(16, 185, 129, 0.1);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.25);
        }

        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #10b981;
            box-shadow: 0 0 10px #10b981;
            animation: pulse-dot 2s infinite;
        }

        @keyframes pulse-dot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(0.85); }
        }

        /* Container */
        .container {
            max-width: 1280px;
            width: 100%;
            margin: 0 auto;
            padding: 32px 20px;
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        /* Quick Chips Bar */
        .chips-container {
            display: flex;
            align-items: center;
            gap: 8px;
            overflow-x: auto;
            padding-bottom: 4px;
            scrollbar-width: none;
        }
        .chips-container::-webkit-scrollbar { display: none; }

        .chips-label {
            font-size: 12px;
            font-weight: 700;
            color: var(--text-sub);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
            margin-right: 4px;
        }

        .chip-item {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            color: var(--text-muted);
            font-size: 13px;
            padding: 6px 14px;
            border-radius: 20px;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.2s ease;
            user-select: none;
        }

        .chip-item:hover {
            color: var(--text-main);
            border-color: var(--primary);
            background: rgba(59, 130, 246, 0.1);
            transform: translateY(-1px);
        }

        /* Main Translation Grid */
        .translator-grid {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            gap: 16px;
            align-items: stretch;
        }

        @media (max-width: 900px) {
            .translator-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Translation Card */
        .trans-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            display: flex;
            flex-direction: column;
            box-shadow: var(--shadow-card);
            transition: border-color 0.2s, box-shadow 0.2s;
            position: relative;
            overflow: hidden;
        }

        .trans-card:focus-within {
            border-color: var(--border-focus);
            box-shadow: 0 10px 30px -5px rgba(59, 130, 246, 0.2), 0 0 0 1px var(--primary);
        }

        /* Card Header / Language Switcher */
        .card-header {
            padding: 12px 18px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(21, 26, 38, 0.6);
        }

        .lang-tabs {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .lang-btn {
            background: transparent;
            border: none;
            color: var(--text-muted);
            font-size: 14px;
            font-weight: 600;
            padding: 6px 12px;
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .lang-btn:hover {
            color: var(--text-main);
            background: rgba(255, 255, 255, 0.05);
        }

        .lang-btn.active {
            color: #60a5fa;
            background: rgba(59, 130, 246, 0.12);
            font-weight: 700;
        }

        .card-body {
            position: relative;
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 220px;
            padding: 18px;
        }

        .trans-textarea {
            width: 100%;
            flex: 1;
            background: transparent;
            border: none;
            outline: none;
            resize: none;
            color: var(--text-main);
            font-family: var(--font-main);
            font-size: 18px;
            line-height: 1.6;
            min-height: 140px;
        }

        .trans-textarea::placeholder {
            color: var(--text-sub);
        }

        /* Target Output styling */
        .target-output {
            width: 100%;
            flex: 1;
            font-size: 18px;
            line-height: 1.6;
            color: var(--text-main);
            min-height: 140px;
            white-space: pre-wrap;
            word-break: break-word;
            user-select: text;
        }

        .target-output.empty {
            color: var(--text-sub);
            font-style: italic;
        }

        /* Phonetic Bar */
        .phonetic-bar {
            margin-top: 10px;
            font-family: var(--font-mono);
            font-size: 13px;
            color: var(--accent-shiba);
            background: rgba(245, 158, 11, 0.08);
            border: 1px dashed rgba(245, 158, 11, 0.25);
            padding: 6px 12px;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Card Footer & Toolbar */
        .card-footer {
            padding: 12px 18px;
            border-top: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(21, 26, 38, 0.4);
        }

        .footer-tools {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .tool-btn {
            background: transparent;
            border: 1px solid transparent;
            color: var(--text-muted);
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.2s ease;
        }

        .tool-btn:hover {
            color: var(--text-main);
            background: var(--bg-surface);
            border-color: var(--border-color);
        }

        .tool-btn.listening {
            color: #ef4444;
            background: rgba(239, 68, 68, 0.15);
            border-color: rgba(239, 68, 68, 0.3);
            animation: pulse-mic 1.5s infinite;
        }

        @keyframes pulse-mic {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.15); }
        }

        .char-counter {
            font-size: 12px;
            color: var(--text-sub);
            font-family: var(--font-mono);
        }

        /* Middle Swap Button Container */
        .swap-wrapper {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .swap-btn {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            color: var(--text-muted);
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            transition: all 0.25s ease;
        }

        .swap-btn:hover {
            color: #60a5fa;
            border-color: var(--primary);
            background: var(--bg-surface);
            transform: rotate(180deg) scale(1.08);
            box-shadow: 0 0 15px var(--primary-glow);
        }

        /* Details & Breakdown Area */
        .details-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 16px;
        }

        @media (max-width: 900px) {
            .details-grid {
                grid-template-columns: 1fr;
            }
        }

        .info-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 20px;
            box-shadow: var(--shadow-card);
        }

        .info-header {
            font-size: 14px;
            font-weight: 700;
            color: var(--text-main);
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border-color);
        }

        /* Breakdown Word Badges */
        .words-flow {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .word-badge {
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 8px 12px;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .word-badge:hover {
            border-color: var(--primary);
            background: var(--bg-card-hover);
            transform: translateY(-2px);
        }

        .word-orig {
            font-weight: 700;
            font-size: 14px;
            color: #93c5fd;
        }

        .word-trans {
            font-size: 12px;
            color: var(--text-main);
        }

        .word-pos {
            font-size: 10px;
            color: var(--text-sub);
            text-transform: uppercase;
            font-weight: 700;
        }

        /* Grammar List */
        .grammar-list {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .grammar-item {
            font-size: 13px;
            color: var(--text-muted);
            background: var(--bg-surface);
            border-left: 3px solid var(--accent-shiba);
            padding: 8px 12px;
            border-radius: 0 var(--radius-sm) var(--radius-sm) 0;
            line-height: 1.4;
        }

        /* Modal Overlay */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(8px);
            z-index: 100;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-backdrop.open {
            display: flex;
        }

        .modal-box {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            max-width: 540px;
            width: 100%;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.7);
            overflow: hidden;
            animation: modal-in 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes modal-in {
            from { opacity: 0; transform: scale(0.95) translateY(10px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }

        .modal-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .modal-body {
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .form-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-main);
        }

        .form-input {
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            color: var(--text-main);
            padding: 10px 14px;
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-family: var(--font-main);
            outline: none;
        }

        .form-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 2px var(--primary-glow);
        }

        .btn-primary {
            background: var(--primary);
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }

        .btn-primary:hover {
            background: var(--primary-hover);
        }

        /* Loading Spinner */
        .spinner {
            display: inline-block;
            width: 18px;
            height: 18px;
            border: 2px solid rgba(255, 255, 255, 0.2);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.7s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Toast notification */
        .toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: var(--bg-surface);
            color: var(--text-main);
            border: 1px solid var(--primary);
            padding: 10px 18px;
            border-radius: var(--radius-md);
            font-size: 13px;
            font-weight: 600;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.4);
            transform: translateY(100px);
            opacity: 0;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            z-index: 200;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .toast.show {
            transform: translateY(0);
            opacity: 1;
        }
    </style>
</head>
<body>

    <!-- Header -->
    <header>
        <a href="translate.php" class="brand">
            <span class="brand-logo">🐕</span>
            <div class="brand-title">
                ShibaLingo
                <span class="brand-badge">Translate AI</span>
            </div>
        </a>
        <div class="header-actions">
            <div class="status-pill">
                <span class="status-dot"></span>
                <span id="engineBadge"><?= ($currentEngineMode === 'rbmt') ? '🛡️ RBMT Словарь' : ($hasApiKey ? '⚡ LLM Нейросеть' : '🛡️ RBMT Словарь') ?></span>
            </div>
            <button class="btn-ghost" onclick="openDictionaryModal()">
                <span>📖</span> Словарь
            </button>
            <button class="btn-ghost" onclick="openSettingsModal()">
                <span>⚙️</span> Настройки ИИ
            </button>
        </div>
    </header>

    <!-- Main Container -->
    <div class="container">
        
        <!-- Quick Phrase Chips -->
        <div class="chips-container">
            <span class="chips-label">Примеры:</span>
            <div class="chip-item" onclick="insertExample('Привет, друг! Как твои дела?')">👋 Привет, друг!</div>
            <div class="chip-item" onclick="insertExample('Небо сегодня прекрасное, собака Шиба летит.')">✈️ Прекрасное небо</div>
            <div class="chip-item" onclick="insertExample('Я очень люблю изучать язык Владикиш.')">❤️ Люблю язык</div>
            <div class="chip-item" onclick="insertExample('Zora vanti est! Me toro Barka.')">🐕 Zora vanti</div>
            <div class="chip-item" onclick="insertExample('Спокойной ночи, верный друг!')">🌙 Спокойной ночи</div>
        </div>

        <!-- Translator Grid -->
        <div class="translator-grid">
            
            <!-- Source Card -->
            <div class="trans-card">
                <div class="card-header">
                    <div class="lang-tabs">
                        <button class="lang-btn active" id="srcAutoBtn" onclick="setSourceLang('auto')">Автоопределение</button>
                        <button class="lang-btn" id="srcRuBtn" onclick="setSourceLang('ru')">Русский</button>
                        <button class="lang-btn" id="srcVladBtn" onclick="setSourceLang('vladikish')">Vladikish 🐕</button>
                    </div>
                    <button class="tool-btn" onclick="clearSourceText()" title="Очистить">✕</button>
                </div>
                <div class="card-body">
                    <textarea id="sourceInput" class="trans-textarea" placeholder="Введите текст для перевода..." autofocus></textarea>
                </div>
                <div class="card-footer">
                    <div class="footer-tools">
                        <button class="tool-btn" id="micBtn" onclick="toggleSpeechRecognition()" title="Голосовой ввод">🎙️</button>
                        <button class="tool-btn" onclick="speakSourceText()" title="Прослушать оригинал">🔊</button>
                    </div>
                    <span class="char-counter" id="charCount">0 / 2000</span>
                </div>
            </div>

            <!-- Swap Button -->
            <div class="swap-wrapper">
                <button class="swap-btn" onclick="swapLanguages()" title="Поменять языки местами">⇄</button>
            </div>

            <!-- Target Card -->
            <div class="trans-card">
                <div class="card-header">
                    <div class="lang-tabs">
                        <button class="lang-btn active" id="tgtLangBtn">Vladikish 🐕</button>
                    </div>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span id="loadingIndicator" style="display: none;"><span class="spinner"></span></span>
                    </div>
                </div>
                <div class="card-body">
                    <div id="targetOutput" class="target-output empty">Перевод появится здесь...</div>
                    <div id="phoneticContainer" class="phonetic-bar" style="display: none;">
                        <span>🗣️ Произношение:</span>
                        <span id="phoneticText" style="font-weight: 600;"></span>
                    </div>
                </div>
                <div class="card-footer">
                    <div class="footer-tools">
                        <button class="tool-btn" onclick="speakTargetText()" title="Прослушать перевод">🔊</button>
                        <button class="tool-btn" onclick="copyTranslation()" title="Скопировать перевод">📋</button>
                    </div>
                    <span class="char-counter" id="confidenceBadge" style="color: #34d399; font-weight: 600;"></span>
                </div>
            </div>

        </div>

        <!-- Details & Breakdown -->
        <div class="details-grid">
            <!-- Word-by-Word Inspector -->
            <div class="info-card">
                <div class="info-header">
                    <span>🔍 Пословный морфологический разбор</span>
                    <span style="font-size: 12px; color: var(--text-sub);">Нажмите на слово для деталей</span>
                </div>
                <div id="breakdownContainer" class="words-flow">
                    <span style="color: var(--text-sub); font-size: 13px;">Введите фразу, чтобы увидеть пословный грамматический анализ.</span>
                </div>
            </div>

            <!-- Grammar Notes -->
            <div class="info-card">
                <div class="info-header">
                    <span>📐 Правила и структура</span>
                </div>
                <ul id="grammarContainer" class="grammar-list">
                    <li class="grammar-item">SVO: Субъект + Глагол + Объект.</li>
                    <li class="grammar-item">Окончание -u для описательных прилагательных.</li>
                </ul>
            </div>
        </div>

    </div>

    <!-- Settings Modal -->
    <div class="modal-backdrop" id="settingsModal">
        <div class="modal-box">
            <div class="modal-header">
                <h3 style="font-size: 16px; font-weight: 700;">⚙️ Настройки движка перевода</h3>
                <button class="tool-btn" onclick="closeSettingsModal()">✕</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Режим работы переводчика</label>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 4px;">
                        <label style="background: var(--bg-surface); border: 1px solid var(--border-color); padding: 12px; border-radius: var(--radius-sm); cursor: pointer; display: flex; flex-direction: column; gap: 4px;" id="lblModeLlm">
                            <div style="display: flex; align-items: center; gap: 6px; font-weight: 700; font-size: 13px; color: #60a5fa;">
                                <input type="radio" name="engineMode" value="llm" <?= ($currentEngineMode === 'llm') ? 'checked' : '' ?>>
                                <span>⚡ Нейросеть (LLM)</span>
                            </div>
                            <span style="font-size: 11px; color: var(--text-muted); line-height: 1.3;">Глубокий контекстный перевод NVIDIA NIM</span>
                        </label>
                        <label style="background: var(--bg-surface); border: 1px solid var(--border-color); padding: 12px; border-radius: var(--radius-sm); cursor: pointer; display: flex; flex-direction: column; gap: 4px;" id="lblModeRbmt">
                            <div style="display: flex; align-items: center; gap: 6px; font-weight: 700; font-size: 13px; color: #34d399;">
                                <input type="radio" name="engineMode" value="rbmt" <?= ($currentEngineMode !== 'llm') ? 'checked' : '' ?>>
                                <span>🛡️ Словарь (RBMT)</span>
                            </div>
                            <span style="font-size: 11px; color: var(--text-muted); line-height: 1.3;">Мгновенный локальный движок по правилам</span>
                        </label>
                    </div>
                </div>

                <div class="form-group" style="margin-top: 6px;">
                    <label class="form-label" style="display: flex; justify-content: space-between;">
                        <span>NVIDIA / OpenAI API Key</span>
                        <?php if ($isHardcodedKey): ?>
                            <span style="font-size: 11px; color: #34d399; font-weight: 600;">🔒 Зашит в коде translate.php</span>
                        <?php endif; ?>
                    </label>
                    <input type="password" id="modalApiKey" class="form-input" placeholder="<?= $isHardcodedKey ? 'Ключ зашит в код (оставьте пустым или переопределите)' : 'nvapi-...' ?>" value="<?= e(getTranslatorSetting('nvidia_api_key', '')) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Модель нейросети</label>
                    <input type="text" id="modalModel" class="form-input" placeholder="meta/llama-3.3-70b-instruct" value="<?= e($currentModel) ?>">
                </div>
                <button class="btn-primary" onclick="saveSettings()">Сохранить настройки 🐾</button>
            </div>
        </div>
    </div>

    <!-- Dictionary Search Modal -->
    <div class="modal-backdrop" id="dictModal">
        <div class="modal-box" style="max-width: 680px;">
            <div class="modal-header">
                <h3 style="font-size: 16px; font-weight: 700;">📖 Официальный словарь Vladikish</h3>
                <button class="tool-btn" onclick="closeDictionaryModal()">✕</button>
            </div>
            <div class="modal-body">
                <input type="text" id="dictSearchInput" class="form-input" placeholder="Поиск по слову или русскому переводу..." oninput="searchDictionary()">
                <div id="dictResults" style="max-height: 380px; overflow-y: auto; display: flex; flex-direction: column; gap: 8px;">
                    <!-- Filled dynamically -->
                </div>
            </div>
        </div>
    </div>

    <!-- Toast Notification -->
    <div class="toast" id="toastMsg">
        <span id="toastIcon">📋</span>
        <span id="toastText">Скопировано в буфер обмена!</span>
    </div>

    <!-- JavaScript Client -->
    <script>
        let currentSourceLang = 'auto';
        let currentTargetLang = 'vladikish';
        let currentEngineMode = '<?= e($currentEngineMode) ?>';
        let debounceTimer = null;
        let recognition = null;
        let isListening = false;

        // Elements
        const sourceInput = document.getElementById('sourceInput');
        const targetOutput = document.getElementById('targetOutput');
        const charCount = document.getElementById('charCount');
        const loadingIndicator = document.getElementById('loadingIndicator');
        const phoneticContainer = document.getElementById('phoneticContainer');
        const phoneticText = document.getElementById('phoneticText');
        const confidenceBadge = document.getElementById('confidenceBadge');
        const breakdownContainer = document.getElementById('breakdownContainer');
        const grammarContainer = document.getElementById('grammarContainer');
        const tgtLangBtn = document.getElementById('tgtLangBtn');
        const micBtn = document.getElementById('micBtn');

        // Input listener with debounce
        sourceInput.addEventListener('input', () => {
            const len = sourceInput.value.length;
            charCount.innerText = `${len} / 2000`;
            
            clearTimeout(debounceTimer);
            if (len === 0) {
                targetOutput.innerText = 'Перевод появится здесь...';
                targetOutput.classList.add('empty');
                phoneticContainer.style.display = 'none';
                confidenceBadge.innerText = '';
                breakdownContainer.innerHTML = '<span style="color: var(--text-sub); font-size: 13px;">Введите фразу, чтобы увидеть пословный грамматический анализ.</span>';
                return;
            }

            loadingIndicator.style.display = 'inline-block';
            debounceTimer = setTimeout(performTranslation, 350);
        });

        async function performTranslation() {
            const text = sourceInput.value.trim();
            if (!text) {
                loadingIndicator.style.display = 'none';
                return;
            }

            try {
                const res = await fetch('translate.php?action=translate', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        text: text,
                        source_lang: currentSourceLang,
                        target_lang: currentTargetLang,
                        engine_mode: currentEngineMode
                    })
                });

                const data = await res.json();
                loadingIndicator.style.display = 'none';

                if (data.success) {
                    targetOutput.innerText = data.translated_text;
                    targetOutput.classList.remove('empty');

                    // Phonetics
                    if (data.phonetic) {
                        phoneticText.innerText = data.phonetic;
                        phoneticContainer.style.display = 'flex';
                    } else {
                        phoneticContainer.style.display = 'none';
                    }

                    // Confidence & Mode
                    const modeLabel = data.mode === 'neural_ai' ? '⚡ AI 98%' : '🛡️ RBMT 92%';
                    confidenceBadge.innerText = modeLabel;

                    // Breakdown
                    renderBreakdown(data.breakdown || []);

                    // Grammar notes
                    renderGrammarNotes(data.grammar_notes || []);
                }
            } catch (err) {
                loadingIndicator.style.display = 'none';
                console.error('Translation error:', err);
            }
        }

        function renderBreakdown(items) {
            if (!items.length) {
                breakdownContainer.innerHTML = '<span style="color: var(--text-sub); font-size: 13px;">Слова не найдены в словаре.</span>';
                return;
            }

            let html = '';
            items.forEach(item => {
                html += `
                    <div class="word-badge" title="Корень: ${item.root || '—'}\nПример: ${item.example || '—'}">
                        <div class="word-orig">${escapeHtml(item.original)} ➔ ${escapeHtml(item.translated)}</div>
                        <div class="word-trans">${escapeHtml(item.definition || '')}</div>
                        <div class="word-pos">${escapeHtml(item.pos || 'слово')} ${item.pronunciation ? '• [' + escapeHtml(item.pronunciation) + ']' : ''}</div>
                    </div>
                `;
            });
            breakdownContainer.innerHTML = html;
        }

        function renderGrammarNotes(notes) {
            if (!notes.length) {
                grammarContainer.innerHTML = `
                    <li class="grammar-item">SVO: Субъект + Глагол + Объект.</li>
                    <li class="grammar-item">Окончание -u для описательных прилагательных.</li>
                `;
                return;
            }

            let html = '';
            notes.forEach(note => {
                html += `<li class="grammar-item">${escapeHtml(note)}</li>`;
            });
            grammarContainer.innerHTML = html;
        }

        function setSourceLang(lang) {
            currentSourceLang = lang;
            document.getElementById('srcAutoBtn').classList.toggle('active', lang === 'auto');
            document.getElementById('srcRuBtn').classList.toggle('active', lang === 'ru');
            document.getElementById('srcVladBtn').classList.toggle('active', lang === 'vladikish');

            if (lang === 'ru') {
                currentTargetLang = 'vladikish';
                tgtLangBtn.innerText = 'Vladikish 🐕';
            } else if (lang === 'vladikish') {
                currentTargetLang = 'ru';
                tgtLangBtn.innerText = 'Русский 🇷🇺';
            } else {
                currentTargetLang = 'vladikish';
                tgtLangBtn.innerText = 'Vladikish 🐕';
            }

            performTranslation();
        }

        function swapLanguages() {
            const currentSrcText = sourceInput.value;
            const currentTgtText = targetOutput.innerText;

            if (currentTgtText && !targetOutput.classList.contains('empty')) {
                sourceInput.value = currentTgtText;
            }

            if (currentSourceLang === 'vladikish' || currentTargetLang === 'ru') {
                setSourceLang('ru');
            } else {
                setSourceLang('vladikish');
            }
        }

        function insertExample(text) {
            sourceInput.value = text;
            sourceInput.dispatchEvent(new Event('input'));
        }

        function clearSourceText() {
            sourceInput.value = '';
            sourceInput.dispatchEvent(new Event('input'));
            sourceInput.focus();
        }

        function copyTranslation() {
            const text = targetOutput.innerText;
            if (!text || targetOutput.classList.contains('empty')) return;

            navigator.clipboard.writeText(text).then(() => {
                showToast('📋 Перевод скопирован в буфер обмена!');
            });
        }

        // Text-to-Speech (Web Speech API)
        function speakTargetText() {
            const text = targetOutput.innerText;
            if (!text || targetOutput.classList.contains('empty')) return;
            speakText(text, currentTargetLang);
        }

        function speakSourceText() {
            const text = sourceInput.value;
            if (!text) return;
            speakText(text, currentSourceLang);
        }

        function speakText(text, lang) {
            if (!('speechSynthesis' in window)) {
                showToast('⚠️ Синтез речи не поддерживается браузером');
                return;
            }

            window.speechSynthesis.cancel();
            const utterance = new SpeechSynthesisUtterance(text);
            
            if (lang === 'ru') {
                utterance.lang = 'ru-RU';
                utterance.rate = 0.95;
            } else {
                // Vladikish uses Italian / Esperanto phonetic cadence
                utterance.lang = 'it-IT';
                utterance.rate = 0.9;
                utterance.pitch = 1.05;
            }

            window.speechSynthesis.speak(utterance);
        }

        // Speech-to-Text (Voice Input)
        function toggleSpeechRecognition() {
            const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            if (!SpeechRecognition) {
                showToast('⚠️ Голосовой ввод не поддерживается в этом браузере');
                return;
            }

            if (isListening) {
                recognition.stop();
                return;
            }

            recognition = new SpeechRecognition();
            recognition.lang = (currentSourceLang === 'vladikish') ? 'it-IT' : 'ru-RU';
            recognition.interimResults = false;

            recognition.onstart = () => {
                isListening = true;
                micBtn.classList.add('listening');
                showToast('🎙️ Слушаю голос...');
            };

            recognition.onresult = (e) => {
                const transcript = e.results[0][0].transcript;
                sourceInput.value = transcript;
                sourceInput.dispatchEvent(new Event('input'));
            };

            recognition.onend = () => {
                isListening = false;
                micBtn.classList.remove('listening');
            };

            recognition.onerror = () => {
                isListening = false;
                micBtn.classList.remove('listening');
            };

            recognition.start();
        }

        // Settings Modal Handlers
        function openSettingsModal() {
            document.getElementById('settingsModal').classList.add('open');
        }

        function closeSettingsModal() {
            document.getElementById('settingsModal').classList.remove('open');
        }

        async function saveSettings() {
            const key = document.getElementById('modalApiKey').value.trim();
            const model = document.getElementById('modalModel').value.trim();
            const selectedModeEl = document.querySelector('input[name="engineMode"]:checked');
            const selectedMode = selectedModeEl ? selectedModeEl.value : 'llm';

            const res = await fetch('translate.php?action=save_settings', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    api_key: key, 
                    model: model,
                    engine_mode: selectedMode
                })
            });

            const data = await res.json();
            if (data.success) {
                currentEngineMode = selectedMode;
                showToast('✨ Настройки сохранены!');
                closeSettingsModal();
                
                if (selectedMode === 'rbmt') {
                    document.getElementById('engineBadge').innerText = '🛡️ RBMT Словарь';
                } else {
                    document.getElementById('engineBadge').innerText = '⚡ LLM Нейросеть';
                }
                
                performTranslation();
            }
        }

        // Dictionary Modal Handlers
        function openDictionaryModal() {
            document.getElementById('dictModal').classList.add('open');
            searchDictionary();
        }

        function closeDictionaryModal() {
            document.getElementById('dictModal').classList.remove('open');
        }

        async function searchDictionary() {
            const q = document.getElementById('dictSearchInput').value.trim();
            const res = await fetch(`translate.php?action=dictionary_lookup&q=${encodeURIComponent(q)}`);
            const data = await res.json();
            const container = document.getElementById('dictResults');

            if (data.results && data.results.length) {
                let html = '';
                data.results.forEach(w => {
                    html += `
                        <div style="background: var(--bg-surface); border: 1px solid var(--border-color); padding: 10px 14px; border-radius: var(--radius-md); display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <div style="font-weight: 700; color: #93c5fd; font-size: 15px;">${escapeHtml(w.word)} <span style="font-size: 11px; color: var(--text-sub);">[${escapeHtml(w.pronunciation || '')}]</span></div>
                                <div style="font-size: 13px; color: var(--text-main);">${escapeHtml(w.translation_ru)} <span style="color: var(--text-sub);">(${escapeHtml(w.part_of_speech)})</span></div>
                                ${w.example_sentence ? `<div style="font-size: 11px; color: var(--accent-shiba); margin-top: 2px;">💬 ${escapeHtml(w.example_sentence)}</div>` : ''}
                            </div>
                            <button class="btn-ghost" style="padding: 4px 8px; font-size: 12px;" onclick="insertExample('${escapeHtml(w.word)}'); closeDictionaryModal();">Использовать</button>
                        </div>
                    `;
                });
                container.innerHTML = html;
            } else {
                container.innerHTML = '<div style="color: var(--text-sub); text-align: center; padding: 20px;">Слова не найдены</div>';
            }
        }

        function showToast(msg) {
            const toast = document.getElementById('toastMsg');
            document.getElementById('toastText').innerText = msg;
            toast.classList.add('show');
            setTimeout(() => toast.classList.remove('show'), 3000);
        }

        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }
    </script>
</body>
</html>
