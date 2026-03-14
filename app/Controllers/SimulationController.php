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
                    'grade' => $stat['grade'],
                ];

                $this->gameStat->create($statRow);
            }

            // Save injuries
            foreach ($result['injuries'] as $inj) {
                $inj['game_id'] = (int) $game['id'];
                $this->injury->create($inj);
                $allInjuries[] = $inj;
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

        Response::json([
            'message' => "Week {$currentWeek} simulated successfully",
            'success' => true,
            'week' => $currentWeek,
            'games_simulated' => count($results),
            'results' => $results,
            'total_injuries' => count($allInjuries),
        ]);
    }

    /**
     * Update a team's win/loss record and point totals.
     */
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
                    $gameResult = [
                        'home_score' => (int) $freshGame['home_score'],
                        'away_score' => (int) $freshGame['away_score'],
                        'turning_point' => $freshGame['turning_point'] ?? '',
                        'home_stats' => $results[$i]['home']['score'] ?? [],
                        'away_stats' => $results[$i]['away']['score'] ?? [],
                    ];

                    // Use the box_score stats if stored
                    $boxScore = json_decode($freshGame['box_score'] ?? '{}', true);
                    if (!empty($boxScore)) {
                        $gameResult['home_stats'] = $boxScore['home']['stats'] ?? [];
                        $gameResult['away_stats'] = $boxScore['away']['stats'] ?? [];
                    }

                    $engine->generateGameContent($freshGame, $gameResult, $seasonId);
                }
            }

            // Generate weekly summary content (power rankings, etc.)
            $engine->generateWeeklyContent($leagueId, $seasonId, $week);
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

        // 4. Columnist Engine — generate weekly GridironX posts
        try {
            $columnistEngine = new \App\Services\ColumnistEngine();
            $columnistEngine->generateWeeklyGridironX($leagueId, $seasonId, $week);
        } catch (\Throwable $e) {
            error_log("ColumnistEngine error: " . $e->getMessage());
        }
    }
}
