<?php

namespace App\Models;

class Game extends BaseModel
{
    protected string $table = 'games';

    public function getByWeek(int $leagueId, int $week): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->table} WHERE league_id = ? AND week = ? ORDER BY id ASC"
        );
        $stmt->execute([$leagueId, $week]);
        return $stmt->fetchAll();
    }

    public function getByTeam(int $teamId, int $seasonId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->table}
             WHERE season_id = ? AND (home_team_id = ? OR away_team_id = ?)
             ORDER BY week ASC"
        );
        $stmt->execute([$seasonId, $teamId, $teamId]);
        return $stmt->fetchAll();
    }

    public function getNextForTeam(int $teamId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->table}
             WHERE (home_team_id = ? OR away_team_id = ?) AND is_simulated = 0
             ORDER BY week ASC
             LIMIT 1"
        );
        $stmt->execute([$teamId, $teamId]);
        return $stmt->fetch() ?: null;
    }
}
