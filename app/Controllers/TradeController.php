<?php

namespace App\Controllers;

use App\Database\Connection;
use App\Helpers\Response;
use App\Middleware\AuthMiddleware;
use App\Services\TradeEngine;
use App\Services\CommissionerService;

class TradeController
{
    private TradeEngine $tradeEngine;
    private \PDO $db;

    public function __construct()
    {
        $this->tradeEngine = new TradeEngine();
        $this->db = Connection::getInstance()->getPdo();
    }

    /**
     * Check if the trade deadline has passed for this league.
     * Returns true if trades are blocked (deadline has passed).
     */
    private function isTradeDeadlinePassed(int $leagueId): bool
    {
        $stmt = $this->db->prepare("SELECT current_week, phase FROM leagues WHERE id = ?");
        $stmt->execute([$leagueId]);
        $league = $stmt->fetch();

        if (!$league || $league['phase'] !== 'regular') {
            // Only enforce during regular season — no trades in playoffs/offseason either
            $phase = $league['phase'] ?? 'unknown';
            if (in_array($phase, ['playoffs', 'offseason', 'preseason'], true)) {
                return true;
            }
            return false;
        }

        $currentWeek = (int) $league['current_week'];
        $commish = new CommissionerService();
        $settings = $commish->getSettings($leagueId);
        $deadlineWeek = (int) ($settings['trade_deadline_week'] ?? 12);

        return $currentWeek > $deadlineWeek;
    }

    /**
     * GET /api/trades
     * List all trades for the user's league.
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

        $trades = $this->tradeEngine->listTrades($leagueId);

        Response::json([
            'trades' => $trades,
        ]);
    }

    /**
     * POST /api/trades
     * Propose a new trade.
     */
    public function propose(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        if ($this->isTradeDeadlinePassed($auth['league_id'])) {
            Response::error('The trade deadline has passed. No trades can be made this season.', 403);
            return;
        }

        $body = Response::getJsonBody();

        if (empty($body['target_team_id'])) {
            Response::error('target_team_id is required');
            return;
        }
        $offeringPlayerIds = $body['offering_player_ids'] ?? [];
        $requestingPlayerIds = $body['requesting_player_ids'] ?? [];
        $offeringPickIds = $body['offering_pick_ids'] ?? [];
        $requestingPickIds = $body['requesting_pick_ids'] ?? [];

        if (empty($offeringPlayerIds) && empty($offeringPickIds)) {
            Response::error('Must offer at least one player or pick');
            return;
        }
        if (empty($requestingPlayerIds) && empty($requestingPickIds)) {
            Response::error('Must request at least one player or pick');
            return;
        }

        // Build structured items arrays for TradeEngine
        $proposingItems = [];
        foreach ($offeringPlayerIds as $pid) {
            $proposingItems[] = ['item_type' => 'player', 'player_id' => (int) $pid, 'draft_pick_id' => null];
        }
        foreach ($offeringPickIds as $pid) {
            $proposingItems[] = ['item_type' => 'draft_pick', 'player_id' => null, 'draft_pick_id' => (int) $pid];
        }

        $receivingItems = [];
        foreach ($requestingPlayerIds as $pid) {
            $receivingItems[] = ['item_type' => 'player', 'player_id' => (int) $pid, 'draft_pick_id' => null];
        }
        foreach ($requestingPickIds as $pid) {
            $receivingItems[] = ['item_type' => 'draft_pick', 'player_id' => null, 'draft_pick_id' => (int) $pid];
        }

        $trade = $this->tradeEngine->proposeTrade(
            $auth['league_id'],
            $auth['team_id'],
            (int) $body['target_team_id'],
            $proposingItems,
            $receivingItems
        );

        if (isset($trade['trade_id'])) {
            $tradeId = $trade['trade_id'];

            // If this came from find-opportunities/acquire (pre-agreed), auto-accept
            $preAgreed = !empty($body['pre_agreed']);

            if ($preAgreed) {
                $this->db->prepare("UPDATE trades SET status = 'accepted' WHERE id = ?")->execute([$tradeId]);
                $this->tradeEngine->executeTrade($tradeId);
                $trade['status'] = 'completed';
                $trade['message'] = 'Trade complete! Players have been swapped.';
            } else {
                // Manual proposal — AI evaluates with full reasoning
                $aiResult = $this->tradeEngine->aiEvaluateTrade($tradeId);
                $decision = $aiResult['decision'] ?? 'rejected';
                $reason = $aiResult['reason'] ?? 'No response.';

                if ($decision === 'accepted') {
                    $this->db->prepare("UPDATE trades SET status = 'accepted' WHERE id = ?")->execute([$tradeId]);
                    $this->tradeEngine->executeTrade($tradeId);
                    $trade['status'] = 'completed';
                    $trade['message'] = 'Trade accepted! Players have been swapped.';
                    $trade['reason'] = $reason;
                } elseif ($decision === 'counter') {
                    $this->db->prepare("UPDATE trades SET status = 'countered', veto_reason = ? WHERE id = ?")
                        ->execute([$reason, $tradeId]);
                    $trade['status'] = 'countered';
                    $trade['message'] = $reason;
                    $trade['reason'] = $reason;
                    $trade['counter_offer'] = $aiResult['counter_offer'] ?? null;
                } else {
                    $this->db->prepare("UPDATE trades SET status = 'rejected', veto_reason = ? WHERE id = ?")
                        ->execute([$reason, $tradeId]);
                    $trade['status'] = 'rejected';
                    $trade['message'] = $reason;
                    $trade['reason'] = $reason;
                }
            }
        }

        Response::success('Trade proposed', ['trade' => $trade]);
    }

    /**
     * POST /api/trades/sweeten
     * Ask a team to sweeten their offer — add a pick or upgrade a player.
     */
    public function sweeten(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        if ($this->isTradeDeadlinePassed($auth['league_id'])) {
            Response::error('The trade deadline has passed. No trades can be made this season.', 403);
            return;
        }

        $body = Response::getJsonBody();
        $teamId = (int) ($body['team_id'] ?? 0);
        $playerId = (int) ($body['player_id'] ?? 0);
        $currentOfferPlayerIds = $body['current_offer_player_ids'] ?? [];
        $currentOfferPickIds = $body['current_offer_pick_ids'] ?? [];

        if (!$teamId || !$playerId) {
            Response::error('team_id and player_id are required');
            return;
        }

        $result = $this->tradeEngine->sweetenDeal(
            $playerId,
            $teamId,
            $auth['team_id'],
            $currentOfferPlayerIds,
            $currentOfferPickIds
        );

        Response::json($result);
    }

    /**
     * PUT /api/trades/{id}/respond
     * Accept, reject, or counter a trade.
     */
    public function respond(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        if ($this->isTradeDeadlinePassed($auth['league_id'])) {
            Response::error('The trade deadline has passed. No trades can be made this season.', 403);
            return;
        }

        $tradeId = (int) $params['id'];
        $body = Response::getJsonBody();

        if (empty($body['action'])) {
            Response::error('action is required (accept, reject, counter)');
            return;
        }

        $action = $body['action'];
        if (!in_array($action, ['accept', 'reject', 'counter'], true)) {
            Response::error('action must be one of: accept, reject, counter');
            return;
        }

        $result = $this->tradeEngine->respondToTrade(
            $tradeId,
            $auth['team_id'],
            $action,
            $body['counter_data'] ?? []
        );

        if (!$result) {
            Response::error('Unable to process trade response');
            return;
        }

        Response::success("Trade {$action}ed successfully", ['trade' => $result]);
    }

    /**
     * GET /api/trades/{id}/evaluate
     * Get an AI evaluation of a trade's fairness.
     */
    public function evaluate(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $tradeId = (int) $params['id'];

        $evaluation = $this->tradeEngine->evaluateTrade($tradeId);

        if (!$evaluation) {
            Response::notFound('Trade not found');
            return;
        }

        Response::json([
            'trade_id' => $tradeId,
            'evaluation' => $evaluation,
        ]);
    }

    /**
     * POST /api/trades/find-opportunities
     * Find trade opportunities for a given player.
     */
    public function findOpportunities(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        if ($this->isTradeDeadlinePassed($auth['league_id'])) {
            Response::error('The trade deadline has passed. No trades can be made this season.', 403);
            return;
        }

        $body = Response::getJsonBody();

        if (empty($body['player_id'])) {
            Response::error('player_id is required');
            return;
        }

        $result = $this->tradeEngine->findTradeOpportunities(
            (int) $body['player_id'],
            $auth['team_id']
        );

        if (!$result['player']) {
            Response::notFound('Player not found');
            return;
        }

        Response::json($result);
    }

    /**
     * POST /api/trades/acquire
     * "I want this player from another team — what would it cost me?"
     * Reverse of findOpportunities: the opposing team's brain decides
     * what it would need from you to give up their player.
     */
    public function acquirePlayer(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        if ($this->isTradeDeadlinePassed($auth['league_id'])) {
            Response::error('The trade deadline has passed. No trades can be made this season.', 403);
            return;
        }

        $body = Response::getJsonBody();

        if (empty($body['player_id'])) {
            Response::error('player_id is required');
            return;
        }

        $result = $this->tradeEngine->findAcquisitionPackages(
            (int) $body['player_id'],
            $auth['team_id']
        );

        if (!$result['player']) {
            Response::notFound('Player not found or not available for trade');
            return;
        }

        Response::json($result);
    }

    /**
     * GET /api/trades/incoming-offers
     * AI teams that want to make trade offers to the user.
     */
    public function incomingOffers(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $offers = $this->tradeEngine->generateIncomingOffers(
            $auth['league_id'],
            $auth['team_id']
        );

        Response::json(['offers' => $offers]);
    }

    /**
     * GET /api/trade-block
     * List all players currently on the trade block for the user's league.
     */
    public function tradeBlockIndex(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $leagueId = $auth['league_id'];
        if (!$leagueId) {
            Response::error('No league associated with session');
            return;
        }

        $players = $this->tradeEngine->getTradeBlock($leagueId);

        Response::json([
            'trade_block' => $players,
        ]);
    }

    /**
     * POST /api/trade-block
     * Add a player to the trade block.
     */
    public function tradeBlockAdd(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $body = Response::getJsonBody();

        if (empty($body['player_id'])) {
            Response::error('player_id is required');
            return;
        }

        $result = $this->tradeEngine->addToTradeBlock(
            $auth['team_id'],
            (int) $body['player_id'],
            $body['notes'] ?? ''
        );

        if (!$result) {
            Response::error('Unable to add player to trade block');
            return;
        }

        Response::success('Player added to trade block', ['entry' => $result]);
    }

    /**
     * DELETE /api/trade-block/{id}
     * Remove a player from the trade block.
     */
    public function tradeBlockRemove(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $entryId = (int) $params['id'];

        $removed = $this->tradeEngine->removeFromTradeBlock($entryId, $auth['team_id']);

        if (!$removed) {
            Response::notFound('Trade block entry not found or not yours');
            return;
        }

        Response::success('Player removed from trade block');
    }
}
