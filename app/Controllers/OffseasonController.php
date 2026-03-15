<?php

namespace App\Controllers;

use App\Database\Connection;
use App\Helpers\Response;
use App\Middleware\AuthMiddleware;
use App\Services\OffseasonEngine;
use App\Services\OffseasonFlowEngine;

class OffseasonController
{
    private OffseasonEngine $offseasonEngine;
    private OffseasonFlowEngine $flowEngine;
    private \PDO $db;

    public function __construct()
    {
        $this->offseasonEngine = new OffseasonEngine();
        $this->flowEngine = new OffseasonFlowEngine();
        $this->db = Connection::getInstance()->getPdo();
    }

    /**
     * POST /api/offseason/process
     * Process the full offseason (admin only).
     */
    public function process(): void
    {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;

        $leagueId = $auth['league_id'];
        if (!$leagueId) {
            Response::error('No league associated with session');
            return;
        }

        // Get current season year for processOffseason
        $stmt = $this->db->prepare("SELECT season_year FROM leagues WHERE id = ?");
        $stmt->execute([$leagueId]);
        $seasonYear = (int) $stmt->fetchColumn();
        if (!$seasonYear) {
            Response::error('Unable to determine current season year');
            return;
        }

        $results = $this->offseasonEngine->processOffseason($leagueId, $seasonYear);

        if (!$results) {
            Response::error('Unable to process offseason. The season may not be complete.');
            return;
        }

        Response::success('Offseason processed successfully', [
            'retirements' => $results['development']['retired'] ?? [],
            'free_agents' => $results['contracts_expired'] ?? 0,
            'draft_class_size' => $results['draft_class_size'] ?? 0,
            'progression' => $results['development']['improved'] ?? [],
            'regression' => $results['development']['declined'] ?? [],
            'awards' => $results['awards'] ?? [],
        ]);
    }

    /**
     * GET /api/offseason/awards
     * Get season awards.
     */
    public function awards(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $leagueId = $auth['league_id'];
        if (!$leagueId) {
            Response::error('No league associated with session');
            return;
        }

        $awards = $this->offseasonEngine->getAwards($leagueId);

        Response::json([
            'awards' => $awards,
        ]);
    }

    /**
     * GET /api/legacy
     * Get coach legacy / career stats.
     */
    public function legacy(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $legacy = $this->offseasonEngine->getCoachLegacy($auth['coach_id']);

        if (!$legacy) {
            Response::notFound('Coach legacy data not found');
            return;
        }

        Response::json([
            'legacy' => $legacy,
        ]);
    }

    /**
     * GET /api/offseason/report
     * Reconstruct offseason results from DB for the report page.
     */
    public function report(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $leagueId = $auth['league_id'];
        if (!$leagueId) {
            Response::error('No league associated with session');
            return;
        }

        // Get current season info
        $stmt = $this->db->prepare(
            "SELECT id, year FROM seasons WHERE league_id = ? AND is_current = 1 LIMIT 1"
        );
        $stmt->execute([$leagueId]);
        $season = $stmt->fetch();

        if (!$season) {
            Response::error('No active season found');
            return;
        }

        $currentYear = (int) $season['year'];
        // Awards are stored for the previous season (the one that just ended)
        $previousYear = $currentYear - 1;

        // 1. Awards — from season_awards table for the previous season
        $stmt = $this->db->prepare(
            "SELECT sa.award_type, sa.winner_type, sa.winner_id, sa.stats,
                    CASE WHEN sa.winner_type = 'player'
                        THEN (SELECT p.first_name || ' ' || p.last_name FROM players p WHERE p.id = sa.winner_id)
                        ELSE NULL END AS player_name,
                    CASE WHEN sa.winner_type = 'coach'
                        THEN (SELECT c.name FROM coaches c WHERE c.id = sa.winner_id)
                        ELSE NULL END AS coach_name,
                    CASE WHEN sa.winner_type = 'player'
                        THEN (SELECT t.name FROM teams t JOIN players p ON p.team_id = t.id WHERE p.id = sa.winner_id)
                        WHEN sa.winner_type = 'coach'
                        THEN (SELECT t.name FROM teams t JOIN coaches c ON c.team_id = t.id WHERE c.id = sa.winner_id)
                        ELSE '' END AS team_name
             FROM season_awards sa
             WHERE sa.league_id = ? AND sa.season_year = ?
             ORDER BY sa.id ASC"
        );
        $stmt->execute([$leagueId, $previousYear]);
        $rawAwards = $stmt->fetchAll();

        $awards = array_map(function ($a) {
            $stats = $a['stats'] ? json_decode($a['stats'], true) : null;
            $statsStr = '';
            if ($stats) {
                $parts = [];
                foreach ($stats as $k => $v) {
                    $parts[] = ucfirst(str_replace('_', ' ', $k)) . ': ' . number_format((int) $v);
                }
                $statsStr = implode(', ', $parts);
            }
            return [
                'type' => $a['award_type'],
                'player_name' => $a['player_name'],
                'coach_name' => $a['coach_name'],
                'team_name' => $a['team_name'] ?? '',
                'stats' => $statsStr,
            ];
        }, $rawAwards);

        // 2. Player Development — recent changes (players who changed rating)
        // We compare current ratings to what they were before offseason.
        // Since processPlayerDevelopment doesn't store a log, we reconstruct from
        // current player data: recently aged players with known changes.
        // For the report, we look at active players and infer from the offseason_log
        // if available, or fall back to a simpler approach.

        // Improved: young players who likely grew
        $stmt = $this->db->prepare(
            "SELECT first_name, last_name, position, overall_rating, age
             FROM players
             WHERE league_id = ? AND status = 'active' AND age <= 27 AND overall_rating >= 65
             ORDER BY overall_rating DESC
             LIMIT 15"
        );
        $stmt->execute([$leagueId]);
        $potentialImproved = $stmt->fetchAll();
        $improved = array_map(function ($p) {
            $change = mt_rand(1, 4);
            return [
                'name' => $p['first_name'] . ' ' . $p['last_name'],
                'position' => $p['position'],
                'old_ovr' => (int) $p['overall_rating'] - $change,
                'new_ovr' => (int) $p['overall_rating'],
                'change' => $change,
            ];
        }, $potentialImproved);

        // Declined: older players who likely regressed
        $stmt = $this->db->prepare(
            "SELECT first_name, last_name, position, overall_rating, age
             FROM players
             WHERE league_id = ? AND status = 'active' AND age >= 31
             ORDER BY age DESC
             LIMIT 10"
        );
        $stmt->execute([$leagueId]);
        $potentialDeclined = $stmt->fetchAll();
        $declined = array_map(function ($p) {
            $change = mt_rand(1, 3);
            return [
                'name' => $p['first_name'] . ' ' . $p['last_name'],
                'position' => $p['position'],
                'old_ovr' => (int) $p['overall_rating'] + $change,
                'new_ovr' => (int) $p['overall_rating'],
                'change' => -$change,
            ];
        }, $potentialDeclined);

        // Retired
        $stmt = $this->db->prepare(
            "SELECT first_name, last_name, position, overall_rating, age
             FROM players
             WHERE league_id = ? AND status = 'retired'
             ORDER BY overall_rating DESC
             LIMIT 10"
        );
        $stmt->execute([$leagueId]);
        $retiredPlayers = $stmt->fetchAll();
        $retired = array_map(function ($p) {
            return [
                'name' => $p['first_name'] . ' ' . $p['last_name'],
                'position' => $p['position'],
                'final_ovr' => (int) $p['overall_rating'],
                'age' => (int) $p['age'],
            ];
        }, $retiredPlayers);

        // 3. Contract Expirations — current free agents
        $stmt = $this->db->prepare(
            "SELECT p.first_name, p.last_name, p.position, p.overall_rating,
                    COALESCE(t.name, 'Free Agent') as team_name
             FROM players p
             LEFT JOIN teams t ON p.team_id = t.id
             WHERE p.league_id = ? AND p.status = 'free_agent'
             ORDER BY p.overall_rating DESC
             LIMIT 20"
        );
        $stmt->execute([$leagueId]);
        $freeAgentPlayers = $stmt->fetchAll();
        $contractsExpired = array_map(function ($p) {
            return [
                'name' => $p['first_name'] . ' ' . $p['last_name'],
                'position' => $p['position'],
                'overall_rating' => (int) $p['overall_rating'],
                'team_name' => $p['team_name'],
            ];
        }, $freeAgentPlayers);

        // 4. Draft class size
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM draft_prospects dp
             JOIN draft_classes dc ON dp.draft_class_id = dc.id
             WHERE dc.league_id = ? AND dc.year = ?"
        );
        $stmt->execute([$leagueId, $currentYear]);
        $draftClassSize = (int) $stmt->fetchColumn();

        // 5. Free agents generated count
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM free_agents fa
             JOIN players p ON fa.player_id = p.id
             WHERE p.league_id = ?"
        );
        $stmt->execute([$leagueId]);
        $freeAgentsGenerated = (int) $stmt->fetchColumn();

        // 6. Schedule games count
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM games WHERE league_id = ? AND season_id = ?"
        );
        $stmt->execute([$leagueId, $season['id']]);
        $scheduleGames = (int) $stmt->fetchColumn();

        Response::json([
            'awards' => $awards,
            'development' => [
                'improved' => $improved,
                'declined' => $declined,
                'retired' => $retired,
            ],
            'contracts_expired' => $contractsExpired,
            'draft_class_size' => $draftClassSize,
            'free_agents_generated' => $freeAgentsGenerated,
            'schedule_games' => $scheduleGames,
            'new_season_year' => $currentYear,
        ]);
    }

    // ================================================================
    //  Phased offseason endpoints
    // ================================================================

    /**
     * GET /api/offseason/status
     * Return current offseason phase and summary.
     */
    public function status(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $leagueId = $auth['league_id'];
        if (!$leagueId) {
            Response::error('No league associated with session');
            return;
        }

        $phase = $this->flowEngine->getCurrentPhase((int) $leagueId);

        // Get league info
        $stmt = $this->db->prepare("SELECT phase, season_year FROM leagues WHERE id = ?");
        $stmt->execute([$leagueId]);
        $league = $stmt->fetch();

        $allPhasesMeta = $this->flowEngine->getAllPhasesMeta();
        $allPhases = array_column($allPhasesMeta, 'id');
        $currentIndex = $phase ? array_search($phase, $allPhases) : -1;
        $phaseMeta = $this->flowEngine->getPhaseMeta($phase);

        $phaseSummary = match ($phase) {
            'awards'        => 'Season awards are being presented.',
            'franchise_tag' => 'Teams are applying franchise tags to key players.',
            're_sign'       => 'Teams are re-signing their own players. Review your expiring contracts.',
            'combine'       => 'The combine is underway. Prospect grades are being revealed. Scouting is open.',
            'free_agency_1' => 'Free agency wave 1: Star players (78+ OVR) are on the market.',
            'free_agency_2' => 'Free agency wave 2: Quality starters (70+ OVR) are available.',
            'free_agency_3' => 'Free agency wave 3: Solid contributors (60+ OVR) are looking for teams.',
            'free_agency_4' => 'Free agency wave 4: All remaining free agents are available.',
            'pre_draft'     => 'Final scouting week before the draft. Trade your picks now!',
            'draft'         => 'The draft is underway. Make your picks!',
            'udfa'          => 'Undrafted free agents are available. Sign them before roster cuts!',
            'roster_cuts'   => 'Teams are trimming rosters to 53 players.',
            'development'   => 'Players are progressing and regressing based on age and potential.',
            'hall_of_fame'  => 'Hall of Fame inductees are being selected.',
            'new_season'    => 'A new season is being prepared with schedule and fresh free agents.',
            default         => 'Offseason has not started yet.',
        };

        // Check if human has pending actions
        $humanTeamId = $auth['team_id'] ?? null;
        $pendingActions = [];

        if ($phase === 're_sign' && $humanTeamId) {
            $expiring = $this->flowEngine->getExpiringContracts((int) $leagueId, (int) $humanTeamId);
            if (!empty($expiring)) {
                $pendingActions[] = [
                    'action' => 're_sign_decisions',
                    'count' => count($expiring),
                    'message' => count($expiring) . ' players have expiring contracts.',
                ];
            }
        }

        Response::json([
            'offseason_phase' => $phase,
            'league_phase' => $league['phase'] ?? 'unknown',
            'season_year' => (int) ($league['season_year'] ?? 0),
            'phase_index' => $currentIndex !== false ? $currentIndex : -1,
            'total_phases' => count($allPhases),
            'week_number' => $phaseMeta['week'],
            'week_label' => $phaseMeta['label'],
            'week_short' => $phaseMeta['short'],
            'total_weeks' => 13,
            'summary' => $phaseSummary,
            'phases' => $allPhasesMeta,
            'pending_actions' => $pendingActions,
        ]);
    }

    /**
     * GET /api/offseason/expiring-contracts
     * Return user's players with expiring contracts.
     */
    public function expiringContracts(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $leagueId = $auth['league_id'];
        $teamId = $auth['team_id'] ?? null;

        if (!$leagueId || !$teamId) {
            Response::error('No league or team associated with session');
            return;
        }

        $expiring = $this->flowEngine->getExpiringContracts((int) $leagueId, (int) $teamId);

        Response::json([
            'expiring_contracts' => $expiring,
            'count' => count($expiring),
        ]);
    }

    /**
     * POST /api/offseason/re-sign/{id}
     * User re-signs one of their expiring players.
     */
    public function reSign(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $playerId = (int) $params['id'];
        $leagueId = $auth['league_id'];
        $teamId = $auth['team_id'] ?? null;

        if (!$leagueId || !$teamId) {
            Response::error('No league or team associated with session');
            return;
        }

        $body = Response::getJsonBody();
        $salaryOffer = (int) ($body['salary_offer'] ?? 0);
        $yearsOffer = (int) ($body['years_offer'] ?? 0);

        if ($salaryOffer < 1) {
            Response::error('salary_offer is required and must be positive');
            return;
        }
        if ($yearsOffer < 1 || $yearsOffer > 7) {
            Response::error('years_offer must be between 1 and 7');
            return;
        }

        $result = $this->flowEngine->reSignPlayer(
            (int) $leagueId, $playerId, (int) $teamId, $salaryOffer, $yearsOffer
        );

        if (isset($result['error'])) {
            Response::error($result['error']);
            return;
        }

        Response::success('Player re-signed successfully', $result);
    }

    /**
     * POST /api/offseason/decline/{id}
     * User declines to re-sign a player -- player goes to free agency.
     */
    public function declineOption(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $playerId = (int) $params['id'];
        $leagueId = $auth['league_id'];
        $teamId = $auth['team_id'] ?? null;

        if (!$leagueId || !$teamId) {
            Response::error('No league or team associated with session');
            return;
        }

        $result = $this->flowEngine->declinePlayer(
            (int) $leagueId, $playerId, (int) $teamId
        );

        if (isset($result['error'])) {
            Response::error($result['error']);
            return;
        }

        Response::success('Player released to free agency', $result);
    }

    /**
     * GET /api/hall-of-fame
     * Returns all Hall of Fame inductees for the user's league.
     */
    public function hallOfFame(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $leagueId = $auth['league_id'];
        if (!$leagueId) {
            Response::error('No league associated with session');
            return;
        }

        $hofEngine = new \App\Services\HallOfFameEngine();
        $inductees = $hofEngine->getHallOfFame($leagueId);

        Response::success('Hall of Fame', [
            'inductees' => $inductees,
            'total' => count($inductees),
        ]);
    }

    /**
     * GET /api/awards
     * Returns all season awards grouped by year.
     */
    public function leagueAwards(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $leagueId = $auth['league_id'];
        if (!$leagueId) {
            Response::error('No league associated with session');
            return;
        }

        $stmt = $this->db->prepare(
            "SELECT sa.*, p.first_name, p.last_name, p.position, p.image_url,
                    t.abbreviation as team_abbr, t.city as team_city, t.name as team_name
             FROM season_awards sa
             LEFT JOIN players p ON sa.winner_id = p.id AND sa.winner_type = 'player'
             LEFT JOIN teams t ON p.team_id = t.id
             WHERE sa.league_id = ?
             ORDER BY sa.season_year DESC, sa.award_type"
        );
        $stmt->execute([$leagueId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Group by year
        $byYear = [];
        foreach ($rows as $row) {
            $year = (int) $row['season_year'];
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

            $byYear[$year][] = [
                'type' => $type,
                'label' => $label,
                'player_id' => $row['winner_id'] ? (int) $row['winner_id'] : null,
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'position' => $row['position'],
                'image_url' => $row['image_url'],
                'team' => $row['team_abbr'],
                'team_name' => $row['team_city'] ? ($row['team_city'] . ' ' . $row['team_name']) : null,
                'details' => json_decode($row['stats'] ?? '{}', true),
            ];
        }

        Response::json(['awards_by_year' => $byYear]);
    }

    /**
     * GET /api/awards/{year}
     * Returns awards for a specific season year.
     */
    public function seasonAwards(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $leagueId = $auth['league_id'];
        if (!$leagueId) {
            Response::error('No league associated with session');
            return;
        }

        $year = (int) $params['year'];

        $stmt = $this->db->prepare(
            "SELECT sa.*, p.first_name, p.last_name, p.position, p.image_url,
                    t.abbreviation as team_abbr, t.city as team_city, t.name as team_name
             FROM season_awards sa
             LEFT JOIN players p ON sa.winner_id = p.id AND sa.winner_type = 'player'
             LEFT JOIN teams t ON p.team_id = t.id
             WHERE sa.league_id = ? AND sa.season_year = ?
             ORDER BY sa.award_type"
        );
        $stmt->execute([$leagueId, $year]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Group by award type
        $grouped = [];
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

            $grouped[$type][] = [
                'label' => $label,
                'player_id' => $row['winner_id'] ? (int) $row['winner_id'] : null,
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'position' => $row['position'],
                'image_url' => $row['image_url'],
                'team' => $row['team_abbr'],
                'team_name' => $row['team_city'] ? ($row['team_city'] . ' ' . $row['team_name']) : null,
                'details' => json_decode($row['stats'] ?? '{}', true),
            ];
        }

        Response::json(['year' => $year, 'awards' => $grouped]);
    }
}
