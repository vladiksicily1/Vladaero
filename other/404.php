<?php
/**
 * ShibaLingo - Custom 404 Error Page
 */

http_response_code(404);
$pageTitle = 'Страница не найдена (404)';
require_once __DIR__ . '/includes/header.php';
?>

<div style="max-width: 650px; margin: 60px auto; text-align: center; padding: 20px;">
    <div class="card-duo anim-bounce" style="padding: 40px 24px;">
        <div id="mascot-404" style="margin-bottom: 16px;"></div>
        
        <div style="font-size: 3.5rem; font-weight: 900; color: var(--primary-shadow); line-height: 1; margin-bottom: 8px;">
            404
        </div>
        
        <h1 style="font-size: 1.6rem; font-weight: 900; margin-bottom: 10px;">
            Ой! Страница потерялась в небесах Vladikish 🐕
        </h1>
        
        <p style="color: var(--text-muted); font-size: 1rem; margin-bottom: 28px; line-height: 1.5;">
            Похоже, адрес был введен с ошибкой или этот раздел переехал в другую звёздную систему.
        </p>

        <!-- Action Buttons -->
        <div style="display: flex; justify-content: center; gap: 12px; flex-wrap: wrap;">
            <a href="index.php" class="btn-duo btn-primary" style="padding: 12px 28px; font-size: 1rem; text-decoration: none;">
                🏠 На главную (Обучение)
            </a>
            <a href="translator.php" class="btn-duo btn-outline" style="padding: 12px 24px; font-size: 1rem; text-decoration: none;">
                🌐 Переводчик
            </a>
            <a href="tamagotchi.php" class="btn-duo btn-outline" style="padding: 12px 24px; font-size: 1rem; text-decoration: none;">
                🐕 К питомцу
            </a>
            <a href="faq.php" class="btn-duo btn-outline" style="padding: 12px 24px; font-size: 1rem; text-decoration: none;">
                ❓ База знаний
            </a>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    if (typeof ShibaMascot !== 'undefined') {
        ShibaMascot.update('mascot-404', 'sad');
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
