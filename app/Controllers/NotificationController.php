<?php

namespace App\Controllers;

use App\Helpers\Response;
use App\Middleware\AuthMiddleware;
use App\Services\NotificationService;

class NotificationController
{
    private NotificationService $notificationService;

    public function __construct()
    {
        $this->notificationService = new NotificationService();
    }

    /**
     * GET /api/notifications
     * Get the authenticated user's notifications.
     */
    public function index(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $unreadOnly = isset($_GET['unread_only']) && $_GET['unread_only'] == '1';

        $notifications = $this->notificationService->getForUser($auth['user_id'], $unreadOnly);

        Response::json([
            'notifications' => $notifications,
        ]);
    }

    /**
     * PUT /api/notifications/{id}/read
     * Mark a single notification as read.
     */
    public function markRead(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $notificationId = (int) $params['id'];

        $marked = $this->notificationService->markRead($notificationId, $auth['user_id']);

        if (!$marked) {
            Response::notFound('Notification not found');
            return;
        }

        Response::success('Notification marked as read');
    }

    /**
     * PUT /api/notifications/read-all
     * Mark all notifications as read for the authenticated user.
     */
    public function markAllRead(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $count = $this->notificationService->markAllRead($auth['user_id']);

        Response::success('All notifications marked as read', ['count' => $count]);
    }

    /**
     * GET /api/notifications/count
     * Get the unread notification count for the authenticated user.
     */
    public function unreadCount(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $count = $this->notificationService->getUnreadCount($auth['user_id']);

        Response::json([
            'unread_count' => $count,
        ]);
    }
}
