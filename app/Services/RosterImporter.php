<?php

namespace App\Services;

use App\Database\Connection;

class RosterImporter
{
    private \PDO $db;

    private array $requiredFields = ['first_name', 'last_name', 'position'];
    private array $validPositions = [
        'QB', 'RB', 'WR', 'TE', 'OT', 'OG', 'C',
        'DE', 'DT', 'LB', 'CB', 'S', 'K', 'P', 'LS',
    ];

    /**
     * All 53 stat columns in the DB. CSV headers will be normalized and matched against these.
     */
    private const STAT_COLUMNS = [
        // Physical
        'speed', 'acceleration', 'agility', 'jumping', 'stamina', 'strength', 'toughness', 'awareness',
        // Ball Carrier
        'bc_vision', 'break_tackle', 'carrying', 'change_of_direction',
        'juke_move', 'spin_move', 'stiff_arm', 'trucking',
        // Receiving
        'catch_in_traffic', 'catching', 'deep_route_running',
        'medium_route_running', 'short_route_running', 'spectacular_catch', 'release',
        // Blocking
        'impact_blocking', 'lead_block', 'pass_block', 'pass_block_finesse',
        'pass_block_power', 'run_block', 'run_block_finesse', 'run_block_power',
        // Defense
        'block_shedding', 'finesse_moves', 'hit_power', 'man_coverage',
        'play_recognition', 'power_moves', 'press', 'pursuit', 'tackle', 'zone_coverage',
        // Quarterback
        'break_sack', 'play_action', 'throw_accuracy_deep', 'throw_accuracy_mid',
        'throw_accuracy_short', 'throw_on_the_run', 'throw_power', 'throw_under_pressure',
        // Kicking
        'kick_accuracy', 'kick_power', 'kick_return',
    ];

    /**
     * Bio/metadata columns beyond stats.
     */
    private const META_COLUMNS = [
        'height', 'weight', 'handedness', 'years_pro', 'archetype', 'college',
        'birthdate', 'position_type', 'injury_prone', 'running_style',
    ];

    /**
     * Header aliases that map common variations to canonical column names.
     */
    private const HEADER_ALIASES = [
        'overall' => 'overall_rating',
        'ovr' => 'overall_rating',
        'rating' => 'overall_rating',
        'spd' => 'speed',
        'acc' => 'acceleration',
        'agi' => 'agility',
        'jmp' => 'jumping',
        'sta' => 'stamina',
        'str' => 'strength',
        'tgh' => 'toughness',
        'awr' => 'awareness',
        'bcv' => 'bc_vision',
        'btk' => 'break_tackle',
        'car' => 'carrying',
        'cod' => 'change_of_direction',
        'jkm' => 'juke_move',
        'spm' => 'spin_move',
        'sfa' => 'stiff_arm',
        'trk' => 'trucking',
        'cit' => 'catch_in_traffic',
        'cth' => 'catching',
        'drr' => 'deep_route_running',
        'mrr' => 'medium_route_running',
        'srr' => 'short_route_running',
        'spc' => 'spectacular_catch',
        'rel' => 'release',
        'ibk' => 'impact_blocking',
        'lbk' => 'lead_block',
        'pbk' => 'pass_block',
        'pbf' => 'pass_block_finesse',
        'pbp' => 'pass_block_power',
        'rbk' => 'run_block',
        'rbf' => 'run_block_finesse',
        'rbp' => 'run_block_power',
        'bsh' => 'block_shedding',
        'fmv' => 'finesse_moves',
        'hpw' => 'hit_power',
        'mcv' => 'man_coverage',
        'prc' => 'play_recognition',
        'pmv' => 'power_moves',
        'prs' => 'press',
        'pur' => 'pursuit',
        'tak' => 'tackle',
        'zcv' => 'zone_coverage',
        'bsk' => 'break_sack',
        'pac' => 'play_action',
        'tad' => 'throw_accuracy_deep',
        'tam' => 'throw_accuracy_mid',
        'tas' => 'throw_accuracy_short',
        'tor' => 'throw_on_the_run',
        'thp' => 'throw_power',
        'tup' => 'throw_under_pressure',
        'kac' => 'kick_accuracy',
        'kpw' => 'kick_power',
        'krt' => 'kick_return',
        'number' => 'jersey_number',
        'team_abbreviation' => 'team',
    ];

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
    }

    /**
     * Normalize a CSV header to its canonical snake_case DB column name.
     */
    private function normalizeHeader(string $header): string
    {
        $header = trim($header);
        // camelCase to snake_case
        $header = preg_replace('/([a-z])([A-Z])/', '$1_$2', $header);
        // Replace spaces, hyphens with underscores
        $header = preg_replace('/[\s\-]+/', '_', $header);
        $header = strtolower($header);
        // Check aliases
        return self::HEADER_ALIASES[$header] ?? $header;
    }

    /**
     * Import players from CSV content.
     */
    public function importCsv(int $leagueId, int $userId, string $csvContent, string $filename): array
    {
        $lines = explode("\n", trim($csvContent));
        if (count($lines) < 2) {
            return ['error' => 'CSV file must have a header row and at least one data row'];
        }

        $rawHeaders = str_getcsv(separator: ',', enclosure: '"', escape: '', string: array_shift($lines));
        $headers = array_map([$this, 'normalizeHeader'], $rawHeaders);

        // Validate required headers
        foreach ($this->requiredFields as $field) {
            if (!in_array($field, $headers)) {
                return ['error' => "Missing required column: {$field}"];
            }
        }

        return $this->processRows($leagueId, $userId, $headers, $lines, $filename, 'csv');
    }

    /**
     * Import players from JSON content.
     */
    public function importJson(int $leagueId, int $userId, string $jsonContent, string $filename): array
    {
        $data = json_decode($jsonContent, true);
        if (!$data || !is_array($data)) {
            return ['error' => 'Invalid JSON format'];
        }

        if (isset($data['players']) && is_array($data['players'])) {
            $data = $data['players'];
        }

        if (empty($data)) {
            return ['error' => 'No player data found'];
        }

        $firstRow = $data[0];
        $rawHeaders = array_keys($firstRow);
        $headers = array_map([$this, 'normalizeHeader'], $rawHeaders);

        foreach ($this->requiredFields as $field) {
            if (!in_array($field, $headers)) {
                return ['error' => "Missing required field: {$field}"];
            }
        }

        $lines = array_map(function ($row) use ($rawHeaders) {
            return implode(',', array_map(function ($h) use ($row) {
                $val = $row[$h] ?? '';
                // Escape commas in values
                if (str_contains((string) $val, ',')) {
                    return '"' . str_replace('"', '""', $val) . '"';
                }
                return $val;
            }, $rawHeaders));
        }, $data);

        return $this->processRows($leagueId, $userId, $headers, $lines, $filename, 'json');
    }

    /**
     * Process rows and import players.
     */
    private function processRows(int $leagueId, int $userId, array $headers, array $lines, string $filename, string $format): array
    {
        $imported = 0;
        $skipped = 0;
        $errors = [];
        $totalRows = count($lines);

        // Create import record
        $now = date('Y-m-d H:i:s');
        $this->db->prepare(
            "INSERT INTO roster_imports (league_id, user_id, filename, format, total_rows, status, created_at)
             VALUES (?, ?, ?, ?, ?, 'processing', ?)"
        )->execute([$leagueId, $userId, $filename, $format, $totalRows, $now]);
        $importId = (int) $this->db->lastInsertId();

        // Get team mapping (abbreviation -> id)
        $stmt = $this->db->prepare("SELECT abbreviation, id FROM teams WHERE league_id = ?");
        $stmt->execute([$leagueId]);
        $teamMap = [];
        while ($row = $stmt->fetch()) {
            $teamMap[strtoupper($row['abbreviation'])] = (int) $row['id'];
        }

        // Initialize PlayerGenerator for default stats
        $generator = new PlayerGenerator();

        // Build header index for quick lookup
        $headerIndex = array_flip($headers);

        foreach ($lines as $lineNum => $line) {
            if (empty(trim($line))) continue;

            if ($format === 'csv') {
                $values = str_getcsv(separator: ',', enclosure: '"', escape: '', string: $line);
            } else {
                $values = explode(',', $line);
            }

            if (count($values) < count($headers)) {
                $errors[] = "Row " . ($lineNum + 2) . ": insufficient columns";
                $skipped++;
                continue;
            }

            $row = [];
            foreach ($headers as $i => $h) {
                $row[$h] = trim($values[$i] ?? '');
            }

            // Validate required fields
            $valid = true;
            foreach ($this->requiredFields as $field) {
                if (empty($row[$field])) {
                    $errors[] = "Row " . ($lineNum + 2) . ": missing {$field}";
                    $valid = false;
                    break;
                }
            }
            if (!$valid) {
                $skipped++;
                continue;
            }

            // Validate position
            $position = strtoupper($row['position']);
            if (!in_array($position, $this->validPositions)) {
                $errors[] = "Row " . ($lineNum + 2) . ": invalid position '{$position}'";
                $skipped++;
                continue;
            }

            // Resolve team
            $teamId = null;
            if (!empty($row['team'])) {
                $abbr = strtoupper($row['team']);
                $teamId = $teamMap[$abbr] ?? null;
                if (!$teamId) {
                    $errors[] = "Row " . ($lineNum + 2) . ": unknown team '{$abbr}'";
                    $skipped++;
                    continue;
                }
            }

            // Core fields
            $age = max(20, min(45, (int) ($row['age'] ?? mt_rand(22, 30))));
            $rating = max(40, min(99, (int) ($row['overall_rating'] ?? mt_rand(55, 80))));
            $potential = $row['potential'] ?? $this->inferPotential($rating, $age);
            $archetype = $row['archetype'] ?? null;

            // Get default stats from PlayerGenerator
            $defaultStats = $generator->getDefaultStats($position, $archetype, $rating);

            // Build player data
            $playerData = [
                'league_id' => $leagueId,
                'team_id' => $teamId,
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'position' => $position,
                'age' => $age,
                'overall_rating' => $rating,
                'potential' => $potential,
                'status' => $teamId ? 'active' : 'free_agent',
                'jersey_number' => (int) ($row['jersey_number'] ?? mt_rand(1, 99)),
                'college' => $row['college'] ?? 'Unknown',
                'created_at' => $now,
            ];

            // Meta columns
            if (!empty($row['height'])) $playerData['height'] = (int) $row['height'];
            if (!empty($row['weight'])) $playerData['weight'] = (int) $row['weight'];
            if (!empty($row['handedness'])) $playerData['handedness'] = (int) $row['handedness'];
            if (!empty($row['years_pro'])) $playerData['years_pro'] = (int) $row['years_pro'];
            if (!empty($row['birthdate'])) $playerData['birthdate'] = $row['birthdate'];
            if (!empty($row['position_type'])) $playerData['position_type'] = $row['position_type'];
            if (!empty($row['injury_prone'])) $playerData['injury_prone'] = (int) $row['injury_prone'];
            if (!empty($row['running_style'])) $playerData['running_style'] = $row['running_style'];
            if ($archetype) $playerData['archetype'] = $archetype;

            // All 53 stat columns — use CSV value if present, otherwise default
            foreach (self::STAT_COLUMNS as $stat) {
                if (isset($row[$stat]) && $row[$stat] !== '') {
                    $playerData[$stat] = max(25, min(99, (int) $row[$stat]));
                } else {
                    $playerData[$stat] = $defaultStats[$stat] ?? 50;
                }
            }

            try {
                $columns = implode(', ', array_keys($playerData));
                $placeholders = implode(', ', array_fill(0, count($playerData), '?'));
                $this->db->prepare(
                    "INSERT INTO players ({$columns}) VALUES ({$placeholders})"
                )->execute(array_values($playerData));
                $imported++;
            } catch (\PDOException $e) {
                $errors[] = "Row " . ($lineNum + 2) . ": DB error — " . $e->getMessage();
                $skipped++;
            }
        }

        // Create free_agents entries for players imported without a team
        $faStmt = $this->db->prepare(
            "INSERT INTO free_agents (league_id, player_id, asking_salary, market_value, status, released_at)
             SELECT ?, id, 750000, 750000, 'available', ?
             FROM players WHERE league_id = ? AND (team_id IS NULL OR status = 'free_agent')
             AND id NOT IN (SELECT player_id FROM free_agents WHERE league_id = ?)"
        );
        $faStmt->execute([$leagueId, date('Y-m-d H:i:s'), $leagueId, $leagueId]);

        // Update import record
        $this->db->prepare(
            "UPDATE roster_imports SET imported = ?, skipped = ?, errors = ?, status = 'completed', completed_at = ? WHERE id = ?"
        )->execute([$imported, $skipped, json_encode(array_slice($errors, 0, 50)), date('Y-m-d H:i:s'), $importId]);

        return [
            'import_id' => $importId,
            'total_rows' => $totalRows,
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => array_slice($errors, 0, 20),
        ];
    }

    /**
     * Get import history for a league.
     */
    public function getImports(int $leagueId): array
    {
        $stmt = $this->db->prepare(
            "SELECT ri.*, u.username FROM roster_imports ri
             JOIN users u ON ri.user_id = u.id
             WHERE ri.league_id = ?
             ORDER BY ri.created_at DESC"
        );
        $stmt->execute([$leagueId]);
        $rows = $stmt->fetchAll();
        return array_map(function ($r) {
            $r['errors'] = json_decode($r['errors'] ?? '[]', true);
            return $r;
        }, $rows);
    }

    /**
     * Generate a validation report for a CSV/JSON before importing.
     */
    public function validate(string $content, string $format): array
    {
        $report = ['valid' => true, 'total_rows' => 0, 'warnings' => [], 'errors' => []];

        if ($format === 'csv') {
            $lines = explode("\n", trim($content));
            if (count($lines) < 2) {
                $report['valid'] = false;
                $report['errors'][] = 'File must have header row + at least one data row';
                return $report;
            }

            $rawHeaders = str_getcsv(separator: ',', enclosure: '"', escape: '', string: array_shift($lines));
            $headers = array_map([$this, 'normalizeHeader'], $rawHeaders);
            $report['total_rows'] = count($lines);
            $report['headers'] = $headers;
        } else {
            $data = json_decode($content, true);
            if (!$data) {
                $report['valid'] = false;
                $report['errors'][] = 'Invalid JSON format';
                return $report;
            }
            if (isset($data['players'])) $data = $data['players'];
            $report['total_rows'] = count($data);
            $rawHeaders = !empty($data) ? array_keys($data[0]) : [];
            $headers = array_map([$this, 'normalizeHeader'], $rawHeaders);
            $report['headers'] = $headers;
        }

        // Check required fields
        foreach ($this->requiredFields as $field) {
            if (!in_array($field, $headers)) {
                $report['valid'] = false;
                $report['errors'][] = "Missing required column: {$field}";
            }
        }

        // All recognized optional columns
        $allOptional = array_merge(
            ['age', 'overall_rating', 'team', 'potential', 'jersey_number'],
            self::STAT_COLUMNS,
            self::META_COLUMNS
        );

        // Count recognized stat columns
        $recognizedStats = array_intersect(self::STAT_COLUMNS, $headers);
        $recognizedMeta = array_intersect(self::META_COLUMNS, $headers);

        if (count($recognizedStats) > 0) {
            $report['warnings'][] = count($recognizedStats) . " of " . count(self::STAT_COLUMNS) . " stat columns detected. Missing stats will use position-based defaults.";
        } else {
            $report['warnings'][] = "No stat columns found. All stats will be auto-generated based on position and overall rating.";
        }

        // Warn about unrecognized columns
        $knownCols = array_merge($this->requiredFields, $allOptional);
        $unrecognized = array_diff($headers, $knownCols);
        if (!empty($unrecognized)) {
            $report['warnings'][] = "Unrecognized columns (will be ignored): " . implode(', ', array_slice($unrecognized, 0, 10));
        }

        return $report;
    }

    private function inferPotential(int $rating, int $age): string
    {
        if ($age <= 23 && $rating >= 75) return 'superstar';
        if ($age <= 25 && $rating >= 70) return 'star';
        if ($age <= 27) return 'normal';
        return 'slow';
    }
}
