<?php

namespace App\Services;

use App\Database\Connection;

class NotificationService
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
    }

    public function create(int $userId, int $leagueId, string $type, string $title, string $body = '', array $data = []): int
    {
        $this->db->prepare(
            "INSERT INTO notifications (user_id, league_id, type, title, body, data, is_read, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 0, ?)"
        )->execute([$userId, $leagueId, $type, $title, $body, json_encode($data), date('Y-m-d H:i:s')]);

        return (int) $this->db->lastInsertId();
    }

    public function getForUser(int $userId, bool $unreadOnly = false, int $limit = 50): array
    {
        $sql = "SELECT * FROM notifications WHERE user_id = ?";
        $params = [$userId];

        if ($unreadOnly) {
            $sql .= " AND is_read = 0";
        }

        $sql .= " ORDER BY created_at DESC LIMIT ?";
        $params[] = $limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return array_map(function ($r) {
            $r['data'] = json_decode($r['data'] ?? '{}', true);
            return $r;
        }, $rows);
    }

    public function markRead(int $notificationId, int $userId): bool
    {
        $stmt = $this->db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        $stmt->execute([$notificationId, $userId]);
        return $stmt->rowCount() > 0;
    }

    public function markAllRead(int $userId): int
    {
        $stmt = $this->db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    }

    public function getUnreadCount(int $userId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Notify all users in a league.
     */
    public function notifyLeague(int $leagueId, string $type, string $title, string $body = '', array $data = []): int
    {
        $stmt = $this->db->prepare(
            "SELECT u.id FROM users u
             JOIN coaches c ON u.id = c.user_id
             WHERE c.league_id = ?"
        );
        $stmt->execute([$leagueId]);
        $userIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $count = 0;
        foreach ($userIds as $uid) {
            $this->create((int) $uid, $leagueId, $type, $title, $body, $data);
            $count++;
        }
        return $count;
    }

    /**
     * Send trade notification.
     */
    public function notifyTrade(int $leagueId, int $proposingUserId, int $receivingUserId, string $action, array $tradeData): void
    {
        $title = match ($action) {
            'proposed' => 'New Trade Proposal',
            'accepted' => 'Trade Accepted',
            'rejected' => 'Trade Rejected',
            'countered' => 'Trade Counter-Offer',
            'review' => 'Trade Under Review',
            'approved' => 'Trade Approved by Commissioner',
            'vetoed' => 'Trade Vetoed by Commissioner',
            default => 'Trade Update',
        };

        $this->create($receivingUserId, $leagueId, 'trade_' . $action, $title, '', $tradeData);

        if (in_array($action, ['accepted', 'rejected', 'countered'])) {
            $this->create($proposingUserId, $leagueId, 'trade_' . $action, $title, '', $tradeData);
        }
    }

    /**
     * Sim complete notification.
     */
    public function notifySimComplete(int $leagueId, int $week, array $results): void
    {
        $this->notifyLeague(
            $leagueId,
            'sim_complete',
            "Week {$week} Simulated",
            count($results) . ' games completed',
            ['week' => $week, 'games' => count($results)]
        );
    }

    /**
     * Draft pick notification.
     */
    public function notifyDraftPick(int $leagueId, string $teamName, string $playerName, int $round, int $pick): void
    {
        $this->notifyLeague(
            $leagueId,
            'draft_pick',
            "Draft Pick: {$teamName}",
            "{$teamName} selects {$playerName} (Round {$round}, Pick {$pick})",
            ['team' => $teamName, 'player' => $playerName, 'round' => $round, 'pick' => $pick]
        );
    }
}
