<?php
/**
 * ShibaLingo - Interactive Word Search & Crossword Puzzle (Feature #59)
 */

$pageTitle = 'Кроссворд & Поиск слов';
$db = getDb();
$driver = Database::getDriver();

// Fetch random 6 words of length 3..7
try {
    $rows = $db->query("SELECT word, translation_ru FROM conlang_dictionary WHERE length(word) BETWEEN 3 AND 7 ORDER BY " . ($driver === 'sqlite' ? 'RANDOM()' : 'RAND()') . " LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $rows = [];
}

if (empty($rows)) {
    $rows = [
        ['word' => 'AERO', 'translation_ru' => 'небо'],
        ['word' => 'ZORA', 'translation_ru' => 'день'],
        ['word' => 'MIRA', 'translation_ru' => 'привет'],
        ['word' => 'NOX', 'translation_ru' => 'ночь'],
        ['word' => 'BONU', 'translation_ru' => 'хороший'],
        ['word' => 'BARKA', 'translation_ru' => 'собака']
    ];
}

$wordsToFind = array_map(function($r) { return strtoupper(trim($r['word'])); }, $rows);
$gridSize = 8;
$grid = array_fill(0, $gridSize, array_fill(0, $gridSize, ''));

// Place words into grid (horizontally or vertically)
$placedWords = [];
foreach ($wordsToFind as $w) {
    $len = strlen($w);
    $placed = false;
    $attempts = 0;
    while (!$placed && $attempts < 50) {
        $attempts++;
        $dir = rand(0, 1); // 0 = horizontal, 1 = vertical
        if ($dir === 0) {
            $row = rand(0, $gridSize - 1);
            $col = rand(0, $gridSize - $len);
            // Check collision
            $canPlace = true;
            for ($i = 0; $i < $len; $i++) {
                if ($grid[$row][$col + $i] !== '' && $grid[$row][$col + $i] !== $w[$i]) {
                    $canPlace = false;
                    break;
                }
            }
            if ($canPlace) {
                for ($i = 0; $i < $len; $i++) {
                    $grid[$row][$col + $i] = $w[$i];
                }
                $placed = true;
                $placedWords[] = $w;
            }
        } else {
            $row = rand(0, $gridSize - $len);
            $col = rand(0, $gridSize - 1);
            $canPlace = true;
            for ($i = 0; $i < $len; $i++) {
                if ($grid[$row + $i][$col] !== '' && $grid[$row + $i][$col] !== $w[$i]) {
                    $canPlace = false;
                    break;
                }
            }
            if ($canPlace) {
                for ($i = 0; $i < $len; $i++) {
                    $grid[$row + $i][$col] = $w[$i];
                }
                $placed = true;
                $placedWords[] = $w;
            }
        }
    }
}

// Fill remaining empty cells with random letters
$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
for ($r = 0; $r < $gridSize; $r++) {
    for ($c = 0; $c < $gridSize; $c++) {
        if ($grid[$r][$c] === '') {
            $grid[$r][$c] = $alphabet[rand(0, strlen($alphabet) - 1)];
        }
    }
}

$wordsToFind = !empty($placedWords) ? $placedWords : ['AERO', 'ZORA', 'MIRA', 'NOX', 'BONU', 'BARKA'];
require_once __DIR__ . '/includes/header.php';
?>

<div style="max-width: 750px; margin: 0 auto; text-align: center;">
    <div style="margin-bottom: 24px;">
        <h1 style="font-size: 1.8rem; font-weight: 900; display: flex; align-items: center; justify-content: center; gap: 12px;">
            <span>🧩</span> Филворд & Поиск слов Vladikish
        </h1>
        <p style="color: var(--text-muted);">
            Найдите спрятанные слова в буквенной сетке, кликая по буквам! (Каждый раз новая головоломка)
        </p>
    </div>

    <!-- Words Target List -->
    <div class="card-duo anim-bounce" style="margin-bottom: 20px;">
        <div style="font-weight: 800; font-size: 1rem; margin-bottom: 10px;">Слова для поиска:</div>
        <div style="display: flex; justify-content: center; gap: 10px; flex-wrap: wrap;" id="target-words-box">
            <?php foreach ($wordsToFind as $w): ?>
                <span class="badge-tag target-word" data-word="<?= e($w) ?>" style="font-size: 1rem; padding: 6px 14px; font-weight: 800; border: 2px solid var(--border-color);">
                    <?= e($w) ?>
                </span>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Letter Grid -->
    <div class="card-duo" style="display: inline-block; padding: 24px;">
        <div id="crossword-grid" style="display: grid; grid-template-columns: repeat(8, 48px); gap: 6px;"></div>
        <div style="margin-top: 16px;">
            <button class="btn-duo btn-outline" onclick="location.reload()" style="font-size: 0.9rem; padding: 8px 16px;">
                🔄 Сгенерировать новый филворд
            </button>
        </div>
    </div>
</div>

<script>
const gridLetters = <?= json_encode($grid) ?>;
const totalWordsCount = <?= count($wordsToFind) ?>;
let selectedLetters = [];
let foundWords = 0;

document.addEventListener('DOMContentLoaded', () => {
    const gridContainer = document.getElementById('crossword-grid');
    gridLetters.forEach((row, rIdx) => {
        row.forEach((char, cIdx) => {
            const tile = document.createElement('div');
            tile.className = 'word-tile';
            tile.style.width = '48px';
            tile.style.height = '48px';
            tile.style.display = 'flex';
            tile.style.alignItems = 'center';
            tile.style.justifyContent = 'center';
            tile.style.padding = '0';
            tile.style.fontSize = '1.2rem';
            tile.style.cursor = 'pointer';
            tile.textContent = char;
            tile.dataset.char = char;

            tile.onclick = () => {
                SoundEngine.play('click');
                tile.classList.toggle('selected');
                checkWordSelection();
            };

            gridContainer.appendChild(tile);
        });
    });
});

function checkWordSelection() {
    const selected = Array.from(document.querySelectorAll('#crossword-grid .word-tile.selected'));
    const word = selected.map(t => t.dataset.char).join('');
    
    document.querySelectorAll('.target-word').forEach(tw => {
        if (tw.dataset.word === word && !tw.classList.contains('found')) {
            tw.classList.add('found');
            tw.style.background = 'var(--primary-light)';
            tw.style.color = 'var(--primary-shadow)';
            tw.style.borderColor = 'var(--primary)';
            
            selected.forEach(t => {
                t.classList.remove('selected');
                t.style.background = 'var(--primary-light)';
                t.style.borderColor = 'var(--primary)';
            });

            SoundEngine.play('correct');
            triggerConfetti();
            foundWords++;

            if (foundWords >= totalWordsCount) {
                setTimeout(() => {
                    SoundEngine.play('win');
                    alert('🎉 Поздравляем! Вы нашли все спрятанные слова Vladikish! +40 XP');
                }, 500);
            }
        }
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
