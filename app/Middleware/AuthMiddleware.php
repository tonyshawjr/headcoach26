<?php

namespace App\Middleware;

use App\Helpers\Response;

class AuthMiddleware
{
    /**
     * Check if user is authenticated. Returns session data or sends 401.
     */
    public static function handle(): ?array
    {
        if (empty($_SESSION['coach_id'])) {
            Response::unauthorized();
            return null;
        }

        return [
            'user_id' => $_SESSION['user_id'] ?? null,
            'coach_id' => $_SESSION['coach_id'],
            'league_id' => $_SESSION['league_id'] ?? null,
            'team_id' => $_SESSION['team_id'] ?? null,
            'is_admin' => $_SESSION['is_admin'] ?? false,
        ];
    }

    /**
     * Check if user is commissioner/admin.
     */
    public static function requireAdmin(): ?array
    {
        $auth = self::handle();
        if ($auth === null) return null;

        if (empty($auth['is_admin'])) {
            Response::error('Admin access required', 403);
            return null;
        }

        return $auth;
    }
}
