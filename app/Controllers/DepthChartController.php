<?php

namespace App\Controllers;

use App\Helpers\Response;
use App\Middleware\AuthMiddleware;
use App\Models\DepthChart;
use App\Models\Team;
use App\Models\Player;

class DepthChartController
{
    private DepthChart $depthChart;
    private Team $team;
    private Player $player;

    public function __construct()
    {
        $this->depthChart = new DepthChart();
        $this->team = new Team();
        $this->player = new Player();
    }

    /**
     * GET /api/teams/{team_id}/depth-chart
     * Get depth chart for a team, grouped by position group.
     */
    public function show(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $teamId = (int) $params['team_id'];
        $team = $this->team->find($teamId);
        if (!$team) {
            Response::notFound('Team not found');
            return;
        }

        $entries = $this->depthChart->getByTeam($teamId);

        // Group by position group
        $grouped = [];
        foreach ($entries as $entry) {
            $pg = $entry['position_group'];
            if (!isset($grouped[$pg])) {
                $grouped[$pg] = [];
            }
            $grouped[$pg][] = [
                'slot' => (int) $entry['slot'],
                'player_id' => (int) $entry['player_id'],
                'name' => $entry['first_name'] . ' ' . $entry['last_name'],
                'position' => $entry['position'],
                'overall_rating' => (int) $entry['overall_rating'],
            ];
        }

        // Return bare position-group map to match frontend DepthChartData type
        Response::json($grouped);
    }

    /**
     * POST /api/teams/{team_id}/depth-chart/auto-set
     * Auto-generate the depth chart by assigning the best players at each position.
     */
    public function autoSet(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $teamId = (int) $params['team_id'];
        $team = $this->team->find($teamId);
        if (!$team) {
            Response::notFound('Team not found');
            return;
        }

        if ((int) $auth['team_id'] !== $teamId && !$auth['is_admin']) {
            Response::error('You can only edit your own depth chart', 403);
            return;
        }

        $db = \App\Database\Connection::getInstance()->getPdo();

        // Clear existing depth chart
        $db->prepare("DELETE FROM depth_chart WHERE team_id = ?")->execute([$teamId]);

        // NFL-style depth chart: each position slot has a starter (slot 1) and backup (slot 2)
        // The position_group label reflects the role, not just the base position
        $depthSlots = [
            // Offense
            ['group' => 'QB1',   'position' => 'QB', 'depth' => 2],
            ['group' => 'RB1',   'position' => 'RB', 'depth' => 2],
            ['group' => 'WR1',   'position' => 'WR', 'depth' => 2],
            ['group' => 'WR2',   'position' => 'WR', 'depth' => 2],
            ['group' => 'SLOT',  'position' => 'WR', 'depth' => 2],
            ['group' => 'TE1',   'position' => 'TE', 'depth' => 2],
            ['group' => 'LT',    'position' => 'OT', 'depth' => 2],
            ['group' => 'RT',    'position' => 'OT', 'depth' => 2],
            ['group' => 'LG',    'position' => 'OG', 'depth' => 2],
            ['group' => 'RG',    'position' => 'OG', 'depth' => 2],
            ['group' => 'C',     'position' => 'C',  'depth' => 2],
            // Defense
            ['group' => 'LDE',   'position' => 'DE', 'depth' => 2],
            ['group' => 'RDE',   'position' => 'DE', 'depth' => 2],
            ['group' => 'DT1',   'position' => 'DT', 'depth' => 2],
            ['group' => 'DT2',   'position' => 'DT', 'depth' => 2],
            ['group' => 'MLB',   'position' => 'LB', 'depth' => 2],
            ['group' => 'WLB',   'position' => 'LB', 'depth' => 2],
            ['group' => 'SLB',   'position' => 'LB', 'depth' => 2],
            ['group' => 'CB1',   'position' => 'CB', 'depth' => 2],
            ['group' => 'CB2',   'position' => 'CB', 'depth' => 2],
            ['group' => 'FS',    'position' => 'S',  'depth' => 2],
            ['group' => 'SS',    'position' => 'S',  'depth' => 2],
            // Special Teams
            ['group' => 'K',     'position' => 'K',  'depth' => 1],
            ['group' => 'P',     'position' => 'P',  'depth' => 1],
        ];

        // Two-pass assignment:
        // Pass 1: Assign the BEST available player as starter (slot 1) for each group
        // Pass 2: Fill backups (slot 2) from remaining players
        $usedPlayerIds = [];
        $assigned = 0;

        // Cache all players by position sorted by rating
        $playersByPos = [];
        foreach (['QB','RB','WR','TE','OT','OG','C','DE','DT','LB','CB','S','K','P'] as $pos) {
            $stmt = $db->prepare(
                "SELECT id FROM players WHERE team_id = ? AND position = ? AND status = 'active'
                 ORDER BY overall_rating DESC"
            );
            $stmt->execute([$teamId, $pos]);
            $playersByPos[$pos] = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        }

        // Pass 1: Starters — best available at each position
        foreach ($depthSlots as $slotDef) {
            $group = $slotDef['group'];
            $pos = $slotDef['position'];

            foreach ($playersByPos[$pos] ?? [] as $playerId) {
                if (in_array($playerId, $usedPlayerIds)) continue;

                $db->prepare(
                    "INSERT INTO depth_chart (team_id, position_group, slot, player_id) VALUES (?, ?, 1, ?)"
                )->execute([$teamId, $group, $playerId]);
                $usedPlayerIds[] = $playerId;
                $assigned++;
                break; // one starter per group
            }
        }

        // Pass 2: Backups — next best available
        foreach ($depthSlots as $slotDef) {
            if (($slotDef['depth'] ?? 1) < 2) continue; // K, P don't need backups

            $group = $slotDef['group'];
            $pos = $slotDef['position'];

            foreach ($playersByPos[$pos] ?? [] as $playerId) {
                if (in_array($playerId, $usedPlayerIds)) continue;

                $db->prepare(
                    "INSERT INTO depth_chart (team_id, position_group, slot, player_id) VALUES (?, ?, 2, ?)"
                )->execute([$teamId, $group, $playerId]);
                $usedPlayerIds[] = $playerId;
                $assigned++;
                break; // one backup per group
            }
        }

        // Recalculate team overall, offense, and defense ratings from starters
        $ratingService = new \App\Services\TeamRatingService();
        $ratingService->recalculate($teamId);

        // Return updated depth chart
        $entries = $this->depthChart->getByTeam($teamId);
        $grouped = [];
        foreach ($entries as $entry) {
            $pg = $entry['position_group'];
            if (!isset($grouped[$pg])) {
                $grouped[$pg] = [];
            }
            $grouped[$pg][] = [
                'slot' => (int) $entry['slot'],
                'player_id' => (int) $entry['player_id'],
                'name' => $entry['first_name'] . ' ' . $entry['last_name'],
                'position' => $entry['position'],
                'overall_rating' => (int) $entry['overall_rating'],
            ];
        }

        Response::json($grouped);
    }

    /**
     * PUT /api/teams/{team_id}/depth-chart
     * Bulk update depth chart positions from JSON body.
     *
     * Expected body: { "changes": [ { "position_group": "QB", "slot": 1, "player_id": 42 }, ... ] }
     */
    public function update(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $teamId = (int) $params['team_id'];
        $team = $this->team->find($teamId);
        if (!$team) {
            Response::notFound('Team not found');
            return;
        }

        // Verify the authenticated user owns this team
        if ((int) $auth['team_id'] !== $teamId && !$auth['is_admin']) {
            Response::error('You can only edit your own depth chart', 403);
            return;
        }

        $body = Response::getJsonBody();
        $changes = $body['changes'] ?? [];

        if (empty($changes) || !is_array($changes)) {
            Response::error('No changes provided. Expected: { "changes": [...] }');
            return;
        }

        $updated = 0;
        $errors = [];

        foreach ($changes as $i => $change) {
            $posGroup = $change['position_group'] ?? '';
            $slot = (int) ($change['slot'] ?? 0);
            $playerId = (int) ($change['player_id'] ?? 0);

            if ($posGroup === '' || $slot < 1 || $playerId < 1) {
                $errors[] = "Entry {$i}: position_group, slot, and player_id are required";
                continue;
            }

            // Verify the player belongs to this team
            $player = $this->player->find($playerId);
            if (!$player || (int) $player['team_id'] !== $teamId) {
                $errors[] = "Entry {$i}: player {$playerId} not found on this team";
                continue;
            }

            $this->depthChart->setStarter($teamId, $posGroup, $slot, $playerId);
            $updated++;
        }

        // Recalculate team ratings after depth chart changes
        if ($updated > 0) {
            $ratingService = new \App\Services\TeamRatingService();
            $ratingService->recalculate($teamId);
        }

        $result = ['message' => "{$updated} depth chart position(s) updated", 'updated' => $updated];
        if (!empty($errors)) {
            $result['errors'] = $errors;
        }

        // Return updated depth chart
        $entries = $this->depthChart->getByTeam($teamId);
        $grouped = [];
        foreach ($entries as $entry) {
            $pg = $entry['position_group'];
            if (!isset($grouped[$pg])) {
                $grouped[$pg] = [];
            }
            $grouped[$pg][] = [
                'slot' => (int) $entry['slot'],
                'player_id' => (int) $entry['player_id'],
                'name' => $entry['first_name'] . ' ' . $entry['last_name'],
                'position' => $entry['position'],
                'overall_rating' => (int) $entry['overall_rating'],
            ];
        }

        // Return bare position-group map to match frontend DepthChartData type
        Response::json($grouped);
    }
}
