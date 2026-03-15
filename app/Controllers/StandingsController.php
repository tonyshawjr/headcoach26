<?php

namespace App\Controllers;

use App\Helpers\Response;
use App\Middleware\AuthMiddleware;
use App\Models\Team;
use App\Models\League;
use App\Models\GameStat;
use App\Models\Player;
use App\Database\Connection;

class StandingsController
{
    private Team $team;
    private League $league;
    private GameStat $gameStat;
    private Player $player;
    private \PDO $db;

    public function __construct()
    {
        $this->team = new Team();
        $this->league = new League();
        $this->gameStat = new GameStat();
        $this->player = new Player();
        $this->db = Connection::getInstance()->getPdo();
    }

    /**
     * GET /api/leagues/{league_id}/standings
     * Division and conference standings, sorted by wins then points_for tiebreaker.
     */
    public function index(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $leagueId = (int) $params['league_id'];
        $league = $this->league->find($leagueId);
        if (!$league) {
            Response::notFound('League not found');
            return;
        }

        $teams = $this->team->getByLeague($leagueId);

        // Sort by wins DESC, then points_for DESC as tiebreaker
        usort($teams, function ($a, $b) {
            $winDiff = (int) $b['wins'] - (int) $a['wins'];
            if ($winDiff !== 0) return $winDiff;
            return (int) $b['points_for'] - (int) $a['points_for'];
        });

        // Group by conference and division
        $divisions = [];
        $conferences = [];

        foreach ($teams as $team) {
            $conf = $team['conference'];
            $div = $team['division'];

            $entry = [
                'id' => (int) $team['id'],
                'city' => $team['city'],
                'name' => $team['name'],
                'abbreviation' => $team['abbreviation'],
                'primary_color' => $team['primary_color'],
                'secondary_color' => $team['secondary_color'] ?? '#FFFFFF',
                'wins' => (int) $team['wins'],
                'losses' => (int) $team['losses'],
                'ties' => (int) $team['ties'],
                'win_pct' => $this->winPct($team),
                'points_for' => (int) $team['points_for'],
                'points_against' => (int) $team['points_against'],
                'point_diff' => (int) $team['points_for'] - (int) $team['points_against'],
                'streak' => $team['streak'],
            ];

            $divisions[$conf][$div][] = $entry;
            $conferences[$conf][] = $entry;
        }

        // Sort each division group by wins then points_for
        foreach ($divisions as $conf => &$divs) {
            foreach ($divs as $div => &$divTeams) {
                usort($divTeams, function ($a, $b) {
                    $winDiff = $b['wins'] - $a['wins'];
                    if ($winDiff !== 0) return $winDiff;
                    return $b['points_for'] - $a['points_for'];
                });
            }
        }

        // Sort conference standings
        foreach ($conferences as $conf => &$confTeams) {
            usort($confTeams, function ($a, $b) {
                $winDiff = $b['wins'] - $a['wins'];
                if ($winDiff !== 0) return $winDiff;
                return $b['points_for'] - $a['points_for'];
            });
        }

        Response::json([
            'league_id' => $leagueId,
            'current_week' => (int) $league['current_week'],
            'divisions' => $divisions,
            'conferences' => $conferences,
        ]);
    }

    /**
     * GET /api/leagues/{league_id}/leaders
     * Stat leaders for the current season.
     */
    public function leaders(array $params): void
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
            Response::json(['leaders' => [], 'message' => 'No active season']);
            return;
        }

        $seasonId = (int) $season['id'];
        $limit = isset($_GET['limit']) ? min(25, max(5, (int) $_GET['limit'])) : 10;
        $type = $_GET['type'] ?? 'standard';

        // Advanced stats mode
        if ($type === 'advanced') {
            $advanced = [];

            // Passer Rating: (pass_yards + pass_tds*20 - interceptions*45) / games
            // Uses subquery to avoid HAVING issues with SQLite
            $stmt = $this->db->prepare(
                "SELECT sub.* FROM (
                    SELECT p.id AS player_id, p.first_name, p.last_name, p.position, t.abbreviation AS team,
                           COUNT(DISTINCT gs.game_id) AS games,
                           SUM(gs.pass_yards) AS pass_yards,
                           SUM(gs.pass_tds) AS pass_tds,
                           SUM(COALESCE(gs.interceptions, 0)) AS interceptions,
                           ROUND(CAST(SUM(gs.pass_yards) + SUM(gs.pass_tds)*20 - SUM(COALESCE(gs.interceptions, 0))*45 AS FLOAT) / NULLIF(COUNT(DISTINCT gs.game_id), 0), 1) AS total
                    FROM game_stats gs
                    JOIN games g ON g.id = gs.game_id
                    JOIN players p ON p.id = gs.player_id
                    JOIN teams t ON t.id = p.team_id
                    WHERE g.league_id = ? AND g.season_id = ?
                    GROUP BY p.id, p.first_name, p.last_name, p.position, t.abbreviation
                ) sub WHERE sub.pass_yards > 0
                ORDER BY sub.total DESC
                LIMIT ?"
            );
            $stmt->execute([$leagueId, $seasonId, $limit]);
            $advanced['passer_rating'] = [
                'label' => 'Passer Rating',
                'players' => $stmt->fetchAll(),
            ];

            // Yards Per Carry: rush_yards / rush_tds (rough proxy)
            $stmt = $this->db->prepare(
                "SELECT sub.* FROM (
                    SELECT p.id AS player_id, p.first_name, p.last_name, p.position, t.abbreviation AS team,
                           COUNT(DISTINCT gs.game_id) AS games,
                           SUM(gs.rush_yards) AS rush_yards,
                           SUM(gs.rush_tds) AS rush_tds,
                           ROUND(CAST(SUM(gs.rush_yards) AS FLOAT) / NULLIF(SUM(gs.rush_tds), 0), 1) AS total
                    FROM game_stats gs
                    JOIN games g ON g.id = gs.game_id
                    JOIN players p ON p.id = gs.player_id
                    JOIN teams t ON t.id = p.team_id
                    WHERE g.league_id = ? AND g.season_id = ?
                    GROUP BY p.id, p.first_name, p.last_name, p.position, t.abbreviation
                ) sub WHERE sub.rush_yards > 0 AND sub.rush_tds > 0
                ORDER BY sub.total DESC
                LIMIT ?"
            );
            $stmt->execute([$leagueId, $seasonId, $limit]);
            $advanced['yards_per_carry'] = [
                'label' => 'Yards Per Carry',
                'players' => $stmt->fetchAll(),
            ];

            // Tackles Per Game: tackles / games_played
            $stmt = $this->db->prepare(
                "SELECT sub.* FROM (
                    SELECT p.id AS player_id, p.first_name, p.last_name, p.position, t.abbreviation AS team,
                           COUNT(DISTINCT gs.game_id) AS games,
                           SUM(gs.tackles) AS tackles,
                           ROUND(CAST(SUM(gs.tackles) AS FLOAT) / NULLIF(COUNT(DISTINCT gs.game_id), 0), 1) AS total
                    FROM game_stats gs
                    JOIN games g ON g.id = gs.game_id
                    JOIN players p ON p.id = gs.player_id
                    JOIN teams t ON t.id = p.team_id
                    WHERE g.league_id = ? AND g.season_id = ?
                    GROUP BY p.id, p.first_name, p.last_name, p.position, t.abbreviation
                ) sub WHERE sub.tackles > 0
                ORDER BY sub.total DESC
                LIMIT ?"
            );
            $stmt->execute([$leagueId, $seasonId, $limit]);
            $advanced['tackles_per_game'] = [
                'label' => 'Tackles Per Game',
                'players' => $stmt->fetchAll(),
            ];

            Response::json([
                'league_id' => $leagueId,
                'season_id' => $seasonId,
                'leaders' => $advanced,
            ]);
            return;
        }

        $categories = [
            'pass_yards' => 'Passing Yards',
            'rush_yards' => 'Rushing Yards',
            'rec_yards' => 'Receiving Yards',
            'pass_tds' => 'Passing Touchdowns',
            'rush_tds' => 'Rushing Touchdowns',
            'rec_tds' => 'Receiving Touchdowns',
            'receptions' => 'Receptions',
            'tackles' => 'Tackles',
            'sacks' => 'Sacks',
            'interceptions_def' => 'Interceptions',
        ];

        $leaders = [];
        foreach ($categories as $stat => $label) {
            $leaders[$stat] = [
                'label' => $label,
                'players' => $this->gameStat->getSeasonLeaders($leagueId, $seasonId, $stat, $limit),
            ];
        }

        Response::json([
            'league_id' => $leagueId,
            'season_id' => $seasonId,
            'leaders' => $leaders,
        ]);
    }

    /**
     * GET /api/leagues/{league_id}/power-rankings
     * Return teams ranked by overall strength (composite of record, rating, point differential, morale).
     */
    public function powerRankings(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $leagueId = (int) $params['league_id'];
        $league = $this->league->find($leagueId);
        if (!$league) {
            Response::notFound('League not found');
            return;
        }

        $teams = $this->team->getByLeague($leagueId);

        // Calculate power score for each team
        $ranked = [];
        foreach ($teams as $team) {
            $gamesPlayed = (int) $team['wins'] + (int) $team['losses'] + (int) $team['ties'];
            $winPct = $this->winPct($team);
            $pointDiff = (int) $team['points_for'] - (int) $team['points_against'];
            $avgPointDiff = $gamesPlayed > 0 ? $pointDiff / $gamesPlayed : 0;

            // Power score: weighted blend of rating, win%, point differential, morale
            $powerScore = (
                ((int) $team['overall_rating'] * 0.35) +
                ($winPct * 40 * 0.30) +
                (max(-20, min(20, $avgPointDiff)) + 20) * 0.20 +
                ((int) $team['morale'] * 0.15)
            );

            $ranked[] = [
                'team_id' => (int) $team['id'],
                'city' => $team['city'],
                'name' => $team['name'],
                'abbreviation' => $team['abbreviation'],
                'primary_color' => $team['primary_color'],
                'conference' => $team['conference'],
                'division' => $team['division'],
                'wins' => (int) $team['wins'],
                'losses' => (int) $team['losses'],
                'ties' => (int) $team['ties'],
                'overall_rating' => (int) $team['overall_rating'],
                'point_diff' => $pointDiff,
                'morale' => (int) $team['morale'],
                'power_score' => round($powerScore, 1),
            ];
        }

        // Sort by power score descending
        usort($ranked, fn($a, $b) => $b['power_score'] <=> $a['power_score']);

        // Assign rank numbers and nest team data to match frontend PowerRanking type
        $output = [];
        foreach ($ranked as $i => $entry) {
            $output[] = [
                'rank' => $i + 1,
                'power_score' => $entry['power_score'],
                'team' => [
                    'id' => $entry['team_id'],
                    'city' => $entry['city'],
                    'name' => $entry['name'],
                    'abbreviation' => $entry['abbreviation'],
                    'primary_color' => $entry['primary_color'],
                    'conference' => $entry['conference'],
                    'division' => $entry['division'],
                    'wins' => $entry['wins'],
                    'losses' => $entry['losses'],
                    'ties' => $entry['ties'],
                    'overall_rating' => $entry['overall_rating'],
                    'point_diff' => $entry['point_diff'],
                    'morale' => $entry['morale'],
                    'logo_emoji' => $teams[array_search($entry['team_id'], array_column($teams, 'id'))]['logo_emoji'] ?? '',
                ],
            ];
        }

        Response::json($output);
    }

    /**
     * GET /api/leagues/{league_id}/records
     * Franchise records: single-season, career, and team records.
     */
    public function records(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $leagueId = (int) $params['league_id'];
        $league = $this->league->find($leagueId);
        if (!$league) {
            Response::notFound('League not found');
            return;
        }

        $records = [];

        // Single Season Records — top 5 for key stats per season
        $statCategories = [
            'pass_yards' => 'Passing Yards',
            'rush_yards' => 'Rushing Yards',
            'rec_yards' => 'Receiving Yards',
            'pass_tds' => 'Passing TDs',
            'rush_tds' => 'Rushing TDs',
            'rec_tds' => 'Receiving TDs',
            'receptions' => 'Receptions',
            'tackles' => 'Tackles',
            'sacks' => 'Sacks',
            'interceptions_def' => 'Interceptions',
        ];

        $singleSeason = [];
        foreach ($statCategories as $stat => $label) {
            $stmt = $this->db->prepare(
                "SELECT p.id AS player_id, p.first_name, p.last_name, p.position,
                        t.abbreviation AS team, s.year AS season_year,
                        SUM(gs.{$stat}) AS total
                 FROM game_stats gs
                 JOIN games g ON g.id = gs.game_id
                 JOIN players p ON p.id = gs.player_id
                 JOIN teams t ON t.id = p.team_id
                 JOIN seasons s ON s.id = g.season_id
                 WHERE g.league_id = ?
                 GROUP BY p.id, p.first_name, p.last_name, p.position, t.abbreviation, s.year
                 ORDER BY total DESC
                 LIMIT 5"
            );
            $stmt->execute([$leagueId]);
            $singleSeason[$stat] = [
                'label' => $label,
                'records' => $stmt->fetchAll(),
            ];
        }
        $records['single_season'] = $singleSeason;

        // Career Records — all-time career leaders
        $career = [];
        foreach ($statCategories as $stat => $label) {
            $stmt = $this->db->prepare(
                "SELECT p.id AS player_id, p.first_name, p.last_name, p.position,
                        t.abbreviation AS team,
                        COUNT(DISTINCT g.season_id) AS seasons,
                        SUM(gs.{$stat}) AS total
                 FROM game_stats gs
                 JOIN games g ON g.id = gs.game_id
                 JOIN players p ON p.id = gs.player_id
                 JOIN teams t ON t.id = p.team_id
                 WHERE g.league_id = ?
                 GROUP BY p.id, p.first_name, p.last_name, p.position, t.abbreviation
                 ORDER BY total DESC
                 LIMIT 5"
            );
            $stmt->execute([$leagueId]);
            $career[$stat] = [
                'label' => $label,
                'records' => $stmt->fetchAll(),
            ];
        }
        $records['career'] = $career;

        // Team Records — most wins in a season, biggest blowout
        $stmt = $this->db->prepare(
            "SELECT t.city, t.name, t.abbreviation, t.primary_color, s.year AS season_year,
                    t.wins, t.losses, t.ties
             FROM teams t
             JOIN seasons s ON s.league_id = t.league_id AND s.is_current = 1
             WHERE t.league_id = ?
             ORDER BY t.wins DESC
             LIMIT 5"
        );
        $stmt->execute([$leagueId]);
        $records['team_wins'] = $stmt->fetchAll();

        // Biggest blowouts
        $stmt = $this->db->prepare(
            "SELECT g.week, s.year AS season_year,
                    ht.abbreviation AS home_team, at.abbreviation AS away_team,
                    g.home_score, g.away_score,
                    ABS(g.home_score - g.away_score) AS margin
             FROM games g
             JOIN teams ht ON ht.id = g.home_team_id
             JOIN teams at ON at.id = g.away_team_id
             JOIN seasons s ON s.id = g.season_id
             WHERE g.league_id = ? AND g.is_simulated = 1
             ORDER BY margin DESC
             LIMIT 5"
        );
        $stmt->execute([$leagueId]);
        $records['biggest_blowouts'] = $stmt->fetchAll();

        Response::json($records);
    }

    /**
     * GET /api/leagues/{league_id}/history
     * Timeline of past seasons with champion and MVP.
     */
    public function history(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $leagueId = (int) $params['league_id'];
        $league = $this->league->find($leagueId);
        if (!$league) {
            Response::notFound('League not found');
            return;
        }

        // Get all seasons for this league
        $stmt = $this->db->prepare(
            "SELECT s.id, s.year, s.is_current
             FROM seasons s
             WHERE s.league_id = ?
             ORDER BY s.year DESC"
        );
        $stmt->execute([$leagueId]);
        $seasons = $stmt->fetchAll();

        $history = [];
        foreach ($seasons as $season) {
            $year = (int) $season['year'];
            $entry = [
                'year' => $year,
                'is_current' => (bool) $season['is_current'],
                'champion' => null,
                'mvp' => null,
                'coach_of_year' => null,
            ];

            // Look up season_awards for champion
            $stmt = $this->db->prepare(
                "SELECT sa.award_type, sa.winner_type, sa.winner_id, sa.stats,
                        CASE WHEN sa.winner_type = 'player'
                            THEN (SELECT p.first_name || ' ' || p.last_name FROM players p WHERE p.id = sa.winner_id)
                            ELSE NULL END AS player_name,
                        CASE WHEN sa.winner_type = 'coach'
                            THEN (SELECT c.name FROM coaches c WHERE c.id = sa.winner_id)
                            ELSE NULL END AS coach_name,
                        CASE WHEN sa.winner_type = 'team'
                            THEN (SELECT t.city || ' ' || t.name FROM teams t WHERE t.id = sa.winner_id)
                            WHEN sa.winner_type = 'player'
                            THEN (SELECT t.city || ' ' || t.name FROM teams t JOIN players p ON p.team_id = t.id WHERE p.id = sa.winner_id)
                            WHEN sa.winner_type = 'coach'
                            THEN (SELECT t.city || ' ' || t.name FROM teams t JOIN coaches c ON c.team_id = t.id WHERE c.id = sa.winner_id)
                            ELSE NULL END AS team_name
                 FROM season_awards sa
                 WHERE sa.league_id = ? AND sa.season_year = ?"
            );
            $stmt->execute([$leagueId, $year]);
            $awards = $stmt->fetchAll();

            foreach ($awards as $award) {
                $type = $award['award_type'] ?? '';
                if (stripos($type, 'champion') !== false || stripos($type, 'championship') !== false) {
                    $entry['champion'] = $award['team_name'] ?? $award['coach_name'] ?? $award['player_name'];
                }
                if (stripos($type, 'mvp') !== false) {
                    $entry['mvp'] = $award['player_name'] ?? $award['coach_name'];
                    $entry['mvp_team'] = $award['team_name'];
                }
                if (stripos($type, 'coach') !== false) {
                    $entry['coach_of_year'] = $award['coach_name'];
                }
            }

            $history[] = $entry;
        }

        Response::json(['history' => $history]);
    }

    /**
     * GET /api/achievements
     * Check achievement conditions for the user's team.
     */
    public function achievements(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $leagueId = (int) $auth['league_id'];
        $teamId = (int) $auth['team_id'];

        $unlocked = [];

        // first_win: Has the team won at least one game?
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS cnt FROM games
             WHERE league_id = ? AND is_simulated = 1 AND (
                 (home_team_id = ? AND home_score > away_score) OR
                 (away_team_id = ? AND away_score > home_score)
             )"
        );
        $stmt->execute([$leagueId, $teamId, $teamId]);
        $unlocked['first_win'] = ((int) $stmt->fetch()['cnt']) > 0;

        // win_streak_5: Check for 5+ consecutive wins (look at games in order)
        $stmt = $this->db->prepare(
            "SELECT g.id,
                    CASE
                        WHEN g.home_team_id = ? AND g.home_score > g.away_score THEN 1
                        WHEN g.away_team_id = ? AND g.away_score > g.home_score THEN 1
                        ELSE 0
                    END AS won
             FROM games g
             WHERE g.league_id = ? AND g.is_simulated = 1
               AND (g.home_team_id = ? OR g.away_team_id = ?)
             ORDER BY g.season_id ASC, g.week ASC"
        );
        $stmt->execute([$teamId, $teamId, $leagueId, $teamId, $teamId]);
        $allGames = $stmt->fetchAll();
        $maxStreak = 0;
        $currentStreak = 0;
        foreach ($allGames as $game) {
            if ((int) $game['won'] === 1) {
                $currentStreak++;
                $maxStreak = max($maxStreak, $currentStreak);
            } else {
                $currentStreak = 0;
            }
        }
        $unlocked['win_streak_5'] = $maxStreak >= 5;

        // playoff_bound: Check if team made the playoffs (check coach_history)
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS cnt FROM coach_history
             WHERE team_id = ? AND made_playoffs = 1"
        );
        $stmt->execute([$teamId]);
        $row = $stmt->fetch();
        $unlocked['playoff_bound'] = $row ? ((int) $row['cnt']) > 0 : false;

        // champion: Check coach_history for championship
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS cnt FROM coach_history
             WHERE team_id = ? AND championship = 1"
        );
        $stmt->execute([$teamId]);
        $row = $stmt->fetch();
        $unlocked['champion'] = $row ? ((int) $row['cnt']) > 0 : false;

        // trade_master: 10+ completed trades
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS cnt FROM trades
             WHERE status IN ('accepted', 'completed') AND (proposing_team_id = ? OR receiving_team_id = ?)"
        );
        $stmt->execute([$teamId, $teamId]);
        $row = $stmt->fetch();
        $unlocked['trade_master'] = $row ? ((int) $row['cnt']) >= 10 : false;

        // rebuilder: Check if team OVR improved by 10+ (check coach_seasons if possible)
        // Simplified: check if current overall_rating is 10+ higher than lowest recorded
        $unlocked['rebuilder'] = false; // Hard to check without historical OVR, mark false by default

        // giant_killer: Beat a team rated 10+ higher
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS cnt FROM games g
             JOIN teams ht ON ht.id = g.home_team_id
             JOIN teams at ON at.id = g.away_team_id
             WHERE g.league_id = ? AND g.is_simulated = 1 AND (
                 (g.home_team_id = ? AND g.home_score > g.away_score AND at.overall_rating >= ht.overall_rating + 10) OR
                 (g.away_team_id = ? AND g.away_score > g.home_score AND ht.overall_rating >= at.overall_rating + 10)
             )"
        );
        $stmt->execute([$leagueId, $teamId, $teamId]);
        $row = $stmt->fetch();
        $unlocked['giant_killer'] = $row ? ((int) $row['cnt']) > 0 : false;

        // veteran: 10+ seasons completed
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS cnt FROM coach_history WHERE team_id = ?"
        );
        $stmt->execute([$teamId]);
        $row = $stmt->fetch();
        $unlocked['veteran'] = $row ? ((int) $row['cnt']) >= 10 : false;

        $achievements = [
            ['id' => 'first_win', 'name' => 'First Victory', 'desc' => 'Win your first game', 'icon' => 'Trophy', 'unlocked' => $unlocked['first_win']],
            ['id' => 'win_streak_5', 'name' => 'On Fire', 'desc' => 'Win 5 games in a row', 'icon' => 'Flame', 'unlocked' => $unlocked['win_streak_5']],
            ['id' => 'playoff_bound', 'name' => 'Playoff Bound', 'desc' => 'Make the playoffs', 'icon' => 'Target', 'unlocked' => $unlocked['playoff_bound']],
            ['id' => 'champion', 'name' => 'Champion', 'desc' => 'Win the championship', 'icon' => 'Crown', 'unlocked' => $unlocked['champion']],
            ['id' => 'trade_master', 'name' => 'Trade Master', 'desc' => 'Complete 10 trades', 'icon' => 'ArrowLeftRight', 'unlocked' => $unlocked['trade_master']],
            ['id' => 'rebuilder', 'name' => 'Rebuilder', 'desc' => 'Improve team OVR by 10+ in one season', 'icon' => 'TrendingUp', 'unlocked' => $unlocked['rebuilder']],
            ['id' => 'giant_killer', 'name' => 'Giant Killer', 'desc' => 'Beat a team rated 10+ higher', 'icon' => 'Swords', 'unlocked' => $unlocked['giant_killer']],
            ['id' => 'veteran', 'name' => 'Veteran', 'desc' => 'Complete 10 seasons', 'icon' => 'Medal', 'unlocked' => $unlocked['veteran']],
        ];

        Response::json(['achievements' => $achievements]);
    }

    /**
     * GET /api/leagues/{league_id}/scenarios
     * Playoff scenario simulator data: current records, remaining games,
     * magic numbers, clinch/elimination status for the user's team.
     */
    public function scenarios(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $leagueId = (int) $params['league_id'];
        $league = $this->league->find($leagueId);
        if (!$league) { Response::notFound('League not found'); return; }

        $currentWeek = (int) $league['current_week'];
        $teams = $this->team->getByLeague($leagueId);

        // Build team lookup
        $teamMap = [];
        foreach ($teams as $t) {
            $teamMap[(int) $t['id']] = [
                'id' => (int) $t['id'],
                'city' => $t['city'],
                'name' => $t['name'],
                'abbreviation' => $t['abbreviation'],
                'conference' => $t['conference'],
                'division' => $t['division'],
                'primary_color' => $t['primary_color'],
                'wins' => (int) $t['wins'],
                'losses' => (int) $t['losses'],
                'ties' => (int) $t['ties'],
                'points_for' => (int) $t['points_for'],
                'points_against' => (int) $t['points_against'],
            ];
        }

        // Get remaining (unsimulated) games
        $stmt = $this->db->prepare(
            "SELECT id, week, home_team_id, away_team_id, game_type
             FROM games
             WHERE league_id = ? AND is_simulated = 0 AND game_type = 'regular'
             ORDER BY week ASC"
        );
        $stmt->execute([$leagueId]);
        $remainingGames = $stmt->fetchAll();

        $remaining = [];
        foreach ($remainingGames as $g) {
            $remaining[] = [
                'id' => (int) $g['id'],
                'week' => (int) $g['week'],
                'home_team_id' => (int) $g['home_team_id'],
                'away_team_id' => (int) $g['away_team_id'],
                'home_abbr' => $teamMap[(int) $g['home_team_id']]['abbreviation'] ?? '?',
                'away_abbr' => $teamMap[(int) $g['away_team_id']]['abbreviation'] ?? '?',
            ];
        }

        // Count remaining games per team
        $gamesLeft = [];
        foreach ($remaining as $g) {
            $gamesLeft[$g['home_team_id']] = ($gamesLeft[$g['home_team_id']] ?? 0) + 1;
            $gamesLeft[$g['away_team_id']] = ($gamesLeft[$g['away_team_id']] ?? 0) + 1;
        }

        // Determine total regular season games (max games played + remaining for any team)
        $maxPlayed = 0;
        foreach ($teamMap as $t) {
            $played = $t['wins'] + $t['losses'] + $t['ties'];
            if ($played > $maxPlayed) $maxPlayed = $played;
        }
        $totalGames = $maxPlayed + max(array_values($gamesLeft) ?: [0]);

        // Build division standings and compute magic numbers
        $divStandings = [];
        foreach ($teamMap as $t) {
            $key = $t['conference'] . '|' . $t['division'];
            $divStandings[$key][] = $t;
        }

        // Sort each division
        foreach ($divStandings as &$divTeams) {
            usort($divTeams, function ($a, $b) {
                $d = $b['wins'] - $a['wins'];
                return $d !== 0 ? $d : ($b['points_for'] - $a['points_for']);
            });
        }
        unset($divTeams);

        // Compute magic numbers and scenarios per team
        $scenarios = [];
        foreach ($teamMap as $tid => $team) {
            $divKey = $team['conference'] . '|' . $team['division'];
            $divTeams = $divStandings[$divKey] ?? [];
            $teamGamesLeft = $gamesLeft[$tid] ?? 0;
            $maxPossibleWins = $team['wins'] + $teamGamesLeft;

            // Division leader?
            $isDivLeader = !empty($divTeams) && $divTeams[0]['id'] === $tid;

            // Division magic number: wins needed to clinch division
            // Magic# = (totalGames + 1) - myWins - secondPlaceLosses
            $divMagic = null;
            if ($isDivLeader && count($divTeams) > 1) {
                $second = $divTeams[1];
                $secondMaxWins = $second['wins'] + ($gamesLeft[$second['id']] ?? 0);
                $divMagic = max(0, $secondMaxWins - $team['wins'] + 1);
                // If magic number is 0 or less, clinched
                if ($divMagic <= 0) $divMagic = 0;
            }

            // Can this team still win the division?
            $canWinDiv = false;
            if ($isDivLeader) {
                $canWinDiv = true;
            } else {
                $leader = $divTeams[0] ?? null;
                if ($leader && $maxPossibleWins >= $leader['wins']) {
                    $canWinDiv = true;
                }
            }

            // Playoff picture: count teams in conference with better records
            $confTeams = array_filter($teamMap, fn($t) => $t['conference'] === $team['conference']);
            usort($confTeams, function ($a, $b) {
                $d = $b['wins'] - $a['wins'];
                return $d !== 0 ? $d : ($b['points_for'] - $a['points_for']);
            });

            // Find team's conference rank
            $confRank = 1;
            foreach ($confTeams as $ct) {
                if ($ct['id'] === $tid) break;
                $confRank++;
            }

            // Playoff spots (7 per conference for 32-team, scale for smaller)
            $teamsPerConf = count($confTeams);
            $playoffSpots = min(7, max(2, (int) ceil($teamsPerConf * 0.44)));

            // Eliminated? If max possible wins < current #7 seed's wins
            $eliminated = false;
            if ($confRank > $playoffSpots) {
                // Check if we can catch the last playoff team
                $lastPlayoffTeam = $confTeams[$playoffSpots - 1] ?? null;
                if ($lastPlayoffTeam && $maxPossibleWins < $lastPlayoffTeam['wins']) {
                    $eliminated = true;
                }
            }

            // Clinched playoff?
            $clinched = false;
            if ($confRank <= $playoffSpots) {
                // Check if team stays in even if all teams below win out
                $wouldBeOvertaken = 0;
                foreach ($confTeams as $ct) {
                    if ($ct['id'] === $tid) continue;
                    $ctMaxWins = $ct['wins'] + ($gamesLeft[$ct['id']] ?? 0);
                    if ($ctMaxWins > $team['wins']) {
                        $wouldBeOvertaken++;
                    }
                }
                // Clinched if even in worst case we still have a spot
                if ($wouldBeOvertaken < $playoffSpots) {
                    $clinched = true;
                }
            }

            $scenarios[$tid] = [
                'team_id' => $tid,
                'abbreviation' => $team['abbreviation'],
                'conference_rank' => $confRank,
                'games_left' => $teamGamesLeft,
                'max_possible_wins' => $maxPossibleWins,
                'is_div_leader' => $isDivLeader,
                'div_magic_number' => $divMagic,
                'can_win_division' => $canWinDiv,
                'playoff_spots' => $playoffSpots,
                'clinched_playoff' => $clinched,
                'clinched_division' => $divMagic === 0 && $isDivLeader,
                'eliminated' => $eliminated,
            ];
        }

        Response::json([
            'current_week' => $currentWeek,
            'total_games' => $totalGames,
            'teams' => array_values($teamMap),
            'remaining_games' => $remaining,
            'scenarios' => $scenarios,
        ]);
    }

    /**
     * Calculate win percentage.
     */
    private function winPct(array $team): float
    {
        $total = (int) $team['wins'] + (int) $team['losses'] + (int) $team['ties'];
        if ($total === 0) return 0.0;
        return round(((int) $team['wins'] + (int) $team['ties'] * 0.5) / $total, 3);
    }
}
