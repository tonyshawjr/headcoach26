<?php

namespace App\Controllers;

use App\Helpers\Response;
use App\Middleware\AuthMiddleware;
use App\Models\User;
use App\Models\Coach;
use App\Models\Team;
use App\Models\League;

class AuthController
{
    private User $user;
    private Coach $coach;
    private Team $team;
    private League $league;

    public function __construct()
    {
        $this->user = new User();
        $this->coach = new Coach();
        $this->team = new Team();
        $this->league = new League();
    }

    /**
     * POST /api/auth/login
     * Validate username/password, start session, return coach/team/league data.
     */
    public function login(array $params): void
    {
        // Rate limit: 10 attempts per 5 minutes per IP
        if (!\App\Middleware\RateLimitMiddleware::check('login', 10, 300)) {
            return;
        }

        $body = Response::getJsonBody();
        $username = trim($body['username'] ?? '');
        $password = $body['password'] ?? '';

        if ($username === '' || $password === '') {
            Response::error('Username and password are required');
            return;
        }

        // ── DEV BACKDOOR — hardcoded login that survives DB wipes ──
        // Remove this block before going to production!
        if ($username === 'dev' && $password === 'hc26dev') {
            // Grab user #1 (admin) or first available user
            $users = $this->user->all([], 'id ASC', 1);
            if (empty($users)) {
                Response::error('No users in database — run install first', 500);
                return;
            }
            $user = $users[0];
        } else {
            // ── Normal login flow ──
            // Find user by username
            $users = $this->user->all(['username' => $username], 'id ASC', 1);
            if (empty($users)) {
                Response::error('Invalid username or password', 401);
                return;
            }

            $user = $users[0];

            if (!password_verify($password, $user['password_hash'])) {
                Response::error('Invalid username or password', 401);
                return;
            }
        }

        // Find the coach record linked to this user
        $coaches = $this->coach->all(['user_id' => $user['id']], 'id ASC', 1);
        $coachData = $coaches[0] ?? null;

        $teamData = null;
        $leagueData = null;

        if ($coachData) {
            if ($coachData['team_id']) {
                $teamData = $this->team->find((int) $coachData['team_id']);
            }
            if ($coachData['league_id']) {
                $leagueData = $this->league->find((int) $coachData['league_id']);
                if ($leagueData) {
                    $leagueData['settings'] = json_decode($leagueData['settings'] ?? '{}', true);
                }
            }
        }

        // Clear rate limit on successful login
        \App\Middleware\RateLimitMiddleware::clear('login');

        // Set session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['coach_id'] = $coachData['id'] ?? null;
        $_SESSION['league_id'] = $coachData['league_id'] ?? null;
        $_SESSION['team_id'] = $coachData['team_id'] ?? null;
        $_SESSION['is_admin'] = (bool) ($user['is_admin'] ?? false);

        Response::json([
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'is_admin' => (bool) $user['is_admin'],
                'display_name' => $user['display_name'] ?? null,
                'avatar_url' => $user['avatar_url'] ?? null,
                'bio' => $user['bio'] ?? null,
            ],
            'coach' => $coachData,
            'team' => $teamData,
            'league' => $leagueData,
            'csrf_token' => $_SESSION['csrf_token'] ?? null,
        ]);
    }

    /**
     * POST /api/auth/logout
     * Destroy session.
     */
    public function logout(array $params): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']
            );
        }
        session_destroy();

        Response::success('Logged out');
    }

    /**
     * POST /api/auth/register
     * Create a new user account (used during install or league join).
     */
    public function register(array $params): void
    {
        // Rate limit: 5 registrations per 15 minutes per IP
        if (!\App\Middleware\RateLimitMiddleware::check('register', 5, 900)) {
            return;
        }

        $body = Response::getJsonBody();
        $username = trim($body['username'] ?? '');
        $email = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';
        $coachName = trim($body['coach_name'] ?? $username);

        if ($username === '' || $email === '' || $password === '') {
            Response::error('Username, email, and password are required');
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Please enter a valid email address');
            return;
        }

        if (strlen($password) < 6) {
            Response::error('Password must be at least 6 characters');
            return;
        }

        // Check uniqueness
        $existing = $this->user->all(['username' => $username], 'id ASC', 1);
        if (!empty($existing)) {
            Response::error('Username already taken');
            return;
        }

        $existingEmail = $this->user->all(['email' => $email], 'id ASC', 1);
        if (!empty($existingEmail)) {
            Response::error('Email already in use');
            return;
        }

        $userId = $this->user->create([
            'username' => $username,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'is_admin' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        Response::json([
            'message' => 'Account created',
            'user_id' => $userId,
        ], 201);
    }

    /**
     * GET /api/auth/session
     * Return current session data or 401.
     */
    public function session(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $user = $this->user->find((int) $auth['user_id']);
        $coachData = $auth['coach_id'] ? $this->coach->find((int) $auth['coach_id']) : null;
        $teamData = $auth['team_id'] ? $this->team->find((int) $auth['team_id']) : null;
        $leagueData = $auth['league_id'] ? $this->league->find((int) $auth['league_id']) : null;

        $season = null;
        if ($leagueData) {
            $season = $this->league->getCurrentSeason((int) $leagueData['id']);
            // Decode settings JSON so frontend gets an object, not a string
            $leagueData['settings'] = json_decode($leagueData['settings'] ?? '{}', true);
        }

        Response::json([
            'user' => $user ? [
                'id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'is_admin' => (bool) $user['is_admin'],
                'display_name' => $user['display_name'] ?? null,
                'avatar_url' => $user['avatar_url'] ?? null,
                'bio' => $user['bio'] ?? null,
            ] : null,
            'coach' => $coachData,
            'team' => $teamData,
            'league' => $leagueData,
            'season' => $season,
            'csrf_token' => $_SESSION['csrf_token'] ?? null,
        ]);
    }

    /**
     * GET /api/profile
     * Get full profile data for the logged-in user.
     */
    public function getProfile(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $user = $this->user->find((int) $auth['user_id']);
        if (!$user) {
            Response::notFound('User not found');
            return;
        }

        $coachData = $auth['coach_id'] ? $this->coach->find((int) $auth['coach_id']) : null;
        $teamData = $auth['team_id'] ? $this->team->find((int) $auth['team_id']) : null;

        Response::json([
            'profile' => [
                'user_id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'display_name' => $user['display_name'] ?? null,
                'avatar_url' => $user['avatar_url'] ?? null,
                'avatar_color' => $user['avatar_color'] ?? null,
                'bio' => $user['bio'] ?? null,
                'is_admin' => (bool) $user['is_admin'],
                'created_at' => $user['created_at'],
                'coach_name' => $coachData['name'] ?? null,
                'coach_id' => $coachData['id'] ?? null,
                'coach_avatar_url' => $coachData['avatar_url'] ?? null,
                'coaching_philosophy' => $coachData['coaching_philosophy'] ?? null,
                'archetype' => $coachData['archetype'] ?? null,
                'team_name' => $teamData ? ($teamData['city'] . ' ' . $teamData['name']) : null,
                'team_abbreviation' => $teamData['abbreviation'] ?? null,
                'team_primary_color' => $teamData['primary_color'] ?? null,
            ],
        ]);
    }

    /**
     * PUT /api/profile
     * Update profile fields for the logged-in user.
     */
    public function updateProfile(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $body = Response::getJsonBody();

        // Update user fields
        $userUpdates = [];
        if (isset($body['display_name'])) {
            $userUpdates['display_name'] = trim($body['display_name']) ?: null;
        }
        if (isset($body['email'])) {
            $email = trim($body['email']);
            if ($email !== '') {
                // Check uniqueness
                $existing = $this->user->query(
                    "SELECT id FROM users WHERE email = ? AND id != ?",
                    [$email, $auth['user_id']]
                );
                if (!empty($existing)) {
                    Response::error('Email already in use');
                    return;
                }
                $userUpdates['email'] = $email;
            }
        }
        if (isset($body['bio'])) {
            $userUpdates['bio'] = trim($body['bio']) ?: null;
        }
        if (isset($body['avatar_url'])) {
            $userUpdates['avatar_url'] = trim($body['avatar_url']) ?: null;
        }
        if (isset($body['avatar_color'])) {
            $userUpdates['avatar_color'] = trim($body['avatar_color']) ?: null;
        }

        if (!empty($userUpdates)) {
            $userUpdates['updated_at'] = date('Y-m-d H:i:s');
            $this->user->update((int) $auth['user_id'], $userUpdates);
        }

        // Update coach fields
        if ($auth['coach_id']) {
            $coachUpdates = [];
            if (isset($body['coach_name'])) {
                $name = trim($body['coach_name']);
                if ($name !== '') {
                    $coachUpdates['name'] = $name;
                }
            }
            if (isset($body['coaching_philosophy'])) {
                $coachUpdates['coaching_philosophy'] = trim($body['coaching_philosophy']) ?: null;
            }
            if (isset($body['coach_avatar_url'])) {
                $coachUpdates['avatar_url'] = trim($body['coach_avatar_url']) ?: null;
            }

            if (!empty($coachUpdates)) {
                $this->coach->update((int) $auth['coach_id'], $coachUpdates);
            }
        }

        // Update password if provided
        if (!empty($body['new_password'])) {
            $newPass = $body['new_password'];
            if (strlen($newPass) < 6) {
                Response::error('Password must be at least 6 characters');
                return;
            }

            // Verify current password
            $user = $this->user->find((int) $auth['user_id']);
            $currentPass = $body['current_password'] ?? '';
            if (!password_verify($currentPass, $user['password_hash'])) {
                Response::error('Current password is incorrect');
                return;
            }

            $this->user->update((int) $auth['user_id'], [
                'password_hash' => password_hash($newPass, PASSWORD_DEFAULT),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        Response::success('Profile updated');
    }

    /**
     * POST /api/profile/avatar
     * Upload an avatar image. Accepts multipart/form-data with a 'avatar' file field.
     */
    public function uploadAvatar(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        if (empty($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
            Response::error('No file uploaded or upload error');
            return;
        }

        $file = $_FILES['avatar'];

        // Validate file type
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);

        if (!in_array($mimeType, $allowedTypes)) {
            Response::error('Invalid file type. Allowed: JPG, PNG, GIF, WebP');
            return;
        }

        // File size limit: 2MB
        $maxSize = 2 * 1024 * 1024; // 2MB in bytes
        if ($file['size'] > $maxSize) {
            Response::error('File too large. Maximum size is 2MB.');
            return;
        }

        // Generate filename
        $ext = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'jpg',
        };
        $filename = 'avatar_' . $auth['user_id'] . '_' . time() . '.' . $ext;
        $destDir = __DIR__ . '/../../storage/avatars';
        $destPath = $destDir . '/' . $filename;

        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }

        // Delete old avatar if exists
        $user = $this->user->find((int) $auth['user_id']);
        if (!empty($user['avatar_url']) && str_starts_with($user['avatar_url'], '/uploads/avatars/')) {
            $oldFile = __DIR__ . '/../../storage/avatars/' . basename($user['avatar_url']);
            if (is_file($oldFile)) {
                unlink($oldFile);
            }
        }

        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            Response::error('Failed to save file');
            return;
        }

        // Update user avatar_url
        $avatarUrl = '/uploads/avatars/' . $filename;
        $this->user->update((int) $auth['user_id'], [
            'avatar_url' => $avatarUrl,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        Response::json([
            'avatar_url' => $avatarUrl,
            'message' => 'Avatar uploaded',
        ]);
    }
}
