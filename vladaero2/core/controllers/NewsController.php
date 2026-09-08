<?php
namespace VladAero\Controllers;

use VladAero\Core\{Controller, Session, Database};

/**
 * NewsController - Новостной раздел
 */
class NewsController extends Controller
{
    public function index()
    {
        $prefix = $this->db->prefix();
        $perPage = 12;
        $page = max(1, (int)($this->query('page') ?? 1));

        $total = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM {$prefix}news WHERE status = 'published'"
        );
        $pagination = paginate($total, $perPage, $page);

        $articles = $this->db->fetchAll(
            "SELECT n.*, u.username as author_name, c.name as category_name
             FROM {$prefix}news n
             LEFT JOIN {$prefix}users u ON n.author_id = u.id
             LEFT JOIN {$prefix}news_categories c ON n.category_id = c.id
             WHERE n.status = 'published'
             ORDER BY n.is_pinned DESC, n.published_at DESC
             LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}"
        );

        $categories = $this->db->fetchAll(
            "SELECT c.*, (SELECT COUNT(*) FROM {$prefix}news WHERE category_id = c.id AND status = 'published') as count
             FROM {$prefix}news_categories c ORDER BY c.name"
        );

        $this->view('pages.news.index', compact('articles', 'categories', 'pagination', 'total'));
    }

    public function show(string $slug)
    {
        $prefix = $this->db->prefix();

        $article = $this->db->fetchOne(
            "SELECT n.*, u.username as author_name, c.name as category_name, c.slug as category_slug
             FROM {$prefix}news n
             LEFT JOIN {$prefix}users u ON n.author_id = u.id
             LEFT JOIN {$prefix}news_categories c ON n.category_id = c.id
             WHERE n.slug = :slug AND n.status = 'published'",
            ['slug' => $slug]
        );
        if (!$article) $this->abort(404, 'Статья не найдена');

        $this->db->query("UPDATE {$prefix}news SET views = views + 1 WHERE id = :id", ['id' => $article['id']]);

        $comments = $this->db->fetchAll(
            "SELECT c.*, u.username FROM {$prefix}comments c
             LEFT JOIN {$prefix}users u ON c.user_id = u.id
             WHERE c.article_id = :id ORDER BY c.created_at ASC",
            ['id' => $article['id']]
        );

        $this->view('pages.news.show', compact('article', 'comments'));
    }
}
