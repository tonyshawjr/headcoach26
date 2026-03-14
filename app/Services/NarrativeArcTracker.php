<?php

namespace App\Services;

use App\Database\Connection;

class NarrativeArcTracker
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
    }

    // ────────────────────────────────────────────
    // Public API
    // ────────────────────────────────────────────

    /**
     * Check for and create/update narrative arcs after each week.
     */
    public function processWeek(int $leagueId, int $seasonId, int $week): void
    {
        // Expire arcs whose conditions are no longer true
        $this->expireStaleArcs($leagueId, $week);

        // Detect new arcs or update existing ones
        $streaks = $this->detectStreaks($leagueId, $week);
        foreach ($streaks as $arc) {
            $this->upsertArc($leagueId, $seasonId, $arc, $week);
        }

        $comebacks = $this->detectComebackStories($leagueId, $week);
        foreach ($comebacks as $arc) {
            $this->upsertArc($leagueId, $seasonId, $arc, $week);
        }

        $breakouts = $this->detectBreakoutPlayers($leagueId, $seasonId, $week);
        foreach ($breakouts as $arc) {
            $this->upsertArc($leagueId, $seasonId, $arc, $week);
        }

        $hotSeats = $this->detectCoachHotSeat($leagueId);
        foreach ($hotSeats as $arc) {
            $this->upsertArc($leagueId, $seasonId, $arc, $week);
        }

        $surprises = $this->detectSurpriseContenders($leagueId);
        foreach ($surprises as $arc) {
            $this->upsertArc($leagueId, $seasonId, $arc, $week);
        }

        $playoffPush = $this->detectPlayoffPush($leagueId, $week);
        foreach ($playoffPush as $arc) {
            $this->upsertArc($leagueId, $seasonId, $arc, $week);
        }

        $rivalries = $this->detectRivalryIntensifying($leagueId);
        foreach ($rivalries as $arc) {
            $this->upsertArc($leagueId, $seasonId, $arc, $week);
        }
    }

    /**
     * Get all active narrative arcs for a league.
     */
    public function getActiveArcs(int $leagueId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM narrative_arcs WHERE league_id = ? AND status = 'active' ORDER BY started_week DESC"
        );
        $stmt->execute([$leagueId]);
        $arcs = $stmt->fetchAll();

        // Decode JSON data column
        foreach ($arcs as &$arc) {
            $arc['data'] = json_decode($arc['data'] ?? '{}', true) ?: [];
            $arc['metadata'] = json_decode($arc['metadata'] ?? '{}', true) ?: [];
        }
        unset($arc);

        return $arcs;
    }

    /**
     * Get active arcs for a specific team.
     */
    public function getTeamArcs(int $leagueId, int $teamId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM narrative_arcs
             WHERE league_id = ? AND status = 'active'
               AND (team_id = ? OR team_ids LIKE ?)
             ORDER BY started_week DESC"
        );
        $stmt->execute([$leagueId, $teamId, '%' . $teamId . '%']);
        $arcs = $stmt->fetchAll();

        foreach ($arcs as &$arc) {
            $arc['data'] = json_decode($arc['data'] ?? '{}', true) ?: [];
        }
        unset($arc);

        return $arcs;
    }

    // ────────────────────────────────────────────
    // Detection: Streaks
    // ────────────────────────────────────────────

    /**
     * Detect winning streaks (3+) and losing streaks (3+).
     */
    private function detectStreaks(int $leagueId, int $week): array
    {
        $stmt = $this->db->prepare("SELECT * FROM teams WHERE league_id = ?");
        $stmt->execute([$leagueId]);
        $teams = $stmt->fetchAll();

        $arcs = [];

        foreach ($teams as $team) {
            $streak = $team['streak'] ?? '';
            if (!preg_match('/^([WL])(\d+)$/', $streak, $m)) {
                continue;
            }

            $type = $m[1] === 'W' ? 'winning_streak' : 'losing_streak';
            $count = (int) $m[2];

            if ($count < 3) {
                continue;
            }

            $tName = $team['city'] . ' ' . $team['name'];

            if ($type === 'winning_streak') {
                $title = "{$tName} Have Won {$count} Straight";
                $description = "The {$tName} are on a red-hot {$count}-game winning streak. Can anyone slow them down?";
            } else {
                $title = "{$tName} Mired in {$count}-Game Losing Streak";
                $description = "The {$tName} have dropped {$count} consecutive games. The frustration is mounting and changes may be coming.";
            }

            $arcs[] = [
                'type' => $type,
                'team_id' => (int) $team['id'],
                'player_id' => null,
                'title' => $title,
                'description' => $description,
                'data' => ['streak_count' => $count, 'streak_type' => $m[1]],
            ];
        }

        return $arcs;
    }

    // ────────────────────────────────────────────
    // Detection: Comeback Stories
    // ────────────────────────────────────────────

    /**
     * Detect comeback stories: teams that started 0-3 or worse and are now winning.
     */
    private function detectComebackStories(int $leagueId, int $week): array
    {
        if ($week < 5) {
            return []; // Need enough games to detect a turnaround
        }

        $stmt = $this->db->prepare("SELECT * FROM teams WHERE league_id = ?");
        $stmt->execute([$leagueId]);
        $teams = $stmt->fetchAll();

        $arcs = [];

        foreach ($teams as $team) {
            $wins = (int) $team['wins'];
            $losses = (int) $team['losses'];
            $totalGames = $wins + $losses;

            if ($totalGames < 5 || $losses < 3) {
                continue;
            }

            // Check if the team has won more of their recent games
            // Simple heuristic: they now have a winning record OR are at .500 after being 3+ games below
            // We look at game history for the first 3 games
            $earlyGames = $this->getTeamGameResults($team['id'], $leagueId, 3);
            $earlyLosses = 0;
            foreach ($earlyGames as $g) {
                if (!$g['won']) {
                    $earlyLosses++;
                }
            }

            if ($earlyLosses >= 3 && $wins >= $losses) {
                $tName = $team['city'] . ' ' . $team['name'];
                $arcs[] = [
                    'type' => 'comeback_story',
                    'team_id' => (int) $team['id'],
                    'player_id' => null,
                    'title' => "Against All Odds: The {$tName} Comeback",
                    'description' => "After starting 0-{$earlyLosses}, the {$tName} have clawed back to {$wins}-{$losses}. One of the most remarkable turnarounds of the season.",
                    'data' => ['early_losses' => $earlyLosses, 'current_wins' => $wins, 'current_losses' => $losses],
                ];
            }
        }

        return $arcs;
    }

    // ────────────────────────────────────────────
    // Detection: Breakout Players
    // ────────────────────────────────────────────

    /**
     * Detect breakout players: players performing significantly above their rating.
     */
    private function detectBreakoutPlayers(int $leagueId, int $seasonId, int $week): array
    {
        if ($week < 3) {
            return []; // Need a few games for sample size
        }

        // Find players with consistently high grades relative to their overall rating
        $stmt = $this->db->prepare(
            "SELECT p.id, p.first_name, p.last_name, p.position, p.overall_rating, p.team_id,
                    COUNT(gs.id) as games_played,
                    AVG(CASE WHEN gs.grade = 'A+' THEN 97
                             WHEN gs.grade = 'A' THEN 93
                             WHEN gs.grade = 'A-' THEN 90
                             WHEN gs.grade = 'B+' THEN 87
                             WHEN gs.grade = 'B' THEN 83
                             WHEN gs.grade = 'B-' THEN 80
                             WHEN gs.grade = 'C+' THEN 77
                             WHEN gs.grade = 'C' THEN 73
                             WHEN gs.grade = 'C-' THEN 70
                             WHEN gs.grade = 'D+' THEN 67
                             WHEN gs.grade = 'D' THEN 63
                             WHEN gs.grade = 'D-' THEN 60
                             WHEN gs.grade = 'F' THEN 50
                             ELSE 75 END) as avg_grade
             FROM players p
             JOIN game_stats gs ON gs.player_id = p.id
             JOIN games g ON g.id = gs.game_id AND g.league_id = ? AND g.season_id = ?
             WHERE p.league_id = ? AND p.overall_rating <= 78
             GROUP BY p.id, p.first_name, p.last_name, p.position, p.overall_rating, p.team_id
             HAVING games_played >= 2 AND avg_grade >= 85"
        );
        $stmt->execute([$leagueId, $seasonId, $leagueId]);
        $breakouts = $stmt->fetchAll();

        $arcs = [];

        foreach ($breakouts as $player) {
            $pName = $player['first_name'] . ' ' . $player['last_name'];
            $team = $this->getTeam($player['team_id']);
            $tName = $team ? $team['city'] . ' ' . $team['name'] : 'his team';

            $arcs[] = [
                'type' => 'breakout_player',
                'team_id' => $player['team_id'] ? (int) $player['team_id'] : null,
                'player_id' => (int) $player['id'],
                'title' => "{$pName} Emerging as Breakout Star for {$tName}",
                'description' => "Despite an overall rating of {$player['overall_rating']}, {$pName} has averaged a grade of " . round($player['avg_grade']) . " across {$player['games_played']} games. A genuine breakout season is unfolding.",
                'data' => [
                    'overall_rating' => (int) $player['overall_rating'],
                    'avg_grade' => round($player['avg_grade'], 1),
                    'games_played' => (int) $player['games_played'],
                    'position' => $player['position'],
                ],
            ];
        }

        return $arcs;
    }

    // ────────────────────────────────────────────
    // Detection: Coach Hot Seat
    // ────────────────────────────────────────────

    /**
     * Detect coaches on the hot seat (low job security).
     */
    private function detectCoachHotSeat(int $leagueId): array
    {
        $stmt = $this->db->prepare(
            "SELECT c.*, t.city, t.name as team_name, t.wins, t.losses
             FROM coaches c
             JOIN teams t ON t.id = c.team_id
             WHERE c.league_id = ? AND c.job_security <= 40"
        );
        $stmt->execute([$leagueId]);
        $coaches = $stmt->fetchAll();

        $arcs = [];

        foreach ($coaches as $coach) {
            $tName = $coach['city'] . ' ' . $coach['team_name'];
            $arcs[] = [
                'type' => 'coach_hot_seat',
                'team_id' => (int) $coach['team_id'],
                'player_id' => null,
                'title' => "{$coach['name']}'s Job on the Line in {$coach['city']}",
                'description' => "With a job security rating of {$coach['job_security']} and the {$tName} sitting at {$coach['wins']}-{$coach['losses']}, {$coach['name']} is firmly on the hot seat. Every game is a must-win.",
                'data' => [
                    'coach_id' => (int) $coach['id'],
                    'job_security' => (int) $coach['job_security'],
                    'record' => "{$coach['wins']}-{$coach['losses']}",
                ],
            ];
        }

        return $arcs;
    }

    // ────────────────────────────────────────────
    // Detection: Surprise Contenders
    // ────────────────────────────────────────────

    /**
     * Detect surprise contenders: low-rated teams that are winning.
     */
    private function detectSurpriseContenders(int $leagueId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM teams WHERE league_id = ? AND overall_rating <= 72 AND wins > losses AND (wins + losses) >= 3"
        );
        $stmt->execute([$leagueId]);
        $teams = $stmt->fetchAll();

        $arcs = [];

        foreach ($teams as $team) {
            $tName = $team['city'] . ' ' . $team['name'];
            $arcs[] = [
                'type' => 'surprise_contender',
                'team_id' => (int) $team['id'],
                'player_id' => null,
                'title' => "Nobody Saw This Coming: The {$tName} Are For Real",
                'description' => "With an overall team rating of just {$team['overall_rating']}, the {$tName} were expected to be rebuilding. Instead, they're {$team['wins']}-{$team['losses']} and making believers out of everyone.",
                'data' => [
                    'overall_rating' => (int) $team['overall_rating'],
                    'record' => "{$team['wins']}-{$team['losses']}",
                    'point_diff' => (int) $team['points_for'] - (int) $team['points_against'],
                ],
            ];
        }

        return $arcs;
    }

    // ────────────────────────────────────────────
    // Detection: Playoff Push
    // ────────────────────────────────────────────

    /**
     * Detect teams fighting for the last playoff spot (late-season, near .500).
     */
    private function detectPlayoffPush(int $leagueId, int $week): array
    {
        if ($week < 8) {
            return []; // Too early to talk about playoff pushes
        }

        // Teams near .500 with at least 8 games played
        $stmt = $this->db->prepare(
            "SELECT * FROM teams WHERE league_id = ? AND (wins + losses) >= 8"
        );
        $stmt->execute([$leagueId]);
        $teams = $stmt->fetchAll();

        // Sort teams by wins descending
        usort($teams, fn($a, $b) => $b['wins'] <=> $a['wins']);

        $arcs = [];

        // Check teams around the 6th-7th seed (positions 5-8 in each conference)
        // Group by conference
        $conferences = [];
        foreach ($teams as $team) {
            $conferences[$team['conference']][] = $team;
        }

        foreach ($conferences as $conf => $confTeams) {
            // Sort conference teams by wins desc
            usort($confTeams, fn($a, $b) => $b['wins'] <=> $a['wins']);

            // The teams around positions 6-8 are in the playoff hunt
            $bubbleStart = min(5, count($confTeams) - 1);
            $bubbleEnd = min(8, count($confTeams));

            for ($i = $bubbleStart; $i < $bubbleEnd; $i++) {
                if (!isset($confTeams[$i])) continue;
                $team = $confTeams[$i];
                $tName = $team['city'] . ' ' . $team['name'];

                // Only flag if they have a realistic shot (not too far behind)
                if (isset($confTeams[5]) && ($confTeams[5]['wins'] - $team['wins']) <= 2) {
                    $arcs[] = [
                        'type' => 'playoff_push',
                        'team_id' => (int) $team['id'],
                        'player_id' => null,
                        'title' => "{$tName} In the Hunt: Every Game Matters Now",
                        'description' => "At {$team['wins']}-{$team['losses']}, the {$tName} are right on the playoff bubble. The margin for error is gone -- every game from here on out is a playoff game.",
                        'data' => [
                            'conference' => $conf,
                            'conference_rank' => $i + 1,
                            'games_behind' => isset($confTeams[5]) ? $confTeams[5]['wins'] - $team['wins'] : 0,
                        ],
                    ];
                }
            }
        }

        return $arcs;
    }

    // ────────────────────────────────────────────
    // Detection: Rivalry Intensifying
    // ────────────────────────────────────────────

    /**
     * Detect divisional rivalries where both teams are competitive.
     */
    private function detectRivalryIntensifying(int $leagueId): array
    {
        // Find divisions where the top two teams both have winning records
        $stmt = $this->db->prepare(
            "SELECT * FROM teams WHERE league_id = ? ORDER BY conference, division, wins DESC"
        );
        $stmt->execute([$leagueId]);
        $teams = $stmt->fetchAll();

        $divisions = [];
        foreach ($teams as $team) {
            $key = $team['conference'] . '_' . $team['division'];
            $divisions[$key][] = $team;
        }

        $arcs = [];

        foreach ($divisions as $divTeams) {
            if (count($divTeams) < 2) continue;

            $team1 = $divTeams[0]; // best record in division
            $team2 = $divTeams[1]; // second best

            // Both need winning records and be close in the standings
            if ($team1['wins'] <= $team1['losses'] || $team2['wins'] <= $team2['losses']) {
                continue;
            }

            $winDiff = abs($team1['wins'] - $team2['wins']);
            if ($winDiff > 2) {
                continue;
            }

            $name1 = $team1['city'] . ' ' . $team1['name'];
            $name2 = $team2['city'] . ' ' . $team2['name'];

            $arcs[] = [
                'type' => 'rivalry_intensifying',
                'team_id' => (int) $team1['id'],
                'player_id' => null,
                'title' => "{$name1} vs. {$name2}: Division Race Heating Up",
                'description' => "Both the {$name1} ({$team1['wins']}-{$team1['losses']}) and the {$name2} ({$team2['wins']}-{$team2['losses']}) are in contention for the division crown. This rivalry is reaching a boiling point.",
                'data' => [
                    'team1_id' => (int) $team1['id'],
                    'team2_id' => (int) $team2['id'],
                    'team1_record' => "{$team1['wins']}-{$team1['losses']}",
                    'team2_record' => "{$team2['wins']}-{$team2['losses']}",
                    'division' => $team1['conference'] . ' ' . $team1['division'],
                ],
            ];
        }

        return $arcs;
    }

    // ────────────────────────────────────────────
    // Arc management helpers
    // ────────────────────────────────────────────

    /**
     * Insert a new arc or update an existing one of the same type/team.
     */
    private function upsertArc(int $leagueId, int $seasonId, array $arc, int $week): void
    {
        // Check if we already have an active arc of this type for this team/player
        $sql = "SELECT id FROM narrative_arcs WHERE league_id = ? AND season_id = ? AND type = ? AND status = 'active'";
        $params = [$leagueId, $seasonId, $arc['type']];

        if (!empty($arc['team_id'])) {
            $sql .= " AND team_id = ?";
            $params[] = $arc['team_id'];
        }

        if (!empty($arc['player_id'])) {
            $sql .= " AND player_id = ?";
            $params[] = $arc['player_id'];
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $existing = $stmt->fetch();

        $dataJson = json_encode($arc['data'] ?? []);

        if ($existing) {
            // Update the existing arc
            $this->db->prepare(
                "UPDATE narrative_arcs SET title = ?, description = ?, data = ?, metadata = ? WHERE id = ?"
            )->execute([
                $arc['title'],
                $arc['description'],
                $dataJson,
                $dataJson, // keep metadata in sync
                $existing['id'],
            ]);
        } else {
            // Create a new arc
            $teamIds = !empty($arc['team_id']) ? json_encode([$arc['team_id']]) : null;
            $playerIds = !empty($arc['player_id']) ? json_encode([$arc['player_id']]) : null;

            $this->db->prepare(
                "INSERT INTO narrative_arcs (league_id, season_id, type, title, description, status, team_id, player_id, team_ids, player_ids, started_week, data, metadata)
                 VALUES (?, ?, ?, ?, ?, 'active', ?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                $leagueId, $seasonId,
                $arc['type'], $arc['title'], $arc['description'],
                $arc['team_id'] ?? null,
                $arc['player_id'] ?? null,
                $teamIds,
                $playerIds,
                $week,
                $dataJson,
                $dataJson,
            ]);
        }
    }

    /**
     * Expire arcs whose conditions are no longer met.
     */
    private function expireStaleArcs(int $leagueId, int $week): void
    {
        $activeArcs = $this->getActiveArcs($leagueId);

        foreach ($activeArcs as $arc) {
            $shouldExpire = false;

            switch ($arc['type']) {
                case 'winning_streak':
                case 'losing_streak':
                    // Check if the team is still on the streak
                    if ($arc['team_id']) {
                        $team = $this->getTeam($arc['team_id']);
                        if ($team) {
                            $streak = $team['streak'] ?? '';
                            $expectedPrefix = $arc['type'] === 'winning_streak' ? 'W' : 'L';
                            if (!str_starts_with($streak, $expectedPrefix) || (int) substr($streak, 1) < 3) {
                                $shouldExpire = true;
                            }
                        }
                    }
                    break;

                case 'coach_hot_seat':
                    // Check if job security has improved
                    $coachId = $arc['data']['coach_id'] ?? null;
                    if ($coachId) {
                        $stmt = $this->db->prepare("SELECT job_security FROM coaches WHERE id = ?");
                        $stmt->execute([$coachId]);
                        $coach = $stmt->fetch();
                        if ($coach && $coach['job_security'] > 50) {
                            $shouldExpire = true;
                        }
                    }
                    break;

                case 'surprise_contender':
                    // Check if team still has winning record
                    if ($arc['team_id']) {
                        $team = $this->getTeam($arc['team_id']);
                        if ($team && $team['wins'] <= $team['losses']) {
                            $shouldExpire = true;
                        }
                    }
                    break;
            }

            // Expire arcs that are too old (more than 8 weeks without update)
            if ($week - $arc['started_week'] > 8 && in_array($arc['type'], ['comeback_story', 'rivalry_intensifying'])) {
                $shouldExpire = true;
            }

            if ($shouldExpire) {
                $this->db->prepare(
                    "UPDATE narrative_arcs SET status = 'expired', resolved_week = ? WHERE id = ?"
                )->execute([$week, $arc['id']]);
            }
        }
    }

    // ────────────────────────────────────────────
    // Data helpers
    // ────────────────────────────────────────────

    private function getTeam(int $teamId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM teams WHERE id = ?");
        $stmt->execute([$teamId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get a team's game results (win/loss) for the first N games of the season.
     */
    private function getTeamGameResults(int $teamId, int $leagueId, int $limit): array
    {
        $stmt = $this->db->prepare(
            "SELECT
                g.id, g.week,
                g.home_team_id, g.away_team_id,
                g.home_score, g.away_score,
                CASE
                    WHEN g.home_team_id = ? AND g.home_score > g.away_score THEN 1
                    WHEN g.away_team_id = ? AND g.away_score > g.home_score THEN 1
                    ELSE 0
                END as won
             FROM games g
             WHERE g.league_id = ?
               AND (g.home_team_id = ? OR g.away_team_id = ?)
               AND g.is_simulated = 1
             ORDER BY g.week ASC
             LIMIT ?"
        );
        $stmt->execute([$teamId, $teamId, $leagueId, $teamId, $teamId, $limit]);
        return $stmt->fetchAll();
    }
}
