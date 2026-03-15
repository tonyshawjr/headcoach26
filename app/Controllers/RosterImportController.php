<?php

namespace App\Controllers;

use App\Database\Connection;
use App\Helpers\Response;
use App\Middleware\AuthMiddleware;
use App\Services\PlayerGenerator;
use App\Services\RosterImporter;
use App\Services\MaddenRosterImporter;

class RosterImportController
{
    private RosterImporter $importer;

    public function __construct()
    {
        $this->importer = new RosterImporter();
    }

    /**
     * POST /api/roster-import/madden — Import players from a Madden 26 ratings CSV.
     *
     * Accepts JSON body with optional "csv_path" field. If omitted, uses the
     * default Madden CSV path bundled with the project.
     */
    public function importMadden(): void
    {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;

        $leagueId = (int) $auth['league_id'];
        if (!$leagueId) {
            Response::error('No league found in session. Please create or join a league first.');
            return;
        }

        $body = Response::getJsonBody();

        // Allow overriding the CSV path; otherwise use the default location
        $defaultPath = dirname(__DIR__, 2) . '/Madden 26 Ratings.xlsx - Week 1 Ratings.csv';
        $csvPath = $body['csv_path'] ?? $defaultPath;

        if (!file_exists($csvPath)) {
            Response::error("Madden CSV file not found at: {$csvPath}");
            return;
        }

        $maddenImporter = new MaddenRosterImporter();

        // Optional: validate-only mode
        if (!empty($body['validate_only'])) {
            $report = $maddenImporter->validate($csvPath);
            Response::json($report);
            return;
        }

        $result = $maddenImporter->import($leagueId, $csvPath);

        if (empty($result['imported']) && !empty($result['errors'])) {
            Response::error('Import failed: ' . ($result['errors'][0] ?? 'unknown error'));
            return;
        }

        // Auto-generate free agents after import
        $faCount = 0;
        try {
            $franchiseCtrl = new FranchiseController();
            $pdo = \App\Database\Connection::getInstance()->getPdo();
            $generator = new \App\Services\PlayerGenerator();
            // Use reflection to call the private method, or just inline the logic
            $faCount = $this->generateFreeAgentPool($pdo, $generator, $leagueId, 150);
        } catch (\Exception $e) {
            // Don't fail the whole import if FA generation fails
        }

        // Try real NFL contracts from Over The Cap data first, fall back to generated
        $contractStats = ['contracts_created' => 0, 'matched' => 0, 'unmatched' => 0];
        try {
            $realImporter = new \App\Services\RealContractImporter();
            $contractStats = $realImporter->importRealContracts($leagueId);
            $contractStats['contracts_created'] = ($contractStats['matched'] ?? 0) + ($contractStats['unmatched'] ?? 0);
        } catch (\Exception $e) {
            // Fall back to generated contracts
            try {
                $contractEngine = new \App\Services\ContractEngine();
                $contractStats = $contractEngine->generateAllContracts($leagueId);
            } catch (\Exception $e2) {
                // Don't fail the whole import
            }
        }

        // Assign player images — try cache first (instant), then ESPN download for any remaining
        $imagesAssigned = 0;
        try {
            $imageService = new \App\Services\PlayerImageService();
            $pdo = \App\Database\Connection::getInstance()->getPdo();

            // Step 1: Instant cache restore (no network needed)
            $cacheResult = $imageService->assignFromCache($pdo, $leagueId);
            $imagesAssigned = $cacheResult['assigned'] ?? 0;

            // Step 2: Download from ESPN for any still missing
            $imgResult = $imageService->assignImages($pdo, $leagueId);
            $imagesAssigned += $imgResult['espn_matched'] ?? 0;
        } catch (\Exception $e) {
            error_log("Image assignment error: " . $e->getMessage());
        }

        // Generate historical career stats for all players
        $historicalStats = 0;
        try {
            $histGen = new \App\Services\HistoricalStatsGenerator();
            $histResult = $histGen->generateForLeague($leagueId);
            $historicalStats = $histResult['generated'] ?? 0;
        } catch (\Exception $e) {
            error_log("Historical stats generation error: " . $e->getMessage());
        }

        $matchInfo = isset($contractStats['matched'])
            ? " ({$contractStats['matched']} real NFL contracts, {$contractStats['unmatched']} generated)"
            : '';

        Response::json([
            'message'       => "Madden roster imported successfully. {$result['imported']} players added. {$faCount} free agents generated. {$contractStats['contracts_created']} contracts created.{$matchInfo}",
            'total_rows'    => $result['total_rows'],
            'imported'      => $result['imported'],
            'skipped'       => $result['skipped'],
            'free_agents'   => $faCount,
            'contracts'     => $contractStats['contracts_created'],
            'real_contracts' => $contractStats['matched'] ?? 0,
            'errors'        => $result['errors'],
            'skip_summary'  => $result['skip_summary'] ?? [],
        ]);
    }

    /**
     * POST /api/roster-import/validate — Validate a CSV/JSON file before importing.
     */
    public function validate(): void
    {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;

        $body = Response::getJsonBody();
        $content = $body['content'] ?? '';
        $format = $body['format'] ?? 'csv';

        if (empty($content)) {
            Response::error('content is required (raw CSV or JSON string)');
            return;
        }

        if (!in_array($format, ['csv', 'json'])) {
            Response::error('format must be csv or json');
            return;
        }

        $report = $this->importer->validate($content, $format);
        Response::json($report);
    }

    /**
     * POST /api/roster-import — Import players from CSV/JSON.
     */
    public function import(): void
    {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;

        $body = Response::getJsonBody();
        $content = $body['content'] ?? '';
        $format = $body['format'] ?? 'csv';
        $filename = $body['filename'] ?? 'import.' . $format;

        if (empty($content)) {
            Response::error('content is required');
            return;
        }

        $leagueId = (int) $auth['league_id'];
        $userId = (int) $auth['user_id'];

        if ($format === 'json') {
            $result = $this->importer->importJson($leagueId, $userId, $content, $filename);
        } else {
            $result = $this->importer->importCsv($leagueId, $userId, $content, $filename);
        }

        if (isset($result['error'])) {
            Response::error($result['error']);
            return;
        }

        Response::json($result);
    }

    /**
     * GET /api/roster-import/history — Get import history.
     */
    public function history(): void
    {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;

        $imports = $this->importer->getImports((int) $auth['league_id']);
        Response::json(['imports' => $imports]);
    }

    /**
     * POST /api/roster-import/fetch-images — Fetch ESPN headshots for all players.
     */
    public function fetchImages(): void
    {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;

        $leagueId = (int) $auth['league_id'];
        if (!$leagueId) {
            Response::error('No league found in session');
            return;
        }

        $imageService = new \App\Services\PlayerImageService();
        $pdo = Connection::getInstance()->getPdo();
        $result = $imageService->assignImages($pdo, $leagueId);

        Response::json([
            'message' => "Images assigned: {$result['espn_matched']} ESPN headshots, {$result['avatars']} generated avatars.",
            'updated' => $result['updated'],
            'espn_matched' => $result['espn_matched'],
            'avatars' => $result['avatars'],
        ]);
    }

    private function generateFreeAgentPool(\PDO $pdo, PlayerGenerator $generator, int $leagueId, int $count): int
    {
        $created = 0;
        $now = date('Y-m-d H:i:s');
        $pool = [];

        $isSqlite = Connection::getInstance()->isSqlite();
        if ($isSqlite) $pdo->exec("PRAGMA foreign_keys = OFF");
        else $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

        while ($created < $count) {
            if (empty($pool)) {
                $anyTeamId = (int) ($pdo->query("SELECT id FROM teams WHERE league_id = {$leagueId} LIMIT 1")->fetchColumn() ?: 0);
                $pool = $generator->generateForTeam($anyTeamId ?: 0, $leagueId);
                shuffle($pool);
            }

            $faPlayer = array_pop($pool);
            $faPlayer['team_id'] = null;
            $faPlayer['status'] = 'free_agent';

            // Lower tier for free agents
            $reduction = mt_rand(5, 15);
            $faPlayer['overall_rating'] = max(42, $faPlayer['overall_rating'] - $reduction);

            $cols = implode(', ', array_keys($faPlayer));
            $placeholders = implode(', ', array_fill(0, count($faPlayer), '?'));
            $stmt = $pdo->prepare("INSERT INTO players ({$cols}) VALUES ({$placeholders})");
            $stmt->execute(array_values($faPlayer));
            $playerId = (int) $pdo->lastInsertId();

            $marketValue = $this->calculateMarketValue($faPlayer);
            $stmt = $pdo->prepare(
                "INSERT INTO free_agents (league_id, player_id, asking_salary, market_value, status, released_at)
                 VALUES (?, ?, ?, ?, 'available', ?)"
            );
            $stmt->execute([$leagueId, $playerId, $marketValue, $marketValue, $now]);
            $created++;
        }

        if ($isSqlite) $pdo->exec("PRAGMA foreign_keys = ON");
        else $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

        return $created;
    }

    private function calculateMarketValue(array $player): int
    {
        $base = 500000;
        $ratingBonus = pow($player['overall_rating'] / 100, 2) * 15000000;
        $posMultiplier = match ($player['position']) {
            'QB' => 2.5, 'DE' => 1.4, 'CB' => 1.3, 'WR' => 1.3, 'OT' => 1.2,
            'LB' => 1.1, 'DT' => 1.1, 'RB' => 1.0, 'TE' => 1.0, 'S' => 1.0,
            'OG' => 0.9, 'C' => 0.9, 'K' => 0.5, 'P' => 0.4,
            default => 1.0,
        };
        $ageFactor = $player['age'] <= 26 ? 1.1 : ($player['age'] >= 31 ? 0.7 : 1.0);
        return max($base, (int) ($ratingBonus * $posMultiplier * $ageFactor));
    }
}
