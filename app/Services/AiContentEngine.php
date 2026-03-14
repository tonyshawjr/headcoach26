<?php

namespace App\Services;

use App\Database\Connection;

class AiContentEngine
{
    private \PDO $db;
    private ClaudeApiClient $claude;
    private NarrativeMemory $memory;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
        $this->claude = new ClaudeApiClient();
        $this->memory = new NarrativeMemory();
    }

    public function isAvailable(): bool
    {
        return $this->claude->isConfigured();
    }

    /**
     * Generate an AI-written game recap article.
     */
    public function generateGameRecap(int $leagueId, array $gameResult, array $boxScore): ?array
    {
        if (!$this->isAvailable()) return null;

        $context = $this->memory->getSeasonContext($leagueId);

        $system = "You are a seasoned NFL sports journalist writing for a major sports network. " .
            "Write engaging, detailed game recaps that capture the drama and storylines. " .
            "Use specific stats, player names, and turning points. " .
            "Write in a professional but entertaining style. " .
            "Return JSON with keys: headline (string), body (string, 3-5 paragraphs), type (always 'game_recap').";

        $prompt = "Write a game recap article based on this data:\n\n" .
            "GAME RESULT:\n" . json_encode($gameResult, JSON_PRETTY_PRINT) . "\n\n" .
            "BOX SCORE:\n" . json_encode($boxScore, JSON_PRETTY_PRINT) . "\n\n" .
            ($context ? "SEASON CONTEXT:\n{$context}\n\n" : "") .
            "Write a compelling game recap with a creative headline. Return valid JSON only.";

        $response = $this->claude->message($system, $prompt, 1500, 0.8);
        if (!$response) return null;

        $this->claude->logGeneration($leagueId, 'game_recap', $response);
        $text = $this->claude->getTextContent($response);

        return $this->parseJsonResponse($text);
    }

    /**
     * Generate an AI-written feature article.
     */
    public function generateFeatureArticle(int $leagueId, string $topic, array $contextData): ?array
    {
        if (!$this->isAvailable()) return null;

        $seasonContext = $this->memory->getSeasonContext($leagueId);

        $system = "You are a top-tier sports journalist known for in-depth feature articles. " .
            "Write compelling narratives about players, teams, and coaching. " .
            "Include storytelling elements, quotes (make them realistic), and analysis. " .
            "Return JSON with keys: headline (string), body (string, 4-6 paragraphs), " .
            "author (string, a fictional columnist name), type (always 'feature').";

        $prompt = "Write a feature article about: {$topic}\n\n" .
            "DATA:\n" . json_encode($contextData, JSON_PRETTY_PRINT) . "\n\n" .
            ($seasonContext ? "SEASON CONTEXT:\n{$seasonContext}\n\n" : "") .
            "Return valid JSON only.";

        $response = $this->claude->message($system, $prompt, 2000, 0.85);
        if (!$response) return null;

        $this->claude->logGeneration($leagueId, 'feature_article', $response);
        return $this->parseJsonResponse($this->claude->getTextContent($response));
    }

    /**
     * Generate AI social media posts.
     */
    public function generateSocialPosts(int $leagueId, int $week, array $gameResults): ?array
    {
        if (!$this->isAvailable()) return null;

        $system = "You are generating social media posts for a fictional football social media platform called GridironX. " .
            "Generate diverse posts from different account types: hot take artists, analysts, beat writers, fans, meme accounts. " .
            "Each post should feel authentic to its account type. Keep posts under 280 characters. " .
            "Return JSON array of objects with keys: handle (string), display_name (string), body (string), " .
            "account_type (string: hot_take|analyst|beat_writer|fan|meme|official), likes (number 10-5000), reposts (number 0-500).";

        $prompt = "Generate 8-12 social media posts reacting to Week {$week} results:\n\n" .
            json_encode($gameResults, JSON_PRETTY_PRINT) . "\n\n" .
            "Include a mix of: hot takes, statistical analysis, insider scoops, fan reactions, and humor. " .
            "Return valid JSON array only.";

        $response = $this->claude->message($system, $prompt, 2000, 0.9);
        if (!$response) return null;

        $this->claude->logGeneration($leagueId, 'social_posts', $response);
        return $this->parseJsonResponse($this->claude->getTextContent($response));
    }

    /**
     * Generate an AI column with columnist personality.
     */
    public function generateColumn(int $leagueId, string $persona, array $weekData): ?array
    {
        if (!$this->isAvailable()) return null;

        $seasonContext = $this->memory->getSeasonContext($leagueId);

        $personas = [
            'terry_hollis' => "You are Terry Hollis, a veteran sports columnist. Old-school, blunt, loves defense and the running game. " .
                "Skeptical of modern analytics. Uses short, punchy sentences. Occasionally cranky but always insightful.",
            'dana_reeves' => "You are Dana Reeves, a lead sports analyst. Data-driven, measured, balanced. " .
                "Loves citing specific statistics and trends. Professional tone with occasional dry wit.",
            'marcus_bell' => "You are Marcus Bell, a sports culture writer. Emotional, narrative-focused. " .
                "Writes about the human side of the game. Loves underdogs, player stories, and atmosphere.",
        ];

        $system = ($personas[$persona] ?? $personas['dana_reeves']) .
            " Write a weekly opinion column. " .
            "Return JSON with keys: headline (string), body (string, 4-6 paragraphs), " .
            "author (string, your name), type (always 'column').";

        $prompt = "Write your weekly column based on this week's events:\n\n" .
            json_encode($weekData, JSON_PRETTY_PRINT) . "\n\n" .
            ($seasonContext ? "SEASON CONTEXT:\n{$seasonContext}\n\n" : "") .
            "Return valid JSON only.";

        $response = $this->claude->message($system, $prompt, 2000, 0.85);
        if (!$response) return null;

        $this->claude->logGeneration($leagueId, 'column', $response);
        return $this->parseJsonResponse($this->claude->getTextContent($response));
    }

    /**
     * Generate press conference questions with AI personality.
     */
    public function generatePressQuestions(int $leagueId, array $context): ?array
    {
        if (!$this->isAvailable()) return null;

        $system = "You are generating press conference questions for a football head coach. " .
            "Questions should reference specific game events, player performance, and team narratives. " .
            "Include tough questions, softball questions, and loaded questions. " .
            "Return JSON array of objects with keys: question (string), reporter_name (string), " .
            "outlet (string), tone (string: tough|neutral|friendly|loaded), " .
            "answers (array of 3 objects with keys: text (string), tone (string: confident|deflect|honest|fired_up|humble)).";

        $prompt = "Generate 4-5 press conference questions based on:\n\n" .
            json_encode($context, JSON_PRETTY_PRINT) . "\n\n" .
            "Each question should have 3 possible answer options with different tones. " .
            "Return valid JSON array only.";

        $response = $this->claude->message($system, $prompt, 2000, 0.8);
        if (!$response) return null;

        $this->claude->logGeneration($leagueId, 'press_questions', $response);
        return $this->parseJsonResponse($this->claude->getTextContent($response));
    }

    /**
     * Parse a JSON response, handling markdown code blocks.
     */
    private function parseJsonResponse(string $text): ?array
    {
        // Strip markdown code blocks if present
        $text = trim($text);
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
            $text = preg_replace('/\s*```\s*$/m', '', $text);
        }

        $decoded = json_decode(trim($text), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("AI content JSON parse error: " . json_last_error_msg() . " — Raw: " . substr($text, 0, 200));
            return null;
        }

        return $decoded;
    }
}
