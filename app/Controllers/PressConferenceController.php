<?php

namespace App\Controllers;

use App\Helpers\Response;
use App\Middleware\AuthMiddleware;
use App\Models\Coach;
use App\Models\League;
use App\Models\Team;
use App\Models\Game;
use App\Database\Connection;

class PressConferenceController
{
    private \PDO $db;
    private Coach $coach;
    private League $league;
    private Team $team;
    private Game $game;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
        $this->coach = new Coach();
        $this->league = new League();
        $this->team = new Team();
        $this->game = new Game();
    }

    /**
     * GET /api/press-conference/current
     * Get the current week's press conference for the logged-in coach.
     * Generates one if none exists.
     */
    public function current(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $coachId = (int) $auth['coach_id'];
        $leagueId = (int) $auth['league_id'];

        $league = $this->league->find($leagueId);
        if (!$league) {
            Response::notFound('League not found');
            return;
        }

        $currentWeek = (int) $league['current_week'];
        if ($currentWeek < 1) {
            Response::error('Season has not started yet');
            return;
        }

        // Check if a press conference already exists for this coach and week
        $stmt = $this->db->prepare(
            "SELECT * FROM press_conferences
             WHERE coach_id = ? AND league_id = ? AND week = ?
             LIMIT 1"
        );
        $stmt->execute([$coachId, $leagueId, $currentWeek]);
        $pc = $stmt->fetch();

        if (!$pc) {
            // Generate a new press conference
            $pc = $this->generatePressConference($coachId, $leagueId, $currentWeek);
        }

        $rawQuestions = json_decode($pc['questions'] ?? '[]', true);

        // Transform questions to match frontend PressQuestion interface:
        // { question: string, answers: { text: string, tone: string }[], topic?: string }
        $transformedQuestions = [];
        $toneOptions = [
            'confident' => 'We prepared well and executed the game plan.',
            'humble' => 'We still have a lot to improve on, but we\'re working hard.',
            'deflect' => 'I\'d rather focus on moving forward.',
            'aggressive' => 'We\'re going to prove the doubters wrong.',
            'honest' => 'I\'ll be straight with you -- it is what it is.',
        ];

        foreach ($rawQuestions as $q) {
            $answers = [];
            foreach ($toneOptions as $tone => $defaultText) {
                $answers[] = [
                    'text' => $defaultText,
                    'tone' => $tone,
                ];
            }
            $transformedQuestions[] = [
                'question' => $q['text'] ?? $q['question'] ?? '',
                'answers' => $answers,
                'topic' => $q['topic'] ?? null,
            ];
        }

        // Return flat PressConference matching frontend interface
        Response::json([
            'id' => (int) $pc['id'],
            'questions' => $transformedQuestions,
            'week' => (int) $pc['week'],
            'type' => $pc['type'],
        ]);
    }

    /**
     * POST /api/press-conference/{id}/answer
     * Submit answers to press conference questions.
     * Calculates consequences (influence/morale changes).
     */
    public function answer(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $pcId = (int) $params['id'];
        $stmt = $this->db->prepare("SELECT * FROM press_conferences WHERE id = ?");
        $stmt->execute([$pcId]);
        $pc = $stmt->fetch();

        if (!$pc) {
            Response::notFound('Press conference not found');
            return;
        }

        if ((int) $pc['coach_id'] !== (int) $auth['coach_id']) {
            Response::error('This is not your press conference', 403);
            return;
        }

        if ($pc['completed_at'] !== null) {
            Response::error('Press conference already completed');
            return;
        }

        $body = Response::getJsonBody();
        $rawAnswers = $body['answers'] ?? [];

        $questions = json_decode($pc['questions'], true);

        // Frontend sends { [questionIndex]: answerIndex } (Record<number, number>)
        // Convert to array of { tone: string } for processing
        $validTones = ['confident', 'humble', 'deflect', 'aggressive', 'honest'];
        $answers = [];
        foreach ($questions as $i => $q) {
            $answerIdx = $rawAnswers[$i] ?? $rawAnswers[(string) $i] ?? null;
            if ($answerIdx !== null && isset($validTones[(int) $answerIdx])) {
                $answers[] = ['tone' => $validTones[(int) $answerIdx]];
            } else {
                // If passed as { tone: string } directly (legacy format)
                if (is_array($rawAnswers) && isset($rawAnswers[$i]['tone'])) {
                    $answers[] = $rawAnswers[$i];
                } else {
                    $answers[] = ['tone' => 'deflect'];
                }
            }
        }

        // Calculate consequences
        $consequences = $this->calculateConsequences($questions, $answers, $auth);

        // Apply consequences to coach
        $coach = $this->coach->find((int) $auth['coach_id']);
        if ($coach) {
            $newMediaRating = max(0, min(100,
                (int) $coach['media_rating'] + $consequences['media_rating_change']
            ));
            $newInfluence = max(0, min(100,
                (int) $coach['influence'] + $consequences['influence_change']
            ));

            $this->coach->update((int) $auth['coach_id'], [
                'media_rating' => $newMediaRating,
                'influence' => $newInfluence,
            ]);
        }

        // Apply morale change to team
        if ($auth['team_id'] && $consequences['morale_change'] !== 0) {
            $team = $this->team->find((int) $auth['team_id']);
            if ($team) {
                $newMorale = max(10, min(100,
                    (int) $team['morale'] + $consequences['morale_change']
                ));
                $this->team->update((int) $auth['team_id'], [
                    'morale' => $newMorale,
                ]);
            }
        }

        // Save answers and consequences
        $updateStmt = $this->db->prepare(
            "UPDATE press_conferences
             SET answers = ?, consequences = ?, media_rating_change = ?, completed_at = ?
             WHERE id = ?"
        );
        $updateStmt->execute([
            json_encode($answers),
            json_encode($consequences),
            $consequences['media_rating_change'],
            date('Y-m-d H:i:s'),
            $pcId,
        ]);

        // Return PressConferenceResult matching frontend interface
        Response::json([
            'influence_change' => $consequences['influence_change'],
            'morale_change' => $consequences['morale_change'],
            'media_change' => $consequences['media_rating_change'],
        ]);
    }

    /**
     * GET /api/press-conference/{id}/results
     * Get results/consequences of a completed press conference.
     */
    public function results(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $pcId = (int) $params['id'];
        $stmt = $this->db->prepare("SELECT * FROM press_conferences WHERE id = ?");
        $stmt->execute([$pcId]);
        $pc = $stmt->fetch();

        if (!$pc) {
            Response::notFound('Press conference not found');
            return;
        }

        if ($pc['completed_at'] === null) {
            Response::error('Press conference has not been completed yet');
            return;
        }

        $consequences = json_decode($pc['consequences'] ?? '{}', true);

        // Return PressConferenceResult matching frontend interface
        Response::json([
            'influence_change' => $consequences['influence_change'] ?? 0,
            'morale_change' => $consequences['morale_change'] ?? 0,
            'media_change' => $consequences['media_rating_change'] ?? 0,
        ]);
    }

    /**
     * Generate a press conference with contextual questions.
     */
    private function generatePressConference(int $coachId, int $leagueId, int $week): array
    {
        $coach = $this->coach->find($coachId);
        $team = $coach && $coach['team_id'] ? $this->team->find((int) $coach['team_id']) : null;

        // Find the most recent game result for context
        $lastGame = null;
        if ($team) {
            $stmt = $this->db->prepare(
                "SELECT g.*, ht.name as home_name, at.name as away_name
                 FROM games g
                 JOIN teams ht ON ht.id = g.home_team_id
                 JOIN teams at ON at.id = g.away_team_id
                 WHERE (g.home_team_id = ? OR g.away_team_id = ?)
                   AND g.is_simulated = 1
                 ORDER BY g.week DESC LIMIT 1"
            );
            $stmt->execute([$team['id'], $team['id']]);
            $lastGame = $stmt->fetch();
        }

        $type = $lastGame ? 'postgame' : 'weekly';

        $questions = $this->generateQuestions($type, $team, $lastGame, $week);

        $data = [
            'league_id' => $leagueId,
            'coach_id' => $coachId,
            'game_id' => $lastGame ? (int) $lastGame['id'] : null,
            'week' => $week,
            'type' => $type,
            'questions' => json_encode($questions),
            'answers' => null,
            'consequences' => null,
            'media_rating_change' => 0,
            'completed_at' => null,
        ];

        $stmt = $this->db->prepare(
            "INSERT INTO press_conferences
             (league_id, coach_id, game_id, week, type, questions, answers, consequences, media_rating_change, completed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $data['league_id'], $data['coach_id'], $data['game_id'],
            $data['week'], $data['type'], $data['questions'],
            $data['answers'], $data['consequences'], $data['media_rating_change'],
            $data['completed_at'],
        ]);

        $data['id'] = (int) $this->db->lastInsertId();
        return $data;
    }

    /**
     * Generate contextual questions for a press conference.
     */
    private function generateQuestions(string $type, ?array $team, ?array $lastGame, int $week): array
    {
        $teamName = $team ? $team['city'] . ' ' . $team['name'] : 'your team';
        $questions = [];

        if ($type === 'postgame' && $lastGame) {
            $isHome = $team && (int) $lastGame['home_team_id'] === (int) $team['id'];
            $teamScore = $isHome ? (int) $lastGame['home_score'] : (int) $lastGame['away_score'];
            $oppScore = $isHome ? (int) $lastGame['away_score'] : (int) $lastGame['home_score'];
            $oppName = $isHome ? $lastGame['away_name'] : $lastGame['home_name'];
            $won = $teamScore > $oppScore;

            if ($won) {
                $questions[] = [
                    'text' => "Coach, great win against the {$oppName}, {$teamScore}-{$oppScore}. What was the key to the victory?",
                    'topic' => 'game_result',
                    'sensitivity' => 'low',
                ];
                $questions[] = [
                    'text' => "The team looked confident out there today. How do you keep the momentum going?",
                    'topic' => 'momentum',
                    'sensitivity' => 'low',
                ];
            } else {
                $questions[] = [
                    'text' => "Tough loss to the {$oppName}, {$teamScore}-{$oppScore}. What went wrong out there?",
                    'topic' => 'game_result',
                    'sensitivity' => 'medium',
                ];
                $questions[] = [
                    'text' => "There have been some concerns about the direction of this team. How do you respond to critics?",
                    'topic' => 'criticism',
                    'sensitivity' => 'high',
                ];
            }

            $questions[] = [
                'text' => "Any injury updates after today's game?",
                'topic' => 'injuries',
                'sensitivity' => 'low',
            ];
        } else {
            $questions[] = [
                'text' => "Coach, how is the team preparing for week {$week}?",
                'topic' => 'preparation',
                'sensitivity' => 'low',
            ];
            $questions[] = [
                'text' => "What are your expectations for the {$teamName} this season?",
                'topic' => 'expectations',
                'sensitivity' => 'medium',
            ];
        }

        // Always include a wildcard question
        $wildcards = [
            [
                'text' => "There's been talk on social media about locker room chemistry. Any truth to that?",
                'topic' => 'locker_room',
                'sensitivity' => 'high',
            ],
            [
                'text' => "How would you rate your own performance as a coach so far this season?",
                'topic' => 'self_assessment',
                'sensitivity' => 'medium',
            ],
            [
                'text' => "The fans have been vocal lately. What's your message to them?",
                'topic' => 'fans',
                'sensitivity' => 'medium',
            ],
            [
                'text' => "Do you feel ownership has given you the tools to succeed?",
                'topic' => 'ownership',
                'sensitivity' => 'high',
            ],
        ];
        $questions[] = $wildcards[array_rand($wildcards)];

        return $questions;
    }

    /**
     * Calculate consequences based on answers and context.
     */
    private function calculateConsequences(array $questions, array $answers, array $auth): array
    {
        $mediaChange = 0;
        $moraleChange = 0;
        $influenceChange = 0;
        $headlines = [];

        foreach ($questions as $i => $question) {
            $tone = $answers[$i]['tone'] ?? 'deflect';
            $sensitivity = $question['sensitivity'] ?? 'low';
            $topic = $question['topic'] ?? 'general';

            // Base impact by tone
            $toneImpact = match ($tone) {
                'confident' => ['media' => 2, 'morale' => 2, 'influence' => 1],
                'humble' => ['media' => 1, 'morale' => 1, 'influence' => 0],
                'deflect' => ['media' => -1, 'morale' => 0, 'influence' => 0],
                'aggressive' => ['media' => -2, 'morale' => 1, 'influence' => 2],
                'honest' => ['media' => 3, 'morale' => -1, 'influence' => 1],
                default => ['media' => 0, 'morale' => 0, 'influence' => 0],
            };

            // Sensitivity multiplier
            $mult = match ($sensitivity) {
                'high' => 2,
                'medium' => 1,
                'low' => 1,
                default => 1,
            };

            // Risky tones on high-sensitivity questions can backfire
            if ($sensitivity === 'high' && $tone === 'aggressive') {
                $toneImpact['media'] = -4;
                $headlines[] = "Coach fires back at reporters in heated exchange";
            }
            if ($sensitivity === 'high' && $tone === 'honest') {
                $toneImpact['media'] = 5;
                $toneImpact['morale'] = -2;
                $headlines[] = "Coach gives brutally honest assessment of team";
            }

            $mediaChange += $toneImpact['media'] * $mult;
            $moraleChange += $toneImpact['morale'];
            $influenceChange += $toneImpact['influence'];
        }

        // Clamp to reasonable ranges per conference
        $mediaChange = max(-10, min(10, $mediaChange));
        $moraleChange = max(-5, min(5, $moraleChange));
        $influenceChange = max(-5, min(5, $influenceChange));

        return [
            'media_rating_change' => $mediaChange,
            'morale_change' => $moraleChange,
            'influence_change' => $influenceChange,
            'headlines' => $headlines,
        ];
    }
}
