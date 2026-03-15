<?php

namespace App\Controllers;

use App\Helpers\Response;
use App\Middleware\AuthMiddleware;
use App\Services\DraftEngine;

class DraftController
{
    private DraftEngine $draftEngine;

    public function __construct()
    {
        $this->draftEngine = new DraftEngine();
    }

    /**
     * GET /api/draft/class
     * Get current draft class info.
     */
    public function draftClass(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $leagueId = $auth['league_id'];
        if (!$leagueId) {
            Response::error('No league associated with session');
            return;
        }

        $draftClass = $this->draftEngine->getDraftClass($leagueId);
        if (!$draftClass) {
            Response::json(['draft_class' => null]);
            return;
        }

        Response::json([
            'draft_class' => $draftClass,
        ]);
    }

    /**
     * GET /api/draft/board
     * Get draft board (available prospects), optional ?position= filter.
     */
    public function board(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $leagueId = $auth['league_id'];
        if (!$leagueId) {
            Response::error('No league associated with session');
            return;
        }

        $classId = $this->draftEngine->getCurrentClassId($leagueId);
        if (!$classId) {
            Response::json(['board' => []]);
            return;
        }

        $position = $_GET['position'] ?? null;

        $board = $this->draftEngine->getDraftBoard($classId, $position);

        Response::json([
            'board' => $board,
        ]);
    }

    /**
     * GET /api/draft/my-picks
     * Get the current user's draft picks.
     */
    public function myPicks(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $leagueId = $auth['league_id'];
        if (!$leagueId) {
            Response::error('No league associated with session');
            return;
        }

        $classId = $this->draftEngine->getCurrentClassId($leagueId);
        if (!$classId) {
            Response::json(['picks' => []]);
            return;
        }

        $picks = $this->draftEngine->getTeamPicks($classId, $auth['team_id']);

        Response::json([
            'picks' => $picks,
        ]);
    }

    /**
     * POST /api/draft/scout/{id}
     * Scout a specific prospect.
     */
    public function scout(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $prospectId = (int) $params['id'];

        $report = $this->draftEngine->scoutProspect($prospectId, $auth['team_id']);

        if (!$report) {
            Response::notFound('Prospect not found');
            return;
        }

        if (isset($report['error'])) {
            Response::error($report['error']);
            return;
        }

        Response::success('Scouting report generated', ['report' => $report]);
    }

    /**
     * POST /api/draft/pick
     * Make a draft pick.
     */
    public function pick(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $body = Response::getJsonBody();

        if (empty($body['pick_id'])) {
            Response::error('pick_id is required');
            return;
        }
        if (empty($body['prospect_id'])) {
            Response::error('prospect_id is required');
            return;
        }

        $result = $this->draftEngine->makePick(
            $auth['team_id'],
            (int) $body['pick_id'],
            (int) $body['prospect_id']
        );

        if (!$result) {
            Response::error('Unable to make pick. It may not be your turn or the prospect is unavailable.');
            return;
        }

        // Check if draft is now complete (no remaining picks)
        $this->checkDraftComplete($auth['league_id']);

        Response::success('Draft pick made successfully', ['pick' => $result]);
    }

    /**
     * POST /api/draft/auto-pick
     * Auto-pick for the current user's pick.
     */
    public function autoPick(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $leagueId = $auth['league_id'];
        if (!$leagueId) {
            Response::error('No league associated with session');
            return;
        }

        $result = $this->draftEngine->autoPick($leagueId, $auth['team_id']);

        if (!$result) {
            Response::error('Unable to auto-pick. It may not be your turn.');
            return;
        }

        Response::success('Auto-pick completed', ['pick' => $result]);
    }

    /**
     * POST /api/draft/simulate
     * Simulate the entire draft with AI picks (admin only).
     */
    public function simulate(): void
    {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;

        $leagueId = $auth['league_id'];
        if (!$leagueId) {
            Response::error('No league associated with session');
            return;
        }

        $results = $this->draftEngine->simulateDraft($leagueId);

        // Generate draft coverage narrative after full draft simulation
        $this->checkDraftComplete($leagueId);

        Response::success('Draft simulation completed', [
            'rounds' => $results['rounds'] ?? 0,
            'picks' => $results['picks'] ?? [],
        ]);
    }

    /**
     * POST /api/draft/generate-prospects
     * Generate draft prospects for the current draft class.
     */
    public function generateProspects(): void
    {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;

        $leagueId = $auth['league_id'];
        if (!$leagueId) {
            Response::error('No league associated with session');
            return;
        }

        $classId = $this->draftEngine->getCurrentClassId($leagueId);
        if (!$classId) {
            // Generate the draft class first
            $league = (new \App\Models\League())->find($leagueId);
            $year = $league ? (int) $league['season_year'] : 2026;
            $classId = $this->draftEngine->generateDraftClass($leagueId, $year);
        }

        $pdo = \App\Database\Connection::getInstance()->getPdo();
        $existing = (int) $pdo->query("SELECT COUNT(*) FROM draft_prospects WHERE draft_class_id = {$classId}")->fetchColumn();

        if ($existing > 0) {
            Response::json([
                'message' => "Draft class already has {$existing} prospects",
                'prospects' => $existing,
                'generated' => false,
            ]);
            return;
        }

        // Generate prospects for this draft class
        // Position distribution for a realistic draft
        $positionSlots = [
            'QB' => 15, 'RB' => 20, 'WR' => 30, 'TE' => 12,
            'OT' => 20, 'OG' => 16, 'C' => 10,
            'DE' => 22, 'DT' => 16, 'LB' => 22, 'CB' => 20, 'S' => 16,
            'K' => 3, 'P' => 2, 'LS' => 2,
        ];

        // Use DraftEngine's internal prospect generation by calling generateDraftClass
        // But we already have the class, so generate prospects directly
        $this->draftEngine->generateProspectsForClass($classId);

        $count = (int) $pdo->query("SELECT COUNT(*) FROM draft_prospects WHERE draft_class_id = {$classId}")->fetchColumn();

        // Initialize stock ratings
        $collegeEngine = new \App\Services\CollegeSeasonEngine();
        $collegeEngine->initializeStockRatings($classId);

        Response::json([
            'message' => "Generated {$count} draft prospects with college season tracking",
            'prospects' => $count,
            'generated' => true,
        ]);
    }

    /**
     * GET /api/draft/report
     * Get the weekly draft scouting report with risers, fallers, headlines.
     */
    public function weeklyReport(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $leagueId = $auth['league_id'];
        if (!$leagueId) {
            Response::error('No league associated with session');
            return;
        }

        $collegeEngine = new \App\Services\CollegeSeasonEngine();
        $report = $collegeEngine->getWeeklyDraftReport($leagueId);

        Response::json($report);
    }

    /**
     * GET /api/draft/prospect/{id}
     * Full prospect profile page — game log, scouted data, etc.
     */
    public function prospectProfile(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $prospectId = (int) $params['id'];
        $profile = $this->draftEngine->getProspectProfile($prospectId);

        if (!$profile) {
            Response::notFound('Prospect not found');
            return;
        }

        Response::json($profile);
    }

    /**
     * POST /api/draft/prospect/{id}/favorite
     * Toggle favorite on a prospect.
     */
    public function toggleFavorite(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $prospectId = (int) $params['id'];
        $pdo = \App\Database\Connection::getInstance()->getPdo();

        $stmt = $pdo->prepare("SELECT is_favorited FROM draft_prospects WHERE id = ?");
        $stmt->execute([$prospectId]);
        $current = $stmt->fetch();
        if (!$current) { Response::notFound('Prospect not found'); return; }

        $newVal = $current['is_favorited'] ? 0 : 1;
        $pdo->prepare("UPDATE draft_prospects SET is_favorited = ? WHERE id = ?")->execute([$newVal, $prospectId]);

        Response::json(['favorited' => (bool) $newVal]);
    }

    /**
     * PUT /api/draft/board
     * Update the user's draft board rankings.
     * Body: { rankings: [{ prospect_id: int, rank: int }, ...] }
     */
    public function updateDraftBoard(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $body = Response::getJsonBody();
        $rankings = $body['rankings'] ?? [];
        $pdo = \App\Database\Connection::getInstance()->getPdo();

        // Clear old rankings
        $classId = $this->draftEngine->getCurrentClassId($auth['league_id']);
        if ($classId) {
            $pdo->prepare("UPDATE draft_prospects SET draft_board_rank = NULL WHERE draft_class_id = ?")->execute([$classId]);
        }

        foreach ($rankings as $r) {
            $pdo->prepare("UPDATE draft_prospects SET draft_board_rank = ? WHERE id = ?")->execute([(int) $r['rank'], (int) $r['prospect_id']]);
        }

        Response::json(['success' => true]);
    }

    /**
     * GET /api/draft/my-board
     * Get the user's favorited/ranked prospects.
     */
    public function myBoard(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $classId = $this->draftEngine->getCurrentClassId($auth['league_id']);
        if (!$classId) { Response::json(['board' => []]); return; }

        $pdo = \App\Database\Connection::getInstance()->getPdo();
        $stmt = $pdo->prepare(
            "SELECT id, first_name, last_name, position, college, age, projected_round,
                    stock_rating, stock_trend, scout_level, scouted_overall, scouted_floor, scouted_ceiling,
                    potential, is_favorited, draft_board_rank, injury_flag, character_flag
             FROM draft_prospects
             WHERE draft_class_id = ? AND (is_favorited = 1 OR draft_board_rank IS NOT NULL)
             ORDER BY COALESCE(draft_board_rank, 999) ASC, stock_rating DESC"
        );
        $stmt->execute([$classId]);

        Response::json(['board' => $stmt->fetchAll()]);
    }

    /**
     * GET /api/draft/state
     * Returns the full draft state for the live draft UI.
     */
    public function draftState(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $leagueId = $auth['league_id'];
        if (!$leagueId) {
            Response::error('No league associated with session');
            return;
        }

        $pdo = \App\Database\Connection::getInstance()->getPdo();

        // Get the current draft class for this league
        $classStmt = $pdo->prepare(
            "SELECT id, year, status FROM draft_classes WHERE league_id = ? ORDER BY year DESC LIMIT 1"
        );
        $classStmt->execute([$leagueId]);
        $draftClass = $classStmt->fetch(\PDO::FETCH_ASSOC);

        if (!$draftClass) {
            Response::json([
                'picks'        => [],
                'current_pick' => null,
                'round'        => 1,
                'total_rounds' => 0,
                'draft_year'   => 0,
                'status'       => 'not_started',
            ]);
            return;
        }

        $classId   = (int) $draftClass['id'];
        $draftYear = (int) $draftClass['year'];

        // Derive total rounds from the picks themselves
        $maxRoundStmt = $pdo->prepare("SELECT MAX(round) FROM draft_picks WHERE draft_class_id = ?");
        $maxRoundStmt->execute([$classId]);
        $totalRounds = (int) $maxRoundStmt->fetchColumn() ?: 7;

        // Fetch all picks with team info; for used picks join players table (created from prospect)
        $picksStmt = $pdo->prepare(
            "SELECT
                dp.id,
                dp.round,
                dp.pick_number,
                dp.is_used,
                dp.current_team_id AS team_id,
                dp.player_id,
                t.name              AS team_name,
                t.city              AS team_city,
                t.abbreviation      AS team_abbreviation,
                t.primary_color     AS team_primary_color,
                t.secondary_color   AS team_secondary_color,
                p.first_name        AS player_first_name,
                p.last_name         AS player_last_name,
                p.position          AS player_position,
                p.college           AS player_college,
                p.age               AS player_age,
                p.overall_rating    AS player_overall
             FROM draft_picks dp
             LEFT JOIN teams t   ON t.id = dp.current_team_id
             LEFT JOIN players p ON p.id = dp.player_id AND dp.is_used = 1
             WHERE dp.draft_class_id = ?
             ORDER BY dp.round ASC, dp.pick_number ASC"
        );
        $picksStmt->execute([$classId]);
        $rawPicks = $picksStmt->fetchAll(\PDO::FETCH_ASSOC);

        $picks = [];
        $currentPick = null;
        $currentRound = 1;
        $overallPick = 0;

        foreach ($rawPicks as $row) {
            $isUsed = (bool) $row['is_used'];
            $overallPick++;

            $pick = [
                'id'                   => (int) $row['id'],
                'round'                => (int) $row['round'],
                'pick_number'          => (int) $row['pick_number'],
                'overall_pick'         => $overallPick,
                'is_used'              => $isUsed,
                'team_id'              => (int) $row['team_id'],
                'team_name'            => $row['team_name'] ?? '',
                'team_city'            => $row['team_city'] ?? '',
                'team_abbreviation'    => $row['team_abbreviation'] ?? '',
                'team_primary_color'   => $row['team_primary_color'] ?? '#333333',
                'team_secondary_color' => $row['team_secondary_color'] ?? '#666666',
            ];

            if ($isUsed && $row['player_id']) {
                $pick['prospect_id']       = (int) $row['player_id'];
                $pick['prospect_name']     = trim(($row['player_first_name'] ?? '') . ' ' . ($row['player_last_name'] ?? ''));
                $pick['prospect_position'] = $row['player_position'] ?? null;
                $pick['prospect_college']  = $row['player_college'] ?? null;
                $pick['prospect_age']      = $row['player_age'] !== null ? (int) $row['player_age'] : null;
                $pick['prospect_overall']  = $row['player_overall'] !== null ? (int) $row['player_overall'] : null;
            }

            $picks[] = $pick;

            // First unused pick is "on the clock"
            if (!$isUsed && $currentPick === null) {
                $currentPick  = $pick;
                $currentRound = (int) $row['round'];
            }
        }

        // Determine status
        if (empty($picks)) {
            $status = 'not_started';
        } elseif ($currentPick === null) {
            $status = 'complete';
        } else {
            $usedCount = count(array_filter($picks, fn($p) => $p['is_used']));
            $status = $usedCount === 0 ? 'not_started' : 'in_progress';
        }

        Response::json([
            'picks'        => $picks,
            'current_pick' => $currentPick,
            'round'        => $currentRound,
            'total_rounds' => $totalRounds,
            'draft_year'   => $draftYear,
            'status'       => $status,
        ]);
    }

    /**
     * GET /api/draft/budget
     * Get current scouting budget (used/remaining/total).
     */
    public function scoutingBudget(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $leagueId = $auth['league_id'];
        if (!$leagueId) {
            Response::error('No league associated with session');
            return;
        }

        $budget = $this->draftEngine->getScoutingBudget($leagueId);
        Response::json($budget);
    }

    /**
     * Check if the draft is complete and generate narrative coverage if so.
     */
    private function checkDraftComplete(int $leagueId): void
    {
        if (!class_exists('App\\Services\\NarrativeEngine')) {
            return;
        }

        try {
            $pdo = \App\Database\Connection::getInstance()->getPdo();
            $classId = $this->draftEngine->getCurrentClassId($leagueId);
            if (!$classId) return;

            // Check if any picks remain
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM draft_picks WHERE draft_class_id = ? AND is_used = 0"
            );
            $stmt->execute([$classId]);
            $remaining = (int) $stmt->fetchColumn();

            if ($remaining > 0) return;

            // Draft is complete — gather all picks
            $stmt = $pdo->prepare(
                "SELECT dp.round, dp.pick_number, dp.current_team_id AS team_id, dp.player_id,
                        p.first_name, p.last_name, p.position, p.overall_rating,
                        t.city AS team_city, t.name AS team_name, t.abbreviation AS team_abbreviation
                 FROM draft_picks dp
                 LEFT JOIN players p ON p.id = dp.player_id
                 LEFT JOIN teams t ON t.id = dp.current_team_id
                 WHERE dp.draft_class_id = ? AND dp.is_used = 1
                 ORDER BY dp.round ASC, dp.pick_number ASC"
            );
            $stmt->execute([$classId]);
            $allPicks = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if (empty($allPicks)) return;

            // Get season ID
            $leagueModel = new \App\Models\League();
            $season = $leagueModel->getCurrentSeason($leagueId);
            $seasonId = $season ? (int) $season['id'] : 0;

            $engine = new \App\Services\NarrativeEngine();
            $engine->generateDraftCoverage($leagueId, $seasonId, $allPicks);

            // Draft scout coverage — both writers react to picks
            if (class_exists('App\\Services\\DraftScoutEngine')) {
                try {
                    $scout = new \App\Services\DraftScoutEngine();
                    $scout->generateDraftDayNarrative($leagueId, $seasonId, $allPicks);
                } catch (\Throwable $e) {
                    error_log("DraftScout draft day error: " . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            error_log("NarrativeEngine draft coverage error: " . $e->getMessage());
        }
    }
}
