<?php

namespace App\Controllers;

use App\Helpers\Response;
use App\Middleware\AuthMiddleware;
use App\Models\Game;
use App\Models\Team;

class GamePlanController
{
    private Game $game;
    private Team $team;

    private const VALID_OFFENSES = ['run_heavy', 'balanced', 'pass_heavy', 'no_huddle', 'ball_control'];
    private const VALID_DEFENSES = ['base_43', '34', 'blitz', 'prevent', 'zone'];

    public function __construct()
    {
        $this->game = new Game();
        $this->team = new Team();
    }

    /**
     * GET /api/games/{id}/game-plan
     * Get the current game plan for a game (for the authenticated user's team).
     */
    public function show(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $game = $this->game->find((int) $params['id']);
        if (!$game) {
            Response::notFound('Game not found');
            return;
        }

        $teamId = (int) $auth['team_id'];
        $isHome = (int) $game['home_team_id'] === $teamId;
        $isAway = (int) $game['away_team_id'] === $teamId;

        if (!$isHome && !$isAway && !$auth['is_admin']) {
            Response::error('Your team is not in this game', 403);
            return;
        }

        $homeTeam = $this->team->find((int) $game['home_team_id']);
        $awayTeam = $this->team->find((int) $game['away_team_id']);

        $homePlan = json_decode($game['home_game_plan'] ?? 'null', true);
        $awayPlan = json_decode($game['away_game_plan'] ?? 'null', true);

        // Frontend expects: { my_plan, opponent_plan?, schemes, is_home }
        $myPlan = $isHome ? $homePlan : ($isAway ? $awayPlan : null);
        $opponentPlan = null; // Only reveal opponent plan after simulation
        if ($game['is_simulated']) {
            $opponentPlan = $isHome ? $awayPlan : ($isAway ? $homePlan : null);
        }

        $response = [
            'my_plan' => $myPlan,
            'opponent_plan' => $opponentPlan,
            'schemes' => [
                'offense' => self::VALID_OFFENSES,
                'defense' => self::VALID_DEFENSES,
            ],
            'is_home' => $isHome,
            // Extra context for the page
            'game_id' => (int) $game['id'],
            'week' => (int) $game['week'],
            'is_simulated' => (bool) $game['is_simulated'],
        ];

        Response::json($response);
    }

    /**
     * POST /api/games/{id}/game-plan
     * Set offensive and defensive scheme for a game.
     */
    public function submit(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $game = $this->game->find((int) $params['id']);
        if (!$game) {
            Response::notFound('Game not found');
            return;
        }

        if ($game['is_simulated']) {
            Response::error('Cannot change game plan for a simulated game');
            return;
        }

        $teamId = (int) $auth['team_id'];
        $isHome = (int) $game['home_team_id'] === $teamId;
        $isAway = (int) $game['away_team_id'] === $teamId;

        if (!$isHome && !$isAway) {
            Response::error('Your team is not in this game', 403);
            return;
        }

        $body = Response::getJsonBody();
        $offense = $body['offense'] ?? '';
        $defense = $body['defense'] ?? '';

        if (!in_array($offense, self::VALID_OFFENSES, true)) {
            Response::error(
                'Invalid offensive scheme. Valid options: ' . implode(', ', self::VALID_OFFENSES)
            );
            return;
        }

        if (!in_array($defense, self::VALID_DEFENSES, true)) {
            Response::error(
                'Invalid defensive scheme. Valid options: ' . implode(', ', self::VALID_DEFENSES)
            );
            return;
        }

        $plan = json_encode(['offense' => $offense, 'defense' => $defense]);

        $column = $isHome ? 'home_game_plan' : 'away_game_plan';
        $this->game->update((int) $game['id'], [$column => $plan]);

        Response::success('Game plan submitted', [
            'game_id' => (int) $game['id'],
            'side' => $isHome ? 'home' : 'away',
            'game_plan' => ['offense' => $offense, 'defense' => $defense],
        ]);
    }
}
