<?php

namespace App\Controllers;

use App\Helpers\Response;
use App\Middleware\AuthMiddleware;
use App\Services\DraftEngine;

class DraftController
{
    private DraftEngine $draftEngine;

    public function __construct()
    {
        $this->draftEngine = new DraftEngine();
    }

    /**
     * GET /api/draft/class
     * Get current draft class info.
     */
    public function draftClass(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $leagueId = $auth['league_id'];
        if (!$leagueId) {
            Response::error('No league associated with session');
            return;
        }

        $draftClass = $this->draftEngine->getDraftClass($leagueId);

        Response::json([
            'draft_class' => $draftClass,
        ]);
    }

    /**
     * GET /api/draft/board
     * Get draft board (available prospects), optional ?position= filter.
     */
    public function board(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $leagueId = $auth['league_id'];
        if (!$leagueId) {
            Response::error('No league associated with session');
            return;
        }

        $position = $_GET['position'] ?? null;

        $board = $this->draftEngine->getDraftBoard($leagueId, $position);

        Response::json([
            'board' => $board,
        ]);
    }

    /**
     * GET /api/draft/my-picks
     * Get the current user's draft picks.
     */
    public function myPicks(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $leagueId = $auth['league_id'];
        if (!$leagueId) {
            Response::error('No league associated with session');
            return;
        }

        $picks = $this->draftEngine->getTeamPicks($leagueId, $auth['team_id']);

        Response::json([
            'picks' => $picks,
        ]);
    }

    /**
     * POST /api/draft/scout/{id}
     * Scout a specific prospect.
     */
    public function scout(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $prospectId = (int) $params['id'];

        $report = $this->draftEngine->scoutProspect($auth['team_id'], $prospectId);

        if (!$report) {
            Response::notFound('Prospect not found');
            return;
        }

        Response::success('Scouting report generated', ['report' => $report]);
    }

    /**
     * POST /api/draft/pick
     * Make a draft pick.
     */
    public function pick(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $body = Response::getJsonBody();

        if (empty($body['pick_id'])) {
            Response::error('pick_id is required');
            return;
        }
        if (empty($body['prospect_id'])) {
            Response::error('prospect_id is required');
            return;
        }

        $result = $this->draftEngine->makePick(
            $auth['team_id'],
            (int) $body['pick_id'],
            (int) $body['prospect_id']
        );

        if (!$result) {
            Response::error('Unable to make pick. It may not be your turn or the prospect is unavailable.');
            return;
        }

        Response::success('Draft pick made successfully', ['pick' => $result]);
    }

    /**
     * POST /api/draft/auto-pick
     * Auto-pick for the current user's pick.
     */
    public function autoPick(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $leagueId = $auth['league_id'];
        if (!$leagueId) {
            Response::error('No league associated with session');
            return;
        }

        $result = $this->draftEngine->autoPick($leagueId, $auth['team_id']);

        if (!$result) {
            Response::error('Unable to auto-pick. It may not be your turn.');
            return;
        }

        Response::success('Auto-pick completed', ['pick' => $result]);
    }

    /**
     * POST /api/draft/simulate
     * Simulate the entire draft with AI picks (admin only).
     */
    public function simulate(): void
    {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;

        $leagueId = $auth['league_id'];
        if (!$leagueId) {
            Response::error('No league associated with session');
            return;
        }

        $results = $this->draftEngine->simulateDraft($leagueId);

        Response::success('Draft simulation completed', [
            'rounds' => $results['rounds'] ?? 0,
            'picks' => $results['picks'] ?? [],
        ]);
    }
}
