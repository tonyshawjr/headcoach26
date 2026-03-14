<?php

namespace App\Models;

class Team extends BaseModel
{
    protected string $table = 'teams';

    public function getByLeague(int $leagueId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE league_id = ? ORDER BY city ASC");
        $stmt->execute([$leagueId]);
        return $stmt->fetchAll();
    }

    public function getByDivision(int $leagueId, string $conference, string $division): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->table} WHERE league_id = ? AND conference = ? AND division = ? ORDER BY city ASC"
        );
        $stmt->execute([$leagueId, $conference, $division]);
        return $stmt->fetchAll();
    }
}
