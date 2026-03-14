<?php

namespace App\Controllers;

use App\Helpers\Response;
use App\Middleware\AuthMiddleware;
use App\Services\InviteService;

class InviteController
{
    private InviteService $inviteService;

    public function __construct()
    {
        $this->inviteService = new InviteService();
    }

    /**
     * POST /api/invites
     * Create an invite link (admin only).
     */
    public function create(): void
    {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;

        $body = Response::getJsonBody();

        $teamId = isset($body['team_id']) ? (int) $body['team_id'] : null;
        $expiresHours = isset($body['expires_hours']) ? (int) $body['expires_hours'] : 168;

        $invite = $this->inviteService->createInvite(
            $auth['league_id'],
            $auth['user_id'],
            $teamId,
            $expiresHours
        );

        Response::success('Invite created', ['invite' => $invite]);
    }

    /**
     * GET /api/invites
     * List all invites for the league (admin only).
     */
    public function index(): void
    {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;

        $invites = $this->inviteService->getLeagueInvites($auth['league_id']);

        Response::json([
            'invites' => $invites,
        ]);
    }

    /**
     * POST /api/invites/claim
     * Claim an invite using a code.
     */
    public function claim(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $body = Response::getJsonBody();

        if (empty($body['code'])) {
            Response::error('code is required');
            return;
        }

        $result = $this->inviteService->claimInvite($body['code'], $auth['user_id']);

        if (isset($result['error'])) {
            Response::error($result['error']);
            return;
        }

        Response::success('Invite claimed successfully', $result);
    }

    /**
     * DELETE /api/invites/{id}
     * Cancel an invite (admin only).
     */
    public function cancel(array $params): void
    {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;

        $inviteId = (int) $params['id'];

        $cancelled = $this->inviteService->cancelInvite($inviteId, $auth['user_id']);

        if (!$cancelled) {
            Response::notFound('Invite not found or already used');
            return;
        }

        Response::success('Invite cancelled');
    }

    /**
     * GET /api/invites/available-teams
     * Get unclaimed teams in the league.
     */
    public function availableTeams(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $teams = $this->inviteService->getAvailableTeams($auth['league_id']);

        Response::json([
            'teams' => $teams,
        ]);
    }
}
