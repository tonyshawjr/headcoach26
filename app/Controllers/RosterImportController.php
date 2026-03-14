<?php

namespace App\Controllers;

use App\Helpers\Response;
use App\Middleware\AuthMiddleware;
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

        Response::json([
            'message'       => "Madden roster imported successfully. {$result['imported']} players added.",
            'total_rows'    => $result['total_rows'],
            'imported'      => $result['imported'],
            'skipped'       => $result['skipped'],
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
}
