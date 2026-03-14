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
                // Move to regular season week 1
                $newPhase = 'regular';
                $newWeek = 1;
                break;

            case 'regular':
                if ($currentWeek >= 18) {
                    // Move to playoffs
                    $newPhase = 'playoffs';
                    $newWeek = 19;
                } else {
                    $newWeek = $currentWeek + 1;
                }
                break;

            case 'playoffs':
                if ($currentWeek >= 22) {
                    // Super Bowl is week 22 -- move to offseason
                    $newPhase = 'offseason';
                    $newWeek = $currentWeek + 1;
                } else {
                    $newWeek = $currentWeek + 1;
                }
                break;

            case 'offseason':
                // Reset for a new season
                $newPhase = 'preseason';
                $newWeek = 0;
                break;
        }

        $this->league->update((int) $params['id'], [
            'current_week' => $newWeek,
            'phase' => $newPhase,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $updated = $this->league->find((int) $params['id']);
        $updated['settings'] = json_decode($updated['settings'] ?? '{}', true);

        Response::json([
            'message' => "Advanced to {$newPhase} week {$newWeek}",
            'success' => true,
            'week' => $newWeek,
            'phase' => $newPhase,
            'league' => $updated,
        ]);
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

                // Save individual player stats
                $allPlayerStats = array_merge($result['home_stats'], $result['away_stats']);
                foreach ($allPlayerStats as $playerId => $stat) {
                    $stat['game_id'] = (int) $game['id'];
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
                        'grade' => $stat['grade'],
                    ];

                    $gameStatModel->create($statRow);
                }

                // Save injuries
                foreach ($result['injuries'] as $inj) {
                    $inj['game_id'] = (int) $game['id'];
                    $injuryModel->create($inj);
                }

                // Update team records
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
}
