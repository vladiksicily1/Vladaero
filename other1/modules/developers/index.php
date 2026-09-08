<?php
if (!defined('VLADINC_INIT')) exit;

$currentUser = Auth::requireAuth();
$pageTitle = 'Портал разработчиков & Внешний REST API';

// Handle key creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_key'])) {
    if (!csrf_validate()) {
        flash_set('error', 'CSRF ошибка');
        redirect('developers');
    }

    $keyName = trim($_POST['key_name'] ?? 'Default API Key');
    $newKey = 'vlad_' . bin2hex(random_bytes(24));

    DB::insert('api_keys', [
        'user_id' => $currentUser['id'],
        'key_name' => $keyName,
        'api_key' => $newKey,
        'permissions' => 'read,write,wallet'
    ]);

    flash_set('success', 'Новый API-ключ успешно сгенерирован!');
    redirect('developers');
}

// Handle key revocation
if (isset($_GET['revoke'])) {
    $revokeId = (int)$_GET['revoke'];
    DB::delete('api_keys', 'id = ? AND user_id = ?', [$revokeId, $currentUser['id']]);
    flash_set('success', 'API-ключ отозван');
    redirect('developers');
}

// User's active API keys
$myKeys = DB::fetchAll("SELECT * FROM api_keys WHERE user_id = ? ORDER BY id DESC", [$currentUser['id']]);

require_once TEMPLATES_PATH . '/header.php';
?>

<div style="grid-column: span 2; display: flex; flex-direction: column; gap: 24px;">

    <!-- HERO CARD -->
    <div class="card" style="background: linear-gradient(135deg, #0c4a6e, #1e1b4b); border-color: #0ea5e9; padding: 32px;">
        <div style="font-size: 13px; font-weight: 800; color: #38bdf8; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px;">
            VladInc Developer Platform &bull; REST API v1
        </div>
        <h1 style="font-size: 26px; font-weight: 800; margin-bottom: 8px;">Внешний программный доступ к экосистеме</h1>
        <p style="color: var(--text-secondary); font-size: 14px; max-width: 700px; line-height: 1.5; margin-bottom: 20px;">
            Создавайте ботов, внешние сайты, скрипты автоматизации и интеграции. Управляйте публикациями, проверяйте баланс VladCoins и переводите средства через защищенные REST API-ключи.
        </p>
        <button type="button" class="btn btn-primary" onclick="document.getElementById('create-key-modal').style.display='block'">
            + Создать новый API-ключ
        </button>
    </div>

    <!-- MY API KEYS -->
    <div class="card">
        <h3 style="font-size: 18px; font-weight: 700; margin-bottom: 14px;">Ваши API-ключи</h3>

        <?php if (empty($myKeys)): ?>
            <div style="text-align: center; color: var(--text-muted); padding: 30px;">
                У вас пока нет активных API-ключей. Нажмите кнопку выше для генерации.
            </div>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 12px;">
                <?php foreach ($myKeys as $k): ?>
                    <div style="padding: 16px; background: var(--bg-input); border-radius: var(--radius-md); border: 1px solid var(--border-color);">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <div>
                                <span style="font-weight: 700; font-size: 15px;"><?= e($k['key_name']) ?></span>
                                <span class="level-badge" style="margin-left: 8px;"><?= e($k['permissions']) ?></span>
                            </div>
                            <a href="<?= url('developers?revoke=' . $k['id']) ?>" class="btn btn-secondary btn-sm" style="color: #ef4444;" onclick="return confirm('Отозвать этот ключ? Все интеграции с ним перестанут работать.')">Отозвать</a>
                        </div>

                        <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 8px;">
                            <input type="text" class="form-control" value="<?= e($k['api_key']) ?>" readonly style="font-family: monospace; font-size: 13px;">
                            <button type="button" class="btn btn-secondary btn-sm" onclick="navigator.clipboard.writeText('<?= e($k['api_key']) ?>'); showToast('Ключ скопирован!', 'success');">Копировать</button>
                        </div>

                        <div style="font-size: 12px; color: var(--text-muted); display: flex; gap: 16px;">
                            <span>Запросов: <strong><?= $k['requests_count'] ?></strong></span>
                            <span>Последнее использование: <?= $k['last_used_at'] ? time_ago($k['last_used_at']) : 'Никогда' ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- INTERACTIVE DOCUMENTATION -->
    <div class="card">
        <h3 style="font-size: 18px; font-weight: 700; margin-bottom: 6px;">📚 Документация и эндпоинты REST API</h3>
        <p style="color: var(--text-secondary); font-size: 13px; margin-bottom: 20px;">
            Базовый URL: <code><?= url('api/v1') ?></code><br>
            Авторизация: Заголовок <code>X-API-Key: &lt;ваш_ключ&gt;</code> или <code>Authorization: Bearer &lt;ваш_ключ&gt;</code>.
        </p>

        <!-- Endpoints List -->
        <div style="display: flex; flex-direction: column; gap: 14px;">
            
            <!-- 1. GET /api/v1/me -->
            <div style="background: var(--bg-input); padding: 14px 18px; border-radius: 10px;">
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 6px;">
                    <span style="background: #10b981; color: #fff; font-size: 11px; font-weight: 800; padding: 2px 6px; border-radius: 4px;">GET</span>
                    <code style="font-size: 14px; font-weight: 700;">/api/v1/me</code>
                </div>
                <div style="font-size: 13px; color: var(--text-secondary); margin-bottom: 8px;">Получить профиль владельца API-ключа, уровень, баланс VladCoins.</div>
                <pre style="background: #000; padding: 10px; border-radius: 6px; font-size: 12px; color: #38bdf8; overflow-x: auto;">curl -H "X-API-Key: YOUR_KEY" <?= url('api/v1/me') ?></pre>
            </div>

            <!-- 2. GET /api/v1/posts -->
            <div style="background: var(--bg-input); padding: 14px 18px; border-radius: 10px;">
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 6px;">
                    <span style="background: #10b981; color: #fff; font-size: 11px; font-weight: 800; padding: 2px 6px; border-radius: 4px;">GET</span>
                    <code style="font-size: 14px; font-weight: 700;">/api/v1/posts?page=1&limit=20</code>
                </div>
                <div style="font-size: 13px; color: var(--text-secondary); margin-bottom: 8px;">Получить посты из ленты социальной сети с пагинацией.</div>
            </div>

            <!-- 3. POST /api/v1/posts -->
            <div style="background: var(--bg-input); padding: 14px 18px; border-radius: 10px;">
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 6px;">
                    <span style="background: #3b82f6; color: #fff; font-size: 11px; font-weight: 800; padding: 2px 6px; border-radius: 4px;">POST</span>
                    <code style="font-size: 14px; font-weight: 700;">/api/v1/posts</code>
                </div>
                <div style="font-size: 13px; color: var(--text-secondary); margin-bottom: 8px;">Опубликовать новую запись в VladInc Social.</div>
                <pre style="background: #000; padding: 10px; border-radius: 6px; font-size: 12px; color: #38bdf8; overflow-x: auto;">curl -X POST <?= url('api/v1/posts') ?> \
  -H "X-API-Key: YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"content": "Привет, мир через API #VladInc!"}'</pre>
            </div>

            <!-- 4. POST /api/v1/wallet/transfer -->
            <div style="background: var(--bg-input); padding: 14px 18px; border-radius: 10px;">
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 6px;">
                    <span style="background: #3b82f6; color: #fff; font-size: 11px; font-weight: 800; padding: 2px 6px; border-radius: 4px;">POST</span>
                    <code style="font-size: 14px; font-weight: 700;">/api/v1/wallet/transfer</code>
                </div>
                <div style="font-size: 13px; color: var(--text-secondary); margin-bottom: 8px;">Программный перевод VladCoins любому пользователю.</div>
                <pre style="background: #000; padding: 10px; border-radius: 6px; font-size: 12px; color: #38bdf8; overflow-x: auto;">curl -X POST <?= url('api/v1/wallet/transfer') ?> \
  -H "X-API-Key: YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"recipient": "alex", "amount": 50, "note": "Авто-выплата"}'</pre>
            </div>

        </div>
    </div>

</div>

<!-- CREATE KEY MODAL -->
<div id="create-key-modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.7); z-index: 2000; align-items: center; justify-content: center; padding: 20px;">
    <div class="card" style="max-width: 440px; margin: 100px auto; position: relative;">
        <button type="button" onclick="document.getElementById('create-key-modal').style.display='none'" style="position: absolute; right: 16px; top: 16px; background: transparent; border: none; font-size: 20px; color: var(--text-muted); cursor: pointer;">✕</button>
        <h3 style="font-size: 18px; font-weight: 700; margin-bottom: 14px;">Создать новый API-ключ</h3>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="create_key" value="1">
            <div style="margin-bottom: 16px;">
                <label style="display: block; font-size: 13px; color: var(--text-muted); margin-bottom: 6px;">Название ключа / проекта</label>
                <input type="text" name="key_name" class="form-control" placeholder="Мой Telegram-бот / Сайт" required>
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%;">Сгенерировать ключ</button>
        </form>
    </div>
</div>

<?php require_once TEMPLATES_PATH . '/footer.php'; ?>
