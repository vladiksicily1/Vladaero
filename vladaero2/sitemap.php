<?php
/**
 * Sitemap — XML sitemap generator
 */
define('PROJECT_ROOT', __DIR__);
require_once PROJECT_ROOT . '/config/app.php';
$GLOBALS['config'] = $config;
require_once PROJECT_ROOT . '/core/helpers/functions.php';
require_once PROJECT_ROOT . '/core/autoload.php';

use VladAero\Core\{Database, Session};

$installedFile = PROJECT_ROOT . '/config/installed.php';
if (file_exists($installedFile)) {
    $installed = require $installedFile;
    $config = array_merge($config, $installed);
    $GLOBALS['config'] = $config;
    Database::init($config['db']);
}

$db = Database::getInstance();
$prefix = $db->prefix();
$base = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'vladaero.ru');

header('Content-Type: application/xml; charset=utf-8');
header('X-Robots-Tag: noindex');

echo '<?xml version="1.0" encoding="UTF-8"?>';
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

// Static pages
$static = ['', '/aircraft', '/airports', '/airlines', '/photos', '/news', '/quizzes', '/calculators', '/radar', '/events'];
foreach ($static as $path) {
    echo "<url><loc>{$base}{$path}</loc><changefreq>daily</changefreq><priority>0.8</priority></url>";
}

// Aircraft
foreach ($db->fetchAll("SELECT slug, updated_at FROM {$prefix}aircraft") as $a) {
    $lastmod = date('Y-m-d', strtotime($a['updated_at']));
    echo "<url><loc>{$base}/aircraft/{$a['slug']}</loc><lastmod>{$lastmod}</lastmod><changefreq>weekly</changefreq><priority>0.7</priority></url>";
}

// Airports
foreach ($db->fetchAll("SELECT icao_code, updated_at FROM {$prefix}airports") as $a) {
    $lastmod = date('Y-m-d', strtotime($a['updated_at']));
    echo "<url><loc>{$base}/airports/{$a['icao_code']}</loc><lastmod>{$lastmod}</lastmod><changefreq>weekly</changefreq><priority>0.6</priority></url>";
}

// Airlines
foreach ($db->fetchAll("SELECT slug, updated_at FROM {$prefix}airlines") as $a) {
    $lastmod = date('Y-m-d', strtotime($a['updated_at']));
    echo "<url><loc>{$base}/airlines/{$a['slug']}</loc><lastmod>{$lastmod}</lastmod><changefreq>weekly</changefreq><priority>0.6</priority></url>";
}

// News
foreach ($db->fetchAll("SELECT slug, published_at FROM {$prefix}news WHERE status = 'published'") as $n) {
    $lastmod = date('Y-m-d', strtotime($n['published_at']));
    echo "<url><loc>{$base}/news/{$n['slug']}</loc><lastmod>{$lastmod}</lastmod><changefreq>monthly</changefreq><priority>0.5</priority></url>";
}

// Photos
foreach ($db->fetchAll("SELECT id, created_at FROM {$prefix}photos WHERE status = 'approved' ORDER BY created_at DESC LIMIT 1000") as $p) {
    $lastmod = date('Y-m-d', strtotime($p['created_at']));
    echo "<url><loc>{$base}/photos/{$p['id']}</loc><lastmod>{$lastmod}</lastmod><changefreq>monthly</changefreq><priority>0.4</priority></url>";
}

echo '</urlset>';
