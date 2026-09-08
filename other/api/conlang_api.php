<?php
/**
 * Conlang dictionary and grammar management API
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$db = getDb();

if ($action === 'add_word') {
    $word = trim($input['word'] ?? '');
    $pos = trim($input['part_of_speech'] ?? 'noun');
    $ru = trim($input['translation_ru'] ?? '');
    $en = trim($input['translation_en'] ?? '');
    $it = trim($input['translation_it'] ?? '');
    $pron = trim($input['pronunciation'] ?? '');
    $ex = trim($input['example_sentence'] ?? '');

    if (empty($word) || empty($ru)) {
        echo json_encode(['success' => false, 'error' => 'Заполните слово и перевод!']);
        exit;
    }

    try {
        $stmt = $db->prepare("INSERT INTO conlang_dictionary (word, part_of_speech, translation_ru, translation_en, translation_it, pronunciation, example_sentence, created_by)
                              VALUES (:w, :pos, :ru, :en, :it, :pron, :ex, 'manual')");
        $stmt->execute([
            'w' => $word,
            'pos' => $pos,
            'ru' => $ru,
            'en' => $en,
            'it' => $it,
            'pron' => $pron,
            'ex' => $ex
        ]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'delete_word') {
    $id = (int)($input['id'] ?? 0);
    $stmt = $db->prepare("DELETE FROM conlang_dictionary WHERE id = :id");
    $stmt->execute(['id' => $id]);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'add_grammar') {
    $title = trim($input['rule_title'] ?? '');
    $desc = trim($input['rule_description'] ?? '');
    $ex = trim($input['rule_examples'] ?? '');

    if (empty($title) || empty($desc)) {
        echo json_encode(['success' => false, 'error' => 'Заполните название и описание правила']);
        exit;
    }

    $stmt = $db->prepare("INSERT INTO conlang_grammar (rule_title, rule_description, rule_examples) VALUES (:t, :d, :e)");
    $stmt->execute(['t' => $title, 'd' => $desc, 'e' => $ex]);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid action']);
