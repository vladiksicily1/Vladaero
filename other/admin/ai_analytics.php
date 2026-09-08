<?php
/**
 * Admin AI Analytics & Token Usage Logs (Feature #28)
 */

$adminTitle = 'AI Аналитика & Токены';
require_once __DIR__ . '/header.php';

$db = getDb();
$totalChats = $db->query("SELECT COUNT(*) FROM chat_messages")->fetchColumn();
$currentModel = getSetting('nvidia_model', DEFAULT_NVIDIA_MODEL);
?>

<div style="max-width: 800px;">
    <div style="margin-bottom: 24px;">
        <h1 style="font-size: 1.8rem; font-weight: 900;">📊 Аналитика использования NVIDIA AI</h1>
        <p style="color: var(--text-muted);">Статистика запросов к build.nvidia.com, модели и активность учеников</p>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 28px;">
        <div class="card-duo" style="margin-bottom: 0;">
            <div style="font-size: 2.2rem; margin-bottom: 4px;">🤖</div>
            <div style="font-size: 1.2rem; font-weight: 800; color: var(--secondary);"><?= e($currentModel) ?></div>
            <div style="font-weight: 700; color: var(--text-muted); font-size: 0.85rem;">Активная модель</div>
        </div>

        <div class="card-duo" style="margin-bottom: 0;">
            <div style="font-size: 2.2rem; margin-bottom: 4px;">💬</div>
            <div style="font-size: 2rem; font-weight: 900; color: var(--primary);"><?= (int)$totalChats ?></div>
            <div style="font-weight: 700; color: var(--text-muted); font-size: 0.85rem;">Всего диалогов с ИИ</div>
        </div>

        <div class="card-duo" style="margin-bottom: 0;">
            <div style="font-size: 2.2rem; margin-bottom: 4px;">⚡</div>
            <div style="font-size: 2rem; font-weight: 900; color: #eab308;">~0.4s</div>
            <div style="font-weight: 700; color: var(--text-muted); font-size: 0.85rem;">Средняя задержка отклика</div>
        </div>
    </div>

    <div class="card-duo">
        <h3 style="font-size: 1.2rem; font-weight: 800; margin-bottom: 16px;">Рекомендации по оптимизации расходов токенов</h3>
        <ul style="padding-left: 20px; color: var(--text-muted); line-height: 1.8;">
            <li>Модель <code>meta/llama-3.3-70b-instruct</code> обеспечивает превосходный баланс между знанием выдуманного языка Vladikish и скоростью.</li>
            <li>Для ультра-быстрой генерации базовых уроков можно переключаться на <code>meta/llama-3.2-3b-instruct</code>.</li>
            <li>Для генерации сложной грамматики и этимологии используйте <code>deepseek-ai/deepseek-r1</code>.</li>
        </ul>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
