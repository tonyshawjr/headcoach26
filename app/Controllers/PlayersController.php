<?php

namespace App\Controllers;

use App\Helpers\Response;
use App\Middleware\AuthMiddleware;
use App\Models\Player;
use App\Models\GameStat;
use App\Models\Game;
use App\Models\Team;
use App\Models\League;
use App\Models\Contract;
use App\Models\Injury;

class PlayersController
{
    private Player $player;
    private GameStat $gameStat;
    private Game $game;
    private Team $team;
    private League $league;
    private Contract $contract;
    private Injury $injury;

    public function __construct()
    {
        $this->player = new Player();
        $this->gameStat = new GameStat();
        $this->game = new Game();
        $this->team = new Team();
        $this->league = new League();
        $this->contract = new Contract();
        $this->injury = new Injury();
    }

    /**
     * GET /api/teams/{team_id}/players
     * All players for a team.
     */
    public function roster(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $teamId = (int) $params['team_id'];
        $team = $this->team->find($teamId);
        if (!$team) {
            Response::notFound('Team not found');
            return;
        }

        $activePlayers = $this->player->getByTeam($teamId, 'active');
        $practiceSquad = $this->player->getByTeam($teamId, 'practice_squad');

        // Get active injuries for this team
        $injuries = $this->injury->getActiveByTeam($teamId);
        $injuredMap = [];
        foreach ($injuries as $inj) {
            $injuredMap[(int) $inj['player_id']] = [
                'type' => $inj['type'],
                'severity' => $inj['severity'],
                'weeks_remaining' => (int) $inj['weeks_remaining'],
            ];
        }

        // Attach injury info to players
        $addInjuryInfo = function (array $players) use ($injuredMap): array {
            return array_map(function ($p) use ($injuredMap) {
                $p['injury'] = $injuredMap[(int) $p['id']] ?? null;
                return $p;
            }, $players);
        };

        Response::json([
            'team' => [
                'id' => (int) $team['id'],
                'city' => $team['city'],
                'name' => $team['name'],
                'abbreviation' => $team['abbreviation'],
            ],
            'active' => $addInjuryInfo($activePlayers),
            'practice_squad' => $addInjuryInfo($practiceSquad),
            'counts' => [
                'active' => count($activePlayers),
                'practice_squad' => count($practiceSquad),
                'injured' => count($injuries),
            ],
        ]);
    }

    /**
     * GET /api/players/{id}
     * Player detail with Madden-style grouped ratings.
     */
    public function show(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $player = $this->player->find((int) $params['id']);
        if (!$player) {
            Response::notFound('Player not found');
            return;
        }

        // Decode instincts (stored as superstar_abilities in DB)
        $player['_instincts'] = json_decode($player['superstar_abilities'] ?? '[]', true);

        // Build position-relevant grouped ratings
        $ratings = Player::buildGroupedRatings($player);

        // Get team info
        $team = $player['team_id'] ? $this->team->find((int) $player['team_id']) : null;

        // Get contract
        $contracts = $this->contract->all(['player_id' => $player['id']], 'id DESC', 1);
        $currentContract = $contracts[0] ?? null;

        // Get active injury
        $injuries = $this->injury->all(['player_id' => $player['id']], 'id DESC', 1);
        $activeInjury = null;
        if (!empty($injuries) && (int) $injuries[0]['weeks_remaining'] > 0) {
            $activeInjury = $injuries[0];
        }

        Response::json([
            'player' => [
                'id'              => (int) $player['id'],
                'first_name'      => $player['first_name'],
                'last_name'       => $player['last_name'],
                'position'        => $player['position'],
                'position_type'   => $player['position_type'],
                'age'             => (int) $player['age'],
                'overall_rating'  => (int) $player['overall_rating'],
                'potential'       => $player['potential'],
                'personality'     => $player['personality'],
                'morale'          => $player['morale'],
                'experience'      => (int) $player['experience'],
                'college'         => $player['college'],
                'jersey_number'   => $player['jersey_number'] !== null ? (int) $player['jersey_number'] : null,
                'is_rookie'       => (bool) $player['is_rookie'],
                'status'          => $player['status'],
                // New bio / metadata
                'height'              => $player['height'] !== null ? (int) $player['height'] : null,
                'weight'              => $player['weight'] !== null ? (int) $player['weight'] : null,
                'handedness'          => $player['handedness'] !== null ? (int) $player['handedness'] : null,
                'birthdate'           => $player['birthdate'],
                'years_pro'           => (int) ($player['years_pro'] ?? 0),
                'archetype'           => $player['archetype'],
                'edge'                => $player['x_factor'],
                'instincts'           => $player['_instincts'],
                'awareness'           => (int) $player['awareness'],
                'injury_prone'        => (int) $player['injury_prone'],
                'running_style'       => $player['running_style'] ?? null,
            ],
            'ratings' => $ratings,
            'team' => $team ? [
                'id'            => (int) $team['id'],
                'city'          => $team['city'],
                'name'          => $team['name'],
                'abbreviation'  => $team['abbreviation'],
                'primary_color' => $team['primary_color'],
            ] : null,
            'contract' => $currentContract,
            'injury' => $activeInjury,
            'free_agent' => $this->getFreeAgentInfo($player['id']),
        ]);
    }

    private function getFreeAgentInfo(int $playerId): ?array
    {
        $db = \App\Database\Connection::getInstance()->getPdo();
        $stmt = $db->prepare(
            "SELECT id, market_value, asking_salary, status FROM free_agents WHERE player_id = ? AND status = 'available' LIMIT 1"
        );
        $stmt->execute([$playerId]);
        $fa = $stmt->fetch();
        return $fa ? [
            'free_agent_id' => (int) $fa['id'],
            'market_value' => (int) $fa['market_value'],
            'asking_salary' => (int) $fa['asking_salary'],
        ] : null;
    }

    /**
     * GET /api/players/{id}/stats
     * Career and current season stats aggregated from game_stats.
     */
    public function stats(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $player = $this->player->find((int) $params['id']);
        if (!$player) {
            Response::notFound('Player not found');
            return;
        }

        // Get current season
        $season = $this->league->getCurrentSeason((int) $player['league_id']);
        $seasonId = $season ? (int) $season['id'] : 0;

        // Season stats (aggregated)
        $seasonStats = $this->gameStat->query(
            "SELECT
                COUNT(*) as games_played,
                SUM(pass_attempts) as pass_attempts,
                SUM(pass_completions) as pass_completions,
                SUM(pass_yards) as pass_yards,
                SUM(pass_tds) as pass_tds,
                SUM(interceptions) as interceptions,
                SUM(rush_attempts) as rush_attempts,
                SUM(rush_yards) as rush_yards,
                SUM(rush_tds) as rush_tds,
                SUM(targets) as targets,
                SUM(receptions) as receptions,
                SUM(rec_yards) as rec_yards,
                SUM(rec_tds) as rec_tds,
                SUM(tackles) as tackles,
                SUM(sacks) as sacks,
                SUM(interceptions_def) as interceptions_def,
                SUM(forced_fumbles) as forced_fumbles,
                SUM(fg_attempts) as fg_attempts,
                SUM(fg_made) as fg_made
             FROM game_stats gs
             JOIN games g ON g.id = gs.game_id
             WHERE gs.player_id = ? AND g.season_id = ?",
            [$player['id'], $seasonId]
        );

        // Career stats (aggregated across all seasons)
        $careerStats = $this->gameStat->query(
            "SELECT
                COUNT(*) as games_played,
                SUM(pass_attempts) as pass_attempts,
                SUM(pass_completions) as pass_completions,
                SUM(pass_yards) as pass_yards,
                SUM(pass_tds) as pass_tds,
                SUM(interceptions) as interceptions,
                SUM(rush_attempts) as rush_attempts,
                SUM(rush_yards) as rush_yards,
                SUM(rush_tds) as rush_tds,
                SUM(targets) as targets,
                SUM(receptions) as receptions,
                SUM(rec_yards) as rec_yards,
                SUM(rec_tds) as rec_tds,
                SUM(tackles) as tackles,
                SUM(sacks) as sacks,
                SUM(interceptions_def) as interceptions_def,
                SUM(forced_fumbles) as forced_fumbles,
                SUM(fg_attempts) as fg_attempts,
                SUM(fg_made) as fg_made
             FROM game_stats
             WHERE player_id = ?",
            [$player['id']]
        );

        Response::json([
            'player_id' => (int) $player['id'],
            'name' => $player['first_name'] . ' ' . $player['last_name'],
            'position' => $player['position'],
            'season' => $seasonStats[0] ?? null,
            'career' => $careerStats[0] ?? null,
        ]);
    }

    /**
     * GET /api/players/{id}/game-log
     * Game-by-game stats for the current season.
     */
    public function gameLog(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $player = $this->player->find((int) $params['id']);
        if (!$player) {
            Response::notFound('Player not found');
            return;
        }

        $season = $this->league->getCurrentSeason((int) $player['league_id']);
        $seasonId = $season ? (int) $season['id'] : 0;

        $gameLog = $this->gameStat->query(
            "SELECT gs.*, g.week, g.home_team_id, g.away_team_id,
                    g.home_score, g.away_score,
                    ht.abbreviation as home_abbr, at.abbreviation as away_abbr
             FROM game_stats gs
             JOIN games g ON g.id = gs.game_id
             JOIN teams ht ON ht.id = g.home_team_id
             JOIN teams at ON at.id = g.away_team_id
             WHERE gs.player_id = ? AND g.season_id = ?
             ORDER BY g.week ASC",
            [$player['id'], $seasonId]
        );

        // Annotate each entry with opponent info
        $log = array_map(function ($entry) use ($player) {
            $isHome = (int) $entry['team_id'] === (int) $entry['home_team_id'];
            $entry['opponent'] = $isHome ? $entry['away_abbr'] : $entry['home_abbr'];
            $entry['location'] = $isHome ? 'home' : 'away';
            $teamScore = $isHome ? $entry['home_score'] : $entry['away_score'];
            $oppScore = $isHome ? $entry['away_score'] : $entry['home_score'];
            $entry['result'] = $teamScore > $oppScore ? 'W' : ($teamScore < $oppScore ? 'L' : 'T');
            $entry['score'] = "{$teamScore}-{$oppScore}";
            return $entry;
        }, $gameLog);

        Response::json([
            'player_id' => (int) $player['id'],
            'name' => $player['first_name'] . ' ' . $player['last_name'],
            'position' => $player['position'],
            'season_year' => $season['year'] ?? null,
            'games' => $log,
        ]);
    }
}
