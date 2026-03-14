<?php

namespace App\Models;

class Injury extends BaseModel
{
    protected string $table = 'injuries';

    public function getActiveByTeam(int $teamId): array
    {
        $stmt = $this->db->prepare(
            "SELECT i.*, p.first_name, p.last_name, p.position
             FROM {$this->table} i
             JOIN players p ON p.id = i.player_id
             WHERE i.team_id = ? AND i.weeks_remaining > 0
             ORDER BY i.weeks_remaining DESC"
        );
        $stmt->execute([$teamId]);
        return $stmt->fetchAll();
    }

    public function decrementWeeks(int $leagueId): int
    {
        $driver = \App\Database\Connection::getInstance()->getDriver();

        if ($driver === 'sqlite') {
            // SQLite does not support UPDATE ... JOIN, use a subquery instead
            $stmt = $this->db->prepare(
                "UPDATE {$this->table}
                 SET weeks_remaining = weeks_remaining - 1
                 WHERE weeks_remaining > 0
                   AND team_id IN (SELECT id FROM teams WHERE league_id = ?)"
            );
        } else {
            $stmt = $this->db->prepare(
                "UPDATE {$this->table} i
                 JOIN teams t ON t.id = i.team_id
                 SET i.weeks_remaining = i.weeks_remaining - 1
                 WHERE t.league_id = ? AND i.weeks_remaining > 0"
            );
        }

        $stmt->execute([$leagueId]);
        return $stmt->rowCount();
    }
}
