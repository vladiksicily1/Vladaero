<?php
/**
 * ShibaLingo - Vladikish Verb Conjugation Engine (Feature #21)
 */

$pageTitle = 'Спряжение глаголов Vladikish';
require_once __DIR__ . '/includes/header.php';

$verb = trim($_GET['verb'] ?? 'toro');

// Conjugation Logic for Vladikish
$stem = rtrim($verb, 'o');
if (empty($stem)) $stem = $verb;

$present = [
    'Me (Я)' => $stem . 'o',
    'Tu (Ты)' => $stem . 'as',
    'Il / Ela (Он / Она)' => $stem . 'a',
    'Nos (Мы)' => $stem . 'amos',
    'Vos (Вы)' => $stem . 'atis',
    'Ilis (Они)' => $stem . 'an'
];

$past = [
    'Me (Я)' => $stem . 'oti',
    'Tu (Ты)' => $stem . 'asti',
    'Il / Ela (Он / Она)' => $stem . 'ati',
    'Nos (Мы)' => $stem . 'amosti',
    'Vos (Вы)' => $stem . 'atisti',
    'Ilis (Они)' => $stem . 'anti'
];

$future = [
    'Me (Я)' => 'vo-' . $stem . 'o',
    'Tu (Ты)' => 'vo-' . $stem . 'as',
    'Il / Ela (Он / Она)' => 'vo-' . $stem . 'a',
    'Nos (Мы)' => 'vo-' . $stem . 'amos',
    'Vos (Вы)' => 'vo-' . $stem . 'atis',
    'Ilis (Они)' => 'vo-' . $stem . 'an'
];
?>

<div style="max-width: 860px; margin: 0 auto; padding-bottom: 40px;">
    <div style="margin-bottom: 20px;">
        <h1 style="font-size: 1.8rem; font-weight: 900; display: flex; align-items: center; gap: 12px; margin-bottom: 6px;">
            <span>📐</span> Таблица спряжения глаголов Vladikish
        </h1>
        <p style="color: var(--text-muted); font-size: 1rem; margin-bottom: 16px;">
            Введите любой глагол Vladikish, чтобы увидеть все его формы и времена
        </p>

        <!-- Sub Navigation Tabs -->
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <a href="conlang.php" class="btn-duo btn-outline" style="padding: 10px 18px; font-size: 0.95rem; text-decoration: none;">
                📚 Словарь и Правила
            </a>
            <a href="conjugator.php" class="btn-duo btn-primary" style="padding: 10px 18px; font-size: 0.95rem; text-decoration: none;">
                🔄 Спрягатель глаголов
            </a>
            <a href="lore.php" class="btn-duo btn-outline" style="padding: 10px 18px; font-size: 0.95rem; text-decoration: none;">
                📜 Лор и История
            </a>
            <a href="translator.php" class="btn-duo btn-outline" style="padding: 10px 18px; font-size: 0.95rem; text-decoration: none;">
                🌐 Переводчик
            </a>
        </div>
    </div>

    <!-- Verb Input Box -->
    <div class="card-duo" style="margin-bottom: 24px;">
        <form method="GET" style="display: flex; gap: 12px;">
            <input type="text" name="verb" value="<?= e($verb) ?>" class="chat-input" placeholder="Например: toro, velo, lingo" style="margin-bottom: 0; font-size: 1.1rem; font-weight: 700;">
            <button type="submit" class="btn-duo btn-primary" style="padding: 12px 28px;">
                Спрягать 🐾
            </button>
        </form>
    </div>

    <!-- Tenses Grid -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 20px;">
        <!-- Present Tense -->
        <div class="card-duo">
            <h3 style="font-size: 1.15rem; font-weight: 800; color: var(--primary); margin-bottom: 12px;">
                🟢 Настоящее время (Present)
            </h3>
            <table style="width: 100%; border-collapse: collapse;">
                <?php foreach ($present as $pronoun => $form): ?>
                    <tr style="border-bottom: 1px solid var(--border-color);">
                        <td style="padding: 8px 0; color: var(--text-muted); font-size: 0.9rem;"><?= $pronoun ?></td>
                        <td style="padding: 8px 0; font-weight: 800; text-align: right; color: var(--primary-shadow);"><?= $form ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>

        <!-- Past Tense -->
        <div class="card-duo">
            <h3 style="font-size: 1.15rem; font-weight: 800; color: var(--secondary); margin-bottom: 12px;">
                🔵 Прошедшее время (-ti)
            </h3>
            <table style="width: 100%; border-collapse: collapse;">
                <?php foreach ($past as $pronoun => $form): ?>
                    <tr style="border-bottom: 1px solid var(--border-color);">
                        <td style="padding: 8px 0; color: var(--text-muted); font-size: 0.9rem;"><?= $pronoun ?></td>
                        <td style="padding: 8px 0; font-weight: 800; text-align: right; color: var(--secondary-shadow);"><?= $form ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>

        <!-- Future Tense -->
        <div class="card-duo">
            <h3 style="font-size: 1.15rem; font-weight: 800; color: #a855f7; margin-bottom: 12px;">
                🟣 Будущее время (vo-)
            </h3>
            <table style="width: 100%; border-collapse: collapse;">
                <?php foreach ($future as $pronoun => $form): ?>
                    <tr style="border-bottom: 1px solid var(--border-color);">
                        <td style="padding: 8px 0; color: var(--text-muted); font-size: 0.9rem;"><?= $pronoun ?></td>
                        <td style="padding: 8px 0; font-weight: 800; text-align: right; color: #7e22ce;"><?= $form ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
