<?php

namespace App\Controllers;

use App\Helpers\Response;
use App\Middleware\AuthMiddleware;
use App\Models\Team;
use App\Models\Coach;
use App\Models\Player;
use App\Models\Contract;
use App\Models\Injury;
use App\Services\ContractEngine;

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
            'rankings' => $this->getTeamRankings((int) $team['id'], (int) $team['league_id']),
        ]);
    }

    /**
     * Compute per-game stat rankings for a team relative to all teams in the league.
     */
    private function getTeamRankings(int $teamId, int $leagueId): array
    {
        $db = \App\Database\Connection::getInstance()->getPdo();

        $stmt = $db->prepare("
            SELECT
                gs.team_id,
                COUNT(DISTINCT gs.game_id) as games_played,
                SUM(gs.pass_yards) as pass_yds,
                SUM(gs.rush_yards) as rush_yds,
                SUM(gs.pass_yards) + SUM(gs.rush_yards) as total_yds
            FROM game_stats gs
            JOIN games g ON g.id = gs.game_id
            JOIN teams t ON t.id = gs.team_id
            WHERE t.league_id = ?
            GROUP BY gs.team_id
            ORDER BY total_yds DESC
        ");
        $stmt->execute([$leagueId]);
        $allTeams = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($allTeams)) {
            return ['pass_rank' => null, 'rush_rank' => null, 'total_rank' => null];
        }

        // Find the max games played to determine if we should use totals or per-game
        $maxGames = max(array_column($allTeams, 'games_played'));

        // If all teams have played similar games (within 1), rank by totals
        // Otherwise rank by per-game average
        $usePerGame = $maxGames > 3; // early season = use totals, later = per game

        $passRanked = $allTeams;
        usort($passRanked, function ($a, $b) use ($usePerGame) {
            $aVal = $usePerGame ? ($a['pass_yds'] / max(1, $a['games_played'])) : $a['pass_yds'];
            $bVal = $usePerGame ? ($b['pass_yds'] / max(1, $b['games_played'])) : $b['pass_yds'];
            return $bVal <=> $aVal;
        });
        $rushRanked = $allTeams;
        usort($rushRanked, function ($a, $b) use ($usePerGame) {
            $aVal = $usePerGame ? ($a['rush_yds'] / max(1, $a['games_played'])) : $a['rush_yds'];
            $bVal = $usePerGame ? ($b['rush_yds'] / max(1, $b['games_played'])) : $b['rush_yds'];
            return $bVal <=> $aVal;
        });
        $totalRanked = $allTeams;
        usort($totalRanked, function ($a, $b) use ($usePerGame) {
            $aVal = $usePerGame ? ($a['total_yds'] / max(1, $a['games_played'])) : $a['total_yds'];
            $bVal = $usePerGame ? ($b['total_yds'] / max(1, $b['games_played'])) : $b['total_yds'];
            return $bVal <=> $aVal;
        });

        $findRank = function (array $ranked, int $tid): array {
            foreach ($ranked as $i => $row) {
                if ((int) $row['team_id'] === $tid) {
                    $gp = max(1, (int) $row['games_played']);
                    return ['rank' => $i + 1];
                }
            }
            return ['rank' => null];
        };

        // Get this team's per-game values
        $myRow = null;
        foreach ($allTeams as $row) {
            if ((int) $row['team_id'] === $teamId) {
                $myRow = $row;
                break;
            }
        }

        $gp = $myRow ? max(1, (int) $myRow['games_played']) : 1;

        return [
            'pass_rank' => $findRank($passRanked, $teamId)['rank'],
            'pass_ypg' => $myRow ? round((int) $myRow['pass_yds'] / $gp, 1) : 0,
            'rush_rank' => $findRank($rushRanked, $teamId)['rank'],
            'rush_ypg' => $myRow ? round((int) $myRow['rush_yds'] / $gp, 1) : 0,
            'total_rank' => $findRank($totalRanked, $teamId)['rank'],
            'total_ypg' => $myRow ? round(((int) $myRow['pass_yds'] + (int) $myRow['rush_yds']) / $gp, 1) : 0,
        ];
    }

    /**
     * GET /api/teams/{id}/cap
     * Return cap space breakdown with detailed contract data, position groupings, and projections.
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

        $teamId = (int) $team['id'];
        $contractEngine = new ContractEngine();

        // Get full cap breakdown including dead cap charges from released players
        $capInfo = $contractEngine->calculateTeamCap($teamId);

        // Get dead cap charge details (individual line items)
        $deadCapCharges = $contractEngine->getDeadCapCharges($teamId);
        $deadCapChargesFormatted = [];
        foreach ($deadCapCharges as $charge) {
            $deadCapChargesFormatted[] = [
                'player_id'   => (int) $charge['player_id'],
                'player_name' => ($charge['first_name'] ?? '') . ' ' . ($charge['last_name'] ?? ''),
                'position'    => $charge['position'] ?? '',
                'cap_charge'  => (int) $charge['cap_charge'],
                'charge_type' => $charge['charge_type'],
                'is_post_june1' => (bool) $charge['is_post_june1'],
                'description' => $charge['description'] ?? '',
            ];
        }

        // Get active contracts
        $contracts = $this->contract->all(['team_id' => $teamId], 'cap_hit DESC');

        $totalCapHit = 0;
        $totalGuaranteed = 0;
        $totalContractDeadCap = 0;
        $contractDetails = [];
        $byPosition = [];
        $committedNextYear = 0;

        foreach ($contracts as $c) {
            $capHit = (int) $c['cap_hit'];
            $totalCapHit += $capHit;
            $totalGuaranteed += (int) $c['guaranteed'];
            $totalContractDeadCap += (int) $c['dead_cap'];

            $player = $this->player->find((int) $c['player_id']);
            $position = $player['position'] ?? '';
            $yearsRemaining = (int) $c['years_remaining'];
            $salaryAnnual = (int) $c['salary_annual'];

            // Calculate what dead cap would be if this player were cut
            $cutDeadCap = $contractEngine->calculateDeadCap((int) $c['id']);

            $contractDetails[] = [
                'contract_id' => (int) $c['id'],
                'player_id' => (int) $c['player_id'],
                'player_name' => $player ? $player['first_name'] . ' ' . $player['last_name'] : 'Unknown',
                'position' => $position,
                'overall_rating' => $player ? (int) $player['overall_rating'] : 0,
                'age' => $player ? (int) $player['age'] : 0,
                'years_total' => (int) $c['years_total'],
                'years_remaining' => $yearsRemaining,
                'salary_annual' => $salaryAnnual,
                'cap_hit' => $capHit,
                'guaranteed' => (int) $c['guaranteed'],
                'dead_cap' => (int) $c['dead_cap'],
                'dead_cap_if_cut' => $cutDeadCap,
                'cap_saved_if_cut' => max(0, $capHit - $cutDeadCap),
            ];

            // Aggregate by position
            if ($position) {
                if (!isset($byPosition[$position])) {
                    $byPosition[$position] = ['total_salary' => 0, 'count' => 0];
                }
                $byPosition[$position]['total_salary'] += $capHit;
                $byPosition[$position]['count'] += 1;
            }

            // Committed cap for next year (contracts that extend beyond this season)
            if ($yearsRemaining > 1) {
                $committedNextYear += $salaryAnnual;
            }
        }

        $salaryCap = (int) $team['salary_cap'];
        $releaseDeadMoney = $capInfo['dead_cap_charges'] ?? 0;

        Response::json([
            'team_id' => $teamId,
            'team_name' => $team['city'] . ' ' . $team['name'],
            'total_cap' => $salaryCap,
            'cap_used' => $capInfo['cap_used'],
            'cap_remaining' => $capInfo['cap_remaining'],
            'active_contracts_total' => $totalCapHit,
            'dead_cap_charges_total' => $releaseDeadMoney,
            'next_year_dead_cap' => $capInfo['next_year_dead_cap'] ?? 0,
            'total_guaranteed' => $totalGuaranteed,
            'total_dead_cap' => $totalContractDeadCap + $releaseDeadMoney,
            'contracts' => $contractDetails,
            'dead_cap_charges' => $deadCapChargesFormatted,
            'by_position' => $byPosition,
            'committed_next_year' => $committedNextYear,
            'projected_cap_available' => $salaryCap - $committedNextYear - ($capInfo['next_year_dead_cap'] ?? 0),
            'dead_money' => $totalContractDeadCap + $releaseDeadMoney,
        ]);
    }

    /**
     * GET /api/teams/{id}/contracts
     * Return all contracts for a team with player info.
     */
    public function contracts(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $team = $this->team->find((int) $params['id']);
        if (!$team) {
            Response::notFound('Team not found');
            return;
        }

        $pdo = \App\Database\Connection::getInstance()->getPdo();

        $stmt = $pdo->prepare(
            "SELECT c.*, p.first_name, p.last_name, p.position, p.overall_rating, p.age, p.potential
             FROM contracts c
             JOIN players p ON c.player_id = p.id
             WHERE c.team_id = ? AND c.status = 'active'
             ORDER BY c.cap_hit DESC"
        );
        $stmt->execute([(int) $params['id']]);
        $contracts = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $contractEngine = new ContractEngine();
        $capInfo = $contractEngine->calculateTeamCap((int) $params['id']);

        $formatted = [];
        foreach ($contracts as $c) {
            $formatted[] = [
                'contract_id'    => (int) $c['id'],
                'player_id'      => (int) $c['player_id'],
                'player_name'    => $c['first_name'] . ' ' . $c['last_name'],
                'position'       => $c['position'],
                'overall_rating' => (int) $c['overall_rating'],
                'age'            => (int) $c['age'],
                'potential'      => $c['potential'],
                'years_total'    => (int) $c['years_total'],
                'years_remaining'=> (int) $c['years_remaining'],
                'salary_annual'  => (int) $c['salary_annual'],
                'cap_hit'        => (int) $c['cap_hit'],
                'guaranteed'     => (int) $c['guaranteed'],
                'dead_cap'       => (int) $c['dead_cap'],
                'signing_bonus'  => (int) ($c['signing_bonus'] ?? 0),
                'base_salary'    => (int) ($c['base_salary'] ?? 0),
                'contract_type'  => $c['contract_type'] ?? 'standard',
                'total_value'    => (int) ($c['total_value'] ?? 0),
            ];
        }

        Response::json([
            'team_id'       => (int) $team['id'],
            'team_name'     => $team['city'] . ' ' . $team['name'],
            'cap'           => $capInfo,
            'contracts'     => $formatted,
            'contract_count'=> count($formatted),
        ]);
    }

    /**
     * POST /api/contracts/{id}/restructure
     * Restructure a contract to save cap space this year.
     */
    public function restructureContract(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $contractId = (int) $params['id'];

        $contractEngine = new ContractEngine();
        $result = $contractEngine->restructureContract($contractId);

        if (isset($result['error'])) {
            Response::error($result['error']);
            return;
        }

        Response::json($result);
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
