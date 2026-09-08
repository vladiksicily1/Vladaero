<?php
/**
 * ShibaLingo - FAQ & Knowledge Base
 */

$pageTitle = 'База знаний и FAQ';
require_once __DIR__ . '/includes/header.php';
?>

<div style="max-width: 860px; margin: 0 auto; padding-bottom: 40px;">
    <!-- Title Header -->
    <div style="margin-bottom: 28px; text-align: center;">
        <div style="font-size: 3rem; margin-bottom: 8px;">🐕 💡</div>
        <h1 style="font-size: 2rem; font-weight: 900; margin-bottom: 8px;">
            База знаний и Ответы на вопросы
        </h1>
        <p style="color: var(--text-muted); font-size: 1.05rem; max-width: 600px; margin: 0 auto;">
            Всё об изучении языков, конланге Vladikish, жизнях ❤️, стриках 🔥, дуэлях и маскоте Шибе!
        </p>
    </div>

    <!-- Quick Search -->
    <div class="card-duo anim-bounce" style="padding: 16px; margin-bottom: 24px;">
        <input type="text" id="faq-search" class="chat-input" placeholder="🔍 Введите ключевое слово (например: 'сердечки', 'стрик', 'vladikish', 'дуэль')..." style="margin-bottom: 0; font-size: 1.05rem;" oninput="filterFaq()">
    </div>

    <!-- FAQ Accordion Container -->
    <div id="faq-list" style="display: flex; flex-direction: column; gap: 14px;">

        <!-- Item 1: What is Vladikish -->
        <div class="card-duo faq-item" style="padding: 20px; cursor: pointer;" onclick="toggleFaq(this)">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h3 style="font-size: 1.15rem; font-weight: 800; margin: 0; display: flex; align-items: center; gap: 10px;">
                    <span>✨</span> Что такое язык Vladikish?
                </h3>
                <span class="faq-icon" style="font-size: 1.2rem; font-weight: 900; color: var(--primary);">+</span>
            </div>
            <div class="faq-answer" style="display: none; margin-top: 14px; color: var(--text-muted); font-size: 0.95rem; line-height: 1.6; border-top: 1px solid var(--border-color); padding-top: 12px;">
                <strong>Vladikish</strong> — это авторский искусственный язык (конланг) с романским и эсперанто-звучанием, логичной грамматикой и строгим порядком SVO (Субъект - Глагол - Объект). На платформе доступен официальный интерактивный словарь более чем на 1 200 слов, спрягатель глаголов, офлайн-переводчик и AI-диалоги с Сиба-сэнсэем.
            </div>
        </div>

        <!-- Item 2: Hearts & Mistakes -->
        <div class="card-duo faq-item" style="padding: 20px; cursor: pointer;" onclick="toggleFaq(this)">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h3 style="font-size: 1.15rem; font-weight: 800; margin: 0; display: flex; align-items: center; gap: 10px;">
                    <span>❤️</span> Как работают Сердечки (Жизни) и как их восстановить?
                </h3>
                <span class="faq-icon" style="font-size: 1.2rem; font-weight: 900; color: var(--primary);">+</span>
            </div>
            <div class="faq-answer" style="display: none; margin-top: 14px; color: var(--text-muted); font-size: 0.95rem; line-height: 1.6; border-top: 1px solid var(--border-color); padding-top: 12px;">
                У каждого ученика есть максимум <strong>5 сердечек</strong>. При каждой ошибке в уроке тратится 1 сердце. Восстановить сердечки можно тремя способами:
                <ul style="margin: 8px 0; padding-left: 20px;">
                    <li>Пройти тренировку в режиме <a href="practice.php" style="color: var(--primary); font-weight: 700;">«Практика»</a> (+1 ❤️ за успешный раунд).</li>
                    <li>Купить мгновенное восстановление за кристаллы 💎 в <a href="shop.php" style="color: var(--primary); font-weight: 700;">Магазине</a>.</li>
                    <li>Активировать промокод на странице <a href="redeem.php" style="color: var(--primary); font-weight: 700;">Промокодов</a>.</li>
                </ul>
            </div>
        </div>

        <!-- Item 3: Streak & Freezes -->
        <div class="card-duo faq-item" style="padding: 20px; cursor: pointer;" onclick="toggleFaq(this)">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h3 style="font-size: 1.15rem; font-weight: 800; margin: 0; display: flex; align-items: center; gap: 10px;">
                    <span>🔥</span> Что такое Стрик дней и Заморозка стрика?
                </h3>
                <span class="faq-icon" style="font-size: 1.2rem; font-weight: 900; color: var(--primary);">+</span>
            </div>
            <div class="faq-answer" style="display: none; margin-top: 14px; color: var(--text-muted); font-size: 0.95rem; line-height: 1.6; border-top: 1px solid var(--border-color); padding-top: 12px;">
                <strong>Стрик (Ударный режим)</strong> — это количество дней подряд, когда вы прошли хотя бы 1 урок или тренировку. Если вы пропустите день, стрик обнулится. Чтобы защитить свой огонёк, купите в магазине предмет <strong>❄️ «Заморозка Стрика»</strong> — она спасёт ваш рекорд при пропуске дня.
            </div>
        </div>

        <!-- Item 4: Duels & Raids -->
        <div class="card-duo faq-item" style="padding: 20px; cursor: pointer;" onclick="toggleFaq(this)">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h3 style="font-size: 1.15rem; font-weight: 800; margin: 0; display: flex; align-items: center; gap: 10px;">
                    <span>⚔️</span> Как играть в PvP Дуэли и Босс-рейды?
                </h3>
                <span class="faq-icon" style="font-size: 1.2rem; font-weight: 900; color: var(--primary);">+</span>
            </div>
            <div class="faq-answer" style="display: none; margin-top: 14px; color: var(--text-muted); font-size: 0.95rem; line-height: 1.6; border-top: 1px solid var(--border-color); padding-top: 12px;">
                <ul>
                    <li><a href="duel.php" style="color: var(--primary); font-weight: 700;">PvP Дуэли</a>: соревнуйтесь с друзьями или случайными игроками в скорости и точности перевода 5 раундов. Победитель забирает XP и кристаллы!</li>
                    <li><a href="raid.php" style="color: var(--primary); font-weight: 700;">Босс-рейды</a>: все игроки сервера объединяются для победы над мировым боссом. Каждый пройденный урок наносит урон боссу.</li>
                </ul>
            </div>
        </div>

        <!-- Item 5: Tamagotchi -->
        <div class="card-duo faq-item" style="padding: 20px; cursor: pointer;" onclick="toggleFaq(this)">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h3 style="font-size: 1.15rem; font-weight: 800; margin: 0; display: flex; align-items: center; gap: 10px;">
                    <span>🐕</span> Как ухаживать за питомцем в Тамагочи?
                </h3>
                <span class="faq-icon" style="font-size: 1.2rem; font-weight: 900; color: var(--primary);">+</span>
            </div>
            <div class="faq-answer" style="display: none; margin-top: 14px; color: var(--text-muted); font-size: 0.95rem; line-height: 1.6; border-top: 1px solid var(--border-color); padding-top: 12px;">
                На странице <a href="tamagotchi.php" style="color: var(--primary); font-weight: 700;">Тамагочи</a> вы можете кормить Шибу вкусняшками, поить молоком, гладить хвостик и играть в мяч. Счастливый и сытый питомец приносит бонусный XP за каждый пройденный урок!
            </div>
        </div>

        <!-- Item 6: Certificate -->
        <div class="card-duo faq-item" style="padding: 20px; cursor: pointer;" onclick="toggleFaq(this)">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h3 style="font-size: 1.15rem; font-weight: 800; margin: 0; display: flex; align-items: center; gap: 10px;">
                    <span>🎓</span> Как получить Сертификат (Диплом)?
                </h3>
                <span class="faq-icon" style="font-size: 1.2rem; font-weight: 900; color: var(--primary);">+</span>
            </div>
            <div class="faq-answer" style="display: none; margin-top: 14px; color: var(--text-muted); font-size: 0.95rem; line-height: 1.6; border-top: 1px solid var(--border-color); padding-top: 12px;">
                Именной диплом с золотой печатью Сиба-сэнсэя и QR-кодом проверки доступен на странице <a href="certificate.php" style="color: var(--primary); font-weight: 700;">Диплома</a> после завершения всех базовых уроков курса. Сертификат можно сохранить в PDF или распечатать.
            </div>
        </div>

    </div>
</div>

<script>
function toggleFaq(card) {
    const answer = card.querySelector('.faq-answer');
    const icon = card.querySelector('.faq-icon');
    if (answer.style.display === 'none' || answer.style.display === '') {
        answer.style.display = 'block';
        icon.textContent = '−';
        icon.style.color = 'var(--text-muted)';
    } else {
        answer.style.display = 'none';
        icon.textContent = '+';
        icon.style.color = 'var(--primary)';
    }
}

function filterFaq() {
    const q = document.getElementById('faq-search').value.toLowerCase();
    const items = document.querySelectorAll('.faq-item');
    items.forEach(item => {
        const text = item.textContent.toLowerCase();
        if (text.includes(q)) {
            item.style.display = 'block';
        } else {
            item.style.display = 'none';
        }
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
