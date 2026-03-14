<?php

namespace App\Helpers;

class Response
{
    public static function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    public static function error(string $message, int $status = 400): void
    {
        self::json(['error' => $message], $status);
    }

    public static function success(string $message = 'OK', array $extra = []): void
    {
        self::json(array_merge(['message' => $message], $extra));
    }

    public static function notFound(string $message = 'Not found'): void
    {
        self::error($message, 404);
    }

    public static function unauthorized(string $message = 'Unauthorized'): void
    {
        self::error($message, 401);
    }

    /**
     * Get JSON body from request.
     */
    public static function getJsonBody(): array
    {
        $body = file_get_contents('php://input');
        if (empty($body)) return [];
        $data = json_decode($body, true);
        return is_array($data) ? $data : [];
    }
}
