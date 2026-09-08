<?php
namespace VladAero\Core;

/**
 * VladAero — File Cache
 */
class Cache
{
    private string $dir;
    private int $defaultTtl;

    public function __construct(?string $dir = null, int $defaultTtl = 3600)
    {
        $this->dir = $dir ?? (PROJECT_ROOT . '/' . ($GLOBALS['config']['paths']['cache'] ?? 'storage/cache'));
        $this->defaultTtl = $defaultTtl;

        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0755, true);
        }
    }

    public function get(string $key): mixed
    {
        $file = $this->filePath($key);
        if (!file_exists($file)) return null;

        $content = @file_get_contents($file);
        if ($content === false) return null;

        $data = @unserialize($content);
        if (!is_array($data) || !isset($data['expires']) || $data['expires'] < time()) {
            @unlink($file);
            return null;
        }
        return $data['value'] ?? null;
    }

    public function set(string $key, mixed $value, int $ttl = 0): void
    {
        $ttl = $ttl ?: $this->defaultTtl;
        $data = [
            'expires' => time() + $ttl,
            'value'   => $value,
        ];
        @file_put_contents($this->filePath($key), serialize($data), LOCK_EX);
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function delete(string $key): void
    {
        $file = $this->filePath($key);
        if (file_exists($file)) {
            @unlink($file);
        }
    }

    public function clear(): void
    {
        $files = glob($this->dir . '/cache_*') ?: [];
        foreach ($files as $file) {
            @unlink($file);
        }
    }

    /**
     * Get or set (cache-through pattern).
     */
    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $value = $this->get($key);
        if ($value !== null) return $value;

        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }

    private function filePath(string $key): string
    {
        return $this->dir . '/cache_' . md5($key) . '.php';
    }
}
