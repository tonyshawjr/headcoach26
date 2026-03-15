<?php

namespace App\Services;

class ScheduleGenerator
{
    private const NFL_TEAM_COUNT = 32;
    private const NFL_GAMES = 17;
    private const NFL_WEEKS = 18;
    private const BYE_WEEK_START = 2;
    private const BYE_WEEK_END = 14;
    private const MAX_BYES_PER_WEEK = 4;

    /**
     * Generate a regular season schedule for a league.
     *
     * For a standard 32-team league (2 conferences, 4 divisions, 4 teams each):
     * produces exactly 17 games per team across 18 weeks with 1 bye.
     *
     * For non-standard leagues: falls back to balanced round-robin.
     */
    public function generate(int $leagueId, int $seasonId, array $teams): array
    {
        $numTeams = count($teams);
        if ($numTeams < 2) {
            return [];
        }

        // Check if we have a standard 32-team NFL structure
        $structure = $this->analyzeStructure($teams);

        if ($structure['is_nfl_standard']) {
            $matchups = $this->generateNFLSchedule($structure);
        } else {
            $matchups = $this->generateFallbackSchedule($teams);
        }

        // Determine week count and target games
        $targetGames = $structure['is_nfl_standard'] ? self::NFL_GAMES : $this->calculateTargetGames($numTeams);
        $maxWeek = $targetGames + 1; // +1 for bye week

        // Assign games to weeks using constraint solver
        $scheduled = $this->assignWeeks($matchups, $teams, $leagueId, $seasonId, $maxWeek, $targetGames, $structure['is_nfl_standard']);

        // Validate schedule integrity
        $this->validate($scheduled, $teams, $targetGames, $maxWeek, $structure['is_nfl_standard']);

        return $scheduled;
    }

    /**
     * Analyze the team structure to determine if it matches NFL format.
     */
    private function analyzeStructure(array $teams): array
    {
        $conferences = [];
        $divisions = [];
        $teamIndex = [];

        foreach ($teams as $team) {
            $conf = $team['conference'];
            $div = $team['division'];
            $key = $conf . '|' . $div;

            $conferences[$conf][] = $team;
            $divisions[$key][] = $team;
            $teamIndex[$team['id']] = $team;
        }

        $isStandard = count($teams) === self::NFL_TEAM_COUNT
            && count($conferences) === 2
            && count($divisions) === 8;

        // Check each division has exactly 4 teams
        if ($isStandard) {
            foreach ($divisions as $divTeams) {
                if (count($divTeams) !== 4) {
                    $isStandard = false;
                    break;
                }
            }
        }

        return [
            'is_nfl_standard' => $isStandard,
            'conferences' => $conferences,
            'divisions' => $divisions,
            'team_index' => $teamIndex,
        ];
    }

    /**
     * Generate a full NFL-style 17-game schedule.
     *
     * Returns array of matchups: ['home' => id, 'away' => id, 'type' => string]
     */
    private function generateNFLSchedule(array $structure): array
    {
        $divisions = $structure['divisions'];
        $conferences = $structure['conferences'];

        $confNames = array_keys($conferences);
        $confDivKeys = [];
        foreach ($confNames as $conf) {
            $confDivKeys[$conf] = [];
            foreach ($divisions as $key => $divTeams) {
                if (str_starts_with($key, $conf . '|')) {
                    $confDivKeys[$conf][] = $key;
                }
            }
            sort($confDivKeys[$conf]);
        }

        $matchups = [];

        // === 1. DIVISIONAL (6 per team) — home+away vs 3 division rivals ===
        foreach ($divisions as $dt) {
            for ($i = 0; $i < 4; $i++) {
                for ($j = $i + 1; $j < 4; $j++) {
                    $matchups[] = ['home' => $dt[$i]['id'], 'away' => $dt[$j]['id'], 'type' => 'divisional'];
                    $matchups[] = ['home' => $dt[$j]['id'], 'away' => $dt[$i]['id'], 'type' => 'divisional'];
                }
            }
        }

        // === 2. INTRA-CONFERENCE ROTATION (4 per team) — paired divisions 0<>1, 2<>3 ===
        foreach ($confNames as $conf) {
            $k = $confDivKeys[$conf];
            $pairs = [[$k[0], $k[1]], [$k[2], $k[3]]];
            foreach ($pairs as [$dA, $dB]) {
                foreach ($divisions[$dA] as $iA => $tA) {
                    foreach ($divisions[$dB] as $iB => $tB) {
                        if ($iB < 2) {
                            $matchups[] = ['home' => $tA['id'], 'away' => $tB['id'], 'type' => 'intra_conf'];
                        } else {
                            $matchups[] = ['home' => $tB['id'], 'away' => $tA['id'], 'type' => 'intra_conf'];
                        }
                    }
                }
            }
        }

        // === 3. CROSS-CONFERENCE ROTATION (4 per team) — conf0[i] <> conf1[i] ===
        for ($i = 0; $i < 4; $i++) {
            $dA = $confDivKeys[$confNames[0]][$i];
            $dB = $confDivKeys[$confNames[1]][$i];
            foreach ($divisions[$dA] as $iA => $tA) {
                foreach ($divisions[$dB] as $iB => $tB) {
                    if ($iB < 2) {
                        $matchups[] = ['home' => $tA['id'], 'away' => $tB['id'], 'type' => 'cross_conf'];
                    } else {
                        $matchups[] = ['home' => $tB['id'], 'away' => $tA['id'], 'type' => 'cross_conf'];
                    }
                }
            }
        }

        // === 4. INTRA-CONFERENCE POSITION (2 per team) — non-paired divisions, same slot ===
        foreach ($confNames as $conf) {
            $k = $confDivKeys[$conf];
            // Non-paired: (0,2), (0,3), (1,2), (1,3)
            $posPairs = [[$k[0], $k[2]], [$k[0], $k[3]], [$k[1], $k[2]], [$k[1], $k[3]]];
            foreach ($posPairs as $pi => [$dA, $dB]) {
                for ($p = 0; $p < 4; $p++) {
                    $tA = $divisions[$dA][$p];
                    $tB = $divisions[$dB][$p];
                    if ($pi % 2 === 0) {
                        $matchups[] = ['home' => $tA['id'], 'away' => $tB['id'], 'type' => 'intra_conf_pos'];
                    } else {
                        $matchups[] = ['home' => $tB['id'], 'away' => $tA['id'], 'type' => 'intra_conf_pos'];
                    }
                }
            }
        }

        // === 5. CROSS-CONFERENCE 17th GAME (1 per team) — derangement: 0->1, 1->0, 2->3, 3->2 ===
        $crossPosMap = [1, 0, 3, 2];
        foreach ($confDivKeys[$confNames[0]] as $idx => $dk0) {
            $dk1 = $confDivKeys[$confNames[1]][$crossPosMap[$idx]];
            for ($p = 0; $p < 4; $p++) {
                $tA = $divisions[$dk0][$p];
                $tB = $divisions[$dk1][$p];
                if ($p % 2 === 0) {
                    $matchups[] = ['home' => $tA['id'], 'away' => $tB['id'], 'type' => 'cross_conf_pos'];
                } else {
                    $matchups[] = ['home' => $tB['id'], 'away' => $tA['id'], 'type' => 'cross_conf_pos'];
                }
            }
        }

        // Verify: 272 total games, 17 per team
        $counts = [];
        foreach ($matchups as $m) {
            $counts[$m['home']] = ($counts[$m['home']] ?? 0) + 1;
            $counts[$m['away']] = ($counts[$m['away']] ?? 0) + 1;
        }
        foreach ($counts as $teamId => $count) {
            if ($count !== self::NFL_GAMES) {
                throw new \RuntimeException("Team {$teamId} has {$count} games, expected " . self::NFL_GAMES);
            }
        }

        return $matchups;
    }

    /**
     * Fallback schedule for non-standard league sizes.
     * Creates a balanced round-robin with target game count.
     */
    private function generateFallbackSchedule(array $teams): array
    {
        $numTeams = count($teams);
        $targetGames = $this->calculateTargetGames($numTeams);

        $matchups = [];
        $teamIds = array_column($teams, 'id');
        $teamGameCount = array_fill_keys($teamIds, 0);
        $pairCount = [];

        // First pass: create a round-robin base
        for ($i = 0; $i < $numTeams; $i++) {
            for ($j = $i + 1; $j < $numTeams; $j++) {
                if ($teamGameCount[$teamIds[$i]] >= $targetGames || $teamGameCount[$teamIds[$j]] >= $targetGames) {
                    continue;
                }

                $homeFirst = (($i + $j) % 2 === 0);
                $matchups[] = [
                    'home' => $homeFirst ? $teamIds[$i] : $teamIds[$j],
                    'away' => $homeFirst ? $teamIds[$j] : $teamIds[$i],
                    'type' => 'regular',
                ];
                $teamGameCount[$teamIds[$i]]++;
                $teamGameCount[$teamIds[$j]]++;

                $pairKey = min($teamIds[$i], $teamIds[$j]) . '-' . max($teamIds[$i], $teamIds[$j]);
                $pairCount[$pairKey] = ($pairCount[$pairKey] ?? 0) + 1;
            }
        }

        // Second pass: fill remaining games
        $maxAttempts = 3000;
        $attempts = 0;
        while ($attempts < $maxAttempts) {
            $needGames = [];
            foreach ($teamIds as $id) {
                if ($teamGameCount[$id] < $targetGames) {
                    $needGames[] = $id;
                }
            }
            if (empty($needGames)) break;

            usort($needGames, fn($a, $b) => $teamGameCount[$a] - $teamGameCount[$b]);

            $paired = false;
            for ($i = 0; $i < count($needGames) && !$paired; $i++) {
                for ($j = $i + 1; $j < count($needGames) && !$paired; $j++) {
                    $a = min($needGames[$i], $needGames[$j]);
                    $b = max($needGames[$i], $needGames[$j]);
                    $pairKey = $a . '-' . $b;

                    if (($pairCount[$pairKey] ?? 0) < 2) {
                        $homeFirst = mt_rand(0, 1) === 0;
                        $matchups[] = [
                            'home' => $homeFirst ? $needGames[$i] : $needGames[$j],
                            'away' => $homeFirst ? $needGames[$j] : $needGames[$i],
                            'type' => 'regular',
                        ];
                        $teamGameCount[$needGames[$i]]++;
                        $teamGameCount[$needGames[$j]]++;
                        $pairCount[$pairKey] = ($pairCount[$pairKey] ?? 0) + 1;
                        $paired = true;
                    }
                }
            }
            if (!$paired) break;
            $attempts++;
        }

        return $matchups;
    }

    /**
     * Calculate target games per team based on league size.
     */
    private function calculateTargetGames(int $numTeams): int
    {
        return match (true) {
            $numTeams <= 6  => 10,
            $numTeams <= 8  => 13,
            $numTeams <= 12 => 14,
            $numTeams <= 16 => 16,
            $numTeams <= 24 => 16,
            default         => 17,
        };
    }

    /**
     * Assign games to weeks using constraint-based solver with bye week pre-assignment.
     * Guarantees all matchups are placed with no team playing twice in any week.
     */
    private function assignWeeks(array $matchups, array $teams, int $leagueId, int $seasonId, int $maxWeek, int $targetGames, bool $isNFL): array
    {
        $teamIds = array_column($teams, 'id');
        $teamDivision = [];
        foreach ($teams as $team) {
            $teamDivision[$team['id']] = ($team['conference'] ?? '') . '|' . ($team['division'] ?? '');
        }

        // Try multiple bye week assignments until the constraint solver succeeds
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $byeWeeks = $this->assignByeWeeks($teamIds, $teamDivision, $isNFL, $maxWeek);
            $result = $this->solveWeekAssignment($matchups, $byeWeeks, $maxWeek);

            if ($result !== null) {
                $scheduled = [];
                foreach ($result as $gameIdx => $week) {
                    $m = $matchups[$gameIdx];
                    $scheduled[] = $this->makeGameRecord($leagueId, $seasonId, $week, $m['home'], $m['away']);
                }
                return $scheduled;
            }
        }

        // Last resort: greedy fallback (should never reach here for valid inputs)
        return $this->greedyFallback($matchups, $leagueId, $seasonId, $maxWeek);
    }

    /**
     * Constraint solver using MCV (Most Constrained Variable) heuristic + swap repair.
     *
     * Phase 1: Greedily assign games starting with the most constrained (fewest available weeks).
     * Phase 2: For any stuck games, resolve by swapping already-placed games to other weeks.
     *
     * @return array<int, int>|null  gameIdx => week mapping, or null if unsolvable
     */
    private function solveWeekAssignment(array $matchups, array $byeWeeks, int $maxWeek): ?array
    {
        $numGames = count($matchups);
        $assignment = array_fill(0, $numGames, 0);
        $weekLoad = array_fill(1, $maxWeek, 0);

        // Track team schedules: teamId => [week => gameIdx] where -1 = bye
        $teamSchedule = [];
        foreach ($byeWeeks as $teamId => $week) {
            $teamSchedule[$teamId] = [$week => -1];
        }
        foreach ($matchups as $m) {
            if (!isset($teamSchedule[$m['home']])) $teamSchedule[$m['home']] = [];
            if (!isset($teamSchedule[$m['away']])) $teamSchedule[$m['away']] = [];
        }

        // Compute valid weeks per game (weeks where neither team has a bye)
        $domains = [];
        foreach ($matchups as $idx => $m) {
            $domains[$idx] = [];
            $homeBye = $byeWeeks[$m['home']] ?? 0;
            $awayBye = $byeWeeks[$m['away']] ?? 0;
            for ($w = 1; $w <= $maxWeek; $w++) {
                if ($w !== $homeBye && $w !== $awayBye) {
                    $domains[$idx][$w] = true;
                }
            }
        }

        // === Phase 1: Greedy MCV assignment ===
        // Pick the game with fewest available weeks, assign to least-loaded valid week
        $remaining = array_flip(range(0, $numGames - 1));

        while (!empty($remaining)) {
            $bestGame = null;
            $bestAvail = PHP_INT_MAX;

            foreach ($remaining as $gameIdx => $_) {
                $m = $matchups[$gameIdx];
                $avail = 0;
                foreach ($domains[$gameIdx] as $w => $__) {
                    if (!isset($teamSchedule[$m['home']][$w]) && !isset($teamSchedule[$m['away']][$w])) {
                        $avail++;
                    }
                }
                if ($avail < $bestAvail) {
                    $bestAvail = $avail;
                    $bestGame = $gameIdx;
                    if ($avail === 0) break;
                }
            }

            if ($bestAvail === 0) break; // Stuck — Phase 2 needed

            // Pick least-loaded available week for balanced distribution
            $m = $matchups[$bestGame];
            $bestWeek = null;
            $bestLoad = PHP_INT_MAX;

            foreach ($domains[$bestGame] as $w => $_) {
                if (!isset($teamSchedule[$m['home']][$w]) && !isset($teamSchedule[$m['away']][$w])) {
                    if ($weekLoad[$w] < $bestLoad) {
                        $bestLoad = $weekLoad[$w];
                        $bestWeek = $w;
                    }
                }
            }

            $assignment[$bestGame] = $bestWeek;
            $teamSchedule[$m['home']][$bestWeek] = $bestGame;
            $teamSchedule[$m['away']][$bestWeek] = $bestGame;
            $weekLoad[$bestWeek]++;
            unset($remaining[$bestGame]);
        }

        if (empty($remaining)) {
            return $assignment;
        }

        // === Phase 2: Swap-based repair ===
        // For each stuck game, try to free up a week by moving conflicting games elsewhere
        $stuck = array_keys($remaining);
        $maxAttempts = count($stuck) * 200;
        $attempts = 0;

        while (!empty($stuck) && $attempts < $maxAttempts) {
            $attempts++;
            $gameIdx = $stuck[0];
            $m = $matchups[$gameIdx];
            $placed = false;

            foreach ($domains[$gameIdx] as $w => $_) {
                $homeConflict = $teamSchedule[$m['home']][$w] ?? null;
                $awayConflict = $teamSchedule[$m['away']][$w] ?? null;

                // Can't swap with bye weeks
                if ($homeConflict === -1 || $awayConflict === -1) continue;

                // Both teams free — assign directly
                if ($homeConflict === null && $awayConflict === null) {
                    $assignment[$gameIdx] = $w;
                    $teamSchedule[$m['home']][$w] = $gameIdx;
                    $teamSchedule[$m['away']][$w] = $gameIdx;
                    $weekLoad[$w]++;
                    $placed = true;
                    break;
                }

                // Single conflict — try to move the blocking game to another week
                if ($homeConflict !== null && $awayConflict === null) {
                    if ($this->trySwapGame($homeConflict, $w, $matchups, $assignment, $teamSchedule, $weekLoad, $domains)) {
                        $assignment[$gameIdx] = $w;
                        $teamSchedule[$m['home']][$w] = $gameIdx;
                        $teamSchedule[$m['away']][$w] = $gameIdx;
                        $weekLoad[$w]++;
                        $placed = true;
                        break;
                    }
                } elseif ($awayConflict !== null && $homeConflict === null) {
                    if ($this->trySwapGame($awayConflict, $w, $matchups, $assignment, $teamSchedule, $weekLoad, $domains)) {
                        $assignment[$gameIdx] = $w;
                        $teamSchedule[$m['home']][$w] = $gameIdx;
                        $teamSchedule[$m['away']][$w] = $gameIdx;
                        $weekLoad[$w]++;
                        $placed = true;
                        break;
                    }
                }

                // Double conflict — snapshot state, try to move both blocking games
                if ($homeConflict !== null && $awayConflict !== null && $homeConflict !== $awayConflict) {
                    $snapAssignment = $assignment;
                    $snapSchedule = $teamSchedule;
                    $snapLoad = $weekLoad;

                    if ($this->trySwapGame($homeConflict, $w, $matchups, $assignment, $teamSchedule, $weekLoad, $domains) &&
                        $this->trySwapGame($awayConflict, $w, $matchups, $assignment, $teamSchedule, $weekLoad, $domains)) {
                        $assignment[$gameIdx] = $w;
                        $teamSchedule[$m['home']][$w] = $gameIdx;
                        $teamSchedule[$m['away']][$w] = $gameIdx;
                        $weekLoad[$w]++;
                        $placed = true;
                        break;
                    } else {
                        // Rollback both moves
                        $assignment = $snapAssignment;
                        $teamSchedule = $snapSchedule;
                        $weekLoad = $snapLoad;
                    }
                }
            }

            if ($placed) {
                array_shift($stuck);
            } else {
                // Rotate: try another stuck game first, come back to this one later
                $stuck[] = array_shift($stuck);
            }
        }

        return empty($stuck) ? $assignment : null;
    }

    /**
     * Try to move an already-assigned game from its current week to any other available week.
     */
    private function trySwapGame(
        int $gameIdx,
        int $fromWeek,
        array $matchups,
        array &$assignment,
        array &$teamSchedule,
        array &$weekLoad,
        array $domains
    ): bool {
        $m = $matchups[$gameIdx];
        $home = $m['home'];
        $away = $m['away'];

        foreach ($domains[$gameIdx] as $w => $_) {
            if ($w === $fromWeek) continue;
            if (!isset($teamSchedule[$home][$w]) && !isset($teamSchedule[$away][$w])) {
                // Move game: clear old week, assign new week
                unset($teamSchedule[$home][$fromWeek]);
                unset($teamSchedule[$away][$fromWeek]);
                $weekLoad[$fromWeek]--;

                $assignment[$gameIdx] = $w;
                $teamSchedule[$home][$w] = $gameIdx;
                $teamSchedule[$away][$w] = $gameIdx;
                $weekLoad[$w]++;
                return true;
            }
        }
        return false;
    }

    /**
     * Emergency greedy fallback — only used if constraint solver fails after all retries.
     */
    private function greedyFallback(array $matchups, int $leagueId, int $seasonId, int $maxWeek): array
    {
        shuffle($matchups);
        $teamBusy = [];
        for ($w = 1; $w <= $maxWeek; $w++) {
            $teamBusy[$w] = [];
        }

        $scheduled = [];
        foreach ($matchups as $m) {
            for ($w = 1; $w <= $maxWeek; $w++) {
                if (!isset($teamBusy[$w][$m['home']]) && !isset($teamBusy[$w][$m['away']])) {
                    $teamBusy[$w][$m['home']] = true;
                    $teamBusy[$w][$m['away']] = true;
                    $scheduled[] = $this->makeGameRecord($leagueId, $seasonId, $w, $m['home'], $m['away']);
                    break;
                }
            }
        }

        return $scheduled;
    }

    /**
     * Assign bye weeks to all teams.
     *
     * NFL-style: byes in weeks 5-14, max 4 teams per week,
     * no full division on bye the same week.
     */
    private function assignByeWeeks(array $teamIds, array $teamDivision, bool $isNFL, int $maxWeek): array
    {
        $byeWeeks = [];

        if ($isNFL) {
            $byeStart = self::BYE_WEEK_START;
            $byeEnd = self::BYE_WEEK_END;
            $byeRange = range($byeStart, $byeEnd);
            $byeWeekCounts = array_fill_keys($byeRange, 0);
            $byeWeekDivisions = []; // week => [division => count]
            foreach ($byeRange as $w) {
                $byeWeekDivisions[$w] = [];
            }

            // Group teams by division for constraint checking
            $divisionTeams = [];
            foreach ($teamIds as $id) {
                $div = $teamDivision[$id];
                $divisionTeams[$div][] = $id;
            }

            // Shuffle teams so it's not always the same assignment
            $shuffledTeams = $teamIds;
            shuffle($shuffledTeams);

            foreach ($shuffledTeams as $teamId) {
                $div = $teamDivision[$teamId];
                $divSize = count($divisionTeams[$div]);

                // Shuffle bye range for variety
                $candidates = $byeRange;
                shuffle($candidates);

                // Sort by fewest teams already on bye that week
                usort($candidates, fn($a, $b) => $byeWeekCounts[$a] - $byeWeekCounts[$b]);

                foreach ($candidates as $week) {
                    if ($byeWeekCounts[$week] >= self::MAX_BYES_PER_WEEK) {
                        continue;
                    }

                    // Check that not all teams in this division have the same bye week
                    $divCountThisWeek = $byeWeekDivisions[$week][$div] ?? 0;
                    if ($divCountThisWeek >= $divSize - 1) {
                        // Would put all division teams on same bye
                        continue;
                    }

                    $byeWeeks[$teamId] = $week;
                    $byeWeekCounts[$week]++;
                    $byeWeekDivisions[$week][$div] = $divCountThisWeek + 1;
                    break;
                }

                // Fallback: if somehow no week found, use least-loaded
                if (!isset($byeWeeks[$teamId])) {
                    asort($byeWeekCounts);
                    $week = array_key_first($byeWeekCounts);
                    $byeWeeks[$teamId] = $week;
                    $byeWeekCounts[$week]++;
                }
            }
        } else {
            // Non-NFL: spread byes across middle weeks
            $byeStart = max(2, (int)($maxWeek * 0.25));
            $byeEnd = min($maxWeek - 1, (int)($maxWeek * 0.75));
            $byeRange = range($byeStart, $byeEnd);
            $idx = 0;

            foreach ($teamIds as $teamId) {
                $byeWeeks[$teamId] = $byeRange[$idx % count($byeRange)];
                $idx++;
            }
        }

        return $byeWeeks;
    }

    /**
     * Create a game record array ready for DB insertion.
     */
    private function makeGameRecord(int $leagueId, int $seasonId, int $week, int $homeId, int $awayId): array
    {
        return [
            'league_id' => $leagueId,
            'season_id' => $seasonId,
            'week' => $week,
            'game_type' => 'regular',
            'home_team_id' => $homeId,
            'away_team_id' => $awayId,
            'home_score' => null,
            'away_score' => null,
            'is_simulated' => 0,
            'weather' => $this->randomWeather(),
            'home_game_plan' => null,
            'away_game_plan' => null,
            'box_score' => null,
            'turning_point' => null,
            'player_grades' => null,
            'simulated_at' => null,
        ];
    }

    /**
     * Validate the generated schedule meets all constraints.
     *
     * @param bool $strict  When true (NFL), requires exact game counts and exactly 1 bye.
     *                      When false (non-NFL), allows targetGames-1 and 1-2 byes.
     * @throws \RuntimeException if any constraint is violated
     */
    private function validate(array $schedule, array $teams, int $targetGames, int $maxWeek, bool $strict = false): void
    {
        $teamIds = array_column($teams, 'id');
        $teamSet = array_flip($teamIds);

        // Count games per team
        $gameCount = array_fill_keys($teamIds, 0);
        // Track weeks per team
        $teamWeeks = [];
        foreach ($teamIds as $id) {
            $teamWeeks[$id] = [];
        }
        // Track opponent counts
        $opponentCount = [];
        foreach ($teamIds as $id) {
            $opponentCount[$id] = [];
        }

        foreach ($schedule as $game) {
            $home = $game['home_team_id'];
            $away = $game['away_team_id'];
            $week = $game['week'];

            // Valid teams
            if (!isset($teamSet[$home]) || !isset($teamSet[$away])) {
                throw new \RuntimeException("Invalid team ID in schedule: home={$home}, away={$away}");
            }

            // No self-play
            if ($home === $away) {
                throw new \RuntimeException("Team {$home} scheduled to play itself");
            }

            $gameCount[$home]++;
            $gameCount[$away]++;

            // No double-booking
            if (isset($teamWeeks[$home][$week])) {
                throw new \RuntimeException("Team {$home} plays twice in week {$week}");
            }
            if (isset($teamWeeks[$away][$week])) {
                throw new \RuntimeException("Team {$away} plays twice in week {$week}");
            }
            $teamWeeks[$home][$week] = true;
            $teamWeeks[$away][$week] = true;

            // Track opponents
            $opponentCount[$home][$away] = ($opponentCount[$home][$away] ?? 0) + 1;
            $opponentCount[$away][$home] = ($opponentCount[$away][$home] ?? 0) + 1;

            // Valid week
            if ($week < 1 || $week > $maxWeek) {
                throw new \RuntimeException("Invalid week {$week} (max {$maxWeek})");
            }
        }

        // Check game counts
        foreach ($gameCount as $teamId => $count) {
            if ($strict) {
                if ($count !== $targetGames) {
                    throw new \RuntimeException("Team {$teamId} has {$count} games, expected exactly {$targetGames}");
                }
            } else {
                if ($count < $targetGames - 1 || $count > $targetGames) {
                    throw new \RuntimeException("Team {$teamId} has {$count} games, expected {$targetGames}");
                }
            }
        }

        // Check bye weeks
        foreach ($teamIds as $teamId) {
            $weeksPlayed = count($teamWeeks[$teamId]);
            $byeWeekCount = $maxWeek - $weeksPlayed;
            if ($strict) {
                if ($byeWeekCount !== 1) {
                    throw new \RuntimeException("Team {$teamId} has {$byeWeekCount} bye weeks, expected exactly 1");
                }
            } else {
                if ($byeWeekCount < 1 || $byeWeekCount > 2) {
                    throw new \RuntimeException("Team {$teamId} has {$byeWeekCount} bye weeks, expected 1-2");
                }
            }
        }

        // No team plays same opponent more than twice
        foreach ($opponentCount as $teamId => $opponents) {
            foreach ($opponents as $oppId => $count) {
                if ($count > 2) {
                    throw new \RuntimeException("Team {$teamId} plays team {$oppId} {$count} times (max 2)");
                }
            }
        }
    }

    /**
     * Generate playoff bracket from standings.
     * Adapts to league size:
     * - 32 teams: 7 per conference (4 div winners + 3 wild cards)
     * - 16 teams: 4 per conference
     * - 8 teams: 4 total
     * - Single conference: top teams directly
     */
    public function generatePlayoffs(int $leagueId, int $seasonId, array $standings): array
    {
        $games = [];

        foreach ($standings as $conf => $confTeams) {
            $numTeams = count($confTeams);
            if ($numTeams < 2) continue;

            if ($numTeams >= 7) {
                // Standard: #2 vs #7, #3 vs #6, #4 vs #5, #1 bye
                $games[] = $this->makePlayoffGame($leagueId, $seasonId, 19, 'wild_card',
                    $confTeams[1]['id'], $confTeams[6]['id']);
                $games[] = $this->makePlayoffGame($leagueId, $seasonId, 19, 'wild_card',
                    $confTeams[2]['id'], $confTeams[5]['id']);
                $games[] = $this->makePlayoffGame($leagueId, $seasonId, 19, 'wild_card',
                    $confTeams[3]['id'], $confTeams[4]['id']);
            } elseif ($numTeams >= 4) {
                // #1 vs #4, #2 vs #3
                $games[] = $this->makePlayoffGame($leagueId, $seasonId, 19, 'wild_card',
                    $confTeams[0]['id'], $confTeams[3]['id']);
                $games[] = $this->makePlayoffGame($leagueId, $seasonId, 19, 'wild_card',
                    $confTeams[1]['id'], $confTeams[2]['id']);
            } elseif ($numTeams >= 2) {
                // #1 vs #2
                $games[] = $this->makePlayoffGame($leagueId, $seasonId, 19, 'wild_card',
                    $confTeams[0]['id'], $confTeams[1]['id']);
            }
        }

        return $games;
    }

    private function makePlayoffGame(int $leagueId, int $seasonId, int $week, string $type, int $homeId, int $awayId): array
    {
        return [
            'league_id' => $leagueId,
            'season_id' => $seasonId,
            'week' => $week,
            'game_type' => $type,
            'home_team_id' => $homeId,
            'away_team_id' => $awayId,
            'home_score' => null,
            'away_score' => null,
            'is_simulated' => 0,
            'weather' => $this->randomWeather(),
            'home_game_plan' => null,
            'away_game_plan' => null,
            'box_score' => null,
            'turning_point' => null,
            'player_grades' => null,
            'simulated_at' => null,
        ];
    }

    private function randomWeather(): string
    {
        $options = ['clear', 'clear', 'clear', 'clear', 'clear',
                    'cloudy', 'cloudy', 'rain', 'wind', 'snow', 'dome'];
        return $options[array_rand($options)];
    }
}
