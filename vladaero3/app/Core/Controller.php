<?php
namespace App\Core;

abstract class Controller {
    protected string $locale = 'ru';
    protected array $sharedData = [];

    public function __construct(string $locale = 'ru') {
        $this->locale = $locale;
        Auth::startSession();

        // Check maintenance mode
        if ($this->isMaintenanceActive() && !Auth::hasRole('admin')) {
            $currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
            if (!str_starts_with($currentPath, '/admin') && !str_starts_with($currentPath, '/login') && !str_ends_with($currentPath, 'install.php')) {
                $this->renderMaintenance();
                exit;
            }
        }

        $this->sharedData = [
            'currentUser' => Auth::user(),
            'locale' => $this->locale,
            'csrfToken' => $this->getCsrfToken(),
            'siteName' => 'VladAero',
            'theme' => $_COOKIE['va_theme'] ?? 'dark',
            'soundEnabled' => ($_COOKIE['va_sound'] ?? '1') === '1'
        ];
    }

    protected function render(string $viewPath, array $data = [], string $layout = 'main'): void {
        $mergedData = array_merge($this->sharedData, $data);
        extract($mergedData);

        $viewFile = __DIR__ . '/../../views/' . $viewPath . '.php';
        if (!file_exists($viewFile)) {
            throw new \RuntimeException("View file not found: views/{$viewPath}.php");
        }

        ob_start();
        include $viewFile;
        $content = ob_get_clean();

        if ($layout === 'none') {
            echo $content;
            return;
        }

        $layoutFile = __DIR__ . '/../../views/layouts/' . $layout . '.php';
        if (!file_exists($layoutFile)) {
            throw new \RuntimeException("Layout file not found: views/layouts/{$layout}.php");
        }

        include $layoutFile;
    }

    protected function json(mixed $data, int $status = 200): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    protected function redirect(string $url): void {
        header("Location: {$url}");
        exit;
    }

    protected function requireAuth(string|array|null $roles = null): array {
        if (!Auth::check()) {
            $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '/';
            $this->redirect('/login');
        }

        $user = Auth::user();
        if ($roles !== null && !Auth::hasRole($roles)) {
            http_response_code(403);
            $this->render('errors/403', ['title' => 'Доступ ограничен'], 'main');
            exit;
        }

        return $user;
    }

    protected function getCsrfToken(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    protected function validateCsrf(): bool {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (empty($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            http_response_code(419);
            $this->json(['error' => 'CSRF token mismatch or expired. Please refresh the page.'], 419);
            return false;
        }
        return true;
    }

    private function isMaintenanceActive(): bool {
        if (!Database::isConfigured() || !Database::isInstalled()) {
            return false;
        }
        try {
            $val = Database::fetchValue(
                "SELECT `value_text` FROM `{prefix}settings` WHERE `key_name` = 'maintenance_mode'"
            );
            return $val === '1';
        } catch (\Exception $e) {
            return false;
        }
    }

    private function renderMaintenance(): void {
        http_response_code(503);
        $this->render('errors/maintenance', [
            'title' => 'Регламентные технические работы',
            'metaDesc' => 'Техническое обслуживание бортовых систем VladAero'
        ], 'none');
    }

    public function notFound(): void {
        http_response_code(404);
        $this->render('errors/404', [
            'title' => '404 - Борт не найден на радаре',
            'metaDesc' => 'Запрошенная страница не обнаружена в воздушном пространстве VladAero'
        ], 'main');
    }
}
