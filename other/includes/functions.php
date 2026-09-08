<?php
/**
 * Helper functions for ShibaLingo
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';

function getDb(): PDO {
    return Database::getConnection();
}

/**
 * Get full prefixed table name
 */
function tbl(string $name): string {
    $prefix = defined('DB_PREFIX') ? DB_PREFIX : '';
    if (Database::getDriver() === 'sqlite') {
        return '"' . $prefix . $name . '"';
    }
    return '`' . $prefix . $name . '`';
}

/**
 * Escape HTML output safely
 */
function e(?string $str): string {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Get setting value from DB
 */
function getSetting(string $key, string $default = ''): string {
    try {
        $db = getDb();
        $stmt = $db->prepare("SELECT setting_value FROM " . tbl('settings') . " WHERE setting_key = :key");
        $stmt->execute(['key' => $key]);
        $val = $stmt->fetchColumn();
        return $val !== false ? $val : $default;
    } catch (Exception $e) {
        return $default;
    }
}

/**
 * Save setting value
 */
function setSetting(string $key, string $value): bool {
    try {
        $db = getDb();
        $table = tbl('settings');
        if (Database::getDriver() === 'sqlite') {
            $stmt = $db->prepare("INSERT INTO {$table} (setting_key, setting_value) VALUES (:key, :val) ON CONFLICT(setting_key) DO UPDATE SET setting_value = :val2");
            return $stmt->execute(['key' => $key, 'val' => $value, 'val2' => $value]);
        } else {
            $stmt = $db->prepare("INSERT INTO {$table} (setting_key, setting_value) VALUES (:key, :val) ON DUPLICATE KEY UPDATE setting_value = :val2");
            return $stmt->execute(['key' => $key, 'val' => $value, 'val2' => $value]);
        }
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Get the current logged in user or null
 */
function getCurrentUser(): ?array {
    $db = getDb();
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) {
        return null;
    }

    $stmt = $db->prepare("SELECT * FROM " . tbl('users') . " WHERE id = :id");
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();

    if (!$user) {
        unset($_SESSION['user_id']);
        return null;
    }

    // Auto-update streak check
    $today = date('Y-m-d');
    if ($user['last_active_date'] !== $today) {
        $lastDate = $user['last_active_date'] ? new DateTime($user['last_active_date']) : null;
        $currDate = new DateTime($today);
        
        if ($lastDate) {
            $diff = $currDate->diff($lastDate)->days;
            if ($diff === 1) {
                $newStreak = (int)$user['streak'] + 1;
            } elseif ($diff > 1) {
                $newStreak = 1;
            } else {
                $newStreak = (int)$user['streak'];
            }
        } else {
            $newStreak = 1;
        }

        $up = $db->prepare("UPDATE " . tbl('users') . " SET streak = :s, hearts = 5, last_active_date = :d WHERE id = :id");
        $up->execute(['s' => $newStreak, 'd' => $today, 'id' => $user['id']]);
        $user['streak'] = $newStreak;
        $user['hearts'] = 5;
        $user['last_active_date'] = $today;
    }

    return $user;
}

/**
 * Require active user session or redirect to login
 */
function requireAuth(): array {
    $user = getCurrentUser();
    if (!$user) {
        header("Location: login.php");
        exit;
    }
    return $user;
}

/**
 * Get currently authenticated admin user
 */
function getCurrentAdmin(): ?array {
    $adminId = $_SESSION['admin_id'] ?? null;
    if (!$adminId) return null;

    $db = getDb();
    $stmt = $db->prepare("SELECT u.*, r.slug as role_slug, r.name as role_name, r.permissions 
                          FROM " . tbl('users') . " u 
                          LEFT JOIN " . tbl('roles') . " r ON u.role_id = r.id 
                          WHERE u.id = :id AND u.status = 'active'");
    $stmt->execute(['id' => $adminId]);
    return $stmt->fetch() ?: null;
}

/**
 * Get all available languages
 */
function getLanguages(): array {
    $db = getDb();
    $stmt = $db->query("SELECT * FROM " . tbl('languages') . " ORDER BY is_conlang DESC, code ASC");
    return $stmt->fetchAll();
}

/**
 * Switch current active language for user
 */
function setCurrentLanguage(string $langCode): bool {
    $user = getCurrentUser();
    $db = getDb();
    $stmt = $db->prepare("UPDATE " . tbl('users') . " SET current_language = :lang WHERE id = :id");
    $_SESSION['current_language'] = $langCode;
    return $stmt->execute(['lang' => $langCode, 'id' => $user['id']]);
}

/**
 * Get skills and lessons tree with progress
 */
function getSkillsWithProgress(string $langCode, int $userId): array {
    $db = getDb();
    
    // Get skills
    $stmt = $db->prepare("SELECT * FROM " . tbl('skills') . " WHERE language_code = :lang ORDER BY level ASC, order_num ASC");
    $stmt->execute(['lang' => $langCode]);
    $skills = $stmt->fetchAll();

    $progressTbl = tbl('user_progress');
    $lessonsTbl = tbl('lessons');

    foreach ($skills as &$skill) {
        $lStmt = $db->prepare("SELECT l.*, (SELECT COUNT(*) FROM {$progressTbl} up WHERE up.lesson_id = l.id AND up.user_id = :uid) as is_completed 
                              FROM {$lessonsTbl} l 
                              WHERE l.skill_id = :sid 
                              ORDER BY l.order_num ASC");
        $lStmt->execute(['sid' => $skill['id'], 'uid' => $userId]);
        $skill['lessons'] = $lStmt->fetchAll();
        
        $totalLessons = count($skill['lessons']);
        $completedLessons = 0;
        foreach ($skill['lessons'] as $les) {
            if ($les['is_completed'] > 0) {
                $completedLessons++;
            }
        }
        $skill['total_lessons'] = $totalLessons;
        $skill['completed_lessons'] = $completedLessons;
        $skill['is_unlocked'] = true; // For now open all or level based
    }

    return $skills;
}

/**
 * Get Vladikish Conlang context for AI (Dictionary + Grammar)
 */
function getVladikishContext(): string {
    $db = getDb();
    $dictStmt = $db->query("SELECT word, part_of_speech, translation_ru, translation_en, example_sentence FROM " . tbl('conlang_dictionary') . " ORDER BY word ASC");
    $words = $dictStmt->fetchAll();

    $gramStmt = $db->query("SELECT rule_title, rule_description, rule_examples FROM " . tbl('conlang_grammar'));
    $rules = $gramStmt->fetchAll();

    $context = "=== CONLANG 'VLADIKISH' OFFICIAL DICTIONARY & GRAMMAR ===\n\n";
    $context .= "DICTIONARY:\n";
    foreach ($words as $w) {
        $context .= "- {$w['word']} ({$w['part_of_speech']}): RU='{$w['translation_ru']}', EN='{$w['translation_en']}'";
        if (!empty($w['example_sentence'])) {
            $context .= " | Example: {$w['example_sentence']}";
        }
        $context .= "\n";
    }

    $context .= "\nGRAMMAR RULES:\n";
    foreach ($rules as $r) {
        $context .= "Rule: {$r['rule_title']}\nDescription: {$r['rule_description']}\nExample: {$r['rule_examples']}\n\n";
    }

    return $context;
}

/**
 * Call OpenAI-compatible / NVIDIA NIM API
 */
function callNvidiaApi(array $messages, ?string $model = null, bool $jsonMode = false): array {
    $apiKey = getSetting('nvidia_api_key', '');
    
    // Check if session has a temporary key
    if (empty($apiKey) && !empty($_SESSION['nvidia_api_key'])) {
        $apiKey = $_SESSION['nvidia_api_key'];
    }

    if (empty($apiKey)) {
        return [
            'success' => false,
            'error' => 'API Key не задан. Пожалуйста, введите ваш API-ключ в настройках ИИ в Панели администратора ⚙️'
        ];
    }

    $model = $model ?? getSetting('nvidia_model', DEFAULT_NVIDIA_MODEL);
    if (empty($model)) {
        $model = DEFAULT_NVIDIA_MODEL;
    }

    $baseUrl = getSetting('nvidia_base_url', defined('NVIDIA_BASE_URL') ? NVIDIA_BASE_URL : 'https://integrate.api.nvidia.com/v1');
    $endpointUrl = rtrim($baseUrl, '/') . '/chat/completions';

    $maxTokens = (int)getSetting('ai_max_tokens', 8192);

    $payload = [
        'model' => $model,
        'messages' => $messages,
        'temperature' => 0.7,
        'max_tokens' => $maxTokens > 0 ? $maxTokens : 8192,
        'top_p' => 0.9,
    ];

    if ($jsonMode) {
        $payload['response_format'] = ['type' => 'json_object'];
    }

    $ch = curl_init($endpointUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . trim($apiKey)
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return [
            'success' => false,
            'error' => 'Ошибка cURL: ' . $curlError
        ];
    }

    $data = json_decode($response, true);

    if ($httpCode !== 200) {
        $errMsg = $data['error']['message'] ?? $data['message'] ?? "HTTP $httpCode: $response";
        return [
            'success' => false,
            'error' => "Ошибка API ($httpCode): $errMsg"
        ];
    }

    $content = $data['choices'][0]['message']['content'] ?? '';

    return [
        'success' => true,
        'content' => $content,
        'raw' => $data
    ];
}

/**
 * Fetch available models from Base URL (/v1/models)
 */
function fetchAiModels(?string $baseUrl = null, ?string $apiKey = null): array {
    $baseUrl = $baseUrl ?: getSetting('nvidia_base_url', 'https://integrate.api.nvidia.com/v1');
    $apiKey = $apiKey ?: getSetting('nvidia_api_key', '');

    if (empty($apiKey)) {
        return ['success' => false, 'error' => 'API ключ не указан'];
    }

    $url = rtrim($baseUrl, '/') . '/models';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . trim($apiKey),
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['success' => false, 'error' => 'Ошибка cURL: ' . $curlError];
    }

    $json = json_decode($response, true);
    if ($httpCode !== 200 || !is_array($json)) {
        $msg = $json['error']['message'] ?? "HTTP $httpCode: $response";
        return ['success' => false, 'error' => "Ошибка сервера ($httpCode): $msg"];
    }

    $models = [];
    if (isset($json['data']) && is_array($json['data'])) {
        foreach ($json['data'] as $item) {
            if (isset($item['id'])) {
                $models[] = $item['id'];
            }
        }
    } elseif (isset($json['models']) && is_array($json['models'])) {
        foreach ($json['models'] as $item) {
            $models[] = is_array($item) ? ($item['name'] ?? $item['id'] ?? '') : (string)$item;
        }
    }

    $models = array_values(array_filter(array_unique($models)));
    sort($models);

    return [
        'success' => true,
        'models' => $models,
        'count' => count($models)
    ];
}
