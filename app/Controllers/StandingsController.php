<?php

namespace App\Controllers;

use App\Helpers\Response;
use App\Middleware\AuthMiddleware;
use App\Models\Team;
use App\Models\League;
use App\Models\GameStat;
use App\Models\Player;

class StandingsController
{
    private Team $team;
    private League $league;
    private GameStat $gameStat;
    private Player $player;

    public function __construct()
    {
        $this->team = new Team();
        $this->league = new League();
        $this->gameStat = new GameStat();
        $this->player = new Player();
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
     * Calculate win percentage.
     */
    private function winPct(array $team): float
    {
        $total = (int) $team['wins'] + (int) $team['losses'] + (int) $team['ties'];
        if ($total === 0) return 0.0;
        return round(((int) $team['wins'] + (int) $team['ties'] * 0.5) / $total, 3);
    }
}
