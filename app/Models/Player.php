<?php

namespace App\Models;

class Player extends BaseModel
{
    protected string $table = 'players';

    // ── Stat column groups (used by controllers to build structured responses) ──

    public const PHYSICAL_STATS = [
        'speed', 'acceleration', 'agility', 'jumping', 'stamina', 'strength', 'toughness',
    ];

    public const BALL_CARRIER_STATS = [
        'bc_vision', 'break_tackle', 'carrying', 'change_of_direction',
        'juke_move', 'spin_move', 'stiff_arm', 'trucking',
    ];

    public const RECEIVING_STATS = [
        'catch_in_traffic', 'catching', 'deep_route_running',
        'medium_route_running', 'short_route_running', 'spectacular_catch', 'release',
    ];

    public const BLOCKING_STATS = [
        'impact_blocking', 'lead_block', 'pass_block', 'pass_block_finesse',
        'pass_block_power', 'run_block', 'run_block_finesse', 'run_block_power',
    ];

    public const DEFENSE_STATS = [
        'block_shedding', 'finesse_moves', 'hit_power', 'man_coverage',
        'play_recognition', 'power_moves', 'press', 'pursuit', 'tackle', 'zone_coverage',
    ];

    public const QUARTERBACK_STATS = [
        'break_sack', 'play_action', 'throw_accuracy_deep', 'throw_accuracy_mid',
        'throw_accuracy_short', 'throw_on_the_run', 'throw_power', 'throw_under_pressure',
    ];

    public const KICKING_STATS = [
        'kick_accuracy', 'kick_power', 'kick_return',
    ];

    /**
     * Map of position -> which stat categories are relevant.
     */
    public const POSITION_STAT_CATEGORIES = [
        'QB'  => ['physical', 'quarterback', 'ball_carrier'],
        'RB'  => ['physical', 'ball_carrier', 'receiving'],
        'FB'  => ['physical', 'ball_carrier', 'blocking'],
        'WR'  => ['physical', 'receiving', 'ball_carrier'],
        'TE'  => ['physical', 'receiving', 'blocking'],
        'OT'  => ['physical', 'blocking'],
        'OG'  => ['physical', 'blocking'],
        'C'   => ['physical', 'blocking'],
        'DE'  => ['physical', 'defense'],
        'DT'  => ['physical', 'defense'],
        'LB'  => ['physical', 'defense'],
        'CB'  => ['physical', 'defense'],
        'S'   => ['physical', 'defense'],
        'K'   => ['physical', 'kicking'],
        'P'   => ['physical', 'kicking'],
    ];

    /**
     * Return the stat arrays keyed by category name.
     */
    public static function getStatColumns(string $category): array
    {
        return match ($category) {
            'physical'     => self::PHYSICAL_STATS,
            'ball_carrier' => self::BALL_CARRIER_STATS,
            'receiving'    => self::RECEIVING_STATS,
            'blocking'     => self::BLOCKING_STATS,
            'defense'      => self::DEFENSE_STATS,
            'quarterback'  => self::QUARTERBACK_STATS,
            'kicking'      => self::KICKING_STATS,
            default        => [],
        };
    }

    /**
     * Build a grouped ratings array for a player row, including only
     * the categories relevant to the player's position.
     */
    public static function buildGroupedRatings(array $player): array
    {
        $pos = $player['position'] ?? '';
        $categories = self::POSITION_STAT_CATEGORIES[$pos] ?? ['physical'];
        $ratings = [];

        foreach ($categories as $cat) {
            $cols = self::getStatColumns($cat);
            $group = [];
            foreach ($cols as $col) {
                $group[$col] = isset($player[$col]) ? (int) $player[$col] : null;
            }
            $ratings[$cat] = $group;
        }

        return $ratings;
    }

    // ── Query helpers ──────────────────────────────────────────────────

    public function getByTeam(int $teamId, string $status = 'active'): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->table} WHERE team_id = ? AND status = ? ORDER BY position ASC, overall_rating DESC"
        );
        $stmt->execute([$teamId, $status]);
        return $stmt->fetchAll();
    }

    public function getByPosition(int $teamId, string $position): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->table} WHERE team_id = ? AND position = ? ORDER BY overall_rating DESC"
        );
        $stmt->execute([$teamId, $position]);
        return $stmt->fetchAll();
    }

    public function getTopByRating(int $leagueId, int $limit = 25): array
    {
        $stmt = $this->db->prepare(
            "SELECT p.* FROM {$this->table} p
             JOIN teams t ON t.id = p.team_id
             WHERE t.league_id = ? AND p.status = 'active'
             ORDER BY p.overall_rating DESC
             LIMIT ?"
        );
        $stmt->execute([$leagueId, $limit]);
        return $stmt->fetchAll();
    }
}
