<?php

namespace App\Services;

class ScheduleGenerator
{
    /**
     * Generate a regular season schedule for a league.
     *
     * Dynamically adapts to any number of teams, conferences, and divisions.
     * For a standard 32-team league: 17 games, 18 weeks (1 bye).
     * For smaller leagues: proportionally fewer games.
     */
    public function generate(int $leagueId, int $seasonId, array $teams): array
    {
        $numTeams = count($teams);
        if ($numTeams < 2) return [];

        // Organize teams by conference and division
        $divisions = [];
        $conferences = [];
        $teamIndex = [];

        foreach ($teams as $team) {
            $key = $team['conference'] . '_' . $team['division'];
            $divisions[$key][] = $team;
            $conferences[$team['conference']] = true;
            $teamIndex[$team['id']] = $team;
        }

        $conferenceNames = array_keys($conferences);
        $numConferences = count($conferenceNames);

        // Calculate target games per team
        $targetGames = $this->calculateTargetGames($numTeams);
        $maxWeek = $targetGames + 1; // +1 for bye week

        $games = [];

        // 1. Divisional games (home & away vs each division rival)
        foreach ($divisions as $divTeams) {
            for ($i = 0; $i < count($divTeams); $i++) {
                for ($j = $i + 1; $j < count($divTeams); $j++) {
                    $games[] = [
                        'home_team_id' => $divTeams[$i]['id'],
                        'away_team_id' => $divTeams[$j]['id'],
                        'type' => 'divisional',
                    ];
                    $games[] = [
                        'home_team_id' => $divTeams[$j]['id'],
                        'away_team_id' => $divTeams[$i]['id'],
                        'type' => 'divisional',
                    ];
                }
            }
        }

        // Count games per team so far
        $teamGameCount = array_fill_keys(array_column($teams, 'id'), 0);
        foreach ($games as $g) {
            $teamGameCount[$g['home_team_id']]++;
            $teamGameCount[$g['away_team_id']]++;
        }

        // 2. Intra-conference games: rotate which division each team plays fully
        // Each team plays all 4 teams from one other intra-conference division (like NFL)
        foreach ($conferenceNames as $conf) {
            $confDivisions = [];
            foreach ($divisions as $key => $divTeams) {
                if (str_starts_with($key, $conf . '_')) {
                    $confDivisions[$key] = $divTeams;
                }
            }

            $divKeys = array_keys($confDivisions);
            $numDivs = count($divKeys);

            if ($numDivs >= 2) {
                // Each division is paired with one other for full round-robin
                // Pair: 0↔1, 2↔3 (or wrap if odd number)
                $paired = [];
                for ($i = 0; $i < $numDivs; $i += 2) {
                    $j = ($i + 1) % $numDivs;
                    if ($i === $j) continue;
                    $pairKey = min($i, $j) . '_' . max($i, $j);
                    if (isset($paired[$pairKey])) continue;
                    $paired[$pairKey] = true;

                    $teamsA = $confDivisions[$divKeys[$i]];
                    $teamsB = $confDivisions[$divKeys[$j]];

                    // Full round-robin: every team in A vs every team in B
                    foreach ($teamsA as $ta) {
                        foreach ($teamsB as $ki => $tb) {
                            $homeFirst = ($ki % 2 === 0);
                            $games[] = [
                                'home_team_id' => $homeFirst ? $ta['id'] : $tb['id'],
                                'away_team_id' => $homeFirst ? $tb['id'] : $ta['id'],
                                'type' => 'conference',
                            ];
                        }
                    }
                }

                // Each team also plays 1 game each against the remaining intra-conf divisions
                for ($i = 0; $i < $numDivs; $i++) {
                    $pairedWith = ($i % 2 === 0) ? ($i + 1) % $numDivs : $i - 1;
                    for ($j = 0; $j < $numDivs; $j++) {
                        if ($j === $i || $j === $pairedWith) continue;
                        $teamsA = $confDivisions[$divKeys[$i]];
                        $teamsB = $confDivisions[$divKeys[$j]];
                        $count = min(count($teamsA), count($teamsB));
                        for ($k = 0; $k < $count; $k++) {
                            $homeFirst = ($k % 2 === 0);
                            $games[] = [
                                'home_team_id' => $homeFirst ? $teamsA[$k]['id'] : $teamsB[$k]['id'],
                                'away_team_id' => $homeFirst ? $teamsB[$k]['id'] : $teamsA[$k]['id'],
                                'type' => 'conference',
                            ];
                        }
                    }
                }
            }
        }

        // 3. Cross-conference games: each division plays one inter-conference division fully
        if ($numConferences >= 2) {
            $conf1Divs = [];
            $conf2Divs = [];
            foreach ($divisions as $key => $divTeams) {
                if (str_starts_with($key, $conferenceNames[0] . '_')) {
                    $conf1Divs[] = $divTeams;
                } elseif (str_starts_with($key, $conferenceNames[1] . '_')) {
                    $conf2Divs[] = $divTeams;
                }
            }

            // Pair divisions 1:1 for full round-robin across conferences
            $crossPairs = min(count($conf1Divs), count($conf2Divs));
            for ($d = 0; $d < $crossPairs; $d++) {
                $teamsA = $conf1Divs[$d];
                $teamsB = $conf2Divs[$d];

                foreach ($teamsA as $ta) {
                    foreach ($teamsB as $ki => $tb) {
                        $homeFirst = ($ki % 2 === 0);
                        $games[] = [
                            'home_team_id' => $homeFirst ? $ta['id'] : $tb['id'],
                            'away_team_id' => $homeFirst ? $tb['id'] : $ta['id'],
                            'type' => 'cross_conference',
                        ];
                    }
                }
            }
        }

        // Recount
        $teamGameCount = array_fill_keys(array_column($teams, 'id'), 0);
        foreach ($games as $g) {
            $teamGameCount[$g['home_team_id']]++;
            $teamGameCount[$g['away_team_id']]++;
        }

        // Deduplicate: remove exact duplicate matchups (same home/away pair)
        $seen = [];
        $uniqueGames = [];
        foreach ($games as $g) {
            $key = $g['home_team_id'] . '-' . $g['away_team_id'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $uniqueGames[] = $g;
            }
        }
        $games = $uniqueGames;

        // Recount after dedup
        $teamGameCount = array_fill_keys(array_column($teams, 'id'), 0);
        foreach ($games as $g) {
            $teamGameCount[$g['home_team_id']]++;
            $teamGameCount[$g['away_team_id']]++;
        }

        // Trim games for teams over target (remove from teams with most excess first)
        $overTarget = true;
        while ($overTarget) {
            $overTarget = false;
            foreach ($games as $idx => $g) {
                $hc = $teamGameCount[$g['home_team_id']];
                $ac = $teamGameCount[$g['away_team_id']];
                if ($hc > $targetGames && $ac > $targetGames) {
                    $teamGameCount[$g['home_team_id']]--;
                    $teamGameCount[$g['away_team_id']]--;
                    unset($games[$idx]);
                    $overTarget = true;
                    break;
                }
            }
        }
        $games = array_values($games);

        // 4. Fill remaining games to reach target
        // Build pair count matrix for efficiency
        $pairCount = [];
        foreach ($games as $g) {
            $a = min($g['home_team_id'], $g['away_team_id']);
            $b = max($g['home_team_id'], $g['away_team_id']);
            $key = $a . '-' . $b;
            $pairCount[$key] = ($pairCount[$key] ?? 0) + 1;
        }

        $allIds = array_column($teams, 'id');
        $maxAttempts = 5000;
        $attempts = 0;

        while ($attempts < $maxAttempts) {
            $needGames = [];
            foreach ($allIds as $id) {
                if ($teamGameCount[$id] < $targetGames) {
                    $needGames[] = $id;
                }
            }
            if (empty($needGames)) break;

            // Sort by most games needed first (greedy)
            usort($needGames, fn($a, $b) => $teamGameCount[$a] - $teamGameCount[$b]);

            $paired = false;
            for ($i = 0; $i < count($needGames) && !$paired; $i++) {
                for ($j = $i + 1; $j < count($needGames) && !$paired; $j++) {
                    $a = min($needGames[$i], $needGames[$j]);
                    $b = max($needGames[$i], $needGames[$j]);
                    $key = $a . '-' . $b;

                    if (($pairCount[$key] ?? 0) < 2) {
                        $homeFirst = (mt_rand(0, 1) === 0);
                        $games[] = [
                            'home_team_id' => $homeFirst ? $needGames[$i] : $needGames[$j],
                            'away_team_id' => $homeFirst ? $needGames[$j] : $needGames[$i],
                            'type' => 'fill',
                        ];
                        $teamGameCount[$needGames[$i]]++;
                        $teamGameCount[$needGames[$j]]++;
                        $pairCount[$key] = ($pairCount[$key] ?? 0) + 1;
                        $paired = true;
                    }
                }
            }
            if (!$paired) break; // No valid pairs left
            $attempts++;
        }

        // Assign games to weeks
        return $this->assignWeeks($games, $teams, $leagueId, $seasonId, $maxWeek);
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
     * Assign games to weeks ensuring no team plays twice in a week.
     * Each team gets approximately 1 bye week.
     */
    private function assignWeeks(array $games, array $teams, int $leagueId, int $seasonId, int $maxWeek): array
    {
        shuffle($games);

        $teamSchedule = [];
        foreach ($teams as $team) {
            $teamSchedule[$team['id']] = [];
        }

        $scheduled = [];
        $unscheduled = $games;

        for ($week = 1; $week <= $maxWeek; $week++) {
            $weekGames = [];
            $teamsThisWeek = [];
            $remaining = [];

            foreach ($unscheduled as $game) {
                $homeId = $game['home_team_id'];
                $awayId = $game['away_team_id'];

                if (!isset($teamsThisWeek[$homeId]) && !isset($teamsThisWeek[$awayId])) {
                    $teamsThisWeek[$homeId] = true;
                    $teamsThisWeek[$awayId] = true;
                    $teamSchedule[$homeId][$week] = true;
                    $teamSchedule[$awayId][$week] = true;

                    $weekGames[] = [
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
                } else {
                    $remaining[] = $game;
                }
            }

            $scheduled = array_merge($scheduled, $weekGames);
            $unscheduled = $remaining;
        }

        // Force remaining into available weeks
        foreach ($unscheduled as $game) {
            for ($week = 1; $week <= $maxWeek; $week++) {
                $homeId = $game['home_team_id'];
                $awayId = $game['away_team_id'];
                if (!isset($teamSchedule[$homeId][$week]) && !isset($teamSchedule[$awayId][$week])) {
                    $teamSchedule[$homeId][$week] = true;
                    $teamSchedule[$awayId][$week] = true;

                    $scheduled[] = [
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
                    break;
                }
            }
        }

        return $scheduled;
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
