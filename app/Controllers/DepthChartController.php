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
