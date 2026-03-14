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
        $summary = [];

        // 1. Season Awards
        $awards = $this->calculateAwards($leagueId, $seasonYear);
        $summary['awards'] = $awards;

        // 2. Player aging & development
        $devChanges = $this->processPlayerDevelopment($leagueId);
        $summary['player_changes'] = count($devChanges);
        $summary['retirements'] = count(array_filter($devChanges, fn($c) => $c['type'] === 'retired'));

        // 3. Contract expirations
        $expired = $this->processContractExpirations($leagueId);
        $summary['contracts_expired'] = $expired;

        // 4. Coach career tracking
        $this->recordCoachHistory($leagueId, $seasonYear);

        // 5. Coaching staff changes
        $staffEngine = new CoachingStaffEngine();
        $staffChanges = $staffEngine->processOffseason($leagueId);
        $summary['staff_changes'] = count($staffChanges);

        // 6. Generate draft class for next season
        $draftEngine = new DraftEngine();
        $classId = $draftEngine->generateDraftClass($leagueId, $seasonYear + 1);
        $summary['draft_class_id'] = $classId;

        // 7. Reset team records for new season
        $this->resetTeamRecords($leagueId);

        // 8. Create new season
        $newSeasonId = $this->createNewSeason($leagueId, $seasonYear + 1);
        $summary['new_season_id'] = $newSeasonId;
        $summary['new_year'] = $seasonYear + 1;

        // 9. Update legacy scores
        $this->updateLegacyScores($leagueId);

        return $summary;
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
            $this->db->prepare("UPDATE players SET age = ?, overall_rating = ? WHERE id = ?")
                ->execute([$newAge, $newRating, $p['id']]);

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
                // Contract expired — player goes to free agency
                $this->db->prepare("UPDATE contracts SET status = 'expired', years_remaining = 0 WHERE id = ?")
                    ->execute([$c['id']]);
                $faEngine->releasePlayer($leagueId, $c['player_id']);
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

    private function saveAward(int $leagueId, int $year, string $type, string $winnerType, int $winnerId, string $stats): void
    {
        $this->db->prepare(
            "INSERT INTO season_awards (league_id, season_year, award_type, winner_type, winner_id, stats)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([$leagueId, $year, $type, $winnerType, $winnerId, $stats]);
    }
}
