<?php

namespace App\Models;

class League extends BaseModel
{
    protected string $table = 'leagues';

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE slug = ?");
        $stmt->execute([$slug]);
        return $stmt->fetch() ?: null;
    }

    public function getCurrentSeason(?int $leagueId = null): ?array
    {
        if ($leagueId) {
            $stmt = $this->db->prepare(
                "SELECT s.* FROM seasons s WHERE s.league_id = ? AND s.is_current = 1 LIMIT 1"
            );
            $stmt->execute([$leagueId]);
        } else {
            $stmt = $this->db->prepare(
                "SELECT s.* FROM seasons s WHERE s.is_current = 1 LIMIT 1"
            );
            $stmt->execute();
        }
        return $stmt->fetch() ?: null;
    }
}
