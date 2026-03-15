<?php

namespace App\Controllers;

use App\Helpers\Response;
use App\Middleware\AuthMiddleware;
use App\Services\FreeAgencyEngine;
use App\Services\OffseasonFlowEngine;

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
     * Accepts optional JSON body: { "post_june_1": true } for post-June-1 designation.
     */
    public function release(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $playerId = (int) $params['id'];
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $postJune1 = !empty($body['post_june_1']);

        // Verify the player is on this team
        $db = \App\Database\Connection::getInstance()->getPdo();
        $stmt = $db->prepare("SELECT team_id FROM players WHERE id = ?");
        $stmt->execute([$playerId]);
        $player = $stmt->fetch();
        if (!$player || (int) $player['team_id'] !== (int) $auth['team_id']) {
            Response::error('Unable to release player. Player may not be on your roster.');
            return;
        }

        $released = $this->freeAgencyEngine->releasePlayer($auth['league_id'] ?? $auth['team_id'], $playerId, $postJune1);

        if (!$released) {
            Response::error('Unable to release player. Player may not be on your roster.');
            return;
        }

        $deadCapResult = $this->freeAgencyEngine->getLastDeadCapResult();

        Response::success('Player released to free agency', [
            'player_id'   => $playerId,
            'dead_cap'    => $deadCapResult['dead_cap'] ?? 0,
            'cap_saved'   => $deadCapResult['cap_saved'] ?? 0,
            'post_june_1' => $postJune1,
            'year1_dead'  => $deadCapResult['year1_dead'] ?? 0,
            'year2_dead'  => $deadCapResult['year2_dead'] ?? 0,
        ]);
    }

    /**
     * GET /api/players/{id}/cut-preview
     * Preview dead cap implications of cutting a player (standard vs post-June-1).
     */
    public function cutPreview(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $playerId = (int) $params['id'];

        $contractEngine = new \App\Services\ContractEngine();
        $preview = $contractEngine->previewCut($playerId);

        if (isset($preview['error'])) {
            Response::error($preview['error']);
            return;
        }

        Response::json($preview);
    }

    /**
     * POST /api/free-agency/simulate-round
     * Triggers AI bidding for the current free agency round (called during offseason advance).
     */
    public function simulateRound(): void
    {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;

        $leagueId = $auth['league_id'];
        if (!$leagueId) {
            Response::error('No league associated with session');
            return;
        }

        $flowEngine = new OffseasonFlowEngine();
        $currentPhase = $flowEngine->getCurrentPhase((int) $leagueId);

        // Determine which round we're in
        $roundMap = [
            'free_agency_1' => 1,
            'free_agency_2' => 2,
            'free_agency_3' => 3,
            'free_agency_4' => 4,
        ];

        $round = $roundMap[$currentPhase] ?? null;
        if ($round === null) {
            Response::error('Not currently in a free agency phase. Current phase: ' . ($currentPhase ?? 'none'));
            return;
        }

        $results = $flowEngine->processFreeAgencyRound((int) $leagueId, $round);

        Response::success("Free agency round {$round} simulated", $results);
    }

    // ================================================================
    //  Restricted Free Agency (RFA) Endpoints
    // ================================================================

    /**
     * POST /api/free-agents/{id}/tender
     * Original team sets a qualifying tender on their restricted free agent.
     * Body: { level: 'first_round' | 'second_round' | 'original_round' }
     */
    public function tender(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $freeAgentId = (int) $params['id'];
        $body = Response::getJsonBody();

        $level = $body['level'] ?? null;
        if (!$level) {
            Response::error('Tender level is required: first_round, second_round, or original_round');
            return;
        }

        $result = $this->freeAgencyEngine->setTender($freeAgentId, $level, $auth['team_id']);

        if (isset($result['error'])) {
            Response::error($result['error']);
            return;
        }

        Response::success('Tender set successfully', $result);
    }

    /**
     * POST /api/free-agents/{id}/offer-sheet
     * Another team makes an offer sheet to a restricted free agent.
     * Body: { salary: int, years: int }
     */
    public function offerSheet(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $freeAgentId = (int) $params['id'];
        $body = Response::getJsonBody();

        $salary = (int) ($body['salary'] ?? 0);
        $years = (int) ($body['years'] ?? 0);

        if ($salary < 1) {
            Response::error('salary must be a positive integer');
            return;
        }
        if ($years < 1 || $years > 7) {
            Response::error('years must be between 1 and 7');
            return;
        }

        $result = $this->freeAgencyEngine->makeOfferSheet($freeAgentId, $auth['team_id'], $salary, $years);

        if (isset($result['error'])) {
            Response::error($result['error']);
            return;
        }

        Response::success('Offer sheet submitted', $result);
    }

    /**
     * POST /api/free-agents/{id}/match-offer
     * Original team matches the offer sheet -- player stays.
     */
    public function matchOffer(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $freeAgentId = (int) $params['id'];
        $result = $this->freeAgencyEngine->matchOfferSheet($freeAgentId, $auth['team_id']);

        if (isset($result['error'])) {
            Response::error($result['error']);
            return;
        }

        Response::success('Offer sheet matched', $result);
    }

    /**
     * POST /api/free-agents/{id}/decline-offer
     * Original team declines the offer sheet -- player goes to new team, compensation awarded.
     */
    public function declineOffer(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $freeAgentId = (int) $params['id'];
        $result = $this->freeAgencyEngine->declineOfferSheet($freeAgentId, $auth['team_id']);

        if (isset($result['error'])) {
            Response::error($result['error']);
            return;
        }

        Response::success('Offer sheet declined', $result);
    }

    /**
     * GET /api/free-agents/rfa-offers
     * Get pending RFA offer sheets for the current user's team.
     */
    public function rfaOffers(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $offers = $this->freeAgencyEngine->getTeamRFAOfferSheets($auth['team_id']);
        $myRFAs = $this->freeAgencyEngine->getTeamRFAs($auth['team_id']);

        Response::json([
            'pending_offers' => $offers,
            'my_rfas' => $myRFAs,
        ]);
    }
}
