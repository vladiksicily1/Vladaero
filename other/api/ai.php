<?php
/**
 * API Backend Proxy for AI (OpenAI-compatible & NVIDIA NIM)
 * Provides comprehensive content generation for all system entities
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/agent_tools.php';

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$db = getDb();

// -------------------------------------------------------------------------
// 1. Fetch available models from Base URL (/v1/models)
// -------------------------------------------------------------------------
if ($action === 'fetch_models') {
    $baseUrl = trim($input['base_url'] ?? $_POST['base_url'] ?? '');
    $apiKey = trim($input['api_key'] ?? $_POST['api_key'] ?? '');
    $res = fetchAiModels($baseUrl ?: null, $apiKey ?: null);
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

// -------------------------------------------------------------------------
// 2. AI Ping / Latency Test (Playground)
// -------------------------------------------------------------------------
if ($action === 'ai_ping') {
    $prompt = trim($input['prompt'] ?? 'Привет, Сиба! Ответь коротко: "Гав! Связь отличная 🐕"');
    $model = trim($input['model'] ?? '');
    
    $startTime = microtime(true);
    $res = callNvidiaApi([
        ['role' => 'user', 'content' => $prompt]
    ], $model ?: null, false);
    $latencyMs = round((microtime(true) - $startTime) * 1000);

    if ($res['success']) {
        echo json_encode([
            'success' => true,
            'reply' => $res['content'],
            'latency_ms' => $latencyMs,
            'model' => $model ?: getSetting('nvidia_model', DEFAULT_NVIDIA_MODEL)
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'success' => false,
            'error' => $res['error'],
            'latency_ms' => $latencyMs
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// -------------------------------------------------------------------------
// 3. AI Chat Tutor with Shiba-sensei
// -------------------------------------------------------------------------
if ($action === 'chat') {
    $sessionId = (int)($input['session_id'] ?? 1);
    $userMsg = trim($input['message'] ?? '');
    $langCode = trim($input['lang'] ?? 'vladikish');

    if (empty($userMsg)) {
        echo json_encode(['success' => false, 'error' => 'Пустое сообщение']);
        exit;
    }

    // Save user message to database
    $stmt = $db->prepare("INSERT INTO " . tbl('chat_messages') . " (session_id, sender, message) VALUES (:sid, 'user', :msg)");
    $stmt->execute(['sid' => $sessionId, 'msg' => $userMsg]);

    // Build context & system prompt
    $conlangContext = '';
    if ($langCode === 'vladikish') {
        $conlangContext = getVladikishContext();
    }

    // Check for URL in message or Web Search intent for Firecrawl
    $webContext = '';
    if (preg_match('/https?:\/\/[^\s]+/i', $userMsg, $urlMatch)) {
        $foundUrl = $urlMatch[0];
        $fetchRes = runAgentFetchUrl($foundUrl);
        if (!empty($fetchRes['content'])) {
            $webContext .= "\n\n[ДАННЫЕ ИЗ ИНТЕРНЕТА ЧЕРЕЗ FIRECRAWL ПО ССЫЛКЕ {$foundUrl}]:\n";
            $webContext .= "Заголовок: " . ($fetchRes['title'] ?? '') . "\n";
            $webContext .= $fetchRes['content'] . "\n[КОНЕЦ ДАННЫХ ИЗ ИНТЕРНЕТА]\n";
        }
    } elseif (preg_match('/(найди|поищи|интернет|новости|гугл|поиск|search|стать|факты|кто так|что так)/ui', $userMsg)) {
        $cleanSearchQuery = preg_replace('/(найди|поищи|в интернете|пожалуйста|сиба|поищи в интернете)/ui', '', $userMsg);
        $cleanSearchQuery = trim($cleanSearchQuery) ?: $userMsg;
        $searchRes = runAgentWebSearch($cleanSearchQuery, 3);
        if (!empty($searchRes['results'])) {
            $webContext .= "\n\n[РЕЗУЛЬТАТЫ ПОИСКА В ИНТЕРНЕТЕ ЧЕРЕЗ FIRECRAWL ПО ЗАПРОСУ '{$cleanSearchQuery}']:\n";
            foreach ($searchRes['results'] as $sr) {
                $webContext .= "- " . ($sr['title'] ?? '') . ": " . ($sr['snippet'] ?? '') . " (" . ($sr['url'] ?? '') . ")\n";
            }
            $webContext .= "[КОНЕЦ РЕЗУЛЬТАТОВ ПОИСКА]\n";
        }
    }

    $systemPrompt = "You are 'Сиба-сэнсэй' (Shiba-sensei), a friendly, cute, encouraging Japanese Shiba Inu dog who teaches languages.\n";
    $systemPrompt .= "You are chatting with a student in target language: '{$langCode}'.\n";
    $systemPrompt .= "Your tone is warm, enthusiastic, puppy-like (can occasionally use gentle dog expressions like 'Гав!', 'Лапку!'), helpful, and motivating.\n\n";

    $systemPrompt .= "STRICT HONESTY DIRECTIVE (NO HALLUCINATIONS / NO LIES):\n";
    $systemPrompt .= "If you don't know the answer, if a word doesn't exist in Vladikish, or if internet search returned no information, you MUST NEVER lie, make up facts, or invent words. Honestly and politely explain that this information is unknown or not found.\n\n";

    if (!empty($webContext)) {
        $systemPrompt .= "REAL-TIME WEB DATA (FIRECRAWL SEARCH & FETCH):\n{$webContext}\nUse the real-time internet data above to accurately answer the student's question, cite facts, or explain the topic.\n\n";
    }

    $customOverride = getSetting('ai_system_prompt_override', '');
    if (!empty($customOverride)) {
        $systemPrompt .= "CUSTOM INSTRUCTIONS: {$customOverride}\n\n";
    }

    if ($langCode === 'vladikish') {
        $systemPrompt .= "IMPORTANT: The language you are teaching is 'Vladikish' - a beautiful conlang.\n";
        $systemPrompt .= "Here is the OFFICIAL dictionary and grammar rules of Vladikish:\n";
        $systemPrompt .= $conlangContext . "\n";
        $systemPrompt .= "ALWAYS speak in Vladikish where possible, using the official words and grammar rules above.\n";
    }

    $systemPrompt .= "OUTPUT FORMAT REQUIREMENT:\n";
    $systemPrompt .= "You MUST reply ONLY with a valid JSON object matching this exact schema:\n";
    $systemPrompt .= "{\n";
    $systemPrompt .= '  "reply": "Your response in the target language (e.g. Vladikish or English or Italian)",' . "\n";
    $systemPrompt .= '  "translation": "Russian translation of your reply",' . "\n";
    $systemPrompt .= '  "corrections": "Short helpful grammar feedback/correction if the user made any errors, or null if their sentence was great"' . "\n";
    $systemPrompt .= "}\n";
    $systemPrompt .= "Do not include any extra text outside the JSON.";

    // Get recent chat history
    $histStmt = $db->prepare("SELECT sender, message FROM " . tbl('chat_messages') . " WHERE session_id = :sid ORDER BY id DESC LIMIT 8");
    $histStmt->execute(['sid' => $sessionId]);
    $history = array_reverse($histStmt->fetchAll());

    $messages = [
        ['role' => 'system', 'content' => $systemPrompt]
    ];

    foreach ($history as $h) {
        $role = ($h['sender'] === 'user') ? 'user' : 'assistant';
        $messages[] = ['role' => $role, 'content' => $h['message']];
    }

    $aiRes = callNvidiaApi($messages, null, false);

    if (!$aiRes['success']) {
        echo json_encode(['success' => false, 'error' => $aiRes['error']]);
        exit;
    }

    $raw = $aiRes['content'];
    $cleanJson = preg_replace('/^```json\s*|\s*```$/i', '', trim($raw));
    $parsed = json_decode($cleanJson, true);

    $reply = $parsed['reply'] ?? $raw;
    $translation = $parsed['translation'] ?? null;
    $corrections = $parsed['corrections'] ?? null;

    // Save AI reply to DB
    $saveAi = $db->prepare("INSERT INTO " . tbl('chat_messages') . " (session_id, sender, message, translation, corrections) VALUES (:sid, 'shiba', :msg, :tr, :corr)");
    $saveAi->execute([
        'sid' => $sessionId,
        'msg' => $reply,
        'tr' => $translation,
        'corr' => $corrections
    ]);

    echo json_encode([
        'success' => true,
        'reply' => $reply,
        'translation' => $translation,
        'corrections' => $corrections
    ]);
    exit;
}

// -------------------------------------------------------------------------
// 4. Generate Dictionary Words
// -------------------------------------------------------------------------
if ($action === 'generate_words') {
    $topic = trim($input['topic'] ?? 'Общение и дружба');
    $count = min(30, max(1, (int)($input['count'] ?? 5)));
    $lang = trim($input['lang'] ?? 'vladikish');

    $conlangContext = getVladikishContext();

    $systemPrompt = "You are the Lead Conlang Designer for 'Vladikish' - a melodic constructed language.\n";
    $systemPrompt .= "Current Vladikish Dictionary & Grammar:\n{$conlangContext}\n\n";
    $systemPrompt .= "TASK: Invent {$count} NEW, unique, creative, phonetically consistent words for Vladikish on topic '{$topic}'.\n";
    $systemPrompt .= "Words should sound soft, melodic (endings in -o, -u, -a, -i, -el, -is, -ar).\n";
    $systemPrompt .= "OUTPUT: Return ONLY a valid JSON array of word objects:\n";
    $systemPrompt .= "[\n  {\n    \"word\": \"Kaelo\",\n    \"part_of_speech\": \"noun\",\n    \"translation_ru\": \"звезда\",\n    \"translation_en\": \"star\",\n    \"pronunciation\": \"Ка́эло\",\n    \"example_sentence\": \"Kaelo velo in Aero.\"\n  }\n]";

    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => "Generate {$count} new words for topic: {$topic}"]
    ];

    $aiRes = callNvidiaApi($messages, null, true);
    if (!$aiRes['success']) {
        echo json_encode(['success' => false, 'error' => $aiRes['error']]);
        exit;
    }

    $cleanJson = preg_replace('/^```json\s*|\s*```$/i', '', trim($aiRes['content']));
    $wordsList = json_decode($cleanJson, true);

    if (!is_array($wordsList)) {
        echo json_encode(['success' => false, 'error' => 'Некорректный JSON от AI']);
        exit;
    }

    $insertStmt = $db->prepare("INSERT INTO " . tbl('conlang_dictionary') . " (language_code, word, part_of_speech, translation_ru, translation_en, pronunciation, example_sentence, created_by) 
                                VALUES (:l, :word, :pos, :ru, :en, :pron, :ex, 'ai')");

    $added = 0;
    foreach ($wordsList as $w) {
        if (!empty($w['word']) && !empty($w['translation_ru'])) {
            try {
                $insertStmt->execute([
                    'l' => $lang,
                    'word' => $w['word'],
                    'pos' => $w['part_of_speech'] ?? 'noun',
                    'ru' => $w['translation_ru'],
                    'en' => $w['translation_en'] ?? $w['translation_ru'],
                    'pron' => $w['pronunciation'] ?? $w['word'],
                    'ex' => $w['example_sentence'] ?? ''
                ]);
                $added++;
            } catch (Exception $e) {}
        }
    }

    echo json_encode(['success' => true, 'added_count' => $added, 'words' => $wordsList]);
    exit;
}

// -------------------------------------------------------------------------
// 5. Generate Grammar Rules
// -------------------------------------------------------------------------
if ($action === 'generate_grammar') {
    $topic = trim($input['topic'] ?? 'Времена глаголов и суффиксы');
    $count = min(5, max(1, (int)($input['count'] ?? 2)));

    $conlangContext = getVladikishContext();

    $systemPrompt = "You are a master conlang linguist for 'Vladikish'.\n";
    $systemPrompt .= "Current Grammar:\n{$conlangContext}\n\n";
    $systemPrompt .= "TASK: Create {$count} clear, elegant grammar rules for Vladikish regarding: '{$topic}'.\n";
    $systemPrompt .= "OUTPUT: Return ONLY a valid JSON array of rules:\n";
    $systemPrompt .= "[\n  {\n    \"rule_title\": \"Суффикс множественного числа (-i)\",\n    \"rule_category\": \"morphology\",\n    \"explanation\": \"Для образования множественного числа к существительным добавляется -i.\",\n    \"examples_json\": [{\"vlad\": \"barka -> barkai\", \"ru\": \"собака -> собаки\"}]\n  }\n]";

    $aiRes = callNvidiaApi([
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => "Generate {$count} grammar rules for: {$topic}"]
    ], null, true);

    if (!$aiRes['success']) {
        echo json_encode(['success' => false, 'error' => $aiRes['error']]);
        exit;
    }

    $cleanJson = preg_replace('/^```json\s*|\s*```$/i', '', trim($aiRes['content']));
    $rules = json_decode($cleanJson, true);

    if (!is_array($rules)) {
        echo json_encode(['success' => false, 'error' => 'Некорректный JSON грамматики']);
        exit;
    }

    $stmt = $db->prepare("INSERT INTO " . tbl('conlang_grammar') . " (language_code, rule_title, rule_description, rule_examples, order_num) VALUES ('vladikish', :t, :d, :ex, 10)");
    $added = 0;
    foreach ($rules as $r) {
        $rTitle = $r['rule_title'] ?? '';
        $rDesc = $r['rule_description'] ?? ($r['explanation'] ?? '');
        if (!empty($rTitle) && !empty($rDesc)) {
            $exStr = is_array($r['examples_json'] ?? ($r['rule_examples'] ?? '')) ? json_encode($r['examples_json'] ?? $r['rule_examples'], JSON_UNESCAPED_UNICODE) : (string)($r['rule_examples'] ?? ($r['examples_json'] ?? ''));
            $stmt->execute([
                't' => $rTitle,
                'd' => $rDesc,
                'ex' => $exStr
            ]);
            $added++;
        }
    }

    echo json_encode(['success' => true, 'added_count' => $added, 'rules' => $rules]);
    exit;
}

// -------------------------------------------------------------------------
// 6. Generate Interactive Lesson
// -------------------------------------------------------------------------
if ($action === 'generate_lesson') {
    $topic = trim($input['topic'] ?? 'Базовые фразы');
    $langCode = trim($input['lang'] ?? 'vladikish');
    $skillId = (int)($input['skill_id'] ?? 1);

    $conlangContext = ($langCode === 'vladikish') ? getVladikishContext() : '';

    $systemPrompt = "You are an expert Duolingo curriculum generator.\n";
    $systemPrompt .= "Generate 5 diverse, interactive exercises for learning '{$langCode}' on topic '{$topic}'.\n";
    $systemPrompt .= "Use a variety of exercise types: 'multiple_choice', 'fill_blank', 'word_bank', 'speak', 'find_error', 'judge', 'dialogue_fill', 'match_pairs'.\n";
    if ($langCode === 'vladikish') {
        $systemPrompt .= "Official Vladikish dictionary and grammar:\n{$conlangContext}\n";
    }
    $systemPrompt .= "CRITICAL: Every exercise MUST be 100% complete with non-empty correct answers, options, and explanations.\n";
    $systemPrompt .= "OUTPUT: Return ONLY a valid JSON array of 5 exercise objects, examples:\n";
    $systemPrompt .= "[\n";
    $systemPrompt .= "  {\"type\": \"multiple_choice\", \"question\": \"Выберите перевод:\", \"prompt\": \"Mira\", \"options\": [\"Привет\", \"Пока\", \"Ночь\", \"Дом\"], \"correct\": 0, \"explanation\": \"Mira = Привет\"},\n";
    $systemPrompt .= "  {\"type\": \"fill_blank\", \"question\": \"Заполните пропуск:\", \"sentence\": \"Me ___ Vladi!\", \"options\": [\"est\", \"esta\", \"esti\"], \"correct_word\": \"est\", \"translation\": \"Я Влади!\", \"explanation\": \"est = глагол-связка\"},\n";
    $systemPrompt .= "  {\"type\": \"word_bank\", \"question\": \"Соберите предложение:\", \"prompt\": \"Привет, друг!\", \"correct_sequence\": [\"Mira,\", \"Vladi!\"], \"word_pool\": [\"Mira,\", \"Vladi!\", \"Barka\", \"Aero\", \"Nox\"], \"explanation\": \"Mira Vladi!\"},\n";
    $systemPrompt .= "  {\"type\": \"speak\", \"question\": \"Произнесите в микрофон:\", \"prompt\": \"Mira, Vladi!\", \"explanation\": \"Произнесите фразу четко\"},\n";
    $systemPrompt .= "  {\"type\": \"dialogue_fill\", \"question\": \"Выберите ответ в диалоге:\", \"speaker_name\": \"Сиба\", \"speaker_avatar\": \"🐕\", \"prompt\": \"Mira! Como sta tu?\", \"prompt_translation\": \"Привет! Как дела?\", \"options\": [\"Bonu est!\", \"No, vale.\", \"Aero.\"], \"correct\": 0, \"explanation\": \"Bonu est = Всё хорошо!\"}\n";
    $systemPrompt .= "]";

    $aiRes = callNvidiaApi([
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => "Generate 5 diverse exercises for: {$topic}"]
    ], null, true);

    if (!$aiRes['success']) {
        echo json_encode(['success' => false, 'error' => $aiRes['error']]);
        exit;
    }

    $cleanJson = preg_replace('/^```json\s*|\s*```$/i', '', trim($aiRes['content']));
    $exercises = json_decode($cleanJson, true);

    if (!is_array($exercises)) {
        echo json_encode(['success' => false, 'error' => 'Некорректный JSON от AI']);
        exit;
    }

    $stmt = $db->prepare("INSERT INTO " . tbl('lessons') . " (skill_id, title, xp_reward, order_num, lesson_data) VALUES (:sid, :title, 20, 99, :data)");
    $stmt->execute([
        'sid' => $skillId,
        'title' => "Урок: {$topic}",
        'data' => json_encode($exercises, JSON_UNESCAPED_UNICODE)
    ]);

    $newLessonId = $db->lastInsertId();
    echo json_encode(['success' => true, 'lesson_id' => $newLessonId, 'exercises' => $exercises]);
    exit;
}

// -------------------------------------------------------------------------
// 7. Generate Interactive Story
// -------------------------------------------------------------------------
if ($action === 'generate_story') {
    $topic = trim($input['topic'] ?? 'Неожиданная встреча в парке');
    $langCode = trim($input['lang'] ?? 'vladikish');
    $level = (int)($input['level'] ?? 1);

    $conlangContext = ($langCode === 'vladikish') ? getVladikishContext() : '';

    $systemPrompt = "You are a creative dialogue writer for language learning stories in '{$langCode}'.\n";
    if ($langCode === 'vladikish') {
        $systemPrompt .= "Vladikish dictionary:\n{$conlangContext}\n";
    }
    $systemPrompt .= "OUTPUT: Return ONLY a JSON array of story dialogue lines and comprehension questions:\n";
    $systemPrompt .= "[\n  {\"character\": \"Shiba\", \"text\": \"Mira! Zora bonu est.\", \"translation\": \"Привет! Сегодня отличный день.\", \"avatar\": \"🐕\"},\n  {\"character\": \"Vladi\", \"text\": \"Mira! Vo-velo in Aero juntos?\", \"translation\": \"Привет! Полетим в небо вместе?\", \"avatar\": \"🧑\"},\n  {\"type\": \"question\", \"prompt\": \"Куда предложил отправиться Влади?\", \"options\": [\"В небо\", \"Спать\", \"В магазин\"], \"correct\": 0}\n]";

    $aiRes = callNvidiaApi([
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => "Generate story about: {$topic} (Level {$level})"]
    ], null, true);

    if (!$aiRes['success']) {
        echo json_encode(['success' => false, 'error' => $aiRes['error']]);
        exit;
    }

    $cleanJson = preg_replace('/^```json\s*|\s*```$/i', '', trim($aiRes['content']));
    $storyData = json_decode($cleanJson, true);

    if (!is_array($storyData)) {
        echo json_encode(['success' => false, 'error' => 'Некорректный JSON истории']);
        exit;
    }

    $stmt = $db->prepare("INSERT INTO " . tbl('stories') . " (title, language_code, level, cover_image, xp_reward, gems_reward, story_data) 
                          VALUES (:t, :l, :lvl, '', 35, 15, :d)");
    $stmt->execute([
        't' => $topic,
        'l' => $langCode,
        'lvl' => $level,
        'd' => json_encode($storyData, JSON_UNESCAPED_UNICODE)
    ]);

    $storyId = $db->lastInsertId();
    echo json_encode(['success' => true, 'story_id' => $storyId, 'story_data' => $storyData]);
    exit;
}

// -------------------------------------------------------------------------
// 8. Generate Daily Quests
// -------------------------------------------------------------------------
if ($action === 'generate_quests') {
    $count = min(5, max(1, (int)($input['count'] ?? 3)));

    $systemPrompt = "Generate {$count} fun gamified daily quests for a language learning platform.\n";
    $systemPrompt .= "OUTPUT: Return ONLY a valid JSON array:\n";
    $systemPrompt .= "[\n  {\"title\": \"Мастер Слов\", \"description\": \"Завершите 2 любых интерактивных урока\", \"req_type\": \"complete_lesson\", \"req_target\": 2, \"xp_reward\": 30, \"gems_reward\": 15}\n]";

    $aiRes = callNvidiaApi([
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => "Generate {$count} daily quests"]
    ], null, true);

    if (!$aiRes['success']) {
        echo json_encode(['success' => false, 'error' => $aiRes['error']]);
        exit;
    }

    $cleanJson = preg_replace('/^```json\s*|\s*```$/i', '', trim($aiRes['content']));
    $quests = json_decode($cleanJson, true);

    if (!is_array($quests)) {
        echo json_encode(['success' => false, 'error' => 'Некорректный JSON квестов']);
        exit;
    }

    $stmt = $db->prepare("INSERT INTO " . tbl('daily_quests') . " (title, description, req_type, req_target, xp_reward, gems_reward) VALUES (:t, :d, :rt, :tar, :x, :g)");
    $added = 0;
    foreach ($quests as $q) {
        if (!empty($q['title'])) {
            $stmt->execute([
                't' => $q['title'],
                'd' => $q['description'] ?? '',
                'rt' => $q['req_type'] ?? 'complete_lesson',
                'tar' => (int)($q['req_target'] ?? 1),
                'x' => (int)($q['xp_reward'] ?? 25),
                'g' => (int)($q['gems_reward'] ?? 10)
            ]);
            $added++;
        }
    }

    echo json_encode(['success' => true, 'added_count' => $added, 'quests' => $quests]);
    exit;
}

// -------------------------------------------------------------------------
// 9. Generate Achievements
// -------------------------------------------------------------------------
if ($action === 'generate_achievements') {
    $theme = trim($input['theme'] ?? 'Магия и Языки');
    $count = min(5, max(1, (int)($input['count'] ?? 3)));

    $systemPrompt = "Generate {$count} gamified achievements with emoji icons for language learners.\n";
    $systemPrompt .= "OUTPUT: Return ONLY a valid JSON array:\n";
    $systemPrompt .= "[\n  {\"slug\": \"conlang_polyglot\", \"icon\": \"🧙‍♂️\", \"title\": \"Архимаг Vladikish\", \"description\": \"Выучите 50 слов на Vladikish\", \"req_type\": \"words_count\", \"req_value\": 50, \"xp_reward\": 100, \"gems_reward\": 50}\n]";

    $aiRes = callNvidiaApi([
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => "Generate {$count} achievements on theme: {$theme}"]
    ], null, true);

    if (!$aiRes['success']) {
        echo json_encode(['success' => false, 'error' => $aiRes['error']]);
        exit;
    }

    $cleanJson = preg_replace('/^```json\s*|\s*```$/i', '', trim($aiRes['content']));
    $achievements = json_decode($cleanJson, true);

    if (!is_array($achievements)) {
        echo json_encode(['success' => false, 'error' => 'Некорректный JSON достижений']);
        exit;
    }

    $stmt = $db->prepare("INSERT INTO " . tbl('achievements') . " (slug, title, description, icon, xp_reward, gems_reward, req_type, req_value) VALUES (:s, :t, :d, :i, :x, :g, :rt, :rv)");
    $added = 0;
    foreach ($achievements as $a) {
        if (!empty($a['slug']) && !empty($a['title'])) {
            try {
                $stmt->execute([
                    's' => $a['slug'],
                    't' => $a['title'],
                    'd' => $a['description'] ?? '',
                    'i' => $a['icon'] ?? '🏆',
                    'x' => (int)($a['xp_reward'] ?? 50),
                    'g' => (int)($a['gems_reward'] ?? 20),
                    'rt' => $a['req_type'] ?? 'lessons_count',
                    'rv' => (int)($a['req_value'] ?? 5)
                ]);
                $added++;
            } catch (Exception $e) {}
        }
    }

    echo json_encode(['success' => true, 'added_count' => $added, 'achievements' => $achievements]);
    exit;
}

// -------------------------------------------------------------------------
// 10. Generate Shop Items & Skins
// -------------------------------------------------------------------------
if ($action === 'generate_shop_items') {
    $theme = trim($input['theme'] ?? 'Космос и Футуризм');
    $count = min(5, max(1, (int)($input['count'] ?? 3)));

    $systemPrompt = "Generate {$count} shop items/costumes for a Shiba Inu mascot in a language learning app.\n";
    $systemPrompt .= "OUTPUT: Return ONLY a valid JSON array:\n";
    $systemPrompt .= "[\n  {\"item_key\": \"skin_astronaut\", \"name\": \"Шиба-Астронавт 🧑‍🚀\", \"description\": \"Космический скафандр для изучения языка среди звезд\", \"icon\": \"🧑‍🚀\", \"price_gems\": 150, \"category\": \"skin\", \"effect_value\": \"astronaut\"}\n]";

    $aiRes = callNvidiaApi([
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => "Generate {$count} shop items for theme: {$theme}"]
    ], null, true);

    if (!$aiRes['success']) {
        echo json_encode(['success' => false, 'error' => $aiRes['error']]);
        exit;
    }

    $cleanJson = preg_replace('/^```json\s*|\s*```$/i', '', trim($aiRes['content']));
    $items = json_decode($cleanJson, true);

    if (!is_array($items)) {
        echo json_encode(['success' => false, 'error' => 'Некорректный JSON товаров']);
        exit;
    }

    $stmt = $db->prepare("INSERT INTO " . tbl('shop_items') . " (item_key, name, description, icon, price_gems, category, effect_value) VALUES (:k, :n, :d, :i, :p, :c, :e)");
    $added = 0;
    foreach ($items as $it) {
        if (!empty($it['item_key']) && !empty($it['name'])) {
            try {
                $stmt->execute([
                    'k' => $it['item_key'],
                    'n' => $it['name'],
                    'd' => $it['description'] ?? '',
                    'i' => $it['icon'] ?? '🎁',
                    'p' => (int)($it['price_gems'] ?? 100),
                    'c' => $it['category'] ?? 'skin',
                    'e' => $it['effect_value'] ?? ''
                ]);
                $added++;
            } catch (Exception $e) {}
        }
    }

    echo json_encode(['success' => true, 'added_count' => $added, 'items' => $items]);
    exit;
}

// -------------------------------------------------------------------------
// 11. Generate Promocodes
// -------------------------------------------------------------------------
if ($action === 'generate_promocodes') {
    $theme = trim($input['theme'] ?? 'Весенний фестиваль');
    $count = min(5, max(1, (int)($input['count'] ?? 3)));

    $systemPrompt = "Generate {$count} promo codes with bonuses (gems, hearts, xp) for language learning students.\n";
    $systemPrompt .= "OUTPUT: Return ONLY a valid JSON array:\n";
    $systemPrompt .= "[\n  {\"code\": \"SHIBA2026\", \"gems_bonus\": 50, \"hearts_bonus\": 3, \"xp_bonus\": 100, \"max_uses\": 500}\n]";

    $aiRes = callNvidiaApi([
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => "Generate {$count} promo codes for: {$theme}"]
    ], null, true);

    if (!$aiRes['success']) {
        echo json_encode(['success' => false, 'error' => $aiRes['error']]);
        exit;
    }

    $cleanJson = preg_replace('/^```json\s*|\s*```$/i', '', trim($aiRes['content']));
    $promos = json_decode($cleanJson, true);

    if (!is_array($promos)) {
        echo json_encode(['success' => false, 'error' => 'Некорректный JSON промокодов']);
        exit;
    }

    $stmt = $db->prepare("INSERT INTO " . tbl('promo_codes') . " (code, gems_bonus, hearts_bonus, xp_bonus, max_uses, expires_at, is_active) VALUES (:c, :g, :h, :x, :m, :exp, 1)");
    $added = 0;
    $expDate = date('Y-m-d H:i:s', strtotime('+30 days'));

    foreach ($promos as $p) {
        if (!empty($p['code'])) {
            try {
                $stmt->execute([
                    'c' => strtoupper(trim($p['code'])),
                    'g' => (int)($p['gems_bonus'] ?? 50),
                    'h' => (int)($p['hearts_bonus'] ?? 0),
                    'x' => (int)($p['xp_bonus'] ?? 50),
                    'm' => (int)($p['max_uses'] ?? 100),
                    'exp' => $expDate
                ]);
                $added++;
            } catch (Exception $e) {}
        }
    }

    echo json_encode(['success' => true, 'added_count' => $added, 'promocodes' => $promos]);
    exit;
}

// -------------------------------------------------------------------------
// 12. Generate Whole Skill Chapter With Lessons
// -------------------------------------------------------------------------
if ($action === 'generate_skill_with_lessons') {
    $lang = trim($input['lang'] ?? 'vladikish');
    $topic = trim($input['topic'] ?? 'Путешествия и транспорт');
    $level = (int)($input['level'] ?? 1);

    $conlangContext = ($lang === 'vladikish') ? getVladikishContext() : '';

    $systemPrompt = "You are a master curriculum designer for Duolingo-style language courses.\n";
    $systemPrompt .= "Generate a complete Skill chapter with 3 sequential lessons for '{$lang}' on theme '{$topic}'.\n";
    if ($lang === 'vladikish') {
        $systemPrompt .= "Vladikish dictionary and grammar:\n{$conlangContext}\n";
    }
    $systemPrompt .= "OUTPUT: Return ONLY a valid JSON object matching this schema:\n";
    $systemPrompt .= "{\n";
    $systemPrompt .= "  \"skill_title\": \"Путешествия и Город\",\n";
    $systemPrompt .= "  \"skill_icon\": \"✈️\",\n";
    $systemPrompt .= "  \"skill_description\": \"Учимся ориентироваться в городе и покупать билеты\",\n";
    $systemPrompt .= "  \"lessons\": [\n";
    $systemPrompt .= "    {\n";
    $systemPrompt .= "      \"title\": \"Урок 1: В аэропорту\",\n";
    $systemPrompt .= "      \"exercises\": [\n";
    $systemPrompt .= "        {\"type\": \"multiple_choice\", \"question\": \"Переведите слово Aero:\", \"prompt\": \"Aero\", \"options\": [\"Самолет / Небо\", \"Чай\", \"Дом\", \"Кошка\"], \"correct\": 0, \"explanation\": \"Aero = Небо / Полет\"},\n";
    $systemPrompt .= "        {\"type\": \"word_bank\", \"question\": \"Соберите фразу:\", \"prompt\": \"Я лечу\", \"correct_sequence\": [\"Me\", \"velo\"], \"word_pool\": [\"Me\", \"velo\", \"Barka\", \"Nox\"], \"explanation\": \"Me velo = Я лечу\"}\n";
    $systemPrompt .= "      ]\n";
    $systemPrompt .= "    },\n";
    $systemPrompt .= "    {\n";
    $systemPrompt .= "      \"title\": \"Урок 2: В отеле\",\n";
    $systemPrompt .= "      \"exercises\": [\n";
    $systemPrompt .= "        {\"type\": \"multiple_choice\", \"question\": \"Переведите слово Dom:\", \"prompt\": \"Dom\", \"options\": [\"Дом / Номер\", \"Вода\", \"Хлеб\", \"Улица\"], \"correct\": 0, \"explanation\": \"Dom = Дом\"}\n";
    $systemPrompt .= "      ]\n";
    $systemPrompt .= "    }\n";
    $systemPrompt .= "  ]\n";
    $systemPrompt .= "}";

    $aiRes = callNvidiaApi([
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => "Generate full skill with 3 lessons for: {$topic}"]
    ], null, true);

    if (!$aiRes['success']) {
        echo json_encode(['success' => false, 'error' => $aiRes['error']]);
        exit;
    }

    $cleanJson = preg_replace('/^```json\s*|\s*```$/i', '', trim($aiRes['content']));
    $skillData = json_decode($cleanJson, true);

    if (!is_array($skillData) || empty($skillData['skill_title'])) {
        echo json_encode(['success' => false, 'error' => 'Некорректный JSON раздела от AI']);
        exit;
    }

    // 1. Insert Skill
    $stmt = $db->prepare("INSERT INTO " . tbl('skills') . " (language_code, title, icon, level, order_num, description) VALUES (:l, :t, :i, :lev, 99, :d)");
    $stmt->execute([
        'l' => $lang,
        't' => $skillData['skill_title'],
        'i' => $skillData['skill_icon'] ?? '🗺️',
        'lev' => $level,
        'd' => $skillData['skill_description'] ?? ''
    ]);

    $newSkillId = $db->lastInsertId();

    // 2. Insert Lessons
    $lessonInsert = $db->prepare("INSERT INTO " . tbl('lessons') . " (skill_id, title, xp_reward, order_num, lesson_data) VALUES (:sid, :t, 20, :ord, :d)");
    $lessonsCreated = 0;

    if (isset($skillData['lessons']) && is_array($skillData['lessons'])) {
        foreach ($skillData['lessons'] as $idx => $les) {
            $lesTitle = $les['title'] ?? ("Урок " . ($idx + 1));
            $exercises = $les['exercises'] ?? [];
            if (!empty($exercises)) {
                $lessonInsert->execute([
                    'sid' => $newSkillId,
                    't' => $lesTitle,
                    'ord' => $idx + 1,
                    'd' => json_encode($exercises, JSON_UNESCAPED_UNICODE)
                ]);
                $lessonsCreated++;
            }
        }
    }

    echo json_encode([
        'success' => true,
        'skill_id' => $newSkillId,
        'skill_title' => $skillData['skill_title'],
        'lessons_created' => $lessonsCreated
    ]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Неизвестное действие']);
