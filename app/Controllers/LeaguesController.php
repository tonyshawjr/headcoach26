<?php

namespace App\Controllers;

use App\Helpers\Response;
use App\Middleware\AuthMiddleware;
use App\Models\League;
use App\Models\Team;
use App\Models\Coach;
use App\Models\Season;
use App\Models\User;
use App\Services\ScheduleGenerator;
use App\Services\PlayerGenerator;
use App\Services\SimEngine;
use App\Services\PlayoffEngine;
use App\Models\Game;
use App\Models\GameStat;
use App\Models\Injury;

class LeaguesController
{
    private League $league;
    private Team $team;
    private Coach $coach;
    private Season $season;
    private User $user;

    public function __construct()
    {
        $this->league = new League();
        $this->team = new Team();
        $this->coach = new Coach();
        $this->season = new Season();
        $this->user = new User();
    }

    /**
     * GET /api/leagues
     * List leagues for the current user.
     */
    public function index(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        // Get leagues where the user has a coach record
        $coaches = $this->coach->all(['user_id' => $auth['user_id']]);
        $leagueIds = array_unique(array_column($coaches, 'league_id'));

        $leagues = [];
        foreach ($leagueIds as $leagueId) {
            $league = $this->league->find((int) $leagueId);
            if ($league) {
                $league['team_count'] = $this->team->count(['league_id' => $leagueId]);
                $leagues[] = $league;
            }
        }

        Response::json(['leagues' => $leagues]);
    }

    /**
     * POST /api/leagues
     * Create a new league (admin only).
     */
    public function create(array $params): void
    {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;

        $body = Response::getJsonBody();
        $name = trim($body['name'] ?? '');

        if ($name === '') {
            Response::error('League name is required');
            return;
        }

        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
        $slug = trim($slug, '-');

        // Ensure slug uniqueness
        $existing = $this->league->findBySlug($slug);
        if ($existing) {
            $slug .= '-' . time();
        }

        $settings = json_encode([
            'quarter_length' => $body['quarter_length'] ?? 15,
            'injury_frequency' => $body['injury_frequency'] ?? 'normal',
            'trade_difficulty' => $body['trade_difficulty'] ?? 'normal',
            'salary_cap_enabled' => $body['salary_cap_enabled'] ?? true,
            'max_teams' => $body['max_teams'] ?? 32,
            'sim_speed' => $body['sim_speed'] ?? 'normal',
        ]);

        $leagueId = $this->league->create([
            'name' => $name,
            'slug' => $slug,
            'season_year' => (int) ($body['season_year'] ?? 2026),
            'current_week' => 0,
            'phase' => 'preseason',
            'commissioner_id' => $auth['user_id'],
            'settings' => $settings,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // Create the initial season record
        $seasonId = $this->season->create([
            'league_id' => $leagueId,
            'year' => (int) ($body['season_year'] ?? 2026),
            'is_current' => 1,
            'champion_team_id' => null,
            'mvp_player_id' => null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $league = $this->league->find($leagueId);

        Response::json([
            'message' => 'League created',
            'league' => $league,
            'season_id' => $seasonId,
        ], 201);
    }

    /**
     * GET /api/leagues/{id}
     * League detail with settings.
     */
    public function show(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $league = $this->league->find((int) $params['id']);
        if (!$league) {
            Response::notFound('League not found');
            return;
        }

        $league['settings'] = json_decode($league['settings'] ?? '{}', true);
        $league['team_count'] = $this->team->count(['league_id' => $league['id']]);

        $season = $this->league->getCurrentSeason((int) $league['id']);

        Response::json([
            'league' => $league,
            'season' => $season,
        ]);
    }

    /**
     * PUT /api/leagues/{id}
     * Update league settings.
     */
    public function update(array $params): void
    {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;

        $league = $this->league->find((int) $params['id']);
        if (!$league) {
            Response::notFound('League not found');
            return;
        }

        $body = Response::getJsonBody();
        $data = [];

        if (isset($body['name'])) {
            $data['name'] = trim($body['name']);
        }

        if (isset($body['settings']) && is_array($body['settings'])) {
            $currentSettings = json_decode($league['settings'] ?? '{}', true);
            $data['settings'] = json_encode(array_merge($currentSettings, $body['settings']));
        }

        if (isset($body['phase'])) {
            $validPhases = ['preseason', 'regular', 'playoffs', 'offseason'];
            if (in_array($body['phase'], $validPhases, true)) {
                $data['phase'] = $body['phase'];
            }
        }

        if (empty($data)) {
            Response::error('No valid fields to update');
            return;
        }

        $data['updated_at'] = date('Y-m-d H:i:s');
        $this->league->update((int) $params['id'], $data);

        $updated = $this->league->find((int) $params['id']);
        $updated['settings'] = json_decode($updated['settings'] ?? '{}', true);

        Response::json(['league' => $updated]);
    }

    /**
     * POST /api/leagues/{id}/join
     * Join a league with an invite code. Assigns user to an available team.
     */
    public function join(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $body = Response::getJsonBody();
        $inviteCode = trim($body['invite_code'] ?? '');
        $teamId = (int) ($body['team_id'] ?? 0);
        $coachName = trim($body['coach_name'] ?? '');

        $league = $this->league->find((int) $params['id']);
        if (!$league) {
            Response::notFound('League not found');
            return;
        }

        // Validate invite code matches the league slug
        $settings = json_decode($league['settings'] ?? '{}', true);
        $expectedCode = $settings['invite_code'] ?? $league['slug'];
        if ($inviteCode !== '' && $inviteCode !== $expectedCode) {
            Response::error('Invalid invite code', 403);
            return;
        }

        // Check that the user is not already in this league
        $existingCoaches = $this->coach->all([
            'user_id' => $auth['user_id'],
            'league_id' => $league['id'],
        ]);
        if (!empty($existingCoaches)) {
            Response::error('You are already in this league');
            return;
        }

        // Validate team is available (no human coach assigned)
        if ($teamId > 0) {
            $team = $this->team->find($teamId);
            if (!$team || (int) $team['league_id'] !== (int) $league['id']) {
                Response::error('Invalid team selection');
                return;
            }

            $assignedCoaches = $this->coach->all([
                'team_id' => $teamId,
                'is_human' => 1,
            ]);
            if (!empty($assignedCoaches)) {
                Response::error('Team is already taken');
                return;
            }
        }

        // Create coach record
        if ($coachName === '') {
            $user = $this->user->find((int) $auth['user_id']);
            $coachName = $user ? $user['username'] : 'Coach';
        }

        $coachId = $this->coach->create([
            'league_id' => $league['id'],
            'team_id' => $teamId > 0 ? $teamId : null,
            'user_id' => $auth['user_id'],
            'name' => $coachName,
            'is_human' => 1,
            'archetype' => $body['archetype'] ?? 'balanced',
            'influence' => 50,
            'job_security' => 70,
            'media_rating' => 50,
            'contract_years' => 3,
            'contract_salary' => 5000000,
            'personality' => null,
            'owner_expectations' => null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // Update session
        $_SESSION['coach_id'] = $coachId;
        $_SESSION['league_id'] = $league['id'];
        $_SESSION['team_id'] = $teamId > 0 ? $teamId : null;

        Response::json([
            'message' => 'Joined league',
            'coach_id' => $coachId,
            'league' => $league,
        ], 201);
    }

    /**
     * POST /api/leagues/{id}/advance
     * Advance the league to the next week or phase.
     */
    public function advance(array $params): void
    {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;

        $league = $this->league->find((int) $params['id']);
        if (!$league) {
            Response::notFound('League not found');
            return;
        }

        // Check if league is paused
        $commissionerService = new \App\Services\CommissionerService();
        if ($commissionerService->isLeaguePaused((int) $params['id'])) {
            Response::error('League is paused by commissioner.');
            return;
        }

        $currentWeek = (int) $league['current_week'];
        $phase = $league['phase'];
        $newWeek = $currentWeek;
        $newPhase = $phase;

        // Auto-simulate any unsimulated games for the current week before advancing
        if ($currentWeek >= 1 && in_array($phase, ['regular', 'playoffs'])) {
            $this->autoSimulateWeek((int) $params['id'], $currentWeek);
        }

        switch ($phase) {
            case 'preseason':
                // Cap compliance check — can't start the season over the cap (if cap is enabled)
                $leagueId = (int) $params['id'];
                $pdo = \App\Database\Connection::getInstance()->getPdo();

                $commishService = new \App\Services\CommissionerService();
                $commSettings = $commishService->getSettings($leagueId);
                $capEnabled = (int) ($commSettings['salary_cap_enabled'] ?? 1);

                if ($capEnabled) {
                    $userCoach = $pdo->prepare("SELECT team_id FROM coaches WHERE league_id = ? AND user_id = ?");
                    $userCoach->execute([$leagueId, $auth['user_id']]);
                    $userTeamId = (int) ($userCoach->fetchColumn() ?: 0);

                    if ($userTeamId) {
                        $teamRow = $pdo->prepare("SELECT salary_cap, cap_used FROM teams WHERE id = ?");
                        $teamRow->execute([$userTeamId]);
                        $teamCap = $teamRow->fetch();
                        if ($teamCap) {
                            $cap = (int) $teamCap['salary_cap'];
                            $used = (int) $teamCap['cap_used'];
                            if ($used > $cap) {
                                $over = $used - $cap;
                                Response::error(
                                    "You're $" . number_format($over / 1000000, 1) . "M over the salary cap ($" .
                                    number_format($used / 1000000, 1) . "M committed vs $" .
                                    number_format($cap / 1000000, 1) . "M cap). Cut players, restructure contracts, or make trades to get under the cap before starting the season."
                                );
                                return;
                            }
                        }
                    }
                }

                // Generate draft prospects if they don't exist yet
                try {
                    $draftEngine = new \App\Services\DraftEngine();
                    $classId = $draftEngine->getCurrentClassId($leagueId);
                    if ($classId) {
                        $prospectCount = (int) $pdo->query("SELECT COUNT(*) FROM draft_prospects WHERE draft_class_id = {$classId}")->fetchColumn();
                        if ($prospectCount === 0) {
                            $draftEngine->generateProspectsForClass($classId);
                        }
                    }
                } catch (\Throwable $e) {
                    error_log("Draft prospect generation error: " . $e->getMessage());
                }

                // Move to regular season week 1
                $newPhase = 'regular';
                $newWeek = 1;
                break;

            case 'regular':
                // Dynamically detect last regular season week
                $lastRegWeek = $this->getLastRegularSeasonWeek((int) $params['id']);
                if ($currentWeek >= $lastRegWeek) {
                    // Move to playoffs — generate Wild Card bracket
                    $newPhase = 'playoffs';
                    $newWeek = $lastRegWeek + 1;
                    try {
                        $playoffEngine = new PlayoffEngine();
                        $season = $this->league->getCurrentSeason((int) $params['id']);
                        $seasonId = $season ? (int) $season['id'] : 0;
                        $playoffEngine->generatePlayoffBracket((int) $params['id'], $seasonId);
                    } catch (\Throwable $e) {
                        error_log("PlayoffEngine bracket generation error: " . $e->getMessage());
                    }
                } else {
                    $newWeek = $currentWeek + 1;
                }
                break;

            case 'playoffs':
                // Check if playoffs are complete (Big Game played)
                try {
                    $playoffEngine = new PlayoffEngine();
                    if ($playoffEngine->isPlayoffsComplete((int) $params['id'])) {
                        $newPhase = 'offseason';
                        $newWeek = $currentWeek + 1;
                    } else {
                        // Advance to next playoff round
                        $newWeek = $currentWeek + 1;
                        $playoffEngine->advancePlayoffRound((int) $params['id']);
                    }
                } catch (\Throwable $e) {
                    error_log("PlayoffEngine advance error: " . $e->getMessage());
                    $newWeek = $currentWeek + 1;
                }
                break;

            case 'offseason':
                // Use the phased OffseasonFlowEngine
                $flowEngine = new \App\Services\OffseasonFlowEngine();
                $offseasonResult = $flowEngine->advancePhase((int) $params['id']);

                if (!empty($offseasonResult['done'])) {
                    // Offseason complete -- flow engine already set phase to preseason
                    $updated = $this->league->find((int) $params['id']);
                    $updated['settings'] = json_decode($updated['settings'] ?? '{}', true);
                    Response::json([
                        'message' => 'Offseason complete! New season ready.',
                        'success' => true,
                        'week' => (int) $updated['current_week'],
                        'phase' => $updated['phase'],
                        'league' => $updated,
                        'offseason' => $offseasonResult,
                    ]);
                    return;
                }

                // Generate weekly draft scout coverage during offseason
                if (class_exists('App\\Services\\DraftScoutEngine')) {
                    try {
                        $db = \App\Database\Connection::getInstance()->getPdo();
                        $draftClassStmt = $db->prepare(
                            "SELECT id FROM draft_classes WHERE league_id = ? ORDER BY year DESC LIMIT 1"
                        );
                        $draftClassStmt->execute([(int) $params['id']]);
                        $draftClassRow = $draftClassStmt->fetch();
                        if ($draftClassRow) {
                            $seasonStmt = $db->prepare(
                                "SELECT id FROM seasons WHERE league_id = ? AND is_current = 1 LIMIT 1"
                            );
                            $seasonStmt->execute([(int) $params['id']]);
                            $seasonRow = $seasonStmt->fetch();
                            $offseasonSeasonId = $seasonRow ? (int) $seasonRow['id'] : 0;

                            $leagueStmt = $db->prepare("SELECT current_week FROM leagues WHERE id = ?");
                            $leagueStmt->execute([(int) $params['id']]);
                            $offseasonWeek = (int) ($leagueStmt->fetchColumn() ?: 0);

                            $draftScout = new \App\Services\DraftScoutEngine();
                            $draftScout->generateWeeklyDraftUpdate(
                                (int) $params['id'],
                                $offseasonSeasonId,
                                $offseasonWeek,
                                (int) $draftClassRow['id']
                            );
                        }
                    } catch (\Throwable $e) {
                        error_log("DraftScout weekly error: " . $e->getMessage());
                    }
                }

                // Still in offseason -- return phase results without changing phase/week
                $updated = $this->league->find((int) $params['id']);
                $updated['settings'] = json_decode($updated['settings'] ?? '{}', true);
                Response::json([
                    'message' => 'Advanced to offseason phase: ' . ($offseasonResult['phase'] ?? 'unknown'),
                    'success' => true,
                    'week' => (int) $updated['current_week'],
                    'phase' => 'offseason',
                    'league' => $updated,
                    'offseason' => $offseasonResult,
                ]);
                return;
        }

        $this->league->update((int) $params['id'], [
            'current_week' => $newWeek,
            'phase' => $newPhase,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $scheduleGenerated = 0;
        if ($newPhase === 'preseason') {
            $scheduleGenerated = $this->ensureScheduleExists((int) $params['id']);
        }

        // Decrement injury weeks BEFORE auto-simming so recovered players can play
        if ($newWeek >= 1 && in_array($newPhase, ['regular', 'playoffs'])) {
            try {
                $injuryModel = new Injury();
                $injuryModel->decrementWeeks((int) $params['id']);
            } catch (\Throwable $e) {
                error_log("Advance injury decrement error: " . $e->getMessage());
            }
        }

        // Auto-sim all OTHER teams' games for the new week (user's game stays unsimulated)
        $autoSimCount = 0;
        $userTeamId = $auth['team_id'] ?? null;
        if ($newWeek >= 1 && in_array($newPhase, ['regular', 'playoffs']) && $userTeamId) {
            $autoSimCount = $this->autoSimulateOtherGames((int) $params['id'], $newWeek, (int) $userTeamId);
        }

        // Run weekly league activity (AI trades, IR moves, free agency)
        $weeklyActivity = [];
        if ($newWeek >= 1 && in_array($newPhase, ['regular', 'playoffs'])) {
            try {
                $weeklyEngine = new \App\Services\WeeklyLeagueEngine();
                $weeklyActivity = $weeklyEngine->processWeek((int) $params['id'], $newWeek);
            } catch (\Throwable $e) {
                error_log("WeeklyLeagueEngine error for league {$params['id']} week {$newWeek}: " . $e->getMessage());
            }
        }

        $updated = $this->league->find((int) $params['id']);
        $updated['settings'] = json_decode($updated['settings'] ?? '{}', true);

        $responseData = [
            'message' => "Advanced to {$newPhase} week {$newWeek}",
            'success' => true,
            'week' => $newWeek,
            'phase' => $newPhase,
            'league' => $updated,
        ];
        if ($scheduleGenerated > 0) {
            $responseData['schedule_generated'] = $scheduleGenerated;
        }
        if ($autoSimCount > 0) {
            $responseData['auto_simulated'] = $autoSimCount;
        }
        if (!empty($weeklyActivity)) {
            $responseData['weekly_activity'] = $weeklyActivity;
        }

        Response::json($responseData);
    }

    /**
     * Auto-simulate all unsimulated games for a given week.
     * Called before advancing to the next week so no games are left unplayed.
     */
    private function autoSimulateWeek(int $leagueId, int $week): void
    {
        try {
            $gameModel = new Game();
            $gameStatModel = new GameStat();
            $teamModel = new Team();
            $injuryModel = new Injury();

            $weekGames = $gameModel->query(
                "SELECT * FROM games WHERE league_id = ? AND week = ? AND is_simulated = 0",
                [$leagueId, $week]
            );

            if (empty($weekGames)) return;

            $simEngine = new SimEngine();

            foreach ($weekGames as $game) {
                $result = $simEngine->simulateGame($game);

                $gameModel->update((int) $game['id'], [
                    'home_score' => $result['home_score'],
                    'away_score' => $result['away_score'],
                    'is_simulated' => 1,
                    'box_score' => json_encode($result['box_score']),
                    'turning_point' => $result['turning_point'],
                    'player_grades' => json_encode($result['grades']),
                    'simulated_at' => date('Y-m-d H:i:s'),
                ]);

                $this->saveSimulationStats($result, (int) $game['id'], $gameStatModel, $injuryModel);

                $this->updateTeamRecordForAutoSim($teamModel, (int) $game['home_team_id'], $result['home_score'], $result['away_score']);
                $this->updateTeamRecordForAutoSim($teamModel, (int) $game['away_team_id'], $result['away_score'], $result['home_score']);
            }

            // Decrement existing injury weeks
            try {
                $injuryModel->decrementWeeks($leagueId);
            } catch (\Throwable $e) {
                error_log("Auto-sim injury decrementWeeks error: " . $e->getMessage());
            }
        } catch (\Throwable $e) {
            error_log("Auto-sim error for league {$leagueId} week {$week}: " . $e->getMessage());
        }
    }

    /**
     * Save player stats and injuries from a simulation result.
     * Shared by autoSimulateWeek and autoSimulateOtherGames.
     */
    private function saveSimulationStats(array $result, int $gameId, GameStat $gameStatModel, Injury $injuryModel): void
    {
        $allPlayerStats = array_merge($result['home_stats'], $result['away_stats']);
        foreach ($allPlayerStats as $playerId => $stat) {
            $stat['game_id'] = $gameId;
            $stat['grade'] = $result['grades'][$playerId] ?? null;

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

            $gameStatModel->create($statRow);
        }

        // Save injuries — strip extra keys not in DB schema
        $injuryColumns = ['player_id', 'team_id', 'type', 'severity', 'weeks_remaining', 'game_id', 'occurred_at'];
        foreach ($result['injuries'] as $inj) {
            $inj['game_id'] = $gameId;
            $injRow = array_intersect_key($inj, array_flip($injuryColumns));
            $injuryModel->create($injRow);
        }
    }

    /**
     * Update a team's win/loss record (used by autoSimulateWeek).
     */
    private function updateTeamRecordForAutoSim(Team $teamModel, int $teamId, int $teamScore, int $opponentScore): void
    {
        $team = $teamModel->find($teamId);
        if (!$team) return;

        $wins = (int) $team['wins'];
        $losses = (int) $team['losses'];
        $ties = (int) $team['ties'];
        $pointsFor = (int) $team['points_for'] + $teamScore;
        $pointsAgainst = (int) $team['points_against'] + $opponentScore;
        $streak = $team['streak'] ?? '';

        if ($teamScore > $opponentScore) {
            $wins++;
            $streak = str_starts_with($streak, 'W') ? 'W' . ((int) substr($streak, 1) + 1) : 'W1';
        } elseif ($teamScore < $opponentScore) {
            $losses++;
            $streak = str_starts_with($streak, 'L') ? 'L' . ((int) substr($streak, 1) + 1) : 'L1';
        } else {
            $ties++;
            $streak = 'T1';
        }

        $morale = (int) $team['morale'];
        $morale = $teamScore > $opponentScore
            ? min(100, $morale + mt_rand(2, 5))
            : max(10, $morale - mt_rand(2, 5));

        $teamModel->update($teamId, [
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
     * Auto-simulate all games for a week EXCEPT those involving the user's team.
     * Called after advancing so the user only needs to sim their own game.
     */
    private function autoSimulateOtherGames(int $leagueId, int $week, int $userTeamId): int
    {
        try {
            $gameModel = new Game();
            $gameStatModel = new GameStat();
            $teamModel = new Team();
            $injuryModel = new Injury();

            // Get unsimulated games that do NOT involve the user's team
            $weekGames = $gameModel->query(
                "SELECT * FROM games WHERE league_id = ? AND week = ? AND is_simulated = 0 AND home_team_id != ? AND away_team_id != ?",
                [$leagueId, $week, $userTeamId, $userTeamId]
            );

            if (empty($weekGames)) return 0;

            $simEngine = new SimEngine();
            $simmedCount = 0;

            foreach ($weekGames as $game) {
                $result = $simEngine->simulateGame($game);

                $gameModel->update((int) $game['id'], [
                    'home_score' => $result['home_score'],
                    'away_score' => $result['away_score'],
                    'is_simulated' => 1,
                    'box_score' => json_encode($result['box_score']),
                    'turning_point' => $result['turning_point'],
                    'player_grades' => json_encode($result['grades']),
                    'simulated_at' => date('Y-m-d H:i:s'),
                ]);

                $this->saveSimulationStats($result, (int) $game['id'], $gameStatModel, $injuryModel);

                $this->updateTeamRecordForAutoSim($teamModel, (int) $game['home_team_id'], $result['home_score'], $result['away_score']);
                $this->updateTeamRecordForAutoSim($teamModel, (int) $game['away_team_id'], $result['away_score'], $result['home_score']);

                $simmedCount++;
            }

            // Generate narratives for auto-simmed games
            $season = $this->league->getCurrentSeason($leagueId);
            $seasonId = $season ? (int) $season['id'] : 0;
            if (class_exists('App\\Services\\NarrativeEngine')) {
                try {
                    $engine = new \App\Services\NarrativeEngine();
                    $engine->generateWeeklyContent($leagueId, $seasonId, $week);
                } catch (\Throwable $e) {
                    error_log("Auto-sim narrative error: " . $e->getMessage());
                }
            }

            return $simmedCount;
        } catch (\Throwable $e) {
            error_log("Auto-sim other games error for league {$leagueId} week {$week}: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * GET /api/leagues/{id}/playoff-bracket
     * Return the full playoff bracket with all rounds.
     */
    public function playoffBracket(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $league = $this->league->find((int) $params['id']);
        if (!$league) {
            Response::notFound('League not found');
            return;
        }

        $playoffEngine = new PlayoffEngine();
        $bracket = $playoffEngine->getPlayoffBracket((int) $params['id']);

        Response::json($bracket);
    }

    /**
     * GET /api/leagues/{id}/playoff-seeding
     * Return playoff seeding for both conferences.
     */
    public function playoffSeeding(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $league = $this->league->find((int) $params['id']);
        if (!$league) {
            Response::notFound('League not found');
            return;
        }

        $playoffEngine = new PlayoffEngine();
        $seeding = $playoffEngine->calculatePlayoffSeeding((int) $params['id']);

        Response::json($seeding);
    }

    /**
     * Get the last regular season week for a league (dynamically).
     */
    private function getLastRegularSeasonWeek(int $leagueId): int
    {
        $season = $this->league->getCurrentSeason($leagueId);
        if (!$season) return 18;

        $gameModel = new Game();
        $result = $gameModel->query(
            "SELECT MAX(week) AS max_week FROM games
             WHERE league_id = ? AND season_id = ? AND game_type = 'regular'",
            [$leagueId, (int) $season['id']]
        );

        return (int) ($result[0]['max_week'] ?? 18);
    }

    // ── Ready Check & Advance System ─────────────────────────

    /**
     * GET /api/leagues/{id}/ready-status
     */
    public function readyStatus(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $leagueId = (int) $params['id'];
        $league = $this->league->find($leagueId);
        if (!$league) { Response::notFound('League not found'); return; }

        $engine = new \App\Services\LeagueAdvanceEngine();
        $status = $engine->getReadyStatus($leagueId, (int) $league['current_week']);

        Response::json($status);
    }

    /**
     * POST /api/leagues/{id}/ready
     * Mark current user's coach and/or fantasy manager as ready.
     */
    public function markReady(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $leagueId = (int) $params['id'];
        $league = $this->league->find($leagueId);
        if (!$league) { Response::notFound('League not found'); return; }

        $week = (int) $league['current_week'];
        $engine = new \App\Services\LeagueAdvanceEngine();

        // Mark coach as ready
        if ($auth['coach_id']) {
            $engine->setCoachReady($leagueId, (int) $auth['coach_id'], $week);
        }

        // Mark any fantasy managers for this user as ready
        $db = \App\Database\Connection::getInstance()->getPdo();
        $fantasyManagers = $db->prepare(
            "SELECT fm.id FROM fantasy_managers fm
             JOIN fantasy_leagues fl ON fl.id = fm.fantasy_league_id
             WHERE fl.league_id = ? AND fm.coach_id = ? AND fm.is_ai = 0
             AND fl.status IN ('active', 'playoffs')"
        );
        $fantasyManagers->execute([$leagueId, $auth['coach_id']]);
        foreach ($fantasyManagers->fetchAll(\PDO::FETCH_ASSOC) as $fm) {
            $engine->setFantasyReady($leagueId, (int) $fm['id'], $week);
        }

        // Check if everyone is ready — auto-advance if so
        $advanceResult = $engine->checkAndAdvance($leagueId);

        $status = $engine->getReadyStatus($leagueId, $week);
        $status['auto_advanced'] = $advanceResult['advanced'] ?? false;
        $status['advance_reason'] = $advanceResult['reason'] ?? null;

        Response::json($status);
    }

    /**
     * POST /api/leagues/{id}/force-advance
     * Commissioner force-advances the week.
     */
    public function forceAdvance(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        // Check if user is commissioner
        if (!($auth['is_admin'] ?? false)) {
            Response::error('Only the commissioner can force advance', 403);
            return;
        }

        $engine = new \App\Services\LeagueAdvanceEngine();
        $result = $engine->forceAdvance((int) $params['id']);

        Response::json($result);
    }

    /**
     * GET /api/leagues/{id}/advance-settings
     */
    public function advanceSettings(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $engine = new \App\Services\LeagueAdvanceEngine();
        $settings = $engine->getSettings((int) $params['id']);
        Response::json($settings);
    }

    /**
     * PUT /api/leagues/{id}/advance-settings
     */
    public function updateAdvanceSettings(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        if (!($auth['is_admin'] ?? false)) {
            Response::error('Only the commissioner can change advance settings', 403);
            return;
        }

        $body = Response::getJsonBody();
        $engine = new \App\Services\LeagueAdvanceEngine();
        $engine->updateSettings((int) $params['id'], $body);
        Response::success('Settings updated');
    }

    /**
     * POST /api/cron/advance
     * Called by external cron job. No auth required (should be secured by cron secret or IP).
     */
    public function cronAdvance(): void
    {
        $engine = new \App\Services\LeagueAdvanceEngine();
        $result = $engine->cronAdvanceAll();
        Response::json($result);
    }

    private function ensureScheduleExists(int $leagueId): int
    {
        $seasonModel = new Season();
        $currentSeason = $this->league->getCurrentSeason($leagueId);
        if (!$currentSeason) {
            return 0;
        }

        $seasonId = (int) $currentSeason['id'];

        $gameModel = new Game();
        $existing = $gameModel->query(
            "SELECT COUNT(*) as cnt FROM games WHERE league_id = ? AND season_id = ?",
            [$leagueId, $seasonId]
        );
        $gameCount = (int) ($existing[0]['cnt'] ?? 0);

        if ($gameCount > 0) {
            return 0;
        }

        $teamModel = new Team();
        $teams = $teamModel->query(
            "SELECT id, city, name, abbreviation, conference, division FROM teams WHERE league_id = ?",
            [$leagueId]
        );

        if (count($teams) < 2) {
            return 0;
        }

        $schedGen = new ScheduleGenerator();
        $schedule = $schedGen->generate($leagueId, $seasonId, $teams);

        $db = \App\Database\Connection::getInstance()->getPdo();
        foreach ($schedule as $g) {
            $cols = implode(', ', array_keys($g));
            $placeholders = implode(', ', array_fill(0, count($g), '?'));
            $stmt = $db->prepare("INSERT INTO games ({$cols}) VALUES ({$placeholders})");
            $stmt->execute(array_values($g));
        }

        return count($schedule);
    }
}
