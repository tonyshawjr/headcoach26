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
            ],
            'coach' => $coachData,
            'team' => $teamData,
            'league' => $leagueData,
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
        $body = Response::getJsonBody();
        $username = trim($body['username'] ?? '');
        $email = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';
        $coachName = trim($body['coach_name'] ?? $username);

        if ($username === '' || $email === '' || $password === '') {
            Response::error('Username, email, and password are required');
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
            ] : null,
            'coach' => $coachData,
            'team' => $teamData,
            'league' => $leagueData,
            'season' => $season,
        ]);
    }
}
