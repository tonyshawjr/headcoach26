<?php

namespace App\Models;

class Coach extends BaseModel
{
    protected string $table = 'coaches';

    public function getByTeam(int $teamId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE team_id = ? ORDER BY id ASC");
        $stmt->execute([$teamId]);
        return $stmt->fetchAll();
    }

    public function getHumanByLeague(int $leagueId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT c.* FROM {$this->table} c
             JOIN teams t ON t.id = c.team_id
             WHERE t.league_id = ? AND c.is_human = 1
             LIMIT 1"
        );
        $stmt->execute([$leagueId]);
        return $stmt->fetch() ?: null;
    }
}
