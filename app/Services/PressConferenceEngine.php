<?php

namespace App\Services;

use App\Database\Connection;

class PressConferenceEngine
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
    }

    /**
     * Generate a press conference for a coach/game.
     * Returns the press conference ID.
     */
    public function generate(int $leagueId, int $coachId, int $week, string $type, ?int $gameId = null): int
    {
        $coach = $this->getCoach($coachId);
        $team = $coach['team_id'] ? $this->getTeam($coach['team_id']) : null;

        $context = [
            'coach_name' => $coach['name'],
            'team_name' => $team ? $team['city'] . ' ' . $team['name'] : 'the team',
            'team_abbr' => $team['abbreviation'] ?? '???',
            'wins' => $team['wins'] ?? 0,
            'losses' => $team['losses'] ?? 0,
            'week' => $week,
            'influence' => $coach['influence'],
            'job_security' => $coach['job_security'],
        ];

        // Get recent game result if post-game
        if ($gameId) {
            $game = $this->getGame($gameId);
            if ($game && $game['is_simulated']) {
                $isHome = $game['home_team_id'] === $team['id'];
                $ourScore = $isHome ? $game['home_score'] : $game['away_score'];
                $theirScore = $isHome ? $game['away_score'] : $game['home_score'];
                $opponentId = $isHome ? $game['away_team_id'] : $game['home_team_id'];
                $opponent = $this->getTeam($opponentId);

                $context['won'] = $ourScore > $theirScore;
                $context['our_score'] = $ourScore;
                $context['their_score'] = $theirScore;
                $context['margin'] = abs($ourScore - $theirScore);
                $context['opponent'] = $opponent['city'] . ' ' . $opponent['name'];
            }
        }

        $questions = $type === 'post_game' && isset($context['won'])
            ? ($context['won'] ? $this->postWinQuestions($context) : $this->postLossQuestions($context))
            : $this->preGameQuestions($context);

        // Pick 3-4 questions
        shuffle($questions);
        $selected = array_slice($questions, 0, min(4, count($questions)));

        $stmt = $this->db->prepare(
            "INSERT INTO press_conferences (league_id, coach_id, game_id, week, type, questions, completed_at)
             VALUES (?, ?, ?, ?, ?, ?, NULL)"
        );
        $stmt->execute([
            $leagueId, $coachId, $gameId, $week, $type,
            json_encode($selected),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Process answers and calculate consequences.
     */
    public function processAnswers(int $pressConferenceId, array $answerIndices): array
    {
        $stmt = $this->db->prepare("SELECT * FROM press_conferences WHERE id = ?");
        $stmt->execute([$pressConferenceId]);
        $pc = $stmt->fetch();

        if (!$pc) return ['error' => 'Press conference not found'];

        $questions = json_decode($pc['questions'], true);
        $influenceChange = 0;
        $moraleChange = 0;
        $mediaChange = 0;

        $answersLog = [];

        foreach ($answerIndices as $qIdx => $aIdx) {
            if (!isset($questions[$qIdx]['answers'][$aIdx])) continue;

            $answer = $questions[$qIdx]['answers'][$aIdx];
            $influenceChange += $answer['influence'] ?? 0;
            $moraleChange += $answer['morale'] ?? 0;
            $mediaChange += $answer['media'] ?? ($answer['influence'] ?? 0);

            $answersLog[] = [
                'question' => $questions[$qIdx]['question'],
                'answer' => $answer['text'],
                'tone' => $answer['tone'],
            ];
        }

        // Apply consequences
        $coachId = $pc['coach_id'];

        // Update coach influence and media rating
        $this->db->prepare(
            "UPDATE coaches SET influence = MAX(0, MIN(100, influence + ?)), media_rating = MAX(0, MIN(100, media_rating + ?)) WHERE id = ?"
        )->execute([$influenceChange, $mediaChange, $coachId]);

        // Update team morale if applicable
        $coach = $this->getCoach($coachId);
        if ($coach['team_id']) {
            $this->db->prepare(
                "UPDATE teams SET morale = MAX(20, MIN(100, morale + ?)) WHERE id = ?"
            )->execute([$moraleChange * 2, $coach['team_id']]);
        }

        // Save answers and consequences
        $consequences = [
            'influence_change' => $influenceChange,
            'morale_change' => $moraleChange,
            'media_change' => $mediaChange,
        ];

        $this->db->prepare(
            "UPDATE press_conferences SET answers = ?, consequences = ?, media_rating_change = ?, completed_at = ? WHERE id = ?"
        )->execute([
            json_encode($answersLog),
            json_encode($consequences),
            $mediaChange,
            date('Y-m-d H:i:s'),
            $pressConferenceId,
        ]);

        return $consequences;
    }

    private function postWinQuestions(array $c): array
    {
        return [
            [
                'question' => "Coach, great win today. What was the key to the victory?",
                'answers' => [
                    ['text' => "Execution. The guys came out with a plan and stuck to it. Really proud of the effort.", 'tone' => 'confident', 'influence' => 2, 'morale' => 1],
                    ['text' => "I thought our preparation this week was outstanding. The coaching staff put together a great game plan.", 'tone' => 'humble', 'influence' => 1, 'morale' => 0],
                    ['text' => "We played well, but there's still a lot to clean up. We left some plays on the field.", 'tone' => 'diplomatic', 'influence' => 0, 'morale' => 0],
                ],
            ],
            [
                'question' => "The team is {$c['wins']}-{$c['losses']} now. Where do you see this season headed?",
                'answers' => [
                    ['text' => "We're taking it one week at a time. That's all we can control.", 'tone' => 'diplomatic', 'influence' => 1, 'morale' => 0],
                    ['text' => "I like where we're at. This team has the talent to make a real run.", 'tone' => 'confident', 'influence' => 2, 'morale' => 2, 'media' => 2],
                    ['text' => "Ask me that question in December. Right now we're focused on the next game.", 'tone' => 'deflective', 'influence' => -1, 'morale' => 0],
                ],
            ],
            [
                'question' => "You won by {$c['margin']} points against {$c['opponent']}. Are you satisfied with the margin of victory?",
                'answers' => [
                    ['text' => "A win is a win. I'll never complain about that.", 'tone' => 'confident', 'influence' => 1, 'morale' => 1],
                    ['text' => "Honestly, I thought we could have put up more points. We'll look at the film.", 'tone' => 'humble', 'influence' => 0, 'morale' => -1],
                    ['text' => "The scoreboard says we won. That's all that matters in this league.", 'tone' => 'combative', 'influence' => -1, 'morale' => 0],
                ],
            ],
            [
                'question' => "Talk about the atmosphere today. The crowd was electric.",
                'answers' => [
                    ['text' => "Our fans are incredible. They gave us a real boost today. This city deserves a winner.", 'tone' => 'humble', 'influence' => 3, 'morale' => 2, 'media' => 3],
                    ['text' => "The energy was great. Hopefully we keep giving them something to cheer about.", 'tone' => 'diplomatic', 'influence' => 1, 'morale' => 1],
                    ['text' => "I try not to get caught up in all that. We've got a job to do regardless of the noise level.", 'tone' => 'deflective', 'influence' => -2, 'morale' => -1],
                ],
            ],
        ];
    }

    private function postLossQuestions(array $c): array
    {
        return [
            [
                'question' => "Coach, the team struggled today. What went wrong?",
                'answers' => [
                    ['text' => "We just didn't execute. That's on me. I need to put these guys in better positions.", 'tone' => 'humble', 'influence' => 2, 'morale' => 1],
                    ['text' => "We had chances. A couple plays go differently and we're having a different conversation.", 'tone' => 'deflective', 'influence' => -1, 'morale' => 0],
                    ['text' => "I don't think we struggled as much as you're suggesting. We moved the ball.", 'tone' => 'combative', 'influence' => -3, 'morale' => -1],
                ],
            ],
            [
                'question' => "The team is now {$c['wins']}-{$c['losses']}. How do you keep the locker room together?",
                'answers' => [
                    ['text' => "This locker room is tight. These guys believe in each other and they believe in what we're building.", 'tone' => 'confident', 'influence' => 2, 'morale' => 2],
                    ['text' => "We'll go back to work on Monday. That's all you can do in this league.", 'tone' => 'diplomatic', 'influence' => 0, 'morale' => 0],
                    ['text' => "I'm not worried about the locker room. I'm worried about finding a way to win football games.", 'tone' => 'combative', 'influence' => -2, 'morale' => -2],
                ],
            ],
            [
                'question' => "There's been some talk about your job security. How do you respond to that?",
                'answers' => [
                    ['text' => "I don't pay attention to outside noise. My focus is on this team and getting better.", 'tone' => 'diplomatic', 'influence' => 1, 'morale' => 0],
                    ['text' => "I have full confidence from the ownership group. We have a plan and we're sticking to it.", 'tone' => 'confident', 'influence' => 0, 'morale' => 1, 'media' => -1],
                    ['text' => "That's a disrespectful question and I'm not going to dignify it. Next.", 'tone' => 'combative', 'influence' => -5, 'morale' => -2, 'media' => -3],
                ],
            ],
            [
                'question' => "Will there be any changes to the lineup or game plan going forward?",
                'answers' => [
                    ['text' => "We'll evaluate everything. Nothing is off the table. We need to find what works.", 'tone' => 'humble', 'influence' => 1, 'morale' => -1],
                    ['text' => "I believe in the guys we have out there. We just need to execute at a higher level.", 'tone' => 'confident', 'influence' => 0, 'morale' => 1],
                    ['text' => "I'm not going to get into specifics about our plans. That's internal.", 'tone' => 'deflective', 'influence' => -1, 'morale' => 0],
                ],
            ],
        ];
    }

    private function preGameQuestions(array $c): array
    {
        return [
            [
                'question' => "How is the team preparing for this week's matchup?",
                'answers' => [
                    ['text' => "The energy at practice has been great. The guys are locked in and ready to compete.", 'tone' => 'confident', 'influence' => 1, 'morale' => 1],
                    ['text' => "We've had a good week of preparation. We know what we need to do.", 'tone' => 'diplomatic', 'influence' => 0, 'morale' => 0],
                    ['text' => "Every week is a new challenge. We're treating this one no differently.", 'tone' => 'deflective', 'influence' => 0, 'morale' => 0],
                ],
            ],
            [
                'question' => "Any concerns about the injury report heading into the game?",
                'answers' => [
                    ['text' => "We feel good about where we are health-wise. Next man up if anyone can't go.", 'tone' => 'confident', 'influence' => 1, 'morale' => 1],
                    ['text' => "I'll let the official injury report speak for itself. We'll see on game day.", 'tone' => 'deflective', 'influence' => 0, 'morale' => 0],
                    ['text' => "Injuries are part of the game. Our depth will be tested and I'm confident in our guys.", 'tone' => 'diplomatic', 'influence' => 1, 'morale' => 1],
                ],
            ],
            [
                'question' => "What do you need to see from this team to feel good about the rest of the season?",
                'answers' => [
                    ['text' => "Consistency. We've shown flashes, but we need to put it together for a full 60 minutes.", 'tone' => 'humble', 'influence' => 1, 'morale' => 0],
                    ['text' => "I already feel good about this team. We have all the pieces, we just need to keep stacking wins.", 'tone' => 'confident', 'influence' => 2, 'morale' => 2],
                    ['text' => "I'm not looking at the big picture right now. Just this week. That's how you build something.", 'tone' => 'diplomatic', 'influence' => 0, 'morale' => 0],
                ],
            ],
        ];
    }

    private function getCoach(int $id): array
    {
        $stmt = $this->db->prepare("SELECT * FROM coaches WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: [];
    }

    private function getTeam(int $id): array
    {
        $stmt = $this->db->prepare("SELECT * FROM teams WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: [];
    }

    private function getGame(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM games WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
}
