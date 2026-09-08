<?php
namespace VladAero\Controllers;

use VladAero\Core\{Controller, Session, Database};

/**
 * ArticleController — Блог и статьи пользователей
 */
class ArticleController extends Controller
{
    public function index()
    {
        $prefix = $this->db->prefix();
        $perPage = 12;
        $page = max(1, (int)($this->query('page') ?? 1));
        $category = $this->query('category') ?? '';

        $where = ["n.status = 'published'", "n.type = 'article'"];
        $params = [];

        if ($category) {
            $where[] = "n.category_id = :cat";
            $params['cat'] = $category;
        }

        $whereStr = 'WHERE ' . implode(' AND ', $where);

        $total = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM {$prefix}news n {$whereStr}", $params
        );
        $pagination = paginate($total, $perPage, $page);

        $articles = $this->db->fetchAll(
            "SELECT n.*, u.username as author_name, u.display_name as author_display_name,
                    c.name as category_name, c.slug as category_slug
             FROM {$prefix}news n
             LEFT JOIN {$prefix}users u ON n.author_id = u.id
             LEFT JOIN {$prefix}news_categories c ON n.category_id = c.id
             {$whereStr}
             ORDER BY n.is_pinned DESC, n.published_at DESC
             LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
            $params
        );

        $categories = $this->db->fetchAll(
            "SELECT c.*, (SELECT COUNT(*) FROM {$prefix}news WHERE category_id = c.id AND status = 'published' AND type = 'article') as count
             FROM {$prefix}news_categories c ORDER BY c.name"
        );

        $topAuthors = $this->db->fetchAll(
            "SELECT u.username, u.display_name, u.avatar_url, COUNT(n.id) as article_count
             FROM {$prefix}users u
             JOIN {$prefix}news n ON n.author_id = u.id
             WHERE n.status = 'published' AND n.type = 'article'
             GROUP BY u.id ORDER BY article_count DESC LIMIT 5"
        );

        $this->view('pages.articles.index', compact('articles', 'categories', 'topAuthors', 'pagination', 'total'));
    }

    public function show(string $slug)
    {
        $prefix = $this->db->prefix();

        $article = $this->db->fetchOne(
            "SELECT n.*, u.username as author_name, u.display_name as author_display_name,
                    u.avatar_url as author_avatar, c.name as category_name, c.slug as category_slug
             FROM {$prefix}news n
             LEFT JOIN {$prefix}users u ON n.author_id = u.id
             LEFT JOIN {$prefix}news_categories c ON n.category_id = c.id
             WHERE n.slug = :slug AND n.status = 'published'",
            ['slug' => $slug]
        );
        if (!$article) $this->abort(404, 'Статья не найдена');

        $this->db->query(
            "UPDATE {$prefix}news SET views = views + 1 WHERE id = :id",
            ['id' => $article['id']]
        );

        $comments = $this->db->fetchAll(
            "SELECT c.*, u.username, u.display_name, u.avatar_url
             FROM {$prefix}comments c
             LEFT JOIN {$prefix}users u ON c.user_id = u.id
             WHERE c.article_id = :id ORDER BY c.created_at ASC",
            ['id' => $article['id']]
        );

        $related = $this->db->fetchAll(
            "SELECT n.slug, n.title, n.cover_image, n.published_at
             FROM {$prefix}news n
             WHERE n.id != :id AND n.status = 'published' AND n.type = 'article'
             AND (n.category_id = :cat OR n.author_id = :author)
             ORDER BY n.published_at DESC, n.id DESC LIMIT 3",
            ['id' => $article['id'], 'cat' => $article['category_id'] ?? 0, 'author' => $article['author_id'] ?? 0]
        );

        $this->view('pages.articles.show', compact('article', 'comments', 'related'));
    }

    public function createForm()
    {
        $user = $this->requireAuth();
        $prefix = $this->db->prefix();
        $categories = $this->db->fetchAll("SELECT * FROM {$prefix}news_categories ORDER BY name");
        $this->view('pages.articles.form', ['article' => null, 'categories' => $categories]);
    }

    public function create()
    {
        $user = $this->requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { $this->redirect('/articles/new'); }
        $this->verifyCsrf();

        $prefix = $this->db->prefix();
        $title = $this->input('title') ?? '';
        $content = $_POST['content'] ?? '';
        $excerpt = $this->input('excerpt') ?? mb_substr(strip_tags($content), 0, 200);

        $articleId = $this->db->insert('news', [
            'title' => $title,
            'slug' => slugify($title),
            'excerpt' => $excerpt,
            'content' => $content,
            'type' => 'article',
            'category_id' => (int)($this->input('category_id') ?? 0) ?: null,
            'author_id' => $user['id'],
            'status' => 'published',
            'cover_image' => $this->input('cover_image') ?? '',
            'published_at' => date('Y-m-d H:i:s'),
        ]);

        $this->redirect('/articles/' . slugify($title));
    }

    public function editForm(string $id)
    {
        $user = $this->requireAuth();
        $prefix = $this->db->prefix();
        $article = $this->db->fetchOne(
            "SELECT * FROM {$prefix}news WHERE id = :id AND author_id = :uid",
            ['id' => (int)$id, 'uid' => $user['id']]
        );
        if (!$article) $this->abort(404);
        $categories = $this->db->fetchAll("SELECT * FROM {$prefix}news_categories ORDER BY name");
        $this->view('pages.articles.form', compact('article', 'categories'));
    }

    public function update(string $id)
    {
        $user = $this->requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { $this->redirect('/articles'); }
        $this->verifyCsrf();

        $prefix = $this->db->prefix();
        $this->db->query(
            "UPDATE {$prefix}news SET title = :title, slug = :slug, excerpt = :excerpt, content = :content,
             category_id = :cat, cover_image = :cover
             WHERE id = :id AND author_id = :uid",
            [
                'title' => $this->input('title') ?? '',
                'slug' => slugify($this->input('title') ?? ''),
                'excerpt' => $this->input('excerpt') ?? '',
                'content' => $_POST['content'] ?? '',
                'cat' => (int)($this->input('category_id') ?? 0) ?: null,
                'cover' => $this->input('cover_image') ?? '',
                'id' => (int)$id,
                'uid' => $user['id'],
            ]
        );
        $this->redirect('/articles/' . slugify($this->input('title') ?? ''));
    }
}
