<?php

namespace App\Controllers;

use App\Helpers\Response;
use App\Middleware\AuthMiddleware;
use App\Services\FreeAgencyEngine;

class FreeAgencyController
{
    private FreeAgencyEngine $freeAgencyEngine;

    public function __construct()
    {
        $this->freeAgencyEngine = new FreeAgencyEngine();
    }

    /**
     * GET /api/free-agents
     * List available free agents, with optional ?position= filter.
     */
    public function index(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $leagueId = $auth['league_id'];
        if (!$leagueId) {
            Response::error('No league associated with session');
            return;
        }

        $position = $_GET['position'] ?? null;

        $freeAgents = $this->freeAgencyEngine->getAvailable($leagueId, $position);

        Response::json([
            'free_agents' => $freeAgents,
        ]);
    }

    /**
     * POST /api/free-agents/{id}/bid
     * Place a bid on a free agent.
     */
    public function bid(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $playerId = (int) $params['id'];
        $body = Response::getJsonBody();

        if (empty($body['salary_offer'])) {
            Response::error('salary_offer is required');
            return;
        }
        if (empty($body['years_offer'])) {
            Response::error('years_offer is required');
            return;
        }

        $salaryOffer = (int) $body['salary_offer'];
        $yearsOffer = (int) $body['years_offer'];

        if ($salaryOffer < 1) {
            Response::error('salary_offer must be a positive integer');
            return;
        }
        if ($yearsOffer < 1 || $yearsOffer > 7) {
            Response::error('years_offer must be between 1 and 7');
            return;
        }

        $bid = $this->freeAgencyEngine->placeBid(
            $auth['team_id'],
            $playerId,
            $salaryOffer,
            $yearsOffer
        );

        if (!$bid) {
            Response::error('Unable to place bid. Player may not be a free agent or cap space insufficient.');
            return;
        }

        Response::success('Bid placed successfully', ['bid' => $bid]);
    }

    /**
     * POST /api/free-agents/resolve
     * Resolve all active free-agent bidding (admin only).
     */
    public function resolve(): void
    {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;

        $leagueId = $auth['league_id'];
        if (!$leagueId) {
            Response::error('No league associated with session');
            return;
        }

        $results = $this->freeAgencyEngine->resolveBidding($leagueId);

        Response::success('Free agency bidding resolved', [
            'signings' => $results['signings'] ?? [],
            'unsigned' => $results['unsigned'] ?? [],
        ]);
    }

    /**
     * GET /api/free-agents/my-bids
     * List the current user's active bids.
     */
    public function myBids(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $bids = $this->freeAgencyEngine->getTeamBids($auth['team_id']);

        Response::json([
            'bids' => $bids,
        ]);
    }

    /**
     * POST /api/players/{id}/release
     * Release a player to free agency.
     */
    public function release(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $playerId = (int) $params['id'];

        $released = $this->freeAgencyEngine->releasePlayer($auth['team_id'], $playerId);

        if (!$released) {
            Response::error('Unable to release player. Player may not be on your roster.');
            return;
        }

        Response::success('Player released to free agency', ['player_id' => $playerId]);
    }
}
