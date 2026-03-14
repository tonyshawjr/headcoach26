<?php

namespace App\Services;

use App\Database\Connection;

class MessageService
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
    }

    /**
     * Post a message to a channel.
     */
    public function post(int $leagueId, int $userId, string $body, string $channel = 'general', ?int $coachId = null): int
    {
        $body = trim($body);
        if (empty($body) || strlen($body) > 2000) {
            return 0;
        }

        $this->db->prepare(
            "INSERT INTO league_messages (league_id, user_id, coach_id, channel, body, created_at)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([$leagueId, $userId, $coachId, $channel, $body, date('Y-m-d H:i:s')]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Get messages for a channel with pagination.
     */
    public function getMessages(int $leagueId, string $channel = 'general', int $limit = 50, int $before = 0): array
    {
        $sql = "SELECT m.*, u.username, c.name as coach_name,
                       t.abbreviation as team_abbr, t.logo_emoji
                FROM league_messages m
                JOIN users u ON m.user_id = u.id
                LEFT JOIN coaches c ON m.coach_id = c.id
                LEFT JOIN teams t ON c.team_id = t.id
                WHERE m.league_id = ? AND m.channel = ?";
        $params = [$leagueId, $channel];

        if ($before > 0) {
            $sql .= " AND m.id < ?";
            $params[] = $before;
        }

        $sql .= " ORDER BY m.created_at DESC LIMIT ?";
        $params[] = $limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return array_reverse($stmt->fetchAll());
    }

    /**
     * Pin/unpin a message.
     */
    public function togglePin(int $messageId, int $leagueId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT is_pinned FROM league_messages WHERE id = ? AND league_id = ?"
        );
        $stmt->execute([$messageId, $leagueId]);
        $msg = $stmt->fetch();
        if (!$msg) return false;

        $newVal = $msg['is_pinned'] ? 0 : 1;
        $this->db->prepare("UPDATE league_messages SET is_pinned = ? WHERE id = ?")->execute([$newVal, $messageId]);
        return true;
    }

    /**
     * Get pinned messages.
     */
    public function getPinned(int $leagueId, string $channel = 'general'): array
    {
        $stmt = $this->db->prepare(
            "SELECT m.*, u.username, c.name as coach_name,
                    t.abbreviation as team_abbr, t.logo_emoji
             FROM league_messages m
             JOIN users u ON m.user_id = u.id
             LEFT JOIN coaches c ON m.coach_id = c.id
             LEFT JOIN teams t ON c.team_id = t.id
             WHERE m.league_id = ? AND m.channel = ? AND m.is_pinned = 1
             ORDER BY m.created_at DESC"
        );
        $stmt->execute([$leagueId, $channel]);
        return $stmt->fetchAll();
    }

    /**
     * Delete a message (by author or admin).
     */
    public function delete(int $messageId, int $userId, bool $isAdmin = false): bool
    {
        if ($isAdmin) {
            $stmt = $this->db->prepare("DELETE FROM league_messages WHERE id = ?");
            $stmt->execute([$messageId]);
        } else {
            $stmt = $this->db->prepare("DELETE FROM league_messages WHERE id = ? AND user_id = ?");
            $stmt->execute([$messageId, $userId]);
        }
        return $stmt->rowCount() > 0;
    }

    /**
     * Get available channels.
     */
    public function getChannels(): array
    {
        return [
            ['id' => 'general', 'name' => 'General', 'description' => 'League-wide discussion'],
            ['id' => 'trades', 'name' => 'Trade Talk', 'description' => 'Trade discussion and proposals'],
            ['id' => 'trash_talk', 'name' => 'Trash Talk', 'description' => 'Friendly competition banter'],
            ['id' => 'announcements', 'name' => 'Announcements', 'description' => 'Commissioner announcements'],
        ];
    }
}
