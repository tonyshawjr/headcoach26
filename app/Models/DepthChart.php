<?php

namespace App\Models;

class DepthChart extends BaseModel
{
    protected string $table = 'depth_chart';

    public function getByTeam(int $teamId): array
    {
        $stmt = $this->db->prepare(
            "SELECT dc.*, p.first_name, p.last_name, p.position, p.overall_rating
             FROM {$this->table} dc
             JOIN players p ON p.id = dc.player_id
             WHERE dc.team_id = ?
             ORDER BY dc.position_group ASC, dc.slot ASC"
        );
        $stmt->execute([$teamId]);
        return $stmt->fetchAll();
    }

    public function setStarter(int $teamId, string $positionGroup, int $slot, int $playerId): bool
    {
        $existing = $this->query(
            "SELECT id FROM {$this->table} WHERE team_id = ? AND position_group = ? AND slot = ?",
            [$teamId, $positionGroup, $slot]
        );

        if (!empty($existing)) {
            $stmt = $this->db->prepare(
                "UPDATE {$this->table} SET player_id = ? WHERE team_id = ? AND position_group = ? AND slot = ?"
            );
            return $stmt->execute([$playerId, $teamId, $positionGroup, $slot]);
        }

        $this->create([
            'team_id' => $teamId,
            'position_group' => $positionGroup,
            'slot' => $slot,
            'player_id' => $playerId,
        ]);
        return true;
    }
}
