<?php

namespace App\Models;

class SocialPost extends BaseModel
{
    protected string $table = 'social_posts';

    public function getByLeague(int $leagueId, ?int $week = null, int $limit = 30): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE league_id = ?";
        $params = [$leagueId];

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
}
