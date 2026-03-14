<?php

namespace App\Controllers;

use App\Helpers\Response;
use App\Middleware\AuthMiddleware;
use App\Models\Team;
use App\Models\Coach;
use App\Models\Player;
use App\Models\Contract;
use App\Models\Injury;

class TeamsController
{
    private Team $team;
    private Coach $coach;
    private Player $player;
    private Contract $contract;
    private Injury $injury;

    public function __construct()
    {
        $this->team = new Team();
        $this->coach = new Coach();
        $this->player = new Player();
        $this->contract = new Contract();
        $this->injury = new Injury();
    }

    /**
     * GET /api/leagues/{league_id}/teams
     * List all teams in a league.
     */
    public function index(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $leagueId = (int) $params['league_id'];
        $teams = $this->team->getByLeague($leagueId);

        // Group by conference and division
        $grouped = [];
        foreach ($teams as $team) {
            $conf = $team['conference'];
            $div = $team['division'];
            $grouped[$conf][$div][] = $team;
        }

        Response::json([
            'teams' => $teams,
            'conferences' => $grouped,
        ]);
    }

    /**
     * GET /api/teams/{id}
     * Team detail with record, colors, injuries, and coaching staff.
     */
    public function show(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $team = $this->team->find((int) $params['id']);
        if (!$team) {
            Response::notFound('Team not found');
            return;
        }

        $coaches = $this->coach->getByTeam((int) $team['id']);
        $injuries = $this->injury->getActiveByTeam((int) $team['id']);
        $rosterCount = $this->player->count(['team_id' => $team['id'], 'status' => 'active']);

        Response::json([
            'team' => $team,
            'record' => [
                'wins' => (int) $team['wins'],
                'losses' => (int) $team['losses'],
                'ties' => (int) $team['ties'],
                'points_for' => (int) $team['points_for'],
                'points_against' => (int) $team['points_against'],
                'streak' => $team['streak'],
            ],
            'colors' => [
                'primary' => $team['primary_color'],
                'secondary' => $team['secondary_color'],
            ],
            'coaches' => $coaches,
            'injuries' => $injuries,
            'roster_count' => $rosterCount,
        ]);
    }

    /**
     * GET /api/teams/{id}/cap
     * Return cap space breakdown.
     */
    public function capSpace(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $team = $this->team->find((int) $params['id']);
        if (!$team) {
            Response::notFound('Team not found');
            return;
        }

        $contracts = $this->contract->all(['team_id' => $team['id']], 'cap_hit DESC');

        $totalCapHit = 0;
        $totalGuaranteed = 0;
        $totalDeadCap = 0;
        $contractDetails = [];

        foreach ($contracts as $c) {
            $totalCapHit += (int) $c['cap_hit'];
            $totalGuaranteed += (int) $c['guaranteed'];
            $totalDeadCap += (int) $c['dead_cap'];

            $player = $this->player->find((int) $c['player_id']);
            $contractDetails[] = [
                'contract_id' => $c['id'],
                'player_id' => $c['player_id'],
                'player_name' => $player ? $player['first_name'] . ' ' . $player['last_name'] : 'Unknown',
                'position' => $player['position'] ?? '',
                'years_total' => (int) $c['years_total'],
                'years_remaining' => (int) $c['years_remaining'],
                'salary_annual' => (int) $c['salary_annual'],
                'cap_hit' => (int) $c['cap_hit'],
                'guaranteed' => (int) $c['guaranteed'],
                'dead_cap' => (int) $c['dead_cap'],
            ];
        }

        $salaryCap = (int) $team['salary_cap'];
        $capSpace = $salaryCap - $totalCapHit;

        Response::json([
            'team_id' => (int) $team['id'],
            'team_name' => $team['city'] . ' ' . $team['name'],
            'total_cap' => $salaryCap,
            'cap_used' => $totalCapHit,
            'cap_remaining' => $capSpace,
            'total_guaranteed' => $totalGuaranteed,
            'total_dead_cap' => $totalDeadCap,
            'contracts' => array_map(function ($c) {
                return [
                    'player_name' => $c['player_name'],
                    'cap_hit' => $c['cap_hit'],
                    'years' => $c['years_remaining'],
                    'position' => $c['position'],
                    'player_id' => $c['player_id'],
                    'guaranteed' => $c['guaranteed'],
                ];
            }, $contractDetails),
        ]);
    }

    /**
     * GET /api/coaches/{id}
     * Return coach profile by id.
     */
    public function coach(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $coach = $this->coach->find((int) $params['id']);
        if (!$coach) {
            Response::notFound('Coach not found');
            return;
        }

        $team = $coach['team_id'] ? $this->team->find((int) $coach['team_id']) : null;

        Response::json([
            'coach' => $coach,
            'team' => $team,
        ]);
    }
}
