<?php

namespace App\Services;

use App\Database\Connection;

/**
 * CollegeSeasonEngine — Simulates the college football season running
 * parallel to the pro season. Each week, prospects have performances
 * that move their draft stock up or down with storylines.
 *
 * Prospects aren't static numbers — they're living stories.
 */
class CollegeSeasonEngine
{
    private \PDO $db;

    // Performance outcome templates by position
    private const PERFORMANCES = [
        'QB' => [
            'elite' => [
                '{name} threw for 4 TDs and 380 yards in a dominant win over {rival}.',
                '{name} was nearly perfect, completing 28-of-32 passes as {college} rolled.',
                '{name} led a game-winning drive in the final minute against {rival}.',
                '{name} accounted for 5 total TDs (3 passing, 2 rushing) in a blowout.',
            ],
            'good' => [
                '{name} tossed 2 TDs with no picks in a solid outing vs {rival}.',
                '{name} managed the game well, going 22-for-30 with 240 yards.',
                '{name} showed poise in the pocket, leading {college} to a road win.',
            ],
            'average' => [
                '{name} had a quiet day — 18-for-28, 195 yards, 1 TD vs {rival}.',
                '{name} was up-and-down but {college} pulled out the W.',
                '{name} didn\'t wow anyone but avoided costly mistakes.',
            ],
            'bad' => [
                '{name} threw 2 interceptions in a sloppy loss to {rival}.',
                '{name} struggled with accuracy, completing just 12-of-28 passes.',
                '{name} was sacked 5 times and looked flustered all game.',
            ],
            'terrible' => [
                '{name} threw 3 picks and fumbled twice in a humiliating loss.',
                '{name} benched in the 3rd quarter after 4 turnovers vs {rival}.',
                '{name} looked completely lost against {rival}\'s blitz packages.',
            ],
        ],
        'RB' => [
            'elite' => [
                '{name} ran for 185 yards and 3 TDs on 22 carries against {rival}.',
                '{name} broke off a 75-yard TD run and finished with 200+ yards.',
                '{name} was unstoppable — 28 carries, 210 yards, 2 TDs.',
            ],
            'good' => [
                '{name} rushed for 120 yards and a TD in a balanced attack.',
                '{name} showed burst and vision, averaging 5.8 YPC vs {rival}.',
            ],
            'average' => [
                '{name} had a workmanlike 80 yards on 18 carries.',
                '{name} was bottled up early but broke a 30-yarder in the 4th.',
            ],
            'bad' => [
                '{name} was held to 45 yards on 15 carries by {rival}\'s front seven.',
                '{name} fumbled twice and was benched in the second half.',
            ],
            'terrible' => [
                '{name} gained just 22 yards on 12 carries and lost a fumble.',
                '{name} had a forgettable night — 18 yards, a fumble, and an injury scare.',
            ],
        ],
        'WR' => [
            'elite' => [
                '{name} hauled in 9 catches for 175 yards and 2 TDs against {rival}.',
                '{name} made the catch of the year — a one-handed grab in triple coverage.',
                '{name} torched {rival}\'s secondary for 3 TDs.',
            ],
            'good' => [
                '{name} had 6 catches for 95 yards and a crucial 3rd-down conversion.',
                '{name} was reliable all day, finishing with 7 catches for 110 yards.',
            ],
            'average' => [
                '{name} finished with 4 catches for 55 yards in a run-heavy game.',
                '{name} was quiet but made a key block on the game-winning TD.',
            ],
            'bad' => [
                '{name} dropped two catchable balls, including one in the end zone.',
                '{name} was shut down by {rival}\'s top corner — 2 catches, 18 yards.',
            ],
            'terrible' => [
                '{name} had 3 drops and a costly fumble in a blowout loss.',
                '{name} was invisible — 1 catch for 8 yards on 7 targets.',
            ],
        ],
        'default' => [
            'elite' => [
                '{name} dominated the line of scrimmage with 3 sacks and 8 tackles.',
                '{name} was a one-man wrecking crew, disrupting every play.',
                '{name} had a pick-six and 2 TFLs in a defensive masterclass.',
            ],
            'good' => [
                '{name} was solid with 7 tackles and a sack against {rival}.',
                '{name} played well in a strong team defensive effort.',
            ],
            'average' => [
                '{name} had 5 tackles in a competitive game against {rival}.',
                '{name} was steady but didn\'t make splash plays.',
            ],
            'bad' => [
                '{name} was beaten multiple times in pass protection matchups.',
                '{name} was exposed in coverage, giving up 2 completions for 70+ yards.',
            ],
            'terrible' => [
                '{name} was pushed around all game and committed 2 penalties.',
                '{name} had a rough outing — missed tackles, blown assignments.',
            ],
        ],
    ];

    private const RIVALS = [
        'Alabama', 'Ohio State', 'Georgia', 'Michigan', 'Clemson', 'LSU', 'Oklahoma',
        'USC', 'Oregon', 'Penn State', 'Florida', 'Texas', 'Notre Dame', 'Tennessee',
        'Miami', 'Florida State', 'Auburn', 'Wisconsin', 'Iowa', 'Texas A&M',
        'Ole Miss', 'Arkansas', 'Kentucky', 'Colorado', 'Nebraska', 'Virginia Tech',
        'North Carolina', 'Baylor', 'TCU', 'Utah', 'Kansas State', 'Pittsburgh',
    ];

    private const BUZZ_TEMPLATES = [
        'rising' => [
            'Scouts are buzzing about {name} after another strong performance.',
            '{name}\'s stock is soaring — some mock drafts now have him in Round {round}.',
            'NFL scouts were lined up to watch {name} this week. He didn\'t disappoint.',
            'Multiple teams have {name} climbing their boards after a breakout stretch.',
        ],
        'falling' => [
            'Concerns growing about {name} after consecutive poor showings.',
            '{name}\'s stock is slipping — inconsistency is the knock.',
            'Some scouts have moved {name} down after questions about his {concern}.',
            'Is {name} a reach in Round {round}? Recent tape says yes.',
        ],
        'steady' => [
            '{name} continues to be a solid, consistent performer for {college}.',
            'No movement on {name} — he is what he is, a Day {day} pick.',
        ],
        'injury' => [
            '{name} left the game with a {injury} and is listed as day-to-day.',
            'Concern for {name} after he was seen in a walking boot postgame.',
            '{name} missed the game with a {injury}. Draft stock could take a hit.',
        ],
        'character' => [
            'Off-field concerns surface for {name} — reports of a team meeting.',
            '{name} was seen leaving practice early. Attitude questions linger.',
            'Despite the talent, some teams have {name} flagged for maturity concerns.',
        ],
    ];

    private const INJURY_TYPES = [
        'ankle sprain', 'hamstring strain', 'shoulder injury', 'knee bruise',
        'concussion protocol', 'back tightness', 'hand injury',
    ];

    private const CHARACTER_CONCERNS = [
        'work_ethic', 'maturity', 'coachability', 'leadership', 'decision_making',
    ];

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
    }

    /**
     * Advance the college season by one week.
     * Every prospect gets a simulated performance, and their stock moves accordingly.
     */
    public function advanceWeek(int $leagueId, int $proWeek): array
    {
        // Get all upcoming draft classes for this league
        $stmt = $this->db->prepare(
            "SELECT id, year FROM draft_classes WHERE league_id = ? AND status IN ('upcoming', 'future')"
        );
        $stmt->execute([$leagueId]);
        $classes = $stmt->fetchAll();

        $allUpdates = [];
        $allHeadlines = [];

        foreach ($classes as $class) {
            $classId = (int) $class['id'];

            $stmt = $this->db->prepare(
                "SELECT * FROM draft_prospects WHERE draft_class_id = ? AND is_drafted = 0"
            );
            $stmt->execute([$classId]);
            $prospects = $stmt->fetchAll();

            if (empty($prospects)) continue;

            // College season runs ~14 weeks (roughly parallel to pro season)
            $collegeWeek = min($proWeek, 14);
            if ($collegeWeek > 14) continue; // College season is over

            foreach ($prospects as $prospect) {
                $result = $this->simulateWeeklyPerformance($prospect, $collegeWeek);

                // Update prospect in DB
                $this->db->prepare(
                    "UPDATE draft_prospects SET
                        stock_rating = ?,
                        stock_trend = ?,
                        projected_round = ?,
                        weekly_log = ?,
                        season_highlights = ?,
                        buzz = ?,
                        injury_flag = ?,
                        character_flag = ?
                     WHERE id = ?"
                )->execute([
                    $result['stock_rating'],
                    $result['stock_trend'],
                    $result['projected_round'],
                    $result['weekly_log'],
                    $result['season_highlights'],
                    $result['buzz'],
                    $result['injury_flag'],
                    $result['character_flag'],
                    (int) $prospect['id'],
                ]);

                if ($result['is_headline']) {
                    $allHeadlines[] = $result['headline'];
                }

                $allUpdates[] = [
                    'prospect_id' => (int) $prospect['id'],
                    'name' => $prospect['first_name'] . ' ' . $prospect['last_name'],
                    'position' => $prospect['position'],
                    'stock_change' => $result['stock_change'],
                    'stock_trend' => $result['stock_trend'],
                ];
            }
        }

        // Return top storylines for this week
        return [
            'week' => $proWeek,
            'updates' => count($allUpdates),
            'headlines' => array_slice($allHeadlines, 0, 5),
            'risers' => array_slice(
                array_filter($allUpdates, fn($u) => $u['stock_change'] >= 3),
                0, 5
            ),
            'fallers' => array_slice(
                array_filter($allUpdates, fn($u) => $u['stock_change'] <= -3),
                0, 5
            ),
        ];
    }

    /**
     * Simulate one week for one prospect.
     */
    private function simulateWeeklyPerformance(array $prospect, int $week): array
    {
        $trueOverall = (int) $prospect['actual_overall'];
        $currentStock = (int) ($prospect['stock_rating'] ?: 50);
        $position = $prospect['position'];
        $name = $prospect['first_name'] . ' ' . $prospect['last_name'];
        $college = $prospect['college'];
        $potential = $prospect['potential'] ?? 'average';

        // Parse existing weekly log
        $weeklyLog = json_decode($prospect['weekly_log'] ?? '[]', true) ?: [];
        $highlights = json_decode($prospect['season_highlights'] ?? '[]', true) ?: [];

        // ── Determine performance tier ──────────────────────────────
        // Better players perform well more often, but anyone can have a bad week
        $perfRoll = mt_rand(1, 100);

        // Base performance distribution shifts based on true talent
        $eliteChance = max(5, min(30, ($trueOverall - 60) * 1.2));
        $goodChance = max(15, min(35, ($trueOverall - 50) * 0.8));
        $terribleChance = max(3, min(15, (90 - $trueOverall) * 0.5));
        $badChance = max(8, min(20, (85 - $trueOverall) * 0.4));

        $perfTier = match (true) {
            $perfRoll <= $eliteChance => 'elite',
            $perfRoll <= $eliteChance + $goodChance => 'good',
            $perfRoll > 100 - $terribleChance => 'terrible',
            $perfRoll > 100 - $terribleChance - $badChance => 'bad',
            default => 'average',
        };

        // ── Stock movement ──────────────────────────────────────────
        $stockChange = match ($perfTier) {
            'elite' => mt_rand(3, 7),
            'good' => mt_rand(1, 3),
            'average' => mt_rand(-1, 1),
            'bad' => mt_rand(-4, -1),
            'terrible' => mt_rand(-7, -3),
        };

        // High-potential players recover faster from bad weeks
        if ($stockChange < 0 && ($potential === 'elite' || $potential === 'high')) {
            $stockChange = (int) ($stockChange * 0.6);
        }

        // Ceiling and floor
        $newStock = max(10, min(95, $currentStock + $stockChange));

        // ── Generate performance narrative ──────────────────────────
        $posKey = isset(self::PERFORMANCES[$position]) ? $position : 'default';
        $templates = self::PERFORMANCES[$posKey][$perfTier];
        $rival = self::RIVALS[array_rand(self::RIVALS)];
        // Don't play against your own school
        while ($rival === $college) {
            $rival = self::RIVALS[array_rand(self::RIVALS)];
        }

        $narrative = $templates[array_rand($templates)];
        $narrative = str_replace(
            ['{name}', '{college}', '{rival}'],
            [$name, $college, $rival],
            $narrative
        );

        // ── Injury chance (~3% per week) ────────────────────────────
        $injuryFlag = $prospect['injury_flag'] ?? null;
        if (mt_rand(1, 100) <= 3 && !$injuryFlag) {
            $injury = self::INJURY_TYPES[array_rand(self::INJURY_TYPES)];
            $injuryFlag = $injury;
            $stockChange -= mt_rand(2, 5);
            $newStock = max(10, $newStock - mt_rand(2, 5));
        } elseif ($injuryFlag && mt_rand(1, 100) <= 50) {
            // 50% chance to recover each week
            $injuryFlag = null;
        }

        // ── Character concern (~2% per week for low-maturity prospects) ──
        $characterFlag = $prospect['character_flag'] ?? null;
        if (mt_rand(1, 100) <= 2 && !$characterFlag) {
            $characterFlag = self::CHARACTER_CONCERNS[array_rand(self::CHARACTER_CONCERNS)];
            $stockChange -= mt_rand(1, 3);
            $newStock = max(10, $newStock - mt_rand(1, 3));
        }

        // ── Trend calculation ───────────────────────────────────────
        // Look at last 3 weeks to determine trend
        $recentChanges = array_map(fn($w) => $w['stock_change'] ?? 0, array_slice($weeklyLog, -2));
        $recentChanges[] = $stockChange;
        $avgRecent = count($recentChanges) > 0 ? array_sum($recentChanges) / count($recentChanges) : 0;

        $trend = match (true) {
            $avgRecent >= 3 => 'rising',
            $avgRecent >= 1 => 'up',
            $avgRecent <= -3 => 'falling',
            $avgRecent <= -1 => 'down',
            default => 'steady',
        };

        // ── Projected round adjustment ──────────────────────────────
        // Stock can move the projection ±1 round from initial, not completely override
        $initialRound = (int) ($prospect['projected_round'] ?? 4);
        $stockRound = match (true) {
            $newStock >= 80 => 1,
            $newStock >= 65 => 2,
            $newStock >= 50 => 3,
            $newStock >= 38 => 4,
            $newStock >= 28 => 5,
            $newStock >= 18 => 6,
            default => 7,
        };
        // Blend: mostly initial projection, stock can shift ±1
        $projectedRound = $initialRound;
        if ($stockRound < $initialRound - 1) $projectedRound = $initialRound - 1;
        elseif ($stockRound > $initialRound + 1) $projectedRound = $initialRound + 1;
        elseif ($stockRound !== $initialRound) $projectedRound = $stockRound;
        $projectedRound = max(1, min(7, $projectedRound));

        // ── Buzz/headline ───────────────────────────────────────────
        $isHeadline = abs($stockChange) >= 4;
        $buzz = null;
        $headline = null;

        if ($isHeadline || $injuryFlag || $characterFlag) {
            $buzzType = match (true) {
                $injuryFlag !== null && $injuryFlag === ($prospect['injury_flag'] ?? '__none__') => 'injury',
                $characterFlag !== null && $characterFlag !== $prospect['character_flag'] => 'character',
                $stockChange >= 3 => 'rising',
                $stockChange <= -3 => 'falling',
                default => 'steady',
            };

            $buzzTemplates = self::BUZZ_TEMPLATES[$buzzType] ?? self::BUZZ_TEMPLATES['steady'];
            $buzz = $buzzTemplates[array_rand($buzzTemplates)];
            $buzz = str_replace(
                ['{name}', '{college}', '{round}', '{concern}', '{injury}', '{day}'],
                [$name, $college, $projectedRound, $characterFlag ?? 'consistency', $injuryFlag ?? 'minor injury', $projectedRound <= 3 ? '2' : '3'],
                $buzz
            );
            $headline = $buzz;
        }

        // ── Update weekly log ───────────────────────────────────────
        $weeklyLog[] = [
            'week' => $week,
            'performance' => $perfTier,
            'narrative' => $narrative,
            'stock_change' => $stockChange,
            'stock_after' => $newStock,
        ];

        // Track season highlights (elite/terrible performances)
        if ($perfTier === 'elite' || $perfTier === 'terrible') {
            $highlights[] = [
                'week' => $week,
                'type' => $perfTier,
                'narrative' => $narrative,
            ];
        }

        return [
            'stock_rating' => $newStock,
            'stock_change' => $stockChange,
            'stock_trend' => $trend,
            'projected_round' => $projectedRound,
            'weekly_log' => json_encode($weeklyLog),
            'season_highlights' => json_encode($highlights),
            'buzz' => $buzz,
            'injury_flag' => $injuryFlag,
            'character_flag' => $characterFlag,
            'is_headline' => $isHeadline || ($injuryFlag && !$prospect['injury_flag']),
            'headline' => $headline,
            'narrative' => $narrative,
        ];
    }

    /**
     * Initialize stock ratings for a draft class.
     * Called after generating prospects to set their initial stock.
     */
    public function initializeStockRatings(int $classId): void
    {
        $stmt = $this->db->prepare(
            "SELECT id, actual_overall, potential, projected_round FROM draft_prospects WHERE draft_class_id = ?"
        );
        $stmt->execute([$classId]);
        $prospects = $stmt->fetchAll();

        foreach ($prospects as $p) {
            $trueOverall = (int) $p['actual_overall'];
            $potential = $p['potential'] ?? 'average';
            $projRound = (int) ($p['projected_round'] ?? 4);

            // Stock should correlate with projected round, not just raw OVR
            // R1 prospects = stock 70-90, R7 = stock 15-30
            $baseStock = match ($projRound) {
                1 => mt_rand(72, 90),
                2 => mt_rand(58, 75),
                3 => mt_rand(44, 62),
                4 => mt_rand(32, 50),
                5 => mt_rand(22, 40),
                6 => mt_rand(16, 30),
                default => mt_rand(10, 25),
            };

            // Talent adjusts within the band
            $talentBonus = ($trueOverall - 65) * 0.3;
            $stock = (int) ($baseStock + $talentBonus);

            // Potential bump
            if ($potential === 'elite') $stock += mt_rand(3, 8);
            elseif ($potential === 'high') $stock += mt_rand(1, 4);

            // Add some noise — not every top prospect is hyped equally preseason
            $stock += mt_rand(-5, 5);
            $stock = max(10, min(95, $stock));

            $this->db->prepare(
                "UPDATE draft_prospects SET stock_rating = ?, stock_trend = 'steady' WHERE id = ?"
            )->execute([$stock, (int) $p['id']]);
        }
    }

    /**
     * Generate a "Draft Report" for the current week — top storylines.
     */
    public function getWeeklyDraftReport(int $leagueId): array
    {
        $stmt = $this->db->prepare(
            "SELECT dp.* FROM draft_prospects dp
             JOIN draft_classes dc ON dp.draft_class_id = dc.id
             WHERE dc.league_id = ? AND dc.status = 'upcoming' AND dp.is_drafted = 0
             ORDER BY dp.stock_rating DESC"
        );
        $stmt->execute([$leagueId]);
        $prospects = $stmt->fetchAll();

        $risers = [];
        $fallers = [];
        $headlines = [];

        foreach ($prospects as $p) {
            $trend = $p['stock_trend'] ?? 'steady';
            $buzz = $p['buzz'] ?? null;
            $name = $p['first_name'] . ' ' . $p['last_name'];

            if (in_array($trend, ['rising', 'up'])) {
                $risers[] = [
                    'id' => (int) $p['id'],
                    'name' => $name,
                    'position' => $p['position'],
                    'college' => $p['college'],
                    'stock_rating' => (int) $p['stock_rating'],
                    'projected_round' => (int) $p['projected_round'],
                    'trend' => $trend,
                ];
            }
            if (in_array($trend, ['falling', 'down'])) {
                $fallers[] = [
                    'id' => (int) $p['id'],
                    'name' => $name,
                    'position' => $p['position'],
                    'college' => $p['college'],
                    'stock_rating' => (int) $p['stock_rating'],
                    'projected_round' => (int) $p['projected_round'],
                    'trend' => $trend,
                ];
            }
            if ($buzz) {
                $headlines[] = $buzz;
            }
        }

        return [
            'total_prospects' => count($prospects),
            'risers' => array_slice($risers, 0, 5),
            'fallers' => array_slice($fallers, 0, 5),
            'headlines' => array_slice($headlines, 0, 5),
            'top_10' => array_slice(array_map(fn($p) => [
                'id' => (int) $p['id'],
                'name' => $p['first_name'] . ' ' . $p['last_name'],
                'position' => $p['position'],
                'college' => $p['college'],
                'stock_rating' => (int) $p['stock_rating'],
                'projected_round' => (int) $p['projected_round'],
                'trend' => $p['stock_trend'] ?? 'steady',
                'injury' => $p['injury_flag'],
                'character' => $p['character_flag'],
            ], $prospects), 0, 10),
        ];
    }
}
