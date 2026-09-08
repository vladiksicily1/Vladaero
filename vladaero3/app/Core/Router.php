<?php
namespace App\Core;

class Router {
    private array $routes = [];
    private string $currentLocale = 'ru';

    public function get(string $path, string $handler): self {
        return $this->addRoute('GET', $path, $handler);
    }

    public function post(string $path, string $handler): self {
        return $this->addRoute('POST', $path, $handler);
    }

    public function any(string $path, string $handler): self {
        $this->addRoute('GET', $path, $handler);
        $this->addRoute('POST', $path, $handler);
        return $this;
    }

    private function addRoute(string $method, string $path, string $handler): self {
        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'handler' => $handler
        ];
        return $this;
    }

    public function dispatch(string $uri, string $requestMethod): void {
        $parsedUri = parse_url($uri, PHP_URL_PATH);
        $cleanUri = '/' . trim($parsedUri, '/');
        if ($cleanUri === '//') $cleanUri = '/';

        // Check language prefix (/ru/... or /en/...)
        if (preg_match('#^/(ru|en)(/.*)?$#i', $cleanUri, $matches)) {
            $this->currentLocale = strtolower($matches[1]);
            $cleanUri = empty($matches[2]) ? '/' : $matches[2];
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $requestMethod && $route['method'] !== 'ANY') {
                continue;
            }

            // Convert route pattern {param} to regex
            $pattern = preg_replace('#\{([a-zA-Z0-9_]+)\}#', '(?P<$1>[^/]+)', $route['path']);
            $pattern = '#^' . $pattern . '$#';

            if (preg_match($pattern, $cleanUri, $matches)) {
                $params = [];
                foreach ($matches as $key => $value) {
                    if (!is_int($key)) {
                        $params[$key] = urldecode($value);
                    }
                }

                [$controllerName, $action] = explode('@', $route['handler']);
                $fullControllerClass = "App\\Controllers\\" . $controllerName;

                if (!class_exists($fullControllerClass)) {
                    throw new \RuntimeException("Controller {$fullControllerClass} not found");
                }

                $controller = new $fullControllerClass($this->currentLocale);
                if (!method_exists($controller, $action)) {
                    throw new \RuntimeException("Action {$action} not found in {$fullControllerClass}");
                }

                call_user_func_array([$controller, $action], $params);
                return;
            }
        }

        // Route not found -> 404
        http_response_code(404);
        $controller = new \App\Controllers\HomeController($this->currentLocale);
        $controller->notFound();
    }

    public function getLocale(): string {
        return $this->currentLocale;
    }
}
