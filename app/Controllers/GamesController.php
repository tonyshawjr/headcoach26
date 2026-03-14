<?php

namespace App\Controllers;

use App\Helpers\Response;
use App\Middleware\AuthMiddleware;
use App\Models\Game;
use App\Models\GameStat;
use App\Models\Team;
use App\Models\Player;
use App\Models\League;

class GamesController
{
    private Game $game;
    private GameStat $gameStat;
    private Team $team;
    private Player $player;
    private League $league;

    public function __construct()
    {
        $this->game = new Game();
        $this->gameStat = new GameStat();
        $this->team = new Team();
        $this->player = new Player();
        $this->league = new League();
    }

    /**
     * GET /api/leagues/{league_id}/schedule
     * Get all games for a league's current season, grouped by week.
     */
    public function schedule(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $leagueId = (int) $params['league_id'];
        $league = $this->league->find($leagueId);
        if (!$league) {
            Response::notFound('League not found');
            return;
        }

        $season = $this->league->getCurrentSeason($leagueId);
        if (!$season) {
            Response::json(new \stdClass()); // Empty object {}
            return;
        }

        // Optional query param filters
        $filterWeek = isset($_GET['week']) ? (int) $_GET['week'] : null;
        $filterTeamId = isset($_GET['team_id']) ? (int) $_GET['team_id'] : null;

        // Build query
        $sql = "SELECT g.*,
                       ht.city as home_city, ht.name as home_name, ht.abbreviation as home_abbr,
                       ht.primary_color as home_primary_color, ht.secondary_color as home_secondary_color,
                       ht.logo_emoji as home_logo_emoji,
                       at.city as away_city, at.name as away_name, at.abbreviation as away_abbr,
                       at.primary_color as away_primary_color, at.secondary_color as away_secondary_color,
                       at.logo_emoji as away_logo_emoji
                FROM games g
                JOIN teams ht ON ht.id = g.home_team_id
                JOIN teams at ON at.id = g.away_team_id
                WHERE g.league_id = ? AND g.season_id = ?";
        $queryParams = [$leagueId, $season['id']];

        if ($filterWeek !== null) {
            $sql .= " AND g.week = ?";
            $queryParams[] = $filterWeek;
        }

        if ($filterTeamId !== null) {
            $sql .= " AND (g.home_team_id = ? OR g.away_team_id = ?)";
            $queryParams[] = $filterTeamId;
            $queryParams[] = $filterTeamId;
        }

        $sql .= " ORDER BY g.week ASC, g.id ASC";

        $games = $this->game->query($sql, $queryParams);

        // Group by week
        $weeks = [];
        foreach ($games as $game) {
            $week = (int) $game['week'];
            if (!isset($weeks[$week])) {
                $weeks[$week] = [];
            }
            $weeks[$week][] = [
                'id' => (int) $game['id'],
                'league_id' => $leagueId,
                'season_id' => (int) $season['id'],
                'week' => $week,
                'game_type' => $game['game_type'],
                'home_team_id' => (int) $game['home_team_id'],
                'away_team_id' => (int) $game['away_team_id'],
                'home_score' => $game['home_score'] !== null ? (int) $game['home_score'] : null,
                'away_score' => $game['away_score'] !== null ? (int) $game['away_score'] : null,
                // home_team / away_team match the frontend Team interface shape
                'home_team' => [
                    'id' => (int) $game['home_team_id'],
                    'city' => $game['home_city'],
                    'name' => $game['home_name'],
                    'abbreviation' => $game['home_abbr'],
                    'primary_color' => $game['home_primary_color'],
                    'secondary_color' => $game['home_secondary_color'],
                    'logo_emoji' => $game['home_logo_emoji'] ?? '',
                ],
                'away_team' => [
                    'id' => (int) $game['away_team_id'],
                    'city' => $game['away_city'],
                    'name' => $game['away_name'],
                    'abbreviation' => $game['away_abbr'],
                    'primary_color' => $game['away_primary_color'],
                    'secondary_color' => $game['away_secondary_color'],
                    'logo_emoji' => $game['away_logo_emoji'] ?? '',
                ],
                'is_simulated' => (bool) $game['is_simulated'],
                'weather' => $game['weather'],
            ];
        }

        Response::json($weeks);
    }

    /**
     * GET /api/games/{id}
     * Single game detail.
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

        $homeTeam = $this->team->find((int) $game['home_team_id']);
        $awayTeam = $this->team->find((int) $game['away_team_id']);

        // Return flat Game object matching frontend Game interface
        $response = [
            'id' => (int) $game['id'],
            'league_id' => (int) $game['league_id'],
            'season_id' => (int) $game['season_id'],
            'week' => (int) $game['week'],
            'game_type' => $game['game_type'],
            'home_team_id' => (int) $game['home_team_id'],
            'away_team_id' => (int) $game['away_team_id'],
            'home_score' => $game['home_score'] !== null ? (int) $game['home_score'] : null,
            'away_score' => $game['away_score'] !== null ? (int) $game['away_score'] : null,
            'is_simulated' => (bool) $game['is_simulated'],
            'weather' => $game['weather'],
            'turning_point' => $game['turning_point'],
            'home_team' => $homeTeam,
            'away_team' => $awayTeam,
        ];

        // Include player grades if game is simulated
        if ($game['is_simulated'] && $game['player_grades']) {
            $response['player_grades'] = json_decode($game['player_grades'], true);
        }

        Response::json($response);
    }

    /**
     * GET /api/games/{id}/box-score
     * Full box score with player stats.
     */
    public function boxScore(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $game = $this->game->find((int) $params['id']);
        if (!$game) {
            Response::notFound('Game not found');
            return;
        }

        if (!$game['is_simulated']) {
            Response::error('Game has not been simulated yet');
            return;
        }

        $homeTeam = $this->team->find((int) $game['home_team_id']);
        $awayTeam = $this->team->find((int) $game['away_team_id']);

        $allStats = $this->gameStat->getByGame((int) $game['id']);

        // Split stats by team and attach player names
        $homeStats = [];
        $awayStats = [];

        foreach ($allStats as $stat) {
            $player = $this->player->find((int) $stat['player_id']);
            $stat['player_name'] = $player
                ? $player['first_name'] . ' ' . $player['last_name']
                : 'Unknown';
            $stat['player_position'] = $player['position'] ?? '';

            if ((int) $stat['team_id'] === (int) $game['home_team_id']) {
                $homeStats[] = $stat;
            } else {
                $awayStats[] = $stat;
            }
        }

        // Calculate team totals
        $calcTotals = function (array $stats): array {
            $totals = [
                'pass_yards' => 0, 'rush_yards' => 0, 'total_yards' => 0,
                'pass_tds' => 0, 'rush_tds' => 0, 'rec_tds' => 0,
                'turnovers' => 0, 'sacks' => 0, 'tackles' => 0,
            ];
            foreach ($stats as $s) {
                $totals['pass_yards'] += (int) ($s['pass_yards'] ?? 0);
                $totals['rush_yards'] += (int) ($s['rush_yards'] ?? 0);
                $totals['pass_tds'] += (int) ($s['pass_tds'] ?? 0);
                $totals['rush_tds'] += (int) ($s['rush_tds'] ?? 0);
                $totals['rec_tds'] += (int) ($s['rec_tds'] ?? 0);
                $totals['turnovers'] += (int) ($s['interceptions'] ?? 0);
                $totals['sacks'] += (float) ($s['sacks'] ?? 0);
                $totals['tackles'] += (int) ($s['tackles'] ?? 0);
            }
            $totals['total_yards'] = $totals['pass_yards'] + $totals['rush_yards'];
            return $totals;
        };

        $grades = $game['player_grades'] ? json_decode($game['player_grades'], true) : [];

        // Normalize player stat fields: frontend reads 'name' and 'position'
        $normalizePlayerStats = function (array $stats): array {
            return array_map(function ($s) {
                $s['name'] = $s['player_name'] ?? 'Unknown';
                $s['position'] = $s['player_position'] ?? '';
                $s['id'] = (int) ($s['player_id'] ?? $s['id'] ?? 0);
                return $s;
            }, $stats);
        };

        Response::json([
            'game' => [
                'id' => (int) $game['id'],
                'league_id' => (int) $game['league_id'],
                'season_id' => (int) $game['season_id'],
                'week' => (int) $game['week'],
                'home_team_id' => (int) $game['home_team_id'],
                'away_team_id' => (int) $game['away_team_id'],
                'home_score' => (int) $game['home_score'],
                'away_score' => (int) $game['away_score'],
                'is_simulated' => (bool) $game['is_simulated'],
                'weather' => $game['weather'],
                'turning_point' => $game['turning_point'],
                'home_team' => $homeTeam,
                'away_team' => $awayTeam,
            ],
            'home' => [
                'totals' => $calcTotals($homeStats),
                'players' => $normalizePlayerStats($homeStats),
            ],
            'away' => [
                'totals' => $calcTotals($awayStats),
                'players' => $normalizePlayerStats($awayStats),
            ],
            'grades' => $grades,
        ]);
    }
}
