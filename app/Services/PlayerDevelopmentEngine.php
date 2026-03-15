<?php

namespace App\Services;

use App\Models\Player;
use App\Database\Connection;

/**
 * PlayerDevelopmentEngine — Handles all player growth and decline.
 *
 * Three systems:
 *   1. recalculateOverall() — Recomputes OVR from current attributes
 *   2. processWeeklyDevelopment() — In-season growth AND regression
 *   3. Performance-based modifiers — Players who perform well grow faster
 *
 * OVR Calculation:
 *   Each position has weighted attributes. The OVR is a weighted average
 *   of the position-relevant attributes, with key attributes weighted higher.
 *
 * In-Season Development:
 *   - Young (≤25) + elite/high potential: chance to gain +1 to an attribute each week
 *   - Performance bonus: players with good recent game grades develop faster
 *   - Old (≥31): chance to lose -1 to an attribute each week (regression)
 *   - OVR is always recalculated after changes
 */
class PlayerDevelopmentEngine
{
    private \PDO $db;

    // Position-specific key attributes and their weights for OVR calculation
    // Higher weight = more important to overall rating
    private const POSITION_WEIGHTS = [
        'QB' => [
            'throw_accuracy_short' => 3, 'throw_accuracy_mid' => 3, 'throw_accuracy_deep' => 2,
            'throw_power' => 2, 'throw_under_pressure' => 2, 'throw_on_the_run' => 1.5,
            'awareness' => 2, 'play_action' => 1, 'break_sack' => 1,
            'speed' => 0.5, 'acceleration' => 0.5, 'agility' => 0.5, 'stamina' => 0.5,
        ],
        'RB' => [
            'speed' => 3, 'acceleration' => 2.5, 'agility' => 2, 'bc_vision' => 3,
            'break_tackle' => 2, 'trucking' => 1.5, 'juke_move' => 1.5, 'spin_move' => 1,
            'carrying' => 2, 'catching' => 1, 'stamina' => 1, 'strength' => 1,
            'change_of_direction' => 1.5, 'stiff_arm' => 1,
        ],
        'WR' => [
            'speed' => 3, 'acceleration' => 2, 'catching' => 3, 'catch_in_traffic' => 2,
            'short_route_running' => 2.5, 'medium_route_running' => 2.5, 'deep_route_running' => 2,
            'release' => 2, 'spectacular_catch' => 1, 'agility' => 1, 'jumping' => 1,
        ],
        'TE' => [
            'catching' => 2.5, 'catch_in_traffic' => 1.5, 'short_route_running' => 2,
            'medium_route_running' => 1.5, 'run_block' => 2, 'pass_block' => 1.5,
            'speed' => 1.5, 'strength' => 1.5, 'release' => 1, 'awareness' => 1,
        ],
        'OT' => [
            'pass_block' => 3, 'pass_block_finesse' => 2.5, 'pass_block_power' => 2.5,
            'run_block' => 2.5, 'run_block_finesse' => 2, 'run_block_power' => 2,
            'strength' => 2, 'awareness' => 1.5, 'stamina' => 1,
        ],
        'OG' => [
            'pass_block' => 2.5, 'pass_block_finesse' => 2, 'pass_block_power' => 2.5,
            'run_block' => 3, 'run_block_finesse' => 2, 'run_block_power' => 2.5,
            'strength' => 2, 'awareness' => 1.5, 'impact_blocking' => 1.5,
        ],
        'C' => [
            'pass_block' => 2.5, 'run_block' => 2.5, 'awareness' => 3,
            'pass_block_finesse' => 2, 'pass_block_power' => 2,
            'run_block_finesse' => 2, 'run_block_power' => 2, 'strength' => 2,
        ],
        'DE' => [
            'finesse_moves' => 3, 'power_moves' => 3, 'block_shedding' => 2.5,
            'speed' => 2, 'acceleration' => 1.5, 'pursuit' => 2, 'play_recognition' => 1.5,
            'tackle' => 1.5, 'hit_power' => 1, 'strength' => 1.5,
        ],
        'DT' => [
            'block_shedding' => 3, 'power_moves' => 3, 'strength' => 2.5,
            'finesse_moves' => 2, 'pursuit' => 1.5, 'play_recognition' => 1.5,
            'tackle' => 2, 'hit_power' => 1.5, 'awareness' => 1,
        ],
        'LB' => [
            'tackle' => 3, 'pursuit' => 2.5, 'play_recognition' => 2.5,
            'zone_coverage' => 2, 'man_coverage' => 1.5, 'block_shedding' => 2,
            'speed' => 1.5, 'hit_power' => 1.5, 'power_moves' => 1, 'awareness' => 1,
        ],
        'CB' => [
            'man_coverage' => 3, 'zone_coverage' => 2.5, 'press' => 2.5,
            'speed' => 2.5, 'acceleration' => 2, 'agility' => 1.5,
            'play_recognition' => 2, 'tackle' => 1, 'jumping' => 1,
        ],
        'S' => [
            'zone_coverage' => 3, 'man_coverage' => 2, 'play_recognition' => 2.5,
            'tackle' => 2, 'hit_power' => 1.5, 'speed' => 2, 'pursuit' => 1.5,
            'acceleration' => 1, 'awareness' => 1.5,
        ],
        'K' => [
            'kick_accuracy' => 4, 'kick_power' => 3, 'awareness' => 1,
        ],
        'P' => [
            'kick_power' => 4, 'kick_accuracy' => 3, 'awareness' => 1,
        ],
    ];

    // Attributes that can develop in-season, by position
    private const DEV_ATTRS = [
        'QB' => ['throw_accuracy_short', 'throw_accuracy_mid', 'throw_accuracy_deep', 'awareness', 'throw_under_pressure', 'throw_on_the_run'],
        'RB' => ['bc_vision', 'speed', 'break_tackle', 'carrying', 'acceleration', 'juke_move'],
        'WR' => ['short_route_running', 'medium_route_running', 'catching', 'release', 'speed', 'catch_in_traffic'],
        'TE' => ['catching', 'run_block', 'speed', 'strength', 'short_route_running'],
        'OT' => ['pass_block', 'pass_block_finesse', 'pass_block_power', 'run_block', 'strength', 'awareness'],
        'OG' => ['pass_block', 'run_block', 'run_block_power', 'strength', 'awareness'],
        'C'  => ['pass_block', 'run_block', 'awareness', 'strength'],
        'DE' => ['finesse_moves', 'power_moves', 'speed', 'block_shedding', 'pursuit'],
        'DT' => ['block_shedding', 'power_moves', 'strength', 'awareness', 'tackle'],
        'LB' => ['tackle', 'zone_coverage', 'pursuit', 'play_recognition', 'block_shedding'],
        'CB' => ['man_coverage', 'zone_coverage', 'speed', 'press', 'play_recognition'],
        'S'  => ['zone_coverage', 'tackle', 'play_recognition', 'speed', 'hit_power'],
        'K'  => ['kick_accuracy', 'kick_power'],
        'P'  => ['kick_power', 'kick_accuracy'],
    ];

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
    }

    /**
     * Recalculate a player's overall_rating from their current attributes.
     * Returns the new OVR.
     */
    public function recalculateOverall(array $player): int
    {
        $position = $player['position'] ?? 'QB';
        $weights = self::POSITION_WEIGHTS[$position] ?? self::POSITION_WEIGHTS['QB'];

        $totalWeight = 0;
        $weightedSum = 0;

        foreach ($weights as $attr => $weight) {
            $val = (int) ($player[$attr] ?? 0);
            if ($val > 0) {
                $weightedSum += $val * $weight;
                $totalWeight += $weight;
            }
        }

        if ($totalWeight <= 0) {
            return (int) ($player['overall_rating'] ?? 70);
        }

        $newOvr = (int) round($weightedSum / $totalWeight);
        return max(40, min(99, $newOvr));
    }

    /**
     * Recalculate and update OVR for a single player in the database.
     */
    public function updatePlayerOverall(int $playerId): int
    {
        $stmt = $this->db->prepare("SELECT * FROM players WHERE id = ?");
        $stmt->execute([$playerId]);
        $player = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$player) return 0;

        $newOvr = $this->recalculateOverall($player);
        $this->db->prepare("UPDATE players SET overall_rating = ? WHERE id = ?")->execute([$newOvr, $playerId]);
        return $newOvr;
    }

    /**
     * Recalculate OVR for ALL players in a league.
     */
    public function recalculateAllOveralls(int $leagueId): int
    {
        $stmt = $this->db->prepare("SELECT * FROM players WHERE league_id = ? AND status IN ('active', 'practice_squad', 'injured_reserve')");
        $stmt->execute([$leagueId]);
        $players = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $updateStmt = $this->db->prepare("UPDATE players SET overall_rating = ? WHERE id = ?");
        $updated = 0;

        foreach ($players as $p) {
            $newOvr = $this->recalculateOverall($p);
            if ($newOvr !== (int) $p['overall_rating']) {
                $updateStmt->execute([$newOvr, $p['id']]);
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Process weekly in-season development for ALL players.
     * Called after each week's games are simulated.
     *
     * Handles:
     *   - Young player growth (≤25, high/elite potential)
     *   - Performance-based bonus (good game grades boost development chance)
     *   - Old player regression (≥31, random attribute decline)
     *   - OVR recalculation after any changes
     *
     * @return array{developed: int, regressed: int}
     */
    public function processWeeklyDevelopment(int $leagueId, int $currentWeek): array
    {
        // Exclude injured players — can't develop while on the sideline
        $stmt = $this->db->prepare(
            "SELECT p.* FROM players p
             WHERE p.league_id = ? AND p.status = 'active'
               AND p.id NOT IN (
                   SELECT i.player_id FROM injuries i WHERE i.weeks_remaining > 0
               )"
        );
        $stmt->execute([$leagueId]);
        $players = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $developed = 0;
        $regressed = 0;

        foreach ($players as $p) {
            $age = (int) $p['age'];
            $potential = $p['potential'] ?? 'average';
            $position = $p['position'];
            $changed = false;

            // ── YOUNG PLAYER DEVELOPMENT ──
            if ($age <= 25 && in_array($potential, ['elite', 'high', 'superstar', 'star'])) {
                // Base chance
                $chance = ($potential === 'elite' || $potential === 'superstar') ? 22 : 12;

                // Performance bonus: check last game grade
                $perfBonus = $this->getPerformanceBonus((int) $p['id'], $currentWeek);
                $chance += $perfBonus;

                if (mt_rand(1, 100) <= $chance) {
                    $attrs = self::DEV_ATTRS[$position] ?? [];
                    if (!empty($attrs)) {
                        $attr = $attrs[array_rand($attrs)];
                        $currentVal = (int) ($p[$attr] ?? 50);
                        if ($currentVal < 99) {
                            $newVal = min(99, $currentVal + 1);
                            $this->db->exec("UPDATE players SET {$attr} = {$newVal} WHERE id = {$p['id']}");
                            $p[$attr] = $newVal; // update local copy for OVR recalc
                            $changed = true;
                            $developed++;
                        }
                    }
                }
            }

            // Average potential young players can still develop, just slower
            if ($age <= 27 && ($potential === 'average' || $potential === 'normal')) {
                $perfBonus = $this->getPerformanceBonus((int) $p['id'], $currentWeek);
                // Only develop if performing well (bonus >= 5 means good game)
                if ($perfBonus >= 5 && mt_rand(1, 100) <= 5 + $perfBonus) {
                    $attrs = self::DEV_ATTRS[$position] ?? [];
                    if (!empty($attrs)) {
                        $attr = $attrs[array_rand($attrs)];
                        $currentVal = (int) ($p[$attr] ?? 50);
                        if ($currentVal < 95) { // average potential caps lower
                            $newVal = min(95, $currentVal + 1);
                            $this->db->exec("UPDATE players SET {$attr} = {$newVal} WHERE id = {$p['id']}");
                            $p[$attr] = $newVal;
                            $changed = true;
                            $developed++;
                        }
                    }
                }
            }

            // ── OLD PLAYER REGRESSION ──
            if ($age >= 31) {
                // Chance increases with age: 31=5%, 33=15%, 35=25%, 37=35%
                $regressChance = max(3, ($age - 29) * 5);

                if (mt_rand(1, 100) <= $regressChance) {
                    $attrs = self::DEV_ATTRS[$position] ?? [];
                    if (!empty($attrs)) {
                        // Tend to lose speed/agility first
                        $physicalFirst = array_intersect($attrs, ['speed', 'acceleration', 'agility', 'stamina', 'jumping']);
                        $pool = !empty($physicalFirst) && mt_rand(1, 100) <= 60 ? array_values($physicalFirst) : $attrs;

                        $attr = $pool[array_rand($pool)];
                        $currentVal = (int) ($p[$attr] ?? 50);
                        if ($currentVal > 40) {
                            $newVal = max(40, $currentVal - 1);
                            $this->db->exec("UPDATE players SET {$attr} = {$newVal} WHERE id = {$p['id']}");
                            $p[$attr] = $newVal;
                            $changed = true;
                            $regressed++;
                        }
                    }
                }
            }

            // ── RECALCULATE OVR ──
            if ($changed) {
                $newOvr = $this->recalculateOverall($p);
                $this->db->exec("UPDATE players SET overall_rating = {$newOvr} WHERE id = {$p['id']}");
            }
        }

        return ['developed' => $developed, 'regressed' => $regressed];
    }

    /**
     * Get a performance bonus based on recent game grades.
     * Returns 0-15 bonus to development chance.
     *
     * Looks at the player's game grade from the most recent week.
     * A+ grade = +15 bonus, A = +10, B = +5, C = 0, D/F = -5
     */
    private function getPerformanceBonus(int $playerId, int $currentWeek): int
    {
        // Look at the most recent game grade
        $stmt = $this->db->prepare(
            "SELECT gs.grade FROM game_stats gs
             JOIN games g ON g.id = gs.game_id
             WHERE gs.player_id = ? AND g.week = ?
             ORDER BY gs.id DESC LIMIT 1"
        );
        $stmt->execute([$playerId, $currentWeek]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row || !$row['grade']) return 0;

        $grade = strtoupper(trim($row['grade']));

        return match (true) {
            $grade === 'A+' || (float) $grade >= 95 => 15,
            $grade === 'A' || (float) $grade >= 85 => 10,
            $grade === 'B+' || (float) $grade >= 80 => 7,
            $grade === 'B' || (float) $grade >= 75 => 5,
            $grade === 'C+' || (float) $grade >= 70 => 2,
            $grade === 'C' || (float) $grade >= 65 => 0,
            $grade === 'D' || (float) $grade >= 55 => -3,
            default => -5, // F grade
        };
    }
}
