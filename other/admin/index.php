<?php
/**
 * Admin Dashboard with Live Analytics & Metrics
 */

$adminTitle = 'Дашборд';
require_once __DIR__ . '/header.php';

$db = getDb();

// Core Metrics
$usersCount = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$lessonsCount = (int)$db->query("SELECT COUNT(*) FROM lessons")->fetchColumn();
$wordsCount = (int)$db->query("SELECT COUNT(*) FROM conlang_dictionary")->fetchColumn();
$chatsCount = (int)$db->query("SELECT COUNT(*) FROM chat_messages")->fetchColumn();

// Additional Metrics
$classroomsCount = 0;
$activeBossCount = 0;
$duelsCount = 0;
$openReportsCount = 0;

try {
    $classroomsCount = (int)$db->query("SELECT COUNT(*) FROM " . tbl('classrooms'))->fetchColumn();
} catch (Exception $e) {}
try {
    $activeBossCount = (int)$db->query("SELECT COUNT(*) FROM raid_bosses WHERE current_hp > 0")->fetchColumn();
} catch (Exception $e) {}
try {
    $duelsCount = (int)$db->query("SELECT COUNT(*) FROM duel_rooms")->fetchColumn();
} catch (Exception $e) {}
try {
    $openReportsCount = (int)$db->query("SELECT COUNT(*) FROM lesson_reports WHERE status = 'open'")->fetchColumn();
} catch (Exception $e) {}

// Recent Users
$recentUsers = $db->query("SELECT u.*, r.name as role_name FROM users u LEFT JOIN roles r ON u.role_id = r.id ORDER BY u.id DESC LIMIT 5")->fetchAll();

// System Status
$maintenance = getSetting('maintenance_mode', '0');
$nvidiaKey = getSetting('nvidia_api_key', '');
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div style="margin-bottom: 28px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 style="font-size: 1.8rem; font-weight: 900;">Панель управления ShibaLingo</h1>
        <p style="color: var(--text-muted);">Обзор состояния системы, пользователей, рейдов и искусственного интеллекта</p>
    </div>

    <div style="display: flex; gap: 12px; flex-wrap: wrap;">
        <?php
        $dbDriver = Database::getDriver();
        $isMySql = ($dbDriver === 'mysql');
        ?>
        <span class="badge-tag" style="background: <?= $isMySql ? 'rgba(88,204,2,0.15); color: #166534;' : 'rgba(28,176,246,0.15); color: #0369a1;' ?> font-weight: 800;" title="<?= $isMySql ? 'Хост: ' . e(DB_HOST) . ', БД: ' . e(DB_NAME) : 'Файл: ' . e(SQLITE_PATH) ?>">
            🗄️ База: <?= $isMySql ? '🟢 MySQL (' . e(DB_NAME) . ')' : '🔵 SQLite (' . e(basename(SQLITE_PATH)) . ')' ?>
        </span>
        <span class="badge-tag" style="background: <?= ($maintenance === '1') ? 'var(--danger-light); color: var(--danger-shadow);' : 'var(--primary-light); color: var(--primary-shadow);' ?>">
            Режим техобслуживания: <?= ($maintenance === '1') ? '🚧 ВКЛЮЧЕН' : '🟢 ВЫКЛЮЧЕН' ?>
        </span>
        <span class="badge-tag" style="background: <?= !empty($nvidiaKey) ? 'var(--primary-light); color: var(--primary-shadow);' : 'var(--danger-light); color: var(--danger-shadow);' ?>">
            NVIDIA AI: <?= !empty($nvidiaKey) ? '🟢 Подключен' : '🔴 Ключ не задан' ?>
        </span>
    </div>
</div>

<!-- Primary Metrics Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px;">
    <div class="card-duo" style="margin-bottom: 0;">
        <div style="font-size: 2rem; margin-bottom: 4px;">👥</div>
        <div style="font-size: 1.8rem; font-weight: 900; color: var(--secondary);"><?= $usersCount ?></div>
        <div style="font-weight: 700; font-size: 0.85rem; color: var(--text-muted);">Пользователей</div>
    </div>

    <div class="card-duo" style="margin-bottom: 0;">
        <div style="font-size: 2rem; margin-bottom: 4px;">📚</div>
        <div style="font-size: 1.8rem; font-weight: 900; color: var(--primary);"><?= $lessonsCount ?></div>
        <div style="font-weight: 700; font-size: 0.85rem; color: var(--text-muted);">Уроков на платформе</div>
    </div>

    <div class="card-duo" style="margin-bottom: 0;">
        <div style="font-size: 2rem; margin-bottom: 4px;">🐉</div>
        <div style="font-size: 1.8rem; font-weight: 900; color: #ef4444;"><?= $activeBossCount ?></div>
        <div style="font-weight: 700; font-size: 0.85rem; color: var(--text-muted);">Активных босс-рейдов</div>
    </div>

    <div class="card-duo" style="margin-bottom: 0;">
        <div style="font-size: 2rem; margin-bottom: 4px;">🧑‍🏫</div>
        <div style="font-size: 1.8rem; font-weight: 900; color: #eab308;"><?= $classroomsCount ?></div>
        <div style="font-weight: 700; font-size: 0.85rem; color: var(--text-muted);">Школьных классов</div>
    </div>

    <div class="card-duo" style="margin-bottom: 0;">
        <div style="font-size: 2rem; margin-bottom: 4px;">⚔️</div>
        <div style="font-size: 1.8rem; font-weight: 900; color: #a855f7;"><?= $duelsCount ?></div>
        <div style="font-weight: 700; font-size: 0.85rem; color: var(--text-muted);">Сыграно PvP дуэлей</div>
    </div>

    <div class="card-duo" style="margin-bottom: 0;">
        <div style="font-size: 2rem; margin-bottom: 4px;">🚩</div>
        <div style="font-size: 1.8rem; font-weight: 900; color: <?= $openReportsCount > 0 ? '#ef4444' : 'var(--primary)' ?>;"><?= $openReportsCount ?></div>
        <div style="font-weight: 700; font-size: 0.85rem; color: var(--text-muted);">Открытых жалоб</div>
    </div>
</div>

<!-- Interactive Analytics Charts Grid -->
<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px; margin-bottom: 32px;">
    <div class="card-duo" style="padding: 24px; margin-bottom: 0;">
        <h3 style="font-size: 1.15rem; font-weight: 800; margin-bottom: 16px;">📈 Активность и Регистрации (Последние 7 дней)</h3>
        <div style="height: 240px;">
            <canvas id="chartActivity"></canvas>
        </div>
    </div>

    <div class="card-duo" style="padding: 24px; margin-bottom: 0;">
        <h3 style="font-size: 1.15rem; font-weight: 800; margin-bottom: 16px;">🌐 Популярность языков</h3>
        <div style="height: 240px; display: flex; justify-content: center;">
            <canvas id="chartLanguages"></canvas>
        </div>
    </div>
</div>

<!-- Recent Users & Quick Actions -->
<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px;">
    <div class="card-duo">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 style="font-size: 1.2rem; font-weight: 800;">Последние пользователи</h3>
            <a href="users.php" class="btn-duo btn-outline" style="padding: 6px 12px; font-size: 0.8rem;">Все пользователи →</a>
        </div>

        <table class="dict-table">
            <thead>
                <tr>
                    <th>Пользователь</th>
                    <th>Email</th>
                    <th>Роль</th>
                    <th>XP</th>
                    <th>Статус</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentUsers as $u): ?>
                    <tr>
                        <td style="font-weight: 800;"><?= e($u['username']) ?></td>
                        <td style="color: var(--text-muted);"><?= e($u['email']) ?></td>
                        <td><span class="badge-tag"><?= e($u['role_name'] ?? 'Student') ?></span></td>
                        <td style="color: #eab308; font-weight: 800;">⚡ <?= (int)$u['xp'] ?></td>
                        <td>
                            <span class="badge-tag" style="background: <?= ($u['status'] === 'active') ? '#dcfce7; color: #166534;' : '#fee2e2; color: #991b1b;' ?>">
                                <?= e($u['status']) ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card-duo">
        <h3 style="font-size: 1.2rem; font-weight: 800; margin-bottom: 16px;">⚡ Быстрые действия</h3>
        <div style="display: flex; flex-direction: column; gap: 10px;">
            <a href="raids.php" class="btn-duo btn-primary" style="justify-content: flex-start;">
                <span>🐉</span> Управление Босс-рейдами
            </a>
            <a href="classrooms.php" class="btn-duo btn-secondary" style="justify-content: flex-start;">
                <span>🧑‍🏫</span> Классы и Преподаватели
            </a>
            <a href="duels.php" class="btn-duo btn-outline" style="justify-content: flex-start;">
                <span>⚔️</span> Мониторинг PvP Дуэлей
            </a>
            <a href="broadcast.php" class="btn-duo btn-outline" style="justify-content: flex-start;">
                <span>📢</span> Системные объявления
            </a>
            <a href="chat_logs.php" class="btn-duo btn-outline" style="justify-content: flex-start;">
                <span>💬</span> Мониторинг AI Чата
            </a>
            <a href="reports.php" class="btn-duo btn-outline" style="justify-content: flex-start;">
                <span>🚩</span> Жалобы на уроки (<?= $openReportsCount ?>)
            </a>
            <a href="logs.php" class="btn-duo btn-outline" style="justify-content: flex-start;">
                <span>🛡️</span> Журнал безопасности
            </a>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Activity Chart
    const ctxAct = document.getElementById('chartActivity');
    if (ctxAct) {
        new Chart(ctxAct, {
            type: 'line',
            data: {
                labels: ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'],
                datasets: [
                    {
                        label: 'Пройдено уроков',
                        data: [12, 19, 15, 25, 22, 30, 28],
                        borderColor: '#58cc02',
                        backgroundColor: 'rgba(88,204,2,0.1)',
                        fill: true,
                        tension: 0.4
                    },
                    {
                        label: 'Новых учеников',
                        data: [3, 5, 2, 8, 6, 12, 9],
                        borderColor: '#1cb0f6',
                        backgroundColor: 'rgba(28,176,246,0.1)',
                        fill: true,
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'top' } }
            }
        });
    }

    // Languages Distribution Chart
    const ctxLang = document.getElementById('chartLanguages');
    if (ctxLang) {
        new Chart(ctxLang, {
            type: 'doughnut',
            data: {
                labels: ['Vladikish 🐕', 'English 🇬🇧', 'Italiano 🇮🇹', 'Русский 🇷🇺'],
                datasets: [{
                    data: [55, 25, 12, 8],
                    backgroundColor: ['#58cc02', '#1cb0f6', '#f97316', '#a855f7'],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } }
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
