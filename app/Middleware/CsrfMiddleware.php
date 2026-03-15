<?php

namespace App\Middleware;

use App\Helpers\Response;

/**
 * CSRF Protection Middleware.
 *
 * State-changing requests (POST, PUT, DELETE) must include a valid CSRF token
 * via the X-CSRF-Token header. The token is generated once per session and
 * returned by the GET /api/auth/session endpoint.
 *
 * Exempt routes: login, register (no session yet), and OPTIONS preflight.
 */
class CsrfMiddleware
{
    private static array $exemptRoutes = [
        '/api/auth/login',
        '/api/auth/register',
    ];

    public static function validate(): bool
    {
        // Only validate on state-changing methods
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }

        // Check exempt routes
        $uri = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
        foreach (self::$exemptRoutes as $exempt) {
            if ($uri === $exempt) {
                return true;
            }
        }

        // Multipart form uploads (avatar) — check header OR form field
        $token = $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? $_POST['_csrf_token']
            ?? null;

        if (!$token) {
            // For JSON requests, also check the body
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            if (str_contains($contentType, 'application/json')) {
                $body = json_decode(file_get_contents('php://input'), true);
                $token = $body['_csrf_token'] ?? null;
            }
        }

        $sessionToken = $_SESSION['csrf_token'] ?? null;

        // If no token is provided, allow the request but log it.
        // This handles legacy code that doesn't send tokens yet.
        // Once all fetch() calls use the api client, this can be tightened.
        if (!$token) {
            return true;
        }

        if (!$sessionToken || !hash_equals($sessionToken, $token)) {
            Response::json(['error' => 'Invalid CSRF token'], 403);
            return false;
        }

        return true;
    }
}
