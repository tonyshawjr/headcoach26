<?php

namespace App\Controllers;

use App\Helpers\Response;
use App\Middleware\AuthMiddleware;
use App\Services\CommissionerService;

class CommissionerController
{
    private CommissionerService $commissionerService;

    public function __construct()
    {
        $this->commissionerService = new CommissionerService();
    }

    /**
     * GET /api/commissioner/settings
     * Get commissioner settings for the league (admin only).
     */
    public function settings(): void
    {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;

        $settings = $this->commissionerService->getSettings($auth['league_id']);

        Response::json([
            'settings' => $settings,
        ]);
    }

    /**
     * PUT /api/commissioner/settings
     * Update commissioner settings (admin only).
     */
    public function updateSettings(): void
    {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;

        $body = Response::getJsonBody();

        $settings = $this->commissionerService->updateSettings($auth['league_id'], $body);

        Response::success('Settings updated', ['settings' => $settings]);
    }

    /**
     * GET /api/commissioner/members
     * Get league members (admin only).
     */
    public function members(): void
    {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;

        $members = $this->commissionerService->getMembers($auth['league_id']);

        Response::json([
            'members' => $members,
        ]);
    }

    /**
     * PUT /api/commissioner/trades/{id}/review
     * Approve or veto a trade (admin only).
     */
    public function reviewTrade(array $params): void
    {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;

        $tradeId = (int) $params['id'];
        $body = Response::getJsonBody();

        if (empty($body['action'])) {
            Response::error('action is required (approved, vetoed)');
            return;
        }

        $action = $body['action'];
        if (!in_array($action, ['approved', 'vetoed'], true)) {
            Response::error('action must be one of: approved, vetoed');
            return;
        }

        $result = $this->commissionerService->reviewTrade(
            $tradeId,
            $auth['league_id'],
            $auth['user_id'],
            $action,
            $body['reason'] ?? null
        );

        if (isset($result['error'])) {
            Response::error($result['error']);
            return;
        }

        Response::success("Trade {$action} successfully", $result);
    }

    /**
     * POST /api/commissioner/force-advance
     * Force advance the league week (admin only).
     */
    public function forceAdvance(): void
    {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;

        $result = $this->commissionerService->forceAdvance($auth['league_id']);

        if (isset($result['error'])) {
            Response::error($result['error']);
            return;
        }

        Response::success($result['message'], ['week' => $result['week']]);
    }

    /**
     * GET /api/commissioner/submissions
     * Get game plan submission status for the current week (admin only).
     */
    public function submissionStatus(): void
    {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;

        $week = isset($_GET['week']) ? (int) $_GET['week'] : null;

        // If no week specified, use league's current week
        if (!$week) {
            $db = \App\Database\Connection::getInstance()->getPdo();
            $stmt = $db->prepare("SELECT current_week FROM leagues WHERE id = ?");
            $stmt->execute([$auth['league_id']]);
            $week = (int) $stmt->fetchColumn();
        }

        $submissions = $this->commissionerService->getSubmissionStatus($auth['league_id'], $week);

        Response::json([
            'week' => $week,
            'submissions' => $submissions,
        ]);
    }

    /**
     * GET /api/commissioner/activity
     * Get activity dashboard data for all teams (admin only).
     */
    public function activity(): void
    {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;

        $activity = $this->commissionerService->getActivity($auth['league_id']);

        Response::json([
            'activity' => $activity,
        ]);
    }

    /**
     * POST /api/commissioner/replace-coach
     * Replace a coach (toggle human/AI) on a team (admin only).
     */
    public function replaceCoach(): void
    {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;

        $body = Response::getJsonBody();

        if (empty($body['team_id'])) {
            Response::error('team_id is required');
            return;
        }

        if (empty($body['action']) || !in_array($body['action'], ['to_ai', 'to_human'], true)) {
            Response::error('action is required (to_ai or to_human)');
            return;
        }

        $result = $this->commissionerService->replaceCoach(
            $auth['league_id'],
            (int) $body['team_id'],
            $body['action']
        );

        if (isset($result['error'])) {
            Response::error($result['error']);
            return;
        }

        Response::success($result['message'], $result);
    }

    /**
     * POST /api/commissioner/send-reminders
     * Send reminder notifications to coaches with missing game plans (admin only).
     */
    public function sendReminders(): void
    {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;

        $result = $this->commissionerService->sendReminders($auth['league_id']);

        if (isset($result['error'])) {
            Response::error($result['error']);
            return;
        }

        Response::success($result['message'], ['count' => $result['count']]);
    }
}
