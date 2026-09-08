<?php
namespace VladAero\Controllers\Admin;

use VladAero\Core\{Controller, Session, Database};

/**
 * GlossaryCrudController — CRUD глоссария в админке
 */
class GlossaryCrudController extends Controller
{
    public function index()
    {
        $this->requireAdmin();
        $prefix = $this->db->prefix();
        $terms = $this->db->fetchAll(
            "SELECT * FROM {$prefix}glossary ORDER BY term"
        );
        $this->view('admin.glossary.index', compact('terms'));
    }

    public function create()
    {
        $this->requireAdmin();
        $this->view('admin.glossary.form', ['term' => null]);
    }

    public function edit(string $id)
    {
        $this->requireAdmin();
        $term = $this->db->fetchOne("SELECT * FROM {$this->db->prefix()}glossary WHERE id = :id", ['id' => (int)$id]);
        if (!$term) $this->abort(404);
        $this->view('admin.glossary.form', compact('term'));
    }

    public function save()
    {
        $this->requireAdmin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { $this->redirect('/admin/glossary'); }
        $this->verifyCsrf();

        $prefix = $this->db->prefix();
        $id = (int)($this->input('id') ?? 0);
        $termText = $this->input('term') ?? '';
        $data = [
            'term' => $termText,
            'slug' => slugify($termText),
            'definition' => $_POST['definition'] ?? '',
            'category' => $this->input('category') ?? '',
            'related_terms' => $this->input('related_terms') ?? '',
        ];

        if ($id) {
            $sets = []; $params = ['id' => $id];
            foreach ($data as $k => $v) { $sets[] = "{$k} = :{$k}"; $params[$k] = $v; }
            $this->db->query("UPDATE {$prefix}glossary SET " . implode(', ', $sets) . " WHERE id = :id", $params);
        } else {
            $this->db->insert('glossary', $data);
        }
        $this->redirect('/admin/glossary');
    }

    public function delete(string $id)
    {
        $this->requireAdmin();
        $this->db->query("DELETE FROM {$this->db->prefix()}glossary WHERE id = :id", ['id' => (int)$id]);
        $this->redirect('/admin/glossary');
    }

    public function generate()
    {
        $this->requireAdmin();
        $this->view('admin.glossary.generate');
    }

    public function generateRun()
    {
        $this->requireAdmin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { $this->redirect('/admin/glossary'); }

        $topic = $this->input('topic') ?? '';
        $apiKey = $this->config['ai']['api_key'] ?? '';
        if (!$apiKey) {
            Session::setFlash('error', 'Для генерации нужен AI API ключ');
            $this->redirect('/admin/glossary/generate');
        }

        $baseUrl = ($this->config['ai']['base_url'] ?? 'https://api.openai.com/v1') . '/chat/completions';
        $model = $this->config['ai']['model_id'] ?? 'gpt-4o-mini';

        $prompt = "Сгенерируй 10 авиационных терминов по теме «{$topic}». Формат JSON: [{\"term\":\"...\",\"definition\":\"...\",\"category\":\"...\",\"slug\":\"...\"}]. Только JSON, без markdown.";

        $ch = curl_init($baseUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $model, 'messages' => [['role' => 'user', 'content' => $prompt]], 'temperature' => 0.7, 'max_tokens' => 3000,
            ]),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        $content = $data['choices'][0]['message']['content'] ?? '';
        $content = preg_replace('/```json\s*/', '', $content);
        $content = preg_replace('/```\s*/', '', $content);
        $terms = json_decode(trim($content), true);

        $prefix = $this->db->prefix();
        $count = 0;
        if (is_array($terms)) {
            foreach ($terms as $t) {
                if (empty($t['term']) || empty($t['definition'])) continue;
                $exists = $this->db->fetchOne("SELECT id FROM {$prefix}glossary WHERE term = :t", ['t' => $t['term']]);
                if (!$exists) {
                    $this->db->insert('glossary', [
                        'term' => $t['term'],
                        'slug' => $t['slug'] ?? slugify($t['term']),
                        'definition' => $t['definition'],
                        'category' => $t['category'] ?? $topic,
                    ]);
                    $count++;
                }
            }
        }

        Session::setFlash('success', "Добавлено {$count} терминов по теме «{$topic}»");
        $this->redirect('/admin/glossary');
    }

    private function requireAdmin() { $this->requireRole('admin'); }
}
