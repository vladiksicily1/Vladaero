<?php
/**
 * ShibaLingo - Vladikish Dictionary & Grammar Guide
 * Clean student-facing reference handbook
 */

$pageTitle = 'Словарь и Грамматика Vladikish';
require_once __DIR__ . '/includes/header.php';

$db = getDb();
$dictStmt = $db->query("SELECT * FROM " . tbl('conlang_dictionary') . " ORDER BY word ASC");
$words = $dictStmt->fetchAll();

$gramStmt = $db->query("SELECT * FROM " . tbl('conlang_grammar') . " ORDER BY order_num ASC, id ASC");
$grammarRules = $gramStmt->fetchAll();
?>

<div style="margin-bottom: 20px;">
    <h1 style="font-size: 1.8rem; font-weight: 900; display: flex; align-items: center; gap: 12px; margin-bottom: 6px;">
        <span>🐕</span> Студия языка Vladikish
    </h1>
    <p style="color: var(--text-muted); font-size: 1rem; margin-bottom: 16px;">
        Официальный словарь, транскрипции с озвучкой, грамматический справочник и спряжения глаголов
    </p>

    <!-- Sub Navigation Tabs -->
    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
        <a href="conlang.php" class="btn-duo btn-primary" style="padding: 10px 18px; font-size: 0.95rem; text-decoration: none;">
            📚 Словарь и Правила
        </a>
        <a href="conjugator.php" class="btn-duo btn-outline" style="padding: 10px 18px; font-size: 0.95rem; text-decoration: none;">
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

<div class="conlang-grid" style="display: grid; grid-template-columns: 1fr; gap: 24px;">
    <!-- Dictionary Table Card -->
    <div class="card-duo">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 12px;">
            <h3 style="font-weight: 800; font-size: 1.2rem;">
                📚 Интерактивный словарь (Слов: <?= count($words) ?>)
            </h3>
            <input type="text" id="dict-search-input" class="chat-input" placeholder="🔍 Поиск по слову или переводу..." style="max-width: 320px; font-size: 0.9rem; padding: 8px 14px;">
        </div>

        <div style="overflow-x: auto;">
            <table class="dict-table" style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="text-align: left; border-bottom: 2px solid var(--border-color);">
                        <th style="padding: 10px;">Слово</th>
                        <th style="padding: 10px;">Часть речи</th>
                        <th style="padding: 10px;">Перевод (RU)</th>
                        <th style="padding: 10px;">Перевод (EN)</th>
                        <th style="padding: 10px;">Транскрипция</th>
                        <th style="padding: 10px;">Пример использования</th>
                    </tr>
                </thead>
                <tbody id="dict-table-body">
                    <?php foreach ($words as $w): ?>
                        <tr class="dict-row" style="border-bottom: 1px solid var(--border-color);">
                            <td style="padding: 10px; font-size: 1.05rem; color: var(--primary-shadow); font-weight: 800; white-space: nowrap;">
                                <?= e($w['word']) ?>
                                <button class="btn-duo btn-outline" style="padding: 2px 6px; font-size: 0.75rem; border-radius: 6px; margin-left: 4px;" onclick="speakText('<?= addslashes($w['word']) ?>', 'vladikish')" title="Прослушать произношение">
                                    🔊
                                </button>
                            </td>
                            <td style="padding: 10px;"><span class="badge-tag"><?= e($w['part_of_speech']) ?></span></td>
                            <td style="padding: 10px; font-weight: 700;"><?= e($w['translation_ru']) ?></td>
                            <td style="padding: 10px; color: var(--text-muted);"><?= e($w['translation_en']) ?></td>
                            <td style="padding: 10px;"><em>[<?= e($w['pronunciation'] ?? $w['word']) ?>]</em></td>
                            <td style="padding: 10px; font-size: 0.9rem; color: var(--text-muted);"><?= e($w['example_sentence']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Grammar Rules Section -->
    <div class="card-duo">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h3 style="font-weight: 800; font-size: 1.2rem;">
                📐 Правила грамматики и синтаксиса Vladikish
            </h3>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px;">
            <?php foreach ($grammarRules as $r): ?>
                <div class="card-duo" style="margin-bottom: 0; background: var(--bg-main); border: 2px solid var(--border-color); padding: 16px;">
                    <h4 style="font-weight: 800; color: var(--secondary); font-size: 1.05rem; margin-bottom: 8px;">
                        <?= e($r['rule_title']) ?>
                    </h4>
                    <p style="font-size: 0.95rem; margin-bottom: 12px; line-height: 1.5;">
                        <?= e($r['rule_description']) ?>
                    </p>
                    <?php if (!empty($r['rule_examples'])): ?>
                        <div style="background: var(--bg-card); padding: 8px 12px; border-radius: 8px; font-size: 0.85rem; border-left: 3px solid var(--primary);">
                            <strong>Пример:</strong> <?= e($r['rule_examples']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Quick search filter for dictionary words
    const searchInput = document.getElementById('dict-search-input');
    if (searchInput) {
        searchInput.addEventListener('input', (e) => {
            const query = e.target.value.toLowerCase().trim();
            document.querySelectorAll('.dict-row').forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(query) ? '' : 'none';
            });
        });
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
