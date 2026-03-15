<?php

namespace App\Controllers;

use App\Helpers\Response;
use App\Middleware\AuthMiddleware;
use App\Models\League;
use App\Models\Game;
use App\Models\GameStat;
use App\Models\Team;
use App\Models\Injury;
use App\Services\SimEngine;

class SimulationController
{
    private League $league;
    private Game $game;
    private GameStat $gameStat;
    private Team $team;
    private Injury $injury;

    public function __construct()
    {
        $this->league = new League();
        $this->game = new Game();
        $this->gameStat = new GameStat();
        $this->team = new Team();
        $this->injury = new Injury();
    }

    /**
     * POST /api/leagues/{league_id}/simulate
     * Simulate all games for the current week.
     */
    public function simulateWeek(array $params): void
    {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;

        $leagueId = (int) $params['league_id'];
        $league = $this->league->find($leagueId);
        if (!$league) {
            Response::notFound('League not found');
            return;
        }

        // Check if league is paused
        $commissionerService = new \App\Services\CommissionerService();
        if ($commissionerService->isLeaguePaused($leagueId)) {
            Response::error('League is paused by commissioner.');
            return;
        }

        $currentWeek = (int) $league['current_week'];
        if ($currentWeek < 1) {
            Response::error('League has not started. Advance to week 1 first.');
            return;
        }

        // Get all unsimulated games for this week
        $weekGames = $this->game->query(
            "SELECT * FROM games WHERE league_id = ? AND week = ? AND is_simulated = 0",
            [$leagueId, $currentWeek]
        );

        if (empty($weekGames)) {
            Response::error("No games to simulate for week {$currentWeek}. Already simulated or no games scheduled.");
            return;
        }

        // Pre-sim: ensure every team playing this week has a healthy QB.
        // If all QBs are injured, the AI signs the best available free agent QB.
        $this->ensureTeamsHaveQB($weekGames, $leagueId);

        $simEngine = new SimEngine();
        $results = [];
        $allInjuries = [];

        foreach ($weekGames as $game) {
            // Simulate the game
            $result = $simEngine->simulateGame($game);

            // Save scores and metadata to the games table
            $this->game->update((int) $game['id'], [
                'home_score' => $result['home_score'],
                'away_score' => $result['away_score'],
                'is_simulated' => 1,
                'box_score' => json_encode($result['box_score']),
                'turning_point' => $result['turning_point'],
                'player_grades' => json_encode($result['grades']),
                'simulated_at' => date('Y-m-d H:i:s'),
            ]);

            // Save individual player stats
            $allPlayerStats = array_merge($result['home_stats'], $result['away_stats']);
            foreach ($allPlayerStats as $playerId => $stat) {
                $stat['game_id'] = (int) $game['id'];
                $stat['grade'] = $result['grades'][$playerId] ?? null;

                // Ensure all stat columns have defaults
                $statRow = [
                    'game_id' => $stat['game_id'],
                    'player_id' => (int) $stat['player_id'],
                    'team_id' => (int) $stat['team_id'],
                    'pass_attempts' => (int) ($stat['pass_attempts'] ?? 0),
                    'pass_completions' => (int) ($stat['pass_completions'] ?? 0),
                    'pass_yards' => (int) ($stat['pass_yards'] ?? 0),
                    'pass_tds' => (int) ($stat['pass_tds'] ?? 0),
                    'interceptions' => (int) ($stat['interceptions'] ?? 0),
                    'rush_attempts' => (int) ($stat['rush_attempts'] ?? 0),
                    'rush_yards' => (int) ($stat['rush_yards'] ?? 0),
                    'rush_tds' => (int) ($stat['rush_tds'] ?? 0),
                    'targets' => (int) ($stat['targets'] ?? 0),
                    'receptions' => (int) ($stat['receptions'] ?? 0),
                    'rec_yards' => (int) ($stat['rec_yards'] ?? 0),
                    'rec_tds' => (int) ($stat['rec_tds'] ?? 0),
                    'tackles' => (int) ($stat['tackles'] ?? 0),
                    'sacks' => (float) ($stat['sacks'] ?? 0),
                    'interceptions_def' => (int) ($stat['interceptions_def'] ?? 0),
                    'forced_fumbles' => (int) ($stat['forced_fumbles'] ?? 0),
                    'fg_attempts' => (int) ($stat['fg_attempts'] ?? 0),
                    'fg_made' => (int) ($stat['fg_made'] ?? 0),
                    'punt_yards' => (int) ($stat['punt_yards'] ?? 0),
                    'punt_returns' => (int) ($stat['punt_returns'] ?? 0),
                    'kick_returns' => (int) ($stat['kick_returns'] ?? 0),
                    'return_yards' => (int) ($stat['return_yards'] ?? 0),
                    'return_tds' => (int) ($stat['return_tds'] ?? 0),
                    'penalties' => (int) ($stat['penalties'] ?? 0),
                    'penalty_yards' => (int) ($stat['penalty_yards'] ?? 0),
                    'grade' => $stat['grade'],
                ];

                $this->gameStat->create($statRow);
            }

            // Save injuries — strip any extra keys not in the DB schema
            $injuryColumns = ['player_id', 'team_id', 'type', 'severity', 'weeks_remaining', 'game_id', 'occurred_at'];
            foreach ($result['injuries'] as $inj) {
                $inj['game_id'] = (int) $game['id'];
                $injRow = array_intersect_key($inj, array_flip($injuryColumns));
                $this->injury->create($injRow);
                $allInjuries[] = $inj;

                // Ticker item for in-game star injuries
                if (!empty($inj['in_game']) && !empty($inj['is_star'])) {
                    $injPlayer = $this->player->find((int) $inj['player_id']);
                    if ($injPlayer) {
                        $injName = $injPlayer['first_name'] . ' ' . $injPlayer['last_name'];
                        $injPos = $injPlayer['position'];
                        $injOvr = (int) $injPlayer['overall_rating'];
                        $injTeam = $this->team->find((int) $inj['team_id']);
                        $injAbbr = $injTeam['abbreviation'] ?? '???';
                        $sevLabel = match ($inj['severity']) {
                            'serious' => 'out ' . $inj['weeks_remaining'] . '+ weeks',
                            'out' => 'out for the game, week-to-week',
                            default => 'questionable to return',
                        };
                        try {
                            $this->db->prepare(
                                "INSERT INTO ticker_items (league_id, type, message, created_at) VALUES (?, 'injury', ?, ?)"
                            )->execute([
                                $leagueId,
                                "INJURY: {$injAbbr} {$injPos} {$injName} ({$injOvr} OVR) — {$inj['type']}, {$sevLabel}",
                                date('Y-m-d H:i:s'),
                            ]);
                        } catch (\Throwable $e) { /* non-critical */ }
                    }
                }
            }

            // Update team records
            $this->updateTeamRecord(
                (int) $game['home_team_id'],
                $result['home_score'],
                $result['away_score']
            );
            $this->updateTeamRecord(
                (int) $game['away_team_id'],
                $result['away_score'],
                $result['home_score']
            );

            $homeTeam = $this->team->find((int) $game['home_team_id']);
            $awayTeam = $this->team->find((int) $game['away_team_id']);

            $results[] = [
                'game_id' => (int) $game['id'],
                'home' => [
                    'team_id' => (int) $game['home_team_id'],
                    'name' => $homeTeam['city'] . ' ' . $homeTeam['name'],
                    'abbreviation' => $homeTeam['abbreviation'],
                    'score' => $result['home_score'],
                ],
                'away' => [
                    'team_id' => (int) $game['away_team_id'],
                    'name' => $awayTeam['city'] . ' ' . $awayTeam['name'],
                    'abbreviation' => $awayTeam['abbreviation'],
                    'score' => $result['away_score'],
                ],
                'turning_point' => $result['turning_point'],
                'injuries' => count($result['injuries']),
            ];
        }

        // Decrement existing injury weeks
        try {
            $this->injury->decrementWeeks($leagueId);
        } catch (\Throwable $e) {
            error_log("Injury decrementWeeks error: " . $e->getMessage());
        }

        // Trigger narrative engine if available
        $season = $this->league->getCurrentSeason($leagueId);
        $seasonId = $season ? (int) $season['id'] : 0;
        $this->generateNarratives($leagueId, $seasonId, $currentWeek, $weekGames, $results);

        // Phase 2 Narrative Layer: arcs, influence, morale, columnist content
        $this->processPhase2Narrative($leagueId, $currentWeek, $weekGames);

        // Milestone checks: playoff clinch, elimination, streaks, trade deadline
        $milestoneService = new \App\Services\MilestoneService();
        try {
            $milestones = $milestoneService->checkMilestones($leagueId, $currentWeek);
        } catch (\Throwable $e) {
            error_log("MilestoneService error: " . $e->getMessage());
            $milestones = [];
        }

        // Player milestone checks: game-level, season-level, and career-level
        $playerMilestones = [];
        try {
            // Game milestones — check each game's individual player stats
            foreach ($weekGames as $game) {
                $gameId = (int) $game['id'];
                $gameStatModel = new GameStat();
                $gameStatRows = $gameStatModel->getByGame($gameId);
                // Re-key by player_id for the milestone checker
                $statsByPlayer = [];
                foreach ($gameStatRows as $row) {
                    $statsByPlayer[(int) $row['player_id']] = $row;
                }
                if (!empty($statsByPlayer)) {
                    $gameMilestones = $milestoneService->checkGameMilestones($leagueId, $currentWeek, $gameId, $statsByPlayer);
                    $playerMilestones = array_merge($playerMilestones, $gameMilestones);
                }
            }

            // Season milestones — cumulative totals across all weeks this season
            $seasonMilestones = $milestoneService->checkWeeklyMilestones($leagueId, $currentWeek);
            $playerMilestones = array_merge($playerMilestones, $seasonMilestones);

            // Career milestones — historical_stats + current season totals
            $careerMilestones = $milestoneService->checkCareerMilestones($leagueId);
            $playerMilestones = array_merge($playerMilestones, $careerMilestones);
        } catch (\Throwable $e) {
            error_log("Player milestones error: " . $e->getMessage());
        }

        // In-season player development — growth, regression, and OVR recalculation
        $devResults = ['developed' => 0, 'regressed' => 0];
        try {
            $devEngine = new \App\Services\PlayerDevelopmentEngine();
            $devResults = $devEngine->processWeeklyDevelopment($leagueId, $currentWeek);
        } catch (\Throwable $e) {
            error_log("PlayerDevelopmentEngine error: " . $e->getMessage());
        }

        // Advance college season — prospect stocks rise and fall
        $draftHeadlines = [];
        try {
            $collegeEngine = new \App\Services\CollegeSeasonEngine();
            $collegeResult = $collegeEngine->advanceWeek($leagueId, $currentWeek);
            $draftHeadlines = $collegeResult['headlines'] ?? [];
        } catch (\Throwable $e) {
            error_log("CollegeSeasonEngine error: " . $e->getMessage());
        }

        // Mid-season free agent cleanup (after Week 6)
        $faCleanup = null;
        if ($currentWeek === 7) {
            try {
                $flowEngine = new \App\Services\OffseasonFlowEngine();
                $faCleanup = $flowEngine->cleanupUnsignedFreeAgents($leagueId, $currentWeek);
            } catch (\Throwable $e) {
                error_log("FA cleanup error: " . $e->getMessage());
            }
        }

        Response::json([
            'message' => "Week {$currentWeek} simulated successfully",
            'success' => true,
            'week' => $currentWeek,
            'games_simulated' => count($results),
            'results' => $results,
            'total_injuries' => count($allInjuries),
            'milestones' => $milestones,
            'player_milestones' => $playerMilestones,
            'draft_headlines' => $draftHeadlines,
            'fa_cleanup' => $faCleanup,
        ]);
    }

    /**
     * Update a team's win/loss record and point totals.
     */
    // processWeeklyDevelopment is now handled by PlayerDevelopmentEngine
    // (called at line 263 via $devEngine->processWeeklyDevelopment())

    private function updateTeamRecord(int $teamId, int $teamScore, int $opponentScore): void
    {
        $team = $this->team->find($teamId);
        if (!$team) return;

        $wins = (int) $team['wins'];
        $losses = (int) $team['losses'];
        $ties = (int) $team['ties'];
        $pointsFor = (int) $team['points_for'] + $teamScore;
        $pointsAgainst = (int) $team['points_against'] + $opponentScore;
        $streak = $team['streak'];

        if ($teamScore > $opponentScore) {
            $wins++;
            // Build streak string
            if (str_starts_with($streak, 'W')) {
                $count = (int) substr($streak, 1) + 1;
                $streak = "W{$count}";
            } else {
                $streak = 'W1';
            }
        } elseif ($teamScore < $opponentScore) {
            $losses++;
            if (str_starts_with($streak, 'L')) {
                $count = (int) substr($streak, 1) + 1;
                $streak = "L{$count}";
            } else {
                $streak = 'L1';
            }
        } else {
            $ties++;
            $streak = 'T1';
        }

        // Adjust morale based on result
        $morale = (int) $team['morale'];
        if ($teamScore > $opponentScore) {
            $morale = min(100, $morale + mt_rand(2, 5));
        } else {
            $morale = max(10, $morale - mt_rand(2, 5));
        }

        $this->team->update($teamId, [
            'wins' => $wins,
            'losses' => $losses,
            'ties' => $ties,
            'points_for' => $pointsFor,
            'points_against' => $pointsAgainst,
            'streak' => $streak,
            'morale' => $morale,
        ]);
    }

    /**
     * Attempt to generate narrative content (articles, social posts, ticker items).
     * Gracefully handles the case where NarrativeEngine does not exist yet.
     */
    private function generateNarratives(int $leagueId, int $seasonId, int $week, array $weekGames, array $results): void
    {
        if (!class_exists('App\\Services\\NarrativeEngine')) {
            return;
        }

        try {
            $engine = new \App\Services\NarrativeEngine();

            // Generate content for each individual game
            foreach ($weekGames as $i => $game) {
                // Re-fetch the game to get updated scores
                $freshGame = $this->game->find((int) $game['id']);
                if ($freshGame && $freshGame['is_simulated']) {
                    // Build the result data the narrative engine expects
                    $boxScore = json_decode($freshGame['box_score'] ?? '{}', true);
                    $gameResult = [
                        'home_score' => (int) $freshGame['home_score'],
                        'away_score' => (int) $freshGame['away_score'],
                        'turning_point' => $freshGame['turning_point'] ?? '',
                        'home_stats' => $boxScore['home']['stats'] ?? [],
                        'away_stats' => $boxScore['away']['stats'] ?? [],
                        'box_score' => $boxScore,
                        'game_log' => $boxScore['game_log'] ?? [],
                        'game_class' => $boxScore['game_class'] ?? [],
                    ];

                    $engine->generateGameContent($freshGame, $gameResult, $seasonId);

                    // Generate additional playoff narrative content for playoff games
                    $playoffTypes = ['wild_card', 'divisional', 'conference_championship', 'super_bowl', 'big_game'];
                    if (in_array($freshGame['game_type'] ?? '', $playoffTypes, true)) {
                        try {
                            $engine->generatePlayoffContent($leagueId, $seasonId, $week, $freshGame, $gameResult);
                        } catch (\Throwable $pe) {
                            error_log("NarrativeEngine playoff content error: " . $pe->getMessage());
                        }
                    }
                }
            }

            // Generate weekly summary content (power rankings, etc.)
            $engine->generateWeeklyContent($leagueId, $seasonId, $week);

            // Weekly column and morning blitz
            try {
                $engine->generateWeeklyColumn($leagueId, $seasonId, $week);
            } catch (\Throwable $e) {
                error_log("Column generation error: " . $e->getMessage());
            }

            try {
                $engine->generateMorningBlitz($leagueId, $seasonId, $week);
            } catch (\Throwable $e) {
                error_log("Morning Blitz error: " . $e->getMessage());
            }

            // Feature stories at key moments
            try {
                if ($week == 9 || $week == 10) {
                    $engine->generateFeatureStory($leagueId, $seasonId, $week, 'midseason_report', []);
                }
                if ($week >= 14 && $week <= 17) {
                    $engine->generateFeatureStory($leagueId, $seasonId, $week, 'playoff_race', []);
                }
                if ($week == 4 || $week == 8 || $week == 12) {
                    $engine->generateFeatureStory($leagueId, $seasonId, $week, 'rookie_watch', []);
                }
            } catch (\Throwable $e) {
                error_log("Feature story error: " . $e->getMessage());
            }
        } catch (\Throwable $e) {
            // Narrative generation is non-critical; log but do not fail
            error_log("NarrativeEngine error: " . $e->getMessage());
        }
    }

    /**
     * Run Phase 2 narrative layer processing after all games in a week are simulated.
     *
     * Calls:
     * - NarrativeArcTracker::processWeek()
     * - InfluenceEngine::processWeek() for each human coach
     * - MoraleEngine::processGameResult() for each team that played
     * - MoraleEngine::processPlayingTime() for each team
     * - ColumnistEngine::generateWeeklyGridironX()
     *
     * All calls are wrapped in try/catch so Phase 2 failures never break simulation.
     */
    private function processPhase2Narrative(int $leagueId, int $week, array $weekGames): void
    {
        // Resolve current season
        $season = $this->league->getCurrentSeason($leagueId);
        $seasonId = $season ? (int) $season['id'] : 0;

        // 1. Narrative Arc Tracker
        try {
            $arcTracker = new \App\Services\NarrativeArcTracker();
            $arcTracker->processWeek($leagueId, $seasonId, $week);
        } catch (\Throwable $e) {
            error_log("NarrativeArcTracker error: " . $e->getMessage());
        }

        // 2. Influence Engine — process each human coach
        try {
            $influenceEngine = new \App\Services\InfluenceEngine();
            $coachModel = new \App\Models\Coach();
            $humanCoach = $coachModel->getHumanByLeague($leagueId);
            if ($humanCoach) {
                $influenceEngine->processWeek($leagueId, (int) $humanCoach['id'], $week);
            }
        } catch (\Throwable $e) {
            error_log("InfluenceEngine error: " . $e->getMessage());
        }

        // 3. Morale Engine — process game results for each team that played
        try {
            $moraleEngine = new \App\Services\MoraleEngine();
            foreach ($weekGames as $game) {
                $homeScore = (int) ($game['home_score'] ?? 0);
                $awayScore = (int) ($game['away_score'] ?? 0);

                if ($homeScore === 0 && $awayScore === 0) {
                    // Game may not have scores stored on the $weekGames array,
                    // re-fetch from DB
                    $freshGame = $this->game->find((int) $game['id']);
                    if ($freshGame) {
                        $homeScore = (int) $freshGame['home_score'];
                        $awayScore = (int) $freshGame['away_score'];
                    }
                }

                $margin = abs($homeScore - $awayScore);
                $moraleEngine->processGameResult((int) $game['home_team_id'], $homeScore > $awayScore, $margin);
                $moraleEngine->processGameResult((int) $game['away_team_id'], $awayScore > $homeScore, $margin);
            }

            // Process playing time morale for all teams in the league
            $allTeams = $this->team->getByLeague($leagueId);
            foreach ($allTeams as $t) {
                $moraleEngine->processPlayingTime((int) $t['id']);
            }
        } catch (\Throwable $e) {
            error_log("MoraleEngine error: " . $e->getMessage());
        }

        // 4. Player Demand Engine — breakout demands, benched reactions, holdout threats
        try {
            $demandEngine = new \App\Services\PlayerDemandEngine();
            $demandEngine->processWeek($leagueId, $seasonId, $week);
        } catch (\Throwable $e) {
            error_log("PlayerDemandEngine error: " . $e->getMessage());
        }

        // 5. Columnist Engine — generate weekly GridironX posts
        try {
            $columnistEngine = new \App\Services\ColumnistEngine();
            $columnistEngine->generateWeeklyGridironX($leagueId, $seasonId, $week);
        } catch (\Throwable $e) {
            error_log("ColumnistEngine error: " . $e->getMessage());
        }

        // 5. Fantasy Football — score players, resolve matchups, AI actions
        try {
            $fantasyLeagueModel = new \App\Models\FantasyLeague();
            $fantasyEngine = new \App\Services\FantasyLeagueEngine();
            $fantasyLeagues = $fantasyLeagueModel->getByLeague($leagueId);

            foreach ($fantasyLeagues as $fl) {
                if (in_array($fl['status'], ['active', 'playoffs'])) {
                    $fantasyEngine->processWeek((int) $fl['id'], $week);
                }
            }
        } catch (\Throwable $e) {
            error_log("FantasyLeagueEngine error: " . $e->getMessage());
        }
    }

    /**
     * Before simulating, check every team playing this week has enough healthy
     * players at each position. If a position is short, the AI promotes backups
     * from the roster first, then signs the best available free agent —
     * just like a real NFL team would before game day.
     */
    private function ensureTeamsHaveQB(array $weekGames, int $leagueId): void
    {
        $db = \App\Database\Connection::getInstance()->getPdo();

        // Minimum starters required per position to field a team
        $positionMinimums = [
            'QB' => 1, 'RB' => 1, 'WR' => 2, 'TE' => 1,
            'OT' => 2, 'OG' => 2, 'C' => 1,
            'DE' => 2, 'DT' => 1, 'LB' => 2, 'CB' => 2, 'S' => 1,
            'K' => 1, 'P' => 1,
        ];

        // Collect all team IDs playing this week
        $teamIds = [];
        foreach ($weekGames as $game) {
            $teamIds[] = (int) $game['home_team_id'];
            $teamIds[] = (int) $game['away_team_id'];
        }
        $teamIds = array_unique($teamIds);

        // Get team info once
        $teamInfoCache = [];
        foreach ($teamIds as $tid) {
            $ts = $db->prepare("SELECT city, name, abbreviation FROM teams WHERE id = ?");
            $ts->execute([$tid]);
            $teamInfoCache[$tid] = $ts->fetch();
        }

        $now = date('Y-m-d H:i:s');

        foreach ($teamIds as $teamId) {
            // Get all injured player IDs for this team
            $injStmt = $db->prepare(
                "SELECT player_id FROM injuries WHERE team_id = ? AND weeks_remaining > 0"
            );
            $injStmt->execute([$teamId]);
            $injuredIds = $injStmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];

            foreach ($positionMinimums as $pos => $needed) {
                // Count healthy players at this position on the roster
                $healthyStmt = $db->prepare(
                    "SELECT COUNT(*) FROM players
                     WHERE team_id = ? AND position = ? AND status = 'active'
                     AND id NOT IN (
                         SELECT player_id FROM injuries WHERE team_id = ? AND weeks_remaining > 0
                     )"
                );
                $healthyStmt->execute([$teamId, $pos, $teamId]);
                $healthyCount = (int) $healthyStmt->fetchColumn();

                if ($healthyCount >= $needed) {
                    continue;
                }

                $deficit = $needed - $healthyCount;
                $team = $teamInfoCache[$teamId] ?? [];
                $teamAbbr = $team['abbreviation'] ?? '???';
                $teamName = trim(($team['city'] ?? '') . ' ' . ($team['name'] ?? ''));

                // Sign free agents to fill the gap
                for ($i = 0; $i < $deficit; $i++) {
                    $faStmt = $db->prepare(
                        "SELECT id, first_name, last_name, overall_rating FROM players
                         WHERE team_id IS NULL AND position = ?
                         AND (status = 'free_agent' OR status IS NULL)
                         ORDER BY overall_rating DESC LIMIT 1"
                    );
                    $faStmt->execute([$pos]);
                    $bestFA = $faStmt->fetch();

                    if (!$bestFA) {
                        error_log("No free agent {$pos} available for {$teamName}");
                        break;
                    }

                    $faPlayerId = (int) $bestFA['id'];
                    $faName = $bestFA['first_name'] . ' ' . $bestFA['last_name'];
                    $faOvr = (int) $bestFA['overall_rating'];

                    // Sign: assign to team
                    $db->prepare("UPDATE players SET team_id = ?, status = 'active' WHERE id = ?")
                        ->execute([$teamId, $faPlayerId]);

                    // League minimum emergency contract
                    $salary = 1000000;
                    $db->prepare(
                        "INSERT INTO contracts (player_id, team_id, salary_annual, cap_hit, years_total,
                         years_remaining, guaranteed, dead_cap, signing_bonus, base_salary, total_value,
                         contract_type, status, signed_at)
                         VALUES (?, ?, ?, ?, 1, 1, 0, 0, 0, ?, ?, 'emergency', 'active', ?)"
                    )->execute([
                        $faPlayerId, $teamId, $salary, $salary, $salary, $salary, $now,
                    ]);

                    // Add to depth chart — find the next open slot for this position
                    $slotStmt = $db->prepare(
                        "SELECT COALESCE(MAX(slot), 0) + 1 FROM depth_chart
                         WHERE team_id = ? AND position_group = ?"
                    );
                    $slotStmt->execute([$teamId, $pos]);
                    $nextSlot = (int) $slotStmt->fetchColumn();

                    // If all starters at this position are injured, put the signing at slot 1
                    if ($healthyCount === 0 && $i === 0) {
                        // Push existing slots down
                        $db->prepare(
                            "UPDATE depth_chart SET slot = slot + 1
                             WHERE team_id = ? AND position_group = ?"
                        )->execute([$teamId, $pos]);
                        $nextSlot = 1;
                    }

                    $db->prepare(
                        "INSERT OR REPLACE INTO depth_chart (team_id, position_group, slot, player_id)
                         VALUES (?, ?, ?, ?)"
                    )->execute([$teamId, $pos, $nextSlot, $faPlayerId]);

                    // Ticker item
                    try {
                        $db->prepare(
                            "INSERT INTO ticker_items (league_id, type, message, created_at)
                             VALUES (?, 'roster', ?, ?)"
                        )->execute([
                            $leagueId,
                            "EMERGENCY SIGNING: {$teamAbbr} signs {$pos} {$faName} ({$faOvr} OVR) — roster need at {$pos}",
                            $now,
                        ]);
                    } catch (\Throwable $e) {
                        // Non-critical
                    }

                    error_log("Emergency signing: {$teamName} signed {$pos} {$faName} ({$faOvr} OVR)");
                }
            }
        }
    }
}
