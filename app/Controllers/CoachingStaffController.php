<?php

namespace App\Controllers;

use App\Helpers\Response;
use App\Middleware\AuthMiddleware;
use App\Services\CoachingStaffEngine;

class CoachingStaffController
{
    private CoachingStaffEngine $coachingStaffEngine;

    public function __construct()
    {
        $this->coachingStaffEngine = new CoachingStaffEngine();
    }

    /**
     * GET /api/coaching-staff
     * Get the current user's team coaching staff.
     */
    public function index(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $staff = $this->coachingStaffEngine->getTeamStaff($auth['team_id']);

        Response::json([
            'coaching_staff' => $staff,
        ]);
    }

    /**
     * GET /api/coaching-staff/available
     * Get coaches available on the market.
     */
    public function available(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $leagueId = $auth['league_id'];
        if (!$leagueId) {
            Response::error('No league associated with session');
            return;
        }

        $coaches = $this->coachingStaffEngine->getAvailableCoaches($leagueId);

        Response::json([
            'available_coaches' => $coaches,
        ]);
    }

    /**
     * POST /api/coaching-staff/hire/{id}
     * Hire a coach.
     */
    public function hire(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $coachId = (int) $params['id'];

        $result = $this->coachingStaffEngine->hireCoach($auth['team_id'], $coachId);

        if (!$result) {
            Response::error('Unable to hire coach. Coach may not be available or budget insufficient.');
            return;
        }

        Response::success('Coach hired successfully', ['coach' => $result]);
    }

    /**
     * POST /api/coaching-staff/fire/{id}
     * Fire a coach.
     */
    public function fire(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $coachId = (int) $params['id'];

        $result = $this->coachingStaffEngine->fireCoach($auth['team_id'], $coachId);

        if (!$result) {
            Response::error('Unable to fire coach. Coach may not be on your staff.');
            return;
        }

        Response::success('Coach fired successfully', ['coach_id' => $coachId]);
    }
}
