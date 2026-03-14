<?php
/**
 * Head Coach 26 — Front Controller
 * Routes all requests through the application.
 * Works with both Apache (.htaccess) and PHP built-in server.
 */

$uri = strtok($_SERVER['REQUEST_URI'], '?');

// ── Installer ──────────────────────────────────────────────
// Always route /install/* to the installer while the install dir exists
if (is_dir(__DIR__ . '/install') && str_starts_with($uri, '/install')) {
    require_once __DIR__ . '/install/index.php';
    exit;
}

// Check if the app is fully installed (DB + user + league exist)
$fullyInstalled = false;
if (file_exists(__DIR__ . '/config/database.php')) {
    try {
        require_once __DIR__ . '/app/Database/Connection.php';
        $cfg = require __DIR__ . '/config/database.php';
        $pdo = \App\Database\Connection::testConnection($cfg);
        $users = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $leagues = (int) $pdo->query("SELECT COUNT(*) FROM leagues")->fetchColumn();
        $fullyInstalled = ($users > 0 && $leagues > 0);
    } catch (\Exception $e) {
        $fullyInstalled = false;
    }
}

if (!$fullyInstalled && is_dir(__DIR__ . '/install')) {
    // App not fully set up — redirect non-API, non-install requests to installer
    if (str_starts_with($uri, '/api/')) {
        header('Content-Type: application/json');
        http_response_code(503);
        echo json_encode(['error' => 'Not installed', 'needs_install' => true]);
        exit;
    }
    header('Location: /install/');
    exit;
}

// Post-install: /install/status still works if install dir exists
if (!is_dir(__DIR__ . '/install') && str_starts_with($uri, '/install')) {
    header('Content-Type: application/json');
    echo json_encode(['installed' => true]);
    exit;
}

// ── Static files (PHP built-in server only) ────────────────
if (php_sapi_name() === 'cli-server') {
    $publicFile = __DIR__ . '/public' . $uri;
    if ($uri !== '/' && is_file($publicFile)) {
        $mimeTypes = [
            'js' => 'application/javascript',
            'css' => 'text/css',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'json' => 'application/json',
            'map' => 'application/json',
        ];
        $ext = strtolower(pathinfo($publicFile, PATHINFO_EXTENSION));
        $mime = $mimeTypes[$ext] ?? mime_content_type($publicFile) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        // Hashed filenames get long cache; everything else no-cache
        if (preg_match('/\-[a-zA-Z0-9]{6,}\.(js|css)$/', $publicFile)) {
            header('Cache-Control: public, max-age=31536000, immutable');
        } else {
            header('Cache-Control: no-cache');
        }
        readfile($publicFile);
        exit;
    }
}

// ── API routes ─────────────────────────────────────────────
if (str_starts_with($uri, '/api/')) {
    require_once __DIR__ . '/app/bootstrap.php';
    $router = new App\Router();
    require_once __DIR__ . '/app/routes.php';
    $router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
    exit;
}

// ── SPA fallback ───────────────────────────────────────────
$spaPath = __DIR__ . '/public/index.html';
if (is_file($spaPath)) {
    header('Content-Type: text/html');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    readfile($spaPath);
} else {
    http_response_code(500);
    echo 'SPA not built. Run: cd frontend && npm run build';
}
