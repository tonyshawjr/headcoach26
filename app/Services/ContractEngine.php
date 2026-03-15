<?php

namespace App\Services;

use App\Database\Connection;

class ContractEngine
{
    private \PDO $db;

    /** 2026 NFL salary cap */
    const SALARY_CAP = 301200000;

    /** Veteran minimum salary */
    const VETERAN_MINIMUM = 1100000;

    /**
     * Position market value ranges [min_avg, max_avg] in dollars for a starter-quality player.
     * Used as multipliers against the base OVR curve.
     */
    const POSITION_MARKET = [
        'QB' => [25000000, 60000000],
        'DE' => [15000000, 28000000],
        'WR' => [15000000, 35000000],
        'CB' => [12000000, 22000000],
        'OT' => [12000000, 22000000],
        'S'  => [8000000,  16000000],
        'LB' => [8000000,  18000000],
        'DT' => [8000000,  16000000],
        'TE' => [8000000,  15000000],
        'RB' => [5000000,  12000000],
        'OG' => [6000000,  14000000],
        'C'  => [5000000,  12000000],
        'K'  => [3000000,   6000000],
        'P'  => [2000000,   4000000],
        'LS' => [1000000,   2000000],
    ];

    /**
     * Rookie contract scale: [total_value, guaranteed] by overall pick number.
     * Interpolated for picks not explicitly listed.
     */
    const ROOKIE_SCALE = [
        1  => [40000000, 25000000],
        5  => [32000000, 22000000],
        10 => [25000000, 18000000],
        15 => [20000000, 15000000],
        20 => [18000000, 13000000],
        25 => [16000000, 11000000],
        32 => [15000000, 10000000],
        // Round 2 (picks 33-64)
        33 => [12000000, 6000000],
        48 => [10000000, 4500000],
        64 => [8000000,  3500000],
        // Round 3 (picks 65-96)
        65 => [7000000,  2500000],
        96 => [5000000,  1800000],
        // Round 4 (picks 97-128)
        97  => [5000000, 1500000],
        128 => [4000000, 1000000],
        // Round 5 (picks 129-160)
        129 => [4000000, 800000],
        160 => [3500000, 600000],
        // Round 6 (picks 161-192)
        161 => [3500000, 500000],
        192 => [3000000, 350000],
        // Round 7 (picks 193-224)
        193 => [3000000, 300000],
        224 => [2500000, 200000],
        // UDFA
        225 => [2500000, 100000],
    ];

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
    }

    // ─── Schema migration ────────────────────────────────────────────

    /**
     * Add missing columns to contracts/teams tables if they don't exist.
     * Safe to call multiple times (idempotent).
     */
    public function migrateSchema(): void
    {
        $cols = [];
        $stmt = $this->db->query("PRAGMA table_info(contracts)");
        foreach ($stmt->fetchAll() as $row) {
            $cols[] = $row['name'];
        }

        if (!in_array('status', $cols)) {
            $this->db->exec("ALTER TABLE contracts ADD COLUMN status VARCHAR(20) DEFAULT 'active'");
        }
        if (!in_array('signing_bonus', $cols)) {
            $this->db->exec("ALTER TABLE contracts ADD COLUMN signing_bonus INT DEFAULT 0");
        }
        if (!in_array('base_salary', $cols)) {
            $this->db->exec("ALTER TABLE contracts ADD COLUMN base_salary INT DEFAULT 0");
        }
        if (!in_array('contract_type', $cols)) {
            $this->db->exec("ALTER TABLE contracts ADD COLUMN contract_type VARCHAR(20) DEFAULT 'standard'");
        }
        if (!in_array('total_value', $cols)) {
            $this->db->exec("ALTER TABLE contracts ADD COLUMN total_value INT DEFAULT 0");
        }
        if (!in_array('void_years', $cols)) {
            $this->db->exec("ALTER TABLE contracts ADD COLUMN void_years INT DEFAULT 0");
        }
        if (!in_array('has_incentives', $cols)) {
            $this->db->exec("ALTER TABLE contracts ADD COLUMN has_incentives TINYINT DEFAULT 0");
        }
        if (!in_array('incentive_type', $cols)) {
            $this->db->exec("ALTER TABLE contracts ADD COLUMN incentive_type VARCHAR(30) NULL");
        }
        if (!in_array('incentive_value', $cols)) {
            $this->db->exec("ALTER TABLE contracts ADD COLUMN incentive_value INT DEFAULT 0");
        }
        if (!in_array('incentive_threshold', $cols)) {
            $this->db->exec("ALTER TABLE contracts ADD COLUMN incentive_threshold TEXT NULL");
        }

        // Create dead_cap_charges table if it doesn't exist
        $this->db->exec("CREATE TABLE IF NOT EXISTS dead_cap_charges (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            team_id INT NOT NULL,
            player_id INT NOT NULL,
            contract_id INT NOT NULL,
            league_id INT NOT NULL,
            season_year INT NOT NULL,
            cap_charge INTEGER NOT NULL DEFAULT 0,
            charge_type VARCHAR(20) NOT NULL DEFAULT 'standard',
            is_post_june1 INTEGER NOT NULL DEFAULT 0,
            description TEXT NULL,
            created_at DATETIME NOT NULL,
            FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
            FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
        )");

        // Update salary cap to 2026 value
        $this->db->exec("UPDATE teams SET salary_cap = " . self::SALARY_CAP . " WHERE salary_cap = 225000000");
    }

    // ─── Market Value ────────────────────────────────────────────────

    /**
     * Calculate a player's annual market value in dollars.
     *
     * Uses an exponential curve on OVR so elite players earn dramatically
     * more than average ones, then applies position and age multipliers.
     */
    public function calculateMarketValue(array $player): int
    {
        $ovr = (int) ($player['overall_rating'] ?? 70);
        $age = (int) ($player['age'] ?? 26);
        $pos = $player['position'] ?? 'LB';
        $potential = $player['potential'] ?? 'average';

        // Base value: exponential curve on OVR (70 = ~$5M, 80 = ~$12M, 90 = ~$28M, 99 = ~$55M)
        // Formula: base = ((ovr - 60) / 39)^3 * max_market
        $posRange = self::POSITION_MARKET[$pos] ?? [5000000, 15000000];
        $maxMarket = $posRange[1];
        $minMarket = $posRange[0];

        if ($ovr <= 64) {
            // Below 65: veteran minimum
            return self::VETERAN_MINIMUM;
        }

        // Normalized 0..1 where 65=0, 99=1
        $norm = max(0, min(1, ($ovr - 65) / 34));
        // Exponential curve: cube for top-heavy distribution
        $curve = pow($norm, 2.5);
        $baseValue = (int) ($minMarket * 0.3 + $curve * $maxMarket);

        // Age discount
        $ageFactor = match (true) {
            $age <= 25 => 1.05,   // Young premium
            $age <= 27 => 1.0,    // Prime
            $age <= 29 => 0.90,   // Starting decline
            $age <= 31 => 0.75,   // Significant discount
            $age <= 33 => 0.60,   // Steep decline
            default    => 0.45,   // 34+: near minimum
        };

        // Potential premium (young + elite potential = future star)
        $potentialFactor = 1.0;
        if ($age <= 26) {
            $potentialFactor = match ($potential) {
                'elite'   => 1.15,
                'high'    => 1.08,
                'average' => 1.0,
                'limited' => 0.92,
                default   => 1.0,
            };
        }

        $value = (int) ($baseValue * $ageFactor * $potentialFactor);

        return max(self::VETERAN_MINIMUM, $value);
    }

    // ─── Contract Generation ─────────────────────────────────────────

    /**
     * Generate a full contract data array for a player.
     *
     * @param string $type      'veteran', 'rookie', 'franchise_tag', 'minimum'
     * @param int    $voidYears Optional void years (spread proration further)
     * @param array|null $incentive Optional incentive clause:
     *   ['type' => 'performance', 'value' => 500000, 'threshold' => ['stat' => 'rush_yards', 'threshold' => 1000]]
     * @return array Contract data (NOT inserted to DB)
     */
    public function generateContractForPlayer(array $player, string $type = 'veteran', int $voidYears = 0, ?array $incentive = null): array
    {
        $ovr = (int) ($player['overall_rating'] ?? 70);
        $age = (int) ($player['age'] ?? 26);
        $annualValue = $this->calculateMarketValue($player);

        // Determine contract length
        $years = $this->determineContractYears($ovr, $age, $type);

        // Guaranteed money percentage
        $guaranteedPct = $this->determineGuaranteedPct($ovr, $age);

        $totalValue = $annualValue * $years;
        $guaranteed = (int) ($totalValue * $guaranteedPct);
        $signingBonus = (int) ($guaranteed * 0.6); // 60% of guaranteed paid upfront as bonus

        // Proration years include void years
        $prorationYears = $years + $voidYears;
        $baseSalary = $annualValue - (int) ($signingBonus / $prorationYears);

        // Cap hit = base salary + prorated signing bonus (spread over real + void years)
        $capHit = $baseSalary + (int) ($signingBonus / $prorationYears);

        // Dead cap = remaining prorated signing bonus if cut today
        $deadCap = $signingBonus; // Full bonus accelerates if cut immediately

        $contract = [
            'salary_annual'  => $annualValue,
            'total_value'    => $totalValue,
            'years_total'    => $years,
            'years_remaining'=> $years,
            'cap_hit'        => $capHit,
            'guaranteed'     => $guaranteed,
            'dead_cap'       => $deadCap,
            'signing_bonus'  => $signingBonus,
            'base_salary'    => $baseSalary,
            'contract_type'  => $type,
            'status'         => 'active',
            'void_years'     => $voidYears,
        ];

        // Attach incentive data if provided
        if ($incentive && isset($incentive['type'], $incentive['value'])) {
            $contract['has_incentives']      = 1;
            $contract['incentive_type']      = $incentive['type'];
            $contract['incentive_value']     = (int) $incentive['value'];
            $contract['incentive_threshold'] = json_encode($incentive['threshold'] ?? []);
        } else {
            $contract['has_incentives']      = 0;
            $contract['incentive_type']      = null;
            $contract['incentive_value']     = 0;
            $contract['incentive_threshold'] = null;
        }

        return $contract;
    }

    /**
     * Generate a rookie contract from draft position.
     */
    public function generateRookieContract(int $round, int $pickNumber): array
    {
        // Interpolate from the rookie scale
        [$totalValue, $guaranteed] = $this->interpolateRookieScale($pickNumber);

        $years = 4; // All rookies get 4-year deals
        $annualValue = (int) ($totalValue / $years);
        $signingBonus = (int) ($guaranteed * 0.7); // Most guaranteed money is signing bonus
        $baseSalary = $annualValue - (int) ($signingBonus / $years);
        $capHit = $baseSalary + (int) ($signingBonus / $years);

        return [
            'salary_annual'  => $annualValue,
            'total_value'    => $totalValue,
            'years_total'    => $years,
            'years_remaining'=> $years,
            'cap_hit'        => $capHit,
            'guaranteed'     => $guaranteed,
            'dead_cap'       => $signingBonus,
            'signing_bonus'  => $signingBonus,
            'base_salary'    => $baseSalary,
            'contract_type'  => 'rookie',
            'status'         => 'active',
        ];
    }

    // ─── Bulk Generation ─────────────────────────────────────────────

    /**
     * Generate contracts for EVERY active player in the league.
     * Called during franchise setup or Madden import.
     *
     * Distributes years_remaining randomly so contracts don't all expire together.
     */
    public function generateAllContracts(int $leagueId): array
    {
        $this->migrateSchema();

        $now = date('Y-m-d H:i:s');

        // Get all active players with their team
        $stmt = $this->db->prepare(
            "SELECT p.* FROM players p
             WHERE p.league_id = ? AND p.status = 'active' AND p.team_id IS NOT NULL"
        );
        $stmt->execute([$leagueId]);
        $players = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Delete any existing contracts for this league's players (fresh start)
        $this->db->prepare(
            "DELETE FROM contracts WHERE player_id IN (
                SELECT id FROM players WHERE league_id = ?
            )"
        )->execute([$leagueId]);

        $totalContracts = 0;
        $totalCapByTeam = [];

        $insertStmt = $this->db->prepare(
            "INSERT INTO contracts (player_id, team_id, years_total, years_remaining, salary_annual,
             cap_hit, guaranteed, dead_cap, signing_bonus, base_salary, contract_type, total_value,
             void_years, has_incentives, incentive_type, incentive_value, incentive_threshold,
             status, signed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)"
        );

        foreach ($players as $player) {
            $teamId = (int) $player['team_id'];
            $ovr = (int) $player['overall_rating'];
            $age = (int) $player['age'];

            // Generate base contract
            $contract = $this->generateContractForPlayer($player, 'veteran');

            // Randomize years remaining (distribute across 1-4 so not all expire at once)
            $yearsRemaining = $this->randomizeYearsRemaining($contract['years_total'], $age);
            $contract['years_remaining'] = $yearsRemaining;

            // Recalculate dead cap based on actual remaining years
            $proratedBonusPerYear = $contract['years_total'] > 0
                ? (int) ($contract['signing_bonus'] / $contract['years_total'])
                : 0;
            $contract['dead_cap'] = $proratedBonusPerYear * $yearsRemaining;

            // Apply +/-15% randomness to salary
            $variance = mt_rand(-15, 15) / 100;
            $contract['salary_annual'] = max(self::VETERAN_MINIMUM, (int) ($contract['salary_annual'] * (1 + $variance)));
            $contract['base_salary'] = max(0, (int) ($contract['base_salary'] * (1 + $variance)));
            $contract['cap_hit'] = $contract['base_salary'] + $proratedBonusPerYear;
            $contract['total_value'] = $contract['salary_annual'] * $contract['years_total'];
            $contract['guaranteed'] = (int) ($contract['guaranteed'] * (1 + $variance));

            $insertStmt->execute([
                $player['id'],
                $teamId,
                $contract['years_total'],
                $contract['years_remaining'],
                $contract['salary_annual'],
                $contract['cap_hit'],
                $contract['guaranteed'],
                $contract['dead_cap'],
                $contract['signing_bonus'],
                $contract['base_salary'],
                $contract['contract_type'],
                $contract['total_value'],
                $contract['void_years'] ?? 0,
                $contract['has_incentives'] ?? 0,
                $contract['incentive_type'] ?? null,
                $contract['incentive_value'] ?? 0,
                $contract['incentive_threshold'] ?? null,
                $now,
            ]);

            // Track cap by team
            if (!isset($totalCapByTeam[$teamId])) {
                $totalCapByTeam[$teamId] = 0;
            }
            $totalCapByTeam[$teamId] += $contract['cap_hit'];
            $totalContracts++;
        }

        // Update each team's cap_used
        foreach ($totalCapByTeam as $teamId => $capUsed) {
            $this->db->prepare("UPDATE teams SET cap_used = ? WHERE id = ?")
                ->execute([$capUsed, $teamId]);
        }

        return [
            'contracts_created' => $totalContracts,
            'teams_updated'     => count($totalCapByTeam),
            'total_cap_allocated' => array_sum($totalCapByTeam),
        ];
    }

    // ─── Team Cap ────────────────────────────────────────────────────

    /**
     * Calculate a team's full cap breakdown including dead cap charges.
     */
    public function calculateTeamCap(int $teamId): array
    {
        $teamRow = $this->db->prepare("SELECT salary_cap FROM teams WHERE id = ?");
        $teamRow->execute([$teamId]);
        $salaryCap = (int) ($teamRow->fetch()['salary_cap'] ?? self::SALARY_CAP);

        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(cap_hit), 0) as cap_used,
                    COALESCE(SUM(guaranteed), 0) as guaranteed_total,
                    COALESCE(SUM(dead_cap), 0) as dead_money
             FROM contracts WHERE team_id = ? AND status = 'active'"
        );
        $stmt->execute([$teamId]);
        $totals = $stmt->fetch();

        $activeCapUsed = (int) $totals['cap_used'];

        // Add current-year dead cap charges from released players
        $deadCapCharges = $this->getTotalDeadMoney($teamId);
        $totalCapUsed = $activeCapUsed + $deadCapCharges;

        // Next year's dead cap charges (for post-June-1 cuts)
        $seasonYear = $this->getCurrentSeasonYear($teamId);
        $nextYearDeadCap = $this->getTotalDeadMoney($teamId, $seasonYear + 1);

        return [
            'cap_total'           => $salaryCap,
            'cap_used'            => $totalCapUsed,
            'cap_remaining'       => $salaryCap - $totalCapUsed,
            'active_contracts'    => $activeCapUsed,
            'dead_cap_charges'    => $deadCapCharges,
            'next_year_dead_cap'  => $nextYearDeadCap,
            'guaranteed_total'    => (int) $totals['guaranteed_total'],
            'dead_money'          => (int) $totals['dead_money'] + $deadCapCharges,
        ];
    }

    /**
     * Check if a team can afford a contract at the given annual salary.
     */
    public function canAffordContract(int $teamId, int $annualSalary): bool
    {
        $cap = $this->calculateTeamCap($teamId);
        return $cap['cap_remaining'] >= $annualSalary;
    }

    // ─── Dead Cap Calculations ───────────────────────────────────────

    /**
     * Calculate dead cap for a contract if the player were cut right now.
     *
     * Dead cap = unamortized signing bonus + remaining guaranteed base salary.
     * Signing bonus is prorated evenly over the contract length. When a player
     * is cut, all future years' proration accelerates onto the current cap.
     *
     * Example: 5-year deal, $25M signing bonus ($5M/yr proration).
     *          Cut after year 2 => 3 years unamortized => $15M dead cap from bonus.
     *          Plus any remaining guaranteed base salary not yet earned.
     */
    public function calculateDeadCap(int $contractId): int
    {
        $contract = $this->getContract($contractId);
        if (!$contract) return 0;

        return $this->computeDeadCap($contract);
    }

    /**
     * Calculate post-June-1 cut dead cap split.
     *
     * Post-June-1 designation: only the current year's signing bonus proration
     * hits this year's cap. The remaining unamortized bonus hits next year's cap.
     * Remaining guaranteed base salary hits this year.
     *
     * Returns: ['year1_dead' => int, 'year2_dead' => int, 'total_dead' => int, 'cap_saved_year1' => int]
     */
    public function calculatePostJuneCut(int $contractId): array
    {
        $contract = $this->getContract($contractId);
        if (!$contract) {
            return ['year1_dead' => 0, 'year2_dead' => 0, 'total_dead' => 0, 'cap_saved_year1' => 0];
        }

        $yearsTotal = (int) $contract['years_total'];
        $yearsRemaining = (int) $contract['years_remaining'];
        $signingBonus = (int) $contract['signing_bonus'];
        $guaranteed = (int) $contract['guaranteed'];
        $salaryAnnual = (int) $contract['salary_annual'];
        $capHit = (int) $contract['cap_hit'];
        $voidYears = (int) ($contract['void_years'] ?? 0);

        // Total proration years includes void years
        $totalProrationYears = $yearsTotal + $voidYears;

        // Prorated signing bonus per year
        $proratedPerYear = $totalProrationYears > 0 ? (int) ($signingBonus / $totalProrationYears) : 0;

        // Remaining guaranteed base salary (guaranteed minus signing bonus minus salary already paid)
        $yearsPaid = $yearsTotal - $yearsRemaining;
        $salaryPaid = $salaryAnnual * $yearsPaid;
        $guaranteedBaseRemaining = max(0, $guaranteed - $signingBonus - $salaryPaid);

        // Year 1 dead cap: current year's proration only + guaranteed base salary remaining
        $year1Dead = $proratedPerYear + $guaranteedBaseRemaining;

        // Year 2 dead cap: remaining unamortized bonus (excluding current year's share)
        $unamortizedYears = max(0, $yearsRemaining + $voidYears - 1);
        $year2Dead = $proratedPerYear * $unamortizedYears;

        $totalDead = $year1Dead + $year2Dead;

        return [
            'year1_dead'       => $year1Dead,
            'year2_dead'       => $year2Dead,
            'total_dead'       => $totalDead,
            'cap_saved_year1'  => max(0, $capHit - $year1Dead),
        ];
    }

    /**
     * Preview dead cap for cutting a player (without actually cutting).
     * Returns both standard and post-June-1 options for comparison.
     */
    public function previewCut(int $playerId): array
    {
        $contract = $this->getActiveContractByPlayer($playerId);
        if (!$contract) {
            return ['error' => 'No active contract found'];
        }

        $standardDeadCap = $this->computeDeadCap($contract);
        $postJune = $this->calculatePostJuneCut((int) $contract['id']);

        // Get player info
        $stmt = $this->db->prepare("SELECT first_name, last_name, position, overall_rating FROM players WHERE id = ?");
        $stmt->execute([$playerId]);
        $player = $stmt->fetch();

        return [
            'contract_id'    => (int) $contract['id'],
            'player_id'      => $playerId,
            'player_name'    => $player ? $player['first_name'] . ' ' . $player['last_name'] : 'Unknown',
            'position'       => $player['position'] ?? '',
            'current_cap_hit'=> (int) $contract['cap_hit'],
            'years_remaining'=> (int) $contract['years_remaining'],
            'standard_cut'   => [
                'dead_cap'  => $standardDeadCap,
                'cap_saved' => max(0, (int) $contract['cap_hit'] - $standardDeadCap),
            ],
            'post_june1_cut' => $postJune,
        ];
    }

    // ─── Contract Operations ─────────────────────────────────────────

    /**
     * Cut (release) a player, applying dead cap to the team.
     *
     * @param int  $playerId    The player to cut
     * @param bool $postJune1   If true, use post-June-1 designation (splits dead cap over 2 years)
     * @return array Result with dead_cap, cap_saved, and charge details
     */
    public function cutPlayer(int $playerId, bool $postJune1 = false): array
    {
        $contract = $this->getActiveContractByPlayer($playerId);
        if (!$contract) {
            return ['error' => 'No active contract found', 'dead_cap' => 0];
        }

        $contractId = (int) $contract['id'];
        $teamId = (int) $contract['team_id'];
        $capHit = (int) $contract['cap_hit'];

        // Get current season year for the league
        $seasonYear = $this->getCurrentSeasonYear($teamId);

        // Get league_id for the dead cap charge record
        $leagueId = $this->getLeagueIdForTeam($teamId);

        // Get player info for the charge description
        $stmt = $this->db->prepare("SELECT first_name, last_name, position FROM players WHERE id = ?");
        $stmt->execute([$playerId]);
        $player = $stmt->fetch();
        $playerName = $player ? $player['first_name'] . ' ' . $player['last_name'] : 'Unknown';

        if ($postJune1) {
            $split = $this->calculatePostJuneCut($contractId);
            $totalDeadCap = $split['total_dead'];

            // Record year 1 dead cap charge
            if ($split['year1_dead'] > 0) {
                $this->recordDeadCapCharge(
                    $teamId, $playerId, $contractId, $leagueId, $seasonYear,
                    $split['year1_dead'], 'post_june1_y1', true,
                    "Post-June-1 cut: {$playerName} (Year 1)"
                );
            }

            // Record year 2 dead cap charge
            if ($split['year2_dead'] > 0) {
                $this->recordDeadCapCharge(
                    $teamId, $playerId, $contractId, $leagueId, $seasonYear + 1,
                    $split['year2_dead'], 'post_june1_y2', true,
                    "Post-June-1 cut: {$playerName} (Year 2)"
                );
            }

            $capSaved = max(0, $capHit - $split['year1_dead']);

            // Terminate the contract
            $this->db->prepare(
                "UPDATE contracts SET status = 'terminated', dead_cap = ?, years_remaining = 0 WHERE id = ?"
            )->execute([$totalDeadCap, $contractId]);

        } else {
            // Standard cut: all dead cap accelerates to this year
            $totalDeadCap = $this->computeDeadCap($contract);

            // Record the dead cap charge
            if ($totalDeadCap > 0) {
                $this->recordDeadCapCharge(
                    $teamId, $playerId, $contractId, $leagueId, $seasonYear,
                    $totalDeadCap, 'standard', false,
                    "Released: {$playerName}"
                );
            }

            $capSaved = max(0, $capHit - $totalDeadCap);

            // Terminate the contract
            $this->db->prepare(
                "UPDATE contracts SET status = 'terminated', dead_cap = ?, years_remaining = 0 WHERE id = ?"
            )->execute([$totalDeadCap, $contractId]);
        }

        // Recalculate team cap (active contracts + current year dead cap charges)
        $this->recalculateTeamCap($teamId);

        return [
            'contract_id'  => $contractId,
            'player_id'    => $playerId,
            'player_name'  => $playerName,
            'dead_cap'     => $totalDeadCap,
            'cap_saved'    => $capSaved,
            'post_june1'   => $postJune1,
            'year1_dead'   => $postJune1 ? $split['year1_dead'] : $totalDeadCap,
            'year2_dead'   => $postJune1 ? $split['year2_dead'] : 0,
        ];
    }

    /**
     * Release a player's contract (cut the player) — legacy wrapper.
     * Remaining guaranteed money accelerates as dead cap.
     */
    public function releasePlayerContract(int $playerId, bool $postJune1 = false): array
    {
        return $this->cutPlayer($playerId, $postJune1);
    }

    /**
     * Get all dead cap charges for a team in a given season year.
     */
    public function getDeadCapCharges(int $teamId, ?int $seasonYear = null): array
    {
        if ($seasonYear === null) {
            $seasonYear = $this->getCurrentSeasonYear($teamId);
        }

        try {
            $stmt = $this->db->prepare(
                "SELECT dcc.*, p.first_name, p.last_name, p.position
                 FROM dead_cap_charges dcc
                 LEFT JOIN players p ON dcc.player_id = p.id
                 WHERE dcc.team_id = ? AND dcc.season_year = ?
                 ORDER BY dcc.cap_charge DESC"
            );
            $stmt->execute([$teamId, $seasonYear]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return [];
        }
    }

    /**
     * Get total dead cap charges for a team in a given season.
     */
    public function getTotalDeadMoney(int $teamId, ?int $seasonYear = null): int
    {
        if ($seasonYear === null) {
            $seasonYear = $this->getCurrentSeasonYear($teamId);
        }

        try {
            $stmt = $this->db->prepare(
                "SELECT COALESCE(SUM(cap_charge), 0) FROM dead_cap_charges
                 WHERE team_id = ? AND season_year = ?"
            );
            $stmt->execute([$teamId, $seasonYear]);
            return (int) $stmt->fetchColumn();
        } catch (\PDOException $e) {
            return 0;
        }
    }

    /**
     * Restructure a contract: convert base salary to signing bonus
     * to lower this year's cap hit by spreading it over remaining years.
     */
    public function restructureContract(int $contractId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM contracts WHERE id = ? AND status = 'active'");
        $stmt->execute([$contractId]);
        $contract = $stmt->fetch();

        if (!$contract) {
            return ['error' => 'Contract not found or not active'];
        }

        $yearsRemaining = (int) $contract['years_remaining'];
        if ($yearsRemaining <= 1) {
            return ['error' => 'Cannot restructure a contract in its final year'];
        }

        $baseSalary = (int) $contract['base_salary'];
        $oldSigningBonus = (int) $contract['signing_bonus'];
        $oldCapHit = (int) $contract['cap_hit'];

        // Convert 60% of base salary to signing bonus (spread over remaining years)
        $convertAmount = (int) ($baseSalary * 0.6);
        $newBaseSalary = $baseSalary - $convertAmount;
        $newSigningBonus = $oldSigningBonus + $convertAmount;

        // New prorated bonus per year (spread over remaining years)
        $proratedBonus = (int) ($newSigningBonus / $yearsRemaining);
        $newCapHit = $newBaseSalary + $proratedBonus;

        // New dead cap = all remaining prorated bonus
        $newDeadCap = $newSigningBonus;

        // More guaranteed money now
        $newGuaranteed = (int) $contract['guaranteed'] + $convertAmount;

        $this->db->prepare(
            "UPDATE contracts SET base_salary = ?, signing_bonus = ?, cap_hit = ?,
             dead_cap = ?, guaranteed = ? WHERE id = ?"
        )->execute([
            $newBaseSalary, $newSigningBonus, $newCapHit,
            $newDeadCap, $newGuaranteed, $contractId,
        ]);

        // Update team cap
        $this->recalculateTeamCap((int) $contract['team_id']);

        return [
            'contract_id'    => $contractId,
            'old_cap_hit'    => $oldCapHit,
            'new_cap_hit'    => $newCapHit,
            'cap_saved'      => $oldCapHit - $newCapHit,
            'new_dead_cap'   => $newDeadCap,
            'warning'        => 'Restructuring increases future dead cap if the player is cut.',
        ];
    }

    // ─── Void Year Contracts ─────────────────────────────────────────

    /**
     * Create a void-year contract.
     *
     * Void years spread the signing bonus proration over (realYears + voidYears),
     * lowering the annual cap hit. When void years are reached the contract
     * automatically voids, the player becomes a free agent, and remaining
     * dead cap accelerates into that season.
     *
     * @return array The created contract row (with id)
     */
    public function createVoidYearContract(
        int $playerId,
        int $teamId,
        int $salary,
        int $realYears,
        int $voidYears
    ): array {
        $now = date('Y-m-d H:i:s');
        $totalProrationYears = $realYears + $voidYears;
        $totalValue = $salary * $realYears;

        // Signing bonus = ~40 % of total value (generous guaranteed money for void deals)
        $signingBonus = (int) ($totalValue * 0.40);
        $guaranteed   = (int) ($totalValue * 0.55);

        // Cap hit = base salary + prorated signing bonus (spread over real + void years)
        $proratedBonusPerYear = (int) ($signingBonus / $totalProrationYears);
        $baseSalary = $salary - $proratedBonusPerYear;
        $capHit     = $baseSalary + $proratedBonusPerYear; // equals $salary but makes the math explicit

        // Dead cap = entire remaining prorated bonus if cut today
        $deadCap = $signingBonus;

        // Terminate any existing active contract
        $this->db->prepare(
            "UPDATE contracts SET status = 'completed', years_remaining = 0 WHERE player_id = ? AND status = 'active'"
        )->execute([$playerId]);

        $this->db->prepare(
            "INSERT INTO contracts
             (player_id, team_id, years_total, years_remaining, salary_annual, cap_hit,
              guaranteed, dead_cap, signing_bonus, base_salary, contract_type, total_value,
              void_years, status, signed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'void_year', ?, ?, 'active', ?)"
        )->execute([
            $playerId, $teamId,
            $realYears, $realYears,
            $salary, $capHit,
            $guaranteed, $deadCap,
            $signingBonus, $baseSalary,
            $totalValue, $voidYears,
            $now,
        ]);

        $contractId = (int) $this->db->lastInsertId();
        $this->recalculateTeamCap($teamId);

        return [
            'id'              => $contractId,
            'player_id'       => $playerId,
            'team_id'         => $teamId,
            'years_total'     => $realYears,
            'years_remaining' => $realYears,
            'void_years'      => $voidYears,
            'salary_annual'   => $salary,
            'cap_hit'         => $capHit,
            'signing_bonus'   => $signingBonus,
            'dead_cap'        => $deadCap,
            'guaranteed'      => $guaranteed,
            'proration_years' => $totalProrationYears,
            'prorated_bonus_per_year' => $proratedBonusPerYear,
        ];
    }

    /**
     * Process void-year expirations for a league.
     *
     * Called when contracts tick down. Any contract whose real years reach 0
     * while void_years > 0 should void: the player becomes a free agent and
     * the remaining prorated signing bonus accelerates as dead cap.
     *
     * @return array Summary of voided contracts
     */
    public function processVoidYearExpirations(int $leagueId): array
    {
        // Find active void-year contracts that have exhausted their real years
        $stmt = $this->db->prepare(
            "SELECT c.* FROM contracts c
             JOIN players p ON c.player_id = p.id
             WHERE p.league_id = ?
               AND c.status = 'active'
               AND c.void_years > 0
               AND c.years_remaining <= 0"
        );
        $stmt->execute([$leagueId]);
        $voided = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $results = [];
        foreach ($voided as $contract) {
            $signingBonus = (int) $contract['signing_bonus'];
            $yearsTotal   = (int) $contract['years_total'];
            $voidYears    = (int) $contract['void_years'];
            $totalProration = $yearsTotal + $voidYears;

            // Remaining dead cap = prorated bonus for each void year that was never "played"
            $proratedPerYear = $totalProration > 0 ? (int) ($signingBonus / $totalProration) : 0;
            $acceleratedDeadCap = $proratedPerYear * $voidYears;

            $teamId = (int) $contract['team_id'];
            $seasonYear = $this->getCurrentSeasonYear($teamId);

            // Record the dead cap charge
            if ($acceleratedDeadCap > 0) {
                $this->recordDeadCapCharge(
                    $teamId, (int) $contract['player_id'], (int) $contract['id'],
                    $this->getLeagueIdForTeam($teamId), $seasonYear,
                    $acceleratedDeadCap, 'void_year', false,
                    "Voided contract acceleration"
                );
            }

            $this->db->prepare(
                "UPDATE contracts SET status = 'voided', dead_cap = ?, years_remaining = 0 WHERE id = ?"
            )->execute([$acceleratedDeadCap, $contract['id']]);

            // Release the player to free agency
            $this->db->prepare("UPDATE players SET team_id = NULL, status = 'free_agent' WHERE id = ?")
                ->execute([$contract['player_id']]);

            $this->recalculateTeamCap($teamId);

            $results[] = [
                'contract_id'         => (int) $contract['id'],
                'player_id'           => (int) $contract['player_id'],
                'team_id'             => $teamId,
                'accelerated_dead_cap' => $acceleratedDeadCap,
            ];
        }

        return $results;
    }

    // ─── Incentive Clauses ───────────────────────────────────────────

    /**
     * Attach an incentive clause to an existing contract.
     *
     * @param string $type       'roster_bonus' | 'performance' | 'playing_time'
     * @param int    $value      Dollar amount of the incentive
     * @param array  $threshold  Trigger condition (stored as JSON)
     *   roster_bonus:  { "week": 1 }  (on roster by week 1 -> auto-triggers)
     *   performance:   { "stat": "rush_yards", "threshold": 1000 }
     *   playing_time:  { "games_played_pct": 0.75 }
     */
    public function addIncentive(int $contractId, string $type, int $value, array $threshold): bool
    {
        $allowed = ['roster_bonus', 'performance', 'playing_time'];
        if (!in_array($type, $allowed, true)) {
            return false;
        }

        $this->db->prepare(
            "UPDATE contracts
             SET has_incentives = 1,
                 incentive_type = ?,
                 incentive_value = ?,
                 incentive_threshold = ?
             WHERE id = ?"
        )->execute([$type, $value, json_encode($threshold), $contractId]);

        return true;
    }

    /**
     * Process all incentive clauses for a league at end of season.
     *
     * All incentives are treated as NLTBE (Not Likely To Be Earned):
     * they only hit the cap when the condition is met.  If triggered,
     * the incentive_value is added to *next year's* cap hit on the contract.
     *
     * @return array List of triggered incentives
     */
    public function processIncentives(int $leagueId, int $seasonYear): array
    {
        $stmt = $this->db->prepare(
            "SELECT c.*, p.id as pid, p.first_name, p.last_name, p.position, p.team_id as player_team_id
             FROM contracts c
             JOIN players p ON c.player_id = p.id
             WHERE p.league_id = ?
               AND c.status = 'active'
               AND c.has_incentives = 1
               AND c.incentive_type IS NOT NULL"
        );
        $stmt->execute([$leagueId]);
        $contracts = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $triggered = [];

        foreach ($contracts as $contract) {
            $type      = $contract['incentive_type'];
            $value     = (int) $contract['incentive_value'];
            $threshold = json_decode($contract['incentive_threshold'] ?? '{}', true);
            $playerId  = (int) $contract['player_id'];
            $teamId    = (int) $contract['team_id'];

            $met = false;

            switch ($type) {
                case 'roster_bonus':
                    // Auto-triggers if the player is still on the team
                    $met = ((int) ($contract['player_team_id'] ?? 0) === $teamId);
                    break;

                case 'performance':
                    $met = $this->checkPerformanceIncentive($playerId, $leagueId, $threshold);
                    break;

                case 'playing_time':
                    $met = $this->checkPlayingTimeIncentive($playerId, $leagueId, $threshold);
                    break;
            }

            if ($met) {
                // Add incentive value to next year's cap hit
                $this->db->prepare(
                    "UPDATE contracts SET cap_hit = cap_hit + ? WHERE id = ?"
                )->execute([$value, $contract['id']]);

                $this->recalculateTeamCap($teamId);

                $triggered[] = [
                    'contract_id' => (int) $contract['id'],
                    'player_id'   => $playerId,
                    'player_name' => $contract['first_name'] . ' ' . $contract['last_name'],
                    'team_id'     => $teamId,
                    'type'        => $type,
                    'value'       => $value,
                    'threshold'   => $threshold,
                ];
            }
        }

        return $triggered;
    }

    /**
     * Check a performance incentive against season stats.
     */
    private function checkPerformanceIncentive(int $playerId, int $leagueId, array $threshold): bool
    {
        $stat = $threshold['stat'] ?? null;
        $target = (int) ($threshold['threshold'] ?? 0);
        if (!$stat || $target <= 0) return false;

        // Map incentive stat names to game_stats columns
        $columnMap = [
            'pass_yards'   => 'pass_yards',
            'rush_yards'   => 'rush_yards',
            'rec_yards'    => 'rec_yards',
            'pass_tds'     => 'pass_tds',
            'rush_tds'     => 'rush_tds',
            'rec_tds'      => 'rec_tds',
            'sacks'        => 'sacks',
            'interceptions'=> 'interceptions_def',
            'receptions'   => 'receptions',
            'tackles'      => 'tackles',
        ];

        $col = $columnMap[$stat] ?? null;
        if (!$col) return false;

        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(gs.{$col}), 0) as total
             FROM game_stats gs
             JOIN games g ON gs.game_id = g.id
             WHERE gs.player_id = ? AND g.league_id = ? AND g.game_type = 'regular'"
        );
        $stmt->execute([$playerId, $leagueId]);
        $total = (int) $stmt->fetchColumn();

        return $total >= $target;
    }

    /**
     * Check a playing-time incentive (games played as pct of 17-game season).
     */
    private function checkPlayingTimeIncentive(int $playerId, int $leagueId, array $threshold): bool
    {
        $requiredPct = (float) ($threshold['games_played_pct'] ?? 0.75);

        // Count distinct games the player appeared in (has any stat line)
        $stmt = $this->db->prepare(
            "SELECT COUNT(DISTINCT gs.game_id) as games
             FROM game_stats gs
             JOIN games g ON gs.game_id = g.id
             WHERE gs.player_id = ? AND g.league_id = ? AND g.game_type = 'regular'"
        );
        $stmt->execute([$playerId, $leagueId]);
        $gamesPlayed = (int) $stmt->fetchColumn();

        $pct = $gamesPlayed / 17.0;
        return $pct >= $requiredPct;
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    /**
     * Determine contract length based on OVR and age.
     */
    private function determineContractYears(int $ovr, int $age, string $type): int
    {
        if ($type === 'rookie') return 4;
        if ($type === 'minimum') return 1;
        if ($type === 'franchise_tag') return 1;

        if ($ovr < 65) return 1;

        // Elite players (90+, under 30): mega deals
        if ($ovr >= 90 && $age < 30) return mt_rand(4, 5);
        // Good starters (80-89)
        if ($ovr >= 80 && $age < 30) return mt_rand(3, 4);
        if ($ovr >= 80 && $age < 33) return mt_rand(2, 3);
        // Average starters (75-79)
        if ($ovr >= 75 && $age < 30) return mt_rand(2, 3);
        if ($ovr >= 75) return mt_rand(1, 2);
        // Backups (70-74)
        if ($ovr >= 70) return mt_rand(1, 2);
        // Depth (65-69)
        return 1;
    }

    /**
     * Determine guaranteed money percentage based on OVR and age.
     */
    private function determineGuaranteedPct(int $ovr, int $age): float
    {
        if ($ovr >= 90 && $age < 30) return 0.60 + (mt_rand(0, 10) / 100); // 60-70%
        if ($ovr >= 80) return 0.40 + (mt_rand(0, 10) / 100); // 40-50%
        if ($ovr >= 75) return 0.25 + (mt_rand(0, 5) / 100);  // 25-30%
        if ($ovr >= 70) return 0.15 + (mt_rand(0, 5) / 100);  // 15-20%
        return 0.05 + (mt_rand(0, 5) / 100); // 5-10%
    }

    /**
     * Randomize years remaining for initial contract generation.
     * Ensures a natural distribution of expiring contracts.
     */
    private function randomizeYearsRemaining(int $yearsTotal, int $age): int
    {
        if ($yearsTotal <= 1) return 1;

        // Young players more likely to have longer remaining
        if ($age <= 25) {
            // 40% chance full, 30% chance total-1, 20% total-2, 10% 1
            $roll = mt_rand(1, 100);
            if ($roll <= 40) return $yearsTotal;
            if ($roll <= 70) return max(1, $yearsTotal - 1);
            if ($roll <= 90) return max(1, $yearsTotal - 2);
            return 1;
        }

        // Prime age: even distribution
        if ($age <= 29) {
            return mt_rand(1, $yearsTotal);
        }

        // Older players: more likely in final year
        if ($age <= 32) {
            $roll = mt_rand(1, 100);
            if ($roll <= 50) return 1;
            if ($roll <= 80) return min(2, $yearsTotal);
            return min(3, $yearsTotal);
        }

        // 33+: almost always final year
        return mt_rand(1, 100) <= 75 ? 1 : min(2, $yearsTotal);
    }

    /**
     * Interpolate the rookie wage scale for a given overall pick number.
     * Returns [total_value, guaranteed].
     */
    private function interpolateRookieScale(int $pick): array
    {
        $scale = self::ROOKIE_SCALE;
        $picks = array_keys($scale);
        sort($picks);

        // Clamp to boundaries
        if ($pick <= $picks[0]) return $scale[$picks[0]];
        if ($pick >= end($picks)) return $scale[end($picks)];

        // Find surrounding picks and interpolate
        $lower = $picks[0];
        $upper = end($picks);

        foreach ($picks as $p) {
            if ($p <= $pick) $lower = $p;
            if ($p >= $pick && $p < $upper) {
                $upper = $p;
                if ($p >= $pick) break;
            }
        }

        // Find the upper bound properly
        foreach ($picks as $p) {
            if ($p > $pick) {
                $upper = $p;
                break;
            }
        }

        if ($lower === $upper) return $scale[$lower];

        $ratio = ($pick - $lower) / ($upper - $lower);
        $totalValue = (int) ($scale[$lower][0] + ($scale[$upper][0] - $scale[$lower][0]) * $ratio);
        $guaranteed = (int) ($scale[$lower][1] + ($scale[$upper][1] - $scale[$lower][1]) * $ratio);

        return [$totalValue, $guaranteed];
    }

    /**
     * Compute dead cap from a contract row (internal helper).
     * Dead cap = unamortized signing bonus + remaining guaranteed base salary.
     */
    private function computeDeadCap(array $contract): int
    {
        $yearsRemaining = (int) $contract['years_remaining'];
        $yearsTotal = (int) $contract['years_total'];
        $signingBonus = (int) $contract['signing_bonus'];
        $guaranteed = (int) $contract['guaranteed'];
        $salaryAnnual = (int) $contract['salary_annual'];
        $voidYears = (int) ($contract['void_years'] ?? 0);

        // Total proration years includes void years
        $totalProrationYears = $yearsTotal + $voidYears;

        // Unamortized signing bonus: proration per year * remaining years (including void)
        $proratedPerYear = $totalProrationYears > 0 ? (int) ($signingBonus / $totalProrationYears) : 0;
        $unamortizedBonus = $proratedPerYear * ($yearsRemaining + $voidYears);

        // Remaining guaranteed base salary (guaranteed minus signing bonus minus salary already paid)
        $yearsPaid = $yearsTotal - $yearsRemaining;
        $salaryPaid = $salaryAnnual * $yearsPaid;
        $guaranteedBaseRemaining = max(0, $guaranteed - $signingBonus - $salaryPaid);

        // Total dead cap is unamortized bonus + remaining guaranteed base salary
        // Use the larger of the two standard calculations for safety
        $totalDeadCap = $unamortizedBonus + $guaranteedBaseRemaining;

        return max(0, $totalDeadCap);
    }

    /**
     * Get a contract by ID.
     */
    private function getContract(int $contractId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM contracts WHERE id = ?");
        $stmt->execute([$contractId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Get the active contract for a player.
     */
    private function getActiveContractByPlayer(int $playerId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM contracts WHERE player_id = ? AND status = 'active' LIMIT 1"
        );
        $stmt->execute([$playerId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Get the current season year for a team's league.
     */
    private function getCurrentSeasonYear(int $teamId): int
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT l.season_year FROM leagues l
                 JOIN teams t ON t.league_id = l.id
                 WHERE t.id = ?"
            );
            $stmt->execute([$teamId]);
            $row = $stmt->fetch();
            return $row ? (int) $row['season_year'] : 2026;
        } catch (\PDOException $e) {
            return 2026;
        }
    }

    /**
     * Get the league_id for a team.
     */
    private function getLeagueIdForTeam(int $teamId): int
    {
        $stmt = $this->db->prepare("SELECT league_id FROM teams WHERE id = ?");
        $stmt->execute([$teamId]);
        $row = $stmt->fetch();
        return $row ? (int) $row['league_id'] : 0;
    }

    /**
     * Record a dead cap charge in the dead_cap_charges table.
     */
    private function recordDeadCapCharge(
        int $teamId,
        int $playerId,
        int $contractId,
        int $leagueId,
        int $seasonYear,
        int $capCharge,
        string $chargeType,
        bool $isPostJune1,
        string $description
    ): void {
        try {
            $this->db->prepare(
                "INSERT INTO dead_cap_charges
                 (team_id, player_id, contract_id, league_id, season_year, cap_charge, charge_type, is_post_june1, description, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                $teamId, $playerId, $contractId, $leagueId, $seasonYear,
                $capCharge, $chargeType, $isPostJune1 ? 1 : 0, $description,
                date('Y-m-d H:i:s'),
            ]);
        } catch (\PDOException $e) {
            // Table may not exist yet; silently skip
        }
    }

    /**
     * Recalculate and update a team's cap_used from active contracts + dead cap charges.
     */
    private function recalculateTeamCap(int $teamId): void
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(cap_hit), 0) FROM contracts WHERE team_id = ? AND status = 'active'"
        );
        $stmt->execute([$teamId]);
        $activeCapUsed = (int) $stmt->fetchColumn();

        // Add current-year dead cap charges from released players
        $deadCapCharges = $this->getTotalDeadMoney($teamId);

        $totalCapUsed = $activeCapUsed + $deadCapCharges;

        $this->db->prepare("UPDATE teams SET cap_used = ? WHERE id = ?")->execute([$totalCapUsed, $teamId]);
    }
}
