<?php

namespace App;

class Router
{
    private array $routes = [];

    public function get(string $path, array $handler): void
    {
        $this->addRoute('GET', $path, $handler);
    }

    public function post(string $path, array $handler): void
    {
        $this->addRoute('POST', $path, $handler);
    }

    public function put(string $path, array $handler): void
    {
        $this->addRoute('PUT', $path, $handler);
    }

    public function delete(string $path, array $handler): void
    {
        $this->addRoute('DELETE', $path, $handler);
    }

    private function addRoute(string $method, string $path, array $handler): void
    {
        // Convert /teams/{id} to regex: /teams/(?P<id>[^/]+)
        $pattern = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $path);
        $pattern = '#^/api' . $pattern . '$#';
        $this->routes[] = compact('method', 'pattern', 'handler');
    }

    public function dispatch(string $method, string $uri): void
    {
        $uri = strtok($uri, '?'); // Strip query string
        $uri = rtrim($uri, '/');
        if ($uri === '') {
            $uri = '/';
        }

        // API routes
        if (str_starts_with($uri, '/api/') || $uri === '/api') {
            header('Content-Type: application/json');

            foreach ($this->routes as $route) {
                if ($route['method'] === $method && preg_match($route['pattern'], $uri, $matches)) {
                    $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                    [$class, $action] = $route['handler'];
                    $controller = new $class();
                    $controller->$action($params);
                    return;
                }
            }

            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            return;
        }

        // Static files in /public
        $publicPath = __DIR__ . '/../public' . $uri;
        if ($uri !== '/' && file_exists($publicPath) && !is_dir($publicPath)) {
            $ext = pathinfo($publicPath, PATHINFO_EXTENSION);
            $mimeTypes = [
                'js' => 'application/javascript',
                'css' => 'text/css',
                'svg' => 'image/svg+xml',
                'png' => 'image/png',
                'jpg' => 'image/jpeg',
                'ico' => 'image/x-icon',
                'woff2' => 'font/woff2',
                'woff' => 'font/woff',
                'json' => 'application/json',
            ];
            if (isset($mimeTypes[$ext])) {
                header('Content-Type: ' . $mimeTypes[$ext]);
            }
            readfile($publicPath);
            return;
        }

        // All other routes: serve SPA
        $this->serveSPA();
    }

    private function serveSPA(): void
    {
        $indexPath = __DIR__ . '/../public/index.html';
        if (file_exists($indexPath)) {
            readfile($indexPath);
        } else {
            echo '<!DOCTYPE html><html><body>';
            echo '<h1>Head Coach 26</h1>';
            echo '<p>Frontend not built. Run: <code>cd frontend && npm run build</code></p>';
            echo '</body></html>';
        }
    }
}
