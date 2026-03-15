<?php

namespace App\Middleware;

use App\Helpers\Response;

/**
 * Simple file-based rate limiter.
 *
 * Tracks attempts by IP address using temp files in storage/rate_limits/.
 * No database or Redis required.
 */
class RateLimitMiddleware
{
    /**
     * Check rate limit for a given action.
     *
     * @param string $action   Key name (e.g., 'login', 'register')
     * @param int    $maxAttempts  Max attempts allowed in the window
     * @param int    $windowSeconds  Time window in seconds
     * @return bool  True if allowed, false if rate-limited (sends 429 response)
     */
    public static function check(string $action, int $maxAttempts = 10, int $windowSeconds = 300): bool
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $key = $action . '_' . md5($ip);

        $dir = __DIR__ . '/../../storage/rate_limits';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $file = $dir . '/' . $key . '.json';

        $data = ['attempts' => [], 'blocked_until' => 0];
        if (file_exists($file)) {
            $raw = file_get_contents($file);
            $data = json_decode($raw, true) ?: $data;
        }

        $now = time();

        // Check if currently blocked
        if ($data['blocked_until'] > $now) {
            $retryAfter = $data['blocked_until'] - $now;
            header("Retry-After: {$retryAfter}");
            Response::json([
                'error' => 'Too many attempts. Please try again later.',
                'retry_after' => $retryAfter,
            ], 429);
            return false;
        }

        // Clean old attempts outside the window
        $data['attempts'] = array_values(array_filter(
            $data['attempts'],
            fn($ts) => $ts > ($now - $windowSeconds)
        ));

        // Check if over limit
        if (count($data['attempts']) >= $maxAttempts) {
            // Block for the remainder of the window
            $data['blocked_until'] = $now + $windowSeconds;
            file_put_contents($file, json_encode($data));

            header("Retry-After: {$windowSeconds}");
            Response::json([
                'error' => 'Too many attempts. Please try again later.',
                'retry_after' => $windowSeconds,
            ], 429);
            return false;
        }

        // Record this attempt
        $data['attempts'][] = $now;
        file_put_contents($file, json_encode($data));

        return true;
    }

    /**
     * Clear rate limit for an action+IP (e.g., after successful login).
     */
    public static function clear(string $action): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $key = $action . '_' . md5($ip);
        $file = __DIR__ . '/../../storage/rate_limits/' . $key . '.json';

        if (file_exists($file)) {
            unlink($file);
        }
    }
}
