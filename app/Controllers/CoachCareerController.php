<?php

namespace App\Controllers;

use App\Database\Connection;
use App\Helpers\Response;
use App\Middleware\AuthMiddleware;

class CoachCareerController
{
    /**
     * GET /api/coach-career/available-teams
     * List teams that have AI coaches (available to switch to).
     */
    public function availableTeams(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $db = Connection::getInstance()->getPdo();

        $stmt = $db->prepare(
            "SELECT t.id, t.city, t.name, t.abbreviation, t.conference, t.division,
                    t.primary_color, t.secondary_color, t.overall_rating, t.wins, t.losses, t.ties,
                    c.name AS coach_name, c.archetype AS coach_archetype
             FROM teams t
             JOIN coaches c ON c.team_id = t.id AND c.is_human = 0
             WHERE t.league_id = ? AND t.id != ?
             ORDER BY t.conference, t.division, t.city"
        );
        $stmt->execute([$auth['league_id'], $auth['team_id']]);
        $teams = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        Response::json($teams);
    }

    /**
     * POST /api/coach-career/switch-team
     * Switch the human coach to a new team.
     * Body: { team_id: int, mode: "request_release" | "retire", new_coach_name?: string }
     */
    public function switchTeam(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $body = Response::getJsonBody();
        $newTeamId = (int) ($body['team_id'] ?? 0);
        $mode = $body['mode'] ?? 'request_release';
        $newCoachName = trim($body['new_coach_name'] ?? '');

        if (!$newTeamId) {
            Response::error('team_id is required');
            return;
        }

        if (!in_array($mode, ['request_release', 'retire'])) {
            Response::error('mode must be "request_release" or "retire"');
            return;
        }

        $db = Connection::getInstance()->getPdo();
        $coachId = (int) $auth['coach_id'];
        $oldTeamId = (int) $auth['team_id'];
        $leagueId = (int) $auth['league_id'];
        $userId = (int) $auth['user_id'];

        if ($newTeamId === $oldTeamId) {
            Response::error('You are already coaching this team');
            return;
        }

        // Validate new team exists in this league and has an AI coach
        $stmt = $db->prepare(
            "SELECT t.id, c.id AS ai_coach_id
             FROM teams t
             JOIN coaches c ON c.team_id = t.id AND c.is_human = 0
             WHERE t.id = ? AND t.league_id = ?"
        );
        $stmt->execute([$newTeamId, $leagueId]);
        $targetTeam = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$targetTeam) {
            Response::error('Team not available (no AI coach or not in your league)');
            return;
        }

        $db->beginTransaction();

        try {
            $now = date('Y-m-d H:i:s');

            // Get current season and old team record
            $stmt = $db->prepare("SELECT season_year FROM leagues WHERE id = ?");
            $stmt->execute([$leagueId]);
            $currentSeason = (int) ($stmt->fetchColumn() ?: 2026);

            $stmt = $db->prepare("SELECT wins, losses, ties FROM teams WHERE id = ?");
            $stmt->execute([$oldTeamId]);
            $oldTeamRecord = $stmt->fetch(\PDO::FETCH_ASSOC);

            // 1. Record career history for old team
            $db->prepare(
                "INSERT INTO coach_career_history
                 (coach_id, team_id, league_id, start_season, end_season, wins, losses, ties, departure_reason, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                $coachId, $oldTeamId, $leagueId,
                $currentSeason, $currentSeason,
                $oldTeamRecord['wins'] ?? 0,
                $oldTeamRecord['losses'] ?? 0,
                $oldTeamRecord['ties'] ?? 0,
                $mode,
                $now,
            ]);

            // 2. Create AI coach for old team
            $aiCoachNames = [
                'Mike Richards', 'John Patterson', 'Dave Sullivan', 'Tom Brennan',
                'Steve Morrison', 'Bill Crawford', 'Jim Henderson', 'Rick Thompson',
                'Mark Davidson', 'Bob Callahan', 'Pete Simmons', 'Dan Wheeler',
            ];
            $archetypes = ['rebuilder', 'win_now', 'conservative', 'gambler', 'developer'];

            $db->prepare(
                "INSERT INTO coaches (league_id, team_id, user_id, name, is_human, archetype, influence, job_security, media_rating, contract_years, contract_salary, created_at)
                 VALUES (?, ?, NULL, ?, 0, ?, ?, ?, 50, 3, 5000000, ?)"
            )->execute([
                $leagueId,
                $oldTeamId,
                $aiCoachNames[array_rand($aiCoachNames)],
                $archetypes[array_rand($archetypes)],
                mt_rand(40, 70),
                mt_rand(50, 80),
                $now,
            ]);

            // 3. Remove AI coach from new team
            $db->prepare(
                "DELETE FROM coaches WHERE team_id = ? AND is_human = 0 AND league_id = ?"
            )->execute([$newTeamId, $leagueId]);

            // 4. Move or create coach
            if ($mode === 'retire') {
                // Detach old coach
                $db->prepare("UPDATE coaches SET team_id = NULL WHERE id = ?")->execute([$coachId]);

                // Create new coach
                $name = $newCoachName ?: 'Coach';
                $db->prepare(
                    "INSERT INTO coaches (league_id, team_id, user_id, name, is_human, influence, job_security, media_rating, contract_years, contract_salary, created_at)
                     VALUES (?, ?, ?, ?, 1, 50, 70, 50, 3, 5000000, ?)"
                )->execute([$leagueId, $newTeamId, $userId, $name, $now]);
                $newCoachId = (int) $db->lastInsertId();
            } else {
                // Transfer existing coach
                $db->prepare("UPDATE coaches SET team_id = ? WHERE id = ?")->execute([$newTeamId, $coachId]);
                $newCoachId = $coachId;
            }

            $db->commit();

            // Update session
            $_SESSION['team_id'] = $newTeamId;
            $_SESSION['coach_id'] = $newCoachId;

            Response::json([
                'success' => true,
                'message' => 'Team switched successfully',
                'new_team_id' => $newTeamId,
                'new_coach_id' => $newCoachId,
            ]);
        } catch (\Exception $e) {
            $db->rollBack();
            Response::error('Failed to switch team: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/coach-career/history
     * Get the coaching career history.
     */
    public function history(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $db = Connection::getInstance()->getPdo();

        // Get all history entries for coaches owned by this user
        $stmt = $db->prepare(
            "SELECT cch.*, t.city, t.name AS team_name, t.abbreviation, t.primary_color, t.secondary_color
             FROM coach_career_history cch
             JOIN teams t ON t.id = cch.team_id
             JOIN coaches c ON c.id = cch.coach_id
             WHERE c.user_id = ? AND cch.league_id = ?
             ORDER BY cch.created_at DESC"
        );
        $stmt->execute([$auth['user_id'], $auth['league_id']]);
        $history = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        Response::json($history);
    }
}
