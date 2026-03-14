<?php

namespace App\Services;

use App\Database\Connection;

class CommissionerService
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
    }

    /**
     * Get or create commissioner settings for a league.
     */
    public function getSettings(int $leagueId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM commissioner_settings WHERE league_id = ?");
        $stmt->execute([$leagueId]);
        $settings = $stmt->fetch();

        if (!$settings) {
            $this->db->prepare(
                "INSERT INTO commissioner_settings (league_id) VALUES (?)"
            )->execute([$leagueId]);
            return $this->getSettings($leagueId);
        }

        return $settings;
    }

    /**
     * Update commissioner settings.
     */
    public function updateSettings(int $leagueId, array $updates): array
    {
        $allowed = [
            'trade_review', 'trade_review_hours', 'game_plan_deadline_hours',
            'auto_sim', 'sim_interval_hours', 'allow_ai_fill',
            'force_advance_enabled', 'max_roster_size', 'salary_cap',
        ];

        $sets = [];
        $params = [];
        foreach ($updates as $key => $value) {
            if (in_array($key, $allowed)) {
                $sets[] = "{$key} = ?";
                $params[] = $value;
            }
        }

        if (empty($sets)) {
            return $this->getSettings($leagueId);
        }

        $sets[] = "updated_at = ?";
        $params[] = date('Y-m-d H:i:s');
        $params[] = $leagueId;

        $this->db->prepare(
            "UPDATE commissioner_settings SET " . implode(', ', $sets) . " WHERE league_id = ?"
        )->execute($params);

        return $this->getSettings($leagueId);
    }

    /**
     * Review a trade (approve/veto).
     */
    public function reviewTrade(int $tradeId, int $leagueId, int $reviewerId, string $action, ?string $reason = null): array
    {
        if (!in_array($action, ['approved', 'vetoed'])) {
            return ['error' => 'Invalid action'];
        }

        $now = date('Y-m-d H:i:s');

        // Check if trade exists and is pending review
        $stmt = $this->db->prepare("SELECT * FROM trade_reviews WHERE trade_id = ? AND status = 'pending'");
        $stmt->execute([$tradeId]);
        $review = $stmt->fetch();

        if (!$review) {
            // Create review record
            $this->db->prepare(
                "INSERT INTO trade_reviews (trade_id, league_id, reviewer_id, status, reason, reviewed_at, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            )->execute([$tradeId, $leagueId, $reviewerId, $action, $reason, $now, $now]);
        } else {
            $this->db->prepare(
                "UPDATE trade_reviews SET reviewer_id = ?, status = ?, reason = ?, reviewed_at = ? WHERE id = ?"
            )->execute([$reviewerId, $action, $reason, $now, $review['id']]);
        }

        // If approved, execute the trade
        if ($action === 'approved') {
            $tradeEngine = new TradeEngine();
            $tradeEngine->executeTrade($tradeId);
        }

        // Update trade status
        $newStatus = $action === 'approved' ? 'completed' : 'vetoed';
        $this->db->prepare("UPDATE trades SET status = ? WHERE id = ?")->execute([$newStatus, $tradeId]);

        return ['success' => true, 'action' => $action, 'trade_id' => $tradeId];
    }

    /**
     * Force advance the league week.
     */
    public function forceAdvance(int $leagueId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM leagues WHERE id = ?");
        $stmt->execute([$leagueId]);
        $league = $stmt->fetch();

        if (!$league) {
            return ['error' => 'League not found'];
        }

        $currentWeek = (int) $league['current_week'];

        // Lock all unsubmitted game plans with defaults
        $this->lockGamePlans($leagueId, $currentWeek);

        return [
            'success' => true,
            'week' => $currentWeek,
            'message' => "Game plans locked for week {$currentWeek}. Ready to simulate.",
        ];
    }

    /**
     * Lock all game plans for the current week with defaults if not submitted.
     */
    private function lockGamePlans(int $leagueId, int $week): void
    {
        // Get all games for this week
        $stmt = $this->db->prepare(
            "SELECT g.id, g.home_team_id, g.away_team_id FROM games g
             WHERE g.league_id = ? AND g.week = ? AND g.is_simulated = 0"
        );
        $stmt->execute([$leagueId, $week]);
        $games = $stmt->fetchAll();

        $now = date('Y-m-d H:i:s');

        foreach ($games as $game) {
            foreach (['home_team_id', 'away_team_id'] as $teamField) {
                $teamId = (int) $game[$teamField];

                // Check if submission exists
                $stmt = $this->db->prepare(
                    "SELECT id FROM game_plan_submissions WHERE game_id = ? AND team_id = ?"
                );
                $stmt->execute([$game['id'], $teamId]);
                $existing = $stmt->fetch();

                if (!$existing) {
                    // Get coach for this team
                    $stmt = $this->db->prepare("SELECT id FROM coaches WHERE team_id = ? AND league_id = ?");
                    $stmt->execute([$teamId, $leagueId]);
                    $coachId = (int) $stmt->fetchColumn();

                    // Create default submission
                    $this->db->prepare(
                        "INSERT INTO game_plan_submissions (game_id, team_id, coach_id, offensive_scheme, defensive_scheme, submitted_at, is_locked)
                         VALUES (?, ?, ?, 'balanced', 'base_43', ?, 1)"
                    )->execute([$game['id'], $teamId, $coachId, $now]);
                } else {
                    // Lock existing
                    $this->db->prepare(
                        "UPDATE game_plan_submissions SET is_locked = 1 WHERE id = ?"
                    )->execute([$existing['id']]);
                }
            }
        }
    }

    /**
     * Get game plan submission status for a week.
     */
    public function getSubmissionStatus(int $leagueId, int $week): array
    {
        $stmt = $this->db->prepare(
            "SELECT g.id as game_id, g.home_team_id, g.away_team_id,
                    ht.abbreviation as home_abbr, at.abbreviation as away_abbr,
                    gps_h.submitted_at as home_submitted, gps_a.submitted_at as away_submitted
             FROM games g
             JOIN teams ht ON g.home_team_id = ht.id
             JOIN teams at ON g.away_team_id = at.id
             LEFT JOIN game_plan_submissions gps_h ON g.id = gps_h.game_id AND gps_h.team_id = g.home_team_id
             LEFT JOIN game_plan_submissions gps_a ON g.id = gps_a.game_id AND gps_a.team_id = g.away_team_id
             WHERE g.league_id = ? AND g.week = ? AND g.is_simulated = 0"
        );
        $stmt->execute([$leagueId, $week]);
        return $stmt->fetchAll();
    }

    /**
     * Get league members (coaches with user info).
     */
    public function getMembers(int $leagueId): array
    {
        $stmt = $this->db->prepare(
            "SELECT c.id as coach_id, c.name as coach_name, c.is_human, c.archetype,
                    t.id as team_id, t.city, t.name as team_name, t.abbreviation, t.logo_emoji,
                    t.wins, t.losses,
                    u.id as user_id, u.username
             FROM coaches c
             JOIN teams t ON c.team_id = t.id
             LEFT JOIN users u ON c.user_id = u.id
             WHERE c.league_id = ?
             ORDER BY t.city"
        );
        $stmt->execute([$leagueId]);
        return $stmt->fetchAll();
    }
}
