<?php

namespace App\Services;

use App\Database\Connection;

class OffseasonEngine
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
    }

    /**
     * Process full offseason flow:
     * 1. Season awards
     * 2. Player aging/progression/regression
     * 3. Contract expirations → free agency
     * 4. Coaching staff changes
     * 5. Generate draft class
     * 6. Prepare for next season
     */
    public function processOffseason(int $leagueId, int $seasonYear): array
    {
        // 1. Season Awards
        $awards = $this->calculateAwards($leagueId, $seasonYear);

        // Generate narrative coverage for awards
        if (!empty($awards) && class_exists('App\\Services\\NarrativeEngine')) {
            try {
                $seasonStmt = $this->db->prepare(
                    "SELECT id FROM seasons WHERE league_id = ? AND is_current = 1 LIMIT 1"
                );
                $seasonStmt->execute([$leagueId]);
                $seasonRow = $seasonStmt->fetch();
                $seasonId = $seasonRow ? (int) $seasonRow['id'] : 0;

                $narrativeEngine = new NarrativeEngine();
                $narrativeEngine->generateAwardsCoverage($leagueId, $seasonId, $awards);
            } catch (\Throwable $e) {
                error_log("NarrativeEngine awards coverage error: " . $e->getMessage());
            }
        }

        // 2. Player aging & development
        $devChanges = $this->processPlayerDevelopment($leagueId);
        $improved = array_filter($devChanges, fn($c) => $c['type'] === 'improved');
        $declined = array_filter($devChanges, fn($c) => $c['type'] === 'declined');
        $retired = array_filter($devChanges, fn($c) => $c['type'] === 'retired');

        // 3. Contract expirations
        $expiredContracts = $this->processContractExpirations($leagueId);

        // 4. Coach career tracking
        $this->recordCoachHistory($leagueId, $seasonYear);

        // 5. Coaching staff changes
        $staffEngine = new CoachingStaffEngine();
        $staffChanges = $staffEngine->processOffseason($leagueId);

        // 6. Recalculate draft order based on final standings (worst record = #1 pick)
        $draftEngine = new DraftEngine();
        $draftEngine->recalculateDraftOrder($leagueId);

        // 7. Generate draft class for next season
        $classId = $draftEngine->generateDraftClass($leagueId, $seasonYear + 1);
        $draftClassSize = 0;
        if ($classId) {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM draft_prospects WHERE draft_class_id = ?");
            $stmt->execute([$classId]);
            $draftClassSize = (int) $stmt->fetchColumn();
        }

        // 7. Reset team records for new season
        $this->resetTeamRecords($leagueId);

        // 8. Create new season
        $newSeasonId = $this->createNewSeason($leagueId, $seasonYear + 1);

        // 9. Generate schedule for new season
        $scheduleGames = $this->generateNewSeasonSchedule($leagueId, $newSeasonId);

        // 10. Generate free agents for new season
        $freeAgentsGenerated = $this->generateFreeAgents($leagueId);

        // 11. Update legacy scores
        $this->updateLegacyScores($leagueId);

        return [
            'awards' => $awards,
            'development' => [
                'improved' => array_values($improved),
                'declined' => array_values($declined),
                'retired' => array_values($retired),
            ],
            'contracts_expired' => $expiredContracts,
            'draft_class_size' => $draftClassSize,
            'schedule_games' => $scheduleGames,
            'free_agents_generated' => $freeAgentsGenerated,
            'new_season_year' => $seasonYear + 1,
            'new_season_id' => $newSeasonId,
            'staff_changes' => count($staffChanges),
        ];
    }

    /**
     * Calculate end-of-season awards.
     */
    private function calculateAwards(int $leagueId, int $seasonYear): array
    {
        $awards = [];

        // MVP — highest combined passing + rushing yards QB, or highest rushing RB
        $stmt = $this->db->prepare(
            "SELECT p.id, p.first_name, p.last_name, p.position, t.abbreviation,
                    SUM(gs.pass_yards) as total_pass_yards,
                    SUM(gs.rush_yards) as total_rush_yards,
                    SUM(gs.pass_tds) + SUM(gs.rush_tds) as total_tds
             FROM game_stats gs
             JOIN players p ON gs.player_id = p.id
             JOIN teams t ON p.team_id = t.id
             JOIN games g ON gs.game_id = g.id
             WHERE g.league_id = ? AND p.position = 'QB'
             GROUP BY p.id
             ORDER BY (SUM(gs.pass_yards) + SUM(gs.rush_yards) + SUM(gs.pass_tds) * 100 + SUM(gs.rush_tds) * 100) DESC
             LIMIT 1"
        );
        $stmt->execute([$leagueId]);
        $mvp = $stmt->fetch();

        if ($mvp) {
            $this->saveAward($leagueId, $seasonYear, 'MVP', 'player', $mvp['id'],
                json_encode(['pass_yards' => $mvp['total_pass_yards'], 'tds' => $mvp['total_tds']]));
            $awards[] = ['type' => 'MVP', 'winner' => $mvp['first_name'] . ' ' . $mvp['last_name']];
        }

        // Offensive Player of Year
        $stmt = $this->db->prepare(
            "SELECT p.id, p.first_name, p.last_name, p.position,
                    SUM(gs.pass_yards) + SUM(gs.rush_yards) + SUM(gs.rec_yards) as total_yards,
                    SUM(gs.pass_tds) + SUM(gs.rush_tds) + SUM(gs.rec_tds) as total_tds
             FROM game_stats gs
             JOIN players p ON gs.player_id = p.id
             JOIN games g ON gs.game_id = g.id
             WHERE g.league_id = ? AND p.position IN ('QB', 'RB', 'WR', 'TE')
             GROUP BY p.id
             ORDER BY total_yards DESC
             LIMIT 1"
        );
        $stmt->execute([$leagueId]);
        $opoy = $stmt->fetch();
        if ($opoy) {
            $this->saveAward($leagueId, $seasonYear, 'Offensive Player of the Year', 'player', $opoy['id'],
                json_encode(['yards' => $opoy['total_yards'], 'tds' => $opoy['total_tds']]));
            $awards[] = ['type' => 'OPOY', 'winner' => $opoy['first_name'] . ' ' . $opoy['last_name']];
        }

        // Defensive Player of Year
        $stmt = $this->db->prepare(
            "SELECT p.id, p.first_name, p.last_name, p.position,
                    SUM(gs.tackles) as total_tackles, SUM(gs.sacks) as total_sacks,
                    SUM(gs.interceptions_def) as total_ints
             FROM game_stats gs
             JOIN players p ON gs.player_id = p.id
             JOIN games g ON gs.game_id = g.id
             WHERE g.league_id = ? AND p.position IN ('DE', 'DT', 'LB', 'CB', 'S')
             GROUP BY p.id
             ORDER BY (SUM(gs.tackles) + SUM(gs.sacks) * 10 + SUM(gs.interceptions_def) * 15) DESC
             LIMIT 1"
        );
        $stmt->execute([$leagueId]);
        $dpoy = $stmt->fetch();
        if ($dpoy) {
            $this->saveAward($leagueId, $seasonYear, 'Defensive Player of the Year', 'player', $dpoy['id'],
                json_encode(['tackles' => $dpoy['total_tackles'], 'sacks' => $dpoy['total_sacks'], 'ints' => $dpoy['total_ints']]));
            $awards[] = ['type' => 'DPOY', 'winner' => $dpoy['first_name'] . ' ' . $dpoy['last_name']];
        }

        // Coach of the Year — best record from team that was below average rating
        $stmt = $this->db->prepare(
            "SELECT c.id, c.name, t.wins, t.losses, t.overall_rating
             FROM coaches c
             JOIN teams t ON c.team_id = t.id
             WHERE c.league_id = ? AND t.overall_rating < 78
             ORDER BY t.wins DESC, (t.points_for - t.points_against) DESC
             LIMIT 1"
        );
        $stmt->execute([$leagueId]);
        $coty = $stmt->fetch();
        if ($coty) {
            $this->saveAward($leagueId, $seasonYear, 'Coach of the Year', 'coach', $coty['id'],
                json_encode(['wins' => $coty['wins'], 'losses' => $coty['losses']]));
            $awards[] = ['type' => 'COTY', 'winner' => $coty['name']];
        }

        return $awards;
    }

    /**
     * Age players, apply growth/regression.
     */
    private function processPlayerDevelopment(int $leagueId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM players WHERE league_id = ? AND status IN ('active', 'practice_squad')"
        );
        $stmt->execute([$leagueId]);
        $players = $stmt->fetchAll();
        $changes = [];

        foreach ($players as $p) {
            $newAge = $p['age'] + 1;
            $ratingChange = 0;

            // Growth for young players
            if ($newAge <= 26) {
                $ratingChange = match ($p['potential']) {
                    'elite' => mt_rand(2, 5),
                    'high' => mt_rand(1, 3),
                    'average' => mt_rand(0, 2),
                    'limited' => mt_rand(-1, 1),
                    default => mt_rand(0, 1),
                };
            }
            // Prime years (27-29): stable
            elseif ($newAge <= 29) {
                $ratingChange = mt_rand(-1, 1);
            }
            // Decline (30+)
            else {
                $ratingChange = match (true) {
                    $newAge >= 35 => mt_rand(-5, -2),
                    $newAge >= 33 => mt_rand(-4, -1),
                    $newAge >= 30 => mt_rand(-2, 0),
                    default => 0,
                };

                // Retirement chance
                if ($newAge >= 34 && mt_rand(1, 100) <= ($newAge - 30) * 10) {
                    $this->db->prepare("UPDATE players SET status = 'retired' WHERE id = ?")->execute([$p['id']]);
                    $changes[] = ['type' => 'retired', 'player_id' => $p['id'], 'name' => $p['first_name'] . ' ' . $p['last_name']];
                    continue;
                }
            }

            $newRating = max(40, min(99, $p['overall_rating'] + $ratingChange));

            // Also adjust individual attributes proportionally
            $attrUpdate = '';
            $attrValues = [];
            if ($ratingChange !== 0) {
                $attrCols = ['speed','strength','awareness','acceleration','agility',
                    'throw_accuracy_short','throw_accuracy_mid','throw_accuracy_deep','throw_power',
                    'bc_vision','break_tackle','catching','short_route_running','medium_route_running',
                    'deep_route_running','pass_block','run_block','block_shedding','finesse_moves',
                    'power_moves','man_coverage','zone_coverage','press','play_recognition',
                    'pursuit','tackle','hit_power','kick_accuracy','kick_power'];
                $sets = [];
                foreach ($attrCols as $col) {
                    $val = $p[$col] ?? null;
                    if ($val !== null && (int) $val > 0) {
                        // Each attribute shifts by the rating change ± small random noise
                        $attrShift = $ratingChange + mt_rand(-1, 1);
                        $newVal = max(30, min(99, (int) $val + $attrShift));
                        $sets[] = "{$col} = {$newVal}";
                    }
                }
                if (!empty($sets)) {
                    $attrUpdate = ', ' . implode(', ', $sets);
                }
            }

            $this->db->exec(
                "UPDATE players SET age = {$newAge}, overall_rating = {$newRating}{$attrUpdate} WHERE id = {$p['id']}"
            );

            if ($ratingChange !== 0) {
                $changes[] = [
                    'type' => $ratingChange > 0 ? 'improved' : 'declined',
                    'player_id' => $p['id'],
                    'name' => $p['first_name'] . ' ' . $p['last_name'],
                    'change' => $ratingChange,
                ];
            }
        }

        return $changes;
    }

    /**
     * Expire contracts and release players to free agency.
     */
    private function processContractExpirations(int $leagueId): int
    {
        $stmt = $this->db->prepare(
            "SELECT c.*, p.team_id FROM contracts c
             JOIN players p ON c.player_id = p.id
             WHERE p.league_id = ? AND c.status = 'active'"
        );
        $stmt->execute([$leagueId]);
        $contracts = $stmt->fetchAll();
        $expired = 0;

        $faEngine = new FreeAgencyEngine();

        foreach ($contracts as $c) {
            $yearsLeft = $c['years_remaining'] - 1;
            if ($yearsLeft <= 0) {
                // Contract expired
                $this->db->prepare("UPDATE contracts SET status = 'expired', years_remaining = 0 WHERE id = ?")
                    ->execute([$c['id']]);

                // Check if player qualifies as RFA (years_pro <= 3)
                $stmtP = $this->db->prepare("SELECT years_pro FROM players WHERE id = ?");
                $stmtP->execute([$c['player_id']]);
                $yearsPro = (int) ($stmtP->fetchColumn() ?: 0);

                if ($yearsPro > 0 && $yearsPro <= 3 && $c['team_id']) {
                    // Restricted free agent -- original team retains rights
                    $faEngine->releaseAsRestricted($leagueId, $c['player_id'], (int) $c['team_id']);
                } else {
                    $faEngine->releasePlayer($leagueId, $c['player_id']);
                }
                $expired++;
            } else {
                $this->db->prepare("UPDATE contracts SET years_remaining = ? WHERE id = ?")
                    ->execute([$yearsLeft, $c['id']]);
            }
        }

        return $expired;
    }

    /**
     * Record coach season history.
     */
    private function recordCoachHistory(int $leagueId, int $seasonYear): void
    {
        $stmt = $this->db->prepare(
            "SELECT c.*, t.wins, t.losses FROM coaches c
             JOIN teams t ON c.team_id = t.id
             WHERE c.league_id = ?"
        );
        $stmt->execute([$leagueId]);
        $coaches = $stmt->fetchAll();

        foreach ($coaches as $c) {
            // Check if made playoffs (top 7 in conference by wins)
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM teams
                 WHERE league_id = ? AND conference = (SELECT conference FROM teams WHERE id = ?)
                 AND wins > (SELECT wins FROM teams WHERE id = ?)"
            );
            $stmt->execute([$leagueId, $c['team_id'], $c['team_id']]);
            $betterTeams = (int) $stmt->fetchColumn();
            $madePlayoffs = $betterTeams < 7 ? 1 : 0;

            $this->db->prepare(
                "INSERT INTO coach_history (coach_id, team_id, league_id, season_year, wins, losses, made_playoffs, championship, final_influence, final_job_security, fired)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, 0)"
            )->execute([
                $c['id'], $c['team_id'], $leagueId, $seasonYear,
                $c['wins'] ?? 0, $c['losses'] ?? 0, $madePlayoffs,
                $c['influence'], $c['job_security'],
            ]);
        }
    }

    private function resetTeamRecords(int $leagueId): void
    {
        $this->db->prepare(
            "UPDATE teams SET wins = 0, losses = 0, ties = 0, points_for = 0, points_against = 0, streak = '' WHERE league_id = ?"
        )->execute([$leagueId]);
    }

    private function createNewSeason(int $leagueId, int $year): int
    {
        // Deactivate current season
        $this->db->prepare("UPDATE seasons SET is_current = 0 WHERE league_id = ?")->execute([$leagueId]);

        // Create new season
        $this->db->prepare(
            "INSERT INTO seasons (league_id, year, is_current, created_at) VALUES (?, ?, 1, ?)"
        )->execute([$leagueId, $year, date('Y-m-d H:i:s')]);
        $seasonId = (int) $this->db->lastInsertId();

        // Update league
        $this->db->prepare(
            "UPDATE leagues SET season_year = ?, current_week = 0, phase = 'offseason' WHERE id = ?"
        )->execute([$year, $leagueId]);

        return $seasonId;
    }

    private function updateLegacyScores(int $leagueId): void
    {
        $stmt = $this->db->prepare("SELECT id FROM coaches WHERE league_id = ?");
        $stmt->execute([$leagueId]);
        $coachIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($coachIds as $coachId) {
            // Get career totals from history
            $stmt = $this->db->prepare(
                "SELECT SUM(wins) as tw, SUM(losses) as tl,
                        SUM(made_playoffs) as tp, SUM(championship) as tc,
                        COUNT(*) as seasons
                 FROM coach_history WHERE coach_id = ?"
            );
            $stmt->execute([$coachId]);
            $totals = $stmt->fetch();

            $score = ($totals['tw'] ?? 0) * 5
                + ($totals['tp'] ?? 0) * 20
                + ($totals['tc'] ?? 0) * 100
                + ($totals['seasons'] ?? 0) * 10;

            // Upsert legacy score
            $stmt = $this->db->prepare("SELECT id FROM legacy_scores WHERE coach_id = ?");
            $stmt->execute([$coachId]);
            $existing = $stmt->fetch();

            if ($existing) {
                $this->db->prepare(
                    "UPDATE legacy_scores SET total_score = ?, total_wins = ?, total_losses = ?,
                     playoff_appearances = ?, championships = ?, seasons_completed = ? WHERE coach_id = ?"
                )->execute([
                    $score, $totals['tw'] ?? 0, $totals['tl'] ?? 0,
                    $totals['tp'] ?? 0, $totals['tc'] ?? 0, $totals['seasons'] ?? 0,
                    $coachId,
                ]);
            } else {
                $this->db->prepare(
                    "INSERT INTO legacy_scores (coach_id, total_score, total_wins, total_losses, playoff_appearances, championships, seasons_completed)
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                )->execute([
                    $coachId, $score, $totals['tw'] ?? 0, $totals['tl'] ?? 0,
                    $totals['tp'] ?? 0, $totals['tc'] ?? 0, $totals['seasons'] ?? 0,
                ]);
            }
        }
    }

    private function generateNewSeasonSchedule(int $leagueId, int $newSeasonId): int
    {
        $stmt = $this->db->prepare(
            "SELECT id, city, name, abbreviation, conference, division FROM teams WHERE league_id = ?"
        );
        $stmt->execute([$leagueId]);
        $teams = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (count($teams) < 2) {
            return 0;
        }

        $schedGen = new ScheduleGenerator();
        $schedule = $schedGen->generate($leagueId, $newSeasonId, $teams);

        foreach ($schedule as $g) {
            $cols = implode(', ', array_keys($g));
            $placeholders = implode(', ', array_fill(0, count($g), '?'));
            $stmt = $this->db->prepare("INSERT INTO games ({$cols}) VALUES ({$placeholders})");
            $stmt->execute(array_values($g));
        }

        return count($schedule);
    }

    private function generateFreeAgents(int $leagueId): int
    {
        $generator = new PlayerGenerator();
        $count = 0;

        $positionCounts = [
            'QB' => 3, 'RB' => 5, 'WR' => 8, 'TE' => 3,
            'OT' => 4, 'OG' => 4, 'C' => 2,
            'DE' => 4, 'DT' => 3, 'LB' => 5,
            'CB' => 4, 'S' => 3, 'K' => 2, 'P' => 2,
        ];

        $firstNames = [
            'Marcus', 'Jaylen', 'DeShawn', 'Tyler', 'Caleb', 'Brandon', 'Trevon', 'Malik',
            'Darius', 'Xavier', 'Antonio', 'Cameron', 'Isaiah', 'Jalen', 'Terrell', 'Davon',
            'Khalil', 'Jamal', 'Derek', 'Corey', 'Travis', 'Jordan', 'Andre', 'Damien',
            'Mitchell', 'Ryan', 'Jake', 'Cody', 'Hunter', 'Austin', 'Cole', 'Garrett',
            'Carson', 'Connor', 'Nolan', 'Blake', 'Chase', 'Wyatt', 'Luke', 'Grant',
            'Dante', 'Keith', 'Jerome', 'Rodney', 'Cedric', 'Troy', 'Darren', 'Preston',
            'Elijah', 'Micah', 'Josiah', 'Ezekiel', 'Aaron', 'Nathan', 'Ethan', 'Amari',
        ];
        $lastNames = [
            'Webb', 'Jackson', 'Rodriguez', 'Patterson', 'Williams', 'Brown', 'Davis', 'Johnson',
            'Wilson', 'Thompson', 'Anderson', 'Taylor', 'Thomas', 'Harris', 'Clark', 'Lewis',
            'Robinson', 'Walker', 'Young', 'Allen', 'King', 'Wright', 'Scott', 'Green',
            'Baker', 'Adams', 'Nelson', 'Hill', 'Campbell', 'Mitchell', 'Roberts', 'Carter',
            'Phillips', 'Evans', 'Turner', 'Torres', 'Parker', 'Collins', 'Edwards', 'Stewart',
            'Cooper', 'Reed', 'Bailey', 'Bell', 'Howard', 'Ward', 'Cox', 'Watson',
            'Brooks', 'Bennett', 'Gray', 'James', 'Hughes', 'Price', 'Long', 'Foster',
        ];

        $rosterPool = $generator->generateForTeam(0, $leagueId);
        $poolByPosition = [];
        foreach ($rosterPool as $p) {
            $poolByPosition[$p['position']][] = $p;
        }

        foreach ($positionCounts as $pos => $num) {
            for ($i = 0; $i < $num; $i++) {
                if (!empty($poolByPosition[$pos])) {
                    $template = $poolByPosition[$pos][array_rand($poolByPosition[$pos])];
                } else {
                    $template = $rosterPool[array_rand($rosterPool)];
                    $template['position'] = $pos;
                }

                $overall = mt_rand(55, 82);
                $age = mt_rand(23, 32);

                $template['team_id'] = null;
                $template['first_name'] = $firstNames[array_rand($firstNames)];
                $template['last_name'] = $lastNames[array_rand($lastNames)];
                $template['overall_rating'] = $overall;
                $template['status'] = 'free_agent';
                $template['age'] = $age;
                $template['experience'] = max(0, $age - 22);
                $template['years_pro'] = max(0, $age - 22);
                $template['jersey_number'] = mt_rand(1, 99);
                $template['birthdate'] = sprintf('%04d-%02d-%02d', date('Y') - $age, mt_rand(1, 12), mt_rand(1, 28));
                $template['created_at'] = date('Y-m-d H:i:s');

                $cols = implode(', ', array_keys($template));
                $placeholders = implode(', ', array_fill(0, count($template), '?'));
                $stmt = $this->db->prepare("INSERT INTO players ({$cols}) VALUES ({$placeholders})");
                $stmt->execute(array_values($template));
                $playerId = (int) $this->db->lastInsertId();

                $marketValue = $this->calculateFreeAgentMarketValue($pos, $overall, $age);

                $this->db->prepare(
                    "INSERT INTO free_agents (league_id, player_id, asking_salary, market_value, status, released_at)
                     VALUES (?, ?, ?, ?, 'available', ?)"
                )->execute([$leagueId, $playerId, $marketValue, $marketValue, date('Y-m-d H:i:s')]);

                $count++;
            }
        }

        return $count;
    }

    private function calculateFreeAgentMarketValue(string $position, int $overall, int $age): int
    {
        $base = 500000;
        $ratingBonus = pow($overall / 100, 2) * 15000000;
        $positionMultiplier = match ($position) {
            'QB' => 2.5, 'DE' => 1.4, 'CB' => 1.3, 'WR' => 1.3, 'OT' => 1.2,
            'LB' => 1.1, 'DT' => 1.1, 'RB' => 1.0, 'TE' => 1.0, 'S' => 1.0,
            'OG' => 0.9, 'C' => 0.9, 'K' => 0.5, 'P' => 0.4, 'LS' => 0.3,
            default => 1.0,
        };
        $ageFactor = $age <= 26 ? 1.1 : ($age >= 31 ? 0.7 : 1.0);
        return max($base, (int) ($ratingBonus * $positionMultiplier * $ageFactor));
    }

    private function saveAward(int $leagueId, int $year, string $type, string $winnerType, int $winnerId, string $stats): void
    {
        $this->db->prepare(
            "INSERT INTO season_awards (league_id, season_year, award_type, winner_type, winner_id, stats)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([$leagueId, $year, $type, $winnerType, $winnerId, $stats]);
    }

    /**
     * Get season awards for the most recent completed season.
     */
    public function getAwards(int $leagueId): array
    {
        // Find the most recent season year that has awards
        $stmt = $this->db->prepare(
            "SELECT sa.award_type, sa.winner_type, sa.winner_id, sa.stats, sa.season_year,
                    CASE WHEN sa.winner_type = 'player'
                        THEN (SELECT p.first_name || ' ' || p.last_name FROM players p WHERE p.id = sa.winner_id)
                        ELSE NULL END AS player_name,
                    CASE WHEN sa.winner_type = 'coach'
                        THEN (SELECT c.name FROM coaches c WHERE c.id = sa.winner_id)
                        ELSE NULL END AS coach_name
             FROM season_awards sa
             WHERE sa.league_id = ?
             ORDER BY sa.season_year DESC, sa.id ASC"
        );
        $stmt->execute([$leagueId]);
        return $stmt->fetchAll();
    }

    /**
     * Get coach legacy / career stats.
     */
    public function getCoachLegacy(int $coachId): ?array
    {
        // Get legacy score
        $stmt = $this->db->prepare("SELECT * FROM legacy_scores WHERE coach_id = ?");
        $stmt->execute([$coachId]);
        $legacy = $stmt->fetch();

        // Get career history
        $stmt = $this->db->prepare(
            "SELECT ch.*, t.city, t.name as team_name, t.abbreviation
             FROM coach_history ch
             JOIN teams t ON ch.team_id = t.id
             WHERE ch.coach_id = ?
             ORDER BY ch.season_year DESC"
        );
        $stmt->execute([$coachId]);
        $history = $stmt->fetchAll();

        if (!$legacy && empty($history)) {
            return null;
        }

        return [
            'legacy' => $legacy ?: [
                'total_score' => 0, 'total_wins' => 0, 'total_losses' => 0,
                'championships' => 0, 'playoff_appearances' => 0, 'seasons_completed' => 0,
            ],
            'history' => $history,
        ];
    }
}
