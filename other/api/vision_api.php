<?php
/**
 * ShibaLingo - AI Vision & AR Object Scanner API
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$user = getCurrentUser();
$db = getDb();
$driver = Database::getDriver();

// Ensure user_ar_collection table exists
try {
    if ($driver === 'sqlite') {
        $db->exec("CREATE TABLE IF NOT EXISTS user_ar_collection (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            word TEXT NOT NULL,
            label_ru TEXT NOT NULL,
            detected_label TEXT NOT NULL,
            xp_reward INTEGER DEFAULT 15,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(user_id, word)
        )");
    } else {
        $db->exec("CREATE TABLE IF NOT EXISTS `user_ar_collection` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `word` VARCHAR(100) NOT NULL,
            `label_ru` VARCHAR(100) NOT NULL,
            `detected_label` VARCHAR(100) NOT NULL,
            `xp_reward` INT DEFAULT 15,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `unique_user_word` (`user_id`, `word`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
} catch (Exception $e) {}

$action = $_GET['action'] ?? ($_POST['action'] ?? 'recognize');

// Comprehensive object mappings to Vladikish
$objectMap = [
    // Animals
    'dog' => ['word' => 'Barka', 'ru' => 'Собака', 'en' => 'Dog', 'pron' => 'Ба́рка', 'ex' => 'Shiba barka bonu est.'],
    'retriever' => ['word' => 'Barka', 'ru' => 'Собака', 'en' => 'Dog', 'pron' => 'Ба́рка', 'ex' => 'Shiba barka bonu est.'],
    'terrier' => ['word' => 'Barka', 'ru' => 'Собака', 'en' => 'Dog', 'pron' => 'Ба́рка', 'ex' => 'Shiba barka bonu est.'],
    'hound' => ['word' => 'Barka', 'ru' => 'Собака', 'en' => 'Dog', 'pron' => 'Ба́рка', 'ex' => 'Shiba barka bonu est.'],
    'puppy' => ['word' => 'Barka', 'ru' => 'Щенок / Собака', 'en' => 'Puppy', 'pron' => 'Ба́рка', 'ex' => 'Barka ludi in domo.'],
    'cat' => ['word' => 'Kato', 'ru' => 'Кот / Кошка', 'en' => 'Cat', 'pron' => 'Ка́то', 'ex' => 'Kato dormi su tablo.'],
    'kitten' => ['word' => 'Kato', 'ru' => 'Котенок / Кот', 'en' => 'Kitten', 'pron' => 'Ка́то', 'ex' => 'Kato dormi su tablo.'],
    'tabby' => ['word' => 'Kato', 'ru' => 'Кот', 'en' => 'Cat', 'pron' => 'Ка́то', 'ex' => 'Kato dormi su tablo.'],
    'bird' => ['word' => 'Avis', 'ru' => 'Птица', 'en' => 'Bird', 'pron' => 'А́вис', 'ex' => 'Avis flugi in aero.'],
    'horse' => ['word' => 'Kavalo', 'ru' => 'Лошадь', 'en' => 'Horse', 'pron' => 'Кава́ло', 'ex' => 'Kavalo kure rapide.'],
    'sheep' => ['word' => 'Oveho', 'ru' => 'Овца', 'en' => 'Sheep', 'pron' => 'Овэ́хо', 'ex' => 'Oveho in agro est.'],
    'cow' => ['word' => 'Vaka', 'ru' => 'Корова', 'en' => 'Cow', 'pron' => 'Ва́ка', 'ex' => 'Vaka dona lakto.'],
    'bear' => ['word' => 'Urso', 'ru' => 'Медведь', 'en' => 'Bear', 'pron' => 'У́рсо', 'ex' => 'Urso vivas in silva.'],

    // Tech & Electronics
    'cell phone' => ['word' => 'Telephono', 'ru' => 'Телефон', 'en' => 'Phone', 'pron' => 'Тэлефо́но', 'ex' => 'Me parolas per telephono.'],
    'cellular' => ['word' => 'Telephono', 'ru' => 'Телефон', 'en' => 'Phone', 'pron' => 'Тэлефо́но', 'ex' => 'Me parolas per telephono.'],
    'phone' => ['word' => 'Telephono', 'ru' => 'Телефон', 'en' => 'Phone', 'pron' => 'Тэлефо́но', 'ex' => 'Me parolas per telephono.'],
    'ipod' => ['word' => 'Telephono', 'ru' => 'Гаджет / Плеер', 'en' => 'Device', 'pron' => 'Тэлефо́но', 'ex' => 'Musika in telephono.'],
    'laptop' => ['word' => 'Computoro', 'ru' => 'Ноутбук / Компьютер', 'en' => 'Laptop', 'pron' => 'Компуто́ро', 'ex' => 'Me laboras kun computoro.'],
    'notebook' => ['word' => 'Computoro', 'ru' => 'Ноутбук', 'en' => 'Notebook', 'pron' => 'Компуто́ро', 'ex' => 'Computoro es rapide.'],
    'computer' => ['word' => 'Computoro', 'ru' => 'Компьютер', 'en' => 'Computer', 'pron' => 'Компуто́ро', 'ex' => 'Computoro es rapide.'],
    'monitor' => ['word' => 'Computoro', 'ru' => 'Монитор / Экран', 'en' => 'Monitor', 'pron' => 'Компуто́ро', 'ex' => 'Vidi ekranon de computoro.'],
    'screen' => ['word' => 'Computoro', 'ru' => 'Экран', 'en' => 'Screen', 'pron' => 'Компуто́ро', 'ex' => 'Ekrano de computoro.'],
    'keyboard' => ['word' => 'Klavaro', 'ru' => 'Клавиатура', 'en' => 'Keyboard', 'pron' => 'Клава́ро', 'ex' => 'Me skribas sur klavaro.'],
    'mouse' => ['word' => 'Muso', 'ru' => 'Мышь', 'en' => 'Mouse', 'pron' => 'Му́со', 'ex' => 'Komputora muso bonu funkcias.'],
    'book' => ['word' => 'Libro', 'ru' => 'Книга', 'en' => 'Book', 'pron' => 'Ли́бро', 'ex' => 'Me legas bonu libro.'],
    'binder' => ['word' => 'Libro', 'ru' => 'Папка / Книга', 'en' => 'Book', 'pron' => 'Ли́бро', 'ex' => 'Libro su tablo.'],
    'clock' => ['word' => 'Horlojo', 'ru' => 'Часы', 'en' => 'Clock', 'pron' => 'Хорло́жо', 'ex' => 'Horlojo montras tempo.'],
    'watch' => ['word' => 'Horlojo', 'ru' => 'Наручные часы', 'en' => 'Watch', 'pron' => 'Хорло́жо', 'ex' => 'Bela horlojo montras tempo.'],
    'glasses' => ['word' => 'Okulvitroj', 'ru' => 'Очки', 'en' => 'Glasses', 'pron' => 'Окулви́трой', 'ex' => 'Okulvitroj por legi.'],
    'sunglass' => ['word' => 'Okulvitroj', 'ru' => 'Солнечные очки', 'en' => 'Sunglasses', 'pron' => 'Окулви́трой', 'ex' => 'Okulvitroj kontraŭ zora.'],

    // Dishes, Food & Drinks
    'cup' => ['word' => 'Taso', 'ru' => 'Чашка / Кружка', 'en' => 'Cup', 'pron' => 'Та́со', 'ex' => 'Taso de varma teo.'],
    'mug' => ['word' => 'Taso', 'ru' => 'Кружка', 'en' => 'Mug', 'pron' => 'Та́со', 'ex' => 'Taso de cofi.'],
    'teacup' => ['word' => 'Taso', 'ru' => 'Чайная чашка', 'en' => 'Teacup', 'pron' => 'Та́со', 'ex' => 'Taso de teo.'],
    'coffee' => ['word' => 'Cofi', 'ru' => 'Кофе', 'en' => 'Coffee', 'pron' => 'Ко́фи', 'ex' => 'Bonu cofi matene.'],
    'espresso' => ['word' => 'Cofi', 'ru' => 'Кофе / Эспрессо', 'en' => 'Espresso', 'pron' => 'Ко́фи', 'ex' => 'Forta cofi.'],
    'bottle' => ['word' => 'Botelo', 'ru' => 'Бутылка', 'en' => 'Bottle', 'pron' => 'Ботэ́ло', 'ex' => 'Botelo de pura aqua.'],
    'water' => ['word' => 'Aqua', 'ru' => 'Вода', 'en' => 'Water', 'pron' => 'А́ква', 'ex' => 'Trinki pura aqua.'],
    'apple' => ['word' => 'Pomo', 'ru' => 'Яблоко', 'en' => 'Apple', 'pron' => 'По́мо', 'ex' => 'Ruga pomo es dolsa.'],
    'banana' => ['word' => 'Banano', 'ru' => 'Банан', 'en' => 'Banana', 'pron' => 'Бана́но', 'ex' => 'Flava banano.'],
    'orange' => ['word' => 'Oranĝo', 'ru' => 'Апельсин', 'en' => 'Orange', 'pron' => 'Ора́нджо', 'ex' => 'Dolsa oranĝo.'],
    'bread' => ['word' => 'Pano', 'ru' => 'Хлеб', 'en' => 'Bread', 'pron' => 'Па́но', 'ex' => 'Frishe pano bonu est.'],
    'pizza' => ['word' => 'Pico', 'ru' => 'Пицца', 'en' => 'Pizza', 'pron' => 'Пи́цо', 'ex' => 'Nos komas pico kune.'],

    // Furniture & Home
    'chair' => ['word' => 'Seĝo', 'ru' => 'Стул / Кресло', 'en' => 'Chair', 'pron' => 'Сэ́джо', 'ex' => 'Sidi su seĝo.'],
    'armchair' => ['word' => 'Seĝo', 'ru' => 'Кресло', 'en' => 'Armchair', 'pron' => 'Сэ́джо', 'ex' => 'Komforta seĝo.'],
    'couch' => ['word' => 'Divano', 'ru' => 'Диван', 'en' => 'Couch', 'pron' => 'Дива́но', 'ex' => 'Dormi su divano.'],
    'sofa' => ['word' => 'Divano', 'ru' => 'Диван', 'en' => 'Sofa', 'pron' => 'Дива́но', 'ex' => 'Dormi su divano.'],
    'bed' => ['word' => 'Lito', 'ru' => 'Кровать', 'en' => 'Bed', 'pron' => 'Ли́то', 'ex' => 'Nox en lito.'],
    'table' => ['word' => 'Tablo', 'ru' => 'Стол', 'en' => 'Table', 'pron' => 'Та́бло', 'ex' => 'Pano su tablo.'],
    'desk' => ['word' => 'Tablo', 'ru' => 'Письменный стол', 'en' => 'Desk', 'pron' => 'Та́бло', 'ex' => 'Labori su tablo.'],
    'door' => ['word' => 'Porto', 'ru' => 'Дверь', 'en' => 'Door', 'pron' => 'По́рто', 'ex' => 'Aperi porto de domo.'],
    'window' => ['word' => 'Fenestro', 'ru' => 'Окно', 'en' => 'Window', 'pron' => 'Фэнэ́стро', 'ex' => 'Vidi zora per fenestro.'],

    // Vehicles & Nature
    'car' => ['word' => 'Auto', 'ru' => 'Автомобиль / Машина', 'en' => 'Car', 'pron' => 'А́уто', 'ex' => 'Auto veturas rapide.'],
    'automobile' => ['word' => 'Auto', 'ru' => 'Автомобиль', 'en' => 'Automobile', 'pron' => 'А́уто', 'ex' => 'Auto veturas rapide.'],
    'airplane' => ['word' => 'Aero-veturilo', 'ru' => 'Самолет', 'en' => 'Airplane', 'pron' => 'Аэро-вэтури́ло', 'ex' => 'Aero-veturilo flugas alte.'],
    'aeroplane' => ['word' => 'Aero-veturilo', 'ru' => 'Самолет', 'en' => 'Airplane', 'pron' => 'Аэро-вэтури́ло', 'ex' => 'Aero-veturilo flugas alte.'],
    'aircraft' => ['word' => 'Aero-veturilo', 'ru' => 'Самолет', 'en' => 'Aircraft', 'pron' => 'Аэро-вэтури́ло', 'ex' => 'Aero-veturilo flugas alte.'],
    'airliner' => ['word' => 'Aero-veturilo', 'ru' => 'Пассажирский самолет', 'en' => 'Airliner', 'pron' => 'Аэро-вэтури́ло', 'ex' => 'Aero-veturilo flugas alte.'],
    'bicycle' => ['word' => 'Biciklo', 'ru' => 'Велосипед', 'en' => 'Bicycle', 'pron' => 'Бици́кло', 'ex' => 'Vladi veturas per biciklo.'],
    'bike' => ['word' => 'Biciklo', 'ru' => 'Велосипед', 'en' => 'Bike', 'pron' => 'Бици́кло', 'ex' => 'Vladi veturas per biciklo.'],
    'plant' => ['word' => 'Planto', 'ru' => 'Растение / Цветок', 'en' => 'Plant', 'pron' => 'Пла́нто', 'ex' => 'Bela planto kreskas.'],
    'flower' => ['word' => 'Planto', 'ru' => 'Цветок', 'en' => 'Flower', 'pron' => 'Пла́нто', 'ex' => 'Bela planto odorigas.'],
    'vase' => ['word' => 'Planto', 'ru' => 'Ваза с цветами', 'en' => 'Vase', 'pron' => 'Пла́нто', 'ex' => 'Planto in vazo.'],
    'person' => ['word' => 'Persono', 'ru' => 'Человек', 'en' => 'Person', 'pron' => 'Пэрсо́но', 'ex' => 'Bonu persono amiko est.'],
    'sun' => ['word' => 'Zora', 'ru' => 'Солнце / Свет', 'en' => 'Sun', 'pron' => 'Зо́ра', 'ex' => 'Zora brilas in kaelo.'],
    'sky' => ['word' => 'Aero', 'ru' => 'Небо / Воздух', 'en' => 'Sky', 'pron' => 'А́эро', 'ex' => 'Kaelo es blua hodie.']
];

// 1. Recognize Detected Label or Real Image Snapshot
if ($action === 'recognize') {
    $label = strtolower(trim($_POST['label'] ?? ($_GET['label'] ?? '')));
    $imageData = $_POST['image'] ?? '';

    // If real base64 image was sent, call Vision AI (NVIDIA Key or free Pollinations AI Vision)
    if (!empty($imageData) && empty($label)) {
        $apiKey = getSetting('nvidia_api_key', '');
        
        // 1. If custom AI API key configured in Admin
        if (!empty($apiKey)) {
            try {
                $messages = [
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => 'Identify the primary single physical object in this image. Respond ONLY with a JSON object in this exact format: {"object": "english_name", "russian": "русское_название"}. Keep object simple (e.g. dog, cat, cup, phone, laptop, book, chair, bottle, apple, car, etc.).'],
                            ['type' => 'image_url', 'image_url' => ['url' => $imageData]]
                        ]
                    ]
                ];
                $aiRes = callNvidiaApi($messages, getSetting('nvidia_model', DEFAULT_NVIDIA_MODEL), true);
                if (!empty($aiRes['content'])) {
                    $parsed = json_decode($aiRes['content'], true);
                    if (!empty($parsed['object'])) {
                        $label = strtolower(trim($parsed['object']));
                        if (!empty($parsed['russian'])) $customRu = $parsed['russian'];
                    }
                }
            } catch (Exception $e) {}
        }

        // 2. Free Pollinations AI Vision (No API key needed)
        if (empty($label)) {
            try {
                $pollPayload = [
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => [
                                ['type' => 'text', 'text' => 'Identify the main physical object in this image. Respond ONLY with a JSON object: {"object": "english_name", "russian": "русское_название"}. Single object only (e.g. cup, dog, cat, phone, laptop, book, chair, bottle, apple, car, watch, plant, bread, etc.).'],
                                ['type' => 'image_url', 'image_url' => ['url' => $imageData]]
                            ]
                        ]
                    ],
                    'model' => 'openai'
                ];

                $ch = curl_init('https://text.pollinations.ai/');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($pollPayload));
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_TIMEOUT, 8);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                $pollRes = curl_exec($ch);
                curl_close($ch);

                if ($pollRes) {
                    if (preg_match('/\{[\s\S]*\}/', $pollRes, $m)) {
                        $parsed = json_decode($m[0], true);
                        if (!empty($parsed['object'])) {
                            $label = strtolower(trim($parsed['object']));
                            if (!empty($parsed['russian'])) $customRu = $parsed['russian'];
                        }
                    }
                }
            } catch (Exception $e) {}
        }
    }

    if (empty($label)) {
        // Sample fallback if no label specified
        $sampleItems = ['phone', 'laptop', 'cup', 'book', 'bottle', 'dog', 'cat', 'chair', 'apple', 'plant', 'person', 'table'];
        $label = $sampleItems[array_rand($sampleItems)];
    }
    
    // Find closest match in dictionary or database
    $found = null;
    $customRu = trim($_POST['custom_ru'] ?? '');

    if (!empty($label)) {
        foreach ($objectMap as $key => $data) {
            if (strpos($label, $key) !== false || strpos($key, $label) !== false) {
                $found = $data;
                $found['detected_label'] = $key;
                if (!empty($customRu)) $found['ru'] = $customRu;
                break;
            }
        }
    }

    // Try matching in full Vladikish database
    if (!$found && !empty($label)) {
        try {
            $q = '%' . $label . '%';
            $dStmt = $db->prepare("SELECT * FROM conlang_dictionary WHERE LOWER(translation_en) LIKE :q OR LOWER(translation_ru) LIKE :q OR LOWER(word) LIKE :q LIMIT 1");
            $dStmt->execute(['q' => strtolower($label)]);
            $dictRow = $dStmt->fetch(PDO::FETCH_ASSOC);
            if ($dictRow) {
                $found = [
                    'word' => ucfirst($dictRow['word']),
                    'ru' => !empty($customRu) ? $customRu : $dictRow['translation_ru'],
                    'en' => $dictRow['translation_en'] ?: ucfirst($label),
                    'pron' => $dictRow['pronunciation'] ?: ucfirst($dictRow['word']),
                    'ex' => $dictRow['example_sentence'] ?: "Tio es {$dictRow['word']} in mondo.",
                    'detected_label' => $label
                ];
            }
        } catch (Exception $e) {}
    }

    if (!$found) {
        $cleanWord = ucfirst(preg_replace('/[^a-zA-Z]/', '', $label)) ?: 'Kaelo';
        if (!preg_match('/[aeiou]$/i', $cleanWord)) {
            $cleanWord .= 'o';
        }
        $found = [
            'word' => $cleanWord,
            'ru' => !empty($customRu) ? $customRu : (!empty($label) ? ucfirst($label) : 'Предмет'),
            'en' => ucfirst($label),
            'pron' => $cleanWord,
            'ex' => "Tio es bela {$cleanWord} in mondo.",
            'detected_label' => $label ?: 'object'
        ];
    }

    // Record to AR Collection & Award XP
    $isNewDiscovery = false;
    $xpAwarded = 0;

    if ($user && $user['id']) {
        try {
            $check = $db->prepare("SELECT id FROM user_ar_collection WHERE user_id = :uid AND word = :w");
            $check->execute(['uid' => $user['id'], 'w' => $found['word']]);
            if (!$check->fetch()) {
                $isNewDiscovery = true;
                $xpAwarded = 15;
                $ins = $db->prepare("INSERT INTO user_ar_collection (user_id, word, label_ru, detected_label, xp_reward) VALUES (:uid, :w, :ru, :lbl, 15)");
                $ins->execute([
                    'uid' => $user['id'],
                    'w' => $found['word'],
                    'ru' => $found['ru'],
                    'lbl' => $found['detected_label']
                ]);
                $db->prepare("UPDATE " . tbl('users') . " SET xp = xp + 15 WHERE id = :uid")->execute(['uid' => $user['id']]);
            }
        } catch (Exception $e) {}
    }

    echo json_encode([
        'success' => true,
        'item' => $found,
        'is_new' => $isNewDiscovery,
        'xp_awarded' => $xpAwarded
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 2. Get User's AR Collection
if ($action === 'get_collection') {
    $items = [];
    if ($user && $user['id']) {
        try {
            $stmt = $db->prepare("SELECT * FROM user_ar_collection WHERE user_id = :uid ORDER BY id DESC");
            $stmt->execute(['uid' => $user['id']]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}
    }

    echo json_encode([
        'success' => true,
        'count' => count($items),
        'items' => $items
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
