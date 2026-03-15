<?php

namespace App\Services;

use App\Database\Connection;

/**
 * DraftScoutEngine — Dedicated draft prospect coverage with two scout writers
 * who follow prospects with progressive storylines throughout the offseason.
 *
 * Writers:
 *   Jake Morrison (Senior Draft Analyst) — film study, measurables, pro comparisons
 *   Nina Charles  (Draft Insider)        — player stories, character, intangibles, drama
 */
class DraftScoutEngine
{
    private \PDO $db;

    // Two dedicated draft writers
    private const SCOUTS = [
        'jake_morrison' => [
            'name' => 'Jake Morrison',
            'title' => 'Senior Draft Analyst',
            'style' => 'analytical',
            // Focuses on film study, measurables, pro comparisons
        ],
        'nina_charles' => [
            'name' => 'Nina Charles',
            'title' => 'Draft Insider',
            'style' => 'narrative',
            // Focuses on player stories, character, intangibles, drama
        ],
    ];

    // Weekly topic rotation for draft updates
    private const WEEKLY_TOPICS = [
        'mock_draft',
        'prospect_spotlight',
        'combine_report',
        'stock_watch',
        'character_concerns',
    ];

    // One-line scouting reports by position
    private const POSITION_SCOUTING_LINES = [
        'QB'  => [
            'elite' => ['Franchise-altering arm talent with elite processing speed.', 'The most complete passer to enter the draft in years.', 'Pro-ready pocket presence with a cannon for an arm.'],
            'high'  => ['Impressive arm talent with room to grow as a decision-maker.', 'Dynamic dual-threat with a live arm and dangerous legs.', 'High-ceiling passer who can make every throw on the field.'],
            'avg'   => ['Solid accuracy and decent mobility, but needs refinement under pressure.', 'Competent game manager with above-average athleticism.', 'Functional arm strength with good intangibles and work ethic.'],
        ],
        'RB'  => [
            'elite' => ['Explosive three-down back with elite vision and contact balance.', 'A generational running talent — breaks tackles like a machine.', 'Complete back: runs between the tackles, catches out of the backfield, and pass-protects.'],
            'high'  => ['Violent runner with impressive burst through the hole.', 'Shifty playmaker who can change the game on any carry.', 'Powerful runner with good hands and solid pass protection.'],
            'avg'   => ['Reliable runner with a patient style behind the line.', 'Decent burst with room to improve in pass-catching.', 'Physical runner who needs development as a receiver.'],
        ],
        'WR'  => [
            'elite' => ['Elite route runner with hands like glue and game-breaking speed.', 'A true alpha receiver who dominates at every level of the field.', 'Generational catch radius with the speed to take the top off defenses.'],
            'high'  => ['Smooth route runner who creates separation at all three levels.', 'Explosive deep threat with reliable hands and YAC ability.', 'Physical wideout with strong contested-catch skills.'],
            'avg'   => ['Solid possession receiver with good hands underneath.', 'Reliable route runner who needs to add speed.', 'Good size and catch radius, needs to refine routes.'],
        ],
        'TE'  => [
            'elite' => ['A mismatch nightmare — blocks like a tackle, catches like a receiver.', 'The best tight end prospect in years, a true three-down player.'],
            'high'  => ['Athletic move tight end with reliable hands and red-zone presence.', 'Versatile weapon who can line up anywhere.'],
            'avg'   => ['Solid blocker who flashes receiving potential.', 'Dependable inline tight end with serviceable hands.'],
        ],
        'OT'  => [
            'elite' => ['Premier blindside protector with NFL-ready technique and length.', 'The best pass-blocking prospect in this class, period.'],
            'high'  => ['Powerful anchor with impressive footwork in pass sets.', 'Long, athletic tackle with a high ceiling.'],
            'avg'   => ['Solid run blocker who needs work in pass protection.', 'Physical tackle with good size and adequate technique.'],
        ],
        'OG'  => [
            'elite' => ['A road-grader in the run game with surprising pass-pro ability.'],
            'high'  => ['Nasty disposition with plus strength at the point of attack.'],
            'avg'   => ['Functional starter with adequate strength and technique.'],
        ],
        'C'   => [
            'elite' => ['Quarterback of the offensive line with elite mental processing.'],
            'high'  => ['Smart, tough, and technically refined interior lineman.'],
            'avg'   => ['Reliable snapper with adequate blocking ability.'],
        ],
        'DE'  => [
            'elite' => ['An unblockable force off the edge — generational first step and bend.', 'The most disruptive pass rusher to enter the draft in a decade.'],
            'high'  => ['Explosive edge rusher with a diverse pass-rush repertoire.', 'Long, bendy edge with elite closing speed.'],
            'avg'   => ['Active motor with flashes of pass-rush ability.', 'Solid run defender who needs to develop a counter move.'],
        ],
        'DT'  => [
            'elite' => ['Interior disruptor who collapses the pocket on every snap.'],
            'high'  => ['Powerful nose tackle with impressive quickness for his size.'],
            'avg'   => ['Run-stuffing tackle with limited pass-rush upside.'],
        ],
        'LB'  => [
            'elite' => ['Sideline-to-sideline eraser with elite instincts in coverage and run support.'],
            'high'  => ['Rangy linebacker with playmaking ability at all three levels.'],
            'avg'   => ['Solid tackler with decent range, limited in coverage.'],
        ],
        'CB'  => [
            'elite' => ['Lockdown corner with elite ball skills and track-star speed.', 'A true shutdown corner who erases half the field.'],
            'high'  => ['Sticky in man coverage with the speed to recover and the instincts to jump routes.'],
            'avg'   => ['Competitive corner with adequate speed and physicality at the catch point.'],
        ],
        'S'   => [
            'elite' => ['Versatile safety who can play the deep half, cover slot receivers, and fill the box.'],
            'high'  => ['Rangy ball-hawk with plus instincts in zone coverage.'],
            'avg'   => ['Solid tackler with good size, limited range in deep coverage.'],
        ],
    ];

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
    }

    // ================================================================
    //  Public API
    // ================================================================

    /**
     * Identify blue chip and drama prospects from a draft class.
     *
     * - potential = 'elite' → generational
     * - combine_score >= 85 AND actual_overall >= 72 → blue chip
     * - character_flag IS NOT NULL AND actual_overall >= 70 → bust risk / drama prospect
     */
    public function identifyBluechipProspects(int $draftClassId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM draft_prospects
             WHERE draft_class_id = ?
               AND (
                   potential = 'elite'
                   OR (combine_score >= 85 AND actual_overall >= 72)
                   OR (character_flag IS NOT NULL AND character_flag != '' AND actual_overall >= 70)
               )
             ORDER BY actual_overall DESC"
        );
        $stmt->execute([$draftClassId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Generate initial pre-draft coverage (3 articles) when the draft class
     * is first generated at the start of offseason.
     */
    public function generatePreDraftCoverage(int $leagueId, int $seasonId, int $draftClassId): void
    {
        $now = date('Y-m-d H:i:s');

        // Get season year for headlines
        $year = $this->getSeasonYear($leagueId);

        // Fetch top prospects
        $stmt = $this->db->prepare(
            "SELECT * FROM draft_prospects WHERE draft_class_id = ? ORDER BY actual_overall DESC LIMIT 15"
        );
        $stmt->execute([$draftClassId]);
        $topProspects = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($topProspects)) {
            return;
        }

        $blueChips = $this->identifyBluechipProspects($draftClassId);
        $generational = array_filter($blueChips, fn($p) => ($p['potential'] ?? '') === 'elite');

        // ── Article 1: Big Board (Jake Morrison) ────────────────────────
        $this->generateBigBoardArticle($leagueId, $seasonId, $year, $topProspects, $generational, $now);

        // ── Article 2: Player to Watch (Nina Charles) ───────────────────
        $this->generatePlayerToWatchArticle($leagueId, $seasonId, $year, $topProspects, $blueChips, $now);

        // ── Article 3: Draft Needs (Jake Morrison) ──────────────────────
        $this->generateDraftNeedsArticle($leagueId, $seasonId, $year, $now);
    }

    /**
     * Generate a weekly draft update with progressive storylines.
     * Called each week during offseason.
     */
    public function generateWeeklyDraftUpdate(int $leagueId, int $seasonId, int $week, int $draftClassId): void
    {
        $now = date('Y-m-d H:i:s');
        $year = $this->getSeasonYear($leagueId);
        $blueChips = $this->identifyBluechipProspects($draftClassId);

        if (empty($blueChips)) {
            return;
        }

        // Rotate between Jake and Nina each week
        $scoutKey = ($week % 2 === 0) ? 'jake_morrison' : 'nina_charles';
        $scout = $this->pickScout($scoutKey);

        // Cycle through topics
        $topicIndex = ($week - 1) % count(self::WEEKLY_TOPICS);
        $topic = self::WEEKLY_TOPICS[$topicIndex];

        // If Nina gets a Jake topic or vice-versa, remap
        if ($scoutKey === 'jake_morrison' && in_array($topic, ['prospect_spotlight', 'character_concerns'])) {
            $topic = 'mock_draft'; // Jake does mock drafts
        } elseif ($scoutKey === 'nina_charles' && in_array($topic, ['mock_draft', 'combine_report'])) {
            $topic = 'stock_watch'; // Nina does stock watch
        }

        match ($topic) {
            'mock_draft'          => $this->generateMockDraft($leagueId, $seasonId, $week, $year, $draftClassId, $scout, $now),
            'prospect_spotlight'  => $this->generateProspectSpotlight($leagueId, $seasonId, $week, $year, $blueChips, $scout, $now),
            'combine_report'      => $this->generateCombineReport($leagueId, $seasonId, $week, $year, $draftClassId, $scout, $now),
            'stock_watch'         => $this->generateStockWatch($leagueId, $seasonId, $week, $year, $draftClassId, $scout, $now),
            'character_concerns'  => $this->generateCharacterConcerns($leagueId, $seasonId, $week, $year, $draftClassId, $scout, $now),
        };
    }

    /**
     * Generate draft-day narrative coverage after the draft completes.
     * Both writers react to the picks.
     */
    public function generateDraftDayNarrative(int $leagueId, int $seasonId, array $picks): void
    {
        $now = date('Y-m-d H:i:s');
        $year = $this->getSeasonYear($leagueId);

        if (empty($picks)) {
            return;
        }

        // ── Nina Charles: Draft Day Drama ───────────────────────────────
        $this->generateDraftDayDramaArticle($leagueId, $seasonId, $year, $picks, $now);

        // ── Jake Morrison: Draft Grades ─────────────────────────────────
        $this->generateDraftGradesArticle($leagueId, $seasonId, $year, $picks, $now);
    }

    // ================================================================
    //  Pre-Draft Coverage Articles
    // ================================================================

    private function generateBigBoardArticle(int $leagueId, int $seasonId, int $year, array $topProspects, array $generational, string $now): void
    {
        $scout = $this->pickScout('jake_morrison');
        $top10 = array_slice($topProspects, 0, 10);

        $hasGenerational = !empty($generational);
        $opener = $hasGenerational
            ? "This is a generational class. I've been scouting prospects for over fifteen years, and this group has the kind of top-end talent that can reshape franchises overnight. "
            : "After months of film study and pro day visits, my board is set. This class has some legitimate difference-makers, but there are also some prospects whose stock doesn't match the tape. ";

        $body = $opener . "Here's my top 10 heading into the draft:\n\n";

        foreach ($top10 as $rank => $p) {
            $num = $rank + 1;
            $name = $p['first_name'] . ' ' . $p['last_name'];
            $combineGrade = $p['combine_grade'] ?? $this->deriveCombineGrade((int) $p['combine_score']);
            $scoutLine = $this->getScoutingLine($p['position'], $p['potential'] ?? 'average');

            $body .= "{$num}. {$name}, {$p['position']} — {$p['college']}\n";
            $body .= "   Combine Grade: {$combineGrade} | {$scoutLine}\n\n";
        }

        if ($hasGenerational) {
            $gen = reset($generational);
            $body .= "The headliner is obvious: {$gen['first_name']} {$gen['last_name']} from {$gen['college']} is the best prospect I've evaluated since I started this job. ";
            $body .= "Whoever picks first is getting a franchise cornerstone.\n";
        }

        $body .= "\nMore updates to come as we get closer to draft night. Stay locked in.";

        $this->insertArticle(
            $leagueId, $seasonId, null, 'draft_coverage',
            "Morrison's Big Board: Top 10 Prospects in the {$year} Draft Class",
            $body, $scout['name'], $scout['style'], null, null, $now
        );
    }

    private function generatePlayerToWatchArticle(int $leagueId, int $seasonId, int $year, array $topProspects, array $blueChips, string $now): void
    {
        $scout = $this->pickScout('nina_charles');

        // Pick the most interesting prospect: generational first, then highest with character flag
        $featured = null;
        foreach ($blueChips as $p) {
            if (($p['potential'] ?? '') === 'elite') {
                $featured = $p;
                break;
            }
        }
        if (!$featured) {
            foreach ($blueChips as $p) {
                if (!empty($p['character_flag'])) {
                    $featured = $p;
                    break;
                }
            }
        }
        if (!$featured && !empty($topProspects)) {
            $featured = $topProspects[0];
        }

        if (!$featured) {
            return;
        }

        $name = $featured['first_name'] . ' ' . $featured['last_name'];
        $pos = $featured['position'];
        $college = $featured['college'];
        $potential = $featured['potential'] ?? 'average';
        $hasFlag = !empty($featured['character_flag']);
        $flagText = $hasFlag ? ucwords(str_replace('_', ' ', $featured['character_flag'])) : '';

        // Build 4-5 paragraph feature
        $body = "Every draft class has that one name — the player everyone is talking about, the one whose name echoes through war rooms and scout meetings and late-night film sessions. ";
        $body .= "This year, that name is {$name}.\n\n";

        // Background
        if ($potential === 'elite') {
            $body .= "The {$college} product has been on the national radar since his freshman year, when he flashed the kind of talent that makes evaluators pull out their phones to text their GMs. ";
            $body .= "By the time he declared for the draft, {$name} wasn't just a top prospect — he was a consensus generational talent.\n\n";
        } else {
            $body .= "The {$college} {$pos} has been a steady riser throughout the evaluation process, turning heads with his combination of athleticism and football instincts. ";
            $body .= "What makes {$name} intriguing isn't just the measurables — it's the way he plays the game.\n\n";
        }

        // Strengths
        $scoutLine = $this->getScoutingLine($pos, $potential);
        $body .= "On tape, the strengths are obvious. {$scoutLine} ";
        $body .= "His combine grade of " . ($featured['combine_grade'] ?? $this->deriveCombineGrade((int) ($featured['combine_score'] ?? 50))) . " only confirmed what scouts had already seen.\n\n";

        // Concerns
        if ($hasFlag) {
            $body .= "But here's where the story gets complicated. There are real concerns about {$name}'s {$flagText}. ";
            $body .= "Teams will have to weigh the undeniable talent against the risk. ";
            $body .= "I've spoken to multiple front office sources who are torn — one told me, 'The talent is undeniable, but we need to do our homework.'\n\n";
        } else {
            $body .= "The concerns? There aren't many. Some scouts want to see more consistency against top competition, and there are always questions about how college production translates to the pros. ";
            $body .= "But the floor here is high, and the ceiling is even higher.\n\n";
        }

        // Where they could go
        $body .= "As for where {$name} lands — everyone in the top five is making calls. ";
        if ($potential === 'elite') {
            $body .= "Barring a stunning trade, this feels like a lock for the first overall pick. The only question is which franchise gets to build around him.\n\n";
        } else {
            $body .= "Mock drafts have him anywhere from the top five to the mid-first round. Wherever he goes, that team is getting a player who can make an immediate impact.\n\n";
        }

        $body .= "This is just the beginning of the story. We'll be following {$name}'s journey all the way to draft night.";

        $this->insertArticle(
            $leagueId, $seasonId, null, 'feature',
            "{$name}: The Most Intriguing Prospect in This Draft",
            $body, $scout['name'], $scout['style'], null, (int) $featured['id'], $now
        );
    }

    private function generateDraftNeedsArticle(int $leagueId, int $seasonId, int $year, string $now): void
    {
        $scout = $this->pickScout('jake_morrison');

        // Get teams with the worst records (most likely to pick high)
        $stmt = $this->db->prepare(
            "SELECT id, city, name, abbreviation, wins, losses FROM teams
             WHERE league_id = ?
             ORDER BY wins ASC, (points_for - points_against) ASC
             LIMIT 10"
        );
        $stmt->execute([$leagueId]);
        $teams = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($teams)) {
            return;
        }

        $body = "Draft season means need season. Every front office is staring at its roster, searching for the holes that cost them wins. ";
        $body .= "Here's my breakdown of the teams most likely to be picking at the top of the board — and what they should be targeting.\n\n";

        foreach ($teams as $team) {
            $needs = $this->getTeamNeeds((int) $team['id']);
            $topNeed = !empty($needs) ? $needs[0] : 'BPA';
            $secondNeed = count($needs) > 1 ? $needs[1] : null;

            $record = ($team['wins'] ?? 0) . '-' . ($team['losses'] ?? 0);
            $body .= "**{$team['city']} {$team['name']}** ({$record})\n";
            $body .= "Primary need: {$topNeed}";
            if ($secondNeed) {
                $body .= " | Also watching: {$secondNeed}";
            }
            $body .= "\n";

            // Brief analysis
            $body .= match ($topNeed) {
                'QB'  => "Until you find your quarterback, nothing else matters. This franchise needs to swing for the fences.\n",
                'DE', 'DT' => "The pass rush was nonexistent last season. Getting pressure with four would transform this defense.\n",
                'OT', 'OG', 'C' => "You can't develop a young quarterback if he's running for his life every snap. The line needs help.\n",
                'CB', 'S' => "The secondary was torched all year. A lockdown corner could be the missing piece.\n",
                'WR', 'TE' => "The passing game needs weapons. Time to give the quarterback someone to throw to.\n",
                'LB' => "The second level of the defense was a liability. A sideline-to-sideline linebacker changes everything.\n",
                'RB' => "The run game was anemic. A dynamic back could open up the entire offense.\n",
                default => "Best player available is the smart play here. Build through talent.\n",
            };
            $body .= "\n";
        }

        $body .= "Draft night is about matching need with talent. The teams that thread that needle will be the ones celebrating in the fall.";

        $this->insertArticle(
            $leagueId, $seasonId, null, 'draft_coverage',
            "Draft Day Guide: What Every Team Needs",
            $body, $scout['name'], $scout['style'], null, null, $now
        );
    }

    // ================================================================
    //  Weekly Draft Update Topics
    // ================================================================

    private function generateMockDraft(int $leagueId, int $seasonId, int $week, int $year, int $draftClassId, array $scout, string $now): void
    {
        // Determine which version of the mock
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM articles WHERE league_id = ? AND author_name = ? AND headline LIKE '%Mock Draft%'"
        );
        $stmt->execute([$leagueId, $scout['name']]);
        $mockVersion = ((int) $stmt->fetchColumn()) + 1;
        $versionLabel = $mockVersion === 1 ? '1.0' : (string) number_format($mockVersion * 1.0, 1);

        // Get ALL teams by draft order (worst record first, point diff as tiebreaker)
        $stmt = $this->db->prepare(
            "SELECT id, city, name, abbreviation, wins, losses, overall_rating
             FROM teams WHERE league_id = ?
             ORDER BY wins ASC, (points_for - points_against) ASC"
        );
        $stmt->execute([$leagueId]);
        $allTeams = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Get ALL available prospects ranked by overall/stock
        $stmt = $this->db->prepare(
            "SELECT * FROM draft_prospects
             WHERE draft_class_id = ? AND is_drafted = 0
             ORDER BY actual_overall DESC, COALESCE(combine_score, 50) DESC"
        );
        $stmt->execute([$draftClassId]);
        $allProspects = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($allTeams) || empty($allProspects)) return;

        // Build comprehensive team needs with context
        $teamNeeds = [];
        $teamBestByPos = [];
        foreach ($allTeams as $t) {
            $tid = (int) $t['id'];
            $teamNeeds[$tid] = $this->getTeamNeeds($tid);

            // Also get the best player at each position (to avoid drafting what they already have)
            $stmt2 = $this->db->prepare(
                "SELECT position, MAX(overall_rating) as best_ovr
                 FROM players WHERE team_id = ? AND status = 'active'
                 GROUP BY position"
            );
            $stmt2->execute([$tid]);
            $teamBestByPos[$tid] = [];
            foreach ($stmt2->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $teamBestByPos[$tid][$r['position']] = (int) $r['best_ovr'];
            }
        }

        // Mock the full first round (32 picks)
        $usedProspects = [];
        $picks = [];
        $pickCount = min(32, count($allTeams), count($allProspects));

        for ($i = 0; $i < $pickCount; $i++) {
            $team = $allTeams[$i];
            $tid = (int) $team['id'];
            $needs = $teamNeeds[$tid];
            $bestAtPos = $teamBestByPos[$tid] ?? [];

            // Score each available prospect for this team
            $bestMatch = null;
            $bestScore = -999;

            foreach ($allProspects as $p) {
                if (in_array((int) $p['id'], $usedProspects)) continue;

                $score = (int) $p['actual_overall'];
                $pos = $p['position'];
                $potential = $p['potential'] ?? 'average';

                // Bonus for filling a need (top 3 needs get bigger bonus)
                $needIdx = array_search($pos, $needs);
                if ($needIdx !== false) {
                    $score += (5 - min($needIdx, 4)) * 4; // +20 for #1 need, +16 for #2, etc.
                }

                // Bonus for elite/high potential
                if ($potential === 'elite') $score += 12;
                elseif ($potential === 'high') $score += 6;

                // Penalty if team already has a star at this position (80+ OVR)
                $existingBest = $bestAtPos[$pos] ?? 0;
                if ($existingBest >= 85) $score -= 20; // Already have a stud, don't draft this
                elseif ($existingBest >= 80) $score -= 10;

                // Combine score bonus
                $combineScore = (int) ($p['combine_score'] ?? 50);
                if ($combineScore >= 90) $score += 5;
                elseif ($combineScore >= 80) $score += 3;

                // Character flag penalty (slight — teams still draft talent)
                if (!empty($p['character_flag'])) $score -= 3;

                // Generational talent override — always top pick material
                if ($potential === 'elite' && $combineScore >= 88) {
                    $score += 15; // Near-impossible to pass on
                }

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestMatch = $p;
                }
            }

            if (!$bestMatch) continue;
            $usedProspects[] = (int) $bestMatch['id'];
            $picks[] = ['pick' => $i + 1, 'team' => $team, 'prospect' => $bestMatch, 'needs' => $needs];
        }

        // Build the article
        $body = $mockVersion === 1
            ? "It's that time of year. The combine is in the books, pro days are wrapping up, and front offices are burning midnight oil. After analyzing every roster, every need, and every prospect in this class, here's my projection for the full first round.\n\n"
            : "The board continues to shift. Since Mock Draft " . number_format(($mockVersion - 1) * 1.0, 1) . ", I've revisited every team's roster, adjusted for new intel, and updated my projections. Here's where things stand.\n\n";

        foreach ($picks as $p) {
            $pick = $p['pick'];
            $team = $p['team'];
            $prospect = $p['prospect'];
            $needs = $p['needs'];
            $pName = $prospect['first_name'] . ' ' . $prospect['last_name'];
            $pos = $prospect['position'];
            $college = $prospect['college'] ?? 'Unknown';
            $potential = $prospect['potential'] ?? 'average';
            $combineGrade = $prospect['combine_grade'] ?? $this->deriveCombineGrade((int) ($prospect['combine_score'] ?? 50));
            $isNeedPick = in_array($pos, array_slice($needs, 0, 3));
            $ovr = (int) $prospect['actual_overall'];

            // Build context-aware analysis for each pick
            $analysis = '';
            if ($potential === 'elite') {
                $analysis = "Generational talent. You don't overthink this — {$pName} is the best player in this class and it's not close.";
            } elseif ($isNeedPick && $ovr >= 72) {
                $analysis = "Fills their biggest need at {$pos} with a {$combineGrade}-grade prospect. This is a no-brainer.";
            } elseif ($isNeedPick) {
                $analysis = "{$pos} is a clear need, and {$pName} has the upside to be a starter by Year 2.";
            } elseif ($ovr >= 74) {
                $analysis = "Best player available. You can't pass on this kind of talent even if {$pos} isn't their top need.";
            } else {
                $analysis = "Solid value here. {$pName} grades out as a {$combineGrade} with {$potential} potential.";
            }

            if (!empty($prospect['character_flag'])) {
                $flag = ucwords(str_replace('_', ' ', $prospect['character_flag']));
                $analysis .= " Note: {$flag} concerns could cause a slide on draft day.";
            }

            $body .= "**{$pick}. {$team['city']} {$team['name']}** ({$team['wins']}-{$team['losses']}) — **{$pName}**, {$pos}, {$college}\n";
            $body .= "   {$analysis}\n";
            $body .= "   Top needs: " . implode(', ', array_slice($needs, 0, 3)) . "\n\n";
        }

        if ($mockVersion === 1) {
            $body .= "This is just the beginning. Boards will shift, trades will reshape the order, and draft day always has surprises. But this is where I see it right now — and I'm putting my name on it.";
        } else {
            $body .= "The draft is getting closer, and I'm more confident in this board than the last one. But as always, draft day has a mind of its own. Stay tuned.";
        }

        $this->insertArticle(
            $leagueId, $seasonId, $week, 'draft_coverage',
            "Morrison Mock Draft {$versionLabel}",
            $body, $scout['name'], $scout['style'], null, null, $now
        );
    }

    private function generateProspectSpotlight(int $leagueId, int $seasonId, int $week, int $year, array $blueChips, array $scout, string $now): void
    {
        if (empty($blueChips)) {
            return;
        }

        // Pick a prospect we haven't written about recently
        $featured = null;
        foreach ($blueChips as $p) {
            $stmt = $this->db->prepare(
                "SELECT id FROM articles WHERE league_id = ? AND author_name = ? AND player_id = ? AND type = 'feature'
                 ORDER BY published_at DESC LIMIT 1"
            );
            $stmt->execute([$leagueId, $scout['name'], (int) $p['id']]);
            if (!$stmt->fetch()) {
                $featured = $p;
                break;
            }
        }

        // If we've covered all of them, revisit the top one
        if (!$featured) {
            $featured = $blueChips[0];
        }

        $name = $featured['first_name'] . ' ' . $featured['last_name'];
        $pos = $featured['position'];
        $college = $featured['college'];

        // Check for previous coverage of this prospect by this author
        $previousArticle = $this->getPreviousArticleAboutProspect($leagueId, $scout['name'], (int) $featured['id']);

        if ($previousArticle) {
            // Progressive storyline — reference previous coverage
            $body = "We've been following {$name}'s draft journey since the beginning. ";
            $body .= "Last time, we highlighted what makes the {$college} {$pos} such a compelling prospect. ";
            $body .= "This week, we dig deeper into the film.\n\n";
        } else {
            $body = "It's time to talk about {$name}. The {$college} {$pos} has been climbing draft boards all offseason, ";
            $body .= "and after spending the week breaking down his tape, I understand why.\n\n";
        }

        // Detailed breakdown
        $scoutLine = $this->getScoutingLine($pos, $featured['potential'] ?? 'average');
        $body .= "The scouting report: {$scoutLine}\n\n";

        $combineScore = (int) ($featured['combine_score'] ?? 50);
        $stockRating = (int) ($featured['stock_rating'] ?? 50);
        $stockTrend = $featured['stock_trend'] ?? 'steady';

        $body .= "The numbers tell a story too. ";
        if ($combineScore >= 85) {
            $body .= "His combine score of {$combineScore} was among the best in the class — the kind of athletic testing that makes front offices stand up and take notice. ";
        } elseif ($combineScore >= 70) {
            $body .= "A combine score of {$combineScore} shows solid athleticism, even if it doesn't scream 'freak athlete.' ";
        } else {
            $body .= "His combine score of {$combineScore} left some wanting more, but tape don't lie — the production is there. ";
        }

        $body .= match ($stockTrend) {
            'rising'  => "His stock is trending up, and for good reason.\n\n",
            'falling' => "His stock has dipped recently, which could make him a value pick.\n\n",
            default   => "His stock has been steady — teams know exactly what they're getting.\n\n",
        };

        if (!empty($featured['character_flag'])) {
            $flagText = ucwords(str_replace('_', ' ', $featured['character_flag']));
            $body .= "The elephant in the room: {$flagText}. I've heard from multiple sources that teams are split on this. ";
            $body .= "Some see it as a red flag, others see it as overblown. What I know is this — talent this rare doesn't come around often.\n\n";
        }

        $body .= "Keep an eye on {$name}. This is a story that's still being written.";

        $this->insertArticle(
            $leagueId, $seasonId, $week, 'feature',
            "Prospect Spotlight: {$name}, {$pos}, {$college}",
            $body, $scout['name'], $scout['style'], null, (int) $featured['id'], $now
        );
    }

    private function generateCombineReport(int $leagueId, int $seasonId, int $week, int $year, int $draftClassId, array $scout, string $now): void
    {
        // Get top combine performers
        $stmt = $this->db->prepare(
            "SELECT * FROM draft_prospects
             WHERE draft_class_id = ? AND is_drafted = 0 AND combine_score IS NOT NULL
             ORDER BY combine_score DESC
             LIMIT 10"
        );
        $stmt->execute([$draftClassId]);
        $topPerformers = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($topPerformers)) {
            return;
        }

        $body = "The combine is where stock gets made — and broken. After a week of testing, here are the prospects who helped themselves the most.\n\n";
        $body .= "TOP PERFORMERS:\n\n";

        foreach ($topPerformers as $i => $p) {
            $name = $p['first_name'] . ' ' . $p['last_name'];
            $grade = $p['combine_grade'] ?? $this->deriveCombineGrade((int) $p['combine_score']);
            $score = (int) $p['combine_score'];

            $body .= ($i + 1) . ". {$name}, {$p['position']} ({$p['college']}) — Grade: {$grade} (Score: {$score})\n";

            if ($score >= 90) {
                $body .= "   Freakish athleticism. This is the kind of testing that vaults you into the top 10.\n";
            } elseif ($score >= 80) {
                $body .= "   Elite testing across the board. Confirmed what the tape already showed.\n";
            } else {
                $body .= "   Solid showing. Nothing to scare anyone, nothing to blow you away.\n";
            }
            $body .= "\n";
        }

        // Bottom performers
        $stmt = $this->db->prepare(
            "SELECT * FROM draft_prospects
             WHERE draft_class_id = ? AND is_drafted = 0 AND combine_score IS NOT NULL
               AND combine_score < 55
             ORDER BY combine_score ASC
             LIMIT 3"
        );
        $stmt->execute([$draftClassId]);
        $poorPerformers = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (!empty($poorPerformers)) {
            $body .= "DISAPPOINTING SHOWINGS:\n\n";
            foreach ($poorPerformers as $p) {
                $name = $p['first_name'] . ' ' . $p['last_name'];
                $body .= "- {$name}, {$p['position']} ({$p['college']}) — Score: {$p['combine_score']}. ";
                $body .= "The tape is better than the testing, but teams notice these numbers.\n";
            }
            $body .= "\n";
        }

        $body .= "The combine is one data point, not the whole picture. But it matters. We'll see how this shakes up the boards.";

        $this->insertArticle(
            $leagueId, $seasonId, $week, 'draft_coverage',
            "Combine Report: The Prospects Who Helped (and Hurt) Themselves",
            $body, $scout['name'], $scout['style'], null, null, $now
        );
    }

    private function generateStockWatch(int $leagueId, int $seasonId, int $week, int $year, int $draftClassId, array $scout, string $now): void
    {
        // Risers
        $stmt = $this->db->prepare(
            "SELECT * FROM draft_prospects
             WHERE draft_class_id = ? AND is_drafted = 0 AND stock_trend = 'rising'
             ORDER BY COALESCE(stock_rating, 50) DESC
             LIMIT 5"
        );
        $stmt->execute([$draftClassId]);
        $risers = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Fallers
        $stmt = $this->db->prepare(
            "SELECT * FROM draft_prospects
             WHERE draft_class_id = ? AND is_drafted = 0 AND stock_trend = 'falling'
             ORDER BY COALESCE(stock_rating, 50) ASC
             LIMIT 5"
        );
        $stmt->execute([$draftClassId]);
        $fallers = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($risers) && empty($fallers)) {
            return;
        }

        // Check for previous stock watch to reference
        $previousArticle = null;
        $stmt = $this->db->prepare(
            "SELECT body FROM articles WHERE league_id = ? AND author_name = ? AND headline LIKE '%Stock Watch%'
             ORDER BY published_at DESC LIMIT 1"
        );
        $stmt->execute([$leagueId, $scout['name']]);
        $prevRow = $stmt->fetch();
        if ($prevRow) {
            $previousArticle = $prevRow['body'];
        }

        $body = $previousArticle
            ? "The draft board never stops moving. Since my last stock watch, we've seen some significant shifts. Here's who's trending.\n\n"
            : "The board is alive. Every week brings new information — pro days, private workouts, interviews — and the rankings shift accordingly. Here's this week's movers.\n\n";

        if (!empty($risers)) {
            $body .= "RISERS:\n\n";
            foreach ($risers as $p) {
                $name = $p['first_name'] . ' ' . $p['last_name'];
                $body .= "UP: {$name}, {$p['position']} ({$p['college']})\n";
                $body .= "   Stock Rating: " . ($p['stock_rating'] ?? 50) . " | ";
                $body .= "The buzz is real. Teams are moving this player up their boards.\n\n";
            }
        }

        if (!empty($fallers)) {
            $body .= "FALLERS:\n\n";
            foreach ($fallers as $p) {
                $name = $p['first_name'] . ' ' . $p['last_name'];
                $body .= "DOWN: {$name}, {$p['position']} ({$p['college']})\n";
                $body .= "   Stock Rating: " . ($p['stock_rating'] ?? 50) . " | ";

                if (!empty($p['character_flag'])) {
                    $flagText = ucwords(str_replace('_', ' ', $p['character_flag']));
                    $body .= "The {$flagText} concerns are weighing on his stock.\n\n";
                } else {
                    $body .= "Something's changed. The early-season hype has faded.\n\n";
                }
            }
        }

        $body .= "Draft season is a marathon, not a sprint. These stocks will keep moving.";

        $this->insertArticle(
            $leagueId, $seasonId, $week, 'draft_coverage',
            "Stock Watch: Risers and Fallers in the {$year} Draft",
            $body, $scout['name'], $scout['style'], null, null, $now
        );
    }

    private function generateCharacterConcerns(int $leagueId, int $seasonId, int $week, int $year, int $draftClassId, array $scout, string $now): void
    {
        // Find prospects with character flags
        $stmt = $this->db->prepare(
            "SELECT * FROM draft_prospects
             WHERE draft_class_id = ? AND is_drafted = 0
               AND character_flag IS NOT NULL AND character_flag != ''
             ORDER BY actual_overall DESC
             LIMIT 3"
        );
        $stmt->execute([$draftClassId]);
        $flagged = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($flagged)) {
            // No character concerns — generate a "clean class" article instead
            $body = "I've done my homework, talked to coaches, talked to teammates, talked to anyone who would answer the phone. ";
            $body .= "And here's the verdict: this is a relatively clean class. No major red flags, no bombshell reports. ";
            $body .= "That's not always the case, and franchises should count their blessings.\n\n";
            $body .= "There are always concerns in the evaluation process — maturity questions, coaching fit, scheme dependency — ";
            $body .= "but nothing that would make me take a player off my board entirely. Draft with confidence.";

            $this->insertArticle(
                $leagueId, $seasonId, $week, 'draft_coverage',
                "Character Check: A Clean Draft Class",
                $body, $scout['name'], $scout['style'], null, null, $now
            );
            return;
        }

        $body = "We have to talk about the things that make front offices uncomfortable. ";
        $body .= "Talent evaluation isn't just about the 40-yard dash and the bench press. ";
        $body .= "It's about who a player is when the cameras are off.\n\n";

        foreach ($flagged as $p) {
            $name = $p['first_name'] . ' ' . $p['last_name'];
            $flagText = ucwords(str_replace('_', ' ', $p['character_flag']));

            // Check for previous coverage
            $prevArticle = $this->getPreviousArticleAboutProspect($leagueId, $scout['name'], (int) $p['id']);

            if ($prevArticle) {
                $body .= "We first reported on {$name}'s situation weeks ago. Since then, the story has only gotten more complicated. ";
            } else {
                $body .= "{$name}, {$p['position']} out of {$p['college']}, is one of the most talented players in this class. ";
                $body .= "He's also one of the most complicated.\n\n";
            }

            $body .= "The concern: {$flagText}.\n\n";
            $body .= "Here's what I know: the talent is real. We're talking about a player with ";
            $body .= ($p['potential'] === 'elite' ? "generational" : ($p['potential'] === 'high' ? "first-round" : "legitimate")) . " ability. ";
            $body .= "But every team I've spoken to is weighing the same question — is the risk worth the reward?\n\n";
        }

        $body .= "History shows us that character concerns sometimes fade, and sometimes they don't. ";
        $body .= "The franchises that do the best job vetting these situations are the ones that win in April — and in January.";

        $this->insertArticle(
            $leagueId, $seasonId, $week, 'draft_coverage',
            "Behind the Curtain: Character Questions in the {$year} Draft",
            $body, $scout['name'], $scout['style'], null, null, $now
        );
    }

    // ================================================================
    //  Draft Day Coverage
    // ================================================================

    private function generateDraftDayDramaArticle(int $leagueId, int $seasonId, int $year, array $picks, string $now): void
    {
        $scout = $this->pickScout('nina_charles');

        // Identify the drama: surprises, slides, reaches
        $surprises = [];
        $slides = [];

        foreach ($picks as $pick) {
            $round = (int) ($pick['round'] ?? 1);
            $overall = (int) ($pick['overall_rating'] ?? 70);
            $pos = $pick['position'] ?? '';
            $name = trim(($pick['first_name'] ?? '') . ' ' . ($pick['last_name'] ?? ''));

            // A "reach" — low-rated player picked in round 1
            if ($round === 1 && $overall < 65) {
                $surprises[] = ['name' => $name, 'pos' => $pos, 'team' => $pick['team_city'] . ' ' . $pick['team_name'], 'type' => 'reach', 'round' => $round, 'overall' => $overall];
            }
            // A "slide" — high-rated player falls to round 2+
            if ($round >= 2 && $overall >= 75) {
                $slides[] = ['name' => $name, 'pos' => $pos, 'team' => $pick['team_city'] . ' ' . $pick['team_name'], 'type' => 'slide', 'round' => $round, 'overall' => $overall];
            }
        }

        // Find prospects Nina was following (articles she wrote about)
        $followedStory = null;
        $stmt = $this->db->prepare(
            "SELECT a.player_id, a.headline FROM articles
             WHERE league_id = ? AND author_name = ? AND player_id IS NOT NULL AND type = 'feature'
             ORDER BY published_at DESC LIMIT 1"
        );
        $stmt->execute([$leagueId, $scout['name']]);
        $followedRow = $stmt->fetch();

        if ($followedRow) {
            // Find if that player was drafted
            foreach ($picks as $pick) {
                if (isset($pick['player_id']) && (int) $pick['player_id'] === (int) $followedRow['player_id']) {
                    $followedStory = $pick;
                    break;
                }
            }
        }

        $firstPick = $picks[0] ?? null;

        $body = "The phone calls are over. The war rooms are empty. The board is clear. Draft night is done — and what a night it was.\n\n";

        // Opening pick drama
        if ($firstPick) {
            $fpName = trim(($firstPick['first_name'] ?? '') . ' ' . ($firstPick['last_name'] ?? ''));
            $fpTeam = ($firstPick['team_city'] ?? '') . ' ' . ($firstPick['team_name'] ?? '');
            $body .= "With the first overall pick, the {$fpTeam} selected {$fpName} ({$firstPick['position']}). ";
            $body .= "The franchise has its cornerstone.\n\n";
        }

        // Followed storyline payoff
        if ($followedStory) {
            $fsName = trim(($followedStory['first_name'] ?? '') . ' ' . ($followedStory['last_name'] ?? ''));
            $fsTeam = ($followedStory['team_city'] ?? '') . ' ' . ($followedStory['team_name'] ?? '');
            $body .= "The story we've been following all offseason has its ending. {$fsName} — the prospect we first profiled months ago — ";
            $body .= "heard his name called by the {$fsTeam}. For a young man who has been under the microscope for months, ";
            $body .= "the wait is finally over.\n\n";
        }

        // Surprises
        if (!empty($surprises)) {
            $body .= "The pick that had the room buzzing: ";
            $s = $surprises[0];
            $body .= "the {$s['team']} taking {$s['name']} ({$s['pos']}) in round {$s['round']}. ";
            $body .= "Most boards had him going later. That's either a franchise-defining move or a franchise-altering mistake. Time will tell.\n\n";
        }

        // Slides
        if (!empty($slides)) {
            $body .= "The slide of the night: ";
            $sl = $slides[0];
            $body .= "{$sl['name']} ({$sl['pos']}) fell to round {$sl['round']} before the {$sl['team']} scooped him up. ";
            $body .= "There will be 31 other teams asking themselves how they let that happen.\n\n";
        }

        $body .= "Draft night is about hope. Every franchise believes they got better today. ";
        $body .= "But the real story won't be written until these players take the field.";

        $this->insertArticle(
            $leagueId, $seasonId, null, 'draft_coverage',
            "Draft Day Drama: The Picks That Defined the {$year} Class",
            $body, $scout['name'], $scout['style'], null, null, $now
        );
    }

    private function generateDraftGradesArticle(int $leagueId, int $seasonId, int $year, array $picks, string $now): void
    {
        $scout = $this->pickScout('jake_morrison');

        // Group picks by team
        $teamPicks = [];
        foreach ($picks as $pick) {
            $teamId = (int) ($pick['team_id'] ?? 0);
            if (!$teamId) continue;
            $teamPicks[$teamId][] = $pick;
        }

        if (empty($teamPicks)) {
            return;
        }

        $body = "The draft is in the books. Now comes my favorite part — grading the work. ";
        $body .= "This isn't about hindsight. This is about process: did you draft for need? Did you get value? Did you make smart moves?\n\n";
        $body .= "Here are my grades for every team:\n\n";

        foreach ($teamPicks as $teamId => $tPicks) {
            $teamCity = $tPicks[0]['team_city'] ?? '';
            $teamName = $tPicks[0]['team_name'] ?? '';
            $teamAbbr = $tPicks[0]['team_abbreviation'] ?? '';
            $needs = $this->getTeamNeeds($teamId);

            // Calculate grade
            $totalValue = 0;
            $needsHit = 0;
            $pickDescriptions = [];

            foreach ($tPicks as $tp) {
                $round = (int) ($tp['round'] ?? 4);
                $overall = (int) ($tp['overall_rating'] ?? 60);
                $pos = $tp['position'] ?? '';
                $name = trim(($tp['first_name'] ?? '') . ' ' . ($tp['last_name'] ?? ''));

                // Value = how good is this player relative to where they were picked?
                $expectedOvr = match ($round) {
                    1 => 72, 2 => 68, 3 => 64, 4 => 60, 5 => 57, 6 => 54, default => 52,
                };
                $value = $overall - $expectedOvr;
                $totalValue += $value;

                // Need hit
                if (in_array($pos, $needs)) {
                    $needsHit++;
                }

                $pickDescriptions[] = "Rd {$round}: {$name} ({$pos})";
            }

            $pickCount = count($tPicks);
            $avgValue = $pickCount > 0 ? $totalValue / $pickCount : 0;
            $needsRate = $pickCount > 0 ? $needsHit / $pickCount : 0;

            // Calculate letter grade
            $gradeScore = $avgValue * 2 + ($needsRate * 10);
            $grade = match (true) {
                $gradeScore >= 15 => 'A+',
                $gradeScore >= 10 => 'A',
                $gradeScore >= 6  => 'A-',
                $gradeScore >= 3  => 'B+',
                $gradeScore >= 0  => 'B',
                $gradeScore >= -3 => 'B-',
                $gradeScore >= -6 => 'C+',
                $gradeScore >= -10 => 'C',
                $gradeScore >= -15 => 'D',
                default           => 'F',
            };

            $body .= "**{$teamCity} {$teamName}** ({$teamAbbr}) — Grade: {$grade}\n";
            $body .= "Picks: " . implode(', ', $pickDescriptions) . "\n";

            // Commentary
            $body .= match (true) {
                $gradeScore >= 10 => "Outstanding draft. They addressed needs and found value. This is how you build a contender.\n",
                $gradeScore >= 3  => "Solid draft. They hit on some needs and didn't reach. A few of these picks could start as rookies.\n",
                $gradeScore >= -3 => "Acceptable work. Nothing spectacular, nothing disastrous. The middle of the pack.\n",
                $gradeScore >= -10 => "Questionable decisions. Some reaches, some missed opportunities. This class needs development.\n",
                default           => "Head-scratcher. Multiple reaches, needs unaddressed, and a strategy that's hard to defend.\n",
            };
            $body .= "\n";
        }

        $body .= "Remember: draft grades are written in pencil. The real grades come three years from now. But based on what we know today, some teams did better work than others.";

        $this->insertArticle(
            $leagueId, $seasonId, null, 'draft_coverage',
            "Morrison's Draft Grades: Winners, Losers, and Head-Scratchers",
            $body, $scout['name'], $scout['style'], null, null, $now
        );
    }

    // ================================================================
    //  Helper Methods
    // ================================================================

    /**
     * Fetch a single prospect by ID.
     */
    private function getProspect(int $id): array
    {
        $stmt = $this->db->prepare("SELECT * FROM draft_prospects WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: [];
    }

    /**
     * Analyze a team's roster for weakest positions (needs).
     * Returns an array of position strings sorted by need.
     */
    private function getTeamNeeds(int $teamId): array
    {
        $positionGroups = ['QB', 'RB', 'WR', 'TE', 'OT', 'OG', 'C', 'DE', 'DT', 'LB', 'CB', 'S'];
        $needs = [];

        foreach ($positionGroups as $pos) {
            $stmt = $this->db->prepare(
                "SELECT AVG(overall_rating) as avg_ovr, COUNT(*) as cnt
                 FROM players
                 WHERE team_id = ? AND position = ? AND status = 'active'"
            );
            $stmt->execute([$teamId, $pos]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            $avgOvr = (float) ($row['avg_ovr'] ?? 0);
            $count = (int) ($row['cnt'] ?? 0);

            // Minimum roster counts by position
            $minCount = match ($pos) {
                'QB' => 2, 'RB' => 2, 'WR' => 4, 'TE' => 2,
                'OT' => 2, 'OG' => 2, 'C' => 1,
                'DE' => 3, 'DT' => 2, 'LB' => 3, 'CB' => 3, 'S' => 2,
                default => 1,
            };

            // Need score: lower is worse (higher priority need)
            $needScore = $avgOvr;
            if ($count < $minCount) {
                $needScore -= (($minCount - $count) * 15); // Heavy penalty for understaffed positions
            }
            if ($count === 0) {
                $needScore = 0; // Critical need
            }

            $needs[$pos] = $needScore;
        }

        // Sort by need (lowest score = biggest need)
        asort($needs);

        return array_keys(array_slice($needs, 0, 5, true));
    }

    /**
     * Return a scout profile from self::SCOUTS.
     */
    private function pickScout(string $key): array
    {
        return self::SCOUTS[$key] ?? self::SCOUTS['jake_morrison'];
    }

    /**
     * Insert an article into the articles table.
     */
    private function insertArticle(
        int $leagueId,
        int $seasonId,
        ?int $week,
        string $type,
        string $headline,
        string $body,
        string $authorName,
        string $authorPersona,
        ?int $teamId,
        ?int $playerId,
        string $publishedAt
    ): void {
        $stmt = $this->db->prepare(
            "INSERT INTO articles (league_id, season_id, week, type, headline, body, author_name, author_persona, team_id, player_id, game_id, is_ai_generated, published_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)"
        );
        $stmt->execute([
            $leagueId, $seasonId, $week, $type, $headline, $body,
            $authorName, $authorPersona, $teamId, $playerId, null, $publishedAt,
        ]);
    }

    /**
     * Get season year from the league.
     */
    private function getSeasonYear(int $leagueId): int
    {
        $stmt = $this->db->prepare("SELECT season_year FROM leagues WHERE id = ?");
        $stmt->execute([$leagueId]);
        return (int) ($stmt->fetchColumn() ?: 2026);
    }

    /**
     * Get the most recent article by a specific author about a specific prospect.
     */
    private function getPreviousArticleAboutProspect(int $leagueId, string $authorName, int $playerId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM articles
             WHERE league_id = ? AND author_name = ? AND player_id = ?
             ORDER BY published_at DESC LIMIT 1"
        );
        $stmt->execute([$leagueId, $authorName, $playerId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Get a one-line scouting report for a position/potential tier.
     */
    private function getScoutingLine(string $position, string $potential): string
    {
        $tier = match ($potential) {
            'elite' => 'elite',
            'high'  => 'high',
            default => 'avg',
        };

        $lines = self::POSITION_SCOUTING_LINES[$position][$tier]
            ?? self::POSITION_SCOUTING_LINES[$position]['avg']
            ?? ['A talented prospect with upside.'];

        return $lines[array_rand($lines)];
    }

    /**
     * Derive a combine grade from a numeric score (fallback when combine_grade column is null).
     */
    private function deriveCombineGrade(int $combineScore): string
    {
        return match (true) {
            $combineScore >= 90 => 'A+',
            $combineScore >= 82 => 'A',
            $combineScore >= 74 => 'B+',
            $combineScore >= 66 => 'B',
            $combineScore >= 58 => 'C+',
            $combineScore >= 50 => 'C',
            default             => 'D',
        };
    }
}
