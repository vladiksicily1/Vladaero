<?php
namespace VladAero\Controllers\Admin;

use VladAero\Core\{Controller, Session, Database};
use Throwable;

/**
 * SiteSettingsController — управление настройками, брендингом и медиа ресурсами сайта
 */
class SiteSettingsController extends Controller
{
    public function index()
    {
        $this->requireAdmin();

        $prefix = $this->db->getPrefix();
        $dbSettings = [];
        try {
            $rows = $this->db->fetchAll("SELECT setting_key, setting_value, setting_group FROM `{$prefix}settings`");
            foreach ($rows as $r) {
                $dbSettings[$r['setting_key']] = $r['setting_value'];
            }
        } catch (Throwable $e) {}

        $installedFile = PROJECT_ROOT . '/config/installed.php';
        $configFile = file_exists($installedFile) ? require $installedFile : [];

        $settings = array_merge($configFile, $dbSettings);

        $this->view('admin.site-settings', [
            'settings' => $settings,
            'title'    => 'Настройки сайта и брендинга | VladAero Admin',
        ]);
    }

    public function update()
    {
        $this->requireAdmin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect(url('/admin/settings'));
        }
        $this->verifyCsrf();

        $prefix = $this->db->getPrefix();
        $uploadDir = PROJECT_ROOT . '/public/uploads/branding';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0755, true);
        }

        // ── Handle Media Uploads ──────────────────────────────
        $mediaFields = [
            'site_logo'       => ['name' => 'logo', 'allowed' => ['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml']],
            'hero_background' => ['name' => 'hero_bg', 'allowed' => ['image/png', 'image/jpeg', 'image/webp']],
            'site_favicon'    => ['name' => 'favicon', 'allowed' => ['image/png', 'image/x-icon', 'image/vnd.microsoft.icon', 'image/svg+xml']],
            'site_og_image'   => ['name' => 'og_image', 'allowed' => ['image/png', 'image/jpeg', 'image/webp']],
        ];

        $uploadedMedia = [];
        foreach ($mediaFields as $fieldKey => $info) {
            if (!empty($_FILES[$fieldKey]['tmp_name']) && $_FILES[$fieldKey]['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES[$fieldKey];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);

                if (in_array($mime, $info['allowed'], true)) {
                    $ext = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'png';
                    $targetFilename = $info['name'] . '_' . time() . '.' . strtolower($ext);
                    $targetPath = $uploadDir . '/' . $targetFilename;

                    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                        $uploadedMedia[$fieldKey] = 'public/uploads/branding/' . $targetFilename;
                    }
                }
            }
        }

        // ── Text & Config Settings ────────────────────────────
        $textSettings = [
            'site_name'         => ['group' => 'general', 'val' => $this->input('site_name', 'VladAero')],
            'site_name_html'    => ['group' => 'general', 'val' => $_POST['site_name_html'] ?? 'Vlad<span class="text-accent">Aero</span>'],
            'site_tagline'      => ['group' => 'general', 'val' => $this->input('site_tagline', 'Авиационный портал')],
            'site_description'  => ['group' => 'general', 'val' => $this->input('site_description', '')],
            'hero_title'        => ['group' => 'general', 'val' => $_POST['hero_title'] ?? 'V<span class="text-accent">A</span> VladAero'],
            'hero_subtitle'     => ['group' => 'general', 'val' => $this->input('hero_subtitle', '')],
            'footer_about'      => ['group' => 'general', 'val' => $this->input('footer_about', '')],
            'telegram_link'     => ['group' => 'general', 'val' => $this->input('telegram_link', 'https://t.me/vladaero')],
            'default_theme'     => ['group' => 'appearance', 'val' => $this->input('default_theme', 'dark')],
            'custom_css'        => ['group' => 'appearance', 'val' => $_POST['custom_css'] ?? ''],
            'maintenance_mode'  => ['group' => 'system', 'val' => !empty($_POST['maintenance_mode']) ? '1' : '0'],
            'allow_registration'=> ['group' => 'system', 'val' => !empty($_POST['allow_registration']) ? '1' : '0'],
        ];

        // Add uploaded media to settings
        foreach ($uploadedMedia as $mKey => $mPath) {
            $textSettings[$mKey] = ['group' => 'branding', 'val' => $mPath];
        }

        // ── Save to DB `{prefix}settings` ─────────────────────
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO `{$prefix}settings` (setting_key, setting_value, setting_group) 
                 VALUES (?, ?, ?) 
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_group = VALUES(setting_group)"
            );
            foreach ($textSettings as $key => $data) {
                $stmt->execute([$key, $data['val'], $data['group']]);
            }
        } catch (Throwable $e) {
            Session::setFlash('error', 'Ошибка сохранения в БД: ' . $e->getMessage());
            $this->redirect(url('/admin/settings'));
        }

        // ── Sync with installed.php ───────────────────────────
        $installedFile = PROJECT_ROOT . '/config/installed.php';
        if (file_exists($installedFile)) {
            $current = require $installedFile;
            $current['maintenance'] = !empty($_POST['maintenance_mode']);
            $current['site_name'] = $textSettings['site_name']['val'];
            $current['default_theme'] = $textSettings['default_theme']['val'];
            $content = "<?php\nreturn " . var_export($current, true) . ";\n";
            @file_put_contents($installedFile, $content);
            $GLOBALS['config'] = array_merge($GLOBALS['config'], $current);
        }

        // Clear settings cache if any
        $cache = new \VladAero\Core\Cache();
        $cache->delete('site_settings');

        Session::setFlash('success', '✅ Настройки и медиа-ресурсы сайта успешно сохранены!');
        $this->redirect(url('/admin/settings'));
    }

    public function resetMedia()
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $prefix = $this->db->getPrefix();
        $target = $this->input('target');

        $defaults = [
            'site_logo'       => 'assets/images/logo.png',
            'hero_background' => '',
            'site_favicon'    => 'assets/images/favicon-32.png',
            'site_og_image'   => 'assets/images/og-default.png',
        ];

        if (isset($defaults[$target])) {
            $this->db->query(
                "INSERT INTO `{$prefix}settings` (setting_key, setting_value, setting_group) 
                 VALUES (?, ?, 'branding') 
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
                [$target, $defaults[$target]]
            );
            Session::setFlash('success', 'Элемент сброшен на значение по умолчанию');
        }

        $this->redirect(url('/admin/settings'));
    }

    public function clearCache()
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $cacheDir = PROJECT_ROOT . '/storage/cache/';
        $count = 0;
        if (is_dir($cacheDir)) {
            foreach (glob($cacheDir . '*') as $file) {
                if (is_file($file)) {
                    @unlink($file);
                    $count++;
                }
            }
        }
        Session::setFlash('success', "🧹 Очищено {$count} файл(ов) кэша");
        $this->redirect(url('/admin/settings'));
    }

    public function optimizeDb()
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $prefix = $this->db->getPrefix();
        $tables = $this->db->fetchAll("SHOW TABLES LIKE '{$prefix}%'");
        foreach ($tables as $t) {
            $tableName = reset($t);
            $this->db->query("OPTIMIZE TABLE `{$tableName}`");
        }
        Session::setFlash('success', '⚡ Все таблицы базы данных оптимизированы');
        $this->redirect(url('/admin/settings'));
    }

    private function requireAdmin()
    {
        $this->requireRole('admin');
    }
}
