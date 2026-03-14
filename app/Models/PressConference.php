<?php

namespace App\Models;

class PressConference extends BaseModel
{
    protected string $table = 'press_conferences';

    public function getCurrentForCoach(int $coachId, int $week): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->table} WHERE coach_id = ? AND week = ? ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute([$coachId, $week]);
        return $stmt->fetch() ?: null;
    }
}
