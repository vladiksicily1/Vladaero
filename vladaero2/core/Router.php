<?php
namespace VladAero\Core;

/**
 * VladAero — Router (lightweight, file-based MVC)
 */
class Router
{
    private array $routes = [];
    private array $middleware = [];
    private string $prefix = '';

    /**
     * Add a GET route.
     */
    public function get(string $path, callable|array $handler, array $middleware = []): self
    {
        return $this->addRoute('GET', $path, $handler, $middleware);
    }

    /**
     * Add a POST route.
     */
    public function post(string $path, callable|array $handler, array $middleware = []): self
    {
        return $this->addRoute('POST', $path, $handler, $middleware);
    }

    /**
     * Add a route for any method.
     */
    public function any(string $path, callable|array $handler, array $middleware = []): self
    {
        foreach (['GET', 'POST', 'PUT', 'DELETE', 'PATCH'] as $method) {
            $this->addRoute($method, $path, $handler, $middleware);
        }
        return $this;
    }

    /**
     * Group routes with a prefix.
     */
    public function group(string $prefix, callable $callback): self
    {
        $oldPrefix = $this->prefix;
        $this->prefix = $oldPrefix . $prefix;
        $callback($this);
        $this->prefix = $oldPrefix;
        return $this;
    }

    /**
     * Add route group middleware.
     */
    public function middleware(string|array $middleware): self
    {
        if (is_array($middleware)) {
            $this->middleware = array_merge($this->middleware, $middleware);
        } else {
            $this->middleware[] = $middleware;
        }
        return $this;
    }

    private function addRoute(string $method, string $path, callable|array $handler, array $middleware): self
    {
        $prefix = rtrim($this->prefix, '/');
        $cleanPath = '/' . ltrim($path, '/');
        $fullPath = $prefix . $cleanPath;
        if ($fullPath === '') {
            $fullPath = '/';
        }

        $this->routes[] = [
            'method'     => $method,
            'path'       => $fullPath,
            'handler'    => $handler,
            'middleware' => array_merge($this->middleware, $middleware),
            'pattern'    => $this->buildPattern($fullPath),
        ];
        return $this;
    }

    /**
     * Convert path pattern like /aircraft/{slug} to regex.
     */
    private function buildPattern(string $path): string
    {
        $trimmed = trim($path, '/');
        if ($trimmed === '') {
            return '#^/?$#';
        }
        $pattern = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $trimmed);
        return '#^/' . $pattern . '/?$#';
    }

    /**
     * Match a request and invoke the handler.
     */
    public function dispatch(string $method, string $uri): mixed
    {
        // Strip query string
        $uri = strtok($uri, '?') ?: '/';

        // Auto-detect and strip subfolder base path (e.g. /my-site/aircraft -> /aircraft)
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        $basePath = ($scriptDir === '/' || $scriptDir === '.' || $scriptDir === '') ? '' : rtrim($scriptDir, '/');
        if ($basePath !== '' && str_starts_with($uri, $basePath)) {
            $uri = substr($uri, strlen($basePath));
        }

        $uri = '/' . ltrim($uri, '/');

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) continue;

            if (preg_match($route['pattern'], $uri, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                // Run middleware
                foreach ($route['middleware'] as $mw) {
                    $result = $this->runMiddleware($mw, $params);
                    if ($result !== null) return $result;
                }

                // Invoke handler
                if (is_array($route['handler'])) {
                    [$controllerClass, $action] = $route['handler'];
                    $controller = new $controllerClass();
                    return $controller->$action(...array_values($params));
                }

                return $route['handler'](...array_values($params));
            }
        }

        return null; // No route matched
    }

    private function runMiddleware(string $name, array &$params): mixed
    {
        $class = "VladAero\\Middleware\\{$name}";
        if (class_exists($class)) {
            $middleware = new $class();
            return $middleware->handle($params);
        }
        return null;
    }

    /**
     * Get all registered routes (for debugging).
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }
}
