<?php
namespace VladAero\Controllers\Admin;

use VladAero\Core\{Controller, Session, Database};

/**
 * NewsCrudController - CRUD новостей в админке
 */
class NewsCrudController extends Controller
{
    public function index()
    {
        $this->requireAdmin();
        $prefix = $this->db->prefix();
        $articles = $this->db->fetchAll(
            "SELECT n.*, u.username as author_name, c.name as category_name
             FROM {$prefix}news n LEFT JOIN {$prefix}users u ON n.author_id = u.id
             LEFT JOIN {$prefix}news_categories c ON n.category_id = c.id
             ORDER BY n.created_at DESC"
        );
        $this->view('admin.news.index', compact('articles'));
    }

    public function create()
    {
        $this->requireAdmin();
        $categories = $this->db->fetchAll("SELECT * FROM {$this->db->prefix()}news_categories ORDER BY name");
        $this->view('admin.news.form', ['article' => null, 'categories' => $categories]);
    }

    public function edit(string $id)
    {
        $this->requireAdmin();
        $prefix = $this->db->prefix();
        $article = $this->db->fetchOne("SELECT * FROM {$prefix}news WHERE id = :id", ['id' => (int)$id]);
        if (!$article) $this->abort(404);
        $categories = $this->db->fetchAll("SELECT * FROM {$prefix}news_categories ORDER BY name");
        $this->view('admin.news.form', compact('article', 'categories'));
    }

    public function save()
    {
        $this->requireAdmin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { $this->redirect('/admin/news'); }
        $this->verifyCsrf();

        $prefix = $this->db->prefix();
        $id = (int)($this->input('id') ?? 0);
        $data = [
            'title' => $this->input('title') ?? '',
            'slug' => $this->slugify($this->input('title') ?? ''),
            'excerpt' => $this->input('excerpt') ?? '',
            'content' => $_POST['content'] ?? '',
            'category_id' => (int)($this->input('category_id') ?? 0) ?: null,
            'author_id' => Session::getAuth()['id'],
            'status' => $this->input('status') ?? 'draft',
            'is_pinned' => (int)($this->input('is_pinned') ?? 0),
            'cover_image' => $this->input('cover_image') ?? '',
        ];
        if ($data['status'] === 'published' && empty($data['published_at'] ?? null)) {
            $data['published_at'] = date('Y-m-d H:i:s');
        }

        if ($id) {
            $sets = [];
            $params = ['id' => $id];
            foreach ($data as $k => $v) { $sets[] = "{$k} = :{$k}"; $params[$k] = $v; }
            $this->db->query("UPDATE {$prefix}news SET " . implode(', ', $sets) . " WHERE id = :id", $params);
        } else {
            $this->db->insert('news', $data);
        }
        $this->redirect('/admin/news');
    }

    public function delete(string $id)
    {
        $this->requireAdmin();
        $this->db->query("DELETE FROM {$this->db->prefix()}news WHERE id = :id", ['id' => (int)$id]);
        $this->redirect('/admin/news');
    }

    private function requireAdmin() { $this->requireRole('admin'); }
    private function slugify(string $text): string {
        return preg_replace('/-+/', '-', preg_replace('/[^a-z0-9-]/', '-', strtolower(trim($text))) . '');
    }
}
