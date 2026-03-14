<?php

namespace App\Models;

class TickerItem extends BaseModel
{
    protected string $table = 'ticker_items';

    public function getLatest(int $leagueId, int $limit = 20): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->table} WHERE league_id = ? ORDER BY created_at DESC LIMIT ?"
        );
        $stmt->execute([$leagueId, $limit]);
        return $stmt->fetchAll();
    }
}
