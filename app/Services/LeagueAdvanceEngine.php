<?php

namespace App\Services;

use App\Database\Connection;

/**
 * LeagueAdvanceEngine — Manages the ready-check and auto-advance system.
 *
 * Two groups must be ready before advancing:
 *   1. Head Coaches (human coaches in the league)
 *   2. Fantasy Managers (human managers in any active fantasy league)
 *
 * Advance modes:
 *   - manual: Commissioner clicks advance (solo/small group)
 *   - auto: Cron job advances after X hours OR when everyone is ready
 *   - ready: Auto-advances immediately when all players are ready
 */
class LeagueAdvanceEngine
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
    }

    // ── Settings ───────────────────────────────────────────────

    /**
     * Get or create advance settings for a league.
     */
    public function getSettings(int $leagueId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM league_advance_settings WHERE league_id = ?");
        $stmt->execute([$leagueId]);
        $settings = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$settings) {
            $this->db->prepare(
                "INSERT INTO league_advance_settings (league_id, advance_mode, auto_advance_hours, require_all_coaches, require_all_fantasy, commissioner_can_force)
                 VALUES (?, 'manual', 24, 1, 1, 1)"
            )->execute([$leagueId]);

            return $this->getSettings($leagueId);
        }

        return $settings;
    }

    /**
     * Update advance settings.
     */
    public function updateSettings(int $leagueId, array $updates): void
    {
        $allowed = ['advance_mode', 'auto_advance_hours', 'require_all_coaches', 'require_all_fantasy', 'commissioner_can_force'];
        $sets = [];
        $params = [];

        foreach ($updates as $key => $val) {
            if (in_array($key, $allowed)) {
                $sets[] = "{$key} = ?";
                $params[] = $val;
            }
        }

        if (empty($sets)) return;

        $params[] = $leagueId;
        $this->db->prepare(
            "UPDATE league_advance_settings SET " . implode(', ', $sets) . " WHERE league_id = ?"
        )->execute($params);
    }

    // ── Ready Checks ───────────────────────────────────────────

    /**
     * Mark a coach as ready for the current week.
     */
    public function setCoachReady(int $leagueId, int $coachId, int $week): void
    {
        $this->db->prepare(
            "INSERT INTO ready_checks (league_id, week, coach_id, type, is_ready, ready_at)
             VALUES (?, ?, ?, 'coach', 1, ?)
             ON CONFLICT(league_id, week, coach_id, type) DO UPDATE SET is_ready = 1, ready_at = ?"
        )->execute([$leagueId, $week, $coachId, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
    }

    /**
     * Mark a fantasy manager as ready for the current week.
     */
    public function setFantasyReady(int $leagueId, int $fantasyManagerId, int $week): void
    {
        $this->db->prepare(
            "INSERT INTO ready_checks (league_id, week, fantasy_manager_id, type, is_ready, ready_at)
             VALUES (?, ?, ?, 'fantasy', 1, ?)
             ON CONFLICT(league_id, week, fantasy_manager_id, type) DO UPDATE SET is_ready = 1, ready_at = ?"
        )->execute([$leagueId, $week, $fantasyManagerId, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
    }

    /**
     * Unmark a coach as ready (they made more changes).
     */
    public function setCoachNotReady(int $leagueId, int $coachId, int $week): void
    {
        $this->db->prepare(
            "UPDATE ready_checks SET is_ready = 0, ready_at = NULL
             WHERE league_id = ? AND week = ? AND coach_id = ? AND type = 'coach'"
        )->execute([$leagueId, $week, $coachId]);
    }

    /**
     * Get the full ready-check status for a week.
     */
    public function getReadyStatus(int $leagueId, int $week): array
    {
        // Get all human coaches in this league
        $coaches = $this->db->prepare(
            "SELECT c.id, c.name, c.team_id, t.city, t.name as team_name, t.abbreviation
             FROM coaches c
             LEFT JOIN teams t ON t.id = c.team_id
             WHERE c.league_id = ? AND c.is_human = 1"
        );
        $coaches->execute([$leagueId]);
        $humanCoaches = $coaches->fetchAll(\PDO::FETCH_ASSOC);

        // Get ready status for coaches
        $readyCoaches = $this->db->prepare(
            "SELECT coach_id FROM ready_checks
             WHERE league_id = ? AND week = ? AND type = 'coach' AND is_ready = 1"
        );
        $readyCoaches->execute([$leagueId, $week]);
        $readyCoachIds = array_column($readyCoaches->fetchAll(\PDO::FETCH_ASSOC), 'coach_id');

        $coachStatus = [];
        foreach ($humanCoaches as $c) {
            $coachStatus[] = [
                'coach_id' => (int) $c['id'],
                'name' => $c['name'],
                'team' => $c['abbreviation'] ?? '',
                'team_name' => ($c['city'] ?? '') . ' ' . ($c['team_name'] ?? ''),
                'is_ready' => in_array((int) $c['id'], $readyCoachIds),
            ];
        }

        // Get all human fantasy managers in active fantasy leagues for this NFL league
        $fantasyManagers = $this->db->prepare(
            "SELECT fm.id, fm.owner_name, fm.team_name, fm.is_ai, fl.id as fantasy_league_id
             FROM fantasy_managers fm
             JOIN fantasy_leagues fl ON fl.id = fm.fantasy_league_id
             WHERE fl.league_id = ? AND fl.status IN ('active', 'playoffs') AND fm.is_ai = 0"
        );
        $fantasyManagers->execute([$leagueId]);
        $humanFantasy = $fantasyManagers->fetchAll(\PDO::FETCH_ASSOC);

        // Get ready status for fantasy managers
        $readyFantasy = $this->db->prepare(
            "SELECT fantasy_manager_id FROM ready_checks
             WHERE league_id = ? AND week = ? AND type = 'fantasy' AND is_ready = 1"
        );
        $readyFantasy->execute([$leagueId, $week]);
        $readyFantasyIds = array_column($readyFantasy->fetchAll(\PDO::FETCH_ASSOC), 'fantasy_manager_id');

        $fantasyStatus = [];
        foreach ($humanFantasy as $fm) {
            $fantasyStatus[] = [
                'manager_id' => (int) $fm['id'],
                'name' => $fm['owner_name'],
                'team_name' => $fm['team_name'],
                'is_ready' => in_array((int) $fm['id'], $readyFantasyIds),
            ];
        }

        $totalCoaches = count($coachStatus);
        $readyCoachCount = count(array_filter($coachStatus, fn($c) => $c['is_ready']));
        $totalFantasy = count($fantasyStatus);
        $readyFantasyCount = count(array_filter($fantasyStatus, fn($f) => $f['is_ready']));

        $settings = $this->getSettings($leagueId);

        // Determine if we can auto-advance
        $coachesReady = $totalCoaches === 0 || $readyCoachCount >= $totalCoaches || !$settings['require_all_coaches'];
        $fantasyReady = $totalFantasy === 0 || $readyFantasyCount >= $totalFantasy || !$settings['require_all_fantasy'];
        $allReady = $coachesReady && $fantasyReady;

        return [
            'week' => $week,
            'coaches' => $coachStatus,
            'coaches_ready' => $readyCoachCount,
            'coaches_total' => $totalCoaches,
            'fantasy' => $fantasyStatus,
            'fantasy_ready' => $readyFantasyCount,
            'fantasy_total' => $totalFantasy,
            'all_ready' => $allReady,
            'advance_mode' => $settings['advance_mode'],
            'auto_advance_hours' => (int) $settings['auto_advance_hours'],
            'next_advance_at' => $settings['next_advance_at'],
        ];
    }

    /**
     * Check if the league should auto-advance and do it if ready.
     * Called by cron job or after a ready-check.
     *
     * @return array{advanced: bool, reason: string}
     */
    public function checkAndAdvance(int $leagueId): array
    {
        $league = $this->db->prepare("SELECT * FROM leagues WHERE id = ?");
        $league->execute([$leagueId]);
        $league = $league->fetch(\PDO::FETCH_ASSOC);

        if (!$league) return ['advanced' => false, 'reason' => 'League not found'];
        if ($league['phase'] === 'offseason') return ['advanced' => false, 'reason' => 'In offseason'];

        $week = (int) $league['current_week'];
        $status = $this->getReadyStatus($leagueId, $week);
        $settings = $this->getSettings($leagueId);

        // Check if all ready
        if ($status['all_ready']) {
            return $this->executeAdvance($leagueId, $week, 'All coaches and fantasy managers are ready');
        }

        // Check cron timer
        if ($settings['advance_mode'] === 'auto' && $settings['next_advance_at']) {
            $nextAdvance = strtotime($settings['next_advance_at']);
            if ($nextAdvance && time() >= $nextAdvance) {
                return $this->executeAdvance($leagueId, $week, 'Scheduled auto-advance time reached');
            }
        }

        return ['advanced' => false, 'reason' => 'Not ready yet'];
    }

    /**
     * Force advance by commissioner (bypasses ready checks).
     */
    public function forceAdvance(int $leagueId): array
    {
        $league = $this->db->prepare("SELECT current_week FROM leagues WHERE id = ?");
        $league->execute([$leagueId]);
        $l = $league->fetch(\PDO::FETCH_ASSOC);
        if (!$l) return ['advanced' => false, 'reason' => 'League not found'];

        return $this->executeAdvance($leagueId, (int) $l['current_week'], 'Commissioner force advance');
    }

    /**
     * Execute the actual sim + advance.
     */
    private function executeAdvance(int $leagueId, int $week, string $reason): array
    {
        // Sim the week first if not already simmed
        $unsimmed = $this->db->prepare(
            "SELECT COUNT(*) FROM games WHERE league_id = ? AND week = ? AND is_simulated = 0"
        );
        $unsimmed->execute([$leagueId, $week]);
        $needsSim = (int) $unsimmed->fetchColumn() > 0;

        if ($needsSim) {
            // Trigger the sim via the existing SimEngine
            try {
                $simController = new \App\Controllers\SimulationController();
                // We call the sim logic directly rather than going through HTTP
                $simEngine = new \App\Services\SimEngine();
                $gameModel = new \App\Models\Game();
                $weekGames = $gameModel->all(['league_id' => $leagueId, 'week' => $week, 'is_simulated' => 0]);

                foreach ($weekGames as $game) {
                    $result = $simEngine->simulateGame((int) $game['id']);
                    // Save results...
                    $gameModel->update((int) $game['id'], [
                        'home_score' => $result['home_score'],
                        'away_score' => $result['away_score'],
                        'is_simulated' => 1,
                        'box_score' => json_encode($result['box_score'] ?? []),
                        'player_grades' => json_encode($result['grades'] ?? []),
                        'turning_point' => $result['turning_point'] ?? null,
                    ]);
                }
            } catch (\Throwable $e) {
                error_log("Auto-advance sim error: " . $e->getMessage());
                return ['advanced' => false, 'reason' => 'Simulation failed: ' . $e->getMessage()];
            }
        }

        // Update the advance timer for next week
        $settings = $this->getSettings($leagueId);
        $hours = (int) $settings['auto_advance_hours'];
        $nextAdvance = date('Y-m-d H:i:s', time() + ($hours * 3600));

        $this->db->prepare(
            "UPDATE league_advance_settings SET last_advance_at = ?, next_advance_at = ? WHERE league_id = ?"
        )->execute([date('Y-m-d H:i:s'), $nextAdvance, $leagueId]);

        // Clear ready checks for this week
        $this->db->prepare(
            "DELETE FROM ready_checks WHERE league_id = ? AND week = ?"
        )->execute([$leagueId, $week]);

        return [
            'advanced' => true,
            'reason' => $reason,
            'week_simmed' => $week,
            'next_advance_at' => $nextAdvance,
        ];
    }

    /**
     * Cron endpoint: check ALL leagues and auto-advance any that are due.
     * Called by an external cron job hitting POST /api/cron/advance
     */
    public function cronAdvanceAll(): array
    {
        $leagues = $this->db->query(
            "SELECT l.id FROM leagues l
             JOIN league_advance_settings las ON las.league_id = l.id
             WHERE l.phase IN ('regular', 'playoffs')
             AND las.advance_mode = 'auto'"
        )->fetchAll(\PDO::FETCH_ASSOC);

        $results = [];
        foreach ($leagues as $l) {
            $result = $this->checkAndAdvance((int) $l['id']);
            if ($result['advanced']) {
                $results[] = ['league_id' => (int) $l['id'], 'reason' => $result['reason']];
            }
        }

        return ['leagues_advanced' => count($results), 'details' => $results];
    }
}
