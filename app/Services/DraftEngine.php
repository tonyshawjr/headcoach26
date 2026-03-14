<?php

namespace App\Services;

use App\Database\Connection;

class DraftEngine
{
    private \PDO $db;

    private array $firstNames = [
        'James', 'Marcus', 'DeShawn', 'Tyler', 'Caleb', 'Jayden', 'Malik', 'Trevor', 'Ryan', 'Josh',
        'Brandon', 'Chris', 'Michael', 'David', 'Andre', 'Darius', 'Kevin', 'Anthony', 'Justin', 'Austin',
        'Trevon', 'Isaiah', 'Jaylon', 'Derek', 'Patrick', 'Jalen', 'Cameron', 'Devonte', 'Aidan', 'Cole',
        'Elijah', 'Carson', 'Drake', 'Quincy', 'Bryce', 'Tavon', 'Lamar', 'Kyler', 'Tua', 'Jordan',
    ];

    private array $lastNames = [
        'Williams', 'Johnson', 'Smith', 'Brown', 'Davis', 'Jones', 'Wilson', 'Anderson', 'Thomas', 'Jackson',
        'White', 'Harris', 'Martin', 'Thompson', 'Moore', 'Clark', 'Lewis', 'Robinson', 'Walker', 'Hall',
        'Allen', 'Young', 'King', 'Wright', 'Scott', 'Green', 'Baker', 'Adams', 'Nelson', 'Carter',
        'Mitchell', 'Perez', 'Roberts', 'Turner', 'Phillips', 'Campbell', 'Parker', 'Evans', 'Edwards', 'Collins',
    ];

    private array $colleges = [
        'Alabama', 'Ohio State', 'Georgia', 'Clemson', 'LSU', 'Michigan', 'Oklahoma', 'USC',
        'Penn State', 'Oregon', 'Florida', 'Texas', 'Notre Dame', 'Texas A&M', 'Wisconsin',
        'Iowa', 'Auburn', 'Tennessee', 'Miami', 'Florida State', 'Stanford', 'Washington',
        'Virginia Tech', 'NC State', 'Pittsburgh', 'UCF', 'Boise State', 'Memphis', 'Ole Miss',
    ];

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
    }

    /**
     * Generate a full draft class of ~224 prospects (7 rounds x 32 picks).
     */
    public function generateDraftClass(int $leagueId, int $year): int
    {
        $now = date('Y-m-d H:i:s');

        $this->db->prepare(
            "INSERT INTO draft_classes (league_id, year, status, created_at) VALUES (?, ?, 'upcoming', ?)"
        )->execute([$leagueId, $year, $now]);
        $classId = (int) $this->db->lastInsertId();

        // Position distribution for a realistic draft
        $positionSlots = [
            'QB' => 15, 'RB' => 20, 'WR' => 30, 'TE' => 12,
            'OT' => 20, 'OG' => 16, 'C' => 10,
            'DE' => 22, 'DT' => 16, 'LB' => 22, 'CB' => 20, 'S' => 16,
            'K' => 3, 'P' => 2, 'LS' => 2,
        ];

        $prospects = [];
        foreach ($positionSlots as $pos => $count) {
            for ($i = 0; $i < $count; $i++) {
                $prospects[] = $this->generateProspect($classId, $pos);
            }
        }

        // Sort by actual overall to assign projected rounds
        usort($prospects, fn($a, $b) => $b['actual_overall'] - $a['actual_overall']);

        foreach ($prospects as $idx => $p) {
            $projectedRound = match (true) {
                $idx < 32 => 1,
                $idx < 64 => 2,
                $idx < 96 => 3,
                $idx < 128 => 4,
                $idx < 160 => 5,
                $idx < 192 => 6,
                default => 7,
            };

            // Add some randomness to projections
            $projectedRound = max(1, min(7, $projectedRound + mt_rand(-1, 1)));

            $this->db->prepare(
                "INSERT INTO draft_prospects (draft_class_id, first_name, last_name, position, college, age,
                 projected_round, actual_overall, potential, combine_score, positional_ratings, is_drafted)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)"
            )->execute([
                $classId, $p['first_name'], $p['last_name'], $p['position'], $p['college'],
                $p['age'], $projectedRound, $p['actual_overall'], $p['potential'],
                $p['combine_score'], json_encode($p['positional_ratings']),
            ]);
        }

        // Generate draft picks for all teams
        $stmt = $this->db->prepare("SELECT id FROM teams WHERE league_id = ? ORDER BY wins ASC, points_for ASC");
        $stmt->execute([$leagueId]);
        $teamIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $pickNum = 1;
        for ($round = 1; $round <= 7; $round++) {
            foreach ($teamIds as $teamId) {
                $this->db->prepare(
                    "INSERT INTO draft_picks (league_id, draft_class_id, round, pick_number, original_team_id, current_team_id, is_used)
                     VALUES (?, ?, ?, ?, ?, ?, 0)"
                )->execute([$leagueId, $classId, $round, $pickNum, $teamId, $teamId]);
                $pickNum++;
            }
        }

        return $classId;
    }

    /**
     * Scout a prospect (progressively reveal info).
     */
    public function scoutProspect(int $prospectId, int $coachId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM draft_prospects WHERE id = ?");
        $stmt->execute([$prospectId]);
        $prospect = $stmt->fetch();
        if (!$prospect) return ['error' => 'Prospect not found'];

        // Each scout reveals more accuracy
        $scoutedOverall = $prospect['scouted_overall'];
        $scoutedFloor = $prospect['scouted_floor'];
        $scoutedCeiling = $prospect['scouted_ceiling'];

        if ($scoutedOverall === null) {
            // First scout: wide range
            $variance = mt_rand(5, 15);
            $scoutedOverall = $prospect['actual_overall'] + mt_rand(-$variance, $variance);
            $scoutedFloor = $prospect['actual_overall'] - mt_rand(8, 18);
            $scoutedCeiling = $prospect['actual_overall'] + mt_rand(5, 15);
        } else {
            // Subsequent scouts: narrow the range
            $variance = mt_rand(2, 7);
            $scoutedOverall = (int) round(($scoutedOverall + $prospect['actual_overall'] + mt_rand(-$variance, $variance)) / 2);
            $scoutedFloor = (int) round(($scoutedFloor + $prospect['actual_overall'] - mt_rand(3, 8)) / 2);
            $scoutedCeiling = (int) round(($scoutedCeiling + $prospect['actual_overall'] + mt_rand(2, 8)) / 2);
        }

        $scoutedOverall = max(40, min(99, $scoutedOverall));
        $scoutedFloor = max(35, min($scoutedOverall, $scoutedFloor));
        $scoutedCeiling = max($scoutedOverall, min(99, $scoutedCeiling));

        $this->db->prepare(
            "UPDATE draft_prospects SET scouted_overall = ?, scouted_floor = ?, scouted_ceiling = ? WHERE id = ?"
        )->execute([$scoutedOverall, $scoutedFloor, $scoutedCeiling, $prospectId]);

        return [
            'prospect_id' => $prospectId,
            'scouted_overall' => $scoutedOverall,
            'scouted_floor' => $scoutedFloor,
            'scouted_ceiling' => $scoutedCeiling,
            'name' => $prospect['first_name'] . ' ' . $prospect['last_name'],
        ];
    }

    /**
     * Make a draft pick (select a prospect).
     */
    public function makePick(int $pickId, int $prospectId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM draft_picks WHERE id = ? AND is_used = 0");
        $stmt->execute([$pickId]);
        $pick = $stmt->fetch();
        if (!$pick) return ['error' => 'Pick not available'];

        $stmt = $this->db->prepare("SELECT * FROM draft_prospects WHERE id = ? AND is_drafted = 0");
        $stmt->execute([$prospectId]);
        $prospect = $stmt->fetch();
        if (!$prospect) return ['error' => 'Prospect not available'];

        $teamId = $pick['current_team_id'];
        $leagueId = $pick['league_id'];

        // Create the player from the prospect
        $positionalRatings = json_decode($prospect['positional_ratings'], true) ?? [];

        $stmt = $this->db->prepare(
            "INSERT INTO players (team_id, league_id, first_name, last_name, position, age, overall_rating,
             potential, jersey_number, college, status, positional_ratings)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)"
        );
        $stmt->execute([
            $teamId, $leagueId,
            $prospect['first_name'], $prospect['last_name'], $prospect['position'],
            $prospect['age'], $prospect['actual_overall'], $prospect['potential'],
            mt_rand(1, 99), $prospect['college'], json_encode($positionalRatings),
        ]);
        $playerId = (int) $this->db->lastInsertId();

        // Create rookie contract
        $rookieSalary = $this->getRookieSalary($pick['round'], $pick['pick_number']);
        $years = $pick['round'] <= 2 ? 4 : 3;
        $now = date('Y-m-d H:i:s');

        $this->db->prepare(
            "INSERT INTO contracts (player_id, team_id, total_value, yearly_salary, years_total, years_remaining, status, signed_at)
             VALUES (?, ?, ?, ?, ?, ?, 'active', ?)"
        )->execute([$playerId, $teamId, $rookieSalary * $years, $rookieSalary, $years, $years, $now]);

        // Mark pick and prospect as used
        $this->db->prepare("UPDATE draft_picks SET player_id = ?, is_used = 1 WHERE id = ?")
            ->execute([$playerId, $pickId]);
        $this->db->prepare("UPDATE draft_prospects SET is_drafted = 1 WHERE id = ?")
            ->execute([$prospectId]);

        return [
            'success' => true,
            'player_id' => $playerId,
            'name' => $prospect['first_name'] . ' ' . $prospect['last_name'],
            'position' => $prospect['position'],
            'overall' => $prospect['actual_overall'],
            'round' => $pick['round'],
            'pick' => $pick['pick_number'],
        ];
    }

    /**
     * AI makes a draft pick (auto-draft best available by need).
     */
    public function aiMakePick(int $pickId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM draft_picks WHERE id = ?");
        $stmt->execute([$pickId]);
        $pick = $stmt->fetch();
        if (!$pick) return ['error' => 'Pick not found'];

        $teamId = $pick['current_team_id'];

        // Find team's weakest positions
        $needs = $this->getTeamNeeds($teamId);

        // Get available prospects
        $stmt = $this->db->prepare(
            "SELECT * FROM draft_prospects WHERE draft_class_id = ? AND is_drafted = 0
             ORDER BY actual_overall DESC"
        );
        $stmt->execute([$pick['draft_class_id']]);
        $available = $stmt->fetchAll();

        // Pick best prospect that fills a need (with some randomness)
        $bestProspect = null;
        $bestScore = 0;

        foreach (array_slice($available, 0, 15) as $p) {
            $needBonus = in_array($p['position'], $needs) ? 15 : 0;
            $score = $p['actual_overall'] + $needBonus + mt_rand(-5, 5);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestProspect = $p;
            }
        }

        if (!$bestProspect && !empty($available)) {
            $bestProspect = $available[0]; // BPA fallback
        }

        if (!$bestProspect) return ['error' => 'No prospects available'];

        return $this->makePick($pickId, $bestProspect['id']);
    }

    /**
     * Get the draft board (available prospects for display).
     */
    public function getDraftBoard(int $classId, ?string $position = null): array
    {
        $sql = "SELECT * FROM draft_prospects WHERE draft_class_id = ? AND is_drafted = 0";
        $params = [$classId];

        if ($position) {
            $sql .= " AND position = ?";
            $params[] = $position;
        }

        $sql .= " ORDER BY projected_round ASC, actual_overall DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get draft picks for a team.
     */
    public function getTeamPicks(int $classId, int $teamId): array
    {
        $stmt = $this->db->prepare(
            "SELECT dp.*, dpr.first_name as pick_name, dpr.last_name as pick_last, dpr.position as pick_position, dpr.actual_overall as pick_overall
             FROM draft_picks dp
             LEFT JOIN draft_prospects dpr ON dp.player_id IS NOT NULL
             LEFT JOIN players p ON dp.player_id = p.id
             WHERE dp.draft_class_id = ? AND dp.current_team_id = ?
             ORDER BY dp.round"
        );
        $stmt->execute([$classId, $teamId]);
        return $stmt->fetchAll();
    }

    private function generateProspect(int $classId, string $position): array
    {
        $age = mt_rand(20, 23);

        // Bell curve for overall rating, centered based on position scarcity
        $center = 65;
        $stddev = 12;
        $overall = $this->bellCurve($center, $stddev);
        $overall = max(45, min(95, $overall));

        // Top prospects are rarer
        $potential = $this->weightedRandom([
            'elite' => $overall >= 80 ? 20 : 5,
            'high' => $overall >= 70 ? 30 : 15,
            'average' => 50,
            'limited' => $overall < 60 ? 20 : 5,
        ]);

        // Combine score (40-100)
        $combineScore = max(40, min(100, $overall + mt_rand(-15, 15)));

        // Generate position-specific ratings
        $positionalRatings = $this->generatePositionalRatings($position, $overall);

        return [
            'first_name' => $this->firstNames[array_rand($this->firstNames)],
            'last_name' => $this->lastNames[array_rand($this->lastNames)],
            'position' => $position,
            'college' => $this->colleges[array_rand($this->colleges)],
            'age' => $age,
            'actual_overall' => $overall,
            'potential' => $potential,
            'combine_score' => $combineScore,
            'positional_ratings' => $positionalRatings,
        ];
    }

    private function generatePositionalRatings(string $position, int $overall): array
    {
        $variance = 8;
        $r = fn() => max(30, min(99, $overall + mt_rand(-$variance, $variance)));

        return match ($position) {
            'QB' => ['arm_strength' => $r(), 'accuracy' => $r(), 'decision_making' => $r(), 'mobility' => $r()],
            'RB' => ['speed' => $r(), 'power' => $r(), 'vision' => $r(), 'receiving' => $r()],
            'WR' => ['speed' => $r(), 'route_running' => $r(), 'catching' => $r(), 'release' => $r()],
            'TE' => ['blocking' => $r(), 'receiving' => $r(), 'speed' => $r(), 'strength' => $r()],
            'OT', 'OG', 'C' => ['pass_block' => $r(), 'run_block' => $r(), 'strength' => $r(), 'awareness' => $r()],
            'DE' => ['pass_rush' => $r(), 'run_defense' => $r(), 'speed' => $r(), 'power' => $r()],
            'DT' => ['run_stuffing' => $r(), 'pass_rush' => $r(), 'strength' => $r(), 'awareness' => $r()],
            'LB' => ['tackling' => $r(), 'coverage' => $r(), 'blitzing' => $r(), 'instincts' => $r()],
            'CB' => ['coverage' => $r(), 'speed' => $r(), 'ball_skills' => $r(), 'tackling' => $r()],
            'S' => ['coverage' => $r(), 'tackling' => $r(), 'range' => $r(), 'instincts' => $r()],
            'K' => ['accuracy' => $r(), 'power' => $r(), 'clutch' => $r()],
            'P' => ['hangtime' => $r(), 'accuracy' => $r(), 'distance' => $r()],
            'LS' => ['accuracy' => $r(), 'strength' => $r(), 'consistency' => $r()],
            default => ['overall' => $overall],
        };
    }

    private function getTeamNeeds(int $teamId): array
    {
        // Find positions where the starter is rated below 70
        $stmt = $this->db->prepare(
            "SELECT p.position, AVG(p.overall_rating) as avg_rating
             FROM depth_chart dc
             JOIN players p ON dc.player_id = p.id
             WHERE dc.team_id = ? AND dc.slot = 1
             GROUP BY p.position
             HAVING avg_rating < 70
             ORDER BY avg_rating ASC"
        );
        $stmt->execute([$teamId]);
        return array_column($stmt->fetchAll(), 'position');
    }

    private function getRookieSalary(int $round, int $overallPick): int
    {
        return match ($round) {
            1 => 8000000 + max(0, (32 - ($overallPick % 32)) * 500000),
            2 => 3000000 + max(0, (32 - ($overallPick % 32)) * 100000),
            3 => 1500000,
            4 => 1000000,
            5 => 800000,
            6 => 700000,
            7 => 660000,
            default => 660000,
        };
    }

    private function bellCurve(float $center, float $stddev): int
    {
        $u1 = mt_rand(1, 10000) / 10000;
        $u2 = mt_rand(1, 10000) / 10000;
        $z = sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
        return (int) round($center + $z * $stddev);
    }

    private function weightedRandom(array $weights): string
    {
        $total = array_sum($weights);
        $roll = mt_rand(1, $total);
        $cumulative = 0;
        foreach ($weights as $key => $weight) {
            $cumulative += $weight;
            if ($roll <= $cumulative) return $key;
        }
        return array_key_first($weights);
    }
}
