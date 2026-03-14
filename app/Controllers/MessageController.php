<?php

namespace App\Controllers;

use App\Helpers\Response;
use App\Middleware\AuthMiddleware;
use App\Services\MessageService;

class MessageController
{
    private MessageService $messageService;

    public function __construct()
    {
        $this->messageService = new MessageService();
    }

    /**
     * GET /api/messages
     * Get messages for a channel.
     */
    public function index(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $channel = $_GET['channel'] ?? 'general';
        $before = isset($_GET['before']) ? (int) $_GET['before'] : 0;

        $messages = $this->messageService->getMessages($auth['league_id'], $channel, 50, $before);

        Response::json([
            'channel' => $channel,
            'messages' => $messages,
        ]);
    }

    /**
     * POST /api/messages
     * Post a message to a channel.
     */
    public function post(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $body = Response::getJsonBody();

        if (empty($body['body'])) {
            Response::error('body is required');
            return;
        }

        $channel = $body['channel'] ?? 'general';

        $messageId = $this->messageService->post(
            $auth['league_id'],
            $auth['user_id'],
            $body['body'],
            $channel,
            $auth['coach_id']
        );

        if (!$messageId) {
            Response::error('Message must be between 1 and 2000 characters');
            return;
        }

        Response::success('Message posted', ['message_id' => $messageId]);
    }

    /**
     * PUT /api/messages/{id}/pin
     * Toggle pin on a message (admin only).
     */
    public function pin(array $params): void
    {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;

        $messageId = (int) $params['id'];

        $toggled = $this->messageService->togglePin($messageId, $auth['league_id']);

        if (!$toggled) {
            Response::notFound('Message not found');
            return;
        }

        Response::success('Pin toggled');
    }

    /**
     * DELETE /api/messages/{id}
     * Delete a message (own message or admin).
     */
    public function delete(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $messageId = (int) $params['id'];

        $deleted = $this->messageService->delete($messageId, $auth['user_id'], (bool) $auth['is_admin']);

        if (!$deleted) {
            Response::notFound('Message not found or not yours');
            return;
        }

        Response::success('Message deleted');
    }

    /**
     * GET /api/messages/channels
     * Get the list of available channels.
     */
    public function channels(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $channels = $this->messageService->getChannels();

        Response::json([
            'channels' => $channels,
        ]);
    }
}
