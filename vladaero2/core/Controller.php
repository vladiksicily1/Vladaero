<?php
namespace VladAero\Core;

/**
 * VladAero — Base Controller
 */
class Controller
{
    protected Database $db;
    protected array $config;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->config = $GLOBALS['config'] ?? [];
    }

    /**
     * Render a view with optional data.
     */
    protected function view(string $view, array $data = [], ?int $statusCode = 200): void
    {
        http_response_code($statusCode);
        $data['config'] = $this->config;
        $data['auth'] = Session::getAuth();
        $data['flash'] = Session::flash();
        $data['current_url'] = $_SERVER['REQUEST_URI'] ?? '/';

        // Extract data into variables for the view
        extract($data, EXTR_SKIP);

        $viewFile = PROJECT_ROOT . '/core/views/' . str_replace('.', '/', $view) . '.php';
        if (!file_exists($viewFile)) {
            $this->view('errors.404');
            return;
        }

        ob_start();
        include $viewFile;
        $content = ob_get_clean();

        // Wrap in layout unless it's already a partial
        if (!str_starts_with($view, 'partials/')) {
            $layoutFile = PROJECT_ROOT . '/core/views/layouts/main.php';
            if (file_exists($layoutFile)) {
                ob_start();
                include $layoutFile;
                echo ob_get_clean();
            } else {
                echo $content;
            }
        } else {
            echo $content;
        }
    }

    /**
     * Render a partial (no layout).
     */
    protected function partial(string $view, array $data = []): void
    {
        $data['config'] = $this->config;
        $data['auth'] = Session::getAuth();
        extract($data, EXTR_SKIP);

        $viewFile = PROJECT_ROOT . '/core/views/' . str_replace('.', '/', $view) . '.php';
        if (file_exists($viewFile)) {
            include $viewFile;
        }
    }

    /**
     * Return JSON response.
     */
    protected function json(mixed $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Redirect to URL (subfolder aware).
     */
    protected function redirect(string $url, int $statusCode = 302): void
    {
        http_response_code($statusCode);
        $target = (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) ? $url : url($url);
        header("Location: {$target}");
        exit;
    }

    /**
     * Abort with an error page.
     */
    protected function abort(int $code, string $message = ''): void
    {
        $statuses = [
            404 => 'Страница не найдена',
            403 => 'Доступ запрещён',
            401 => 'Необходима авторизация',
            500 => 'Внутренняя ошибка сервера',
            503 => 'Сервис временно недоступен',
        ];
        $message = $message ?: ($statuses[$code] ?? 'Ошибка');
        http_response_code($code);
        $this->view('errors.error', ['code' => $code, 'message' => $message]);
        exit;
    }

    /**
     * Get POST data with optional sanitization.
     */
    protected function input(string $key, mixed $default = null): mixed
    {
        $value = $_POST[$key] ?? $default;
        if (is_string($value)) {
            return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
        }
        return $value;
    }

    /**
     * Get query parameter.
     */
    protected function query(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    /**
     * Get all input data.
     */
    protected function all(): array
    {
        return $_POST;
    }

    /**
     * Validate required fields.
     */
    protected function validate(array $data, array $rules): ?array
    {
        $errors = [];
        foreach ($rules as $field => $rule) {
            $value = $data[$field] ?? null;

            if (str_contains($rule, 'required') && empty($value) && $value !== '0') {
                $errors[$field] = "Поле {$field} обязательно";
            }
            if (str_contains($rule, 'email') && !empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[$field] = "Некорректный email";
            }
            if (preg_match('/min:(\d+)/', $rule, $m) && strlen((string)$value) < (int)$m[1]) {
                $errors[$field] = "Минимум {$m[1]} символов";
            }
            if (preg_match('/max:(\d+)/', $rule, $m) && strlen((string)$value) > (int)$m[1]) {
                $errors[$field] = "Максимум {$m[1]} символов";
            }
        }
        return empty($errors) ? null : $errors;
    }

    /**
     * Require authentication. Redirect if not logged in.
     */
    protected function requireAuth(): array
    {
        $user = Session::getAuth();
        if (!$user) {
            Session::setFlash('error', 'Необходима авторизация');
            $this->redirect(url('/auth/login'));
        }
        return $user;
    }

    /**
     * Require specific role.
     */
    protected function requireRole(string ...$roles): array
    {
        $user = $this->requireAuth();
        if (!in_array($user['role'], $roles, true)) {
            $this->abort(403, 'Недостаточно прав');
        }
        return $user;
    }

    /**
     * CSRF token check.
     */
    protected function verifyCsrf(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
            if (!Session::verifyCsrf($token)) {
                $this->abort(403, 'Невалидный CSRF-токен');
            }
        }
    }
}
