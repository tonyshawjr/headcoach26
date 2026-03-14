<?php

namespace App\Controllers;

use App\Helpers\Response;
use App\Middleware\AuthMiddleware;
use App\Services\InfluenceEngine;

class OwnerOfficeController
{
    /**
     * GET /api/owner-office
     *
     * Returns influence, job_security, owner expectations, recent changes
     * for the currently authenticated coach.
     */
    public function index(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $coachId = $auth['coach_id'] ?? null;
        if (!$coachId) {
            Response::error('No coach associated with this session', 400);
            return;
        }

        $engine = new InfluenceEngine();
        $data = $engine->getOwnerOffice((int) $coachId);

        if (isset($data['error'])) {
            Response::error($data['error'], 404);
            return;
        }

        Response::json($data);
    }
}
