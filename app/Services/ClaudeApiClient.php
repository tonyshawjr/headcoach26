<?php

namespace App\Services;

use App\Database\Connection;

class ClaudeApiClient
{
    private string $apiKey;
    private string $model;
    private string $baseUrl = 'https://api.anthropic.com/v1';
    private \PDO $db;

    public function __construct(?string $apiKey = null, string $model = 'claude-sonnet-4-20250514')
    {
        $this->db = Connection::getInstance()->getPdo();

        // Try parameter, then config file, then DB setting
        if ($apiKey) {
            $this->apiKey = $apiKey;
        } else {
            $configPath = dirname(__DIR__, 2) . '/config/api_keys.php';
            if (file_exists($configPath)) {
                $keys = require $configPath;
                $this->apiKey = $keys['anthropic_api_key'] ?? '';
            } else {
                // Try from leagues table or settings
                $this->apiKey = '';
            }
        }

        $this->model = $model;
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * Send a message to Claude API.
     */
    public function message(string $systemPrompt, string $userMessage, int $maxTokens = 2048, float $temperature = 0.7): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $payload = [
            'model' => $this->model,
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
            'system' => $systemPrompt,
            'messages' => [
                ['role' => 'user', 'content' => $userMessage],
            ],
        ];

        $ch = curl_init($this->baseUrl . '/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 60,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            error_log("Claude API error (HTTP {$httpCode}): " . ($response ?: 'no response'));
            return null;
        }

        $data = json_decode($response, true);
        if (!$data || !isset($data['content'])) {
            error_log("Claude API invalid response: " . $response);
            return null;
        }

        return $data;
    }

    /**
     * Get text content from a Claude response.
     */
    public function getTextContent(array $response): string
    {
        foreach ($response['content'] ?? [] as $block) {
            if ($block['type'] === 'text') {
                return $block['text'];
            }
        }
        return '';
    }

    /**
     * Simple text generation helper.
     */
    public function generate(string $systemPrompt, string $userMessage, int $maxTokens = 2048): ?string
    {
        $response = $this->message($systemPrompt, $userMessage, $maxTokens);
        if (!$response) return null;

        return $this->getTextContent($response);
    }

    /**
     * Log an AI generation for tracking usage.
     */
    public function logGeneration(int $leagueId, string $type, array $response): void
    {
        $usage = $response['usage'] ?? [];
        $this->db->prepare(
            "INSERT INTO ai_generations (league_id, type, prompt_tokens, completion_tokens, model, created_at)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([
            $leagueId,
            $type,
            $usage['input_tokens'] ?? 0,
            $usage['output_tokens'] ?? 0,
            $response['model'] ?? $this->model,
            date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Get usage stats for a league.
     */
    public function getUsageStats(int $leagueId): array
    {
        $stmt = $this->db->prepare(
            "SELECT type, COUNT(*) as count,
                    SUM(prompt_tokens) as total_prompt,
                    SUM(completion_tokens) as total_completion
             FROM ai_generations WHERE league_id = ?
             GROUP BY type"
        );
        $stmt->execute([$leagueId]);
        return $stmt->fetchAll();
    }
}
