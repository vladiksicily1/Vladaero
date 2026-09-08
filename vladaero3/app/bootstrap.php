<?php
// VladAero Master Bootstrap

mb_internal_encoding('UTF-8');
date_default_timezone_set('UTC');

// Register Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

// Custom error & exception logging
set_exception_handler(function (\Throwable $e) {
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }
    $logMsg = sprintf(
        "[%s] [VladAero Exception] %s in %s:%d\nStack Trace:\n%s\n",
        date('Y-m-d H:i:s'),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    );
    @file_put_contents($logDir . '/app.log', $logMsg, FILE_APPEND);

    // If installed and configured, show friendly 500 error, else print error in dev
    if (php_sapi_name() !== 'cli') {
        http_response_code(500);
        $errView = __DIR__ . '/../views/errors/500.php';
        if (file_exists($errView)) {
            $errorDetails = $e->getMessage();
            include $errView;
        } else {
            echo "<h1>500 - Сбой бортовых систем VladAero</h1><p>" . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }
});

// If installed, initialize Database connection
if (file_exists(__DIR__ . '/../config/database.php')) {
    try {
        \App\Core\Database::init();
    } catch (\Exception $e) {
        // DB not reachable yet
    }
}
