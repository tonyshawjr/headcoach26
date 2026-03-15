<?php

namespace App\Services;

use App\Database\Connection;

/**
 * OffseasonFlowEngine -- Master orchestrator for the phased offseason.
 *
 * Phases (in order):
 *   awards -> franchise_tag -> re_sign -> combine -> free_agency_1
 *   -> free_agency_2 -> free_agency_3 -> free_agency_4 -> pre_draft
 *   -> draft -> udfa -> roster_cuts -> development -> hall_of_fame
 *   -> new_season
 */
class OffseasonFlowEngine
{
    private \PDO $db;

    private const PHASES = [
        'awards',
        'franchise_tag',
        're_sign',
        'combine',
        'free_agency_1',
        'free_agency_2',
        'free_agency_3',
        'free_agency_4',
        'pre_draft',
        'draft',
        'udfa',
        'roster_cuts',
        'development',
        'hall_of_fame',
        'new_season',
    ];

    /** Human-readable week labels for each phase */
    private const PHASE_META = [
        'awards'         => ['week' => 1,  'label' => 'Awards & Franchise Tags', 'short' => 'Awards'],
        'franchise_tag'  => ['week' => 1,  'label' => 'Awards & Franchise Tags', 'short' => 'Tags'],
        're_sign'        => ['week' => 2,  'label' => 'Re-Sign Window',          'short' => 'Re-Sign'],
        'combine'        => ['week' => 3,  'label' => 'Combine & Scouting',      'short' => 'Combine'],
        'free_agency_1'  => ['week' => 4,  'label' => 'Free Agency: Big Names',  'short' => 'FA Wave 1'],
        'free_agency_2'  => ['week' => 5,  'label' => 'Free Agency: Starters',   'short' => 'FA Wave 2'],
        'free_agency_3'  => ['week' => 6,  'label' => 'Free Agency: Depth',      'short' => 'FA Wave 3'],
        'free_agency_4'  => ['week' => 7,  'label' => 'Free Agency: Remaining',  'short' => 'FA Wave 4'],
        'pre_draft'      => ['week' => 8,  'label' => 'Pre-Draft Week',          'short' => 'Pre-Draft'],
        'draft'          => ['week' => 9,  'label' => 'The Draft',               'short' => 'Draft'],
        'udfa'           => ['week' => 10, 'label' => 'Undrafted Free Agents',   'short' => 'UDFAs'],
        'roster_cuts'    => ['week' => 11, 'label' => 'Roster Cuts',             'short' => 'Cuts'],
        'development'    => ['week' => 12, 'label' => 'Development & Hall of Fame', 'short' => 'Dev'],
        'hall_of_fame'   => ['week' => 12, 'label' => 'Development & Hall of Fame', 'short' => 'HOF'],
        'new_season'     => ['week' => 13, 'label' => 'New Season',              'short' => 'New Season'],
    ];

    /** OVR thresholds per FA round (stars sign first) */
    private const FA_ROUND_THRESHOLDS = [
        1 => 78,
        2 => 70,
        3 => 60,
        4 => 0,
    ];

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
        $this->ensureOffseasonPhaseColumn();
    }

    // ================================================================
    //  Column migration (idempotent)
    // ================================================================

    private function ensureOffseasonPhaseColumn(): void
    {
        $cols = $this->db->query("PRAGMA table_info(leagues)")->fetchAll(\PDO::FETCH_ASSOC);
        $names = array_column($cols, 'name');
        if (!in_array('offseason_phase', $names, true)) {
            $this->db->exec("ALTER TABLE leagues ADD COLUMN offseason_phase VARCHAR(30)");
        }
    }

    // ================================================================
    //  Public API
    // ================================================================

    public function getCurrentPhase(int $leagueId): ?string
    {
        $stmt = $this->db->prepare("SELECT offseason_phase FROM leagues WHERE id = ?");
        $stmt->execute([$leagueId]);
        return $stmt->fetchColumn() ?: null;
    }

    /**
     * Advance to the next offseason phase, execute its logic, return results.
     */
    public function advancePhase(int $leagueId): array
    {
        $current = $this->getCurrentPhase($leagueId);
        $nextPhase = $this->nextPhase($current);

        if ($nextPhase === null) {
            return ['done' => true, 'message' => 'Offseason complete'];
        }

        // Persist the new phase
        $this->db->prepare("UPDATE leagues SET offseason_phase = ?, updated_at = ? WHERE id = ?")
            ->execute([$nextPhase, date('Y-m-d H:i:s'), $leagueId]);

        // Execute the phase
        $results = $this->executePhase($leagueId, $nextPhase);
        $results['phase'] = $nextPhase;
        $results['phases_remaining'] = $this->phasesRemaining($nextPhase);

        return $results;
    }

    /**
     * Get user's team ID for a league (the human player).
     */
    public function getHumanTeamId(int $leagueId): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT team_id FROM coaches WHERE league_id = ? AND is_human = 1 LIMIT 1"
        );
        $stmt->execute([$leagueId]);
        $id = $stmt->fetchColumn();
        return $id ? (int) $id : null;
    }

    // ================================================================
    //  Phase navigation
    // ================================================================

    private function nextPhase(?string $current): ?string
    {
        if ($current === null) {
            return self::PHASES[0]; // awards
        }
        $idx = array_search($current, self::PHASES, true);
        if ($idx === false || $idx >= count(self::PHASES) - 1) {
            return null; // finished
        }
        return self::PHASES[$idx + 1];
    }

    private function phasesRemaining(string $current): int
    {
        $idx = array_search($current, self::PHASES, true);
        return $idx !== false ? count(self::PHASES) - 1 - $idx : 0;
    }

    // ================================================================
    //  Phase dispatcher
    // ================================================================

    private function executePhase(int $leagueId, string $phase): array
    {
        return match ($phase) {
            'awards'         => $this->processAwards($leagueId),
            'franchise_tag'  => $this->processFranchiseTags($leagueId),
            're_sign'        => $this->processReSignWindow($leagueId),
            'combine'        => $this->processCombine($leagueId),
            'free_agency_1'  => $this->processFreeAgencyRound($leagueId, 1),
            'free_agency_2'  => $this->processFreeAgencyRound($leagueId, 2),
            'free_agency_3'  => $this->processFreeAgencyRound($leagueId, 3),
            'free_agency_4'  => $this->processFreeAgencyRound($leagueId, 4),
            'pre_draft'      => $this->processPreDraft($leagueId),
            'draft'          => $this->processDraftAI($leagueId),
            'udfa'           => $this->processUDFAWindow($leagueId),
            'roster_cuts'    => $this->processRosterCuts($leagueId),
            'development'    => $this->processDevelopment($leagueId),
            'hall_of_fame'   => $this->processHallOfFame($leagueId),
            'new_season'     => $this->processNewSeason($leagueId),
            default          => ['message' => "Unknown phase: {$phase}"],
        };
    }

    // ================================================================
    //  PHASE: Awards
    // ================================================================

    private function processAwards(int $leagueId): array
    {
        $league = $this->getLeague($leagueId);
        $seasonYear = (int) ($league['season_year'] ?? 2026);

        $offseason = new OffseasonEngine();
        // calculateAwards is private in OffseasonEngine, so we call processOffseason
        // selectively. But we want to keep existing engine. Use the awards query inline.
        $awards = $this->calculateAwardsInline($leagueId, $seasonYear);

        // Process end-of-season incentive clauses (NLTBE — only count when triggered)
        $contractEngine = new ContractEngine();
        $triggeredIncentives = $contractEngine->processIncentives($leagueId, $seasonYear);

        // Process void-year contract expirations
        $voidedContracts = $contractEngine->processVoidYearExpirations($leagueId);

        // All-League and Gridiron Classic selections
        $allProEngine = new AllProEngine();
        $allLeague = $allProEngine->selectAllPro($leagueId, $seasonYear);
        $gridironClassic = $allProEngine->selectGridironClassic($leagueId, $seasonYear);

        $result = [
            'awards' => $awards,
            'all_league' => $allLeague,
            'gridiron_classic' => $gridironClassic,
        ];
        if (!empty($triggeredIncentives)) {
            $result['incentives_triggered'] = $triggeredIncentives;
        }
        if (!empty($voidedContracts)) {
            $result['voided_contracts'] = $voidedContracts;
        }

        return $result;
    }

    private function calculateAwardsInline(int $leagueId, int $seasonYear): array
    {
        $awards = [];

        // MVP
        $stmt = $this->db->prepare(
            "SELECT p.id, p.first_name, p.last_name, p.position, t.abbreviation,
                    SUM(gs.pass_yards) as total_pass_yards,
                    SUM(gs.rush_yards) as total_rush_yards,
                    SUM(gs.pass_tds) + SUM(gs.rush_tds) as total_tds
             FROM game_stats gs
             JOIN players p ON gs.player_id = p.id
             JOIN teams t ON p.team_id = t.id
             JOIN games g ON gs.game_id = g.id
             WHERE g.league_id = ? AND p.position = 'QB'
             GROUP BY p.id
             ORDER BY (SUM(gs.pass_yards) + SUM(gs.rush_yards) + SUM(gs.pass_tds) * 100 + SUM(gs.rush_tds) * 100) DESC
             LIMIT 1"
        );
        $stmt->execute([$leagueId]);
        $mvp = $stmt->fetch();
        if ($mvp) {
            $this->saveAward($leagueId, $seasonYear, 'MVP', 'player', $mvp['id'],
                json_encode(['pass_yards' => $mvp['total_pass_yards'], 'tds' => $mvp['total_tds']]));
            $awards[] = ['type' => 'MVP', 'winner' => $mvp['first_name'] . ' ' . $mvp['last_name'],
                         'team' => $mvp['abbreviation']];
        }

        // OPOY
        $stmt = $this->db->prepare(
            "SELECT p.id, p.first_name, p.last_name, p.position, t.abbreviation,
                    SUM(gs.pass_yards) + SUM(gs.rush_yards) + SUM(gs.rec_yards) as total_yards,
                    SUM(gs.pass_tds) + SUM(gs.rush_tds) + SUM(gs.rec_tds) as total_tds
             FROM game_stats gs
             JOIN players p ON gs.player_id = p.id
             JOIN teams t ON p.team_id = t.id
             JOIN games g ON gs.game_id = g.id
             WHERE g.league_id = ? AND p.position IN ('QB','RB','WR','TE')
             GROUP BY p.id ORDER BY total_yards DESC LIMIT 1"
        );
        $stmt->execute([$leagueId]);
        $opoy = $stmt->fetch();
        if ($opoy) {
            $this->saveAward($leagueId, $seasonYear, 'Offensive Player of the Year', 'player', $opoy['id'],
                json_encode(['yards' => $opoy['total_yards'], 'tds' => $opoy['total_tds']]));
            $awards[] = ['type' => 'OPOY', 'winner' => $opoy['first_name'] . ' ' . $opoy['last_name'],
                         'team' => $opoy['abbreviation']];
        }

        // DPOY
        $stmt = $this->db->prepare(
            "SELECT p.id, p.first_name, p.last_name, p.position, t.abbreviation,
                    SUM(gs.tackles) as total_tackles, SUM(gs.sacks) as total_sacks,
                    SUM(gs.interceptions_def) as total_ints
             FROM game_stats gs
             JOIN players p ON gs.player_id = p.id
             JOIN teams t ON p.team_id = t.id
             JOIN games g ON gs.game_id = g.id
             WHERE g.league_id = ? AND p.position IN ('DE','DT','LB','CB','S')
             GROUP BY p.id
             ORDER BY (SUM(gs.tackles) + SUM(gs.sacks) * 10 + SUM(gs.interceptions_def) * 15) DESC
             LIMIT 1"
        );
        $stmt->execute([$leagueId]);
        $dpoy = $stmt->fetch();
        if ($dpoy) {
            $this->saveAward($leagueId, $seasonYear, 'Defensive Player of the Year', 'player', $dpoy['id'],
                json_encode(['tackles' => $dpoy['total_tackles'], 'sacks' => $dpoy['total_sacks'], 'ints' => $dpoy['total_ints']]));
            $awards[] = ['type' => 'DPOY', 'winner' => $dpoy['first_name'] . ' ' . $dpoy['last_name'],
                         'team' => $dpoy['abbreviation']];
        }

        // COTY
        $stmt = $this->db->prepare(
            "SELECT c.id, c.name, t.wins, t.losses, t.overall_rating, t.abbreviation
             FROM coaches c JOIN teams t ON c.team_id = t.id
             WHERE c.league_id = ? AND t.overall_rating < 78
             ORDER BY t.wins DESC, (t.points_for - t.points_against) DESC LIMIT 1"
        );
        $stmt->execute([$leagueId]);
        $coty = $stmt->fetch();
        if ($coty) {
            $this->saveAward($leagueId, $seasonYear, 'Coach of the Year', 'coach', $coty['id'],
                json_encode(['wins' => $coty['wins'], 'losses' => $coty['losses']]));
            $awards[] = ['type' => 'COTY', 'winner' => $coty['name'],
                         'team' => $coty['abbreviation']];
        }

        return $awards;
    }

    private function saveAward(int $leagueId, int $year, string $type, string $winnerType, int $winnerId, string $stats): void
    {
        // Avoid duplicates
        $stmt = $this->db->prepare(
            "SELECT id FROM season_awards WHERE league_id = ? AND season_year = ? AND award_type = ?"
        );
        $stmt->execute([$leagueId, $year, $type]);
        if ($stmt->fetch()) return;

        $this->db->prepare(
            "INSERT INTO season_awards (league_id, season_year, award_type, winner_type, winner_id, stats)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([$leagueId, $year, $type, $winnerType, $winnerId, $stats]);
    }

    // ================================================================
    //  PHASE: Franchise Tags
    // ================================================================

    /**
     * AI teams apply franchise tags to their best expiring players.
     * Human team tags are applied via the API before advancing past this phase.
     * Also resets franchise_tags_used for the new offseason year.
     */
    private function processFranchiseTags(int $leagueId): array
    {
        $tagEngine = new FranchiseTagEngine();

        // Reset tag counters for the new offseason
        $tagEngine->resetTagsForNewSeason($leagueId);

        // AI teams apply tags
        $aiTags = $tagEngine->aiApplyTags($leagueId);

        // Get all tagged players for the summary
        $allTagged = $tagEngine->getTaggedPlayers($leagueId);

        return [
            'ai_tags_applied' => count($aiTags),
            'ai_tags'         => $aiTags,
            'all_tagged'      => $allTagged,
            'message'         => count($aiTags) > 0
                ? count($aiTags) . ' AI team(s) applied franchise tags.'
                : 'No AI teams applied franchise tags.',
        ];
    }

    // ================================================================
    //  PHASE: Re-sign Window
    // ================================================================

    public function processReSignWindow(int $leagueId): array
    {
        $humanTeamId = $this->getHumanTeamId($leagueId);
        $reSignings = [];
        $newFreeAgents = [];

        // Find all players with expiring contracts (years_remaining <= 1)
        // years_remaining = 1 means this was their last season
        // Exclude franchise-tagged players — they already have a new 1-year deal
        $stmt = $this->db->prepare(
            "SELECT c.id as contract_id, c.player_id, c.team_id, c.salary_annual, c.cap_hit,
                    c.years_remaining,
                    p.first_name, p.last_name, p.position, p.overall_rating, p.age, p.potential
             FROM contracts c
             JOIN players p ON c.player_id = p.id
             WHERE p.league_id = ? AND c.years_remaining <= 1
               AND (c.franchise_tag_type IS NULL)"
        );
        $stmt->execute([$leagueId]);
        $expiringContracts = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Group by team
        $byTeam = [];
        foreach ($expiringContracts as $ec) {
            $byTeam[(int) $ec['team_id']][] = $ec;
        }

        // Get AI teams
        $stmt = $this->db->prepare(
            "SELECT c.*, t.wins, t.losses, t.overall_rating, t.salary_cap, t.cap_used
             FROM coaches c
             JOIN teams t ON c.team_id = t.id
             WHERE c.league_id = ? AND c.is_human = 0 AND c.team_id IS NOT NULL"
        );
        $stmt->execute([$leagueId]);
        $aiCoaches = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $faEngine = new FreeAgencyEngine();

        foreach ($aiCoaches as $coach) {
            $teamId = (int) $coach['team_id'];
            $expiring = $byTeam[$teamId] ?? [];
            if (empty($expiring)) continue;

            // Build TeamBrain for this team
            $brain = $this->buildTeamBrain($leagueId, $teamId, $coach);
            $gmTraits = $brain->getGmTraits();
            $needs = $brain->getNeeds();
            $mode = $brain->getMode();

            foreach ($expiring as $ec) {
                $ovr = (int) $ec['overall_rating'];
                $age = (int) $ec['age'];
                $pos = $ec['position'];
                $playerName = $ec['first_name'] . ' ' . $ec['last_name'];

                // Use TeamBrain's assessTradeCandidate to check if untouchable
                $baseValue = $this->calculateMarketValue($pos, $ovr, $age);
                $assessment = $brain->assessTradeCandidate([
                    'id' => $ec['player_id'],
                    'first_name' => $ec['first_name'],
                    'last_name' => $ec['last_name'],
                    'position' => $pos,
                    'overall_rating' => $ovr,
                    'age' => $age,
                ], (float) $baseValue);

                $shouldReSign = false;
                $salaryOffer = $baseValue;

                if (!$assessment['available']) {
                    // Untouchable -- auto re-sign at market + premium
                    $shouldReSign = true;
                    $salaryOffer = (int) ($baseValue * 1.1);
                } else {
                    // Decision based on GM personality
                    $personalityKey = $coach['gm_personality'] ?? 'balanced';
                    $overpayTolerance = $gmTraits['overpay_tolerance'] ?? 0.05;

                    // Factor in position need
                    $posNeed = $needs[$pos] ?? 0.5;

                    // Should we re-sign?
                    if ($mode === 'rebuilding' && $age >= 30 && $ovr < 82) {
                        $shouldReSign = false; // Rebuilding: let aging vets walk
                    } elseif ($mode === 'contender' && $ovr >= 75) {
                        $shouldReSign = true; // Contenders keep talent
                        $salaryOffer = (int) ($baseValue * (1.0 + $overpayTolerance));
                    } elseif ($posNeed >= 0.5 && $ovr >= 70) {
                        $shouldReSign = true; // Need the position
                        $salaryOffer = (int) ($baseValue * (1.0 + $overpayTolerance * 0.5));
                    } elseif ($ovr >= 80) {
                        $shouldReSign = true; // Good player worth keeping
                        $salaryOffer = (int) ($baseValue * (1.0 + $overpayTolerance));
                    } else {
                        // Role player -- personality-dependent
                        $shouldReSign = match ($personalityKey) {
                            'aggressive'   => $ovr >= 72,
                            'conservative' => $ovr >= 76 && $age < 30,
                            'analytics'    => $ovr >= 70 && $age < 28,
                            'old_school'   => $ovr >= 68 && $age >= 26,
                            default        => $ovr >= 73,
                        };
                        $salaryOffer = (int) ($baseValue * (1.0 + $overpayTolerance * 0.3));
                    }
                }

                if ($shouldReSign) {
                    // Create new contract
                    $years = $this->determineContractYears($age, $ovr, $mode);
                    $capHit = (int) ($salaryOffer * 1.05); // cap hit slightly above salary
                    $now = date('Y-m-d H:i:s');

                    // Expire old contract
                    $this->db->prepare("DELETE FROM contracts WHERE id = ?")->execute([$ec['contract_id']]);

                    // Insert new contract
                    $this->db->prepare(
                        "INSERT INTO contracts (player_id, team_id, years_total, years_remaining, salary_annual, cap_hit, guaranteed, dead_cap, signed_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                    )->execute([
                        $ec['player_id'], $teamId, $years, $years,
                        $salaryOffer, $capHit,
                        (int) ($salaryOffer * $years * 0.5), // 50% guaranteed
                        (int) ($salaryOffer * 0.3), // dead cap
                        $now,
                    ]);

                    // Update team cap
                    $this->db->prepare("UPDATE teams SET cap_used = cap_used + ? WHERE id = ?")
                        ->execute([$capHit, $teamId]);

                    $reSignings[] = [
                        'player_id' => $ec['player_id'],
                        'name' => $playerName,
                        'position' => $pos,
                        'overall' => $ovr,
                        'team_id' => $teamId,
                        'salary' => $salaryOffer,
                        'years' => $years,
                    ];
                } else {
                    // Release to free agency
                    $this->db->prepare("DELETE FROM contracts WHERE id = ?")->execute([$ec['contract_id']]);

                    // Check if player qualifies as a restricted free agent (years_pro <= 3)
                    $stmtP = $this->db->prepare("SELECT years_pro FROM players WHERE id = ?");
                    $stmtP->execute([$ec['player_id']]);
                    $yearsPro = (int) ($stmtP->fetchColumn() ?: 0);

                    if ($yearsPro > 0 && $yearsPro <= 3) {
                        // Restricted free agent -- original team retains rights
                        $faEngine->releaseAsRestricted($leagueId, $ec['player_id'], $teamId);
                        $newFreeAgents[] = [
                            'player_id' => $ec['player_id'],
                            'name' => $playerName,
                            'position' => $pos,
                            'overall' => $ovr,
                            'age' => $age,
                            'is_restricted' => true,
                        ];
                    } else {
                        $faEngine->releasePlayer($leagueId, $ec['player_id']);
                        $newFreeAgents[] = [
                            'player_id' => $ec['player_id'],
                            'name' => $playerName,
                            'position' => $pos,
                            'overall' => $ovr,
                            'age' => $age,
                            'is_restricted' => false,
                        ];
                    }
                }
            }
        }

        // Also expire contracts for human team players -- but do NOT auto-decide.
        // Just tick down the years. The human decides via the UI.
        // (Human's expiring players stay on the roster until they act.)

        return [
            're_signings' => $reSignings,
            'new_free_agents' => $newFreeAgents,
            'ai_re_signed' => count($reSignings),
            'ai_released' => count($newFreeAgents),
        ];
    }

    // ================================================================
    //  PHASE: Combine
    // ================================================================

    public function processCombine(int $leagueId): array
    {
        $league = $this->getLeague($leagueId);
        $seasonYear = (int) ($league['season_year'] ?? 2026);

        // Find the draft class for the upcoming season
        $stmt = $this->db->prepare(
            "SELECT id FROM draft_classes WHERE league_id = ? ORDER BY year DESC LIMIT 1"
        );
        $stmt->execute([$leagueId]);
        $classRow = $stmt->fetch();
        if (!$classRow) {
            return ['message' => 'No draft class found', 'prospects_updated' => 0];
        }
        $classId = (int) $classRow['id'];

        // Unlock combine grades for all prospects that don't have one
        $stmt = $this->db->prepare(
            "SELECT id, actual_overall, position, potential FROM draft_prospects
             WHERE draft_class_id = ? AND is_drafted = 0"
        );
        $stmt->execute([$classId]);
        $prospects = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $updated = 0;
        $combineResults = [];

        foreach ($prospects as $p) {
            $ovr = (int) $p['actual_overall'];
            $potential = $p['potential'] ?? 'normal';

            // Generate combine grade (A+ to D)
            $grades = ['A+', 'A', 'A-', 'B+', 'B', 'B-', 'C+', 'C', 'C-', 'D'];
            $baseIndex = max(0, min(9, 9 - (int) (($ovr - 55) / 5)));
            // Randomize +/- 2 slots
            $finalIndex = max(0, min(9, $baseIndex + mt_rand(-2, 2)));
            $grade = $grades[$finalIndex];

            // Combine score: 1-100
            $combineScore = max(20, min(100, $ovr + mt_rand(-12, 12)));

            // Small stock adjustment from combine performance
            $stockDelta = 0;
            if ($finalIndex <= 2) $stockDelta = mt_rand(3, 8);      // Great combine
            elseif ($finalIndex <= 5) $stockDelta = mt_rand(-2, 3);  // Average
            else $stockDelta = mt_rand(-8, -2);                       // Poor

            $newStock = max(10, min(100, ($p['stock_rating'] ?? 50) + $stockDelta));
            $newTrend = $stockDelta > 2 ? 'rising' : ($stockDelta < -2 ? 'falling' : 'steady');

            $this->db->prepare(
                "UPDATE draft_prospects SET combine_grade = ?, combine_score = ?,
                        stock_rating = ?, stock_trend = ?
                 WHERE id = ?"
            )->execute([$grade, $combineScore, $newStock, $newTrend, $p['id']]);

            $combineResults[] = [
                'prospect_id' => $p['id'],
                'grade' => $grade,
                'score' => $combineScore,
                'stock_change' => $stockDelta,
            ];
            $updated++;
        }

        // Run CollegeSeasonEngine final stock adjustment if available
        try {
            $collegeEngine = new CollegeSeasonEngine();
            // Simulate a final "combine week" to adjust stock
            $collegeEngine->advanceWeek($leagueId, 99); // week 99 = combine special
        } catch (\Throwable $e) {
            // Non-critical -- combine grades already set
            error_log("CollegeSeasonEngine combine error: " . $e->getMessage());
        }

        return [
            'prospects_updated' => $updated,
            'top_performers' => array_slice(
                array_filter($combineResults, fn($r) => in_array($r['grade'], ['A+', 'A', 'A-'])),
                0, 10
            ),
            'risers' => array_slice(
                array_filter($combineResults, fn($r) => $r['stock_change'] >= 5),
                0, 5
            ),
            'fallers' => array_slice(
                array_filter($combineResults, fn($r) => $r['stock_change'] <= -5),
                0, 5
            ),
        ];
    }

    // ================================================================
    //  PHASE: Free Agency Round
    // ================================================================

    public function processFreeAgencyRound(int $leagueId, int $round): array
    {
        $humanTeamId = $this->getHumanTeamId($leagueId);
        $threshold = self::FA_ROUND_THRESHOLDS[$round] ?? 0;

        // --- Restricted Free Agency processing ---
        $faEngine = new FreeAgencyEngine();
        $rfaResults = [];

        if ($round === 1) {
            // Round 1: AI teams set tenders on their restricted free agents
            $rfaResults = $faEngine->aiHandleRFAs($leagueId);
        } elseif ($round >= 3) {
            // Round 3+: Resolve remaining RFA situations
            // AI teams match/decline any pending offer sheets
            $rfaResults = $faEngine->aiHandleRFAs($leagueId);

            // Auto-sign un-offered tendered RFAs (they sign the tender)
            $stmt = $this->db->prepare(
                "SELECT fa.id FROM free_agents fa
                 WHERE fa.league_id = ? AND fa.is_restricted = 1 AND fa.status = 'available'
                   AND fa.tender_level IS NOT NULL"
            );
            $stmt->execute([$leagueId]);
            $tenderedRFAs = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            $tenderSignings = [];
            foreach ($tenderedRFAs as $faId) {
                $result = $faEngine->autoSignTender((int) $faId);
                if ($result) {
                    $tenderSignings[] = $result;
                }
            }
            $rfaResults['tender_signings'] = $tenderSignings;
        }

        // Get available free agents above the OVR threshold for this round
        $stmt = $this->db->prepare(
            "SELECT fa.id as fa_id, fa.player_id, fa.market_value,
                    p.first_name, p.last_name, p.position, p.overall_rating, p.age, p.potential
             FROM free_agents fa
             JOIN players p ON fa.player_id = p.id
             WHERE fa.league_id = ? AND fa.status = 'available' AND p.overall_rating >= ?
             ORDER BY p.overall_rating DESC"
        );
        $stmt->execute([$leagueId, $threshold]);
        $availableFAs = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($availableFAs)) {
            return ['round' => $round, 'signings' => [], 'message' => 'No free agents available for this round'];
        }

        // Get AI coaches
        $stmt = $this->db->prepare(
            "SELECT c.*, t.wins, t.losses, t.overall_rating, t.salary_cap, t.cap_used,
                    t.id as team_id_real
             FROM coaches c
             JOIN teams t ON c.team_id = t.id
             WHERE c.league_id = ? AND c.is_human = 0 AND c.team_id IS NOT NULL"
        );
        $stmt->execute([$leagueId]);
        $aiCoaches = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Phase 1: AI teams place bids
        $allBids = []; // fa_id => [bids]

        foreach ($aiCoaches as $coach) {
            $teamId = (int) $coach['team_id'];
            $brain = $this->buildTeamBrain($leagueId, $teamId, $coach);
            $gmTraits = $brain->getGmTraits();
            $needs = $brain->getNeeds();
            $mode = $brain->getMode();
            $personality = $coach['gm_personality'] ?? 'balanced';

            // How many FAs to target based on personality
            $maxTargets = match ($personality) {
                'aggressive'   => mt_rand(3, 4),
                'conservative' => mt_rand(1, 2),
                'analytics'    => mt_rand(2, 3),
                'old_school'   => mt_rand(2, 3),
                default        => mt_rand(2, 3),
            };

            // Preferred round for action
            $preferredRounds = match ($personality) {
                'aggressive'   => [1, 2],
                'conservative' => [2, 3, 4],
                'analytics'    => [2, 3],
                'old_school'   => [1, 2, 3],
                default        => [1, 2, 3],
            };

            // Less active in non-preferred rounds
            if (!in_array($round, $preferredRounds)) {
                $maxTargets = max(1, $maxTargets - 1);
            }

            // Get top needs (positions with need >= 0.3)
            $topNeeds = [];
            foreach ($needs as $pos => $score) {
                if ($score >= 0.3) {
                    $topNeeds[$pos] = $score;
                }
            }
            arsort($topNeeds);
            $topNeedPositions = array_keys(array_slice($topNeeds, 0, 4, true));

            // Cap space check
            $capSpace = (int) (($coach['salary_cap'] ?? 225000000) - ($coach['cap_used'] ?? 0));
            $targetsMade = 0;

            foreach ($availableFAs as $fa) {
                if ($targetsMade >= $maxTargets) break;

                $pos = $fa['position'];
                $ovr = (int) $fa['overall_rating'];
                $age = (int) $fa['age'];
                $marketValue = (int) $fa['market_value'];

                // Skip if can't afford
                if ($marketValue * 0.6 > $capSpace) continue;

                // Personality-specific targeting
                $interested = false;
                $bidMultiplier = 1.0;

                if (in_array($pos, $topNeedPositions)) {
                    $interested = true;
                    $bidMultiplier = 1.0 + ($gmTraits['overpay_tolerance'] ?? 0.05);
                }

                // Additional personality-specific logic
                if (!$interested) {
                    $interested = match ($personality) {
                        'aggressive'   => $ovr >= 80, // Aggressive goes after stars
                        'analytics'    => $age <= 26 && ($fa['potential'] ?? 'normal') !== 'limited',
                        'old_school'   => $age >= 27 && $ovr >= 75,
                        'conservative' => false, // Only targets needs
                        default        => $ovr >= 78 && in_array($pos, $topNeedPositions),
                    };
                }

                if (!$interested) continue;

                // Calculate bid
                $overpay = $gmTraits['overpay_tolerance'] ?? 0.05;
                $bid = (int) ($marketValue * $bidMultiplier * (1.0 + $overpay));
                $bid = max((int) ($marketValue * 0.7), $bid); // Floor at 70% market
                $years = $this->determineFAContractYears($age, $ovr, $personality);

                // Place bid
                $faId = (int) $fa['fa_id'];
                if (!isset($allBids[$faId])) $allBids[$faId] = [];
                $allBids[$faId][] = [
                    'team_id' => $teamId,
                    'coach_id' => (int) $coach['id'],
                    'salary' => $bid,
                    'years' => $years,
                    'team_wins' => (int) ($coach['wins'] ?? 0),
                    'team_rating' => (int) ($coach['overall_rating'] ?? 75),
                    'mode' => $mode,
                    'fa' => $fa,
                ];

                // Store in DB too
                $this->db->prepare(
                    "INSERT INTO fa_bids (free_agent_id, team_id, coach_id, salary_offer, years_offer, is_winning, created_at)
                     VALUES (?, ?, ?, ?, ?, 0, ?)"
                )->execute([$faId, $teamId, (int) $coach['id'], $bid, $years, date('Y-m-d H:i:s')]);

                $capSpace -= $bid;
                $targetsMade++;
            }
        }

        // Phase 2: Resolve bids using PlayerDecisionEngine
        // Players evaluate all offers through their own lens — personality, winning,
        // playing time, loyalty, and market. No more coin flips.
        $signings = [];
        $playerBrain = new PlayerDecisionEngine();

        foreach ($allBids as $faId => $bids) {
            if (empty($bids)) continue;

            $playerId = (int) $bids[0]['fa']['player_id'];

            // Build offers array for the player brain to rank
            $offers = array_map(fn($b) => [
                'team_id' => (int) $b['team_id'],
                'salary'  => (int) $b['salary'],
                'years'   => (int) $b['years'],
            ], $bids);

            // Player ranks all offers by personal preference
            $rankedOffers = $playerBrain->rankFreeAgencyOffers($playerId, $offers);

            // Player picks the best offer they're at least willing to accept
            $winner = null;
            $winnerReasoning = '';
            foreach ($rankedOffers as $ranked) {
                if (in_array($ranked['willingness'], ['eager', 'willing', 'reluctant'])) {
                    // Find the matching bid
                    foreach ($bids as $bid) {
                        if ((int) $bid['team_id'] === $ranked['team_id']) {
                            $winner = $bid;
                            $winnerReasoning = $ranked['reasoning'];
                            break 2;
                        }
                    }
                }
            }

            // Fallback: if player refuses all offers, pick the highest-scored one anyway
            // (in real FA, players eventually sign somewhere)
            if (!$winner && !empty($rankedOffers)) {
                $topRanked = $rankedOffers[0];
                foreach ($bids as $bid) {
                    if ((int) $bid['team_id'] === $topRanked['team_id']) {
                        $winner = $bid;
                        $winnerReasoning = $topRanked['reasoning'];
                        break;
                    }
                }
            }

            if (!$winner) $winner = $bids[0];

            // Sign the player
            $this->signFreeAgent(
                $leagueId,
                $faId,
                (int) $winner['fa']['player_id'],
                (int) $winner['team_id'],
                (int) $winner['salary'],
                (int) $winner['years']
            );

            // Mark winning bid
            $this->db->prepare(
                "UPDATE fa_bids SET is_winning = 1
                 WHERE free_agent_id = ? AND team_id = ? ORDER BY id DESC LIMIT 1"
            )->execute([$faId, $winner['team_id']]);

            $signings[] = [
                'player_id' => (int) $winner['fa']['player_id'],
                'name' => $winner['fa']['first_name'] . ' ' . $winner['fa']['last_name'],
                'position' => $winner['fa']['position'],
                'overall' => (int) $winner['fa']['overall_rating'],
                'team_id' => (int) $winner['team_id'],
                'salary' => (int) $winner['salary'],
                'years' => (int) $winner['years'],
                'num_bidders' => count($bids),
                'reasoning' => $winnerReasoning,
            ];
        }

        return [
            'round' => $round,
            'threshold' => $threshold,
            'available_count' => count($availableFAs),
            'bids_placed' => array_sum(array_map('count', $allBids)),
            'signings' => $signings,
            'signed_count' => count($signings),
            'rfa' => $rfaResults,
        ];
    }

    // ================================================================
    //  PHASE: Draft (AI only)
    // ================================================================

    public function processDraftAI(int $leagueId): array
    {
        $humanTeamId = $this->getHumanTeamId($leagueId);
        $draftEngine = new DraftEngine();
        $aiPicks = [];
        $humanPicksPending = [];

        // Get all unused draft picks in order
        $stmt = $this->db->prepare(
            "SELECT dp.* FROM draft_picks dp
             JOIN draft_classes dc ON dp.draft_class_id = dc.id
             WHERE dp.league_id = ? AND dp.is_used = 0
             ORDER BY dp.round ASC, dp.pick_number ASC"
        );
        $stmt->execute([$leagueId]);
        $picks = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Get AI coaches for TeamBrain building
        $aiCoachMap = [];
        $stmt = $this->db->prepare(
            "SELECT c.*, t.wins, t.losses, t.overall_rating
             FROM coaches c JOIN teams t ON c.team_id = t.id
             WHERE c.league_id = ? AND c.is_human = 0"
        );
        $stmt->execute([$leagueId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $c) {
            $aiCoachMap[(int) $c['team_id']] = $c;
        }

        foreach ($picks as $pick) {
            $teamId = (int) $pick['current_team_id'];

            if ($teamId === $humanTeamId) {
                // Skip human picks -- they pick manually
                $humanPicksPending[] = [
                    'pick_id' => (int) $pick['id'],
                    'round' => (int) $pick['round'],
                    'pick_number' => (int) $pick['pick_number'],
                ];
                continue;
            }

            $coach = $aiCoachMap[$teamId] ?? null;
            if (!$coach) {
                // Fallback: use basic AI pick
                $result = $draftEngine->aiMakePick((int) $pick['id']);
                if (!empty($result['success'])) {
                    $aiPicks[] = $result;
                }
                continue;
            }

            // Build brain and evaluate prospects
            $brain = $this->buildTeamBrain($leagueId, $teamId, $coach);
            $needs = $brain->getNeeds();
            $gmTraits = $brain->getGmTraits();
            $mode = $brain->getMode();
            $personality = $coach['gm_personality'] ?? 'balanced';

            // Get available prospects
            $stmt2 = $this->db->prepare(
                "SELECT * FROM draft_prospects WHERE draft_class_id = ? AND is_drafted = 0
                 ORDER BY actual_overall DESC"
            );
            $stmt2->execute([$pick['draft_class_id']]);
            $available = $stmt2->fetchAll(\PDO::FETCH_ASSOC);

            if (empty($available)) continue;

            // Score each prospect
            $bestProspect = null;
            $bestScore = -999;

            $topNeeds = [];
            foreach ($needs as $pos => $score) {
                $topNeeds[$pos] = $score;
            }
            arsort($topNeeds);
            $needPositions = array_keys(array_slice($topNeeds, 0, 5, true));

            foreach (array_slice($available, 0, 20) as $prospect) {
                $ovr = (int) $prospect['actual_overall'];
                $pos = $prospect['position'];
                $potential = $prospect['potential'] ?? 'normal';
                $age = (int) ($prospect['age'] ?? 21);

                // Base score: raw talent
                $score = $ovr;

                // Position need weight (0-1 scale, multiply for impact)
                $posNeed = $needs[$pos] ?? 0.3;
                $score += $posNeed * 20;

                // Personality weights
                $score += match ($personality) {
                    'aggressive' => in_array($pos, ['QB', 'DE', 'CB', 'WR']) ? 5 : 0,
                    'conservative' => 0, // Pure BPA
                    'analytics' => match ($potential) {
                        'elite' => 10, 'high' => 5, default => 0,
                    } + ($age <= 21 ? 3 : 0),
                    'old_school' => ($prospect['combine_grade'] ?? 'C')[0] === 'A' ? 5 :
                        (($prospect['combine_grade'] ?? 'C')[0] === 'B' ? 2 : 0),
                    default => $posNeed >= 0.5 ? 5 : 0,
                };

                // Mode weight
                if ($mode === 'rebuilding') {
                    // Rebuilders value potential over polish
                    $score += match ($potential) {
                        'elite' => 8, 'high' => 4, default => 0,
                    };
                } elseif ($mode === 'contender') {
                    // Contenders want immediately ready players
                    $score += $ovr >= 75 ? 5 : 0;
                }

                // Add small randomness
                $score += mt_rand(-3, 3);

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestProspect = $prospect;
                }
            }

            if (!$bestProspect && !empty($available)) {
                $bestProspect = $available[0];
            }

            if ($bestProspect) {
                $result = $draftEngine->makePick((int) $pick['id'], (int) $bestProspect['id']);
                if (!empty($result['success'])) {
                    $result['team_id'] = $teamId;
                    $result['personality'] = $personality;
                    $aiPicks[] = $result;
                }
            }
        }

        // ── UDFA: Convert undrafted prospects to free agent players ──
        $udfaCount = $this->processUndraftedFreeAgents($leagueId);

        return [
            'ai_picks' => $aiPicks,
            'ai_picks_count' => count($aiPicks),
            'human_picks_pending' => $humanPicksPending,
            'undrafted_free_agents' => $udfaCount,
        ];
    }

    /**
     * Convert remaining undrafted prospects into real players as UDFAs.
     * They become free agents with minimum-salary contracts available for any team to sign.
     */
    private function processUndraftedFreeAgents(int $leagueId): int
    {
        $draftEngine = new DraftEngine();
        $classId = $draftEngine->getCurrentClassId($leagueId);
        if (!$classId) return 0;

        // Get all undrafted prospects
        $stmt = $this->db->prepare(
            "SELECT * FROM draft_prospects WHERE draft_class_id = ? AND is_drafted = 0"
        );
        $stmt->execute([$classId]);
        $undrafted = $stmt->fetchAll();

        if (empty($undrafted)) return 0;

        $now = date('Y-m-d H:i:s');
        $created = 0;

        foreach ($undrafted as $prospect) {
            $ovr = (int) $prospect['actual_overall'];
            $potential = $prospect['potential'] ?? 'average';
            $positionalRatings = json_decode($prospect['positional_ratings'] ?? '{}', true) ?: [];

            // Create the player (no team — free agent)
            $this->db->prepare(
                "INSERT INTO players (league_id, team_id, first_name, last_name, position, age,
                 overall_rating, potential, jersey_number, college, status, is_rookie, experience,
                 personality, morale, positional_ratings, created_at)
                 VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, 'free_agent', 1, 0, ?, 'content', ?, ?)"
            )->execute([
                $leagueId,
                $prospect['first_name'], $prospect['last_name'], $prospect['position'],
                (int) $prospect['age'], $ovr, $potential,
                mt_rand(1, 99), $prospect['college'],
                ['team_player', 'competitor', 'quiet_professional', 'vocal_leader'][mt_rand(0, 3)],
                json_encode($positionalRatings), $now,
            ]);
            $playerId = (int) $this->db->lastInsertId();

            // Create minimum UDFA contract
            $minSalary = 885000;
            $this->db->prepare(
                "INSERT INTO contracts (player_id, team_id, years_total, years_remaining, salary_annual,
                 cap_hit, guaranteed, dead_cap, signing_bonus, base_salary, contract_type, total_value, status, signed_at)
                 VALUES (?, NULL, 3, 3, ?, ?, 0, 0, 0, ?, 'rookie', ?, 'active', ?)"
            )->execute([$playerId, $minSalary, $minSalary, $minSalary, $minSalary * 3, $now]);

            // Add to free agents pool
            $this->db->prepare(
                "INSERT INTO free_agents (league_id, player_id, asking_salary, market_value, status, released_at)
                 VALUES (?, ?, ?, ?, 'available', ?)"
            )->execute([$leagueId, $playerId, $minSalary, $minSalary, $now]);

            // Mark prospect as drafted (technically "processed")
            $this->db->prepare("UPDATE draft_prospects SET is_drafted = 1 WHERE id = ?")
                ->execute([(int) $prospect['id']]);

            $created++;
        }

        return $created;
    }

    // ================================================================
    //  PHASE: Roster Cuts
    // ================================================================

    public function processRosterCuts(int $leagueId): array
    {
        $faEngine = new FreeAgencyEngine();
        $cuts = [];

        $stmt = $this->db->prepare("SELECT id FROM teams WHERE league_id = ?");
        $stmt->execute([$leagueId]);
        $teamIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($teamIds as $teamId) {
            $stmt = $this->db->prepare(
                "SELECT id, first_name, last_name, position, overall_rating
                 FROM players WHERE team_id = ? AND status = 'active'
                 ORDER BY overall_rating ASC"
            );
            $stmt->execute([$teamId]);
            $roster = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $count = count($roster);
            if ($count <= 53) continue;

            // Find positions with the deepest depth
            $byPos = [];
            foreach ($roster as $p) {
                $byPos[$p['position']][] = $p;
            }

            $toCut = $count - 53;
            $cutFromTeam = [];

            // Cut lowest OVR players from deepest positions first
            while ($toCut > 0 && !empty($roster)) {
                // Find deepest position group
                $deepest = null;
                $deepestCount = 0;
                foreach ($byPos as $pos => $players) {
                    $ideal = match ($pos) {
                        'QB' => 2, 'RB' => 3, 'WR' => 5, 'TE' => 3,
                        'OT' => 4, 'OG' => 4, 'C' => 2,
                        'DE' => 4, 'DT' => 4, 'LB' => 4, 'CB' => 5, 'S' => 4,
                        'K' => 1, 'P' => 1, default => 2,
                    };
                    $excess = count($players) - $ideal;
                    if ($excess > $deepestCount) {
                        $deepestCount = $excess;
                        $deepest = $pos;
                    }
                }

                if ($deepest === null) {
                    // No excess at any position -- cut the overall lowest rated
                    $deepest = array_key_first($byPos);
                }

                // Cut the worst player in the deepest group
                $group = &$byPos[$deepest];
                usort($group, fn($a, $b) => (int) $a['overall_rating'] <=> (int) $b['overall_rating']);
                $cut = array_shift($group);
                if (empty($group)) unset($byPos[$deepest]);

                $faEngine->releasePlayer($leagueId, (int) $cut['id']);
                $cutFromTeam[] = [
                    'player_id' => (int) $cut['id'],
                    'name' => $cut['first_name'] . ' ' . $cut['last_name'],
                    'position' => $cut['position'],
                    'overall' => (int) $cut['overall_rating'],
                ];
                $toCut--;
            }

            if (!empty($cutFromTeam)) {
                $cuts[] = [
                    'team_id' => (int) $teamId,
                    'players_cut' => $cutFromTeam,
                ];
            }
        }

        // ── AI teams sign UDFAs to fill roster holes ──
        $udfaSignings = $this->aiSignUDFAs($leagueId);

        return [
            'teams_affected' => count($cuts),
            'total_cuts' => array_sum(array_map(fn($t) => count($t['players_cut']), $cuts)),
            'cuts' => $cuts,
            'udfa_signings' => $udfaSignings,
        ];
    }

    /**
     * AI teams sign available UDFAs/free agents to fill roster spots.
     */
    private function aiSignUDFAs(int $leagueId): int
    {
        $humanTeamId = $this->getHumanTeamId($leagueId);
        $signed = 0;
        $now = date('Y-m-d H:i:s');

        $teamStmt = $this->db->prepare("SELECT id FROM teams WHERE league_id = ?");
        $teamStmt->execute([$leagueId]);
        $teamIds = $teamStmt->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($teamIds as $teamId) {
            if ((int) $teamId === $humanTeamId) continue; // Don't sign for the human

            // How many roster spots open?
            $rosterCount = (int) $this->db->query(
                "SELECT COUNT(*) FROM players WHERE team_id = {$teamId} AND status = 'active'"
            )->fetchColumn();

            $spotsOpen = 53 - $rosterCount;
            if ($spotsOpen <= 0) continue;

            // Find positions of need (positions with fewer players than ideal)
            $posCount = [];
            $stmt = $this->db->prepare("SELECT position, COUNT(*) as cnt FROM players WHERE team_id = ? AND status = 'active' GROUP BY position");
            $stmt->execute([$teamId]);
            while ($row = $stmt->fetch()) {
                $posCount[$row['position']] = (int) $row['cnt'];
            }

            $idealDepth = ['QB' => 2, 'RB' => 3, 'WR' => 5, 'TE' => 3, 'OT' => 4, 'OG' => 4, 'C' => 2,
                           'DE' => 4, 'DT' => 4, 'LB' => 4, 'CB' => 5, 'S' => 4, 'K' => 1, 'P' => 1];

            $needs = [];
            foreach ($idealDepth as $pos => $ideal) {
                $have = $posCount[$pos] ?? 0;
                if ($have < $ideal) {
                    $needs[$pos] = $ideal - $have;
                }
            }

            // Sign UDFAs at positions of need
            foreach ($needs as $pos => $count) {
                if ($spotsOpen <= 0) break;

                $faStmt = $this->db->prepare(
                    "SELECT fa.id as fa_id, fa.player_id, p.overall_rating
                     FROM free_agents fa JOIN players p ON fa.player_id = p.id
                     WHERE fa.league_id = ? AND p.position = ? AND fa.status = 'available' AND p.team_id IS NULL
                     ORDER BY p.overall_rating DESC LIMIT ?"
                );
                $faStmt->execute([$leagueId, $pos, min($count, $spotsOpen)]);
                $available = $faStmt->fetchAll();

                foreach ($available as $fa) {
                    // Sign to team
                    $this->db->prepare("UPDATE players SET team_id = ?, status = 'active' WHERE id = ?")
                        ->execute([$teamId, $fa['player_id']]);
                    $this->db->prepare("UPDATE free_agents SET status = 'signed' WHERE id = ?")
                        ->execute([$fa['fa_id']]);
                    // Update contract team
                    $this->db->prepare("UPDATE contracts SET team_id = ? WHERE player_id = ? AND status = 'active'")
                        ->execute([$teamId, $fa['player_id']]);

                    $signed++;
                    $spotsOpen--;
                }
            }
        }

        return $signed;
    }

    /**
     * Mid-season cleanup: remove unsigned free agents who didn't make it.
     * Called during weekly simulation after Week 6.
     */
    public function cleanupUnsignedFreeAgents(int $leagueId, int $currentWeek): array
    {
        $removed = 0;
        $kept = 0;

        if ($currentWeek < 6) {
            return ['removed' => 0, 'kept' => 0, 'message' => 'Too early in the season for cleanup.'];
        }

        // Remove low-OVR unsigned free agents (below 62 OVR)
        $stmt = $this->db->prepare(
            "SELECT fa.id as fa_id, fa.player_id, p.first_name, p.last_name, p.overall_rating
             FROM free_agents fa JOIN players p ON fa.player_id = p.id
             WHERE fa.league_id = ? AND fa.status = 'available' AND p.team_id IS NULL"
        );
        $stmt->execute([$leagueId]);
        $unsigned = $stmt->fetchAll();

        foreach ($unsigned as $fa) {
            $ovr = (int) $fa['overall_rating'];

            if ($ovr < 62) {
                // Remove — they didn't make it
                $this->db->prepare("UPDATE free_agents SET status = 'removed' WHERE id = ?")->execute([$fa['fa_id']]);
                $this->db->prepare("UPDATE players SET status = 'out_of_league' WHERE id = ?")->execute([$fa['player_id']]);
                $this->db->prepare("UPDATE contracts SET status = 'terminated' WHERE player_id = ? AND status = 'active'")->execute([$fa['player_id']]);
                $removed++;
            } else {
                // Keep — viable street free agent
                $kept++;
            }
        }

        return [
            'removed' => $removed,
            'kept' => $kept,
            'message' => $removed > 0
                ? "{$removed} unsigned free agents have been removed from the league. {$kept} remain available."
                : "All unsigned free agents are still viable.",
        ];
    }

    /**
     * Offseason cleanup: clear out stale free agents between seasons.
     * Players under 60 OVR who've been unsigned for a full season get removed.
     */
    public function offseasonFreeAgentCleanup(int $leagueId): int
    {
        $removed = 0;

        $stmt = $this->db->prepare(
            "SELECT fa.id as fa_id, fa.player_id, p.overall_rating, p.age
             FROM free_agents fa JOIN players p ON fa.player_id = p.id
             WHERE fa.league_id = ? AND fa.status = 'available' AND p.team_id IS NULL"
        );
        $stmt->execute([$leagueId]);
        $unsigned = $stmt->fetchAll();

        foreach ($unsigned as $fa) {
            $ovr = (int) $fa['overall_rating'];
            $age = (int) $fa['age'];

            // Remove if: under 60 OVR, OR over 35, OR under 65 and over 30
            $shouldRemove = ($ovr < 60) || ($age >= 35) || ($ovr < 65 && $age >= 30);

            if ($shouldRemove) {
                $this->db->prepare("UPDATE free_agents SET status = 'removed' WHERE id = ?")->execute([$fa['fa_id']]);
                $this->db->prepare("UPDATE players SET status = 'out_of_league' WHERE id = ?")->execute([$fa['player_id']]);
                $this->db->prepare("UPDATE contracts SET status = 'terminated' WHERE player_id = ? AND status = 'active'")->execute([$fa['player_id']]);
                $removed++;
            }
        }

        return $removed;
    }

    // ================================================================
    //  PHASE: Development
    // ================================================================

    private function processDevelopment(int $leagueId): array
    {
        $offseason = new OffseasonEngine();
        // processPlayerDevelopment is private, so replicate the logic here
        $changes = $this->processPlayerDevelopmentInline($leagueId);
        $improved = array_filter($changes, fn($c) => $c['type'] === 'improved');
        $declined = array_filter($changes, fn($c) => $c['type'] === 'declined');
        $retired  = array_filter($changes, fn($c) => $c['type'] === 'retired');

        return [
            'improved' => array_values($improved),
            'declined' => array_values($declined),
            'retired'  => array_values($retired),
            'total_improved' => count($improved),
            'total_declined' => count($declined),
            'total_retired'  => count($retired),
        ];
    }

    // ================================================================
    //  Position-specific career expectancy & starter thresholds
    // ================================================================

    /** Typical retirement age ceiling by position */
    private const POSITION_CAREER_END = [
        'QB' => 40, 'RB' => 32, 'FB' => 33, 'WR' => 35, 'TE' => 35,
        'OT' => 36, 'OG' => 36, 'C'  => 36,
        'DE' => 34, 'DT' => 34,
        'LB' => 34,
        'CB' => 33, 'S'  => 34,
        'K'  => 42, 'P'  => 42, 'LS' => 42,
    ];

    /** OVR threshold that counts as starter-level for a position */
    private const POSITION_STARTER_OVR = [
        'QB' => 75, 'RB' => 72, 'FB' => 65, 'WR' => 73, 'TE' => 72,
        'OT' => 72, 'OG' => 70, 'C'  => 70,
        'DE' => 73, 'DT' => 72,
        'LB' => 72,
        'CB' => 73, 'S'  => 72,
        'K'  => 70, 'P'  => 68, 'LS' => 60,
    ];

    // ================================================================
    //  Public: shouldRetire — callable from anywhere (FA cleanup, etc.)
    // ================================================================

    /**
     * Determine whether a player should retire.
     *
     * @param  array $player  Row from the players table (must include at minimum:
     *                        age, position, overall_rating, team_id, status)
     * @return bool
     */
    public function shouldRetire(array $player): bool
    {
        $age = (int) ($player['age'] ?? 0);
        $ovr = (int) ($player['overall_rating'] ?? 0);
        $pos = $player['position'] ?? '';
        $isFreeAgent = empty($player['team_id']) || ($player['status'] ?? '') === 'free_agent';

        $chance = $this->calculateRetirementChance($age, $ovr, $pos, $isFreeAgent);

        if ($chance <= 0) {
            return false;
        }
        if ($chance >= 100) {
            return true;
        }

        return mt_rand(1, 100) <= $chance;
    }

    /**
     * Calculate retirement probability (0-100) based on age, OVR, position,
     * and free-agent status.  Checked from highest to lowest severity.
     */
    private function calculateRetirementChance(int $age, int $ovr, string $pos, bool $isFreeAgent): int
    {
        $careerEnd  = self::POSITION_CAREER_END[$pos] ?? 34;
        $starterOvr = self::POSITION_STARTER_OVR[$pos] ?? 72;
        $isSpecialTeams = in_array($pos, ['K', 'P', 'LS'], true);

        // ── 1. Mandatory retirement at 42 ───────────────────────────
        if ($age >= 42) {
            return 100;
        }

        // ── 5. Players who almost NEVER retire early ────────────────
        //    Elite QBs (Brady rule): 85+ OVR, under 40
        if ($pos === 'QB' && $ovr >= 85 && $age < 40) {
            return 0;
        }
        //    K/P with serviceable rating play easily into their 40s
        if ($isSpecialTeams && $ovr >= 60 && $age < 40) {
            return 0;
        }

        // ── 2. High probability triggers (80%+) ────────────────────
        // OVR below 50 at any age — can't contribute anywhere
        if ($ovr < 50) {
            return 90;
        }
        // 38+ and OVR below 70
        if ($age >= 38 && $ovr < 70) {
            return 85;
        }
        // 36+ and OVR below 60
        if ($age >= 36 && $ovr < 60) {
            return 85;
        }
        // 35+ and unsigned / free agent
        if ($age >= 35 && $isFreeAgent) {
            return 80;
        }

        // ── 3. Medium probability triggers (40-60%) ────────────────
        // 35+ and OVR below 75
        if ($age >= 35 && $ovr < 75) {
            return 55;
        }
        // 33+ and below starter threshold for their position
        if ($age >= 33 && $ovr < $starterOvr) {
            return 45;
        }
        // Past typical career-end age for position and below 80 OVR
        if ($age >= $careerEnd && $ovr < 80) {
            return 40;
        }

        // ── 4. Low probability triggers (10-20%) ───────────────────
        // RBs specifically — shorter careers
        if ($pos === 'RB' && $age >= 32) {
            return 20;
        }
        // Past typical career end but still good
        if ($age >= $careerEnd) {
            return 15;
        }
        // Generic age-34+ baseline
        if ($age >= 34) {
            return 12;
        }

        // ── No trigger matched ─────────────────────────────────────
        return 0;
    }

    /**
     * Execute the retirement of a single player:
     *   - status -> retired, team_id -> NULL
     *   - void active contracts (mark completed)
     *   - close any open free-agent listing
     */
    private function retirePlayer(int $playerId): void
    {
        $this->db->prepare(
            "UPDATE players SET status = 'retired', team_id = NULL WHERE id = ?"
        )->execute([$playerId]);

        $this->db->prepare(
            "UPDATE contracts SET status = 'completed', years_remaining = 0 WHERE player_id = ? AND status = 'active'"
        )->execute([$playerId]);

        $this->db->prepare(
            "UPDATE free_agents SET status = 'retired' WHERE player_id = ? AND status = 'available'"
        )->execute([$playerId]);
    }

    // ================================================================
    //  Development: player aging, attribute changes, retirement
    // ================================================================

    private function processPlayerDevelopmentInline(int $leagueId): array
    {
        // Include free agents so they can be evaluated for retirement too
        $stmt = $this->db->prepare(
            "SELECT * FROM players WHERE league_id = ? AND status IN ('active', 'practice_squad', 'free_agent')"
        );
        $stmt->execute([$leagueId]);
        $players = $stmt->fetchAll();
        $changes = [];

        foreach ($players as $p) {
            $newAge = $p['age'] + 1;
            $ovr    = (int) $p['overall_rating'];
            $pos    = $p['position'] ?? '';
            $isFreeAgent = empty($p['team_id']) || ($p['status'] ?? '') === 'free_agent';

            // ── Retirement check (evaluated at the new age) ─────────
            $retireCheck = $p;
            $retireCheck['age'] = $newAge;

            if ($this->shouldRetire($retireCheck)) {
                $this->retirePlayer((int) $p['id']);
                // Still persist the incremented age for historical records
                $this->db->prepare("UPDATE players SET age = ? WHERE id = ?")
                    ->execute([$newAge, $p['id']]);

                $changes[] = [
                    'type'      => 'retired',
                    'player_id' => $p['id'],
                    'name'      => $p['first_name'] . ' ' . $p['last_name'],
                    'position'  => $pos,
                    'age'       => $newAge,
                    'final_ovr' => $ovr,
                ];
                continue;
            }

            // ── Free agents just age — no development ───────────────
            if ($isFreeAgent) {
                $this->db->exec("UPDATE players SET age = {$newAge} WHERE id = {$p['id']}");
                continue;
            }

            // ── Rating development (preserved original logic) ───────
            $ratingChange = 0;

            if ($newAge <= 26) {
                $ratingChange = match ($p['potential']) {
                    'elite'   => mt_rand(2, 5),
                    'high'    => mt_rand(1, 3),
                    'average' => mt_rand(0, 2),
                    'limited' => mt_rand(-1, 1),
                    default   => mt_rand(0, 1),
                };
            } elseif ($newAge <= 29) {
                $ratingChange = mt_rand(-1, 1);
            } else {
                $ratingChange = match (true) {
                    $newAge >= 35 => mt_rand(-5, -2),
                    $newAge >= 33 => mt_rand(-4, -1),
                    $newAge >= 30 => mt_rand(-2, 0),
                    default       => 0,
                };
            }

            $newRating = max(40, min(99, $ovr + $ratingChange));

            // Adjust attributes proportionally
            $attrUpdate = '';
            if ($ratingChange !== 0) {
                $attrCols = [
                    'speed','strength','awareness','acceleration','agility',
                    'throw_accuracy_short','throw_accuracy_mid','throw_accuracy_deep','throw_power',
                    'bc_vision','break_tackle','catching','short_route_running','medium_route_running',
                    'deep_route_running','pass_block','run_block','block_shedding','finesse_moves',
                    'power_moves','man_coverage','zone_coverage','press','play_recognition',
                    'pursuit','tackle','hit_power','kick_accuracy','kick_power',
                ];
                $sets = [];
                foreach ($attrCols as $col) {
                    $val = $p[$col] ?? null;
                    if ($val !== null && (int) $val > 0) {
                        $attrShift = $ratingChange + mt_rand(-1, 1);
                        $newVal = max(30, min(99, (int) $val + $attrShift));
                        $sets[] = "{$col} = {$newVal}";
                    }
                }
                if (!empty($sets)) {
                    $attrUpdate = ', ' . implode(', ', $sets);
                }
            }

            // Apply age and attribute changes first
            $this->db->exec(
                "UPDATE players SET age = {$newAge}{$attrUpdate} WHERE id = {$p['id']}"
            );

            // Recalculate OVR from the updated attributes
            $devEngine = new PlayerDevelopmentEngine();
            $newRating = $devEngine->updatePlayerOverall((int) $p['id']);

            $actualChange = $newRating - $ovr;

            if ($actualChange !== 0) {
                $changes[] = [
                    'type' => $actualChange > 0 ? 'improved' : 'declined',
                    'player_id' => $p['id'],
                    'name' => $p['first_name'] . ' ' . $p['last_name'],
                    'position' => $pos,
                    'change' => $actualChange,
                    'new_ovr' => $newRating,
                ];
            }
        }

        return $changes;
    }

    // ================================================================
    //  PHASE: Hall of Fame
    // ================================================================

    private function processHallOfFame(int $leagueId): array
    {
        $league = $this->getLeague($leagueId);
        $seasonYear = (int) ($league['season_year'] ?? 2026);

        $hofEngine = new HallOfFameEngine();
        $inductees = $hofEngine->processHOFInductions($leagueId, $seasonYear);

        return [
            'inductees' => $inductees,
            'total_inducted' => count($inductees),
        ];
    }

    // ================================================================
    //  PHASE: New Season
    // ================================================================

    private function processNewSeason(int $leagueId): array
    {
        $league = $this->getLeague($leagueId);
        $seasonYear = (int) ($league['season_year'] ?? 2026);
        $newYear = $seasonYear + 1;

        // Record coach history
        $this->recordCoachHistory($leagueId, $seasonYear);

        // Reset team records
        $this->db->prepare(
            "UPDATE teams SET wins = 0, losses = 0, ties = 0, points_for = 0, points_against = 0, streak = '' WHERE league_id = ?"
        )->execute([$leagueId]);

        // Deactivate current season
        $this->db->prepare("UPDATE seasons SET is_current = 0 WHERE league_id = ?")->execute([$leagueId]);

        // Create new season
        $this->db->prepare(
            "INSERT INTO seasons (league_id, year, is_current, created_at) VALUES (?, ?, 1, ?)"
        )->execute([$leagueId, $newYear, date('Y-m-d H:i:s')]);
        $newSeasonId = (int) $this->db->lastInsertId();

        // Update league to new season
        $this->db->prepare(
            "UPDATE leagues SET season_year = ?, current_week = 0, phase = 'preseason', offseason_phase = NULL, updated_at = ? WHERE id = ?"
        )->execute([$newYear, date('Y-m-d H:i:s'), $leagueId]);

        // Generate schedule
        $stmt = $this->db->prepare(
            "SELECT id, city, name, abbreviation, conference, division FROM teams WHERE league_id = ?"
        );
        $stmt->execute([$leagueId]);
        $teams = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $scheduleGames = 0;
        if (count($teams) >= 2) {
            $schedGen = new ScheduleGenerator();
            $schedule = $schedGen->generate($leagueId, $newSeasonId, $teams);
            foreach ($schedule as $g) {
                $cols = implode(', ', array_keys($g));
                $placeholders = implode(', ', array_fill(0, count($g), '?'));
                $stmt = $this->db->prepare("INSERT INTO games ({$cols}) VALUES ({$placeholders})");
                $stmt->execute(array_values($g));
            }
            $scheduleGames = count($schedule);
        }

        // Generate new free agents
        $offseason = new OffseasonEngine();
        // generateFreeAgents is private, so call inline
        $freeAgentsGenerated = $this->generateFreeAgentsInline($leagueId);

        // Recalculate draft order for next draft
        try {
            $draftEngine = new DraftEngine();
            $draftEngine->recalculateDraftOrder($leagueId);
            $draftEngine->generateDraftClass($leagueId, $newYear + 1);
        } catch (\Throwable $e) {
            error_log("Draft class generation error: " . $e->getMessage());
        }

        // Update legacy scores
        $this->updateLegacyScores($leagueId);

        // Tick down contract years
        $this->db->prepare(
            "UPDATE contracts SET years_remaining = years_remaining - 1
             WHERE team_id IN (SELECT id FROM teams WHERE league_id = ?) AND status = 'active'"
        )->execute([$leagueId]);

        // Process holdouts — angry players in final contract year may refuse to report
        $holdouts = [];
        try {
            $demandEngine = new PlayerDemandEngine();
            $holdouts = $demandEngine->processHoldouts($leagueId);
        } catch (\Throwable $e) {
            error_log("Holdout processing error: " . $e->getMessage());
        }

        // Clean up stale free agents from last season
        $faCleanedUp = $this->offseasonFreeAgentCleanup($leagueId);

        return [
            'new_season_year' => $newYear,
            'new_season_id' => $newSeasonId,
            'schedule_games' => $scheduleGames,
            'free_agents_generated' => $freeAgentsGenerated,
            'free_agents_cleaned_up' => $faCleanedUp,
        ];
    }

    // ================================================================
    //  Helper: Build TeamBrain for an AI team
    // ================================================================

    private function buildTeamBrain(int $leagueId, int $teamId, array $coach): TeamBrain
    {
        $stmt = $this->db->prepare("SELECT * FROM teams WHERE id = ?");
        $stmt->execute([$teamId]);
        $team = $stmt->fetch(\PDO::FETCH_ASSOC);

        $stmt = $this->db->prepare(
            "SELECT * FROM players WHERE team_id = ? AND status = 'active'"
        );
        $stmt->execute([$teamId]);
        $players = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $stmt = $this->db->prepare(
            "SELECT * FROM draft_picks WHERE current_team_id = ? AND is_used = 0"
        );
        $stmt->execute([$teamId]);
        $picks = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $league = $this->getLeague($leagueId);

        return TeamBrain::create(
            $team,
            $coach,
            $players,
            $picks,
            (int) ($league['current_week'] ?? 0),
            (int) ($league['season_year'] ?? 2026)
        );
    }

    // ================================================================
    //  Helper: Sign a free agent
    // ================================================================

    private function signFreeAgent(int $leagueId, int $faId, int $playerId, int $teamId, int $salary, int $years): void
    {
        // Move player to team
        $this->db->prepare("UPDATE players SET team_id = ?, status = 'active' WHERE id = ?")
            ->execute([$teamId, $playerId]);

        // Create contract
        $capHit = (int) ($salary * 1.05);
        $this->db->prepare(
            "INSERT INTO contracts (player_id, team_id, years_total, years_remaining, salary_annual, cap_hit, guaranteed, dead_cap, signed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $playerId, $teamId, $years, $years,
            $salary, $capHit,
            (int) ($salary * $years * 0.4),
            (int) ($salary * 0.2),
            date('Y-m-d H:i:s'),
        ]);

        // Update team cap
        $this->db->prepare("UPDATE teams SET cap_used = cap_used + ? WHERE id = ?")
            ->execute([$capHit, $teamId]);

        // Mark FA as signed
        $this->db->prepare("UPDATE free_agents SET status = 'signed', signed_at = ? WHERE id = ?")
            ->execute([date('Y-m-d H:i:s'), $faId]);
    }

    // ================================================================
    //  Helper: Market value calculation
    // ================================================================

    private function calculateMarketValue(string $position, int $overall, int $age): int
    {
        $base = 500000;
        $ratingBonus = pow($overall / 100, 2) * 15000000;
        $posMultiplier = match ($position) {
            'QB' => 2.5, 'DE' => 1.4, 'CB' => 1.3, 'WR' => 1.3, 'OT' => 1.2,
            'LB' => 1.1, 'DT' => 1.1, 'RB' => 1.0, 'TE' => 1.0, 'S' => 1.0,
            'OG' => 0.9, 'C' => 0.9, 'K' => 0.5, 'P' => 0.4, 'LS' => 0.3,
            default => 1.0,
        };
        $ageFactor = $age <= 26 ? 1.1 : ($age >= 31 ? 0.7 : 1.0);
        return max($base, (int) ($ratingBonus * $posMultiplier * $ageFactor));
    }

    // ================================================================
    //  Helper: Contract length determination
    // ================================================================

    private function determineContractYears(int $age, int $ovr, string $mode): int
    {
        if ($age >= 33) return 1;
        if ($age >= 30) return mt_rand(1, 2);
        if ($mode === 'rebuilding' && $age >= 28) return mt_rand(1, 2);
        if ($ovr >= 85) return mt_rand(3, 5);
        if ($ovr >= 75) return mt_rand(2, 4);
        return mt_rand(1, 3);
    }

    private function determineFAContractYears(int $age, int $ovr, string $personality): int
    {
        $base = match (true) {
            $age >= 33 => 1,
            $age >= 30 => mt_rand(1, 2),
            $ovr >= 85 => mt_rand(3, 5),
            $ovr >= 75 => mt_rand(2, 4),
            default    => mt_rand(1, 3),
        };

        // Personality adjustments
        return match ($personality) {
            'aggressive'   => min(5, $base + 1), // Longer deals
            'conservative' => max(1, $base - 1), // Shorter deals
            default        => $base,
        };
    }

    // ================================================================
    //  Helper: Get league row
    // ================================================================

    private function getLeague(int $id): array
    {
        $stmt = $this->db->prepare("SELECT * FROM leagues WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    // ================================================================
    //  Helper: Record coach history
    // ================================================================

    private function recordCoachHistory(int $leagueId, int $seasonYear): void
    {
        $stmt = $this->db->prepare(
            "SELECT c.*, t.wins, t.losses FROM coaches c
             JOIN teams t ON c.team_id = t.id
             WHERE c.league_id = ?"
        );
        $stmt->execute([$leagueId]);
        $coaches = $stmt->fetchAll();

        foreach ($coaches as $c) {
            $stmt2 = $this->db->prepare(
                "SELECT COUNT(*) FROM teams
                 WHERE league_id = ? AND conference = (SELECT conference FROM teams WHERE id = ?)
                 AND wins > (SELECT wins FROM teams WHERE id = ?)"
            );
            $stmt2->execute([$leagueId, $c['team_id'], $c['team_id']]);
            $betterTeams = (int) $stmt2->fetchColumn();
            $madePlayoffs = $betterTeams < 7 ? 1 : 0;

            $this->db->prepare(
                "INSERT INTO coach_history (coach_id, team_id, league_id, season_year, wins, losses, made_playoffs, championship, final_influence, final_job_security, fired)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, 0)"
            )->execute([
                $c['id'], $c['team_id'], $leagueId, $seasonYear,
                $c['wins'] ?? 0, $c['losses'] ?? 0, $madePlayoffs,
                $c['influence'], $c['job_security'],
            ]);
        }
    }

    // ================================================================
    //  Helper: Update legacy scores
    // ================================================================

    private function updateLegacyScores(int $leagueId): void
    {
        $stmt = $this->db->prepare("SELECT id FROM coaches WHERE league_id = ?");
        $stmt->execute([$leagueId]);
        $coachIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($coachIds as $coachId) {
            $stmt = $this->db->prepare(
                "SELECT SUM(wins) as tw, SUM(losses) as tl,
                        SUM(made_playoffs) as tp, SUM(championship) as tc,
                        COUNT(*) as seasons
                 FROM coach_history WHERE coach_id = ?"
            );
            $stmt->execute([$coachId]);
            $totals = $stmt->fetch();

            $score = ($totals['tw'] ?? 0) * 5
                + ($totals['tp'] ?? 0) * 20
                + ($totals['tc'] ?? 0) * 100
                + ($totals['seasons'] ?? 0) * 10;

            $stmt = $this->db->prepare("SELECT id FROM legacy_scores WHERE coach_id = ?");
            $stmt->execute([$coachId]);
            if ($stmt->fetch()) {
                $this->db->prepare(
                    "UPDATE legacy_scores SET total_score = ?, total_wins = ?, total_losses = ?,
                     playoff_appearances = ?, championships = ?, seasons_completed = ? WHERE coach_id = ?"
                )->execute([
                    $score, $totals['tw'] ?? 0, $totals['tl'] ?? 0,
                    $totals['tp'] ?? 0, $totals['tc'] ?? 0, $totals['seasons'] ?? 0,
                    $coachId,
                ]);
            } else {
                $this->db->prepare(
                    "INSERT INTO legacy_scores (coach_id, total_score, total_wins, total_losses, playoff_appearances, championships, seasons_completed)
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                )->execute([
                    $coachId, $score, $totals['tw'] ?? 0, $totals['tl'] ?? 0,
                    $totals['tp'] ?? 0, $totals['tc'] ?? 0, $totals['seasons'] ?? 0,
                ]);
            }
        }
    }

    // ================================================================
    //  Helper: Generate free agents (inline from OffseasonEngine)
    // ================================================================

    private function generateFreeAgentsInline(int $leagueId): int
    {
        $generator = new PlayerGenerator();
        $count = 0;

        $positionCounts = [
            'QB' => 3, 'RB' => 5, 'WR' => 8, 'TE' => 3,
            'OT' => 4, 'OG' => 4, 'C' => 2,
            'DE' => 4, 'DT' => 3, 'LB' => 5,
            'CB' => 4, 'S' => 3, 'K' => 2, 'P' => 2,
        ];

        $firstNames = [
            'Marcus', 'Jaylen', 'DeShawn', 'Tyler', 'Caleb', 'Brandon', 'Trevon', 'Malik',
            'Darius', 'Xavier', 'Antonio', 'Cameron', 'Isaiah', 'Jalen', 'Terrell', 'Davon',
        ];
        $lastNames = [
            'Webb', 'Jackson', 'Rodriguez', 'Patterson', 'Williams', 'Brown', 'Davis', 'Johnson',
            'Wilson', 'Thompson', 'Anderson', 'Taylor', 'Thomas', 'Harris', 'Clark', 'Lewis',
        ];

        $rosterPool = $generator->generateForTeam(0, $leagueId);
        $poolByPosition = [];
        foreach ($rosterPool as $p) {
            $poolByPosition[$p['position']][] = $p;
        }

        foreach ($positionCounts as $pos => $num) {
            for ($i = 0; $i < $num; $i++) {
                if (!empty($poolByPosition[$pos])) {
                    $template = $poolByPosition[$pos][array_rand($poolByPosition[$pos])];
                } else {
                    $template = $rosterPool[array_rand($rosterPool)];
                    $template['position'] = $pos;
                }

                $overall = mt_rand(55, 82);
                $age = mt_rand(23, 32);

                $template['team_id'] = null;
                $template['first_name'] = $firstNames[array_rand($firstNames)];
                $template['last_name'] = $lastNames[array_rand($lastNames)];
                $template['overall_rating'] = $overall;
                $template['status'] = 'free_agent';
                $template['age'] = $age;
                $template['experience'] = max(0, $age - 22);
                $template['years_pro'] = max(0, $age - 22);
                $template['jersey_number'] = mt_rand(1, 99);
                $template['birthdate'] = sprintf('%04d-%02d-%02d', date('Y') - $age, mt_rand(1, 12), mt_rand(1, 28));
                $template['created_at'] = date('Y-m-d H:i:s');

                $cols = implode(', ', array_keys($template));
                $placeholders = implode(', ', array_fill(0, count($template), '?'));
                $stmt = $this->db->prepare("INSERT INTO players ({$cols}) VALUES ({$placeholders})");
                $stmt->execute(array_values($template));
                $playerId = (int) $this->db->lastInsertId();

                $marketValue = $this->calculateMarketValue($pos, $overall, $age);

                $this->db->prepare(
                    "INSERT INTO free_agents (league_id, player_id, asking_salary, market_value, status, released_at)
                     VALUES (?, ?, ?, ?, 'available', ?)"
                )->execute([$leagueId, $playerId, $marketValue, $marketValue, date('Y-m-d H:i:s')]);

                $count++;
            }
        }

        return $count;
    }

    // ================================================================
    //  Public: Get human team's expiring contracts
    // ================================================================

    public function getExpiringContracts(int $leagueId, int $teamId): array
    {
        $stmt = $this->db->prepare(
            "SELECT c.id as contract_id, c.player_id, c.salary_annual, c.cap_hit,
                    c.years_remaining, c.years_total,
                    p.first_name, p.last_name, p.position, p.overall_rating, p.age, p.potential
             FROM contracts c
             JOIN players p ON c.player_id = p.id
             WHERE p.team_id = ? AND p.league_id = ? AND c.years_remaining <= 1
             ORDER BY p.overall_rating DESC"
        );
        $stmt->execute([$teamId, $leagueId]);
        $expiring = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Add market value estimate
        foreach ($expiring as &$e) {
            $e['market_value'] = $this->calculateMarketValue(
                $e['position'], (int) $e['overall_rating'], (int) $e['age']
            );
        }

        return $expiring;
    }

    // ================================================================
    //  Public: Human re-signs a player
    // ================================================================

    public function reSignPlayer(int $leagueId, int $playerId, int $teamId, int $salaryOffer, int $yearsOffer): array
    {
        // Validate player belongs to team
        $stmt = $this->db->prepare(
            "SELECT p.*, c.id as contract_id FROM players p
             LEFT JOIN contracts c ON c.player_id = p.id AND c.years_remaining <= 1
             WHERE p.id = ? AND p.team_id = ? AND p.league_id = ?"
        );
        $stmt->execute([$playerId, $teamId, $leagueId]);
        $player = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$player) {
            return ['error' => 'Player not found on your team'];
        }

        $marketValue = $this->calculateMarketValue(
            $player['position'], (int) $player['overall_rating'], (int) $player['age']
        );

        // Must offer at least 70% of market value
        if ($salaryOffer < $marketValue * 0.7) {
            return ['error' => 'Offer too low. Minimum is 70% of market value ($' . number_format($marketValue * 0.7) . ')'];
        }

        // Delete old contract if exists
        if ($player['contract_id']) {
            $this->db->prepare("DELETE FROM contracts WHERE id = ?")->execute([$player['contract_id']]);
        }

        // Create new contract
        $capHit = (int) ($salaryOffer * 1.05);
        $this->db->prepare(
            "INSERT INTO contracts (player_id, team_id, years_total, years_remaining, salary_annual, cap_hit, guaranteed, dead_cap, signed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $playerId, $teamId, $yearsOffer, $yearsOffer,
            $salaryOffer, $capHit,
            (int) ($salaryOffer * $yearsOffer * 0.5),
            (int) ($salaryOffer * 0.3),
            date('Y-m-d H:i:s'),
        ]);

        // Update cap
        $this->db->prepare("UPDATE teams SET cap_used = cap_used + ? WHERE id = ?")
            ->execute([$capHit, $teamId]);

        return [
            'success' => true,
            'player_id' => $playerId,
            'name' => $player['first_name'] . ' ' . $player['last_name'],
            'salary' => $salaryOffer,
            'years' => $yearsOffer,
            'cap_hit' => $capHit,
        ];
    }

    // ================================================================
    //  Public: Human declines to re-sign (player goes to FA)
    // ================================================================

    public function declinePlayer(int $leagueId, int $playerId, int $teamId): array
    {
        $stmt = $this->db->prepare(
            "SELECT p.first_name, p.last_name, p.position, p.overall_rating
             FROM players p WHERE p.id = ? AND p.team_id = ? AND p.league_id = ?"
        );
        $stmt->execute([$playerId, $teamId, $leagueId]);
        $player = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$player) {
            return ['error' => 'Player not found on your team'];
        }

        // Remove contract
        $this->db->prepare("DELETE FROM contracts WHERE player_id = ? AND team_id = ?")
            ->execute([$playerId, $teamId]);

        // Release to free agency
        $faEngine = new FreeAgencyEngine();
        $faEngine->releasePlayer($leagueId, $playerId);

        return [
            'success' => true,
            'player_id' => $playerId,
            'name' => $player['first_name'] . ' ' . $player['last_name'],
            'position' => $player['position'],
            'released_to_fa' => true,
        ];
    }

    // ================================================================
    //  PHASE: Pre-Draft
    // ================================================================

    private function processPreDraft(int $leagueId): array
    {
        $league = $this->getLeague($leagueId);
        $seasonYear = (int) ($league['season_year'] ?? 2026);

        // Generate pre-draft coverage articles
        $articles = [];
        try {
            $draftEngine = new DraftEngine();
            $classId = $draftEngine->getCurrentClassId($leagueId);
            if ($classId) {
                $seasonStmt = $this->db->prepare(
                    "SELECT id FROM seasons WHERE league_id = ? AND is_current = 1 LIMIT 1"
                );
                $seasonStmt->execute([$leagueId]);
                $seasonRow = $seasonStmt->fetch();
                $seasonId = $seasonRow ? (int) $seasonRow['id'] : 0;

                if (class_exists('App\\Services\\DraftScoutEngine')) {
                    $scoutEngine = new DraftScoutEngine();
                    $articles = $scoutEngine->generatePreDraftCoverage($leagueId, $seasonId, $classId);
                }
            }
        } catch (\Throwable $e) {
            error_log("Pre-draft coverage error: " . $e->getMessage());
        }

        // Final stock adjustments — a few prospects rise/fall
        $stockChanges = $this->preDraftStockAdjustments($leagueId);

        return [
            'articles_generated' => is_array($articles) ? count($articles) : 0,
            'stock_changes' => $stockChanges,
            'message' => 'Pre-draft week: final scouting before the draft. Trade your picks now!',
        ];
    }

    /**
     * Minor stock adjustments for the pre-draft week — mock draft buzz
     */
    private function preDraftStockAdjustments(int $leagueId): array
    {
        $draftEngine = new DraftEngine();
        $classId = $draftEngine->getCurrentClassId($leagueId);
        if (!$classId) return [];

        $stmt = $this->db->prepare(
            "SELECT id, stock_rating, stock_trend FROM draft_prospects
             WHERE draft_class_id = ? AND is_drafted = 0
             ORDER BY RANDOM() LIMIT 10"
        );
        $stmt->execute([$classId]);
        $prospects = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $changes = [];
        foreach ($prospects as $p) {
            $delta = mt_rand(-5, 5);
            if ($delta === 0) continue;

            $newStock = max(10, min(100, (int) ($p['stock_rating'] ?? 50) + $delta));
            $newTrend = $delta > 2 ? 'rising' : ($delta < -2 ? 'falling' : 'steady');

            $this->db->prepare(
                "UPDATE draft_prospects SET stock_rating = ?, stock_trend = ? WHERE id = ?"
            )->execute([$newStock, $newTrend, $p['id']]);

            $changes[] = ['prospect_id' => (int) $p['id'], 'delta' => $delta, 'trend' => $newTrend];
        }

        return $changes;
    }

    // ================================================================
    //  PHASE: UDFA Window
    // ================================================================

    private function processUDFAWindow(int $leagueId): array
    {
        // The UDFAs were already created during processDraftAI.
        // This phase exists so the human player has a dedicated window
        // to browse and sign UDFAs before AI teams grab them in roster_cuts.

        // Count available UDFAs
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM free_agents fa
             JOIN players p ON fa.player_id = p.id
             WHERE fa.league_id = ? AND fa.status = 'available' AND p.is_rookie = 1 AND p.team_id IS NULL"
        );
        $stmt->execute([$leagueId]);
        $udfaCount = (int) $stmt->fetchColumn();

        // Get top available UDFAs for the summary
        $stmt = $this->db->prepare(
            "SELECT p.first_name, p.last_name, p.position, p.overall_rating, p.college
             FROM free_agents fa
             JOIN players p ON fa.player_id = p.id
             WHERE fa.league_id = ? AND fa.status = 'available' AND p.is_rookie = 1 AND p.team_id IS NULL
             ORDER BY p.overall_rating DESC
             LIMIT 10"
        );
        $stmt->execute([$leagueId]);
        $topUDFAs = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $topUDFAList = array_map(fn($p) => [
            'name' => $p['first_name'] . ' ' . $p['last_name'],
            'position' => $p['position'],
            'overall' => (int) $p['overall_rating'],
            'college' => $p['college'],
        ], $topUDFAs);

        return [
            'udfa_available' => $udfaCount,
            'top_udfas' => $topUDFAList,
            'message' => $udfaCount > 0
                ? "{$udfaCount} undrafted free agents are available. Sign them before roster cuts!"
                : 'No undrafted free agents available.',
        ];
    }

    /**
     * Get metadata for a phase (week number, label, short label).
     */
    public function getPhaseMeta(?string $phase): array
    {
        if (!$phase || !isset(self::PHASE_META[$phase])) {
            return ['week' => 0, 'label' => 'Offseason', 'short' => 'Offseason'];
        }
        return self::PHASE_META[$phase];
    }

    /**
     * Get all phases with their metadata for the frontend timeline.
     */
    public function getAllPhasesMeta(): array
    {
        $result = [];
        foreach (self::PHASES as $phase) {
            $meta = self::PHASE_META[$phase] ?? ['week' => 0, 'label' => $phase, 'short' => $phase];
            $result[] = array_merge($meta, ['id' => $phase]);
        }
        return $result;
    }
}
