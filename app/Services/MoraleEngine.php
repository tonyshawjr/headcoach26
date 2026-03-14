<?php

namespace App\Services;

use App\Database\Connection;

class MoraleEngine
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
     * Process morale changes after a game result.
     *
     * Win:  +2 to +5 based on margin
     * Loss: -2 to -5 based on margin
     */
    public function processGameResult(int $teamId, bool $won, int $margin): void
    {
        $change = $this->calculateResultMoraleChange($won, $margin);

        // Apply streak modifier: consecutive results amplify morale swings
        $team = $this->getTeam($teamId);
        if ($team) {
            $streakBonus = $this->calculateStreakMoraleBonus($team, $won);
            $change += $streakBonus;
        }

        $this->applyMoraleChange($teamId, $change);

        // Also update individual player morale based on the result
        $this->updatePlayerMorale($teamId, $won, $margin);
    }

    /**
     * Process playing time morale effects.
     * High-rated players not starting lose -1 morale per week.
     * Called once per week after simulation.
     */
    public function processPlayingTime(int $teamId): void
    {
        // Find high-rated players (75+ overall) who are NOT in the depth chart as starters (slot 1)
        $stmt = $this->db->prepare(
            "SELECT p.id, p.overall_rating, p.position, p.morale
             FROM players p
             WHERE p.team_id = ? AND p.status = 'active' AND p.overall_rating >= 75
               AND p.id NOT IN (
                   SELECT dc.player_id FROM depth_chart dc
                   WHERE dc.team_id = ? AND dc.slot = 1
               )"
        );
        $stmt->execute([$teamId, $teamId]);
        $benchedStars = $stmt->fetchAll();

        foreach ($benchedStars as $player) {
            // Higher-rated players get more upset about being benched
            $ratingPenalty = $player['overall_rating'] >= 85 ? -2 : -1;

            $newMorale = $this->clampMorale($player['morale'], $ratingPenalty);

            $this->db->prepare(
                "UPDATE players SET morale = ? WHERE id = ?"
            )->execute([$newMorale, $player['id']]);
        }

        // Conversely, players who ARE starting and performing feel good
        $stmt = $this->db->prepare(
            "SELECT p.id, p.morale
             FROM players p
             JOIN depth_chart dc ON dc.player_id = p.id AND dc.team_id = ? AND dc.slot = 1
             WHERE p.team_id = ? AND p.status = 'active' AND p.morale NOT IN ('ecstatic')"
        );
        $stmt->execute([$teamId, $teamId]);
        $starters = $stmt->fetchAll();

        foreach ($starters as $player) {
            // Small positive nudge for starters
            if (mt_rand(1, 3) === 1) { // 33% chance each week
                $newMorale = $this->clampMorale($player['morale'], 1);
                $this->db->prepare(
                    "UPDATE players SET morale = ? WHERE id = ?"
                )->execute([$newMorale, $player['id']]);
            }
        }
    }

    /**
     * Get the morale-based performance modifier for a team.
     * Returns a multiplier between 0.95 and 1.05.
     *
     * This modifier is designed to be applied to team strength in the SimEngine.
     */
    public function getMoraleModifier(int $teamId): float
    {
        $team = $this->getTeam($teamId);
        if (!$team) {
            return 1.0;
        }

        $morale = (int) $team['morale'];

        // Map morale (10-100) to a modifier (0.95-1.05)
        // 50 = 1.0 (neutral)
        // 100 = 1.05 (max boost)
        // 10 = 0.95 (max penalty)
        if ($morale >= 50) {
            // 50-100 maps to 1.0-1.05
            return 1.0 + (($morale - 50) / 50) * 0.05;
        } else {
            // 10-50 maps to 0.95-1.0
            return 0.95 + (($morale - 10) / 40) * 0.05;
        }
    }

    /**
     * Get a detailed morale report for a team (for frontend display).
     */
    public function getTeamMoraleReport(int $teamId): array
    {
        $team = $this->getTeam($teamId);
        if (!$team) {
            return ['error' => 'Team not found'];
        }

        $teamMorale = (int) $team['morale'];

        // Get player morale distribution
        $stmt = $this->db->prepare(
            "SELECT morale, COUNT(*) as count FROM players WHERE team_id = ? AND status = 'active' GROUP BY morale"
        );
        $stmt->execute([$teamId]);
        $distribution = $stmt->fetchAll();

        $moraleMap = [];
        foreach ($distribution as $row) {
            $moraleMap[$row['morale']] = (int) $row['count'];
        }

        // Get unhappy players (potential locker room issues)
        $stmt = $this->db->prepare(
            "SELECT id, first_name, last_name, position, overall_rating, morale
             FROM players
             WHERE team_id = ? AND status = 'active' AND morale IN ('angry', 'frustrated')
             ORDER BY overall_rating DESC"
        );
        $stmt->execute([$teamId]);
        $unhappyPlayers = $stmt->fetchAll();

        // Determine overall vibe
        if ($teamMorale >= 80) {
            $vibe = 'The locker room is buzzing with energy. Players are confident and united.';
            $vibeLevel = 'excellent';
        } elseif ($teamMorale >= 60) {
            $vibe = 'The mood in the building is positive. Players are focused and working hard.';
            $vibeLevel = 'good';
        } elseif ($teamMorale >= 45) {
            $vibe = 'The locker room is quiet. Players are going about their business, but the energy could be better.';
            $vibeLevel = 'neutral';
        } elseif ($teamMorale >= 30) {
            $vibe = 'There are some grumblings in the locker room. A few players have expressed frustration behind closed doors.';
            $vibeLevel = 'concerning';
        } else {
            $vibe = 'The locker room is fractured. Multiple players are unhappy and the tension is palpable. Something needs to change.';
            $vibeLevel = 'critical';
        }

        return [
            'team_morale' => $teamMorale,
            'modifier' => $this->getMoraleModifier($teamId),
            'vibe' => $vibe,
            'vibe_level' => $vibeLevel,
            'player_morale_distribution' => $moraleMap,
            'unhappy_players' => $unhappyPlayers,
        ];
    }

    // ────────────────────────────────────────────
    // Private: Morale calculations
    // ────────────────────────────────────────────

    /**
     * Calculate morale change from a game result.
     */
    private function calculateResultMoraleChange(bool $won, int $margin): int
    {
        if ($won) {
            if ($margin >= 21) return 5;  // Blowout win
            if ($margin >= 14) return 4;  // Comfortable win
            if ($margin >= 7)  return 3;  // Solid win
            return 2;                      // Close win
        } else {
            if ($margin >= 21) return -5; // Blowout loss
            if ($margin >= 14) return -4; // Bad loss
            if ($margin >= 7)  return -3; // Normal loss
            return -2;                     // Close loss
        }
    }

    /**
     * Calculate additional morale change from streaks.
     * Winning streaks amplify positive morale, losing streaks amplify negative.
     */
    private function calculateStreakMoraleBonus(array $team, bool $won): int
    {
        $streak = $team['streak'] ?? '';

        if ($won && preg_match('/^W(\d+)$/', $streak, $m)) {
            $count = (int) $m[1];
            if ($count >= 5) return 3;
            if ($count >= 3) return 2;
            if ($count >= 2) return 1;
        }

        if (!$won && preg_match('/^L(\d+)$/', $streak, $m)) {
            $count = (int) $m[1];
            if ($count >= 5) return -3;
            if ($count >= 3) return -2;
            if ($count >= 2) return -1;
        }

        return 0;
    }

    /**
     * Update individual player morale after a game.
     */
    private function updatePlayerMorale(int $teamId, bool $won, int $margin): void
    {
        // All active players on the team get a small morale nudge from the result
        $stmt = $this->db->prepare(
            "SELECT id, morale FROM players WHERE team_id = ? AND status = 'active'"
        );
        $stmt->execute([$teamId]);
        $players = $stmt->fetchAll();

        foreach ($players as $player) {
            // Small chance of individual morale shift per game (30%)
            if (mt_rand(1, 100) > 30) {
                continue;
            }

            $change = $won ? 1 : -1;

            // Amplify for blowouts
            if ($margin >= 17) {
                $change *= 2;
            }

            $newMorale = $this->clampMorale($player['morale'], $change);

            $this->db->prepare("UPDATE players SET morale = ? WHERE id = ?")->execute([$newMorale, $player['id']]);
        }
    }

    /**
     * Apply morale change to a team, clamped between 10 and 100.
     */
    private function applyMoraleChange(int $teamId, int $change): void
    {
        $this->db->prepare(
            "UPDATE teams SET morale = MAX(10, MIN(100, morale + ?)) WHERE id = ?"
        )->execute([$change, $teamId]);
    }

    /**
     * Clamp player morale string within the valid range.
     * Player morale uses string values: ecstatic, happy, content, frustrated, angry
     */
    private function clampMorale(string $currentMorale, int $change): string
    {
        $moraleScale = ['angry', 'frustrated', 'content', 'happy', 'ecstatic'];

        $currentIndex = array_search($currentMorale, $moraleScale);
        if ($currentIndex === false) {
            $currentIndex = 2; // default to 'content'
        }

        $newIndex = max(0, min(count($moraleScale) - 1, $currentIndex + $change));

        return $moraleScale[$newIndex];
    }

    // ────────────────────────────────────────────
    // Private helpers
    // ────────────────────────────────────────────

    private function getTeam(int $teamId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM teams WHERE id = ?");
        $stmt->execute([$teamId]);
        return $stmt->fetch() ?: null;
    }
}
