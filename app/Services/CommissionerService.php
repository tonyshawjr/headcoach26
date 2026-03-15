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
            'trade_deadline_week', 'salary_cap_enabled', 'allow_ai_trades',
            'league_paused',
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

        // Conflict of interest check: commissioner cannot review trades involving their own team
        $stmt = $this->db->prepare(
            "SELECT c.team_id FROM coaches c WHERE c.user_id = ? AND c.league_id = ? LIMIT 1"
        );
        $stmt->execute([$reviewerId, $leagueId]);
        $reviewerTeamId = $stmt->fetchColumn();

        if ($reviewerTeamId) {
            $stmt = $this->db->prepare(
                "SELECT proposing_team_id, receiving_team_id FROM trades WHERE id = ?"
            );
            $stmt->execute([$tradeId]);
            $trade = $stmt->fetch();

            if ($trade && (
                (int) $trade['proposing_team_id'] === (int) $reviewerTeamId ||
                (int) $trade['receiving_team_id'] === (int) $reviewerTeamId
            )) {
                return ['error' => 'Cannot review trades involving your own team'];
            }
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
     * Check if a league is currently paused.
     */
    public function isLeaguePaused(int $leagueId): bool
    {
        $settings = $this->getSettings($leagueId);
        return !empty($settings['league_paused']);
    }

    /**
     * Get activity dashboard data for all teams in the league.
     * Shows submission counts, games played, and activity status.
     */
    public function getActivity(int $leagueId): array
    {
        // Get the current season
        $stmt = $this->db->prepare(
            "SELECT id, year FROM seasons WHERE league_id = ? AND is_current = 1 LIMIT 1"
        );
        $stmt->execute([$leagueId]);
        $season = $stmt->fetch();
        $seasonId = $season ? (int) $season['id'] : 0;

        // Get current week
        $stmt = $this->db->prepare("SELECT current_week FROM leagues WHERE id = ?");
        $stmt->execute([$leagueId]);
        $currentWeek = (int) $stmt->fetchColumn();

        // Get all coaches with team info
        $stmt = $this->db->prepare(
            "SELECT c.id as coach_id, c.name as coach_name, c.is_human,
                    c.user_id,
                    t.id as team_id, t.city, t.name as team_name, t.abbreviation, t.logo_emoji,
                    u.username
             FROM coaches c
             JOIN teams t ON c.team_id = t.id
             LEFT JOIN users u ON c.user_id = u.id
             WHERE c.league_id = ?
             ORDER BY t.city"
        );
        $stmt->execute([$leagueId]);
        $coaches = $stmt->fetchAll();

        // Get total games played per team this season
        $stmt = $this->db->prepare(
            "SELECT team_id, COUNT(*) as games_played FROM (
                SELECT g.home_team_id as team_id FROM games g
                WHERE g.league_id = ? AND g.season_id = ? AND g.is_simulated = 1
                UNION ALL
                SELECT g.away_team_id as team_id FROM games g
                WHERE g.league_id = ? AND g.season_id = ? AND g.is_simulated = 1
             ) sub GROUP BY team_id"
        );
        $stmt->execute([$leagueId, $seasonId, $leagueId, $seasonId]);
        $gamesMap = [];
        foreach ($stmt->fetchAll() as $row) {
            $gamesMap[(int) $row['team_id']] = (int) $row['games_played'];
        }

        // Count game plan submissions per team this season
        $stmt = $this->db->prepare(
            "SELECT gps.team_id, COUNT(*) as submission_count
             FROM game_plan_submissions gps
             JOIN games g ON gps.game_id = g.id
             WHERE g.league_id = ? AND g.season_id = ?
             GROUP BY gps.team_id"
        );
        $stmt->execute([$leagueId, $seasonId]);
        $submissionMap = [];
        foreach ($stmt->fetchAll() as $row) {
            $submissionMap[(int) $row['team_id']] = (int) $row['submission_count'];
        }

        // Check recent submission activity (last 7 days) for each human coach
        $recentCutoff = date('Y-m-d H:i:s', strtotime('-7 days'));
        $stmt = $this->db->prepare(
            "SELECT gps.team_id, COUNT(*) as recent_count
             FROM game_plan_submissions gps
             JOIN games g ON gps.game_id = g.id
             WHERE g.league_id = ? AND g.season_id = ? AND gps.submitted_at >= ?
             GROUP BY gps.team_id"
        );
        $stmt->execute([$leagueId, $seasonId, $recentCutoff]);
        $recentMap = [];
        foreach ($stmt->fetchAll() as $row) {
            $recentMap[(int) $row['team_id']] = (int) $row['recent_count'];
        }

        // Count how many weeks with no submissions per human coach
        // We check how many scheduled games had no submission
        $stmt = $this->db->prepare(
            "SELECT sub.team_id, COUNT(*) as missed
             FROM (
                SELECT g.home_team_id as team_id, g.id as game_id FROM games g
                WHERE g.league_id = ? AND g.season_id = ? AND g.is_simulated = 1
                UNION ALL
                SELECT g.away_team_id as team_id, g.id as game_id FROM games g
                WHERE g.league_id = ? AND g.season_id = ? AND g.is_simulated = 1
             ) sub
             LEFT JOIN game_plan_submissions gps ON gps.game_id = sub.game_id AND gps.team_id = sub.team_id
             WHERE gps.id IS NULL
             GROUP BY sub.team_id"
        );
        $stmt->execute([$leagueId, $seasonId, $leagueId, $seasonId]);
        $missedMap = [];
        foreach ($stmt->fetchAll() as $row) {
            $missedMap[(int) $row['team_id']] = (int) $row['missed'];
        }

        $activity = [];
        foreach ($coaches as $c) {
            $teamId = (int) $c['team_id'];
            $isHuman = (bool) $c['is_human'];
            $gamesPlayed = $gamesMap[$teamId] ?? 0;
            $submissions = $submissionMap[$teamId] ?? 0;
            $recentSubs = $recentMap[$teamId] ?? 0;
            $missed = $missedMap[$teamId] ?? 0;

            // Determine status
            if (!$isHuman) {
                $status = 'active'; // AI coaches are always active
            } elseif ($recentSubs > 0) {
                $status = 'active';
            } elseif ($missed >= 3) {
                $status = 'absent';
            } elseif ($missed >= 1) {
                $status = 'inactive';
            } else {
                $status = 'active';
            }

            $activity[] = [
                'team_id' => $teamId,
                'team_name' => $c['city'] . ' ' . $c['team_name'],
                'team_emoji' => $c['logo_emoji'],
                'abbreviation' => $c['abbreviation'],
                'coach_id' => (int) $c['coach_id'],
                'coach_name' => $c['username'] ?? $c['coach_name'],
                'is_human' => $isHuman,
                'games_played' => $gamesPlayed,
                'plans_submitted' => $submissions,
                'plans_missed' => $missed,
                'status' => $status,
            ];
        }

        return $activity;
    }

    /**
     * Replace a coach: toggle between human and AI.
     */
    public function replaceCoach(int $leagueId, int $teamId, string $action): array
    {
        // Validate team belongs to league
        $stmt = $this->db->prepare(
            "SELECT c.id, c.is_human, c.user_id FROM coaches c
             WHERE c.team_id = ? AND c.league_id = ? LIMIT 1"
        );
        $stmt->execute([$teamId, $leagueId]);
        $coach = $stmt->fetch();

        if (!$coach) {
            return ['error' => 'Coach not found for this team'];
        }

        if ($action === 'to_ai') {
            if (!(int) $coach['is_human']) {
                return ['error' => 'Coach is already AI-controlled'];
            }
            $this->db->prepare(
                "UPDATE coaches SET is_human = 0, user_id = NULL WHERE id = ?"
            )->execute([$coach['id']]);

            return ['success' => true, 'message' => 'Coach replaced with AI'];
        } elseif ($action === 'to_human') {
            if ((int) $coach['is_human']) {
                return ['error' => 'Coach is already human-controlled'];
            }
            $this->db->prepare(
                "UPDATE coaches SET is_human = 1 WHERE id = ?"
            )->execute([$coach['id']]);

            return ['success' => true, 'message' => 'Team opened for human coach'];
        }

        return ['error' => 'Invalid action. Use "to_ai" or "to_human"'];
    }

    /**
     * Send reminder notifications to human coaches who haven't submitted game plans for the current week.
     */
    public function sendReminders(int $leagueId): array
    {
        // Get current week
        $stmt = $this->db->prepare("SELECT current_week FROM leagues WHERE id = ?");
        $stmt->execute([$leagueId]);
        $currentWeek = (int) $stmt->fetchColumn();

        if ($currentWeek < 1) {
            return ['error' => 'Season has not started yet'];
        }

        // Get current season
        $stmt = $this->db->prepare(
            "SELECT id FROM seasons WHERE league_id = ? AND is_current = 1 LIMIT 1"
        );
        $stmt->execute([$leagueId]);
        $seasonId = (int) $stmt->fetchColumn();

        // Find human coaches who haven't submitted for their current week games
        $stmt = $this->db->prepare(
            "SELECT DISTINCT c.user_id, c.id as coach_id, t.city, t.name as team_name
             FROM coaches c
             JOIN teams t ON c.team_id = t.id
             JOIN games g ON (g.home_team_id = t.id OR g.away_team_id = t.id)
             WHERE c.league_id = ? AND c.is_human = 1 AND c.user_id IS NOT NULL
               AND g.league_id = ? AND g.season_id = ? AND g.week = ? AND g.is_simulated = 0
               AND NOT EXISTS (
                   SELECT 1 FROM game_plan_submissions gps
                   WHERE gps.game_id = g.id AND gps.team_id = t.id
               )"
        );
        $stmt->execute([$leagueId, $leagueId, $seasonId, $currentWeek]);
        $coaches = $stmt->fetchAll();

        if (empty($coaches)) {
            return ['success' => true, 'count' => 0, 'message' => 'All coaches have submitted their game plans'];
        }

        $notificationService = new NotificationService();
        $count = 0;
        foreach ($coaches as $c) {
            $notificationService->create(
                (int) $c['user_id'],
                $leagueId,
                'reminder',
                "Reminder: Submit your game plan for Week {$currentWeek}",
                "Your game plan for {$c['city']} {$c['team_name']} has not been submitted yet for Week {$currentWeek}.",
                ['week' => $currentWeek]
            );
            $count++;
        }

        return ['success' => true, 'count' => $count, 'message' => "Sent {$count} reminder(s)"];
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
