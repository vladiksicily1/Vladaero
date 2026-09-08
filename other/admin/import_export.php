<?php
/**
 * ShibaLingo - Master Universal JSON Data Hub & AI-Ready Sync Center
 * Full Dump Generation with Embedded AI Instructions & Smart Auto-Detecting Importer
 */

require_once __DIR__ . '/auth_check.php';
$admin = requireAdminAuth();
$db = getDb();

// =============================================================================
// 1. HELPER: Generate Complete Mega JSON Data Array with AI Instructions
// =============================================================================
function generateFullDumpArray(PDO $db): array {
    // 1. Languages
    $languages = $db->query("SELECT * FROM " . tbl('languages') . " ORDER BY is_conlang DESC, code ASC")->fetchAll(PDO::FETCH_ASSOC);

    // 2. Skills
    $skills = $db->query("SELECT * FROM " . tbl('skills') . " ORDER BY language_code ASC, order_num ASC")->fetchAll(PDO::FETCH_ASSOC);

    // 3. Lessons with decoded exercise data
    $rawLessons = $db->query("SELECT l.*, s.title as skill_title, s.language_code 
                              FROM " . tbl('lessons') . " l 
                              JOIN " . tbl('skills') . " s ON l.skill_id = s.id 
                              ORDER BY s.language_code ASC, s.order_num ASC, l.order_num ASC")->fetchAll(PDO::FETCH_ASSOC);
    $lessons = [];
    foreach ($rawLessons as $l) {
        $exData = json_decode($l['lesson_data'], true) ?: [];
        $lessons[] = [
            'id' => (int)$l['id'],
            'skill_id' => (int)$l['skill_id'],
            'skill_title' => $l['skill_title'],
            'language_code' => $l['language_code'],
            'title' => $l['title'],
            'xp_reward' => (int)$l['xp_reward'],
            'order_num' => (int)$l['order_num'],
            'exercises' => $exData
        ];
    }

    // 4. Dictionary (Vladikish & Conlang)
    $dictionary = $db->query("SELECT * FROM " . tbl('conlang_dictionary') . " ORDER BY word ASC")->fetchAll(PDO::FETCH_ASSOC);

    // 5. Grammar Rules
    $grammar = $db->query("SELECT * FROM " . tbl('conlang_grammar') . " ORDER BY order_num ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

    // 6. Interactive Stories
    $rawStories = $db->query("SELECT * FROM " . tbl('stories') . " ORDER BY level ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $stories = [];
    foreach ($rawStories as $st) {
        $stData = json_decode($st['story_data'], true) ?: [];
        $stories[] = [
            'id' => (int)$st['id'],
            'title' => $st['title'],
            'language_code' => $st['language_code'],
            'level' => (int)$st['level'],
            'cover_image' => $st['cover_image'] ?? '',
            'xp_reward' => (int)$st['xp_reward'],
            'gems_reward' => (int)$st['gems_reward'],
            'story_dialogue' => $stData
        ];
    }

    // 7. Daily Quests
    $quests = $db->query("SELECT * FROM " . tbl('daily_quests') . " ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

    // 8. Achievements
    $achievements = $db->query("SELECT * FROM " . tbl('achievements') . " ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

    // 9. Shop Items
    $shopItems = $db->query("SELECT * FROM " . tbl('shop_items') . " ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

    // 10. Promo Codes
    $promoCodes = $db->query("SELECT * FROM " . tbl('promo_codes') . " ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

    // Embedded Detailed AI Instructions
    $aiInstructions = [
        "instruction_ru" => "ИНСТРУКЦИЯ ДЛЯ ИСКУССТВЕННОГО ИНТЕЛЛЕКТА (AI / LLM): Данный JSON-файл является полной базой знаний и курсов платформы ShibaLingo (главный конланг — Vladikish). Вы можете использовать эту схему для добавления новых уроков, упражнений, глав, слов словаря и грамматических правил. При генерации новых данных возвращайте валидный JSON, соответствующий ключам ниже. Вы можете вернуть либо целый обновленный файл, либо отдельный массив (например, массив уроков `lessons` или массив слов `dictionary`). Сайт автоматически определит формат при импорте.",
        "instruction_en" => "AI / LLM PROMPT INSTRUCTIONS: This JSON contains the complete knowledge base and curriculum for ShibaLingo language learning platform. You can read, expand, and generate new lessons, chapters, dictionary words, and grammar rules. Ensure all generated exercises contain non-empty correct answers, options, and explanations matching the supported schemas.",
        "supported_exercise_types" => [
            "multiple_choice" => [
                "description" => "Выбор правильного перевода из 4 вариантов",
                "schema" => [
                    "type" => "multiple_choice",
                    "question" => "Выберите правильный перевод:",
                    "prompt" => "Mira",
                    "options" => ["Привет", "Пока", "Дом", "Ночь"],
                    "correct" => 0,
                    "explanation" => "Mira означает Привет"
                ]
            ],
            "fill_blank" => [
                "description" => "Вставка в пропуск (Cloze) с интерактивным слотом",
                "schema" => [
                    "type" => "fill_blank",
                    "question" => "Заполните пропуск в предложении:",
                    "sentence" => "Me ___ Vladi!",
                    "options" => ["est", "esta", "esti"],
                    "correct_word" => "est",
                    "translation" => "Я Влади!",
                    "explanation" => "est — глагол-связка настоящего времени"
                ]
            ],
            "word_bank" => [
                "description" => "Сборка предложения из перемешанных плашек",
                "schema" => [
                    "type" => "word_bank",
                    "question" => "Соберите предложение:",
                    "prompt" => "Привет, друг!",
                    "correct_sequence" => ["Mira,", "Vladi!"],
                    "word_pool" => ["Mira,", "Vladi!", "Barka", "Aero", "Nox"],
                    "explanation" => "Mira = Привет, Vladi = Друг"
                ]
            ],
            "speak" => [
                "description" => "Тренировка произношения в микрофон (Web Speech API)",
                "schema" => [
                    "type" => "speak",
                    "question" => "Произнесите фразу в микрофон:",
                    "prompt" => "Mira, Vladi!",
                    "explanation" => "Произнесите фразу четко в микрофон"
                ]
            ],
            "listen_tap" => [
                "description" => "Аудирование вслепую (текст скрыт, сборка на слух)",
                "schema" => [
                    "type" => "listen_tap",
                    "question" => "Соберите услышанное предложение:",
                    "prompt" => "Zora bonu est",
                    "correct_sequence" => ["Zora", "bonu", "est"],
                    "word_pool" => ["Zora", "bonu", "est", "mira", "aero"],
                    "explanation" => "Zora bonu est = Сегодня хороший день"
                ]
            ],
            "find_error" => [
                "description" => "Поиск и нажатие на грамматически ошибочное слово",
                "schema" => [
                    "type" => "find_error",
                    "question" => "Найдите и нажмите на ошибочное слово в предложении:",
                    "sentence_words" => ["Me", "bonu", "esta", "nox"],
                    "error_word" => "esta",
                    "translation" => "У меня хорошая ночь",
                    "explanation" => "Вместо esta нужно использовать est"
                ]
            ],
            "judge" => [
                "description" => "Оценка утверждения (Правда / Ложь)",
                "schema" => [
                    "type" => "judge",
                    "question" => "Оцените утверждение:",
                    "prompt" => "Mira",
                    "statement" => "Это слово означает «До свидания»",
                    "is_true" => false,
                    "explanation" => "Mira означает Привет, а не До свидания."
                ]
            ],
            "dialogue_fill" => [
                "description" => "Мини-диалог внутри урока с выбором ответа персонажу",
                "schema" => [
                    "type" => "dialogue_fill",
                    "question" => "Выберите подходящую реплику в диалоге:",
                    "speaker_name" => "Сиба",
                    "speaker_avatar" => "🐕",
                    "prompt" => "Mira! Como sta tu?",
                    "prompt_translation" => "Привет! Как дела?",
                    "options" => ["Bonu est!", "No, vale.", "Aero."],
                    "correct" => 0,
                    "explanation" => "Bonu est = Всё отлично!"
                ]
            ],
            "match_pairs" => [
                "description" => "Сопоставление пар слов и переводов",
                "schema" => [
                    "type" => "match_pairs",
                    "question" => "Сопоставьте пары слов:",
                    "pairs" => [
                        ["left" => "Mira", "right" => "Привет"],
                        ["left" => "Barka", "right" => "Собака"],
                        ["left" => "Aero", "right" => "Небо"]
                    ],
                    "explanation" => "Все пары успешно сопоставлены!"
                ]
            ],
            "translate" => [
                "description" => "Свободный перевод с клавиатуры",
                "schema" => [
                    "type" => "translate",
                    "question" => "Переведите фразу:",
                    "prompt" => "Mira",
                    "correct_answers" => ["Привет", "Здравствуй", "Приветствую"],
                    "explanation" => "Mira = Привет"
                ]
            ],
            "listening_dictation" => [
                "description" => "Диктант на слух",
                "schema" => [
                    "type" => "listening_dictation",
                    "question" => "Напишите услышанное слово:",
                    "prompt" => "Kaelo",
                    "explanation" => "Kaelo = Звезда"
                ]
            ]
        ]
    ];

    return [
        "_ai_instructions" => $aiInstructions,
        "platform" => [
            "name" => "ShibaLingo Language Platform",
            "version" => "2.0",
            "exported_at" => date('c'),
            "database_driver" => Database::getDriver(),
            "total_languages" => count($languages),
            "total_skills" => count($skills),
            "total_lessons" => count($lessons),
            "total_dictionary_words" => count($dictionary),
            "total_grammar_rules" => count($grammar),
            "total_stories" => count($stories),
            "total_daily_quests" => count($quests),
            "total_achievements" => count($achievements),
            "total_shop_items" => count($shopItems),
            "total_promo_codes" => count($promoCodes)
        ],
        "languages" => $languages,
        "skills" => $skills,
        "lessons" => $lessons,
        "conlang_dictionary" => $dictionary,
        "conlang_grammar" => $grammar,
        "stories" => $stories,
        "daily_quests" => $quests,
        "achievements" => $achievements,
        "shop_items" => $shopItems,
        "promo_codes" => $promoCodes
    ];
}

// =============================================================================
// 2. EXPORT DISPATCHERS (MUST RUN BEFORE HTML OUTPUT TO PREVENT 500/WARNINGS)
// =============================================================================
if (isset($_GET['action'])) {
    $act = $_GET['action'];

    if ($act === 'export_full_json') {
        $data = generateFullDumpArray($db);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="shibalingo_full_database_dump_' . date('Y-m-d_H-i') . '.json"');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($act === 'export_dict') {
        $words = $db->query("SELECT * FROM " . tbl('conlang_dictionary') . " ORDER BY word ASC")->fetchAll(PDO::FETCH_ASSOC);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="shibalingo_dictionary_' . date('Y-m-d') . '.json"');
        echo json_encode(["dictionary" => $words], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($act === 'export_lessons') {
        $rawLessons = $db->query("SELECT l.*, s.title as skill_title, s.language_code FROM " . tbl('lessons') . " l JOIN " . tbl('skills') . " s ON l.skill_id = s.id ORDER BY s.language_code, l.order_num")->fetchAll(PDO::FETCH_ASSOC);
        $lessons = [];
        foreach ($rawLessons as $l) {
            $l['exercises'] = json_decode($l['lesson_data'], true) ?: [];
            unset($l['lesson_data']);
            $lessons[] = $l;
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="shibalingo_lessons_' . date('Y-m-d') . '.json"');
        echo json_encode(["lessons" => $lessons], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($act === 'export_grammar') {
        $rules = $db->query("SELECT * FROM " . tbl('conlang_grammar') . " ORDER BY order_num, id")->fetchAll(PDO::FETCH_ASSOC);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="shibalingo_grammar_' . date('Y-m-d') . '.json"');
        echo json_encode(["grammar" => $rules], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($act === 'export_stories') {
        $rawStories = $db->query("SELECT * FROM " . tbl('stories') . " ORDER BY level, id")->fetchAll(PDO::FETCH_ASSOC);
        $stories = [];
        foreach ($rawStories as $st) {
            $st['story_dialogue'] = json_decode($st['story_data'], true) ?: [];
            unset($st['story_data']);
            $stories[] = $st;
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="shibalingo_stories_' . date('Y-m-d') . '.json"');
        echo json_encode(["stories" => $stories], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// =============================================================================
// 3. SMART UNIVERSAL IMPORT ENGINE
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_action'])) {
    $jsonContent = '';

    // A. Read from uploaded file
    if (!empty($_FILES['import_file']['tmp_name']) && is_uploaded_file($_FILES['import_file']['tmp_name'])) {
        $jsonContent = file_get_contents($_FILES['import_file']['tmp_name']);
    }
    // B. Or read from textarea
    elseif (!empty($_POST['json_text'])) {
        $jsonContent = trim($_POST['json_text']);
    }

    if (empty($jsonContent)) {
        $error = 'Ошибка: Пожалуйста, загрузите .json файл или вставьте JSON-текст в поле ввода!';
    } else {
        $data = json_decode($jsonContent, true);
        if (!is_array($data)) {
            $error = 'Ошибка: Некорректный JSON! Проверьте синтаксис (запятые, кавычки) через jsonlint.com.';
        } else {
            // Processing Counters
            $counts = [
                'languages' => 0,
                'skills' => 0,
                'lessons' => 0,
                'dictionary' => 0,
                'grammar' => 0,
                'stories' => 0,
                'quests' => 0,
                'achievements' => 0,
                'shop_items' => 0,
                'promocodes' => 0,
                'detected_types' => []
            ];

            // -----------------------------------------------------------------
            // AUTO-DETECTION & EXTRACTION
            // -----------------------------------------------------------------

            // 1. Languages Array
            $languagesList = $data['languages'] ?? [];
            if (empty($languagesList) && isset($data[0]['code'], $data[0]['name'], $data[0]['flag'])) {
                $languagesList = $data;
            }
            if (!empty($languagesList) && is_array($languagesList)) {
                $counts['detected_types'][] = '🌐 Языки (' . count($languagesList) . ')';
                $stmtLang = $db->prepare("INSERT OR REPLACE INTO " . tbl('languages') . " (code, name, native_name, flag, description, is_conlang) VALUES (:c, :n, :nn, :f, :d, :cl)");
                foreach ($languagesList as $l) {
                    if (!empty($l['code']) && !empty($l['name'])) {
                        try {
                            $stmtLang->execute([
                                'c' => strtolower(trim($l['code'])),
                                'n' => trim($l['name']),
                                'nn' => trim($l['native_name'] ?? $l['name']),
                                'f' => trim($l['flag'] ?? '🌐'),
                                'd' => trim($l['description'] ?? ''),
                                'cl' => (int)($l['is_conlang'] ?? 0)
                            ]);
                            $counts['languages']++;
                        } catch (Exception $e) {}
                    }
                }
            }

            // -----------------------------------------------------------------
            // 2. SKILLS & LESSONS LINKAGE MAPPING ENGINE
            // -----------------------------------------------------------------
            $skillIdMap = [];
            $skillTitleMap = [];

            // Pre-fill existing skills from DB
            $existingSkills = $db->query("SELECT id, language_code, title FROM " . tbl('skills'))->fetchAll(PDO::FETCH_ASSOC);
            foreach ($existingSkills as $esk) {
                $lKey = strtolower(trim($esk['language_code']));
                $tKey = mb_strtolower(trim($esk['title']), 'UTF-8');
                $skillTitleMap[$lKey . '::' . $tKey] = (int)$esk['id'];
                $skillTitleMap['any::' . $tKey] = (int)$esk['id'];
            }

            $extractedNestedLessons = [];

            // 2. Process Skills Array
            $skillsList = $data['skills'] ?? [];
            if (empty($skillsList) && isset($data[0]['title']) && (isset($data[0]['language_code']) || isset($data[0]['icon']) || isset($data[0]['lessons']))) {
                if (!isset($data[0]['exercises']) && !isset($data[0]['lesson_data'])) {
                    $skillsList = $data;
                }
            }

            if (!empty($skillsList) && is_array($skillsList)) {
                $counts['detected_types'][] = '🗺️ Разделы / Главы (' . count($skillsList) . ')';
                
                $stmtInsertSkill = $db->prepare("INSERT INTO " . tbl('skills') . " (language_code, title, icon, level, order_num, description) VALUES (:l, :t, :i, :lev, :ord, :d)");
                $stmtUpdateSkill = $db->prepare("UPDATE " . tbl('skills') . " SET icon = :i, level = :lev, order_num = :ord, description = :d WHERE id = :id");

                foreach ($skillsList as $sk) {
                    if (!empty($sk['title'])) {
                        $skLang = strtolower(trim($sk['language_code'] ?? 'vladikish'));
                        $skTitle = trim($sk['title']);
                        $skIcon = trim($sk['icon'] ?? '🐾');
                        $skLevel = (int)($sk['level'] ?? 1);
                        $skOrder = (int)($sk['order_num'] ?? 1);
                        $skDesc = trim($sk['description'] ?? '');
                        $rawOldSkillId = isset($sk['id']) ? (int)$sk['id'] : null;

                        $mapKey = $skLang . '::' . mb_strtolower($skTitle, 'UTF-8');
                        $dbSkillId = null;

                        if (isset($skillTitleMap[$mapKey])) {
                            $dbSkillId = $skillTitleMap[$mapKey];
                            try {
                                $stmtUpdateSkill->execute([
                                    'i' => $skIcon,
                                    'lev' => $skLevel,
                                    'ord' => $skOrder,
                                    'd' => $skDesc,
                                    'id' => $dbSkillId
                                ]);
                            } catch (Exception $e) {}
                        } else {
                            try {
                                $stmtInsertSkill->execute([
                                    'l' => $skLang,
                                    't' => $skTitle,
                                    'i' => $skIcon,
                                    'lev' => $skLevel,
                                    'ord' => $skOrder,
                                    'd' => $skDesc
                                ]);
                                $dbSkillId = (int)$db->lastInsertId();
                                $skillTitleMap[$mapKey] = $dbSkillId;
                                $skillTitleMap['any::' . mb_strtolower($skTitle, 'UTF-8')] = $dbSkillId;
                                $counts['skills']++;
                            } catch (Exception $e) {}
                        }

                        if ($dbSkillId) {
                            if ($rawOldSkillId !== null && $rawOldSkillId > 0) {
                                $skillIdMap[$rawOldSkillId] = $dbSkillId;
                                $skillIdMap[$skLang . '::' . $rawOldSkillId] = $dbSkillId;
                            }

                            // Extract nested lessons inside this skill if present
                            if (!empty($sk['lessons']) && is_array($sk['lessons'])) {
                                foreach ($sk['lessons'] as $nestedLsn) {
                                    $nestedLsn['skill_id'] = $dbSkillId;
                                    $nestedLsn['skill_title'] = $skTitle;
                                    $nestedLsn['language_code'] = $skLang;
                                    $extractedNestedLessons[] = $nestedLsn;
                                }
                            }
                        }
                    }
                }
            }

            // 3. Process Lessons Array
            $lessonsList = $data['lessons'] ?? [];
            if (empty($lessonsList)) {
                // Check if the root is a single lesson
                if (isset($data['title']) && (isset($data['exercises']) || isset($data['lesson_data']))) {
                    $lessonsList = [$data];
                }
                // Or root is array of lessons
                elseif (isset($data[0]['title']) && (isset($data[0]['exercises']) || isset($data[0]['lesson_data']))) {
                    $lessonsList = $data;
                }
            }

            // Merge with extracted nested lessons
            if (!empty($extractedNestedLessons)) {
                $lessonsList = array_merge($lessonsList, $extractedNestedLessons);
            }

            if (!empty($lessonsList) && is_array($lessonsList)) {
                $counts['detected_types'][] = '📚 Уроки (' . count($lessonsList) . ')';
                
                $stmtInsertLesson = $db->prepare("INSERT INTO " . tbl('lessons') . " (skill_id, title, xp_reward, order_num, lesson_data) VALUES (:sid, :t, :xp, :ord, :d)");

                foreach ($lessonsList as $lsn) {
                    if (!empty($lsn['title'])) {
                        $lsnTitle = trim($lsn['title']);
                        $lsnLang = strtolower(trim($lsn['language_code'] ?? 'vladikish'));
                        $lsnSkillTitle = trim($lsn['skill_title'] ?? '');
                        $lsnRawSkillId = isset($lsn['skill_id']) ? (int)$lsn['skill_id'] : 0;
                        $xpReward = (int)($lsn['xp_reward'] ?? 20);
                        $orderNum = (int)($lsn['order_num'] ?? 1);

                        // Normalize exercise data
                        $exs = $lsn['exercises'] ?? ($lsn['lesson_data'] ?? ($lsn['tasks'] ?? ($lsn['questions'] ?? [])));
                        if (is_string($exs)) {
                            $decoded = json_decode($exs, true);
                            $exs = is_array($decoded) ? $decoded : [];
                        }

                        // Determine target DB skill_id
                        $targetSkillId = null;

                        // 1. Check mapped old skill ID from skills section
                        if ($lsnRawSkillId > 0 && isset($skillIdMap[$lsnRawSkillId])) {
                            $targetSkillId = $skillIdMap[$lsnRawSkillId];
                        }

                        // 2. Check by skill title
                        if (!$targetSkillId && !empty($lsnSkillTitle)) {
                            $stKey = $lsnLang . '::' . mb_strtolower($lsnSkillTitle, 'UTF-8');
                            if (isset($skillTitleMap[$stKey])) {
                                $targetSkillId = $skillTitleMap[$stKey];
                            } elseif (isset($skillTitleMap['any::' . mb_strtolower($lsnSkillTitle, 'UTF-8')])) {
                                $targetSkillId = $skillTitleMap['any::' . mb_strtolower($lsnSkillTitle, 'UTF-8')];
                            } else {
                                $findSk = $db->prepare("SELECT id FROM " . tbl('skills') . " WHERE title = :t LIMIT 1");
                                $findSk->execute(['t' => $lsnSkillTitle]);
                                $fId = (int)$findSk->fetchColumn();
                                if ($fId > 0) {
                                    $targetSkillId = $fId;
                                    $skillTitleMap[$stKey] = $fId;
                                }
                            }
                        }

                        // 3. Check if raw skill ID actually exists directly in DB
                        if (!$targetSkillId && $lsnRawSkillId > 0) {
                            $checkSk = $db->prepare("SELECT id FROM " . tbl('skills') . " WHERE id = :id LIMIT 1");
                            $checkSk->execute(['id' => $lsnRawSkillId]);
                            $cId = (int)$checkSk->fetchColumn();
                            if ($cId > 0) {
                                $targetSkillId = $cId;
                            }
                        }

                        // 4. Auto-create skill if needed
                        if (!$targetSkillId) {
                            if (!empty($lsnSkillTitle)) {
                                $newSkStmt = $db->prepare("INSERT INTO " . tbl('skills') . " (language_code, title, icon, level, order_num, description) VALUES (:l, :t, '🐾', 1, 1, 'Импортированный раздел')");
                                $newSkStmt->execute(['l' => $lsnLang, 't' => $lsnSkillTitle]);
                                $targetSkillId = (int)$db->lastInsertId();
                                $skillTitleMap[$lsnLang . '::' . mb_strtolower($lsnSkillTitle, 'UTF-8')] = $targetSkillId;
                                $counts['skills']++;
                            } else {
                                $getDef = $db->prepare("SELECT id FROM " . tbl('skills') . " WHERE language_code = :l ORDER BY order_num ASC LIMIT 1");
                                $getDef->execute(['l' => $lsnLang]);
                                $targetSkillId = (int)$getDef->fetchColumn();
                                if ($targetSkillId <= 0) {
                                    $db->exec("INSERT INTO " . tbl('skills') . " (language_code, title, icon, level, order_num, description) VALUES ('{$lsnLang}', 'Основной раздел', '🐾', 1, 1, 'Вводные уроки')");
                                    $targetSkillId = (int)$db->lastInsertId();
                                    $counts['skills']++;
                                }
                            }
                        }

                        // Insert the lesson linked to validated targetSkillId
                        try {
                            $stmtInsertLesson->execute([
                                'sid' => $targetSkillId,
                                't' => $lsnTitle,
                                'xp' => $xpReward,
                                'ord' => $orderNum,
                                'd' => json_encode($exs, JSON_UNESCAPED_UNICODE)
                            ]);
                            $counts['lessons']++;
                        } catch (Exception $e) {}
                    }
                }
            }

            // 4. Dictionary Words Array
            $dictList = $data['conlang_dictionary'] ?? ($data['dictionary'] ?? ($data['words'] ?? []));
            if (empty($dictList) && isset($data[0]['word'], $data[0]['translation_ru'])) {
                $dictList = $data;
            }
            if (!empty($dictList) && is_array($dictList)) {
                $counts['detected_types'][] = '📖 Словарь (' . count($dictList) . ')';
                $stmtWord = $db->prepare("INSERT INTO " . tbl('conlang_dictionary') . " (language_code, word, part_of_speech, translation_ru, translation_en, pronunciation, example_sentence, created_by) 
                                         VALUES (:l, :w, :pos, :ru, :en, :pr, :ex, 'import')");
                foreach ($dictList as $w) {
                    if (!empty($w['word']) && !empty($w['translation_ru'])) {
                        try {
                            $stmtWord->execute([
                                'l' => trim($w['language_code'] ?? 'vladikish'),
                                'w' => trim($w['word']),
                                'pos' => trim($w['part_of_speech'] ?? 'noun'),
                                'ru' => trim($w['translation_ru']),
                                'en' => trim($w['translation_en'] ?? $w['translation_ru']),
                                'pr' => trim($w['pronunciation'] ?? $w['word']),
                                'ex' => trim($w['example_sentence'] ?? '')
                            ]);
                            $counts['dictionary']++;
                        } catch (Exception $e) {}
                    }
                }
            }

            // 5. Grammar Rules Array
            $grammarList = $data['conlang_grammar'] ?? ($data['grammar'] ?? ($data['grammar_rules'] ?? []));
            if (empty($grammarList) && isset($data[0]['rule_title'], $data[0]['rule_description'])) {
                $grammarList = $data;
            }
            if (!empty($grammarList) && is_array($grammarList)) {
                $counts['detected_types'][] = '📐 Грамматика (' . count($grammarList) . ')';
                $stmtGr = $db->prepare("INSERT INTO " . tbl('conlang_grammar') . " (language_code, rule_title, rule_description, rule_examples, order_num) 
                                       VALUES (:l, :t, :d, :ex, :ord)");
                foreach ($grammarList as $g) {
                    if (!empty($g['rule_title'])) {
                        $ruleDesc = $g['rule_description'] ?? ($g['explanation'] ?? '');
                        $ruleEx = $g['rule_examples'] ?? ($g['examples'] ?? ($g['examples_json'] ?? ''));
                        if (is_array($ruleEx)) {
                            $ruleEx = json_encode($ruleEx, JSON_UNESCAPED_UNICODE);
                        }
                        try {
                            $stmtGr->execute([
                                'l' => trim($g['language_code'] ?? 'vladikish'),
                                't' => trim($g['rule_title']),
                                'd' => trim($ruleDesc),
                                'ex' => trim($ruleEx),
                                'ord' => (int)($g['order_num'] ?? 1)
                            ]);
                            $counts['grammar']++;
                        } catch (Exception $e) {}
                    }
                }
            }

            // 6. Interactive Stories Array
            $storiesList = $data['stories'] ?? [];
            if (empty($storiesList) && isset($data[0]['title'], $data[0]['story_dialogue'])) {
                $storiesList = $data;
            }
            if (!empty($storiesList) && is_array($storiesList)) {
                $counts['detected_types'][] = '📖 Истории (' . count($storiesList) . ')';
                $stmtSt = $db->prepare("INSERT INTO " . tbl('stories') . " (title, language_code, level, cover_image, xp_reward, gems_reward, story_data) 
                                       VALUES (:t, :l, :lvl, :cov, :xp, :gems, :d)");
                foreach ($storiesList as $st) {
                    if (!empty($st['title'])) {
                        $stDialog = $st['story_dialogue'] ?? ($st['story_data'] ?? []);
                        try {
                            $stmtSt->execute([
                                't' => trim($st['title']),
                                'l' => trim($st['language_code'] ?? 'vladikish'),
                                'lvl' => (int)($st['level'] ?? 1),
                                'cov' => trim($st['cover_image'] ?? ''),
                                'xp' => (int)($st['xp_reward'] ?? 30),
                                'gems' => (int)($st['gems_reward'] ?? 15),
                                'd' => is_string($stDialog) ? $stDialog : json_encode($stDialog, JSON_UNESCAPED_UNICODE)
                            ]);
                            $counts['stories']++;
                        } catch (Exception $e) {}
                    }
                }
            }

            // 7. Daily Quests Array
            $questsList = $data['daily_quests'] ?? ($data['quests'] ?? []);
            if (!empty($questsList) && is_array($questsList)) {
                $counts['detected_types'][] = '🎯 Квесты (' . count($questsList) . ')';
                $stmtQ = $db->prepare("INSERT INTO " . tbl('daily_quests') . " (title, description, req_type, req_target, xp_reward, gems_reward) 
                                      VALUES (:t, :d, :rt, :targ, :xp, :gems)");
                foreach ($questsList as $q) {
                    if (!empty($q['title'])) {
                        try {
                            $stmtQ->execute([
                                't' => trim($q['title']),
                                'd' => trim($q['description'] ?? ''),
                                'rt' => trim($q['req_type'] ?? 'complete_lessons'),
                                'targ' => (int)($q['req_target'] ?? 1),
                                'xp' => (int)($q['xp_reward'] ?? 25),
                                'gems' => (int)($q['gems_reward'] ?? 10)
                            ]);
                            $counts['quests']++;
                        } catch (Exception $e) {}
                    }
                }
            }

            $importResults = $counts;
            $message = "Импорт успешно завершен! Автоматически распознаны и загружены сущности: " . implode(', ', $counts['detected_types']);
        }
    }
}

// Load header HTML now that all download headers are completed
$adminTitle = 'JSON Экспорт, Импорт и AI-Синхронизация';
require_once __DIR__ . '/header.php';

// Prepare quick stats for UI
$stats = [
    'languages' => $db->query("SELECT COUNT(*) FROM " . tbl('languages'))->fetchColumn(),
    'skills' => $db->query("SELECT COUNT(*) FROM " . tbl('skills'))->fetchColumn(),
    'lessons' => $db->query("SELECT COUNT(*) FROM " . tbl('lessons'))->fetchColumn(),
    'words' => $db->query("SELECT COUNT(*) FROM " . tbl('conlang_dictionary'))->fetchColumn(),
    'grammar' => $db->query("SELECT COUNT(*) FROM " . tbl('conlang_grammar'))->fetchColumn(),
    'stories' => $db->query("SELECT COUNT(*) FROM " . tbl('stories'))->fetchColumn()
];
?>

<div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 style="font-size: 1.8rem; font-weight: 900;">📦 JSON Data Hub: Экспорт, Импорт и Синхронизация с AI</h1>
        <p style="color: var(--text-muted);">
            Генерация всеобъемлющего JSON-файла со встроенной инструкцией для нейросетей и универсальный авто-импорт
        </p>
    </div>

    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
        <a href="?action=export_full_json" class="btn-duo btn-primary" style="text-decoration: none; font-size: 0.95rem;">
            💾 Скачать полный JSON файл (.json)
        </a>
    </div>
</div>

<?php if (!empty($message)): ?>
    <div class="alert alert-success" style="background: var(--primary-light); color: var(--primary-shadow); padding: 16px; border-radius: 14px; font-weight: 700; margin-bottom: 20px; border-left: 6px solid var(--primary);">
        <div style="font-size: 1.1rem; margin-bottom: 6px;">✓ <?= e($message) ?></div>
        <?php if ($importResults): ?>
            <div style="display: flex; gap: 12px; flex-wrap: wrap; margin-top: 8px; font-size: 0.88rem;">
                <?php if ($importResults['skills'] > 0): ?><span class="badge-tag">🗺️ +<?= $importResults['skills'] ?> глав</span><?php endif; ?>
                <?php if ($importResults['lessons'] > 0): ?><span class="badge-tag">📚 +<?= $importResults['lessons'] ?> уроков</span><?php endif; ?>
                <?php if ($importResults['dictionary'] > 0): ?><span class="badge-tag">📖 +<?= $importResults['dictionary'] ?> слов</span><?php endif; ?>
                <?php if ($importResults['grammar'] > 0): ?><span class="badge-tag">📐 +<?= $importResults['grammar'] ?> правил</span><?php endif; ?>
                <?php if ($importResults['stories'] > 0): ?><span class="badge-tag">📖 +<?= $importResults['stories'] ?> историй</span><?php endif; ?>
                <?php if ($importResults['languages'] > 0): ?><span class="badge-tag">🌐 +<?= $importResults['languages'] ?> языков</span><?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger" style="background: var(--danger-light); color: var(--danger-shadow); padding: 16px; border-radius: 14px; font-weight: 700; margin-bottom: 20px; border-left: 6px solid var(--danger);">
        ✕ <?= e($error) ?>
    </div>
<?php endif; ?>

<!-- Current Database Snapshot Badges -->
<div class="card-duo" style="margin-bottom: 24px; padding: 16px 20px; background: linear-gradient(135deg, rgba(88,204,2,0.05), rgba(28,176,246,0.05));">
    <div style="font-weight: 800; font-size: 0.95rem; margin-bottom: 10px; color: var(--text-color);">
        📊 Текущее состояние базы данных (попадает в полный JSON-дамп):
    </div>
    <div style="display: flex; gap: 14px; flex-wrap: wrap;">
        <span class="badge-tag" style="background: var(--bg-card); font-size: 0.9rem;">🌐 Языков: <strong><?= $stats['languages'] ?></strong></span>
        <span class="badge-tag" style="background: var(--bg-card); font-size: 0.9rem;">🗺️ Глав/Разделов: <strong><?= $stats['skills'] ?></strong></span>
        <span class="badge-tag" style="background: var(--bg-card); font-size: 0.9rem;">📚 Уроков: <strong><?= $stats['lessons'] ?></strong></span>
        <span class="badge-tag" style="background: var(--bg-card); font-size: 0.9rem;">📖 Слов Vladikish: <strong><?= $stats['words'] ?></strong></span>
        <span class="badge-tag" style="background: var(--bg-card); font-size: 0.9rem;">📐 Правил грамматики: <strong><?= $stats['grammar'] ?></strong></span>
        <span class="badge-tag" style="background: var(--bg-card); font-size: 0.9rem;">📖 Историй: <strong><?= $stats['stories'] ?></strong></span>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 30px;">
    <!-- EXPORT CARD -->
    <div class="card-duo" style="border-top: 4px solid var(--primary); display: flex; flex-direction: column; justify-content: space-between;">
        <div>
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 12px;">
                <span style="font-size: 1.6rem;">📤</span>
                <h3 style="font-size: 1.3rem; font-weight: 900; margin: 0;">Экспорт и AI-Дамп</h3>
            </div>
            
            <p style="font-size: 0.9rem; color: var(--text-muted); line-height: 1.5; margin-bottom: 18px;">
                Сгенерируйте единый файл, содержащий <strong>абсолютно все знания, главы, уроки с 10 типами упражнений, словарь и грамматику</strong>, снабженный встроенной инструкцией для внешних ИИ.
            </p>

            <div style="background: var(--bg-main); padding: 14px; border-radius: 12px; border: 1.5px solid var(--border-color); margin-bottom: 18px;">
                <div style="font-weight: 800; font-size: 0.85rem; margin-bottom: 6px; color: var(--primary);">
                    🤖 Встроенная инструкция для нейросетей (_ai_instructions):
                </div>
                <div style="font-size: 0.8rem; color: var(--text-muted); line-height: 1.4;">
                    Любая нейросеть (ChatGPT, Claude, Gemini, DeepSeek), получив этот JSON, сразу поймет все правила конланга Vladikish, форматы заданий (multiple_choice, fill_blank, word_bank, speak, judge, find_error, dialogue_fill) и сможет сгенерировать новые главы или уроки в 100% совместимом виде.
                </div>
            </div>
        </div>

        <div>
            <a href="?action=export_full_json" class="btn-duo btn-primary" style="width: 100%; text-align: center; text-decoration: none; margin-bottom: 12px; font-size: 1.05rem; padding: 14px;">
                📥 Скачать файл базы в формате JSON (.json)
            </a>

            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                <a href="?action=export_dict" class="btn-duo btn-outline" style="font-size: 0.8rem; text-decoration: none; padding: 6px 10px;">
                    📖 Словарь (.json)
                </a>
                <a href="?action=export_lessons" class="btn-duo btn-outline" style="font-size: 0.8rem; text-decoration: none; padding: 6px 10px;">
                    📚 Уроки (.json)
                </a>
                <a href="?action=export_grammar" class="btn-duo btn-outline" style="font-size: 0.8rem; text-decoration: none; padding: 6px 10px;">
                    📐 Грамматика (.json)
                </a>
                <a href="?action=export_stories" class="btn-duo btn-outline" style="font-size: 0.8rem; text-decoration: none; padding: 6px 10px;">
                    📖 Истории (.json)
                </a>
                <button type="button" class="btn-duo btn-secondary" style="font-size: 0.8rem; padding: 6px 10px; margin-left: auto;" onclick="openJsonPreviewModal()">
                    👁️ Просмотр и Скачивание
                </button>
            </div>
        </div>
    </div>

    <!-- SMART IMPORT CARD -->
    <div class="card-duo" style="border-top: 4px solid var(--secondary);">
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 12px;">
            <span style="font-size: 1.6rem;">📥</span>
            <h3 style="font-size: 1.3rem; font-weight: 900; margin: 0;">Универсальный Smart Импорт</h3>
        </div>

        <p style="font-size: 0.9rem; color: var(--text-muted); line-height: 1.5; margin-bottom: 16px;">
            Загрузите файл или вставьте сгенерированный ИИ JSON. Система <strong>автоматически определит</strong> тип содержимого (полный дамп, один урок, пачка слов, грамматика или истории).
        </p>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="import_action" value="execute_smart_import">

            <div style="margin-bottom: 14px;">
                <label style="font-weight: 800; font-size: 0.85rem; display: block; margin-bottom: 6px;">
                    1. Выберите .json файл с компьютера:
                </label>
                <input type="file" name="import_file" accept=".json,application/json" class="chat-input" style="padding: 10px;">
            </div>

            <div style="margin-bottom: 16px;">
                <label style="font-weight: 800; font-size: 0.85rem; display: block; margin-bottom: 6px;">
                    2. Или вставьте JSON-код прямо сюда (из буфера):
                </label>
                <textarea name="json_text" rows="5" class="chat-input" placeholder='{"lessons": [...]} или [{"word": "Kaelo", "translation_ru": "Звезда"}]' style="font-family: monospace; font-size: 0.85rem;"></textarea>
            </div>

            <button type="submit" class="btn-duo btn-secondary" style="width: 100%; font-size: 1.05rem; padding: 14px;" onclick="return confirm('Импортировать указанные данные в базу ShibaLingo?');">
                ⚡ Распознать и импортировать в базу
            </button>
        </form>
    </div>
</div>

<!-- Modal: JSON Live Inspector -->
<div id="modal-json-preview" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 9999; align-items: center; justify-content: center; padding: 20px;">
    <div class="card-duo anim-bounce" style="max-width: 900px; width: 100%; max-height: 90vh; display: flex; flex-direction: column; margin-bottom: 0;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 2px solid var(--border-color); padding-bottom: 10px;">
            <h3 style="font-size: 1.3rem; font-weight: 800; display: flex; align-items: center; gap: 8px;">
                <span>👁️</span> <span>Предпросмотр полного AI JSON-дампа</span>
            </h3>
            <div style="display: flex; gap: 8px;">
                <button class="btn-duo btn-primary" style="padding: 6px 14px; font-size: 0.9rem;" onclick="downloadJsonFileFromPreview()">
                    💾 Скачать файл (.json)
                </button>
                <button class="btn-duo btn-outline" style="padding: 6px 12px; font-size: 0.85rem;" onclick="copyJsonToClipboard()">
                    📋 Скопировать
                </button>
                <button onclick="closeJsonPreviewModal()" style="background: none; border: none; font-size: 1.4rem; cursor: pointer;">✕</button>
            </div>
        </div>

        <div id="json-loading-spinner" style="text-align: center; padding: 40px; font-weight: 800; color: var(--text-muted);">
            ⏳ Формирование полного JSON дампа со всеми главами, уроками и грамматикой...
        </div>

        <textarea id="json-preview-area" readonly style="display: none; width: 100%; flex: 1; min-height: 400px; font-family: 'JetBrains Mono', 'Fira Code', monospace; font-size: 0.82rem; background: var(--bg-main); color: var(--text-main); border: 2px solid var(--border-color); border-radius: 12px; padding: 14px; resize: none;"></textarea>
    </div>
</div>

<script>
function openJsonPreviewModal() {
    const modal = document.getElementById('modal-json-preview');
    const spinner = document.getElementById('json-loading-spinner');
    const area = document.getElementById('json-preview-area');

    modal.style.display = 'flex';
    spinner.style.display = 'block';
    area.style.display = 'none';

    fetch('?action=export_full_json')
        .then(res => res.json())
        .then(data => {
            spinner.style.display = 'none';
            area.style.display = 'block';
            area.value = JSON.stringify(data, null, 2);
        })
        .catch(err => {
            spinner.textContent = 'Ошибка загрузки JSON: ' + err;
        });
}

function closeJsonPreviewModal() {
    document.getElementById('modal-json-preview').style.display = 'none';
}

function downloadJsonFileFromPreview() {
    const area = document.getElementById('json-preview-area');
    const jsonStr = area.value;
    if (!jsonStr) {
        window.location.href = '?action=export_full_json';
        return;
    }
    const blob = new Blob([jsonStr], { type: 'application/json;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'shibalingo_full_database_dump_' + new Date().toISOString().slice(0,10) + '.json';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

function copyJsonToClipboard() {
    const area = document.getElementById('json-preview-area');
    area.select();
    navigator.clipboard.writeText(area.value).then(() => {
        alert('✓ Полный JSON-дамп скопирован в буфер обмена!');
    });
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
