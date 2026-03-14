<?php

namespace App\Services;

use App\Database\Connection;

class InviteService
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
    }

    /**
     * Create an invite link for a team in a league.
     */
    public function createInvite(int $leagueId, int $invitedBy, ?int $teamId = null, int $expiresInHours = 168): array
    {
        $code = bin2hex(random_bytes(16));
        $now = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiresInHours} hours"));

        $this->db->prepare(
            "INSERT INTO league_invites (league_id, invite_code, team_id, invited_by, status, expires_at, created_at)
             VALUES (?, ?, ?, ?, 'pending', ?, ?)"
        )->execute([$leagueId, $code, $teamId, $invitedBy, $expiresAt, $now]);

        return [
            'id' => (int) $this->db->lastInsertId(),
            'code' => $code,
            'team_id' => $teamId,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Get all invites for a league.
     */
    public function getLeagueInvites(int $leagueId): array
    {
        $stmt = $this->db->prepare(
            "SELECT li.*, t.city, t.name as team_name, t.logo_emoji,
                    u.username as invited_by_name
             FROM league_invites li
             LEFT JOIN teams t ON li.team_id = t.id
             LEFT JOIN users u ON li.invited_by = u.id
             WHERE li.league_id = ?
             ORDER BY li.created_at DESC"
        );
        $stmt->execute([$leagueId]);
        return $stmt->fetchAll();
    }

    /**
     * Claim an invite — assign a team to a user.
     */
    public function claimInvite(string $code, int $userId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM league_invites WHERE invite_code = ?");
        $stmt->execute([$code]);
        $invite = $stmt->fetch();

        if (!$invite) {
            return ['error' => 'Invalid invite code'];
        }

        if ($invite['status'] !== 'pending') {
            return ['error' => 'Invite already used or cancelled'];
        }

        if ($invite['expires_at'] && strtotime($invite['expires_at']) < time()) {
            return ['error' => 'Invite has expired'];
        }

        $leagueId = (int) $invite['league_id'];
        $teamId = $invite['team_id'] ? (int) $invite['team_id'] : null;

        // If no specific team, find an unclaimed team
        if (!$teamId) {
            $stmt = $this->db->prepare(
                "SELECT t.id FROM teams t
                 LEFT JOIN coaches c ON t.id = c.team_id AND c.is_human = 1
                 WHERE t.league_id = ? AND c.id IS NULL
                 ORDER BY t.id ASC LIMIT 1"
            );
            $stmt->execute([$leagueId]);
            $teamId = $stmt->fetchColumn();

            if (!$teamId) {
                return ['error' => 'No unclaimed teams available'];
            }
        }

        // Check if user already has a coach in this league
        $stmt = $this->db->prepare("SELECT id FROM coaches WHERE user_id = ? AND league_id = ?");
        $stmt->execute([$userId, $leagueId]);
        if ($stmt->fetch()) {
            return ['error' => 'You already have a team in this league'];
        }

        $now = date('Y-m-d H:i:s');

        // Create coach for this user
        $this->db->prepare(
            "INSERT INTO coaches (user_id, league_id, team_id, name, is_human, archetype, influence, job_security, created_at)
             VALUES (?, ?, ?, (SELECT username FROM users WHERE id = ?), 1, 'balanced', 50, 100, ?)"
        )->execute([$userId, $leagueId, $teamId, $userId, $now]);

        $coachId = (int) $this->db->lastInsertId();

        // Mark invite as claimed
        $this->db->prepare(
            "UPDATE league_invites SET status = 'claimed', claimed_by = ?, claimed_at = ? WHERE id = ?"
        )->execute([$userId, $now, $invite['id']]);

        return [
            'success' => true,
            'coach_id' => $coachId,
            'team_id' => $teamId,
            'league_id' => $leagueId,
        ];
    }

    /**
     * Cancel an invite.
     */
    public function cancelInvite(int $inviteId, int $userId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE league_invites SET status = 'cancelled'
             WHERE id = ? AND invited_by = ? AND status = 'pending'"
        );
        $stmt->execute([$inviteId, $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Get available (unclaimed) teams in a league.
     */
    public function getAvailableTeams(int $leagueId): array
    {
        $stmt = $this->db->prepare(
            "SELECT t.id, t.city, t.name, t.abbreviation, t.logo_emoji, t.conference, t.division, t.overall_rating
             FROM teams t
             LEFT JOIN coaches c ON t.id = c.team_id AND c.is_human = 1
             WHERE t.league_id = ? AND c.id IS NULL
             ORDER BY t.city"
        );
        $stmt->execute([$leagueId]);
        return $stmt->fetchAll();
    }
}
