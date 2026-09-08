<?php
/**
 * VladAero — Composer-less PSR-4 Autoloader
 */

spl_autoload_register(function ($class) {
    if (class_exists($class, false) || interface_exists($class, false) || trait_exists($class, false)) {
        return;
    }

    $prefixes = [
        'VladAero\\Core\\'               => __DIR__ . '/',
        'VladAero\\Models\\'             => __DIR__ . '/models/',
        'VladAero\\Controllers\\Admin\\' => __DIR__ . '/controllers/admin/',
        'VladAero\\Controllers\\Api\\'   => __DIR__ . '/controllers/api/',
        'VladAero\\Controllers\\'        => __DIR__ . '/controllers/',
        'VladAero\\Middleware\\'         => __DIR__ . '/middleware/',
        'VladAero\\Helpers\\'            => __DIR__ . '/helpers/',
        'Admin\\'                        => __DIR__ . '/controllers/admin/',
        'Api\\'                          => __DIR__ . '/controllers/api/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) continue;

        $relativeClass = substr($class, $len);
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

        if (file_exists($file)) {
            require_once $file;

            // Create alias if class was defined with full VladAero namespace
            $parts = explode('\\', $class);
            $name = end($parts);
            if (!class_exists($class, false)) {
                $candidates = [
                    'VladAero\\Controllers\\Admin\\' . $name,
                    'VladAero\\Controllers\\Api\\' . $name,
                    'VladAero\\Controllers\\' . $name,
                    'VladAero\\Core\\' . $name,
                    'VladAero\\Models\\' . $name,
                ];
                foreach ($candidates as $fqcn) {
                    if (class_exists($fqcn, false)) {
                        class_alias($fqcn, $class);
                        break;
                    }
                }
            }
            return;
        }
    }

    // Fallback: try to find the class in core/ directories
    $parts = explode('\\', $class);
    $name = end($parts);

    $paths = [
        __DIR__ . '/' . $name . '.php',
        __DIR__ . '/controllers/' . $name . '.php',
        __DIR__ . '/controllers/admin/' . $name . '.php',
        __DIR__ . '/controllers/api/' . $name . '.php',
        __DIR__ . '/helpers/' . $name . '.php',
        __DIR__ . '/models/' . $name . '.php',
        __DIR__ . '/middleware/' . $name . '.php',
    ];

    foreach ($paths as $path) {
        if (file_exists($path)) {
            require_once $path;

            if (!class_exists($class, false)) {
                $candidates = [
                    'VladAero\\Controllers\\' . $name,
                    'VladAero\\Controllers\\Admin\\' . $name,
                    'VladAero\\Controllers\\Api\\' . $name,
                    'VladAero\\Core\\' . $name,
                    'VladAero\\Models\\' . $name,
                    'VladAero\\Helpers\\' . $name,
                    'VladAero\\Middleware\\' . $name,
                ];
                foreach ($candidates as $fqcn) {
                    if (class_exists($fqcn, false)) {
                        class_alias($fqcn, $class);
                        break;
                    }
                }
            }
            return;
        }
    }
});

