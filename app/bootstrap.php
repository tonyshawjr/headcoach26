<?php
/**
 * Head Coach 26 — Bootstrap
 * Autoloader, error handling, session start, CORS headers.
 */

// PSR-4-style autoloader for App\ namespace
spl_autoload_register(function (string $class) {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relativeClass = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Error handling
error_reporting(E_ALL);
set_error_handler(function (int $severity, string $message, string $file, int $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    $response = ['error' => 'Internal Server Error'];
    if (getenv('APP_DEBUG') === 'true' || (defined('APP_DEBUG') && APP_DEBUG)) {
        $response['message'] = $e->getMessage();
        $response['file'] = $e->getFile() . ':' . $e->getLine();
        $response['trace'] = $e->getTraceAsString();
    }
    echo json_encode($response);
    exit;
});

// Session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// CORS headers for development
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// App debug mode
if (file_exists(__DIR__ . '/../config/app.php')) {
    $appConfig = require __DIR__ . '/../config/app.php';
    if (!empty($appConfig['debug'])) {
        define('APP_DEBUG', true);
    }
}
