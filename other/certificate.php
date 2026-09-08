<?php
/**
 * ShibaLingo - Official Language Certificate & Citizen Passport Generator (Feature #40, #52)
 */

$pageTitle = 'Сертификат и Паспорт Vladikish';
require_once __DIR__ . '/includes/header.php';

$certId = strtoupper(substr(md5($user['username'] . $user['id']), 0, 10));
$issueDate = date('d.m.Y');
?>

<div style="max-width: 800px; margin: 0 auto; text-align: center;">
    <div style="margin-bottom: 24px;">
        <h1 style="font-size: 1.8rem; font-weight: 900; display: flex; align-items: center; justify-content: center; gap: 12px;">
            <span>📜</span> Официальный Сертификат & Паспорт Гражданина
        </h1>
        <p style="color: var(--text-muted);">
            Официальное свидетельство Академии Исследователей Vladikish с печатью Сиба-сэнсэя
        </p>
    </div>

    <!-- Certificate Card (Printable) -->
    <div class="card-duo anim-bounce" id="printable-certificate" style="padding: 48px 36px; background: #fffdfa; border: 8px double #d97706; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); position: relative; margin-bottom: 28px;">
        <div style="position: absolute; top: 20px; right: 20px; font-size: 3rem; opacity: 0.8;">🐕</div>
        
        <div style="font-size: 0.95rem; font-weight: 800; text-transform: uppercase; letter-spacing: 3px; color: #b45309; margin-bottom: 8px;">
            ACADEMIA EXPLORATORUM VLADIKISH
        </div>

        <h2 style="font-size: 2.2rem; font-weight: 900; color: #78350f; font-family: serif; margin-bottom: 16px;">
            СЕРТИФИКАТ ЗНАНИЯ ЯЗЫКА
        </h2>

        <p style="font-size: 1.1rem; color: #451a03; margin-bottom: 20px;">
            Настоящий документ удостоверяет, что гражданин
        </p>

        <div style="font-size: 2.4rem; font-weight: 900; color: var(--primary); border-bottom: 2px solid #d97706; display: inline-block; padding: 0 32px 8px 32px; margin-bottom: 20px;">
            <?= e($user['username']) ?>
        </div>

        <p style="font-size: 1.05rem; color: #451a03; max-width: 550px; margin: 0 auto 32px auto; line-height: 1.6;">
            успешно освоил основы и лексику языка <strong>Vladikish</strong>, заработал <strong><?= (int)$user['xp'] ?> очков XP</strong> и удерживает стрик в <strong><?= (int)$user['streak'] ?> дней</strong> активных занятий.
        </p>

        <div style="display: flex; justify-content: space-between; align-items: flex-end; border-top: 1px dashed #d97706; padding-top: 24px;">
            <div style="text-align: left;">
                <div style="font-size: 0.8rem; color: var(--text-muted);">Идентификатор сертификата:</div>
                <code style="font-weight: 900; color: #b45309; font-size: 1rem;">ID: VLAD-<?= $certId ?></code>
                <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 4px;">Дата выдачи: <?= $issueDate ?></div>
            </div>

            <div style="text-align: center;">
                <div style="font-size: 2.5rem;">🐾</div>
                <div style="font-size: 0.85rem; font-weight: 800; color: #78350f;">Печать Сиба-сэнсэя</div>
            </div>
        </div>
    </div>

    <!-- Actions -->
    <div style="display: flex; justify-content: center; gap: 16px;">
        <button class="btn-duo btn-primary" onclick="window.print()" style="padding: 14px 36px;">
            🖨️ Распечатать сертификат (PDF)
        </button>
        <a href="profile.php" class="btn-duo btn-outline">
            Назад в профиль
        </a>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
