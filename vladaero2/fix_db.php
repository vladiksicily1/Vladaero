<?php
/**
 * VladAero — Comprehensive DB Migration & Fix
 * DELETE THIS FILE AFTER RUNNING!
 */

$host = '78.108.80.36';
$name = 'b240971_Vlad2';
$user = 'u240971_QVuz';
$pass = 'VladZhuk2026';

// Auto-detect prefix from existing tables
$pdo = new PDO("mysql:host={\$host};dbname={\$name};charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$allTables = [];
foreach ($pdo->query('SHOW TABLES') as $row) $allTables[] = current($row);
$prefix = 'vld_'; // default
foreach ($allTables as $t) {
    if (preg_match('/^(\w+)_users$/', $t, $m)) {
        $prefix = $m[1] . '_';
        break;
    }
}

try {
    echo "<h2>🔧 VladAero — DB Migration</h2>";
    echo "<p>Обнаружен префикс: <b>{$prefix}</b></p>";

    // ─── Helper: add column if missing (safe AFTER) ──────────
    function addCol($pdo, $prefix, $table, $col, $def, $after = null) {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$prefix}{$table}`");
        $cols = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) $cols[$r['Field']] = true;
        if (isset($cols[$col])) return false; // already exists

        // Check if AFTER column exists; if not, skip AFTER
        if ($after && !isset($cols[$after])) $after = null;

        $sql = "ALTER TABLE `{$prefix}{$table}` ADD COLUMN `{$col}` {$def}";
        if ($after) $sql .= " AFTER `{$after}`";
        $pdo->exec($sql);
        return true;
    }

    // ─── Helper: drop column ─────────────────────────────────
    function dropCol($pdo, $prefix, $table, $col) {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$prefix}{$table}`");
        $cols = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) $cols[$r['Field']] = true;
        if (isset($cols[$col])) {
            $pdo->exec("ALTER TABLE `{$prefix}{$table}` DROP COLUMN `{$col}`");
            return true;
        }
        return false;
    }

    // ─── Helper: get column names ────────────────────────────
    function getCols($pdo, $prefix, $table) {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$prefix}{$table}`");
        $cols = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) $cols[$r['Field']] = $r;
        return $cols;
    }

    $totalAdded = 0;
    $log = [];

    // ═══════════════════════════════════════════════════════════
    // 1. USERS TABLE — add all missing columns in order
    // ═══════════════════════════════════════════════════════════
    $usersAdded = [];
    // First pass: add without AFTER to ensure position independence
    $userCols = [
        'display_name'   => "VARCHAR(100) NOT NULL DEFAULT ''",
        'avatar_url'     => "VARCHAR(500) DEFAULT NULL",
        'rank'           => "VARCHAR(50) DEFAULT 'Курсант'",
        'email_notifications' => "TINYINT(1) DEFAULT 0",
        'show_email'     => "TINYINT(1) DEFAULT 0",
        'show_profile'   => "TINYINT(1) DEFAULT 1",
        'tfa_enabled'    => "TINYINT(1) DEFAULT 0",
        'tfa_secret'     => "VARCHAR(255)",
        'language'       => "VARCHAR(5) DEFAULT 'ru'",
        'theme'          => "ENUM('light','dark','system') DEFAULT 'system'",
        'sound_enabled'  => "TINYINT(1) DEFAULT 1",
        'privacy_logbook' => "TINYINT(1) DEFAULT 1",
        'privacy_stats'  => "TINYINT(1) DEFAULT 1",
        'privacy_albums' => "TINYINT(1) DEFAULT 1",
        'status'         => "ENUM('active','banned','deleted') DEFAULT 'active'",
        'email_verified_at' => "TIMESTAMP NULL",
        'last_login_at'  => "TIMESTAMP NULL",
        'reputation'     => "INT UNSIGNED DEFAULT 0",
        'telegram_id'    => "BIGINT",
        'telegram_username' => "VARCHAR(100)",
        'telegram_linked' => "TINYINT(1) DEFAULT 0",
    ];
    foreach ($userCols as $col => $def) {
        if (addCol($pdo, $prefix, 'users', $col, $def)) $usersAdded[] = $col;
    }
    $pdo->exec("UPDATE `{$prefix}users` SET display_name = username WHERE display_name = '' OR display_name IS NULL");
    if (!empty($usersAdded)) { $log[] = "users: +" . implode(', ', $usersAdded); $totalAdded += count($usersAdded); }

    // ═══════════════════════════════════════════════════════════
    // 2. NEWS TABLE
    // ═══════════════════════════════════════════════════════════
    $newsAdded = [];
    $newsCols = [
        'type'        => "ENUM('news','article') DEFAULT 'news'",
        'category_id' => "INT UNSIGNED",
        'author_id'   => "INT UNSIGNED",
        'is_pinned'   => "TINYINT(1) DEFAULT 0",
        'status'      => "ENUM('draft','published','archived') DEFAULT 'draft'",
        'views'       => "INT UNSIGNED DEFAULT 0",
    ];
    foreach ($newsCols as $col => $def) {
        if (addCol($pdo, $prefix, 'news', $col, $def)) $newsAdded[] = $col;
    }
    if (!empty($newsAdded)) { $log[] = "news: +" . implode(', ', $newsAdded); $totalAdded += count($newsAdded); }

    // ═══════════════════════════════════════════════════════════
    // 3. PHOTOS TABLE
    // ═══════════════════════════════════════════════════════════
    $photosAdded = [];
    $photosCols = [
        'description'  => "TEXT",
        'views'        => "INT UNSIGNED DEFAULT 0",
        'rating'       => "DECIMAL(3,2) DEFAULT 0",
        'rating_count' => "INT UNSIGNED DEFAULT 0",
    ];
    foreach ($photosCols as $col => $def) {
        if (addCol($pdo, $prefix, 'photos', $col, $def)) $photosAdded[] = $col;
    }
    if (!empty($photosAdded)) { $log[] = "photos: +" . implode(', ', $photosAdded); $totalAdded += count($photosAdded); }

    // ═══════════════════════════════════════════════════════════
    // 4. AIRPORTS TABLE
    // ═══════════════════════════════════════════════════════════
    $airportsAdded = [];
    $airportsCols = [
        'runway_length' => "INT",
        'airport_type'  => "VARCHAR(50) DEFAULT 'international'",
        'elevation'     => "INT",
    ];
    foreach ($airportsCols as $col => $def) {
        if (addCol($pdo, $prefix, 'airports', $col, $def)) $airportsAdded[] = $col;
    }
    if (!empty($airportsAdded)) { $log[] = "airports: +" . implode(', ', $airportsAdded); $totalAdded += count($airportsAdded); }

    // ═══════════════════════════════════════════════════════════
    // 5. AIRLINES TABLE
    // ═══════════════════════════════════════════════════════════
    $airlinesAdded = [];
    $airlinesCols = [
        'founded'    => "VARCHAR(20)",
        'hub_airport' => "VARCHAR(100)",
        'alliance'   => "VARCHAR(50)",
        'fleet_size' => "INT UNSIGNED DEFAULT 0",
        'logo_url'   => "VARCHAR(500)",
    ];
    foreach ($airlinesCols as $col => $def) {
        if (addCol($pdo, $prefix, 'airlines', $col, $def)) $airlinesAdded[] = $col;
    }
    if (!empty($airlinesAdded)) { $log[] = "airlines: +" . implode(', ', $airlinesAdded); $totalAdded += count($airlinesAdded); }

    // ═══════════════════════════════════════════════════════════
    // 6. AIRCRAFT TABLE
    // ═══════════════════════════════════════════════════════════
    $aircraftAdded = [];
    $aircraftCols = [
        'manufacturer'   => "VARCHAR(200)",
        'type_code'      => "VARCHAR(20)",
        'engine_type'    => "VARCHAR(50)",
        'engine_count'   => "TINYINT DEFAULT 2",
        'passengers'     => "SMALLINT",
        'description'    => "TEXT",
        'wikipedia_url'  => "VARCHAR(500)",
    ];
    foreach ($aircraftCols as $col => $def) {
        if (addCol($pdo, $prefix, 'aircraft', $col, $def)) $aircraftAdded[] = $col;
    }
    if (!empty($aircraftAdded)) { $log[] = "aircraft: +" . implode(', ', $aircraftAdded); $totalAdded += count($aircraftAdded); }

    // ═══════════════════════════════════════════════════════════
    // 7. Create missing tables
    // ═══════════════════════════════════════════════════════════
    $stmt = $pdo->query('SHOW TABLES');
    $existing = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $existing[] = current($row);

    $requiredTables = [
        'settings', 'languages', 'admin_log', 'users', 'user_sessions', 'password_resets',
        'user_follows', 'user_bookmarks', 'user_achievements', 'user_favorite_aircraft',
        'aircraft_manufacturers', 'aircraft', 'aircraft_i18n', 'aircraft_photos', 'aircraft_incidents',
        'airlines', 'airline_fleet', 'airports', 'airport_spotting_spots',
        'photos', 'photo_likes', 'articles', 'article_drafts', 'news',
        'comments', 'events', 'event_attendees',
        'quizzes', 'quiz_questions', 'quiz_results', 'quiz_leaderboard',
        'comparisons', 'comparison_votes', 'flight_logs', 'airline_reviews',
        'clubs', 'club_members', 'checklists', 'glossary', 'phraseology',
        'media', 'liveries', 'va_companies', 'va_pilots', 'va_flights',
        'photo_of_day', 'radar_subscriptions', 'radar_track_archive', 'notifications',
        'ai_conversations', 'ai_messages', 'ai_chats', 'polls', 'poll_votes',
        'cache_metadata', 'sitemap_queue',
        'news_categories', 'photo_ratings', 'checklist_items',
    ];

    $missing = [];
    foreach ($requiredTables as $t) {
        if (!in_array("{$prefix}{$t}", $existing)) {
            $missing[] = $prefix . $t;
        }
    }

    $tablesCreated = 0;
    if (!empty($missing)) {
        $schemaFile = __DIR__ . '/database/schema.sql';
        if (file_exists($schemaFile)) {
            $schema = file_get_contents($schemaFile);
            $schema = str_replace('{prefix}', $prefix);
            preg_match_all('/CREATE TABLE IF NOT EXISTS `' . preg_quote($prefix) . '(\w+)`\s*\(.*?\)\s*ENGINE=InnoDB[^;]*;/s', $schema, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                $tname = $prefix . $m[1];
                if (in_array($tname, $missing)) {
                    try { $pdo->exec($m[0]); $tablesCreated++; } catch (Throwable $e) {}
                }
            }
            // Views
            preg_match_all('/CREATE OR REPLACE VIEW `' . preg_quote($prefix) . '\w+`.*?;/s', $schema, $vmatches, PREG_SET_ORDER);
            foreach ($vmatches as $vm) {
                try { $pdo->exec($vm[0]); } catch (Throwable $e) {}
            }
        }
    }

    // ═══════════════════════════════════════════════════════════
    // OUTPUT
    // ═══════════════════════════════════════════════════════════
    echo "<h3>✅ Миграция завершена</h3>";
    echo "<p>Добавлено колонок: <b>{$totalAdded}</b></p>";

    if (!empty($log)) {
        echo "<ul>";
        foreach ($log as $l) echo "<li>{$l}</li>";
        echo "</ul>";
    } else {
        echo "<p>Все колонки уже на месте.</p>";
    }

    if ($tablesCreated > 0) {
        echo "<p>Создано таблиц: <b>{$tablesCreated}</b></p>";
    }

    if (!empty($missing)) {
        echo "<p>⚠️ Отсутствующие таблицы (попытка создать): " . implode(', ', $missing) . "</p>";
    }

    echo "<h3>📋 vlaero_users:</h3><pre>";
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$prefix}users`");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo $row['Field'] . " | " . $row['Type'] . "\n";
    }
    echo "</pre>";

    echo "<p style='color:red;font-weight:bold;font-size:1.2em;margin-top:2rem;'>⚠️ УДАЛИТЕ fix_db.php!</p>";

} catch (Throwable $e) {
    echo "<h2 style='color:red'>❌ " . htmlspecialchars($e->getMessage()) . "</h2>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}
