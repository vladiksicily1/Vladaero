<?php
/**
 * HomeController — Главная страница
 */
namespace VladAero\Controllers;

use VladAero\Core\Controller;
use Throwable;

class HomeController extends Controller {

    public function index() {
        $db = $this->db;
        $prefix = $db->prefix();

        $featured_aircraft = [];
        $latest_photos = [];
        $latest_news = [];
        $stats = ['aircraft' => 0, 'airports' => 0, 'airlines' => 0, 'photos' => 0];

        try {
            // Optimized query: no RAND() temp tables, select only necessary display columns
            $featured_aircraft = $db->fetchAll(
                "SELECT id, name, slug, image, type_code, manufacturer_id, category, max_speed_knots, range_km, passenger_capacity 
                 FROM `{$prefix}aircraft` 
                 WHERE is_published = 1 
                 ORDER BY is_featured DESC, views_count DESC, id DESC 
                 LIMIT 6"
            );
        } catch (Throwable $e) {}

        try {
            $latest_photos = $db->fetchAll(
                "SELECT p.id, p.title, p.file_path, p.created_at, u.username 
                 FROM `{$prefix}photos` p
                 LEFT JOIN `{$prefix}users` u ON p.user_id = u.id
                 WHERE p.status = 'approved' 
                 ORDER BY p.id DESC 
                 LIMIT 8"
            );
        } catch (Throwable $e) {}

        try {
            $latest_news = $db->fetchAll(
                "SELECT n.id, n.title, n.slug, n.cover_image, n.excerpt, n.published_at, n.views, u.username as author_name 
                 FROM `{$prefix}news` n
                 LEFT JOIN `{$prefix}users` u ON n.created_by = u.id
                 WHERE n.status = 'published' 
                 ORDER BY n.is_pinned DESC, n.published_at DESC 
                 LIMIT 4"
            );
        } catch (Throwable $e) {}

        try {
            $stats = [
                'aircraft' => (int) $db->single("SELECT COUNT(id) FROM `{$prefix}aircraft` WHERE is_published = 1"),
                'airports' => (int) $db->single("SELECT COUNT(id) FROM `{$prefix}airports` WHERE is_published = 1"),
                'airlines' => (int) $db->single("SELECT COUNT(id) FROM `{$prefix}airlines` WHERE is_published = 1"),
                'photos'   => (int) $db->single("SELECT COUNT(id) FROM `{$prefix}photos` WHERE status = 'approved'"),
            ];
        } catch (Throwable $e) {}

        $this->view('pages.home.index', [
            'title'             => setting('site_name', 'VladAero') . ' — ' . setting('site_tagline', 'Авиационный портал'),
            'featured_aircraft' => $featured_aircraft ?: [],
            'latest_photos'     => $latest_photos ?: [],
            'latest_news'       => $latest_news ?: [],
            'stats'             => $stats,
        ]);
    }
}

