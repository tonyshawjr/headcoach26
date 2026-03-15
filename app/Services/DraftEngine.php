<?php

namespace App\Services;

use App\Database\Connection;

class DraftEngine
{
    private \PDO $db;

    const SCOUTS_PER_WEEK = 10;  // 10 scouting points per week

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

        // Initialize stock ratings for the college season
        $collegeEngine = new CollegeSeasonEngine();
        $collegeEngine->initializeStockRatings($classId);

        return $classId;
    }

    /**
     * Generate prospects for an existing draft class.
     * Count is based on league size: teams × 7 rounds + ~20% undrafted pool.
     */
    public function generateProspectsForClass(int $classId): int
    {
        // Get team count from the draft class's league
        $stmt = $this->db->prepare(
            "SELECT league_id FROM draft_classes WHERE id = ?"
        );
        $stmt->execute([$classId]);
        $leagueId = (int) ($stmt->fetchColumn() ?: 0);

        $teamCount = 32;
        if ($leagueId) {
            $teamCount = (int) $this->db->query(
                "SELECT COUNT(*) FROM teams WHERE league_id = {$leagueId}"
            )->fetchColumn() ?: 32;
        }

        // Total prospects = picks in draft + undrafted pool (~20%)
        $totalPicks = $teamCount * 7;
        $totalProspects = (int) ($totalPicks * 1.20);

        // ── NFL-realistic position distribution ──────────────────────
        // Based on actual NFL draft data: which positions get drafted most
        $positionWeights = [
            'QB' => 0.06, 'RB' => 0.06, 'WR' => 0.13, 'TE' => 0.05,
            'OT' => 0.09, 'OG' => 0.06, 'C' => 0.04,
            'DE' => 0.10, 'DT' => 0.07, 'LB' => 0.09, 'CB' => 0.10, 'S' => 0.07,
            'K' => 0.01, 'P' => 0.01, 'LS' => 0.005,
        ];

        // Normalize and calculate counts
        $totalWeight = array_sum($positionWeights);
        $positionSlots = [];
        foreach ($positionWeights as $pos => $w) {
            $positionSlots[$pos] = max(1, (int) round(($w / $totalWeight) * $totalProspects));
        }

        // ── Position draft ceiling — max OVR a rookie at this position can be ──
        // Even the best college players enter the NFL needing development
        $positionCeiling = [
            'QB' => 80, 'DE' => 80, 'OT' => 79, 'CB' => 79, 'WR' => 79,
            'DT' => 78, 'LB' => 78, 'S' => 78, 'TE' => 77,
            'RB' => 77, 'OG' => 76, 'C' => 75,
            'K' => 72, 'P' => 70, 'LS' => 65,
        ];

        // ── Position draft floor — minimum round positions typically go ──
        // Premium positions are drafted earlier, specialists go late
        $positionFloor = [
            'QB' => 1, 'DE' => 1, 'OT' => 1, 'CB' => 1, 'WR' => 1,
            'DT' => 1, 'LB' => 1, 'S' => 1, 'TE' => 1,
            'RB' => 1, 'OG' => 2, 'C' => 2,
            'K' => 4, 'P' => 5, 'LS' => 7,
        ];

        $prospects = [];
        $generationalCount = 0;

        foreach ($positionSlots as $pos => $count) {
            $ceiling = $positionCeiling[$pos] ?? 90;
            $floor = $positionFloor[$pos] ?? 3;

            for ($i = 0; $i < $count; $i++) {
                $p = $this->generateProspect($classId, $pos);

                // Apply position ceiling
                $p['actual_overall'] = min($ceiling, $p['actual_overall']);

                // ── Generational talent (0-2 per class) ──────────────
                // A generational prospect is a 76-80 OVR rookie with elite potential
                // They're special because they'll develop into superstars, not because
                // they're already great. Think: Caleb Williams enters at 78 but projects to 95+
                if ($generationalCount < 2 && $i === 0 && $p['actual_overall'] >= 70
                    && in_array($pos, ['QB', 'DE', 'OT', 'WR', 'CB'])
                    && mt_rand(1, 100) <= 8) {
                    $p['actual_overall'] = mt_rand(76, $ceiling);
                    $p['potential'] = 'elite';
                    $p['combine_score'] = mt_rand(88, 98);
                    $p['character_flag'] = null;
                    $generationalCount++;
                }

                $prospects[] = $p;
            }
        }

        // ── Sort by talent and assign projected rounds ────────────────
        usort($prospects, fn($a, $b) => $b['actual_overall'] <=> $a['actual_overall']);

        $picksPerRound = $teamCount;

        foreach ($prospects as $idx => $p) {
            $pos = $p['position'];
            $ovr = $p['actual_overall'];
            $minRound = $positionFloor[$pos] ?? 1;

            // Base projected round from ranking
            $baseRound = match (true) {
                $idx < $picksPerRound => 1,
                $idx < $picksPerRound * 2 => 2,
                $idx < $picksPerRound * 3 => 3,
                $idx < $picksPerRound * 4 => 4,
                $idx < $picksPerRound * 5 => 5,
                $idx < $picksPerRound * 6 => 6,
                default => 7,
            };

            // Apply position floor (centers don't go Round 1, kickers don't go Round 2)
            $projectedRound = max($minRound, $baseRound);

            // Small randomness (±1 round for mid-rounders)
            if ($projectedRound >= 2 && $projectedRound <= 6) {
                $projectedRound = max($minRound, min(7, $projectedRound + mt_rand(-1, 1)));
            }

            // Projected pick within round (1-32 based on position in tier)
            $projectedPick = ($idx % $picksPerRound) + 1;

            // Combine grade based on athleticism
            $combineGrade = match (true) {
                $p['combine_score'] >= 90 => 'A+',
                $p['combine_score'] >= 82 => 'A',
                $p['combine_score'] >= 74 => 'B+',
                $p['combine_score'] >= 66 => 'B',
                $p['combine_score'] >= 58 => 'C+',
                $p['combine_score'] >= 50 => 'C',
                default => 'D',
            };

            $this->db->prepare(
                "INSERT INTO draft_prospects (draft_class_id, first_name, last_name, position, college, age,
                 projected_round, actual_overall, potential, combine_score, combine_grade,
                 positional_ratings, is_drafted)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)"
            )->execute([
                $classId, $p['first_name'], $p['last_name'], $p['position'], $p['college'],
                $p['age'], $projectedRound, $p['actual_overall'], $p['potential'],
                $p['combine_score'], $combineGrade, json_encode($p['positional_ratings']),
            ]);
        }

        return count($prospects);
    }

    /**
     * Scout a prospect (progressively reveal info).
     * Enforces a per-draft-class scouting budget.
     */
    /**
     * Get the current week's scouting budget.
     * 4 scouts per week. Resets each week. Once a prospect is scouted, they stay scouted.
     */
    public function getScoutingBudget(int $leagueId): array
    {
        $stmt = $this->db->prepare("SELECT current_week FROM leagues WHERE id = ?");
        $stmt->execute([$leagueId]);
        $currentWeek = (int) ($stmt->fetchColumn() ?: 0);

        $classId = $this->getCurrentClassId($leagueId);

        // Count scouts used THIS week only
        $usedThisWeek = 0;
        $totalScouted = 0;
        if ($classId) {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM draft_prospects WHERE draft_class_id = ? AND scouted_week = ?"
            );
            $stmt->execute([$classId, $currentWeek]);
            $usedThisWeek = (int) $stmt->fetchColumn();

            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM draft_prospects WHERE draft_class_id = ? AND scouted_overall IS NOT NULL"
            );
            $stmt->execute([$classId]);
            $totalScouted = (int) $stmt->fetchColumn();
        }

        return [
            'used_this_week' => $usedThisWeek,
            'per_week' => self::SCOUTS_PER_WEEK,
            'remaining' => max(0, self::SCOUTS_PER_WEEK - $usedThisWeek),
            'total_scouted' => $totalScouted,
            'week' => $currentWeek,
        ];
    }

    public function scoutProspect(int $prospectId, int $teamId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM draft_prospects WHERE id = ?");
        $stmt->execute([$prospectId]);
        $prospect = $stmt->fetch();
        if (!$prospect) return ['error' => 'Prospect not found'];

        $classId = $prospect['draft_class_id'];

        // Get league for budget calculation
        $stmt = $this->db->prepare("SELECT league_id FROM draft_classes WHERE id = ?");
        $stmt->execute([$classId]);
        $leagueId = (int) ($stmt->fetchColumn() ?: 0);

        $budget = $this->getScoutingBudget($leagueId);
        $currentWeek = $budget['week'];
        $scoutLevel = (int) ($prospect['scout_level'] ?? 0);

        // Max 3 scout levels
        if ($scoutLevel >= 3) {
            return [
                'error' => $prospect['first_name'] . ' ' . $prospect['last_name'] . ' is fully scouted. No more information to uncover.',
                'scouts_remaining' => $budget['remaining'],
            ];
        }

        // Check weekly budget
        if ($budget['remaining'] <= 0) {
            return [
                'error' => "No scouting points left this week ({$budget['used_this_week']}/{$budget['per_week']} used). New points unlock next week.",
                'scouts_remaining' => 0,
            ];
        }

        $actual = (int) $prospect['actual_overall'];
        $potential = $prospect['potential'] ?? 'average';
        $newLevel = $scoutLevel + 1;

        // Each level reveals more and narrows the OVR range
        $scoutedOverall = $prospect['scouted_overall'];
        $scoutedFloor = $prospect['scouted_floor'];
        $scoutedCeiling = $prospect['scouted_ceiling'];

        if ($newLevel === 1) {
            // Level 1: wide range, rough estimate. Could be off.
            $noise = mt_rand(-5, 5);
            $scoutedOverall = $actual + $noise;
            $scoutedFloor = $actual - mt_rand(6, 10);
            $scoutedCeiling = $actual + mt_rand(5, 10);
        } elseif ($newLevel === 2) {
            // Level 2: range NARROWS — floor goes UP, ceiling comes DOWN toward truth
            // But ceiling never drops below what L1 showed (no bait-and-switch)
            $prevFloor = (int) $scoutedFloor;
            $prevCeiling = (int) $scoutedCeiling;

            $scoutedOverall = (int) round(($scoutedOverall + $actual) / 2) + mt_rand(-1, 1);
            $scoutedFloor = max($prevFloor, $actual - mt_rand(3, 6)); // floor goes UP
            $scoutedCeiling = min($prevCeiling, $actual + mt_rand(3, 6)); // ceiling comes DOWN

            // Ensure ceiling stays above floor and OVR
            $scoutedCeiling = max($scoutedCeiling, $scoutedOverall + 1);
        } else {
            // Level 3: very tight, near exact. Floor goes up again, ceiling tightens.
            $prevFloor = (int) $scoutedFloor;

            $scoutedOverall = $actual + mt_rand(-1, 1);
            $scoutedFloor = max($prevFloor, $actual - mt_rand(1, 3)); // floor only goes UP
            $scoutedCeiling = $actual + mt_rand(1, 3);
        }

        $scoutedOverall = max(45, min(85, (int) $scoutedOverall));
        $scoutedFloor = max(40, min($scoutedOverall, (int) $scoutedFloor));
        $scoutedCeiling = max($scoutedOverall, min(88, (int) $scoutedCeiling));

        // Generate attribute grades based on scout level
        $positionalRatings = json_decode($prospect['positional_ratings'] ?? '{}', true) ?: [];
        $allAttrs = array_keys($positionalRatings);
        $strengths = [];
        $weaknesses = [];
        $revealedGrades = [];

        // How many attributes to reveal at each level
        $attrCount = count($allAttrs);
        $revealCount = match ($newLevel) {
            1 => min(2, $attrCount),       // Level 1: reveal 2 attributes
            2 => min(4, $attrCount),       // Level 2: reveal 4 total (2 new)
            default => $attrCount,          // Level 3: reveal all
        };

        // Deterministic order (same attributes revealed each time for this prospect)
        // Use prospect ID as seed so it's consistent
        $orderedAttrs = $allAttrs;
        mt_srand((int) $prospect['id'] * 7);
        shuffle($orderedAttrs);
        mt_srand(); // reset

        $revealedAttrs = array_slice($orderedAttrs, 0, $revealCount);

        foreach ($revealedAttrs as $attr) {
            $value = $positionalRatings[$attr] ?? 50;
            $label = ucwords(str_replace('_', ' ', $attr));
            $grade = self::attributeToGrade((int) $value);
            $revealedGrades[$attr] = $grade;

            if ($grade === 'A') $strengths[] = "Elite {$label}";
            elseif ($grade === 'B') $strengths[] = "Strong {$label}";
            elseif ($grade === 'D') $weaknesses[] = "Below-average {$label}";
            elseif ($grade === 'F') $weaknesses[] = "Weak {$label}";
        }

        // Level 1: reveals development trait
        // Level 2: adds strengths/weaknesses + character/injury flags
        // Level 3: adds tier tag (Generational/Blue Chip)
        if ($newLevel >= 1 && $potential === 'elite') {
            $strengths[] = 'Exceptional development trajectory';
        } elseif ($newLevel >= 1 && $potential === 'high') {
            $strengths[] = 'Strong upside';
        } elseif ($newLevel >= 1 && $potential === 'limited') {
            $weaknesses[] = 'Limited ceiling';
        }

        if ($newLevel >= 2) {
            if ($prospect['character_flag']) {
                $weaknesses[] = 'Character concerns: ' . ucwords(str_replace('_', ' ', $prospect['character_flag']));
            }
            if ($prospect['injury_flag']) {
                $weaknesses[] = 'Injury history: ' . $prospect['injury_flag'];
            }
        }

        $this->db->prepare(
            "UPDATE draft_prospects SET scouted_overall = ?, scouted_floor = ?, scouted_ceiling = ?, scouted_week = ?, scout_level = ? WHERE id = ?"
        )->execute([$scoutedOverall, $scoutedFloor, $scoutedCeiling, $currentWeek, $newLevel, $prospectId]);

        $newBudget = $this->getScoutingBudget($leagueId);

        // Tier tag only at level 3
        $tier = null;
        if ($newLevel >= 3) {
            $tier = self::calculateTier($actual, $potential);
        }

        return [
            'prospect_id' => $prospectId,
            'name' => $prospect['first_name'] . ' ' . $prospect['last_name'],
            'position' => $prospect['position'],
            'college' => $prospect['college'],
            'age' => (int) $prospect['age'],
            'scout_level' => $newLevel,
            'scouted_overall' => $scoutedOverall,
            'scouted_floor' => $scoutedFloor,
            'scouted_ceiling' => $scoutedCeiling,
            'overall_range_low' => $scoutedFloor,
            'overall_range_high' => $scoutedCeiling,
            'potential' => $potential,
            'tier' => $tier,
            'attribute_grades' => $revealedGrades,
            'strengths' => $strengths,
            'weaknesses' => $weaknesses,
            'scouts_remaining' => $newBudget['remaining'],
        ];
    }

    /**
     * Get full prospect profile for their dedicated page.
     */
    public function getProspectProfile(int $prospectId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM draft_prospects WHERE id = ?");
        $stmt->execute([$prospectId]);
        $prospect = $stmt->fetch();
        if (!$prospect) return null;

        $isScouted = $prospect['scouted_overall'] !== null;
        $actual = (int) $prospect['actual_overall'];
        $potential = $prospect['potential'] ?? 'average';
        $positionalRatings = json_decode($prospect['positional_ratings'] ?? '{}', true) ?: [];
        $weeklyLog = json_decode($prospect['weekly_log'] ?? '[]', true) ?: [];
        $highlights = json_decode($prospect['season_highlights'] ?? '[]', true) ?: [];

        $scoutLevel = (int) ($prospect['scout_level'] ?? 0);

        $profile = [
            'id' => (int) $prospect['id'],
            'first_name' => $prospect['first_name'],
            'last_name' => $prospect['last_name'],
            'position' => $prospect['position'],
            'college' => $prospect['college'],
            'age' => (int) $prospect['age'],
            'projected_round' => (int) $prospect['projected_round'],
            'stock_rating' => (int) ($prospect['stock_rating'] ?? 50),
            'stock_trend' => $prospect['stock_trend'] ?? 'steady',
            'is_drafted' => (bool) $prospect['is_drafted'],
            'injury_flag' => $prospect['injury_flag'],
            'character_flag' => $prospect['character_flag'],
            'buzz' => $prospect['buzz'],
            'scout_level' => $scoutLevel,
            'is_scouted' => $scoutLevel > 0,
            'is_favorited' => (bool) ($prospect['is_favorited'] ?? false),
            'draft_board_rank' => $prospect['draft_board_rank'] ? (int) $prospect['draft_board_rank'] : null,
        ];

        // College game log (always visible — public info)
        $profile['game_log'] = $weeklyLog;
        $profile['season_highlights'] = $highlights;

        // ── Level 1+: OVR range + development + 2 attribute grades ──
        if ($scoutLevel >= 1) {
            $profile['scouted_overall'] = (int) $prospect['scouted_overall'];
            $profile['scouted_floor'] = (int) $prospect['scouted_floor'];
            $profile['scouted_ceiling'] = (int) $prospect['scouted_ceiling'];
            $profile['potential'] = $potential;
        }

        // Build attribute grades based on scout level
        $allAttrs = array_keys($positionalRatings);
        $attrCount = count($allAttrs);
        $revealCount = match ($scoutLevel) {
            1 => min(2, $attrCount),
            2 => min(4, $attrCount),
            3 => $attrCount,
            default => 0,
        };

        if ($revealCount > 0) {
            // Same deterministic order as scoutProspect
            $orderedAttrs = $allAttrs;
            mt_srand((int) $prospect['id'] * 7);
            shuffle($orderedAttrs);
            mt_srand();

            $revealedAttrs = array_slice($orderedAttrs, 0, $revealCount);
            $grades = [];
            $strengths = [];
            $weaknesses = [];

            foreach ($revealedAttrs as $attr) {
                $value = $positionalRatings[$attr] ?? 50;
                $label = ucwords(str_replace('_', ' ', $attr));
                $grade = self::attributeToGrade((int) $value);
                $grades[$attr] = $grade;

                if ($grade === 'A') $strengths[] = "Elite {$label}";
                elseif ($grade === 'B') $strengths[] = "Strong {$label}";
                elseif ($grade === 'D') $weaknesses[] = "Below-average {$label}";
                elseif ($grade === 'F') $weaknesses[] = "Weak {$label}";
            }

            // Development insight at level 1+
            if ($potential === 'elite') $strengths[] = 'Exceptional development trajectory';
            elseif ($potential === 'high') $strengths[] = 'Strong upside';
            elseif ($potential === 'limited') $weaknesses[] = 'Limited ceiling';

            $profile['attribute_grades'] = $grades;
            $profile['strengths'] = $strengths;
            $profile['weaknesses'] = $weaknesses;
        }

        // ── Level 2+: character/injury concerns ─────────────────────
        if ($scoutLevel >= 2) {
            if ($prospect['character_flag']) {
                $profile['weaknesses'][] = 'Character: ' . ucwords(str_replace('_', ' ', $prospect['character_flag']));
            }
        }

        // ── Level 3: tier tag (Generational, Blue Chip, etc.) ───────
        if ($scoutLevel >= 3) {
            $profile['tier'] = self::calculateTier($actual, $potential);
        }

        return $profile;
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

        // Create the player from the prospect — translate prospect ratings to real attributes
        $positionalRatings = json_decode($prospect['positional_ratings'], true) ?? [];
        $ovr = (int) $prospect['actual_overall'];
        $pos = $prospect['position'];

        // Map prospect positional ratings to actual player attribute columns
        $attrMap = $this->mapProspectToPlayerAttributes($pos, $positionalRatings, $ovr);

        // Build the INSERT with all attribute columns
        $baseCols = [
            'team_id' => $teamId, 'league_id' => $leagueId,
            'first_name' => $prospect['first_name'], 'last_name' => $prospect['last_name'],
            'position' => $pos, 'age' => $prospect['age'],
            'overall_rating' => $ovr, 'potential' => $prospect['potential'],
            'jersey_number' => mt_rand(1, 99), 'college' => $prospect['college'],
            'status' => 'active', 'is_rookie' => 1, 'experience' => 0,
            'personality' => ['team_player', 'competitor', 'quiet_professional', 'vocal_leader'][mt_rand(0, 3)],
            'morale' => 'content',
            'positional_ratings' => json_encode($positionalRatings),
        ];
        $allCols = array_merge($baseCols, $attrMap);
        $colNames = implode(', ', array_keys($allCols));
        $placeholders = implode(', ', array_fill(0, count($allCols), '?'));

        $stmt = $this->db->prepare("INSERT INTO players ({$colNames}) VALUES ({$placeholders})");
        $stmt->execute(array_values($allCols));
        $playerId = (int) $this->db->lastInsertId();

        // Create rookie contract via ContractEngine
        $contractEngine = new ContractEngine();
        $rookieContract = $contractEngine->generateRookieContract($pick['round'], $pick['pick_number']);
        $now = date('Y-m-d H:i:s');

        $this->db->prepare(
            "INSERT INTO contracts (player_id, team_id, years_total, years_remaining, salary_annual,
             cap_hit, guaranteed, dead_cap, signing_bonus, base_salary, contract_type, total_value, status, signed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)"
        )->execute([
            $playerId, $teamId,
            $rookieContract['years_total'], $rookieContract['years_remaining'],
            $rookieContract['salary_annual'], $rookieContract['cap_hit'],
            $rookieContract['guaranteed'], $rookieContract['dead_cap'],
            $rookieContract['signing_bonus'], $rookieContract['base_salary'],
            $rookieContract['contract_type'], $rookieContract['total_value'],
            $now,
        ]);

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
     * Get the current draft class for a league.
     */
    public function getDraftClass(int $leagueId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM draft_classes WHERE league_id = ? AND status = 'upcoming' ORDER BY year ASC LIMIT 1"
        );
        $stmt->execute([$leagueId]);
        $class = $stmt->fetch();
        if (!$class) {
            // Fallback: any draft class for this league
            $stmt = $this->db->prepare(
                "SELECT * FROM draft_classes WHERE league_id = ? ORDER BY year ASC LIMIT 1"
            );
            $stmt->execute([$leagueId]);
            $class = $stmt->fetch();
        }
        if (!$class) return null;

        // Count prospects
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM draft_prospects WHERE draft_class_id = ?");
        $stmt->execute([$class['id']]);
        $total = (int) $stmt->fetchColumn();

        // Top positions
        $stmt = $this->db->prepare(
            "SELECT position, COUNT(*) as cnt FROM draft_prospects WHERE draft_class_id = ? GROUP BY position ORDER BY cnt DESC"
        );
        $stmt->execute([$class['id']]);
        $topPos = [];
        while ($row = $stmt->fetch()) {
            $topPos[$row['position']] = (int) $row['cnt'];
        }

        return [
            'id' => (int) $class['id'],
            'year' => (int) $class['year'],
            'total_prospects' => $total,
            'top_positions' => $topPos,
            'strength' => $total >= 200 ? 'deep' : ($total >= 100 ? 'average' : 'thin'),
            'status' => $class['status'],
        ];
    }

    /**
     * Get the current draft class ID for a league (helper).
     */
    public function getCurrentClassId(int $leagueId): ?int
    {
        $class = $this->getDraftClass($leagueId);
        return $class ? $class['id'] : null;
    }

    /**
     * Get the draft board (available prospects for display).
     * Returns enriched prospect data with stock info and scouting status.
     */
    public function getDraftBoard(int $classId, ?string $position = null): array
    {
        $sql = "SELECT * FROM draft_prospects WHERE draft_class_id = ? AND is_drafted = 0";
        $params = [$classId];

        if ($position) {
            $sql .= " AND position = ?";
            $params[] = $position;
        }

        // Sort by stock rating (current hype) first, then projected round
        $sql .= " ORDER BY stock_rating DESC, projected_round ASC, actual_overall DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $prospects = $stmt->fetchAll();

        // Enrich each prospect
        foreach ($prospects as &$p) {
            $ovr = (int) $p['actual_overall'];
            $stock = (int) ($p['stock_rating'] ?: 50);
            $potential = $p['potential'] ?? 'average';

            // Prospect tier label (only shown if scouted to level 3)
            $p['tier'] = ((int) ($p['scout_level'] ?? 0) >= 3)
                ? self::calculateTier($ovr, $potential)
                : null;

            // Stock trend arrow
            $p['trend_label'] = match ($p['stock_trend'] ?? 'steady') {
                'rising' => 'Rising Fast',
                'up' => 'Trending Up',
                'steady' => 'Steady',
                'down' => 'Trending Down',
                'falling' => 'Falling Fast',
                default => 'Steady',
            };

            // Parse weekly log for latest performance
            $weeklyLog = json_decode($p['weekly_log'] ?? '[]', true) ?: [];
            $latestWeek = !empty($weeklyLog) ? end($weeklyLog) : null;
            $p['latest_performance'] = $latestWeek ? ($latestWeek['narrative'] ?? null) : null;
            $p['latest_week'] = $latestWeek ? ($latestWeek['week'] ?? null) : null;

            // Clean up large JSON fields for the list view
            unset($p['weekly_log'], $p['season_highlights']);
        }
        unset($p);

        return $prospects;
    }

    /**
     * Get draft picks for a team — includes "via" team info and trade provenance.
     */
    public function getTeamPicks(int $classId, int $teamId): array
    {
        $stmt = $this->db->prepare(
            "SELECT dp.*,
                    dc.year,
                    orig_t.city as original_city, orig_t.name as original_name,
                    orig_t.abbreviation as original_abbreviation,
                    orig_t.primary_color as original_color,
                    orig_t.wins as original_wins, orig_t.losses as original_losses
             FROM draft_picks dp
             JOIN draft_classes dc ON dp.draft_class_id = dc.id
             LEFT JOIN teams orig_t ON dp.original_team_id = orig_t.id
             WHERE dp.draft_class_id = ? AND dp.current_team_id = ?
             ORDER BY dc.year ASC, dp.round ASC, dp.pick_number ASC"
        );
        $stmt->execute([$classId, $teamId]);
        $picks = $stmt->fetchAll();

        // Enrich each pick with provenance
        foreach ($picks as &$pick) {
            $isOwn = ((int) $pick['original_team_id'] === $teamId);
            $pick['is_own_pick'] = $isOwn;
            $pick['via_team'] = $isOwn ? null : $pick['original_abbreviation'];
            $pick['via_city'] = $isOwn ? null : $pick['original_city'];
            $pick['via_name'] = $isOwn ? null : $pick['original_name'];

            // Build label: "2026 Round 1" or "2026 Round 1 (via PHI)"
            $label = $pick['year'] . ' Round ' . $pick['round'];
            if (!$isOwn && $pick['original_abbreviation']) {
                $label .= ' (via ' . $pick['original_abbreviation'] . ')';
            }
            $pick['label'] = $label;

            // Projected pick position based on original team's record
            // Worse record = lower pick number = better pick
            if (!$pick['is_used']) {
                $origWins = (int) ($pick['original_wins'] ?? 0);
                $origLosses = (int) ($pick['original_losses'] ?? 0);
                $pick['original_record'] = $origWins . '-' . $origLosses;
            }

            // Get trade history for this pick
            $histStmt = $this->db->prepare(
                "SELECT dph.*, ft.abbreviation as from_abbr, ft.city as from_city,
                        tt.abbreviation as to_abbr, tt.city as to_city
                 FROM draft_pick_history dph
                 LEFT JOIN teams ft ON dph.from_team_id = ft.id
                 LEFT JOIN teams tt ON dph.to_team_id = tt.id
                 WHERE dph.draft_pick_id = ?
                 ORDER BY dph.transferred_at ASC"
            );
            $histStmt->execute([(int) $pick['id']]);
            $history = $histStmt->fetchAll();
            $pick['trade_history'] = array_map(fn($h) => [
                'from' => $h['from_abbr'],
                'to' => $h['to_abbr'],
                'date' => $h['transferred_at'],
            ], $history);
        }
        unset($pick);

        return $picks;
    }

    /**
     * Recalculate draft order based on team records.
     * Worst team gets pick #1, best team gets pick #32.
     * Called at end of season before the draft.
     */
    public function recalculateDraftOrder(int $leagueId): void
    {
        // Get all draft classes for this league that haven't been used
        $stmt = $this->db->prepare(
            "SELECT id, year FROM draft_classes WHERE league_id = ? AND status IN ('upcoming', 'future') ORDER BY year"
        );
        $stmt->execute([$leagueId]);
        $classes = $stmt->fetchAll();

        if (empty($classes)) return;

        // Get teams sorted by record (worst first = lowest pick number)
        $stmt = $this->db->prepare(
            "SELECT id, wins, losses, ties, points_for, points_against
             FROM teams WHERE league_id = ?
             ORDER BY
                (CASE WHEN (wins + losses + ties) > 0 THEN CAST(wins AS FLOAT) / (wins + losses + ties) ELSE 0.5 END) ASC,
                (points_for - points_against) ASC"
        );
        $stmt->execute([$leagueId]);
        $teams = $stmt->fetchAll();

        $teamOrder = [];
        foreach ($teams as $i => $team) {
            $teamOrder[(int) $team['id']] = $i + 1; // 1-based pick position
        }

        // Update pick numbers for each draft class
        foreach ($classes as $class) {
            $classId = (int) $class['id'];

            // Get all picks for this class, grouped by round
            for ($round = 1; $round <= 7; $round++) {
                $stmt = $this->db->prepare(
                    "SELECT id, original_team_id FROM draft_picks
                     WHERE draft_class_id = ? AND round = ? AND is_used = 0
                     ORDER BY original_team_id"
                );
                $stmt->execute([$classId, $round]);
                $roundPicks = $stmt->fetchAll();

                // Sort picks by original team's draft position
                usort($roundPicks, function ($a, $b) use ($teamOrder) {
                    $posA = $teamOrder[(int) $a['original_team_id']] ?? 16;
                    $posB = $teamOrder[(int) $b['original_team_id']] ?? 16;
                    return $posA <=> $posB;
                });

                // Update pick numbers
                $basePickNum = ($round - 1) * count($teamOrder);
                foreach ($roundPicks as $i => $pick) {
                    $newPickNumber = $basePickNum + $i + 1;
                    $this->db->prepare(
                        "UPDATE draft_picks SET pick_number = ? WHERE id = ?"
                    )->execute([$newPickNumber, (int) $pick['id']]);
                }
            }
        }
    }

    /**
     * Log a pick trade in the history table.
     */
    public function logPickTrade(int $pickId, int $fromTeamId, int $toTeamId, ?int $tradeId = null): void
    {
        $this->db->prepare(
            "INSERT INTO draft_pick_history (draft_pick_id, from_team_id, to_team_id, trade_id, transferred_at)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$pickId, $fromTeamId, $toTeamId, $tradeId, date('Y-m-d H:i:s')]);
    }

    /**
     * Calculate prospect tier from OVR + development potential.
     * Development is the key differentiator:
     *   - Generational: high OVR + elite dev (will become a superstar)
     *   - Blue Chip: good OVR + elite/high dev (will be very good)
     *   - Average/Limited dev caps the tier no matter how high the OVR
     */
    public static function calculateTier(int $ovr, string $potential): string
    {
        // Development multiplier — this is what separates tiers
        $devScore = match ($potential) {
            'elite' => 20,
            'high' => 10,
            'average' => 0,
            'limited' => -10,
            default => 0,
        };

        // Combined score: OVR + development bonus
        $combined = $ovr + $devScore;

        // Average/Limited development CAPS the tier
        if ($potential === 'limited') {
            // Limited dev = Day 3 at best, no matter the OVR
            return match (true) {
                $combined >= 65 => 'Day 2 Pick',
                $combined >= 55 => 'Day 3 Pick',
                default => 'Priority Free Agent',
            };
        }

        if ($potential === 'average') {
            // Average dev = First Rounder at best
            return match (true) {
                $combined >= 72 => 'First Rounder',
                $combined >= 62 => 'Day 2 Pick',
                $combined >= 55 => 'Day 3 Pick',
                default => 'Priority Free Agent',
            };
        }

        // High and Elite development can reach the top tiers
        return match (true) {
            $combined >= 92 && $potential === 'elite' => 'Generational',
            $combined >= 82 => 'Blue Chip',
            $combined >= 72 => 'First Rounder',
            $combined >= 62 => 'Day 2 Pick',
            $combined >= 55 => 'Day 3 Pick',
            default => 'Priority Free Agent',
        };
    }

    private function generateProspect(int $classId, string $position): array
    {
        $age = mt_rand(20, 23);

        // Realistic rookie OVR distribution:
        // NFL rookies are typically 58-80 OVR. Even #1 overall picks are ~75-80.
        // Late rounders are 55-65. Undrafted are 50-60.
        // Bell curve centered at 62 with stddev 7 gives us a realistic spread.
        $center = 62;
        $stddev = 7;
        $overall = $this->bellCurve($center, $stddev);
        $overall = max(48, min(82, $overall));

        // Development potential — this is the real differentiator
        // High potential players will BECOME great, they don't start great
        $potential = $this->weightedRandom([
            'elite' => $overall >= 72 ? 10 : 2,
            'high' => $overall >= 65 ? 25 : 10,
            'average' => 50,
            'limited' => $overall < 60 ? 25 : 10,
        ]);

        // Combine score (athleticism, separate from game OVR)
        $combineScore = max(40, min(98, $overall + mt_rand(-12, 18)));

        // Generate position-specific ratings
        $positionalRatings = $this->generatePositionalRatings($position, $overall, $potential);

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

    /**
     * Generate position-specific attribute ratings.
     * Development potential directly affects the attribute profile:
     *   - Elite: 1-2 standout attributes (A-grade), rest solid
     *   - High: 1 strong attribute, rest above average
     *   - Average: flat, everything near OVR
     *   - Limited: weak spots, maybe 1 decent attribute
     *
     * Grades use ABSOLUTE thresholds (not relative to OVR):
     *   A = 78+, B = 70-77, C = 60-69, D = 50-59, F = <50
     */
    private function generatePositionalRatings(string $position, int $overall, string $potential = 'average'): array
    {
        $attrs = match ($position) {
            'QB' => ['arm_strength', 'accuracy', 'decision_making', 'mobility'],
            'RB' => ['speed', 'power', 'vision', 'receiving'],
            'WR' => ['speed', 'route_running', 'catching', 'release'],
            'TE' => ['blocking', 'receiving', 'speed', 'strength'],
            'OT', 'OG', 'C' => ['pass_block', 'run_block', 'strength', 'awareness'],
            'DE' => ['pass_rush', 'run_defense', 'speed', 'power'],
            'DT' => ['run_stuffing', 'pass_rush', 'strength', 'awareness'],
            'LB' => ['tackling', 'coverage', 'blitzing', 'instincts'],
            'CB' => ['coverage', 'speed', 'ball_skills', 'tackling'],
            'S' => ['coverage', 'tackling', 'range', 'instincts'],
            'K' => ['accuracy', 'power', 'clutch'],
            'P' => ['hangtime', 'accuracy', 'distance'],
            'LS' => ['accuracy', 'strength', 'consistency'],
            default => ['overall'],
        };

        $ratings = [];
        $count = count($attrs);

        // Build attribute profile based on development potential
        if ($potential === 'elite') {
            // Elite: 1-2 standout attributes well above OVR, rest solid
            $standoutCount = min($count, mt_rand(1, 2));
            $standoutIndices = array_rand($attrs, $standoutCount);
            if (!is_array($standoutIndices)) $standoutIndices = [$standoutIndices];

            foreach ($attrs as $i => $attr) {
                if (in_array($i, $standoutIndices)) {
                    // Standout: 10-18 points above OVR (this is what makes them special)
                    $ratings[$attr] = max(40, min(95, $overall + mt_rand(10, 18)));
                } else {
                    // Solid: near or slightly above OVR
                    $ratings[$attr] = max(40, min(90, $overall + mt_rand(-3, 6)));
                }
            }
        } elseif ($potential === 'high') {
            // High: 1 strong attribute, rest above average
            $strongIdx = array_rand($attrs);
            foreach ($attrs as $i => $attr) {
                if ($i === $strongIdx) {
                    $ratings[$attr] = max(40, min(92, $overall + mt_rand(6, 14)));
                } else {
                    $ratings[$attr] = max(40, min(88, $overall + mt_rand(-4, 5)));
                }
            }
        } elseif ($potential === 'average') {
            // Average: everything clusters near OVR, no standouts
            foreach ($attrs as $attr) {
                $ratings[$attr] = max(35, min(85, $overall + mt_rand(-6, 6)));
            }
        } else {
            // Limited: weak spots, some attributes notably below OVR
            $weakCount = min($count, mt_rand(1, 2));
            $weakIndices = array_rand($attrs, $weakCount);
            if (!is_array($weakIndices)) $weakIndices = [$weakIndices];

            foreach ($attrs as $i => $attr) {
                if (in_array($i, $weakIndices)) {
                    // Weak spot: 8-15 points below OVR
                    $ratings[$attr] = max(30, min(75, $overall - mt_rand(8, 15)));
                } else {
                    // Rest near OVR
                    $ratings[$attr] = max(35, min(82, $overall + mt_rand(-4, 4)));
                }
            }
        }

        return $ratings;
    }

    /**
     * Convert a raw attribute value to a letter grade.
     * Uses ABSOLUTE thresholds — same scale regardless of player OVR.
     * This means the grades are meaningful: A = excellent, F = terrible.
     */
    public static function attributeToGrade(int $value): string
    {
        return match (true) {
            $value >= 82 => 'A',
            $value >= 72 => 'B',
            $value >= 62 => 'C',
            $value >= 52 => 'D',
            default => 'F',
        };
    }

    /**
     * Map prospect positional ratings to actual player attribute columns.
     * Prospects have simplified ratings (4 attrs). Players have 50+ attrs.
     * We derive the detailed attributes from the prospect's ratings + OVR.
     */
    private function mapProspectToPlayerAttributes(string $position, array $prospectRatings, int $ovr): array
    {
        $r = fn(int $base) => max(35, min(95, $base + mt_rand(-4, 4)));
        $low = fn() => $r(max(40, $ovr - mt_rand(10, 20)));  // weak attribute
        $mid = fn() => $r($ovr);                               // average
        $hi = fn(int $val) => $r($val);                        // from prospect rating

        // Base physical attributes for all positions
        $attrs = [
            'speed' => $mid(), 'strength' => $mid(), 'awareness' => $mid(),
            'acceleration' => $mid(), 'agility' => $mid(), 'stamina' => $r(max(60, $ovr + 5)),
            'jumping' => $mid(), 'toughness' => $mid(), 'injury_prone' => mt_rand(15, 40),
        ];

        // Position-specific attribute mapping from prospect grades
        match ($position) {
            'QB' => $attrs = array_merge($attrs, [
                'throw_power' => $hi($prospectRatings['arm_strength'] ?? $ovr),
                'throw_accuracy_short' => $hi($prospectRatings['accuracy'] ?? $ovr),
                'throw_accuracy_mid' => $r(($prospectRatings['accuracy'] ?? $ovr) - 2),
                'throw_accuracy_deep' => $r(($prospectRatings['accuracy'] ?? $ovr) - 5),
                'throw_under_pressure' => $r(($prospectRatings['decision_making'] ?? $ovr) - 3),
                'throw_on_the_run' => $hi($prospectRatings['mobility'] ?? $ovr),
                'break_sack' => $r($prospectRatings['mobility'] ?? $ovr),
                'play_action' => $mid(),
                'speed' => $hi($prospectRatings['mobility'] ?? ($ovr - 5)),
            ]),
            'RB' => $attrs = array_merge($attrs, [
                'speed' => $hi($prospectRatings['speed'] ?? $ovr),
                'bc_vision' => $hi($prospectRatings['vision'] ?? $ovr),
                'break_tackle' => $hi($prospectRatings['power'] ?? $ovr),
                'trucking' => $r($prospectRatings['power'] ?? ($ovr - 3)),
                'juke_move' => $r($prospectRatings['speed'] ?? ($ovr - 2)),
                'carrying' => $mid(),
                'catching' => $hi($prospectRatings['receiving'] ?? ($ovr - 5)),
            ]),
            'WR' => $attrs = array_merge($attrs, [
                'speed' => $hi($prospectRatings['speed'] ?? $ovr),
                'short_route_running' => $hi($prospectRatings['route_running'] ?? $ovr),
                'medium_route_running' => $r(($prospectRatings['route_running'] ?? $ovr) - 2),
                'deep_route_running' => $r(($prospectRatings['route_running'] ?? $ovr) - 4),
                'catching' => $hi($prospectRatings['catching'] ?? $ovr),
                'catch_in_traffic' => $r(($prospectRatings['catching'] ?? $ovr) - 3),
                'release' => $hi($prospectRatings['release'] ?? $ovr),
            ]),
            'TE' => $attrs = array_merge($attrs, [
                'catching' => $hi($prospectRatings['receiving'] ?? $ovr),
                'short_route_running' => $r(($prospectRatings['receiving'] ?? $ovr) - 3),
                'run_block' => $hi($prospectRatings['blocking'] ?? $ovr),
                'pass_block' => $r(($prospectRatings['blocking'] ?? $ovr) - 5),
                'speed' => $hi($prospectRatings['speed'] ?? ($ovr - 3)),
                'strength' => $hi($prospectRatings['strength'] ?? $ovr),
            ]),
            'OT', 'OG', 'C' => $attrs = array_merge($attrs, [
                'pass_block' => $hi($prospectRatings['pass_block'] ?? $ovr),
                'pass_block_finesse' => $r(($prospectRatings['pass_block'] ?? $ovr) - 2),
                'pass_block_power' => $r(($prospectRatings['pass_block'] ?? $ovr) - 2),
                'run_block' => $hi($prospectRatings['run_block'] ?? $ovr),
                'run_block_finesse' => $r(($prospectRatings['run_block'] ?? $ovr) - 2),
                'run_block_power' => $r(($prospectRatings['run_block'] ?? $ovr) - 2),
                'strength' => $hi($prospectRatings['strength'] ?? $ovr),
                'awareness' => $hi($prospectRatings['awareness'] ?? $ovr),
            ]),
            'DE' => $attrs = array_merge($attrs, [
                'finesse_moves' => $hi($prospectRatings['pass_rush'] ?? $ovr),
                'power_moves' => $hi($prospectRatings['power'] ?? $ovr),
                'block_shedding' => $r($prospectRatings['run_defense'] ?? $ovr),
                'speed' => $hi($prospectRatings['speed'] ?? $ovr),
                'pursuit' => $mid(), 'tackle' => $mid(),
            ]),
            'DT' => $attrs = array_merge($attrs, [
                'block_shedding' => $hi($prospectRatings['run_stuffing'] ?? $ovr),
                'power_moves' => $hi($prospectRatings['pass_rush'] ?? $ovr),
                'finesse_moves' => $r(($prospectRatings['pass_rush'] ?? $ovr) - 5),
                'strength' => $hi($prospectRatings['strength'] ?? $ovr),
                'awareness' => $hi($prospectRatings['awareness'] ?? $ovr),
                'pursuit' => $mid(), 'tackle' => $mid(),
            ]),
            'LB' => $attrs = array_merge($attrs, [
                'tackle' => $hi($prospectRatings['tackling'] ?? $ovr),
                'man_coverage' => $r(($prospectRatings['coverage'] ?? $ovr) - 3),
                'zone_coverage' => $hi($prospectRatings['coverage'] ?? $ovr),
                'block_shedding' => $hi($prospectRatings['blitzing'] ?? ($ovr - 3)),
                'pursuit' => $hi($prospectRatings['instincts'] ?? $ovr),
                'play_recognition' => $r($prospectRatings['instincts'] ?? $ovr),
                'hit_power' => $mid(),
            ]),
            'CB' => $attrs = array_merge($attrs, [
                'man_coverage' => $hi($prospectRatings['coverage'] ?? $ovr),
                'zone_coverage' => $r(($prospectRatings['coverage'] ?? $ovr) - 3),
                'speed' => $hi($prospectRatings['speed'] ?? $ovr),
                'press' => $r($prospectRatings['coverage'] ?? ($ovr - 3)),
                'play_recognition' => $hi($prospectRatings['ball_skills'] ?? $ovr),
                'tackle' => $hi($prospectRatings['tackling'] ?? ($ovr - 5)),
            ]),
            'S' => $attrs = array_merge($attrs, [
                'zone_coverage' => $hi($prospectRatings['coverage'] ?? $ovr),
                'man_coverage' => $r(($prospectRatings['coverage'] ?? $ovr) - 5),
                'tackle' => $hi($prospectRatings['tackling'] ?? $ovr),
                'hit_power' => $r($prospectRatings['tackling'] ?? ($ovr - 2)),
                'speed' => $hi($prospectRatings['range'] ?? $ovr),
                'play_recognition' => $hi($prospectRatings['instincts'] ?? $ovr),
                'pursuit' => $mid(),
            ]),
            'K' => $attrs = array_merge($attrs, [
                'kick_accuracy' => $hi($prospectRatings['accuracy'] ?? $ovr),
                'kick_power' => $hi($prospectRatings['power'] ?? $ovr),
            ]),
            'P' => $attrs = array_merge($attrs, [
                'kick_power' => $hi($prospectRatings['distance'] ?? $ovr),
                'kick_accuracy' => $hi($prospectRatings['accuracy'] ?? $ovr),
            ]),
            default => null,
        };

        // Fill any missing standard columns with defaults
        $defaults = [
            'throw_accuracy_short' => $low(), 'throw_accuracy_mid' => $low(),
            'throw_accuracy_deep' => $low(), 'throw_power' => $low(),
            'throw_under_pressure' => $low(), 'throw_on_the_run' => $low(),
            'break_sack' => $low(), 'play_action' => $low(),
            'bc_vision' => $low(), 'break_tackle' => $low(), 'trucking' => $low(),
            'carrying' => $low(), 'juke_move' => $low(), 'spin_move' => $low(),
            'stiff_arm' => $low(),
            'catching' => $low(), 'catch_in_traffic' => $low(),
            'short_route_running' => $low(), 'medium_route_running' => $low(),
            'deep_route_running' => $low(), 'spectacular_catch' => $low(), 'release' => $low(),
            'pass_block' => $low(), 'pass_block_finesse' => $low(), 'pass_block_power' => $low(),
            'run_block' => $low(), 'run_block_finesse' => $low(), 'run_block_power' => $low(),
            'impact_blocking' => $low(), 'lead_block' => $low(),
            'block_shedding' => $low(), 'finesse_moves' => $low(), 'power_moves' => $low(),
            'hit_power' => $low(), 'man_coverage' => $low(), 'zone_coverage' => $low(),
            'press' => $low(), 'play_recognition' => $low(), 'pursuit' => $low(), 'tackle' => $low(),
            'kick_accuracy' => $low(), 'kick_power' => $low(), 'kick_return' => $low(),
            'change_of_direction' => $mid(),
        ];

        foreach ($defaults as $col => $defaultVal) {
            if (!isset($attrs[$col])) {
                $attrs[$col] = $defaultVal;
            }
        }

        return $attrs;
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
