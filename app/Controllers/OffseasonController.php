<?php

namespace App\Controllers;

use App\Helpers\Response;
use App\Middleware\AuthMiddleware;
use App\Services\OffseasonEngine;

class OffseasonController
{
    private OffseasonEngine $offseasonEngine;

    public function __construct()
    {
        $this->offseasonEngine = new OffseasonEngine();
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

        $results = $this->offseasonEngine->processOffseason($leagueId);

        if (!$results) {
            Response::error('Unable to process offseason. The season may not be complete.');
            return;
        }

        Response::success('Offseason processed successfully', [
            'retirements' => $results['retirements'] ?? [],
            'free_agents' => $results['free_agents'] ?? [],
            'draft_order' => $results['draft_order'] ?? [],
            'progression' => $results['progression'] ?? [],
            'regression' => $results['regression'] ?? [],
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
}
