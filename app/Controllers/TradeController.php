<?php

namespace App\Controllers;

use App\Database\Connection;
use App\Helpers\Response;
use App\Middleware\AuthMiddleware;
use App\Services\TradeEngine;

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

        // Have AI immediately evaluate and respond
        if (isset($trade['trade_id'])) {
            $aiDecision = $this->tradeEngine->aiEvaluateTrade($trade['trade_id']);
            if ($aiDecision === 'accepted') {
                $this->tradeEngine->executeTrade($trade['trade_id']);
                $trade['status'] = 'accepted';
                $trade['message'] = 'Trade accepted! Players have been swapped.';
            } elseif ($aiDecision === 'rejected') {
                $this->db->prepare("UPDATE trades SET status = 'rejected' WHERE id = ?")->execute([$trade['trade_id']]);
                $trade['status'] = 'rejected';
                $trade['message'] = 'Trade rejected by the other team.';
            } else {
                $trade['status'] = 'counter';
                $trade['message'] = 'The other team wants to counter-offer.';
            }
        }

        Response::success('Trade proposed', ['trade' => $trade]);
    }

    /**
     * PUT /api/trades/{id}/respond
     * Accept, reject, or counter a trade.
     */
    public function respond(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

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
