<?php

namespace App\Controllers;

use App\Helpers\Response;
use App\Middleware\AuthMiddleware;
use App\Services\AiContentEngine;
use App\Services\ClaudeApiClient;
use App\Services\NarrativeMemory;

class AiContentController
{
    private AiContentEngine $engine;
    private ClaudeApiClient $claude;

    public function __construct()
    {
        $this->engine = new AiContentEngine();
        $this->claude = new ClaudeApiClient();
    }

    /**
     * GET /api/ai/status — Check if AI features are configured.
     */
    public function status(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $usage = $this->claude->getUsageStats((int) $auth['league_id']);

        Response::json([
            'configured' => $this->claude->isConfigured(),
            'usage' => $usage,
        ]);
    }

    /**
     * POST /api/ai/generate-recap — Generate an AI game recap.
     */
    public function generateRecap(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        if (!$this->engine->isAvailable()) {
            Response::error('AI features not configured. Add your Anthropic API key in Settings.');
            return;
        }

        $body = Response::getJsonBody();
        $gameId = (int) ($body['game_id'] ?? 0);
        if (!$gameId) {
            Response::error('game_id is required');
            return;
        }

        // Get game data
        $db = \App\Database\Connection::getInstance()->getPdo();
        $stmt = $db->prepare(
            "SELECT g.*, ht.city as home_city, ht.name as home_name, ht.abbreviation as home_abbr,
                    at.city as away_city, at.name as away_name, at.abbreviation as away_abbr
             FROM games g
             JOIN teams ht ON g.home_team_id = ht.id
             JOIN teams at ON g.away_team_id = at.id
             WHERE g.id = ? AND g.league_id = ?"
        );
        $stmt->execute([$gameId, $auth['league_id']]);
        $game = $stmt->fetch();

        if (!$game || !$game['is_simulated']) {
            Response::error('Game not found or not yet simulated');
            return;
        }

        $gameResult = [
            'home' => ['name' => "{$game['home_city']} {$game['home_name']}", 'score' => $game['home_score']],
            'away' => ['name' => "{$game['away_city']} {$game['away_name']}", 'score' => $game['away_score']],
            'turning_point' => $game['turning_point'],
        ];

        $boxScore = json_decode($game['box_score'] ?? '{}', true);

        $article = $this->engine->generateGameRecap((int) $auth['league_id'], $gameResult, $boxScore);

        if (!$article) {
            Response::error('Failed to generate recap. Check API configuration.');
            return;
        }

        // Save as article
        $now = date('Y-m-d H:i:s');
        $db->prepare(
            "INSERT INTO articles (league_id, type, headline, body, author, week, created_at)
             VALUES (?, 'game_recap', ?, ?, 'AI Generated', ?, ?)"
        )->execute([
            $auth['league_id'],
            $article['headline'] ?? 'Game Recap',
            $article['body'] ?? '',
            $game['week'],
            $now,
        ]);

        Response::json([
            'article' => $article,
            'article_id' => (int) $db->lastInsertId(),
        ]);
    }

    /**
     * POST /api/ai/generate-feature — Generate an AI feature article.
     */
    public function generateFeature(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        if (!$this->engine->isAvailable()) {
            Response::error('AI features not configured');
            return;
        }

        $body = Response::getJsonBody();
        $topic = $body['topic'] ?? '';
        $context = $body['context'] ?? [];

        if (empty($topic)) {
            Response::error('topic is required');
            return;
        }

        $article = $this->engine->generateFeatureArticle((int) $auth['league_id'], $topic, $context);

        if (!$article) {
            Response::error('Failed to generate article');
            return;
        }

        // Save
        $db = \App\Database\Connection::getInstance()->getPdo();
        $db->prepare(
            "INSERT INTO articles (league_id, type, headline, body, author, column_persona, created_at)
             VALUES (?, 'feature', ?, ?, ?, 'ai', ?)"
        )->execute([
            $auth['league_id'],
            $article['headline'] ?? 'Feature',
            $article['body'] ?? '',
            $article['author'] ?? 'AI Generated',
            date('Y-m-d H:i:s'),
        ]);

        Response::json(['article' => $article, 'article_id' => (int) $db->lastInsertId()]);
    }

    /**
     * POST /api/ai/generate-social — Generate AI social posts.
     */
    public function generateSocial(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        if (!$this->engine->isAvailable()) {
            Response::error('AI features not configured');
            return;
        }

        $body = Response::getJsonBody();
        $week = (int) ($body['week'] ?? 0);
        $results = $body['results'] ?? [];

        if (!$week) {
            Response::error('week is required');
            return;
        }

        $posts = $this->engine->generateSocialPosts((int) $auth['league_id'], $week, $results);

        if (!$posts) {
            Response::error('Failed to generate social posts');
            return;
        }

        // Save posts
        $db = \App\Database\Connection::getInstance()->getPdo();
        $now = date('Y-m-d H:i:s');
        $saved = 0;

        foreach ($posts as $post) {
            $db->prepare(
                "INSERT INTO social_posts (league_id, handle, display_name, body, likes, reposts, week, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                $auth['league_id'],
                $post['handle'] ?? '@unknown',
                $post['display_name'] ?? 'Unknown',
                $post['body'] ?? '',
                $post['likes'] ?? 0,
                $post['reposts'] ?? 0,
                $week,
                $now,
            ]);
            $saved++;
        }

        Response::json(['posts_generated' => $saved]);
    }

    /**
     * POST /api/ai/configure — Save API key.
     */
    public function configure(): void
    {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;

        $body = Response::getJsonBody();
        $apiKey = $body['api_key'] ?? '';

        if (empty($apiKey)) {
            Response::error('api_key is required');
            return;
        }

        // Validate key by making a test call
        $testClient = new ClaudeApiClient($apiKey);
        $testResponse = $testClient->message(
            'You are a test.',
            'Reply with "ok" and nothing else.',
            50,
            0.0
        );

        if (!$testResponse) {
            Response::error('Invalid API key. Could not connect to Anthropic API.');
            return;
        }

        // Save to config file
        $configPath = dirname(__DIR__, 2) . '/config/api_keys.php';
        $content = "<?php\n\nreturn [\n    'anthropic_api_key' => " . var_export($apiKey, true) . ",\n];\n";
        file_put_contents($configPath, $content);

        Response::success('API key configured successfully');
    }
}
