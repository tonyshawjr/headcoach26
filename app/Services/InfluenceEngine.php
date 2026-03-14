<?php

namespace App\Services;

use App\Database\Connection;

class InfluenceEngine
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
    }

    // ────────────────────────────────────────────
    // Public API
    // ────────────────────────────────────────────

    /**
     * Process influence changes for a coach after a week of games.
     *
     * @return array Summary of all influence changes applied this week
     */
    public function processWeek(int $leagueId, int $coachId, int $week): array
    {
        $coach = $this->getCoach($coachId);
        if (!$coach || !$coach['team_id']) {
            return ['changes' => [], 'total' => 0];
        }

        $team = $this->getTeam($coach['team_id']);
        if (!$team) {
            return ['changes' => [], 'total' => 0];
        }

        $changes = [];
        $totalChange = 0;

        // 1. Game result influence
        $gameResult = $this->getWeekGameResult($team['id'], $leagueId, $week);
        if ($gameResult) {
            $resultChange = $this->calculateGameResultInfluence($gameResult);
            if ($resultChange !== 0) {
                $reason = $gameResult['won'] ? 'Win' : 'Loss';
                $margin = $gameResult['margin'];
                if ($margin >= 17) {
                    $reason .= ' (blowout)';
                } elseif ($margin <= 3) {
                    $reason .= ' (close game)';
                }
                $changes[] = ['source' => 'game_result', 'amount' => $resultChange, 'reason' => $reason];
                $totalChange += $resultChange;
            }

            // 2. Upset bonus: beating a higher-rated team
            $upsetChange = $this->calculateUpsetBonus($gameResult, $team);
            if ($upsetChange !== 0) {
                $changes[] = ['source' => 'upset_bonus', 'amount' => $upsetChange, 'reason' => 'Upset victory over higher-rated opponent'];
                $totalChange += $upsetChange;
            }
        }

        // 3. Streak bonus: +2 per consecutive win beyond 3
        $streakChange = $this->calculateStreakBonus($team);
        if ($streakChange !== 0) {
            $changes[] = ['source' => 'streak_bonus', 'amount' => $streakChange, 'reason' => "On a {$team['streak']} streak"];
            $totalChange += $streakChange;
        }

        // 4. Media coverage influence
        $mediaChange = $this->calculateMediaInfluence($coachId, $leagueId, $week);
        if ($mediaChange !== 0) {
            $changes[] = ['source' => 'media_coverage', 'amount' => $mediaChange, 'reason' => 'Media perception ' . ($mediaChange > 0 ? 'positive' : 'negative')];
            $totalChange += $mediaChange;
        }

        // Apply total change to coach influence
        if ($totalChange !== 0) {
            $this->applyInfluenceChange($coachId, $totalChange);
        }

        // Also adjust job security based on overall direction
        $this->adjustJobSecurity($coachId, $team, $totalChange);

        return [
            'coach_id' => $coachId,
            'week' => $week,
            'changes' => $changes,
            'total' => $totalChange,
            'new_influence' => $this->getCoach($coachId)['influence'],
            'new_job_security' => $this->getCoach($coachId)['job_security'],
        ];
    }

    /**
     * Process owner expectations and check if the coach is meeting them.
     *
     * @return array Owner check results: expectations, met/not met, job_security change, message
     */
    public function processOwnerCheck(int $coachId): array
    {
        $coach = $this->getCoach($coachId);
        if (!$coach || !$coach['team_id']) {
            return ['error' => 'Coach or team not found'];
        }

        $team = $this->getTeam($coach['team_id']);
        if (!$team) {
            return ['error' => 'Team not found'];
        }

        $expectations = json_decode($coach['owner_expectations'] ?? '{}', true) ?: [];
        $targetWins = $expectations['target_wins'] ?? $this->calculateTargetWins($team);
        $tolerance = $expectations['tolerance'] ?? 'medium'; // low, medium, high

        $gamesPlayed = $team['wins'] + $team['losses'] + $team['ties'];
        if ($gamesPlayed === 0) {
            return [
                'expectations_met' => true,
                'job_security_change' => 0,
                'message' => 'The season has just begun. The owner is watching with cautious optimism.',
                'target_wins' => $targetWins,
                'current_wins' => 0,
            ];
        }

        // Calculate expected win pace
        $winPace = ($team['wins'] / $gamesPlayed) * 17;
        $onPace = $winPace >= $targetWins;

        // How far off pace
        $winsNeeded = $targetWins - $team['wins'];
        $gamesRemaining = max(1, 17 - $gamesPlayed);
        $requiredWinRate = $winsNeeded / $gamesRemaining;

        $met = false;
        $securityChange = 0;
        $message = '';

        if ($onPace || $team['wins'] >= $targetWins) {
            $met = true;
            $securityChange = 2;

            $messages = [
                "The owner is pleased with the team's performance. \"Keep doing what you're doing, Coach. The fans are excited.\"",
                "Ownership has expressed confidence in the direction of the program. \"We like what we see out there.\"",
                "The front office called to say they're happy with the progress. \"You've got our full support, Coach.\"",
            ];
            $message = $messages[array_rand($messages)];
        } elseif ($requiredWinRate > 0.75) {
            $met = false;
            $toleranceMultiplier = match ($tolerance) {
                'low' => 2,
                'high' => 0.5,
                default => 1,
            };
            $securityChange = (int) round(-3 * $toleranceMultiplier);

            $messages = [
                "The owner pulled you aside. \"I'm not happy with where we are, Coach. I need to see improvement, and I need to see it fast.\"",
                "The GM relayed a message from ownership: \"The results aren't matching the investment we've made. We need wins.\"",
                "\"Let me be direct,\" the owner said in a private meeting. \"This isn't the start I was expecting. I need you to turn this around.\"",
            ];
            $message = $messages[array_rand($messages)];
        } else {
            $met = false;
            $securityChange = -1;

            $messages = [
                "The owner hasn't said much publicly, but sources say he's monitoring the situation closely. \"It's not panic time yet, but the patience isn't unlimited.\"",
                "Ownership is taking a wait-and-see approach. \"We believe in the process, but results matter. That's just the reality.\"",
                "The front office remains cautiously supportive. \"We're not where we want to be, but there's time to course-correct.\"",
            ];
            $message = $messages[array_rand($messages)];
        }

        // Apply job security change
        if ($securityChange !== 0) {
            $this->db->prepare(
                "UPDATE coaches SET job_security = MAX(5, MIN(100, job_security + ?)) WHERE id = ?"
            )->execute([$securityChange, $coachId]);
        }

        return [
            'expectations_met' => $met,
            'job_security_change' => $securityChange,
            'message' => $message,
            'target_wins' => $targetWins,
            'current_wins' => $team['wins'],
            'win_pace' => round($winPace, 1),
            'games_remaining' => $gamesRemaining,
        ];
    }

    /**
     * Get owner office data for the frontend.
     */
    public function getOwnerOffice(int $coachId): array
    {
        $coach = $this->getCoach($coachId);
        if (!$coach) {
            return ['error' => 'Coach not found'];
        }

        $team = $coach['team_id'] ? $this->getTeam($coach['team_id']) : null;

        $expectations = json_decode($coach['owner_expectations'] ?? '{}', true) ?: [];
        $targetWins = $expectations['target_wins'] ?? ($team ? $this->calculateTargetWins($team) : 8);
        $tolerance = $expectations['tolerance'] ?? 'medium';

        // Get recent influence changes (from media_ratings table which tracks weekly changes)
        $recentChanges = [];
        $stmt = $this->db->prepare(
            "SELECT week, rating, change_amount, reason FROM media_ratings WHERE coach_id = ? AND league_id = ? ORDER BY week DESC LIMIT 5"
        );
        $stmt->execute([$coachId, $coach['league_id']]);
        $recentChanges = $stmt->fetchAll();

        // Determine owner mood
        $jobSecurity = (int) $coach['job_security'];
        if ($jobSecurity >= 80) {
            $ownerMood = 'very_pleased';
            $ownerMessage = "The owner is thrilled with the direction of this team. Your job is safe and your influence is growing.";
        } elseif ($jobSecurity >= 60) {
            $ownerMood = 'satisfied';
            $ownerMessage = "Ownership is content with the progress. Keep building and the resources will follow.";
        } elseif ($jobSecurity >= 40) {
            $ownerMood = 'concerned';
            $ownerMessage = "The owner has expressed some concerns privately. Results need to improve to maintain the current level of support.";
        } elseif ($jobSecurity >= 20) {
            $ownerMood = 'unhappy';
            $ownerMessage = "Sources indicate the owner is actively exploring options. The next few games could determine your future with this organization.";
        } else {
            $ownerMood = 'furious';
            $ownerMessage = "Multiple sources report the owner is ready to make a change. Only a dramatic turnaround can save this coaching tenure.";
        }

        // Flatten to match frontend OwnerOfficeData interface
        // Transform recent_changes to match expected { week, type, amount, reason }
        $flatChanges = array_map(function ($rc) {
            return [
                'week' => (int) ($rc['week'] ?? 0),
                'type' => $rc['type'] ?? 'influence',
                'amount' => (int) ($rc['change_amount'] ?? $rc['amount'] ?? 0),
                'reason' => $rc['reason'] ?? '',
            ];
        }, $recentChanges);

        return [
            'influence' => (int) $coach['influence'],
            'job_security' => $jobSecurity,
            'media_rating' => (int) $coach['media_rating'],
            'contract_years' => (int) $coach['contract_years'],
            'contract_salary' => (int) ($coach['contract_salary'] ?? 0),
            'owner_message' => $ownerMessage,
            'expectations' => "Win {$targetWins}+ games this season. Tolerance: {$tolerance}.",
            'recent_changes' => $flatChanges,
            'morale' => $team ? (int) $team['morale'] : 50,
        ];
    }

    // ────────────────────────────────────────────
    // Private: Influence calculations
    // ────────────────────────────────────────────

    /**
     * Calculate influence change from a game result.
     * Win: +2 to +5 (blowout = more)
     * Loss: -1 to -4 (blowout = more loss)
     */
    private function calculateGameResultInfluence(array $gameResult): int
    {
        $margin = $gameResult['margin'];

        if ($gameResult['won']) {
            if ($margin >= 21) return 5;  // Dominant win
            if ($margin >= 14) return 4;  // Comfortable win
            if ($margin >= 7)  return 3;  // Solid win
            return 2;                      // Close win
        } else {
            if ($margin >= 21) return -4; // Embarrassing loss
            if ($margin >= 14) return -3; // Bad loss
            if ($margin >= 7)  return -2; // Normal loss
            return -1;                     // Close loss
        }
    }

    /**
     * Calculate upset bonus for beating a higher-rated team.
     * +3 for beating a team rated 10+ points higher.
     */
    private function calculateUpsetBonus(array $gameResult, array $team): int
    {
        if (!$gameResult['won']) {
            return 0;
        }

        $opponentId = $gameResult['opponent_team_id'];
        $opponent = $this->getTeam($opponentId);
        if (!$opponent) {
            return 0;
        }

        $ratingDiff = $opponent['overall_rating'] - $team['overall_rating'];
        if ($ratingDiff >= 10) {
            return 3;
        }
        if ($ratingDiff >= 5) {
            return 1;
        }

        return 0;
    }

    /**
     * Calculate streak bonus.
     * +2 per consecutive win beyond 3.
     */
    private function calculateStreakBonus(array $team): int
    {
        $streak = $team['streak'] ?? '';
        if (!preg_match('/^W(\d+)$/', $streak, $m)) {
            return 0;
        }

        $count = (int) $m[1];
        if ($count <= 3) {
            return 0;
        }

        // +2 per win beyond 3 (cap at +8)
        return min(8, ($count - 3) * 2);
    }

    /**
     * Calculate media influence based on the coach's media rating.
     */
    private function calculateMediaInfluence(int $coachId, int $leagueId, int $week): int
    {
        $stmt = $this->db->prepare(
            "SELECT change_amount FROM media_ratings WHERE coach_id = ? AND league_id = ? AND week = ?"
        );
        $stmt->execute([$coachId, $leagueId, $week]);
        $row = $stmt->fetch();

        if (!$row) {
            return 0;
        }

        $mediaChange = (int) $row['change_amount'];

        // Convert media rating change to a smaller influence change
        if ($mediaChange >= 5) return 2;
        if ($mediaChange >= 2) return 1;
        if ($mediaChange <= -5) return -2;
        if ($mediaChange <= -2) return -1;

        return 0;
    }

    /**
     * Apply influence change, clamped between 0 and 100.
     */
    private function applyInfluenceChange(int $coachId, int $amount): void
    {
        $this->db->prepare(
            "UPDATE coaches SET influence = MAX(0, MIN(100, influence + ?)) WHERE id = ?"
        )->execute([$amount, $coachId]);
    }

    /**
     * Adjust job security based on team performance and influence direction.
     */
    private function adjustJobSecurity(int $coachId, array $team, int $influenceChange): void
    {
        $change = 0;

        // Winning improves security, losing hurts it
        $record = $team['wins'] - $team['losses'];
        if ($record > 0 && $influenceChange > 0) {
            $change = 1; // Modest security gain when winning
        } elseif ($record < -2) {
            $change = -2; // Security drops faster when losing badly
        } elseif ($record < 0) {
            $change = -1;
        }

        if ($change !== 0) {
            $this->db->prepare(
                "UPDATE coaches SET job_security = MAX(5, MIN(100, job_security + ?)) WHERE id = ?"
            )->execute([$change, $coachId]);
        }
    }

    // ────────────────────────────────────────────
    // Private helpers
    // ────────────────────────────────────────────

    /**
     * Calculate target wins based on team rating (owner expectations).
     */
    private function calculateTargetWins(array $team): int
    {
        $rating = (int) $team['overall_rating'];

        if ($rating >= 85) return 11;  // Elite team: expect 11+ wins
        if ($rating >= 80) return 10;  // Very good team
        if ($rating >= 75) return 9;   // Good team
        if ($rating >= 70) return 8;   // Average team
        if ($rating >= 65) return 7;   // Below average
        return 6;                       // Rebuilding
    }

    /**
     * Get a coach's game result for a specific week.
     */
    private function getWeekGameResult(int $teamId, int $leagueId, int $week): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM games
             WHERE league_id = ? AND week = ? AND is_simulated = 1
               AND (home_team_id = ? OR away_team_id = ?)
             LIMIT 1"
        );
        $stmt->execute([$leagueId, $week, $teamId, $teamId]);
        $game = $stmt->fetch();

        if (!$game) {
            return null;
        }

        $isHome = (int) $game['home_team_id'] === $teamId;
        $ourScore = $isHome ? (int) $game['home_score'] : (int) $game['away_score'];
        $theirScore = $isHome ? (int) $game['away_score'] : (int) $game['home_score'];
        $opponentId = $isHome ? (int) $game['away_team_id'] : (int) $game['home_team_id'];

        return [
            'game_id' => (int) $game['id'],
            'won' => $ourScore > $theirScore,
            'our_score' => $ourScore,
            'their_score' => $theirScore,
            'margin' => abs($ourScore - $theirScore),
            'opponent_team_id' => $opponentId,
            'is_home' => $isHome,
        ];
    }

    private function getCoach(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM coaches WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    private function getTeam(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM teams WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
}
