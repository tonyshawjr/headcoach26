<?php

namespace App\Services;

use App\Database\Connection;

class MilestoneService
{
    private \PDO $db;

    // ---------------------------------------------------------------
    // Season milestone definitions: stat_column => [threshold => label]
    // ---------------------------------------------------------------
    private const SEASON_MILESTONES = [
        'pass_yards' => [
            4000 => '4,000-Yard Passer',
            5000 => '5,000-Yard Passer',
        ],
        'pass_tds' => [
            30 => '30-TD Passer',
            40 => '40-TD Passer',
        ],
        'rush_yards' => [
            1000 => '1,000-Yard Rusher',
        ],
        'rec_yards' => [
            1000 => '1,000-Yard Receiver',
        ],
        'sacks' => [
            10 => '10-Sack Player',
            15 => '15-Sack Player',
        ],
        'tackles' => [
            100 => '100-Tackle Player',
        ],
        'interceptions_def' => [
            10 => '10-Interception Player',
        ],
    ];

    // ---------------------------------------------------------------
    // Career milestone definitions: stat_column => [threshold => label]
    // ---------------------------------------------------------------
    private const CAREER_MILESTONES = [
        'pass_yards' => [
            10000 => '10,000 Career Passing Yards',
            20000 => '20,000 Career Passing Yards',
            30000 => '30,000 Career Passing Yards',
            40000 => '40,000 Career Passing Yards',
        ],
        'pass_tds' => [
            100 => '100 Career Touchdown Passes',
            200 => '200 Career Touchdown Passes',
        ],
        'rush_yards' => [
            5000  => '5,000 Career Rushing Yards',
            10000 => '10,000 Career Rushing Yards',
        ],
        'rush_tds' => [
            50 => '50 Career Rushing Touchdowns',
        ],
        'rec_yards' => [
            5000  => '5,000 Career Receiving Yards',
            10000 => '10,000 Career Receiving Yards',
        ],
        'sacks' => [
            100 => '100 Career Sacks',
        ],
    ];

    // ---------------------------------------------------------------
    // Game milestone definitions: stat_column => [threshold => label]
    // ---------------------------------------------------------------
    private const GAME_MILESTONES = [
        'pass_yards' => [
            300 => '300-Yard Passing Game',
            400 => '400-Yard Passing Game',
            500 => '500-Yard Passing Game',
        ],
        'rush_yards' => [
            200 => '200-Yard Rushing Game',
        ],
        'rec_yards' => [
            200 => '200-Yard Receiving Game',
        ],
        'pass_tds' => [
            5 => '5-TD Passing Game',
        ],
        'rush_tds' => [
            3 => '3-TD Rushing Game',
        ],
        'sacks' => [
            4 => '4-Sack Game',
        ],
        'interceptions_def' => [
            3 => '3-Interception Game',
        ],
    ];

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
    }

    // ===================================================================
    //  PUBLIC API — called by SimulationController
    // ===================================================================

    /**
     * Check for team-level milestones after a week is simulated.
     * Creates notifications for affected coaches and ticker items for league-wide visibility.
     *
     * @return array List of milestones found
     */
    public function checkMilestones(int $leagueId, int $week): array
    {
        $milestones = [];
        $notifService = new NotificationService();

        // Get league info for settings (trade deadline, etc.)
        $leagueStmt = $this->db->prepare("SELECT * FROM leagues WHERE id = ?");
        $leagueStmt->execute([$leagueId]);
        $league = $leagueStmt->fetch();
        if (!$league) return $milestones;

        $settings = json_decode($league['settings'] ?? '{}', true) ?: [];

        // Get current season
        $seasonStmt = $this->db->prepare(
            "SELECT * FROM seasons WHERE league_id = ? AND is_current = 1 LIMIT 1"
        );
        $seasonStmt->execute([$leagueId]);
        $season = $seasonStmt->fetch();
        $seasonId = $season ? (int) $season['id'] : 0;

        // Determine total regular season weeks (based on game schedule)
        $maxWeekStmt = $this->db->prepare(
            "SELECT MAX(week) as max_week FROM games
             WHERE league_id = ? AND season_id = ? AND game_type = 'regular'"
        );
        $maxWeekStmt->execute([$leagueId, $seasonId]);
        $maxWeekRow = $maxWeekStmt->fetch();
        $totalRegularWeeks = $maxWeekRow ? (int) $maxWeekRow['max_week'] : 18;

        // Get all teams in the league
        $teamsStmt = $this->db->prepare(
            "SELECT t.*, c.user_id, c.id as coach_id
             FROM teams t
             LEFT JOIN coaches c ON c.team_id = t.id AND c.league_id = t.league_id
             WHERE t.league_id = ?"
        );
        $teamsStmt->execute([$leagueId]);
        $teams = $teamsStmt->fetchAll();

        // 1. Check for clinched playoff berth (10+ wins with <=4 games left)
        $milestones = array_merge($milestones, $this->checkPlayoffClinch($leagueId, $seasonId, $week, $totalRegularWeeks, $teams, $notifService));

        // 2. Check for eliminated from playoff contention
        $milestones = array_merge($milestones, $this->checkEliminated($leagueId, $seasonId, $week, $totalRegularWeeks, $teams, $notifService));

        // 3. Trade deadline approaching (2 weeks before)
        $tradeDeadline = (int) ($settings['trade_deadline_week'] ?? 12);
        if ($week === $tradeDeadline - 2) {
            $milestones = array_merge($milestones, $this->tradeDeadlineApproaching($leagueId, $seasonId, $week, $tradeDeadline, $teams, $notifService));
        }

        // 4. Trade deadline passed
        if ($week === $tradeDeadline) {
            $milestones = array_merge($milestones, $this->tradeDeadlinePassed($leagueId, $seasonId, $week, $teams, $notifService));
        }

        // 5. Win streak 5+
        $milestones = array_merge($milestones, $this->checkWinStreak($leagueId, $seasonId, $week, $teams, $notifService));

        // 6. Loss streak 5+
        $milestones = array_merge($milestones, $this->checkLossStreak($leagueId, $seasonId, $week, $teams, $notifService));

        // Generate narrative articles for significant milestones
        if (!empty($milestones) && class_exists('App\\Services\\NarrativeEngine')) {
            try {
                $engine = new \App\Services\NarrativeEngine();
                foreach ($milestones as $milestone) {
                    try {
                        $engine->generateMilestoneArticle($leagueId, $seasonId, $week, [
                            'type' => $milestone['type'] ?? 'unknown',
                            'team_id' => $milestone['team_id'] ?? null,
                            'details' => $milestone['message'] ?? '',
                        ]);
                    } catch (\Throwable $e) {
                        error_log("NarrativeEngine milestone article error: " . $e->getMessage());
                    }
                }
            } catch (\Throwable $e) {
                error_log("NarrativeEngine milestone init error: " . $e->getMessage());
            }
        }

        return $milestones;
    }

    /**
     * Check for single-game player milestones after each game is simulated.
     * Called once per game with the raw per-player stats from SimEngine.
     *
     * @param int   $leagueId
     * @param int   $week
     * @param int   $gameId
     * @param array $gameStats  Associative array: playerId => stat row (pass_yards, rush_yards, etc.)
     * @return array  List of achieved milestones
     */
    public function checkGameMilestones(int $leagueId, int $week, int $gameId, array $gameStats): array
    {
        $this->ensureMilestonesTable();
        $achieved = [];

        $season = $this->getCurrentSeason($leagueId);
        $seasonYear = $season ? (int) $season['year'] : 0;
        $seasonId = $season ? (int) $season['id'] : 0;

        foreach ($gameStats as $playerId => $stats) {
            $playerName = $this->getPlayerName((int) $playerId);
            $teamId = (int) ($stats['team_id'] ?? 0);

            foreach (self::GAME_MILESTONES as $statCol => $thresholds) {
                $value = $this->numericStat($stats, $statCol);
                if ($value <= 0) continue;

                foreach ($thresholds as $threshold => $label) {
                    if ($value >= $threshold) {
                        if ($this->awardPlayerMilestone((int) $playerId, $leagueId, 'game', $threshold, $label, $seasonYear, $week, $gameId, $statCol)) {
                            $msg = "{$playerName} recorded a {$label}!";
                            $this->createTickerItem($leagueId, 0, $week, $msg, $teamId);
                            $achieved[] = [
                                'type' => 'game',
                                'milestone' => $label,
                                'player_id' => (int) $playerId,
                                'player_name' => $playerName,
                                'team_id' => $teamId,
                                'stat' => $statCol,
                                'value' => $value,
                                'threshold' => $threshold,
                                'message' => $msg,
                            ];
                        }
                    }
                }
            }
        }

        // Generate narrative articles for player game milestones
        if (!empty($achieved) && class_exists('App\\Services\\NarrativeEngine')) {
            try {
                $engine = new \App\Services\NarrativeEngine();
                foreach ($achieved as $milestone) {
                    try {
                        $engine->generateMilestoneArticle($leagueId, $seasonId, $week, [
                            'type' => $milestone['milestone'] ?? 'game_milestone',
                            'team_id' => $milestone['team_id'] ?? null,
                            'details' => $milestone['message'] ?? '',
                        ]);
                    } catch (\Throwable $e) {
                        error_log("NarrativeEngine game milestone article error: " . $e->getMessage());
                    }
                }
            } catch (\Throwable $e) {
                error_log("NarrativeEngine game milestone init error: " . $e->getMessage());
            }
        }

        return $achieved;
    }

    /**
     * Check for season-cumulative player milestones after a full week of games.
     * Aggregates all game_stats for the current season and compares to thresholds.
     *
     * @return array  List of achieved milestones
     */
    public function checkWeeklyMilestones(int $leagueId, int $week): array
    {
        $this->ensureMilestonesTable();
        $achieved = [];

        $season = $this->getCurrentSeason($leagueId);
        if (!$season) return $achieved;

        $seasonId = (int) $season['id'];
        $seasonYear = (int) $season['year'];

        // Build the list of stat columns we care about
        $statCols = array_keys(self::SEASON_MILESTONES);
        $sumCols = implode(', ', array_map(fn($c) => "SUM(gs.{$c}) AS {$c}", $statCols));

        $stmt = $this->db->prepare(
            "SELECT gs.player_id, p.first_name, p.last_name, p.team_id, {$sumCols}
             FROM game_stats gs
             JOIN games g ON g.id = gs.game_id
             JOIN players p ON p.id = gs.player_id
             WHERE g.league_id = ? AND g.season_id = ?
             GROUP BY gs.player_id"
        );
        $stmt->execute([$leagueId, $seasonId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $playerId = (int) $row['player_id'];
            $playerName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            $teamId = (int) ($row['team_id'] ?? 0);

            foreach (self::SEASON_MILESTONES as $statCol => $thresholds) {
                $value = (float) ($row[$statCol] ?? 0);
                if ($value <= 0) continue;

                foreach ($thresholds as $threshold => $label) {
                    if ($value >= $threshold) {
                        if ($this->awardPlayerMilestone($playerId, $leagueId, 'season', $threshold, $label, $seasonYear, $week, null, $statCol)) {
                            $msg = "{$playerName} has surpassed {$this->formatNumber($threshold)} {$this->statLabel($statCol)} this season!";
                            $this->createTickerItem($leagueId, $seasonId, $week, $msg, $teamId);
                            $achieved[] = [
                                'type' => 'season',
                                'milestone' => $label,
                                'player_id' => $playerId,
                                'player_name' => $playerName,
                                'team_id' => $teamId,
                                'stat' => $statCol,
                                'value' => (int) $value,
                                'threshold' => $threshold,
                                'message' => $msg,
                            ];
                        }
                    }
                }
            }
        }

        return $achieved;
    }

    /**
     * Check for career-cumulative milestones (historical_stats + current season game_stats).
     * Only runs for stat columns defined in CAREER_MILESTONES.
     *
     * @return array  List of achieved milestones
     */
    public function checkCareerMilestones(int $leagueId): array
    {
        $this->ensureMilestonesTable();
        $achieved = [];

        $season = $this->getCurrentSeason($leagueId);
        if (!$season) return $achieved;

        $seasonId = (int) $season['id'];
        $seasonYear = (int) $season['year'];
        $week = (int) ($season['current_week'] ?? 0);
        if ($week === 0) {
            // Fall back to league current_week
            $lStmt = $this->db->prepare("SELECT current_week FROM leagues WHERE id = ?");
            $lStmt->execute([$leagueId]);
            $lRow = $lStmt->fetch();
            $week = $lRow ? (int) $lRow['current_week'] : 0;
        }

        $statCols = array_keys(self::CAREER_MILESTONES);

        // Historical totals per player (all prior seasons)
        $histSumCols = implode(', ', array_map(fn($c) => "COALESCE(SUM(h.{$c}), 0) AS hist_{$c}", $statCols));
        $currSumCols = implode(', ', array_map(fn($c) => "COALESCE(SUM(gs.{$c}), 0) AS curr_{$c}", $statCols));

        // Step 1: Get current-season totals per player
        $currStmt = $this->db->prepare(
            "SELECT gs.player_id, p.first_name, p.last_name, p.team_id, {$currSumCols}
             FROM game_stats gs
             JOIN games g ON g.id = gs.game_id
             JOIN players p ON p.id = gs.player_id
             WHERE g.league_id = ? AND g.season_id = ?
             GROUP BY gs.player_id"
        );
        $currStmt->execute([$leagueId, $seasonId]);
        $currentRows = $currStmt->fetchAll(\PDO::FETCH_ASSOC);

        // Step 2: For each player with current-season stats, sum historical + current
        foreach ($currentRows as $row) {
            $playerId = (int) $row['player_id'];
            $playerName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            $teamId = (int) ($row['team_id'] ?? 0);

            // Fetch historical totals for this player
            $histStmt = $this->db->prepare(
                "SELECT {$histSumCols}
                 FROM historical_stats h
                 WHERE h.player_id = ? AND h.league_id = ?"
            );
            $histStmt->execute([$playerId, $leagueId]);
            $histRow = $histStmt->fetch(\PDO::FETCH_ASSOC);

            foreach (self::CAREER_MILESTONES as $statCol => $thresholds) {
                $histVal = (float) ($histRow["hist_{$statCol}"] ?? 0);
                $currVal = (float) ($row["curr_{$statCol}"] ?? 0);
                $careerTotal = $histVal + $currVal;

                if ($careerTotal <= 0) continue;

                foreach ($thresholds as $threshold => $label) {
                    if ($careerTotal >= $threshold) {
                        if ($this->awardPlayerMilestone($playerId, $leagueId, 'career', $threshold, $label, $seasonYear, $week, null, $statCol)) {
                            $msg = "{$playerName} has reached {$this->formatNumber($threshold)} career {$this->statLabel($statCol)}!";
                            $this->createTickerItem($leagueId, $seasonId, $week, $msg, $teamId);
                            $achieved[] = [
                                'type' => 'career',
                                'milestone' => $label,
                                'player_id' => $playerId,
                                'player_name' => $playerName,
                                'team_id' => $teamId,
                                'stat' => $statCol,
                                'value' => (int) $careerTotal,
                                'threshold' => $threshold,
                                'message' => $msg,
                            ];
                        }
                    }
                }
            }
        }

        return $achieved;
    }

    /**
     * Retrieve all milestones for a league, optionally filtered by season or player.
     */
    public function getMilestones(int $leagueId, ?int $seasonYear = null, ?int $playerId = null): array
    {
        $this->ensureMilestonesTable();

        $sql = "SELECT pm.*, p.first_name, p.last_name, p.position, t.abbreviation AS team_abbr
                FROM player_milestones pm
                JOIN players p ON p.id = pm.player_id
                LEFT JOIN teams t ON t.id = p.team_id
                WHERE pm.league_id = ?";
        $params = [$leagueId];

        if ($seasonYear !== null) {
            $sql .= " AND pm.season_year = ?";
            $params[] = $seasonYear;
        }
        if ($playerId !== null) {
            $sql .= " AND pm.player_id = ?";
            $params[] = $playerId;
        }

        $sql .= " ORDER BY pm.achieved_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // ===================================================================
    //  PLAYER MILESTONE HELPERS
    // ===================================================================

    /**
     * Insert a player milestone row. Returns true if newly inserted, false if duplicate.
     * The UNIQUE constraint (player_id, league_id, milestone_type, milestone_value)
     * prevents the same milestone from being awarded twice.
     */
    private function awardPlayerMilestone(
        int $playerId,
        int $leagueId,
        string $milestoneType,
        int $milestoneValue,
        string $label,
        int $seasonYear,
        int $week,
        ?int $gameId,
        string $statCol
    ): bool {
        try {
            $this->db->prepare(
                "INSERT OR IGNORE INTO player_milestones
                 (player_id, league_id, milestone_type, milestone_value, milestone_label, season_year, week, game_id, achieved_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))"
            )->execute([
                $playerId,
                $leagueId,
                $milestoneType . '_' . $statCol,
                $milestoneValue,
                $label,
                $seasonYear,
                $week,
                $gameId,
            ]);

            // rowCount() == 1 means the row was actually inserted (not a duplicate)
            return $this->db->lastInsertId() > 0;
        } catch (\Throwable $e) {
            // If insert fails for any reason (e.g., constraint), treat as duplicate
            error_log("MilestoneService awardPlayerMilestone error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get current season record for this league.
     */
    private function getCurrentSeason(int $leagueId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM seasons WHERE league_id = ? AND is_current = 1 LIMIT 1"
        );
        $stmt->execute([$leagueId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Get a player's display name by ID.
     */
    private function getPlayerName(int $playerId): string
    {
        $stmt = $this->db->prepare("SELECT first_name, last_name FROM players WHERE id = ?");
        $stmt->execute([$playerId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return "Unknown Player";
        return trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    }

    /**
     * Safely extract a numeric stat value from an array (handles float sacks, etc.).
     */
    private function numericStat(array $stats, string $col): float
    {
        return (float) ($stats[$col] ?? 0);
    }

    /**
     * Format a number with commas for display (e.g., 10000 -> "10,000").
     */
    private function formatNumber(int $n): string
    {
        return number_format($n);
    }

    /**
     * Human-readable label for a stat column.
     */
    private function statLabel(string $col): string
    {
        return match ($col) {
            'pass_yards' => 'passing yards',
            'pass_tds' => 'touchdown passes',
            'rush_yards' => 'rushing yards',
            'rush_tds' => 'rushing touchdowns',
            'rec_yards' => 'receiving yards',
            'rec_tds' => 'receiving touchdowns',
            'sacks' => 'sacks',
            'tackles' => 'tackles',
            'interceptions_def' => 'interceptions',
            default => str_replace('_', ' ', $col),
        };
    }

    /**
     * Ensure the player_milestones table exists (runtime safety net).
     */
    private function ensureMilestonesTable(): void
    {
        try {
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS player_milestones (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    player_id INT NOT NULL,
                    league_id INT NOT NULL,
                    milestone_type VARCHAR(30) NOT NULL,
                    milestone_value INT NOT NULL,
                    milestone_label VARCHAR(200) NOT NULL,
                    season_year INT NULL,
                    week INT NULL,
                    game_id INT NULL,
                    achieved_at DATETIME NOT NULL DEFAULT (datetime('now')),
                    UNIQUE(player_id, league_id, milestone_type, milestone_value)
                )"
            );
        } catch (\Throwable $e) {
            // Table already exists or other benign error
        }
    }

    // ===================================================================
    //  TEAM-LEVEL MILESTONE CHECKS (unchanged from original)
    // ===================================================================

    /**
     * Check if any team has clinched a playoff berth.
     * A team clinches when they have enough wins that even if they lose all remaining games,
     * they would still have more wins than the team that would be in the last playoff spot.
     * Simplified: 10+ wins with 4 or fewer games remaining.
     */
    private function checkPlayoffClinch(
        int $leagueId, int $seasonId, int $week, int $totalRegularWeeks,
        array $teams, NotificationService $notifService
    ): array {
        $milestones = [];
        $gamesRemaining = $totalRegularWeeks - $week;

        foreach ($teams as $team) {
            $wins = (int) $team['wins'];
            $teamId = (int) $team['id'];

            // Check if already notified for this milestone this season
            if ($this->alreadyNotified($leagueId, $seasonId, $teamId, 'clinched_playoff')) {
                continue;
            }

            // Simple clinch check: 10+ wins with 4 or fewer games left
            if ($wins >= 10 && $gamesRemaining <= 4) {
                $teamName = $team['city'] . ' ' . $team['name'];
                $title = "{$teamName} Clinch Playoff Berth";
                $message = "The {$teamName} have clinched a spot in the playoffs with a {$wins}-{$team['losses']} record.";

                // Notify the team's coach if they are human
                if (!empty($team['user_id'])) {
                    $notifService->create(
                        (int) $team['user_id'],
                        $leagueId,
                        'achievement',
                        $title,
                        $message
                    );
                }

                // Create ticker item for league visibility
                $this->createTickerItem($leagueId, $seasonId, $week, $title, $teamId);

                // Record that we sent this milestone
                $this->recordMilestone($leagueId, $seasonId, $teamId, 'clinched_playoff');

                $milestones[] = [
                    'type' => 'clinched_playoff',
                    'team_id' => $teamId,
                    'team_name' => $teamName,
                    'message' => $message,
                ];
            }
        }

        return $milestones;
    }

    /**
     * Check if any team has been mathematically eliminated from playoff contention.
     * A team is eliminated when even if they win all remaining games, they cannot
     * reach the win total of the team holding the last playoff spot in their conference.
     */
    private function checkEliminated(
        int $leagueId, int $seasonId, int $week, int $totalRegularWeeks,
        array $teams, NotificationService $notifService
    ): array {
        $milestones = [];
        $gamesRemaining = $totalRegularWeeks - $week;

        // Group teams by conference and sort by wins desc
        $confTeams = [];
        foreach ($teams as $team) {
            $confTeams[$team['conference']][] = $team;
        }

        foreach ($confTeams as $conf => $cTeams) {
            // Sort by wins desc, then points_for desc
            usort($cTeams, function ($a, $b) {
                $winDiff = (int) $b['wins'] - (int) $a['wins'];
                if ($winDiff !== 0) return $winDiff;
                return (int) $b['points_for'] - (int) $a['points_for'];
            });

            // Determine playoff spots: 7 for 14+ teams per conf, 4 for smaller
            $numTeams = count($cTeams);
            $playoffSpots = $numTeams >= 7 ? 7 : min(4, (int) floor($numTeams / 2));
            if ($playoffSpots < 1) continue;

            // The last team currently in a playoff spot
            $lastInIdx = min($playoffSpots - 1, $numTeams - 1);
            $lastInWins = (int) $cTeams[$lastInIdx]['wins'];

            foreach ($cTeams as $team) {
                $teamId = (int) $team['id'];
                $maxPossibleWins = (int) $team['wins'] + $gamesRemaining;

                if ($this->alreadyNotified($leagueId, $seasonId, $teamId, 'eliminated')) {
                    continue;
                }

                // A team is eliminated if their max possible wins < the wins of the last playoff team
                // AND we're at least halfway through the season
                if ($maxPossibleWins < $lastInWins && $week >= (int) ($totalRegularWeeks / 2)) {
                    $teamName = $team['city'] . ' ' . $team['name'];
                    $title = "{$teamName} Eliminated";
                    $message = "The {$teamName} ({$team['wins']}-{$team['losses']}) have been mathematically eliminated from playoff contention.";

                    if (!empty($team['user_id'])) {
                        $notifService->create(
                            (int) $team['user_id'],
                            $leagueId,
                            'achievement',
                            $title,
                            $message
                        );
                    }

                    $this->createTickerItem($leagueId, $seasonId, $week, $title, $teamId);
                    $this->recordMilestone($leagueId, $seasonId, $teamId, 'eliminated');

                    $milestones[] = [
                        'type' => 'eliminated',
                        'team_id' => $teamId,
                        'team_name' => $teamName,
                        'message' => $message,
                    ];
                }
            }
        }

        return $milestones;
    }

    /**
     * Trade deadline approaching notification (2 weeks before).
     */
    private function tradeDeadlineApproaching(
        int $leagueId, int $seasonId, int $week, int $tradeDeadline,
        array $teams, NotificationService $notifService
    ): array {
        $milestones = [];

        // Only send once per season
        if ($this->alreadyNotified($leagueId, $seasonId, 0, 'trade_deadline_approaching')) {
            return $milestones;
        }

        $title = "Trade Deadline Approaching";
        $message = "The trade deadline is in 2 weeks (Week {$tradeDeadline}). Make your moves before it's too late.";

        // Notify all human coaches
        foreach ($teams as $team) {
            if (!empty($team['user_id'])) {
                $notifService->create(
                    (int) $team['user_id'],
                    $leagueId,
                    'achievement',
                    $title,
                    $message
                );
            }
        }

        $this->createTickerItem($leagueId, $seasonId, $week, "Trade deadline in 2 weeks (Week {$tradeDeadline})", null);
        $this->recordMilestone($leagueId, $seasonId, 0, 'trade_deadline_approaching');

        $milestones[] = [
            'type' => 'trade_deadline_approaching',
            'team_id' => null,
            'message' => $message,
        ];

        return $milestones;
    }

    /**
     * Trade deadline has passed notification.
     */
    private function tradeDeadlinePassed(
        int $leagueId, int $seasonId, int $week,
        array $teams, NotificationService $notifService
    ): array {
        $milestones = [];

        if ($this->alreadyNotified($leagueId, $seasonId, 0, 'trade_deadline_passed')) {
            return $milestones;
        }

        $title = "Trade Deadline Has Passed";
        $message = "The trade deadline has passed. No more trades can be made this season.";

        foreach ($teams as $team) {
            if (!empty($team['user_id'])) {
                $notifService->create(
                    (int) $team['user_id'],
                    $leagueId,
                    'achievement',
                    $title,
                    $message
                );
            }
        }

        $this->createTickerItem($leagueId, $seasonId, $week, "Trade deadline has passed", null);
        $this->recordMilestone($leagueId, $seasonId, 0, 'trade_deadline_passed');

        $milestones[] = [
            'type' => 'trade_deadline_passed',
            'team_id' => null,
            'message' => $message,
        ];

        return $milestones;
    }

    /**
     * Check for win streaks of 5+.
     */
    private function checkWinStreak(
        int $leagueId, int $seasonId, int $week,
        array $teams, NotificationService $notifService
    ): array {
        $milestones = [];

        foreach ($teams as $team) {
            $streak = $team['streak'] ?? '';
            $teamId = (int) $team['id'];

            if (!str_starts_with($streak, 'W')) continue;

            $streakCount = (int) substr($streak, 1);
            if ($streakCount < 5) continue;

            // Only notify on exact milestones: 5, 8, 10, etc.
            // Use modular approach: notify at 5 and then every 3
            $milestoneKey = "win_streak_{$streakCount}";
            if ($this->alreadyNotified($leagueId, $seasonId, $teamId, $milestoneKey)) {
                continue;
            }

            // Only trigger at notable thresholds
            if ($streakCount !== 5 && $streakCount !== 8 && $streakCount !== 10 && $streakCount % 5 !== 0) {
                continue;
            }

            $teamName = $team['city'] . ' ' . $team['name'];
            $title = "{$teamName} on {$streakCount}-Game Win Streak";
            $message = "The {$teamName} are red hot with {$streakCount} consecutive wins!";

            if (!empty($team['user_id'])) {
                $notifService->create(
                    (int) $team['user_id'],
                    $leagueId,
                    'achievement',
                    $title,
                    $message
                );
            }

            $this->createTickerItem($leagueId, $seasonId, $week, $title, $teamId);
            $this->recordMilestone($leagueId, $seasonId, $teamId, $milestoneKey);

            $milestones[] = [
                'type' => 'win_streak',
                'team_id' => $teamId,
                'team_name' => $teamName,
                'streak' => $streakCount,
                'message' => $message,
            ];
        }

        return $milestones;
    }

    /**
     * Check for loss streaks of 5+.
     */
    private function checkLossStreak(
        int $leagueId, int $seasonId, int $week,
        array $teams, NotificationService $notifService
    ): array {
        $milestones = [];

        foreach ($teams as $team) {
            $streak = $team['streak'] ?? '';
            $teamId = (int) $team['id'];

            if (!str_starts_with($streak, 'L')) continue;

            $streakCount = (int) substr($streak, 1);
            if ($streakCount < 5) continue;

            $milestoneKey = "loss_streak_{$streakCount}";
            if ($this->alreadyNotified($leagueId, $seasonId, $teamId, $milestoneKey)) {
                continue;
            }

            // Only trigger at notable thresholds
            if ($streakCount !== 5 && $streakCount !== 8 && $streakCount !== 10 && $streakCount % 5 !== 0) {
                continue;
            }

            $teamName = $team['city'] . ' ' . $team['name'];
            $title = "{$teamName} on {$streakCount}-Game Losing Streak";
            $message = "The {$teamName} are struggling mightily with {$streakCount} consecutive losses.";

            if (!empty($team['user_id'])) {
                $notifService->create(
                    (int) $team['user_id'],
                    $leagueId,
                    'achievement',
                    $title,
                    $message
                );
            }

            $this->createTickerItem($leagueId, $seasonId, $week, $title, $teamId);
            $this->recordMilestone($leagueId, $seasonId, $teamId, $milestoneKey);

            $milestones[] = [
                'type' => 'loss_streak',
                'team_id' => $teamId,
                'team_name' => $teamName,
                'streak' => $streakCount,
                'message' => $message,
            ];
        }

        return $milestones;
    }

    // ===================================================================
    //  SHARED HELPERS (team-level tracking)
    // ===================================================================

    /**
     * Insert a ticker item for league-wide visibility.
     */
    private function createTickerItem(int $leagueId, int $seasonId, int $week, string $text, ?int $teamId): void
    {
        $this->db->prepare(
            "INSERT INTO ticker_items (league_id, text, type, team_id, week, created_at)
             VALUES (?, ?, 'milestone', ?, ?, datetime('now'))"
        )->execute([$leagueId, $text, $teamId, $week]);
    }

    /**
     * Check if a milestone notification was already sent (prevents duplicates).
     * Uses ticker_items as a lightweight check — looks for matching milestone text patterns.
     */
    private function alreadyNotified(int $leagueId, int $seasonId, int $teamId, string $milestoneKey): bool
    {
        // Use a simple tracking table if it exists, otherwise check ticker items
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM milestone_tracking
                 WHERE league_id = ? AND season_id = ? AND team_id = ? AND milestone_key = ?"
            );
            $stmt->execute([$leagueId, $seasonId, $teamId, $milestoneKey]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (\Throwable $e) {
            // Table doesn't exist yet — create it
            $this->ensureTrackingTable();
            return false;
        }
    }

    /**
     * Record that a milestone was sent to prevent duplicates.
     */
    private function recordMilestone(int $leagueId, int $seasonId, int $teamId, string $milestoneKey): void
    {
        try {
            $this->db->prepare(
                "INSERT INTO milestone_tracking (league_id, season_id, team_id, milestone_key, created_at)
                 VALUES (?, ?, ?, ?, datetime('now'))"
            )->execute([$leagueId, $seasonId, $teamId, $milestoneKey]);
        } catch (\Throwable $e) {
            $this->ensureTrackingTable();
            // Retry once after table creation
            try {
                $this->db->prepare(
                    "INSERT INTO milestone_tracking (league_id, season_id, team_id, milestone_key, created_at)
                     VALUES (?, ?, ?, ?, datetime('now'))"
                )->execute([$leagueId, $seasonId, $teamId, $milestoneKey]);
            } catch (\Throwable $e2) {
                error_log("MilestoneService recordMilestone error: " . $e2->getMessage());
            }
        }
    }

    /**
     * Create the milestone_tracking table if it doesn't exist.
     */
    private function ensureTrackingTable(): void
    {
        try {
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS milestone_tracking (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    league_id INT NOT NULL,
                    season_id INT NOT NULL,
                    team_id INT NOT NULL DEFAULT 0,
                    milestone_key VARCHAR(100) NOT NULL,
                    created_at DATETIME NOT NULL,
                    UNIQUE(league_id, season_id, team_id, milestone_key)
                )"
            );
        } catch (\Throwable $e) {
            error_log("MilestoneService ensureTrackingTable error: " . $e->getMessage());
        }
    }
}
