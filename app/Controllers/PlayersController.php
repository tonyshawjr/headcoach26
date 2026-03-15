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
use App\Services\PlayerDecisionEngine;
use App\Services\ContractEngine;
use App\Services\FranchiseTagEngine;

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
        $injuredReserve = $this->player->getByTeam($teamId, 'injured_reserve');

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
            'injured_reserve' => $addInjuryInfo($injuredReserve),
            'counts' => [
                'active' => count($activePlayers),
                'practice_squad' => count($practiceSquad),
                'injured_reserve' => count($injuredReserve),
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
                'image_url'           => $player['image_url'] ?? null,
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
            'awards' => $this->getPlayerAwards((int) $player['id']),
            'free_agent' => $this->getFreeAgentInfo($player['id']),
            'scout_report' => $this->generateScoutReport($player, $ratings),
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
     * POST /api/players/{id}/move-to-active
     * Move a player to the active roster (from PS or IR).
     */
    public function moveToActive(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $player = $this->player->find((int) $params['id']);
        if (!$player) {
            Response::notFound('Player not found');
            return;
        }

        // Verify the player belongs to the coach's team
        $coach = $auth;
        if ((int) $player['team_id'] !== (int) $coach['team_id']) {
            Response::json(['error' => 'You can only manage your own roster'], 403);
            return;
        }

        // If player is on IR with weeks remaining, block activation
        if ($player['status'] === 'injured_reserve') {
            $injuries = $this->injury->all(['player_id' => $player['id']], 'id DESC', 1);
            if (!empty($injuries) && (int) $injuries[0]['weeks_remaining'] > 0) {
                Response::json(['error' => 'Player still has ' . $injuries[0]['weeks_remaining'] . ' weeks remaining on IR'], 400);
                return;
            }
        }

        // Check active roster limit (53)
        $activeCount = $this->player->count(['team_id' => $player['team_id'], 'status' => 'active']);
        if ($activeCount >= 53) {
            Response::json(['error' => 'Active roster is full (53 players). Release or move a player first.'], 400);
            return;
        }

        $this->player->update((int) $player['id'], ['status' => 'active']);

        Response::json([
            'success' => true,
            'message' => $player['first_name'] . ' ' . $player['last_name'] . ' moved to active roster',
        ]);
    }

    /**
     * POST /api/players/{id}/move-to-practice-squad
     * Move a player to the practice squad.
     */
    public function moveToPracticeSquad(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $player = $this->player->find((int) $params['id']);
        if (!$player) {
            Response::notFound('Player not found');
            return;
        }

        $coach = $auth;
        if ((int) $player['team_id'] !== (int) $coach['team_id']) {
            Response::json(['error' => 'You can only manage your own roster'], 403);
            return;
        }

        // Check practice squad limit (16)
        $psCount = $this->player->count(['team_id' => $player['team_id'], 'status' => 'practice_squad']);
        if ($psCount >= 16) {
            Response::json(['error' => 'Practice squad is full (16 players).'], 400);
            return;
        }

        $this->player->update((int) $player['id'], ['status' => 'practice_squad']);

        Response::json([
            'success' => true,
            'message' => $player['first_name'] . ' ' . $player['last_name'] . ' moved to practice squad',
        ]);
    }

    /**
     * POST /api/players/{id}/move-to-ir
     * Move a player to injured reserve.
     */
    public function moveToIR(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $player = $this->player->find((int) $params['id']);
        if (!$player) {
            Response::notFound('Player not found');
            return;
        }

        $coach = $auth;
        if ((int) $player['team_id'] !== (int) $coach['team_id']) {
            Response::json(['error' => 'You can only manage your own roster'], 403);
            return;
        }

        $this->player->update((int) $player['id'], ['status' => 'injured_reserve']);

        Response::json([
            'success' => true,
            'message' => $player['first_name'] . ' ' . $player['last_name'] . ' placed on injured reserve',
        ]);
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

        // Add computed stats (passer rating, completion %, yards per attempt, etc.)
        $position = $player['position'];
        $seasonRow = $seasonStats[0] ?? null;
        $careerRow = $careerStats[0] ?? null;

        if ($seasonRow) $seasonRow = $this->addComputedStats($seasonRow, $position);
        if ($careerRow) $careerRow = $this->addComputedStats($careerRow, $position);

        // Year-by-year career stats (one row per season)
        // Year-by-year grouped by season AND team (so traded players show separate rows)
        $yearByYear = $this->gameStat->query(
            "SELECT
                s.year as season_year,
                t.abbreviation as team_abbr,
                t.primary_color as team_color,
                COUNT(*) as games_played,
                SUM(gs.pass_attempts) as pass_attempts,
                SUM(gs.pass_completions) as pass_completions,
                SUM(gs.pass_yards) as pass_yards,
                SUM(gs.pass_tds) as pass_tds,
                SUM(gs.interceptions) as interceptions,
                SUM(gs.rush_attempts) as rush_attempts,
                SUM(gs.rush_yards) as rush_yards,
                SUM(gs.rush_tds) as rush_tds,
                SUM(gs.targets) as targets,
                SUM(gs.receptions) as receptions,
                SUM(gs.rec_yards) as rec_yards,
                SUM(gs.rec_tds) as rec_tds,
                SUM(gs.tackles) as tackles,
                SUM(gs.sacks) as sacks,
                SUM(gs.interceptions_def) as interceptions_def,
                SUM(gs.forced_fumbles) as forced_fumbles,
                SUM(gs.fg_attempts) as fg_attempts,
                SUM(gs.fg_made) as fg_made
             FROM game_stats gs
             JOIN games g ON g.id = gs.game_id
             JOIN seasons s ON s.id = g.season_id
             JOIN teams t ON t.id = gs.team_id
             WHERE gs.player_id = ?
             GROUP BY s.year, gs.team_id
             ORDER BY s.year DESC, games_played DESC",
            [$player['id']]
        );

        // Add computed stats to each year
        foreach ($yearByYear as &$yearRow) {
            $yearRow = $this->addComputedStats($yearRow, $position);
        }
        unset($yearRow);

        // Merge in historical stats (pre-franchise career data)
        $historicalStmt = $this->gameStat->query(
            "SELECT * FROM historical_stats WHERE player_id = ? ORDER BY season_year DESC",
            [$player['id']]
        );

        // Build a set of years we already have from game_stats
        $existingYears = array_column($yearByYear, 'season_year');

        foreach ($historicalStmt as $hRow) {
            // Don't duplicate years that have real game_stats
            if (in_array($hRow['season_year'], $existingYears)) continue;

            $historicalRow = [
                'season_year' => $hRow['season_year'],
                'games_played' => (int) $hRow['games_played'],
                'pass_attempts' => (int) $hRow['pass_attempts'],
                'pass_completions' => (int) $hRow['pass_completions'],
                'pass_yards' => (int) $hRow['pass_yards'],
                'pass_tds' => (int) $hRow['pass_tds'],
                'interceptions' => (int) $hRow['interceptions'],
                'rush_attempts' => (int) $hRow['rush_attempts'],
                'rush_yards' => (int) $hRow['rush_yards'],
                'rush_tds' => (int) $hRow['rush_tds'],
                'targets' => (int) $hRow['targets'],
                'receptions' => (int) $hRow['receptions'],
                'rec_yards' => (int) $hRow['rec_yards'],
                'rec_tds' => (int) $hRow['rec_tds'],
                'tackles' => (int) $hRow['tackles'],
                'sacks' => (float) $hRow['sacks'],
                'interceptions_def' => (int) $hRow['interceptions_def'],
                'forced_fumbles' => (int) $hRow['forced_fumbles'],
                'fg_attempts' => (int) $hRow['fg_attempts'],
                'fg_made' => (int) $hRow['fg_made'],
                'team_abbr' => $hRow['team_abbr'] ?? null,
            ];
            $historicalRow = $this->addComputedStats($historicalRow, $position);
            $yearByYear[] = $historicalRow;
        }

        // Re-sort by year descending
        usort($yearByYear, fn($a, $b) => ($b['season_year'] ?? 0) <=> ($a['season_year'] ?? 0));

        // Recalculate career totals including historical
        if (!empty($yearByYear)) {
            $careerRow = ['games_played' => 0];
            $sumFields = ['pass_attempts', 'pass_completions', 'pass_yards', 'pass_tds', 'interceptions',
                'rush_attempts', 'rush_yards', 'rush_tds', 'targets', 'receptions', 'rec_yards', 'rec_tds',
                'tackles', 'sacks', 'interceptions_def', 'forced_fumbles', 'fg_attempts', 'fg_made'];
            foreach ($sumFields as $f) $careerRow[$f] = 0;
            foreach ($yearByYear as $yr) {
                $careerRow['games_played'] += (int) ($yr['games_played'] ?? 0);
                foreach ($sumFields as $f) {
                    $careerRow[$f] += ($yr[$f] ?? 0);
                }
            }
            $careerRow = $this->addComputedStats($careerRow, $position);
        }

        $seasonYear = $season ? ($season['year'] ?? null) : null;

        Response::json([
            'player_id' => (int) $player['id'],
            'name' => $player['first_name'] . ' ' . $player['last_name'],
            'position' => $position,
            'season' => $seasonRow,
            'season_year' => $seasonYear,
            'career' => $careerRow,
            'career_by_year' => $yearByYear,
        ]);
    }

    /**
     * Add computed/derived stats based on position.
     */
    private function addComputedStats(array $stats, string $position): array
    {
        $att = (int) ($stats['pass_attempts'] ?? 0);
        $comp = (int) ($stats['pass_completions'] ?? 0);
        $yds = (int) ($stats['pass_yards'] ?? 0);
        $tds = (int) ($stats['pass_tds'] ?? 0);
        $ints = (int) ($stats['interceptions'] ?? 0);
        $rushAtt = (int) ($stats['rush_attempts'] ?? 0);
        $rushYds = (int) ($stats['rush_yards'] ?? 0);
        $rec = (int) ($stats['receptions'] ?? 0);
        $recYds = (int) ($stats['rec_yards'] ?? 0);
        $targets = (int) ($stats['targets'] ?? 0);
        $fgAtt = (int) ($stats['fg_attempts'] ?? 0);
        $fgMade = (int) ($stats['fg_made'] ?? 0);
        $gp = (int) ($stats['games_played'] ?? 1);

        // QB computed stats
        if ($att > 0) {
            $stats['comp_pct'] = round(($comp / $att) * 100, 1);
            $stats['yards_per_attempt'] = round($yds / $att, 1);
            $stats['td_pct'] = round(($tds / $att) * 100, 1);
            $stats['int_pct'] = round(($ints / $att) * 100, 1);

            // NFL Passer Rating formula (scale 0-158.3)
            $a = max(0, min(2.375, (($comp / $att) - 0.3) * 5));
            $b = max(0, min(2.375, (($yds / $att) - 3) * 0.25));
            $c = max(0, min(2.375, ($tds / $att) * 20));
            $d = max(0, min(2.375, 2.375 - (($ints / $att) * 25)));
            $stats['passer_rating'] = round((($a + $b + $c + $d) / 6) * 100, 1);

            $stats['yards_per_game'] = $gp > 0 ? round($yds / $gp, 1) : 0;
        }

        // Rushing computed stats
        if ($rushAtt > 0) {
            $stats['yards_per_carry'] = round($rushYds / $rushAtt, 1);
            $stats['rush_yards_per_game'] = $gp > 0 ? round($rushYds / $gp, 1) : 0;
        }

        // Receiving computed stats
        if ($targets > 0) {
            $stats['catch_pct'] = round(($rec / $targets) * 100, 1);
        }
        if ($rec > 0) {
            $stats['yards_per_catch'] = round($recYds / $rec, 1);
            $stats['rec_yards_per_game'] = $gp > 0 ? round($recYds / $gp, 1) : 0;
        }

        // Kicking computed stats
        if ($fgAtt > 0) {
            $stats['fg_pct'] = round(($fgMade / $fgAtt) * 100, 1);
        }

        return $stats;
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
                    ht.abbreviation as home_abbr, at.abbreviation as away_abbr,
                    pt.abbreviation as player_team_abbr, pt.primary_color as player_team_color
             FROM game_stats gs
             JOIN games g ON g.id = gs.game_id
             JOIN teams ht ON ht.id = g.home_team_id
             JOIN teams at ON at.id = g.away_team_id
             JOIN teams pt ON pt.id = gs.team_id
             WHERE gs.player_id = ? AND g.season_id = ?
             ORDER BY g.week ASC",
            [$player['id'], $seasonId]
        );

        // Annotate each entry with opponent info and player's team
        $log = array_map(function ($entry) use ($player) {
            $isHome = (int) $entry['team_id'] === (int) $entry['home_team_id'];
            $entry['opponent'] = $isHome ? $entry['away_abbr'] : $entry['home_abbr'];
            $entry['location'] = $isHome ? 'home' : 'away';
            $entry['team'] = $entry['player_team_abbr'];
            $entry['team_color'] = $entry['player_team_color'];
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

    /**
     * GET /api/players/search?q=...
     * Search players by name across all teams in the user's league.
     */
    public function search(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 2) {
            Response::json(['players' => []]);
            return;
        }

        $leagueId = (int) $auth['league_id'];
        $term = '%' . $q . '%';

        $rows = $this->player->query(
            "SELECT p.id, p.first_name, p.last_name, p.position,
                    p.overall_rating, p.age, p.team_id, p.status,
                    t.city AS team_city, t.name AS team_name, t.abbreviation AS team_abbreviation
             FROM players p
             LEFT JOIN teams t ON t.id = p.team_id
             WHERE (p.first_name || ' ' || p.last_name LIKE ? OR p.last_name LIKE ? OR p.first_name LIKE ?)
               AND (t.league_id = ? OR p.league_id = ?)
             ORDER BY p.overall_rating DESC
             LIMIT 20",
            [$term, $term, $term, $leagueId, $leagueId]
        );

        $players = array_map(function ($r) {
            return [
                'id'                => (int) $r['id'],
                'first_name'        => $r['first_name'],
                'last_name'         => $r['last_name'],
                'position'          => $r['position'],
                'overall_rating'    => (int) $r['overall_rating'],
                'age'               => (int) $r['age'],
                'team_id'           => $r['team_id'] ? (int) $r['team_id'] : null,
                'team_city'         => $r['team_city'] ?? null,
                'team_name'         => $r['team_name'] ?? null,
                'team_abbreviation' => $r['team_abbreviation'] ?? null,
                'status'            => $r['status'],
            ];
        }, $rows);

        Response::json(['players' => $players]);
    }

    // ================================================================
    //  Contract Status & Extension
    // ================================================================

    /**
     * GET /api/players/{id}/contract-status
     * Returns contract details, extension eligibility, willingness, and market value.
     */
    public function contractStatus(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $player = $this->player->find((int) $params['id']);
        if (!$player) {
            Response::notFound('Player not found');
            return;
        }

        // Get current contract
        $contracts = $this->contract->all(['player_id' => $player['id']], 'id DESC', 1);
        $currentContract = $contracts[0] ?? null;

        $yearsRemaining = $currentContract ? (int) ($currentContract['years_remaining'] ?? 0) : 0;
        $contractType = $currentContract['contract_type'] ?? 'standard';
        // Eligible only if expiring AND not already extended this season
        $isEligible = $yearsRemaining <= 1 && $contractType !== 'extension';

        // Get team info
        $team = $player['team_id'] ? $this->team->find((int) $player['team_id']) : null;

        // Market value
        $contractEngine = new ContractEngine();
        $marketValue = $contractEngine->calculateMarketValue($player);

        // Player's willingness to re-sign
        $decisionEngine = new PlayerDecisionEngine();
        $willingness = $decisionEngine->wouldReSignWithCurrentTeam((int) $player['id']);
        $preferences = $decisionEngine->getPlayerPreferences((int) $player['id']);

        // Is this the user's player?
        $isMyPlayer = $team && (int) ($auth['team_id'] ?? 0) === (int) $team['id'];

        Response::json([
            'player' => [
                'id'             => (int) $player['id'],
                'first_name'     => $player['first_name'],
                'last_name'      => $player['last_name'],
                'position'       => $player['position'],
                'age'            => (int) $player['age'],
                'overall_rating' => (int) $player['overall_rating'],
                'personality'    => $player['personality'],
                'morale'         => $player['morale'],
            ],
            'contract' => $currentContract ? [
                'id'              => (int) $currentContract['id'],
                'salary_annual'   => (int) $currentContract['salary_annual'],
                'years_total'     => (int) $currentContract['years_total'],
                'years_remaining' => $yearsRemaining,
                'cap_hit'         => (int) ($currentContract['cap_hit'] ?? 0),
                'guaranteed'      => (int) ($currentContract['guaranteed'] ?? 0),
                'dead_cap'        => (int) ($currentContract['dead_cap'] ?? 0),
                'contract_type'   => $currentContract['contract_type'] ?? 'standard',
            ] : null,
            'team' => $team ? [
                'id'            => (int) $team['id'],
                'city'          => $team['city'],
                'name'          => $team['name'],
                'abbreviation'  => $team['abbreviation'],
            ] : null,
            'eligible_for_extension' => $isEligible,
            'market_value'           => $marketValue,
            'willingness'            => $willingness,
            'preferences'            => $preferences,
            'is_my_player'           => $isMyPlayer,

            // ── GM Advice ──────────────────────────────────────────
            'gm_advice'  => $isEligible && $isMyPlayer ? $this->generateGmAdvice($player, $team, $marketValue, $willingness, $contractEngine) : null,

            // ── Offer Presets (Team-Friendly / Balanced / Player-Friendly) ──
            'offer_presets' => $isEligible && $isMyPlayer ? $this->generateOfferPresets($player, $marketValue, $willingness) : null,

            // ── Cap Context ────────────────────────────────────────
            'cap_info' => $isMyPlayer && $team ? $contractEngine->calculateTeamCap((int) $team['id']) : null,
        ]);
    }

    /**
     * Generate GM advice about whether to re-sign this player.
     */
    private function generateGmAdvice(array $player, ?array $team, int $marketValue, array $willingness, $contractEngine): array
    {
        $ovr = (int) $player['overall_rating'];
        $age = (int) $player['age'];
        $pos = $player['position'];
        $potential = $player['potential'] ?? 'average';

        // Is this player important to us?
        $pdo = \App\Database\Connection::getInstance()->getPdo();
        $depthStmt = $pdo->prepare(
            "SELECT slot FROM depth_chart WHERE team_id = ? AND player_id = ? ORDER BY slot LIMIT 1"
        );
        $depthStmt->execute([(int) ($team['id'] ?? 0), (int) $player['id']]);
        $depthSlot = $depthStmt->fetchColumn();
        $isStarter = $depthSlot !== false && (int) $depthSlot === 1;

        // Cap situation
        $capInfo = $team ? $contractEngine->calculateTeamCap((int) $team['id']) : null;
        $capRemaining = $capInfo ? ($capInfo['cap_remaining'] ?? 0) : 0;
        $capTight = $capRemaining < $marketValue * 2;

        // Build the advice
        $priority = 'medium';
        $recommendation = 'consider';
        $reasoning = '';

        if ($ovr >= 85 && $age <= 28) {
            $priority = 'critical';
            $recommendation = 'must_sign';
            $reasoning = "Coach, we can't let {$player['first_name']} walk. He's a cornerstone of this team — {$ovr} OVR at {$age} years old. Lock him up.";
        } elseif ($ovr >= 80 && $isStarter && $age <= 30) {
            $priority = 'high';
            $recommendation = 'should_sign';
            $reasoning = "{$player['first_name']} is a quality starter for us. At {$age}, he's still got good years left. I'd recommend getting this done.";
        } elseif ($ovr >= 75 && $isStarter && $potential === 'elite') {
            $priority = 'high';
            $recommendation = 'should_sign';
            $reasoning = "This kid has elite development. He's only going to get better. If we let him walk, we'll regret it.";
        } elseif ($ovr >= 75 && $isStarter && $age >= 31) {
            $priority = 'medium';
            $recommendation = 'consider';
            $reasoning = "{$player['first_name']} has been solid, but he's {$age}. We should look at the market first — we might find a younger replacement in free agency or the draft.";
        } elseif ($age >= 32 && $potential !== 'elite') {
            $priority = 'low';
            $recommendation = 'let_walk';
            $reasoning = "At {$age} with {$potential} development, {$player['first_name']}'s best days are behind him. I'd let him test the market and use that cap space elsewhere.";
        } elseif (!$isStarter) {
            $priority = 'low';
            $recommendation = 'let_walk';
            $reasoning = "{$player['first_name']} is a depth piece for us. We can find that production in free agency or the draft for less.";
        } elseif ($potential === 'limited') {
            $priority = 'low';
            $recommendation = 'let_walk';
            $reasoning = "Limited development means {$player['first_name']} isn't going to get any better. Not worth investing long-term.";
        } else {
            $priority = 'medium';
            $recommendation = 'consider';
            $reasoning = "{$player['first_name']} is a capable player. It depends on whether we can get a fair deal done.";
        }

        // Add cap context
        if ($capTight && $recommendation !== 'must_sign') {
            $reasoning .= " Keep in mind, we're tight on cap space (" . number_format($capRemaining / 1000000, 1) . "M remaining).";
        }

        // If the player doesn't want to re-sign
        if (!$willingness['open_to_extension']) {
            $reasoning .= " One concern — he's not showing much interest in staying. We might need to overpay or accept he's gone.";
        }

        return [
            'priority' => $priority,          // critical, high, medium, low
            'recommendation' => $recommendation, // must_sign, should_sign, consider, let_walk
            'reasoning' => $reasoning,
            'is_starter' => $isStarter,
        ];
    }

    /**
     * Generate three offer presets: team-friendly, balanced, player-friendly.
     */
    private function generateOfferPresets(array $player, int $marketValue, array $willingness): array
    {
        $age = (int) $player['age'];
        $minSalary = (int) ($willingness['minimum_salary'] ?? $marketValue * 0.8);
        $prefYears = (int) ($willingness['preferred_years'] ?? 3);
        $vetMin = 1100000;

        // Team-friendly: below market, shorter term
        $teamFriendlySalary = max($vetMin, (int) ($marketValue * 0.82));
        $teamFriendlyYears = max(1, min($prefYears - 1, 2));

        // Balanced: at market value, standard term
        $balancedSalary = max($vetMin, $marketValue);
        $balancedYears = max(1, $prefYears);

        // Player-friendly: above market, longer term
        $playerFriendlySalary = max($vetMin + 100000, (int) ($marketValue * 1.15));
        $playerFriendlyYears = max(1, min(6, $prefYears + 1));

        // If team-friendly and balanced end up the same (happens at vet minimum),
        // differentiate by years instead
        if ($teamFriendlySalary === $balancedSalary) {
            // Team-friendly = shorter commitment, balanced = standard
            $teamFriendlyYears = 1;
            $balancedYears = max(2, $prefYears);
            // Make player-friendly meaningfully different
            $playerFriendlySalary = max($vetMin + 200000, (int) ($marketValue * 1.20));
        }

        // Calculate actual risk based on whether the offer meets what the player wants
        $calcRisk = function(int $salary, int $years) use ($minSalary, $prefYears) {
            $meetsMinSalary = $salary >= $minSalary;
            $meetsYears = $years >= $prefYears;

            if ($meetsMinSalary && $meetsYears) return 'low';
            if ($meetsMinSalary || $salary >= $minSalary * 0.95) return 'medium';
            return 'high';
        };

        $calcDesc = function(string $risk, int $salary, int $years) use ($minSalary, $marketValue) {
            if ($risk === 'low') return 'Meets what he wants. Should get it done.';
            if ($risk === 'medium') return 'Close to what he wants. Might need to negotiate.';
            if ($salary < $marketValue) return 'Below market value. He may push back.';
            return 'Short commitment. He may want more security.';
        };

        $presets = [];

        $tfRisk = $calcRisk($teamFriendlySalary, $teamFriendlyYears);
        $presets['team_friendly'] = [
            'label' => 'Team-Friendly',
            'description' => $calcDesc($tfRisk, $teamFriendlySalary, $teamFriendlyYears),
            'salary' => $teamFriendlySalary,
            'years' => $teamFriendlyYears,
            'total' => $teamFriendlySalary * $teamFriendlyYears,
            'risk' => $tfRisk,
        ];

        $balRisk = $calcRisk($balancedSalary, $balancedYears);
        $presets['balanced'] = [
            'label' => 'Balanced',
            'description' => $calcDesc($balRisk, $balancedSalary, $balancedYears),
            'salary' => $balancedSalary,
            'years' => $balancedYears,
            'total' => $balancedSalary * $balancedYears,
            'risk' => $balRisk,
        ];

        $pfRisk = $calcRisk($playerFriendlySalary, $playerFriendlyYears);
        $presets['player_friendly'] = [
            'label' => 'Player-Friendly',
            'description' => $calcDesc($pfRisk, $playerFriendlySalary, $playerFriendlyYears),
            'salary' => $playerFriendlySalary,
            'years' => $playerFriendlyYears,
            'total' => $playerFriendlySalary * $playerFriendlyYears,
            'risk' => $pfRisk,
        ];

        return $presets;
    }

    /**
     * GET /api/offseason/contract-planner
     * GM tells you who to sign, who to let walk, and what it'll cost.
     */
    public function contractPlanner(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $teamId = (int) $auth['team_id'];
        $pdo = \App\Database\Connection::getInstance()->getPdo();
        $contractEngine = new ContractEngine();
        $decisionEngine = new PlayerDecisionEngine();

        // Get team cap
        $capInfo = $contractEngine->calculateTeamCap($teamId);

        // Get all expiring players
        // Get expiring players — only show those on their ORIGINAL contract, not recently extended
        // If a player has multiple contracts, only look at the newest active one
        // Exclude anyone whose latest contract is an extension (they already re-signed)
        $stmt = $pdo->prepare(
            "SELECT p.*, c.salary_annual, c.years_remaining, c.cap_hit, c.contract_type, c.id as contract_id
             FROM players p
             JOIN contracts c ON p.id = c.player_id
             WHERE p.team_id = ? AND c.status = 'active' AND c.years_remaining <= 1
             AND c.id = (SELECT MAX(c2.id) FROM contracts c2 WHERE c2.player_id = p.id AND c2.status = 'active')
             AND c.contract_type NOT IN ('extension')
             ORDER BY p.overall_rating DESC"
        );
        $stmt->execute([$teamId]);
        $expiring = $stmt->fetchAll();

        // Check who's a starter
        $starterIds = [];
        $depthStmt = $pdo->prepare("SELECT player_id FROM depth_chart WHERE team_id = ? AND slot = 1");
        $depthStmt->execute([$teamId]);
        while ($row = $depthStmt->fetch()) {
            $starterIds[] = (int) $row['player_id'];
        }

        $mustSign = [];
        $shouldSign = [];
        $canLetGo = [];
        $totalMustCost = 0;
        $totalShouldCost = 0;

        foreach ($expiring as $p) {
            $marketValue = $contractEngine->calculateMarketValue($p);
            $isStarter = in_array((int) $p['id'], $starterIds);
            $ovr = (int) $p['overall_rating'];
            $age = (int) $p['age'];
            $potential = $p['potential'] ?? 'average';

            $playerInfo = [
                'id' => (int) $p['id'],
                'name' => $p['first_name'] . ' ' . $p['last_name'],
                'position' => $p['position'],
                'overall_rating' => $ovr,
                'age' => $age,
                'potential' => $potential,
                'is_starter' => $isStarter,
                'current_salary' => (int) $p['salary_annual'],
                'market_value' => $marketValue,
            ];

            // Categorize
            if ($ovr >= 85 && $age <= 28) {
                $playerInfo['reason'] = "Franchise cornerstone. Lock him up.";
                $playerInfo['gm_note'] = "Don't even think about letting this one go.";
                $mustSign[] = $playerInfo;
                $totalMustCost += $marketValue;
            } elseif ($isStarter && $ovr >= 78 && $age <= 30) {
                $playerInfo['reason'] = "Quality starter. Worth the investment.";
                $playerInfo['gm_note'] = "Losing him hurts our lineup.";
                $mustSign[] = $playerInfo;
                $totalMustCost += $marketValue;
            } elseif ($isStarter && ($potential === 'elite' || $potential === 'high') && $age <= 26) {
                $playerInfo['reason'] = "Young with upside. Could become a star.";
                $playerInfo['gm_note'] = "Invest now before he gets expensive.";
                $shouldSign[] = $playerInfo;
                $totalShouldCost += $marketValue;
            } elseif ($isStarter && $ovr >= 73) {
                $playerInfo['reason'] = "Solid contributor.";
                $playerInfo['gm_note'] = "Nice to keep if the money works.";
                $shouldSign[] = $playerInfo;
                $totalShouldCost += $marketValue;
            } else {
                // Can let go — find specific replacement
                $replacement = $this->findReplacement($pdo, $p, $teamId, $auth['league_id']);
                $alternative = '';
                if ($age >= 30 && $potential !== 'elite') {
                    $alternative = "Aging and limited upside.";
                } elseif (!$isStarter) {
                    $alternative = "Depth piece — not a priority.";
                } else {
                    $alternative = "Can find similar production for less.";
                }
                $playerInfo['reason'] = $alternative;
                $playerInfo['gm_note'] = "Save the money for bigger priorities.";
                $playerInfo['replacement'] = $replacement;
                $canLetGo[] = $playerInfo;
            }
        }

        $capRemaining = (int) ($capInfo['cap_remaining'] ?? 0);

        // GM summary
        $summary = '';
        if ($totalMustCost + $totalShouldCost <= $capRemaining) {
            $leftover = $capRemaining - $totalMustCost - $totalShouldCost;
            $summary = "Good news, Coach. We can afford to keep everyone that matters and still have about " .
                       number_format($leftover / 1000000, 0) . "M left for free agency and the draft.";
        } elseif ($totalMustCost <= $capRemaining) {
            $leftover = $capRemaining - $totalMustCost;
            $summary = "We can lock up the must-sign players, but we'll need to make tough choices on the others. " .
                       "After the must-signs, we'll have about " . number_format($leftover / 1000000, 0) .
                       "M left — not enough for everyone. Pick wisely.";
        } else {
            $summary = "We're in a tough spot, Coach. Even the must-sign players cost more than we have. " .
                       "We need to restructure some contracts or make cuts to free up space.";
        }

        Response::json([
            'summary' => $summary,
            'budget' => [
                'total' => (int) ($capInfo['cap_total'] ?? 0),
                'committed' => (int) ($capInfo['cap_used'] ?? 0),
                'available' => $capRemaining,
                'must_sign_cost' => $totalMustCost,
                'should_sign_cost' => $totalShouldCost,
                'after_must_signs' => $capRemaining - $totalMustCost,
                'after_all_signs' => $capRemaining - $totalMustCost - $totalShouldCost,
            ],
            'must_sign' => $mustSign,
            'should_sign' => $shouldSign,
            'can_let_go' => $canLetGo,
            'total_expiring' => count($expiring),
            'cap_fixes' => $capRemaining < 0 ? $this->suggestCapFixes($pdo, $teamId, abs($capRemaining), $contractEngine) : null,
        ]);
    }

    /**
     * Suggest ways to get under the salary cap.
     */
    private function suggestCapFixes(\PDO $pdo, int $teamId, int $amountOver, $contractEngine): array
    {
        $fixes = [];
        $fixes['over_by'] = $amountOver;
        $fixes['message'] = "You're $" . number_format($amountOver / 1000000, 1) . "M over the cap. Here's how to fix it:";

        // Option 1: Restructure expensive contracts (convert base salary to bonus, spreads cap hit)
        $restructureCandidates = $pdo->prepare(
            "SELECT c.id as contract_id, c.base_salary, c.cap_hit, c.years_remaining, c.signing_bonus,
                    p.id as player_id, p.first_name, p.last_name, p.position, p.overall_rating
             FROM contracts c JOIN players p ON c.player_id = p.id
             WHERE c.team_id = ? AND c.status = 'active' AND c.base_salary > 3000000 AND c.years_remaining >= 2
             ORDER BY c.base_salary DESC LIMIT 5"
        );
        $restructureCandidates->execute([$teamId]);
        $restructures = [];
        foreach ($restructureCandidates->fetchAll() as $c) {
            // Restructuring converts base salary to signing bonus
            // New cap hit = (base * 0.2) + prorated bonus over remaining years
            $convertable = (int) ($c['base_salary'] * 0.8); // convert 80% to bonus
            $newBonus = $convertable;
            $proratedPerYear = (int) ($newBonus / max(1, $c['years_remaining']));
            $newCapHit = (int) ($c['base_salary'] * 0.2) + $proratedPerYear + (int) ($c['signing_bonus'] / max(1, (int) $c['years_remaining'] + 1));
            $savings = (int) $c['cap_hit'] - $newCapHit;

            if ($savings > 500000) {
                $restructures[] = [
                    'contract_id' => (int) $c['contract_id'],
                    'player_id' => (int) $c['player_id'],
                    'name' => $c['first_name'] . ' ' . $c['last_name'],
                    'position' => $c['position'],
                    'overall_rating' => (int) $c['overall_rating'],
                    'current_cap_hit' => (int) $c['cap_hit'],
                    'new_cap_hit' => max(1100000, $newCapHit),
                    'savings' => $savings,
                    'note' => "Saves " . number_format($savings / 1000000, 1) . "M this year by spreading the cost over " . $c['years_remaining'] . " years. Increases future cap hits.",
                ];
            }
        }
        $fixes['restructure'] = $restructures;

        // Option 2: Cut players to save money
        $cutCandidates = $pdo->prepare(
            "SELECT c.id as contract_id, c.cap_hit, c.dead_cap, c.guaranteed,
                    p.id as player_id, p.first_name, p.last_name, p.position, p.overall_rating, p.age
             FROM contracts c JOIN players p ON c.player_id = p.id
             LEFT JOIN depth_chart dc ON dc.player_id = p.id AND dc.team_id = ? AND dc.slot = 1
             WHERE c.team_id = ? AND c.status = 'active' AND c.cap_hit > 1500000
             ORDER BY (c.cap_hit - COALESCE(c.dead_cap, 0)) DESC LIMIT 8"
        );
        $cutCandidates->execute([$teamId, $teamId]);
        $cuts = [];
        foreach ($cutCandidates->fetchAll() as $c) {
            $capHit = (int) $c['cap_hit'];
            $deadCap = (int) ($c['dead_cap'] ?? 0);
            $savings = $capHit - $deadCap;

            if ($savings > 500000) {
                $cuts[] = [
                    'player_id' => (int) $c['player_id'],
                    'name' => $c['first_name'] . ' ' . $c['last_name'],
                    'position' => $c['position'],
                    'overall_rating' => (int) $c['overall_rating'],
                    'age' => (int) $c['age'],
                    'cap_hit' => $capHit,
                    'dead_cap' => $deadCap,
                    'savings' => $savings,
                    'note' => "Cutting saves " . number_format($savings / 1000000, 1) . "M" .
                        ($deadCap > 0 ? " (but " . number_format($deadCap / 1000000, 1) . "M dead money stays on the books)" : ""),
                ];
            }
        }
        $fixes['cuts'] = $cuts;

        return $fixes;
    }

    /**
     * Find a specific replacement for a player the GM recommends letting go.
     * Checks free agents, draft prospects, and trade targets.
     */
    private function findReplacement(\PDO $pdo, array $player, int $teamId, int $leagueId): ?array
    {
        $pos = $player['position'];
        $ovr = (int) $player['overall_rating'];

        // Check free agents at this position
        $faStmt = $pdo->prepare(
            "SELECT fa.*, p.first_name, p.last_name, p.overall_rating, p.age, p.potential
             FROM free_agents fa JOIN players p ON fa.player_id = p.id
             WHERE fa.league_id = ? AND p.position = ? AND p.overall_rating >= ? AND fa.status = 'available'
             ORDER BY p.overall_rating DESC LIMIT 1"
        );
        $faStmt->execute([$leagueId, $pos, max(60, $ovr - 5)]);
        $fa = $faStmt->fetch();

        if ($fa) {
            $contractEngine = new ContractEngine();
            $faMv = $contractEngine->calculateMarketValue($fa);
            $currentSalary = (int) ($player['salary_annual'] ?? 0);

            // Only suggest the FA if they're actually a better deal
            // (higher OVR for similar money, or similar OVR for less money)
            $faOvr = (int) $fa['overall_rating'];
            $isCheaper = $faMv <= $currentSalary * 1.2; // within 20% of current salary
            $isUpgrade = $faOvr > $ovr;
            $isBetterValue = $isUpgrade || ($isCheaper && $faOvr >= $ovr - 2);

            if ($isBetterValue) {
                $note = '';
                if ($isUpgrade && $isCheaper) {
                    $note = "Upgrade available in free agency for similar money.";
                } elseif ($isUpgrade) {
                    $note = "Upgrade available in free agency.";
                } else {
                    $note = "Similar player available — saves cap space.";
                }

                return [
                    'type' => 'free_agent',
                    'player_id' => (int) $fa['player_id'],
                    'name' => $fa['first_name'] . ' ' . $fa['last_name'],
                    'position' => $pos,
                    'overall_rating' => $faOvr,
                    'age' => (int) $fa['age'],
                    'potential' => $fa['potential'],
                    'estimated_cost' => $faMv,
                    'note' => $note,
                ];
            }
        }

        // Check draft prospects at this position (if scouted)
        $draftStmt = $pdo->prepare(
            "SELECT dp.id, dp.first_name, dp.last_name, dp.projected_round, dp.stock_rating, dp.potential
             FROM draft_prospects dp
             JOIN draft_classes dc ON dp.draft_class_id = dc.id
             WHERE dc.league_id = ? AND dp.position = ? AND dp.is_drafted = 0
             AND dp.projected_round <= 3
             ORDER BY dp.stock_rating DESC LIMIT 1"
        );
        $draftStmt->execute([$leagueId, $pos]);
        $prospect = $draftStmt->fetch();

        if ($prospect) {
            return [
                'type' => 'draft',
                'prospect_id' => (int) $prospect['id'],
                'name' => $prospect['first_name'] . ' ' . $prospect['last_name'],
                'position' => $pos,
                'projected_round' => (int) $prospect['projected_round'],
                'potential' => $prospect['potential'],
                'note' => "Draft option — Round " . $prospect['projected_round'] . " prospect" .
                    ($prospect['potential'] === 'elite' ? " with elite development." :
                    ($prospect['potential'] === 'high' ? " with strong upside." : ".")),
            ];
        }

        // Check trade targets — other teams' players at this position who might be available
        $tradeStmt = $pdo->prepare(
            "SELECT p.id, p.first_name, p.last_name, p.overall_rating, p.age, p.potential,
                    t.city, t.name as team_name, t.abbreviation
             FROM players p JOIN teams t ON p.team_id = t.id
             WHERE p.league_id = ? AND p.position = ? AND p.team_id != ?
             AND p.overall_rating >= ? AND p.status = 'active'
             ORDER BY p.overall_rating DESC LIMIT 1"
        );
        $tradeStmt->execute([$leagueId, $pos, $teamId, max(65, $ovr - 3)]);
        $target = $tradeStmt->fetch();

        if ($target) {
            return [
                'type' => 'trade',
                'player_id' => (int) $target['id'],
                'name' => $target['first_name'] . ' ' . $target['last_name'],
                'position' => $pos,
                'overall_rating' => (int) $target['overall_rating'],
                'age' => (int) $target['age'],
                'team' => $target['abbreviation'],
                'note' => "Trade target from " . $target['abbreviation'] . " — " .
                    ((int) $target['overall_rating'] >= $ovr ? "upgrade." : "similar caliber."),
            ];
        }

        return null;
    }

    /**
     * POST /api/players/{id}/offer-extension
     * Body: { salary: int, years: int }
     * Player evaluates the extension offer and responds.
     */
    public function offerExtension(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $player = $this->player->find((int) $params['id']);
        if (!$player) {
            Response::notFound('Player not found');
            return;
        }

        // Verify the player is on the user's team
        $coach = $auth;
        if ((int) ($player['team_id'] ?? 0) !== (int) ($coach['team_id'] ?? 0)) {
            Response::json(['error' => 'You can only offer extensions to your own players'], 403);
            return;
        }

        // Check eligibility (must be in final year or expired)
        $contracts = $this->contract->all(['player_id' => $player['id']], 'id DESC', 1);
        $currentContract = $contracts[0] ?? null;
        $yearsRemaining = $currentContract ? (int) ($currentContract['years_remaining'] ?? 0) : 0;

        if ($yearsRemaining > 1) {
            Response::json(['error' => 'Player is not eligible for an extension. They have ' . $yearsRemaining . ' years remaining.'], 400);
            return;
        }

        // Parse offer
        $input = json_decode(file_get_contents('php://input'), true);
        $salary = (int) ($input['salary'] ?? 0);
        $years = (int) ($input['years'] ?? 0);
        $voidYears = (int) ($input['void_years'] ?? 0);
        $incentive = $input['incentive'] ?? null; // optional: {type, value, threshold}

        if ($salary <= 0 || $years <= 0 || $years > 6) {
            Response::json(['error' => 'Invalid offer. Salary must be positive and years between 1-6.'], 400);
            return;
        }

        if ($voidYears < 0 || $voidYears > 3) {
            Response::json(['error' => 'Void years must be between 0 and 3.'], 400);
            return;
        }

        // Check cap space
        $contractEngine = new ContractEngine();
        $teamId = (int) $player['team_id'];
        if (!$contractEngine->canAffordContract($teamId, $salary)) {
            Response::json(['error' => 'Your team cannot afford this contract. Check your cap space.'], 400);
            return;
        }

        // Player evaluates the offer
        $decisionEngine = new PlayerDecisionEngine();
        $evaluation = $decisionEngine->evaluateExtensionOffer((int) $player['id'], $salary, $years);

        if ($evaluation['interested']) {
            // Player accepts — create the new contract
            if ($voidYears > 0) {
                // Use void-year contract path
                $contractEngine->createVoidYearContract(
                    (int) $player['id'], $teamId, $salary, $years, $voidYears
                );
            } else {
                $this->createExtensionContract($player, $teamId, $salary, $years, $voidYears, $incentive);
            }

            // Resolve any holdout or active demand
            \App\Services\PlayerDemandEngine::resolveHoldout((int) $player['id']);

            $label = $voidYears > 0
                ? "{$years}yr + {$voidYears} void"
                : "{$years}-year";

            Response::json([
                'result'      => 'accepted',
                'message'     => $player['first_name'] . ' ' . $player['last_name'] . ' has agreed to a ' . $label . ', ' . $this->formatMoney($salary) . '/yr extension!',
                'reasoning'   => $evaluation['reasoning'],
                'willingness' => $evaluation['willingness'],
                'score'       => $evaluation['score'],
            ]);
            return;
        }

        if ($evaluation['willingness'] === 'reluctant' && $evaluation['counter_offer']) {
            // Player counters
            Response::json([
                'result'        => 'countered',
                'message'       => $player['first_name'] . ' ' . $player['last_name'] . ' wants to negotiate.',
                'reasoning'     => $evaluation['reasoning'],
                'willingness'   => $evaluation['willingness'],
                'score'         => $evaluation['score'],
                'counter_offer' => $evaluation['counter_offer'],
                'market_value'  => $evaluation['market_value'] ?? 0,
            ]);
            return;
        }

        // Player refuses
        Response::json([
            'result'      => 'refused',
            'message'     => $player['first_name'] . ' ' . $player['last_name'] . ' has declined the extension.',
            'reasoning'   => $evaluation['reasoning'],
            'willingness' => $evaluation['willingness'],
            'score'       => $evaluation['score'],
        ]);
    }

    /**
     * Create a new contract for an extension (supports void years and incentives).
     */
    private function createExtensionContract(array $player, int $teamId, int $salary, int $years, int $voidYears = 0, ?array $incentive = null): void
    {
        $db = \App\Database\Connection::getInstance()->getPdo();

        // Terminate old contract
        $db->prepare(
            "UPDATE contracts SET status = 'completed', years_remaining = 0 WHERE player_id = ? AND status = 'active'"
        )->execute([$player['id']]);

        // Create new contract
        $guaranteedPct = 0.35 + (min(99, (int) $player['overall_rating']) / 100) * 0.25;
        $totalValue = $salary * $years;
        $guaranteed = (int) ($totalValue * $guaranteedPct);
        $signingBonus = (int) ($guaranteed * 0.6);

        // Proration spreads over real + void years
        $prorationYears = $years + $voidYears;
        $baseSalary = $salary - (int) ($signingBonus / $prorationYears);
        $capHit = $baseSalary + (int) ($signingBonus / $prorationYears);

        // Incentive columns
        $hasIncentives = 0;
        $incentiveType = null;
        $incentiveValue = 0;
        $incentiveThreshold = null;
        if ($incentive && isset($incentive['type'], $incentive['value'])) {
            $hasIncentives = 1;
            $incentiveType = $incentive['type'];
            $incentiveValue = (int) $incentive['value'];
            $incentiveThreshold = json_encode($incentive['threshold'] ?? []);
        }

        $db->prepare(
            "INSERT INTO contracts (player_id, team_id, years_total, years_remaining, salary_annual,
             cap_hit, guaranteed, dead_cap, signing_bonus, base_salary, contract_type, total_value,
             void_years, has_incentives, incentive_type, incentive_value, incentive_threshold,
             status, signed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'extension', ?, ?, ?, ?, ?, ?, 'active', ?)"
        )->execute([
            $player['id'], $teamId, $years, $years,
            $salary, $capHit, $guaranteed, $signingBonus,
            $signingBonus, $baseSalary, $totalValue,
            $voidYears, $hasIncentives, $incentiveType, $incentiveValue, $incentiveThreshold,
            date('Y-m-d H:i:s'),
        ]);

        $contractId = (int) $db->lastInsertId();

        // If this is a void-year deal via the extension path, attach incentive via ContractEngine
        // (already handled inline above)

        // Update team cap
        $db->prepare("UPDATE teams SET cap_used = cap_used + ? WHERE id = ?")
            ->execute([$capHit, $teamId]);
    }

    // ================================================================
    //  Franchise Tag Endpoints
    // ================================================================

    /**
     * POST /api/players/{id}/franchise-tag
     * Apply a franchise tag to a player.
     * Body: { "type": "exclusive" | "non_exclusive" | "transition" }
     */
    public function applyFranchiseTag(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $playerId = (int) $params['id'];
        $body = Response::getJsonBody();
        $tagType = $body['type'] ?? '';

        if (!in_array($tagType, ['exclusive', 'non_exclusive', 'transition'], true)) {
            Response::error('Invalid tag type. Must be exclusive, non_exclusive, or transition.');
            return;
        }

        // Verify the player belongs to the user's team
        $player = $this->player->find($playerId);
        if (!$player) {
            Response::notFound('Player not found');
            return;
        }

        $teamId = (int) $player['team_id'];

        // Verify ownership: user must coach this team
        $db = \App\Database\Connection::getInstance()->getPdo();
        $stmt = $db->prepare(
            "SELECT id FROM coaches WHERE user_id = ? AND team_id = ? AND is_human = 1"
        );
        $stmt->execute([$auth['user_id'], $teamId]);
        if (!$stmt->fetch()) {
            Response::error('You do not control this player\'s team', 403);
            return;
        }

        $engine = new FranchiseTagEngine();

        $result = match ($tagType) {
            'exclusive'     => $engine->applyExclusiveTag($teamId, $playerId),
            'non_exclusive' => $engine->applyNonExclusiveTag($teamId, $playerId),
            'transition'    => $engine->applyTransitionTag($teamId, $playerId),
        };

        if (isset($result['error'])) {
            Response::error($result['error']);
            return;
        }

        Response::json($result);
    }

    /**
     * DELETE /api/players/{id}/franchise-tag
     * Remove a franchise tag from a player.
     */
    public function removeFranchiseTag(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $playerId = (int) $params['id'];

        $player = $this->player->find($playerId);
        if (!$player) {
            Response::notFound('Player not found');
            return;
        }

        $teamId = (int) $player['team_id'];

        // Verify ownership
        $db = \App\Database\Connection::getInstance()->getPdo();
        $stmt = $db->prepare(
            "SELECT id FROM coaches WHERE user_id = ? AND team_id = ? AND is_human = 1"
        );
        $stmt->execute([$auth['user_id'], $teamId]);
        if (!$stmt->fetch()) {
            Response::error('You do not control this player\'s team', 403);
            return;
        }

        $engine = new FranchiseTagEngine();
        $removed = $engine->removeTag($playerId);

        if (!$removed) {
            Response::error('Player does not have a franchise tag');
            return;
        }

        Response::success('Franchise tag removed');
    }

    /**
     * GET /api/players/{id}/franchise-tag/check
     * Check if a player can be franchise-tagged and what it would cost.
     */
    public function checkFranchiseTag(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $playerId = (int) $params['id'];

        $player = $this->player->find($playerId);
        if (!$player) {
            Response::notFound('Player not found');
            return;
        }

        $teamId = (int) $player['team_id'];

        $engine = new FranchiseTagEngine();
        $result = $engine->canTagPlayer($teamId, $playerId);

        Response::json($result);
    }

    /**
     * GET /api/franchise-tag/values
     * Get franchise tag values for all positions in the user's league.
     */
    public function franchiseTagValues(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        // Get user's league
        $db = \App\Database\Connection::getInstance()->getPdo();
        $stmt = $db->prepare(
            "SELECT l.id as league_id FROM coaches c
             JOIN teams t ON c.team_id = t.id
             JOIN leagues l ON t.league_id = l.id
             WHERE c.user_id = ? AND c.is_human = 1
             LIMIT 1"
        );
        $stmt->execute([$auth['user_id']]);
        $row = $stmt->fetch();

        if (!$row) {
            Response::error('No active league found');
            return;
        }

        $engine = new FranchiseTagEngine();
        $values = $engine->getAllTagValues((int) $row['league_id']);

        Response::json(['tag_values' => $values]);
    }

    /**
     * Get all career awards for a player.
     */
    private function getPlayerAwards(int $playerId): array
    {
        $db = \App\Database\Connection::getInstance()->getPdo();
        $stmt = $db->prepare(
            "SELECT award_type, season_year, stats FROM season_awards
             WHERE winner_id = ? AND winner_type = 'player'
             ORDER BY season_year DESC, award_type"
        );
        $stmt->execute([$playerId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $awards = [];
        $summary = [
            'all_league_first' => 0,
            'all_league_second' => 0,
            'gridiron_classic' => 0,
            'mvp' => 0,
            'opoy' => 0,
            'dpoy' => 0,
        ];

        foreach ($rows as $row) {
            $type = $row['award_type'];
            $label = match ($type) {
                'all_league_first' => 'All-League First Team',
                'all_league_second' => 'All-League Second Team',
                'gridiron_classic' => 'Gridiron Classic',
                'mvp' => 'MVP',
                'opoy' => 'Offensive Player of the Year',
                'dpoy' => 'Defensive Player of the Year',
                'oroy' => 'Offensive Rookie of the Year',
                'droy' => 'Defensive Rookie of the Year',
                'coty' => 'Coach of the Year',
                default => ucwords(str_replace('_', ' ', $type)),
            };

            if (isset($summary[$type])) {
                $summary[$type]++;
            }

            $awards[] = [
                'type' => $type,
                'label' => $label,
                'season_year' => (int) $row['season_year'],
                'details' => json_decode($row['stats'] ?? '{}', true),
            ];
        }

        return [
            'list' => $awards,
            'summary' => $summary,
        ];
    }

    private function formatMoney(int $amount): string
    {
        if ($amount >= 1000000) return '$' . number_format($amount / 1000000, 1) . 'M';
        if ($amount >= 1000) return '$' . number_format($amount / 1000, 0) . 'K';
        return '$' . number_format($amount);
    }

    // ─── Scout Report Generator ─────────────────────────────────────────

    private const SCOUTS = [
        [
            'name' => 'Terry Hollis',
            'style' => 'old_school',
            'positions' => ['RB', 'OT', 'OG', 'C', 'DE', 'DT', 'LB', 'TE'],
        ],
        [
            'name' => 'Dana Reeves',
            'style' => 'analytical',
            'positions' => ['QB', 'WR', 'CB', 'S', 'K', 'P'],
        ],
        [
            'name' => 'Marcus Bell',
            'style' => 'narrative',
            'positions' => ['QB', 'RB', 'WR', 'TE', 'CB', 'DE', 'LB'],
        ],
    ];

    private function generateScoutReport(array $player, array $ratings): array
    {
        $pos = $player['position'] ?? '';
        $firstName = $player['first_name'] ?? '';
        $lastName = $player['last_name'] ?? '';
        $fullName = trim("$firstName $lastName");
        $ovr = (int) ($player['overall_rating'] ?? 50);
        $age = (int) ($player['age'] ?? 22);
        $yearsPro = (int) ($player['years_pro'] ?? 0);
        $archetype = $player['archetype'] ?? '';
        $potential = $player['potential'] ?? 'normal';
        $personality = $player['personality'] ?? 'team_player';

        // Pick a scout whose position list includes this player
        $eligibleScouts = array_filter(self::SCOUTS, fn($s) => in_array($pos, $s['positions']));
        if (empty($eligibleScouts)) $eligibleScouts = self::SCOUTS;
        // Deterministic pick based on player id
        $scoutIndex = ((int) ($player['id'] ?? 0)) % count($eligibleScouts);
        $scout = array_values($eligibleScouts)[$scoutIndex];

        // Flatten all ratings into a single array
        $allRatings = [];
        foreach ($ratings as $group) {
            if (is_array($group)) {
                foreach ($group as $attr => $val) {
                    if ($val !== null) $allRatings[$attr] = (int) $val;
                }
            }
        }

        // Find strengths (top 3) and weaknesses (bottom 3)
        if (empty($allRatings)) {
            return ['scout' => $scout['name'], 'style' => $scout['style'], 'paragraphs' => []];
        }

        arsort($allRatings);
        $strengths = array_slice($allRatings, 0, 3, true);
        asort($allRatings);
        $weaknesses = array_slice($allRatings, 0, 3, true);

        $paragraphs = [];

        // ── Paragraph 1: Overview
        $paragraphs[] = $this->scoutOverview($fullName, $pos, $ovr, $age, $yearsPro, $archetype, $potential, $scout);

        // ── Paragraph 2: Strengths
        $paragraphs[] = $this->scoutStrengths($fullName, $lastName, $pos, $strengths, $scout);

        // ── Paragraph 3: Weaknesses / Areas to improve
        $paragraphs[] = $this->scoutWeaknesses($lastName, $pos, $weaknesses, $scout);

        // ── Paragraph 4: Bottom line / projection
        $paragraphs[] = $this->scoutBottomLine($fullName, $lastName, $pos, $ovr, $age, $potential, $personality, $scout);

        return [
            'scout' => $scout['name'],
            'style' => $scout['style'],
            'paragraphs' => $paragraphs,
        ];
    }

    private function scoutOverview(string $name, string $pos, int $ovr, int $age, int $yearsPro, string $archetype, string $potential, array $scout): string
    {
        $tierWord = match (true) {
            $ovr >= 90 => 'elite',
            $ovr >= 80 => 'high-end starter',
            $ovr >= 72 => 'solid starter',
            $ovr >= 65 => 'capable backup',
            default => 'developmental prospect',
        };

        $archeLabel = $archetype ? str_replace(' - ', ' ', $archetype) : $pos;

        $expLabel = match (true) {
            $yearsPro === 0 => 'a rookie',
            $yearsPro <= 2 => 'a young player still developing',
            $yearsPro <= 5 => 'entering his prime years',
            $yearsPro <= 9 => 'a seasoned veteran',
            default => 'a grizzled veteran nearing the end',
        };

        return match ($scout['style']) {
            'old_school' => "{$name} is {$expLabel} at {$pos} and grades out as a {$tierWord} at this level. He is a {$archeLabel} who at {$age} years old has shown {$this->potentialPhrase($potential, 'old_school')}. You watch the tape and you see a player who {$this->ovrImpression($ovr, 'old_school')}.",
            'analytical' => "{$name} profiles as a {$tierWord} {$archeLabel}, currently rated {$ovr} overall. At {$age} with {$yearsPro} year" . ($yearsPro !== 1 ? 's' : '') . " of experience, the projection models show {$this->potentialPhrase($potential, 'analytical')}. The data paints a clear picture of {$this->ovrImpression($ovr, 'analytical')}.",
            'narrative' => "There is something about {$name} that makes you watch a little closer. At {$age}, the {$archeLabel} is {$expLabel} — a {$tierWord} talent who {$this->potentialPhrase($potential, 'narrative')}. {$this->ovrImpression($ovr, 'narrative')}.",
            default => "{$name} is a {$tierWord} {$pos}.",
        };
    }

    private function potentialPhrase(string $potential, string $style): string
    {
        return match ($potential) {
            'star' => match ($style) {
                'old_school' => 'the kind of upside you dream about',
                'analytical' => 'an exceptionally high ceiling with projected growth curves trending upward',
                'narrative' => 'carries the kind of potential that keeps scouts up at night',
                default => 'star potential',
            },
            'superstar' => match ($style) {
                'old_school' => 'franchise-changing ability if he puts it all together',
                'analytical' => 'elite-tier ceiling projections — top 5% of his draft class in expected development',
                'narrative' => 'has a gift. The kind of raw talent that comes around once every few years',
                default => 'superstar potential',
            },
            'normal' => match ($style) {
                'old_school' => 'a guy who knows his role and plays within himself',
                'analytical' => 'development metrics consistent with a reliable contributor',
                'narrative' => 'the steadiness of a player who will give you exactly what you expect',
                default => 'normal development trajectory',
            },
            default => match ($style) {
                'old_school' => 'room to grow if he puts in the work',
                'analytical' => 'development indicators within the expected range',
                'narrative' => 'the look of a player still figuring out who he wants to be',
                default => 'development potential',
            },
        };
    }

    private function ovrImpression(int $ovr, string $style): string
    {
        return match (true) {
            $ovr >= 90 => match ($style) {
                'old_school' => 'dominates every single snap',
                'analytical' => 'a player whose efficiency metrics rank among the league\'s elite',
                'narrative' => 'Every now and then you see a player who makes the game look easy — this is one of those players',
                default => 'elite performance',
            },
            $ovr >= 80 => match ($style) {
                'old_school' => 'plays the game the right way, week in and week out',
                'analytical' => 'a consistently above-average performer across all key metrics',
                'narrative' => 'There is a quiet confidence to his game, the kind that comes from knowing you belong',
                default => 'quality starter',
            },
            $ovr >= 72 => match ($style) {
                'old_school' => "won't lose you games, and some weeks he'll win you one",
                'analytical' => 'a net-positive contributor with a few exploitable tendencies',
                'narrative' => "He is the kind of player who won't make the highlight reel, but his teammates trust him",
                default => 'solid contributor',
            },
            $ovr >= 65 => match ($style) {
                'old_school' => 'has limitations, but he fights through them',
                'analytical' => 'a below-average grader with specific schematic fits',
                'narrative' => 'You can see him thinking out there — the instincts are not quite automatic yet',
                default => 'backup-level',
            },
            default => match ($style) {
                'old_school' => 'has a long way to go before he\'s ready',
                'analytical' => 'a player whose current metrics suggest a role-player ceiling at best',
                'narrative' => 'He is raw. There is no other way to put it',
                default => 'project player',
            },
        };
    }

    private function scoutStrengths(string $fullName, string $lastName, string $pos, array $strengths, array $scout): string
    {
        $attrLabels = $this->attrLabel();
        $lines = [];

        foreach ($strengths as $attr => $val) {
            $label = $attrLabels[$attr] ?? ucwords(str_replace('_', ' ', $attr));
            $valWord = match (true) {
                $val >= 95 => 'generational',
                $val >= 90 => 'elite',
                $val >= 85 => 'excellent',
                $val >= 80 => 'very good',
                $val >= 75 => 'above average',
                default => 'solid',
            };
            $lines[] = ['attr' => $attr, 'label' => $label, 'val' => $val, 'word' => $valWord];
        }

        $topAttr = $lines[0] ?? null;
        if (!$topAttr) return '';

        $otherMentions = array_slice($lines, 1);
        $otherStr = implode(' and ', array_map(fn($l) => strtolower($l['label']) . " ({$l['val']})", $otherMentions));

        return match ($scout['style']) {
            'old_school' => "What jumps off the film is his {$topAttr['label']}. At {$topAttr['val']}, that is {$topAttr['word']}-level — the kind of trait that wins you football games. He also shows {$otherStr}. You can build around that.",
            'analytical' => "{$lastName}'s standout metric is {$topAttr['label']} at {$topAttr['val']}, which grades as {$topAttr['word']} relative to the positional average. Additionally, his {$otherStr} create a favorable profile for sustained production.",
            'narrative' => "Watch {$lastName} long enough and one thing becomes obvious: his {$topAttr['label']} ({$topAttr['val']}) is {$topAttr['word']}. It changes the geometry of the play. Pair that with {$otherStr}, and you start to understand why coordinators scheme around him.",
            default => "Strengths: {$topAttr['label']} ({$topAttr['val']}).",
        };
    }

    private function scoutWeaknesses(string $lastName, string $pos, array $weaknesses, array $scout): string
    {
        $attrLabels = $this->attrLabel();
        $lines = [];

        foreach ($weaknesses as $attr => $val) {
            $label = $attrLabels[$attr] ?? ucwords(str_replace('_', ' ', $attr));
            $concern = match (true) {
                $val <= 55 => 'a major concern',
                $val <= 65 => 'below average',
                $val <= 72 => 'adequate but not a strength',
                default => 'passable',
            };
            $lines[] = ['attr' => $attr, 'label' => $label, 'val' => $val, 'concern' => $concern];
        }

        $worstAttr = $lines[0] ?? null;
        if (!$worstAttr) return '';

        $otherMentions = array_slice($lines, 1);
        $otherStr = implode(' and ', array_map(fn($l) => strtolower($l['label']) . " ({$l['val']})", $otherMentions));

        return match ($scout['style']) {
            'old_school' => "Now, I am not going to sugarcoat it — his {$worstAttr['label']} at {$worstAttr['val']} is {$worstAttr['concern']}. Good opponents will attack that. His {$otherStr} also need work. You have to scheme around these limitations or they will cost you games.",
            'analytical' => "The concerning data points center around {$worstAttr['label']} ({$worstAttr['val']}), which rates as {$worstAttr['concern']} at the position. His {$otherStr} are additional areas where the efficiency numbers lag behind the positional baseline.",
            'narrative' => "But there is another side to {$lastName}'s tape. His {$worstAttr['label']} ({$worstAttr['val']}) is {$worstAttr['concern']}, and in big moments, that is where the game finds you. His {$otherStr} raise similar questions. The talent is there — but so are the holes.",
            default => "Weaknesses: {$worstAttr['label']} ({$worstAttr['val']}).",
        };
    }

    private function scoutBottomLine(string $fullName, string $lastName, string $pos, int $ovr, int $age, string $potential, string $personality, array $scout): string
    {
        $personalityTrait = match ($personality) {
            'leader' => 'a natural leader in the locker room',
            'team_player' => 'a selfless teammate who puts the team first',
            'diva' => 'a high-maintenance personality who needs the spotlight',
            'mercenary' => 'motivated primarily by the paycheck',
            'intense' => 'a fierce competitor with an edge',
            'quiet' => 'a quiet professional who lets his play do the talking',
            default => 'a professional',
        };

        $ageOutlook = match (true) {
            $age <= 23 => 'has years of development ahead',
            $age <= 26 => 'is approaching his peak years',
            $age <= 29 => 'is in his prime window right now',
            $age <= 32 => 'has a few productive years left if he stays healthy',
            default => 'is on borrowed time — every snap could be the last',
        };

        return match ($scout['style']) {
            'old_school' => "Bottom line: {$lastName} is {$personalityTrait} who {$ageOutlook}. At {$ovr} overall, he is " . ($ovr >= 80 ? 'a guy you want in your foxhole.' : ($ovr >= 70 ? 'a serviceable player who knows his role.' : 'going to need to earn his spot every single day.')) . " I have seen enough to know what he is — the question is whether your staff can maximize it.",
            'analytical' => "Projection summary: {$lastName}, {$ovr} OVR, {$ageOutlook}. Personality assessment indicates {$personalityTrait}. " . ($potential === 'star' || $potential === 'superstar' ? "The development ceiling remains high, making him a strong asset for long-term roster construction." : "The expected value trajectory suggests a reliable contributor within his current tier.") . " Recommendation: " . ($ovr >= 80 ? 'cornerstone-level investment.' : ($ovr >= 70 ? 'starter-level investment with upside.' : 'depth investment with development upside.')),
            'narrative' => "So what is the verdict on {$fullName}? He is {$personalityTrait}, and at {$age}, he {$ageOutlook}. " . ($ovr >= 80 ? "This is a player you build around. The kind of player who changes what your team can be." : ($ovr >= 70 ? "He might never be the best player on your roster, but he might be the most important." : "The story of {$lastName} is still being written. Whether it ends as a footnote or a chapter is up to the coaching staff.")),
            default => "Overall assessment: {$ovr} OVR, {$ageOutlook}.",
        };
    }

    private function attrLabel(): array
    {
        return [
            'speed' => 'Speed', 'acceleration' => 'Acceleration', 'agility' => 'Agility',
            'jumping' => 'Jumping', 'stamina' => 'Stamina', 'strength' => 'Strength', 'toughness' => 'Toughness',
            'bc_vision' => 'Ball Carrier Vision', 'break_tackle' => 'Break Tackle', 'carrying' => 'Ball Security',
            'change_of_direction' => 'Change of Direction', 'juke_move' => 'Juke Move', 'spin_move' => 'Spin Move',
            'stiff_arm' => 'Stiff Arm', 'trucking' => 'Trucking',
            'catch_in_traffic' => 'Catching in Traffic', 'catching' => 'Catching', 'deep_route_running' => 'Deep Route Running',
            'medium_route_running' => 'Medium Route Running', 'short_route_running' => 'Short Route Running',
            'spectacular_catch' => 'Spectacular Catch', 'release' => 'Release',
            'impact_blocking' => 'Impact Blocking', 'lead_block' => 'Lead Blocking', 'pass_block' => 'Pass Blocking',
            'pass_block_finesse' => 'Pass Block Finesse', 'pass_block_power' => 'Pass Block Power',
            'run_block' => 'Run Blocking', 'run_block_finesse' => 'Run Block Finesse', 'run_block_power' => 'Run Block Power',
            'block_shedding' => 'Block Shedding', 'finesse_moves' => 'Finesse Moves', 'hit_power' => 'Hit Power',
            'man_coverage' => 'Man Coverage', 'play_recognition' => 'Play Recognition', 'power_moves' => 'Power Moves',
            'press' => 'Press Coverage', 'pursuit' => 'Pursuit', 'tackle' => 'Tackling', 'zone_coverage' => 'Zone Coverage',
            'break_sack' => 'Break Sack', 'play_action' => 'Play Action', 'throw_accuracy_deep' => 'Deep Accuracy',
            'throw_accuracy_mid' => 'Mid-Range Accuracy', 'throw_accuracy_short' => 'Short Accuracy',
            'throw_on_the_run' => 'Throw on the Run', 'throw_power' => 'Arm Strength', 'throw_under_pressure' => 'Throw Under Pressure',
            'kick_accuracy' => 'Kick Accuracy', 'kick_power' => 'Kick Power', 'kick_return' => 'Kick Return',
            'awareness' => 'Awareness',
        ];
    }
}
