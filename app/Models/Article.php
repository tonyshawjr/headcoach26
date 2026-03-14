<?php

namespace App\Models;

class Article extends BaseModel
{
    protected string $table = 'articles';

    public function getByLeague(int $leagueId, ?string $type = null, ?int $week = null, int $limit = 20): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE league_id = ?";
        $params = [$leagueId];

        if ($type !== null) {
            $sql .= " AND type = ?";
            $params[] = $type;
        }
        if ($week !== null) {
            $sql .= " AND week = ?";
            $params[] = $week;
        }

        $sql .= " ORDER BY created_at DESC LIMIT ?";
        $params[] = $limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getByTeam(int $teamId, int $limit = 20): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->table} WHERE team_id = ? ORDER BY created_at DESC LIMIT ?"
        );
        $stmt->execute([$teamId, $limit]);
        return $stmt->fetchAll();
    }

    public function getLatest(int $leagueId, int $limit = 10): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->table} WHERE league_id = ? ORDER BY created_at DESC LIMIT ?"
        );
        $stmt->execute([$leagueId, $limit]);
        return $stmt->fetchAll();
    }
}
