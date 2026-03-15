<?php

namespace App\Services;

use App\Database\Connection;

/**
 * RealContractImporter — Matches scraped Over The Cap contract data
 * to our imported Madden players and creates real NFL contracts.
 *
 * Flow:
 * 1. Load all OTC contract JSON files from storage/
 * 2. Build a name → contract lookup (NFL team → player name → contract data)
 * 3. For each player in our DB, find their real NFL team via the Madden import mapping
 * 4. Match by name (fuzzy) and position
 * 5. Create contract with real cap hit, base salary, signing bonus, guaranteed
 * 6. Players not matched get generated contracts as fallback
 */
class RealContractImporter
{
    private \PDO $db;

    // NFL team slug → our abbreviation mapping
    private const NFL_TEAM_MAP = [
        'arizona-cardinals' => 'PHX',
        'atlanta-falcons' => 'ATL',
        'baltimore-ravens' => 'BAL',
        'buffalo-bills' => 'BUF',
        'carolina-panthers' => 'CAR',
        'chicago-bears' => 'CHI',
        'cincinnati-bengals' => 'CIN',
        'cleveland-browns' => 'CLE',
        'dallas-cowboys' => 'DAL',
        'denver-broncos' => 'DEN',
        'detroit-lions' => 'DET',
        'green-bay-packers' => 'GB',
        'houston-texans' => 'HOU',
        'indianapolis-colts' => 'IND',
        'jacksonville-jaguars' => 'JAX',
        'kansas-city-chiefs' => 'KC',
        'las-vegas-raiders' => 'LV',
        'los-angeles-chargers' => 'LAS',
        'los-angeles-rams' => 'LAQ',
        'miami-dolphins' => 'MIA',
        'minnesota-vikings' => 'MIN',
        'new-england-patriots' => 'NE',
        'new-orleans-saints' => 'NO',
        'new-york-giants' => 'NYE',
        'new-york-jets' => 'NYT',
        'philadelphia-eagles' => 'PHI',
        'pittsburgh-steelers' => 'PIT',
        'san-francisco-49ers' => 'SF',
        'seattle-seahawks' => 'SEA',
        'tampa-bay-buccaneers' => 'TB',
        'tennessee-titans' => 'NSH',
        'washington-commanders' => 'WAS',
    ];

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
    }

    /**
     * Import real contracts from scraped OTC data.
     * Call this AFTER Madden roster import and INSTEAD of generateAllContracts.
     */
    public function importRealContracts(int $leagueId): array
    {
        // Load all OTC data
        $otcData = $this->loadOtcData();
        if (empty($otcData)) {
            return ['matched' => 0, 'unmatched' => 0, 'error' => 'No OTC data files found. Run the scraper first.'];
        }

        // Build lookup: our_abbreviation → [normalized_name → contract_data]
        $contractLookup = [];
        $totalOtcPlayers = 0;
        foreach ($otcData as $nflSlug => $players) {
            $ourAbbr = self::NFL_TEAM_MAP[$nflSlug] ?? null;
            if (!$ourAbbr || !is_array($players)) continue;

            foreach ($players as $p) {
                if (!isset($p['name'])) continue;
                $normalized = $this->normalizeName($p['name']);
                $contractLookup[$ourAbbr][$normalized] = $p;
                $totalOtcPlayers++;
            }
        }

        // Get our team abbreviation → team_id map
        $teamMap = [];
        $stmt = $this->db->prepare("SELECT id, abbreviation FROM teams WHERE league_id = ?");
        $stmt->execute([$leagueId]);
        while ($row = $stmt->fetch()) {
            $teamMap[$row['abbreviation']] = (int) $row['id'];
        }

        // Delete existing contracts for this league
        $this->db->prepare(
            "DELETE FROM contracts WHERE team_id IN (SELECT id FROM teams WHERE league_id = ?)"
        )->execute([$leagueId]);

        // Get all active players
        $stmt = $this->db->prepare(
            "SELECT p.*, t.abbreviation as team_abbr FROM players p
             JOIN teams t ON p.team_id = t.id
             WHERE p.league_id = ? AND p.status = 'active'"
        );
        $stmt->execute([$leagueId]);
        $players = $stmt->fetchAll();

        $matched = 0;
        $unmatched = 0;
        $fallbackEngine = new ContractEngine();

        foreach ($players as $player) {
            $teamAbbr = $player['team_abbr'];
            $playerName = $this->normalizeName($player['first_name'] . ' ' . $player['last_name']);

            // Try exact match first
            $otcContract = $contractLookup[$teamAbbr][$playerName] ?? null;

            // Try fuzzy match if exact fails
            if (!$otcContract && isset($contractLookup[$teamAbbr])) {
                $otcContract = $this->fuzzyMatch($playerName, $contractLookup[$teamAbbr]);
            }

            if ($otcContract) {
                $this->createRealContract($player, $otcContract);
                $matched++;
            } else {
                // Fallback to generated contract
                $contract = $fallbackEngine->generateContractForPlayer($player);
                $this->insertContract($player, $contract);
                $unmatched++;
            }
        }

        // Update team cap_used
        foreach ($teamMap as $abbr => $teamId) {
            $capUsed = (int) $this->db->query(
                "SELECT COALESCE(SUM(cap_hit), 0) FROM contracts WHERE team_id = {$teamId} AND status = 'active'"
            )->fetchColumn();
            $this->db->prepare("UPDATE teams SET cap_used = ? WHERE id = ?")->execute([$capUsed, $teamId]);
        }

        return [
            'matched' => $matched,
            'unmatched' => $unmatched,
            'total_otc_players' => $totalOtcPlayers,
            'total_our_players' => count($players),
        ];
    }

    /**
     * Load all OTC batch JSON files from storage.
     */
    private function loadOtcData(): array
    {
        $storagePath = dirname(__DIR__, 2) . '/storage';
        $allData = [];

        for ($i = 1; $i <= 4; $i++) {
            $file = $storagePath . "/otc_contracts_batch{$i}.json";
            if (file_exists($file)) {
                $data = json_decode(file_get_contents($file), true);
                if (is_array($data)) {
                    $allData = array_merge($allData, $data);
                }
            }
        }

        // Also check for a single combined file
        $combinedFile = $storagePath . '/otc_contracts.json';
        if (file_exists($combinedFile)) {
            $data = json_decode(file_get_contents($combinedFile), true);
            if (is_array($data)) {
                $allData = array_merge($allData, $data);
            }
        }

        return $allData;
    }

    /**
     * Create a contract from real OTC data.
     */
    private function createRealContract(array $player, array $otc): void
    {
        $capHit = $this->parseMoneyValue($otc['cap_hit'] ?? 0);
        $baseSalary = $this->parseMoneyValue($otc['base_salary'] ?? 0);
        $signingBonus = $this->parseMoneyValue($otc['signing_bonus'] ?? 0);
        $guaranteed = $this->parseMoneyValue($otc['guaranteed'] ?? 0);
        $deadCap = abs($this->parseMoneyValue($otc['dead_cap'] ?? 0));

        // If cap_hit is 0 or very low, use base salary
        if ($capHit < 500000) $capHit = max($baseSalary, 1100000);
        if ($baseSalary < 500000) $baseSalary = max($capHit - $signingBonus, 1100000);

        // Estimate years remaining from contract structure
        $yearsRemaining = $this->estimateYearsRemaining($player, $capHit, $signingBonus);
        $yearsTotal = $yearsRemaining + mt_rand(0, 2); // original was longer

        $salary = $capHit; // Use cap hit as the annual salary for simplicity
        $totalValue = $salary * $yearsTotal;

        // Determine contract type
        $age = (int) $player['age'];
        $contractType = 'veteran';
        if ($age <= 24 && $capHit < 15000000) {
            $contractType = 'rookie';
        }

        $now = date('Y-m-d H:i:s');
        $this->db->prepare(
            "INSERT INTO contracts (player_id, team_id, years_total, years_remaining, salary_annual,
             cap_hit, guaranteed, dead_cap, signing_bonus, base_salary, contract_type, total_value, status, signed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)"
        )->execute([
            (int) $player['id'], (int) $player['team_id'],
            $yearsTotal, $yearsRemaining,
            $salary, $capHit, $guaranteed, $deadCap,
            $signingBonus, $baseSalary,
            $contractType, $totalValue, $now,
        ]);
    }

    /**
     * Insert a fallback generated contract.
     */
    private function insertContract(array $player, array $contract): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->prepare(
            "INSERT INTO contracts (player_id, team_id, years_total, years_remaining, salary_annual,
             cap_hit, guaranteed, dead_cap, signing_bonus, base_salary, contract_type, total_value, status, signed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)"
        )->execute([
            (int) $player['id'], (int) $player['team_id'],
            $contract['years_total'], $contract['years_remaining'],
            $contract['salary_annual'], $contract['cap_hit'],
            $contract['guaranteed'], $contract['dead_cap'],
            $contract['signing_bonus'] ?? 0, $contract['base_salary'] ?? 0,
            $contract['contract_type'] ?? 'veteran', $contract['total_value'] ?? 0,
            $now,
        ]);
    }

    /**
     * Estimate years remaining based on player age and contract structure.
     */
    private function estimateYearsRemaining(array $player, int $capHit, int $signingBonus): int
    {
        $age = (int) $player['age'];

        // Rookies on rookie deals (age 22-24, lower cap hit)
        if ($age <= 23 && $capHit < 12000000) {
            return mt_rand(2, 4); // early in rookie deal
        }
        if ($age <= 24 && $capHit < 15000000) {
            return mt_rand(1, 3);
        }

        // Big signing bonus = longer deal (spread over years)
        if ($signingBonus > $capHit * 2) {
            return mt_rand(3, 5);
        }
        if ($signingBonus > $capHit) {
            return mt_rand(2, 4);
        }

        // Veterans
        if ($age >= 32) return mt_rand(1, 2);
        if ($age >= 29) return mt_rand(1, 3);
        if ($age >= 26) return mt_rand(2, 4);

        return mt_rand(2, 4);
    }

    /**
     * Normalize a player name for matching.
     */
    private function normalizeName(string $name): string
    {
        $name = strtolower(trim($name));
        // Remove suffixes
        $name = preg_replace('/\s+(jr\.?|sr\.?|ii|iii|iv|v)$/i', '', $name);
        // Remove periods and extra spaces
        $name = str_replace('.', '', $name);
        $name = preg_replace('/\s+/', ' ', $name);
        return $name;
    }

    /**
     * Fuzzy match a player name against OTC data.
     */
    private function fuzzyMatch(string $playerName, array $otcTeam): ?array
    {
        $parts = explode(' ', $playerName);
        $lastName = end($parts);

        // Try last name match + first initial
        foreach ($otcTeam as $otcName => $contract) {
            $otcParts = explode(' ', $otcName);
            $otcLast = end($otcParts);

            if ($lastName === $otcLast) {
                // Last names match — check first name similarity
                $firstNameSimilarity = similar_text($parts[0] ?? '', $otcParts[0] ?? '');
                if ($firstNameSimilarity >= 2) {
                    return $contract;
                }
            }
        }

        // Try contains match (handles name variations like "D.J." vs "DJ")
        $cleanPlayer = str_replace([' ', '-', "'"], '', $playerName);
        foreach ($otcTeam as $otcName => $contract) {
            $cleanOtc = str_replace([' ', '-', "'"], '', $otcName);
            if ($cleanPlayer === $cleanOtc) {
                return $contract;
            }
        }

        return null;
    }

    /**
     * Parse money values that might be strings like "$1,234,567" or integers.
     */
    private function parseMoneyValue($value): int
    {
        if (is_int($value) || is_float($value)) return (int) $value;
        if (!is_string($value)) return 0;

        // Remove $, commas, parentheses, spaces
        $clean = preg_replace('/[$,\s()]+/', '', $value);

        // Handle negative (parentheses or minus)
        $negative = str_contains($value, '(') || str_starts_with($clean, '-');
        $clean = ltrim($clean, '-');

        $val = (int) $clean;
        return $negative ? -$val : $val;
    }
}
