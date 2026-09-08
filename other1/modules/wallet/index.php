<?php
if (!defined('VLADINC_INIT')) exit;

$currentUser = Auth::requireAuth();
$pageTitle = 'VladPay — Внутренний кошелек';

// Fetch recent transactions
$transactions = DB::fetchAll("
    SELECT t.*, 
           u_from.username as from_user, u_from.display_name as from_name,
           u_to.username as to_user, u_to.display_name as to_name
    FROM transactions t
    LEFT JOIN users u_from ON t.from_user_id = u_from.id
    LEFT JOIN users u_to ON t.to_user_id = u_to.id
    WHERE t.from_user_id = ? OR t.to_user_id = ?
    ORDER BY t.id DESC LIMIT 30
", [$currentUser['id'], $currentUser['id']]);

require_once TEMPLATES_PATH . '/header.php';
?>

<div style="grid-column: span 2; display: flex; flex-direction: column; gap: 24px;">

    <!-- WALLET HERO DASHBOARD -->
    <div class="card" style="background: linear-gradient(135deg, #172554, #1e1b4b); border-color: #3b82f6; position: relative; overflow: hidden; padding: 32px;">
        <div style="position: absolute; right: -20px; top: -20px; font-size: 150px; opacity: 0.08; user-select: none;">🪙</div>

        <div style="font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #93c5fd; margin-bottom: 8px;">
            VladPay &bull; Внутренняя валюта экосистемы
        </div>
        
        <div style="display: flex; align-items: baseline; gap: 12px; margin-bottom: 20px;">
            <div style="font-size: 44px; font-weight: 900; color: #fbbf24; text-shadow: 0 0 20px rgba(245, 158, 11, 0.4);">
                <?= format_coins($currentUser['coins']) ?>
            </div>
            <span style="font-size: 20px; font-weight: 700; color: #fde68a;">VladCoins</span>
        </div>

        <div style="display: flex; gap: 16px; flex-wrap: wrap;">
            <button type="button" class="btn btn-primary" onclick="document.getElementById('transfer-recipient').focus()">
                <span>💸</span> Перевести пользователю
            </button>
            <button type="button" id="claim-bonus-btn" class="btn btn-secondary" style="border-color: #f59e0b; color: #fbbf24;">
                <span>🎁</span> Забрать ежедневный бонус (+50 🪙)
            </button>
            <a href="<?= url('arcade') ?>" class="btn btn-secondary">
                <span>🎮</span> Добывать в Аркаде
            </a>
        </div>
    </div>

    <!-- TRANSFER CARD -->
    <div class="card">
        <h3 style="font-size: 17px; font-weight: 700; margin-bottom: 6px;">Мгновенный перевод VladCoins</h3>
        <p style="color: var(--text-secondary); font-size: 13px; margin-bottom: 20px;">
            Переводите монеты любому участнику экосистемы без комиссии.
        </p>

        <form id="transfer-coins-form">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                <div>
                    <label style="display: block; font-size: 13px; color: var(--text-muted); margin-bottom: 6px;">Получатель (@username)</label>
                    <input type="text" id="transfer-recipient" class="form-control" placeholder="admin" required>
                </div>
                <div>
                    <label style="display: block; font-size: 13px; color: var(--text-muted); margin-bottom: 6px;">Сумма (VladCoins)</label>
                    <input type="number" id="transfer-amount" class="form-control" min="1" max="<?= $currentUser['coins'] ?>" placeholder="100" required>
                </div>
            </div>
            <div style="margin-bottom: 18px;">
                <label style="display: block; font-size: 13px; color: var(--text-muted); margin-bottom: 6px;">Комментарий к переводу (необязательно)</label>
                <input type="text" id="transfer-note" class="form-control" placeholder="За отличный пост!">
            </div>
            <button type="submit" class="btn btn-primary">Отправить перевод</button>
        </form>
    </div>

    <!-- TRANSACTION HISTORY -->
    <div class="card">
        <h3 style="font-size: 17px; font-weight: 700; margin-bottom: 14px;">История операций</h3>

        <?php if (empty($transactions)): ?>
            <div style="text-align: center; color: var(--text-muted); padding: 30px;">
                Транзакций пока нет
            </div>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 10px;">
                <?php foreach ($transactions as $tx): 
                    $isIncome = ($tx['to_user_id'] == $currentUser['id']);
                ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; background: var(--bg-input); border-radius: var(--radius-md);">
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div style="width: 36px; height: 36px; border-radius: 50%; background: <?= $isIncome ? 'rgba(16, 185, 129, 0.2)' : 'rgba(239, 68, 68, 0.2)' ?>; display: flex; align-items: center; justify-content: center; font-size: 18px;">
                                <?= $isIncome ? '📥' : '📤' ?>
                            </div>
                            <div>
                                <div style="font-size: 14px; font-weight: 700;">
                                    <?php if ($tx['type'] === 'bonus'): ?>
                                        Бонус экосистемы
                                    <?php elseif ($tx['type'] === 'game_reward'): ?>
                                        Награда за Аркаду
                                    <?php elseif ($isIncome): ?>
                                        Перевод от <a href="<?= url('profile/@' . $tx['from_user']) ?>" style="color: var(--accent-primary);">@<?= e($tx['from_user']) ?></a>
                                    <?php else: ?>
                                        Перевод для <a href="<?= url('profile/@' . $tx['to_user']) ?>" style="color: var(--accent-primary);">@<?= e($tx['to_user']) ?></a>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size: 12px; color: var(--text-muted);">
                                    <?= e($tx['note'] ?: 'Без комментария') ?> &bull; <?= time_ago($tx['created_at']) ?>
                                </div>
                            </div>
                        </div>

                        <div style="font-size: 16px; font-weight: 800; color: <?= $isIncome ? '#34d399' : '#f87171' ?>;">
                            <?= $isIncome ? '+' : '-' ?><?= number_format($tx['amount'], 0, '.', ' ') ?> 🪙
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

</div>

<?php require_once TEMPLATES_PATH . '/footer.php'; ?>
