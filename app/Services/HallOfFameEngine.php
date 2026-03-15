<?php

namespace App\Services;

use App\Database\Connection;

/**
 * HallOfFameEngine — Evaluates retired players for Hall of Fame induction.
 *
 * Scoring system:
 *   - Awards: All-League 1st (15), All-League 2nd (8), Gridiron Classic (5), MVP (25), Championship (20)
 *   - Career stats: position-specific thresholds (10 pts each)
 *   - Score > 50 = Hall of Famer
 *   - Max 5 inductees per year, 1 year waiting period after retirement
 */
class HallOfFameEngine
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
        $this->ensureTable();
    }

    // ================================================================
    //  Table bootstrap (idempotent)
    // ================================================================

    private function ensureTable(): void
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS hall_of_fame (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            player_id INT NOT NULL,
            league_id INT NOT NULL,
            first_name VARCHAR(50) NOT NULL,
            last_name VARCHAR(50) NOT NULL,
            position VARCHAR(5) NOT NULL,
            inducted_year INT NOT NULL,
            career_years INT NOT NULL DEFAULT 0,
            career_stats TEXT NULL,
            peak_overall INT NOT NULL DEFAULT 0,
            all_league_first_count INT NOT NULL DEFAULT 0,
            all_league_second_count INT NOT NULL DEFAULT 0,
            gridiron_classic_count INT NOT NULL DEFAULT 0,
            championships INT NOT NULL DEFAULT 0,
            mvp_count INT NOT NULL DEFAULT 0,
            inducted_at DATETIME NOT NULL,
            FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
            FOREIGN KEY (league_id) REFERENCES leagues(id) ON DELETE CASCADE
        )");

        // Ensure index exists
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_hall_of_fame_league ON hall_of_fame(league_id)");
    }

    // ================================================================
    //  Public API
    // ================================================================

    /**
     * Evaluate a single retired player for HOF worthiness.
     *
     * @return array { eligible: bool, score: int, breakdown: array, inducted: bool }
     */
    public function evaluateForHOF(int $playerId, int $leagueId): array
    {
        $player = $this->getPlayer($playerId);
        if (!$player) {
            return ['eligible' => false, 'score' => 0, 'breakdown' => ['error' => 'Player not found'], 'inducted' => false];
        }

        // Already in HOF?
        $stmt = $this->db->prepare("SELECT id FROM hall_of_fame WHERE player_id = ? AND league_id = ?");
        $stmt->execute([$playerId, $leagueId]);
        if ($stmt->fetch()) {
            return ['eligible' => false, 'score' => 0, 'breakdown' => ['already_inducted' => true], 'inducted' => true];
        }

        $careerYears = max((int) ($player['years_pro'] ?? 0), (int) ($player['experience'] ?? 0));

        // Baseline: need 10+ years
        if ($careerYears < 10) {
            return [
                'eligible' => false,
                'score' => 0,
                'breakdown' => ['insufficient_career_length' => true, 'career_years' => $careerYears],
                'inducted' => false,
            ];
        }

        $score = 0;
        $breakdown = ['career_years' => $careerYears];

        // ── Awards ──────────────────────────────────────────────────
        $awards = $this->getPlayerAwards($playerId, $leagueId);

        $allProFirst = (int) ($awards['all_league_first'] ?? 0);
        $allProSecond = (int) ($awards['all_league_second'] ?? 0);
        $proBowl = (int) ($awards['gridiron_classic'] ?? 0);
        $mvpCount = (int) ($awards['mvp'] ?? 0);
        $championships = (int) ($awards['championship'] ?? 0);

        $awardPts = ($allProFirst * 15) + ($allProSecond * 8) + ($proBowl * 5) + ($mvpCount * 25) + ($championships * 20);
        $score += $awardPts;

        $breakdown['awards'] = [
            'all_league_first' => $allProFirst,
            'all_league_first_pts' => $allProFirst * 15,
            'all_league_second' => $allProSecond,
            'all_league_second_pts' => $allProSecond * 8,
            'gridiron_classic' => $proBowl,
            'gridiron_classic_pts' => $proBowl * 5,
            'mvp' => $mvpCount,
            'mvp_pts' => $mvpCount * 25,
            'championships' => $championships,
            'championship_pts' => $championships * 20,
            'total_award_pts' => $awardPts,
        ];

        // ── Peak OVR ────────────────────────────────────────────────
        $peakOvr = $this->getPeakOverall($playerId, $leagueId);
        $breakdown['peak_overall'] = $peakOvr;

        // ── Career Stats ────────────────────────────────────────────
        $careerStats = $this->getCareerStats($playerId, $leagueId);
        $statPts = $this->scorePositionStats($player['position'], $careerStats);
        $score += $statPts['total'];

        $breakdown['career_stats'] = $careerStats;
        $breakdown['stat_scoring'] = $statPts;

        // ── Final determination ─────────────────────────────────────
        $eligible = $score > 50;

        return [
            'eligible' => $eligible,
            'score' => $score,
            'breakdown' => $breakdown,
            'inducted' => false,
        ];
    }

    /**
     * Process HOF inductions for a given year during offseason.
     *
     * Finds retired players eligible for evaluation (retired at least 1 season ago),
     * scores them, and inducts up to 5 per year.
     *
     * @return array List of inductees with their evaluation data
     */
    public function processHOFInductions(int $leagueId, int $year): array
    {
        $eligible = $this->getHOFEligible($leagueId);
        $candidates = [];

        foreach ($eligible as $player) {
            // Must have been retired for at least 1 season
            // We check if the player was already retired before the current offseason year
            $retiredYear = $this->getRetiredYear($player['id'], $leagueId, $year);
            if ($retiredYear === null || ($year - $retiredYear) < 1) {
                continue;
            }

            $eval = $this->evaluateForHOF($player['id'], $leagueId);
            if ($eval['eligible']) {
                $eval['player'] = $player;
                $eval['retired_year'] = $retiredYear;
                $candidates[] = $eval;
            }
        }

        // Sort by score descending, take top 5
        usort($candidates, fn($a, $b) => $b['score'] <=> $a['score']);
        $inductees = array_slice($candidates, 0, 5);

        $results = [];
        foreach ($inductees as $candidate) {
            $player = $candidate['player'];
            $careerYears = $candidate['breakdown']['career_years'];
            $careerStats = $candidate['breakdown']['career_stats'] ?? [];
            $peakOvr = $candidate['breakdown']['peak_overall'] ?? (int) $player['overall_rating'];
            $awards = $candidate['breakdown']['awards'] ?? [];

            $stmt = $this->db->prepare(
                "INSERT INTO hall_of_fame
                    (player_id, league_id, first_name, last_name, position, inducted_year,
                     career_years, career_stats, peak_overall, all_league_first_count,
                     all_league_second_count, gridiron_classic_count, championships, mvp_count, inducted_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $player['id'],
                $leagueId,
                $player['first_name'],
                $player['last_name'],
                $player['position'],
                $year,
                $careerYears,
                json_encode($careerStats),
                $peakOvr,
                (int) ($awards['all_league_first'] ?? 0),
                (int) ($awards['all_league_second'] ?? 0),
                (int) ($awards['gridiron_classic'] ?? 0),
                (int) ($awards['championships'] ?? 0),
                (int) ($awards['mvp'] ?? 0),
                date('Y-m-d H:i:s'),
            ]);

            $results[] = [
                'id' => (int) $this->db->lastInsertId(),
                'player_id' => $player['id'],
                'name' => $player['first_name'] . ' ' . $player['last_name'],
                'position' => $player['position'],
                'score' => $candidate['score'],
                'career_years' => $careerYears,
                'peak_overall' => $peakOvr,
                'breakdown' => $candidate['breakdown'],
            ];
        }

        return $results;
    }

    /**
     * Return all Hall of Fame members for a league.
     */
    public function getHallOfFame(int $leagueId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM hall_of_fame WHERE league_id = ? ORDER BY inducted_year DESC, peak_overall DESC"
        );
        $stmt->execute([$leagueId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Decode career_stats JSON
        foreach ($rows as &$row) {
            $row['career_stats'] = json_decode($row['career_stats'] ?? '{}', true);
        }

        return $rows;
    }

    /**
     * Return retired players who have not yet been evaluated/inducted.
     */
    public function getHOFEligible(int $leagueId): array
    {
        $stmt = $this->db->prepare(
            "SELECT p.* FROM players p
             WHERE p.league_id = ?
               AND p.status = 'retired'
               AND p.id NOT IN (SELECT player_id FROM hall_of_fame WHERE league_id = ?)
             ORDER BY p.overall_rating DESC"
        );
        $stmt->execute([$leagueId, $leagueId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // ================================================================
    //  Private helpers
    // ================================================================

    private function getPlayer(int $playerId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM players WHERE id = ?");
        $stmt->execute([$playerId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Count awards from the season_awards table.
     */
    private function getPlayerAwards(int $playerId, int $leagueId): array
    {
        $stmt = $this->db->prepare(
            "SELECT award_type, COUNT(*) as cnt
             FROM season_awards
             WHERE league_id = ? AND winner_id = ? AND winner_type = 'player'
             GROUP BY award_type"
        );
        $stmt->execute([$leagueId, $playerId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $awards = [
            'all_league_first' => 0,
            'all_league_second' => 0,
            'gridiron_classic' => 0,
            'mvp' => 0,
            'championship' => 0,
        ];

        foreach ($rows as $row) {
            $type = strtolower($row['award_type']);
            if (str_contains($type, 'all_league_first') || str_contains($type, 'all-pro first') || str_contains($type, 'first_team_all_pro')) {
                $awards['all_league_first'] += (int) $row['cnt'];
            } elseif (str_contains($type, 'all_league_second') || str_contains($type, 'all-pro second') || str_contains($type, 'second_team_all_pro')) {
                $awards['all_league_second'] += (int) $row['cnt'];
            } elseif (str_contains($type, 'gridiron_classic') || str_contains($type, 'probowl')) {
                $awards['gridiron_classic'] += (int) $row['cnt'];
            } elseif ($type === 'mvp' || str_contains($type, 'most_valuable')) {
                $awards['mvp'] += (int) $row['cnt'];
            }
        }

        // Check championships via seasons table (player on champion team)
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM seasons s
             JOIN players p ON p.league_id = s.league_id
             WHERE s.league_id = ? AND s.champion_team_id IS NOT NULL
               AND EXISTS (
                   SELECT 1 FROM game_stats gs
                   JOIN games g ON g.id = gs.game_id
                   WHERE gs.player_id = ? AND gs.team_id = s.champion_team_id
                     AND g.season_id = s.id
               )"
        );
        $stmt->execute([$leagueId, $playerId]);
        $awards['championship'] = (int) $stmt->fetchColumn();

        return $awards;
    }

    /**
     * Get the peak overall rating for a player.
     * Checks current OVR and historical data if available.
     */
    private function getPeakOverall(int $playerId, int $leagueId): int
    {
        $player = $this->getPlayer($playerId);
        $peak = (int) ($player['overall_rating'] ?? 0);

        // Check historical_stats for any season data that might indicate peak
        // The player's current OVR may be their retired (declined) rating,
        // so we also check if there's a trajectory stored
        try {
            $stmt = $this->db->prepare(
                "SELECT MAX(overall_rating) as peak FROM (
                    SELECT overall_rating FROM players WHERE id = ?
                ) sub"
            );
            $stmt->execute([$playerId]);
            $row = $stmt->fetchColumn();
            if ($row && (int) $row > $peak) {
                $peak = (int) $row;
            }
        } catch (\Throwable $e) {
            // Table might not exist
        }

        // Estimate peak from years_pro and current rating
        // Retired players have declined, so estimate their peak was higher
        $age = (int) ($player['age'] ?? 30);
        $currentOvr = (int) ($player['overall_rating'] ?? 70);
        if ($age >= 34) {
            // Rough estimate: they lost ~2-3 pts per year after 30
            $declineYears = $age - 30;
            $estimatedPeak = min(99, $currentOvr + (int) ($declineYears * 2));
            if ($estimatedPeak > $peak) {
                $peak = $estimatedPeak;
            }
        }

        return $peak;
    }

    /**
     * Aggregate career stats from historical_stats + game_stats.
     */
    private function getCareerStats(int $playerId, int $leagueId): array
    {
        $stats = [
            'pass_yards' => 0, 'pass_tds' => 0, 'pass_completions' => 0, 'pass_attempts' => 0,
            'interceptions' => 0,
            'rush_yards' => 0, 'rush_tds' => 0, 'rush_attempts' => 0,
            'rec_yards' => 0, 'rec_tds' => 0, 'receptions' => 0,
            'tackles' => 0, 'sacks' => 0.0, 'interceptions_def' => 0,
            'forced_fumbles' => 0, 'fg_made' => 0, 'fg_attempts' => 0,
            'games_played' => 0,
        ];

        // Historical stats (includes synthetic pre-franchise data)
        try {
            $stmt = $this->db->prepare(
                "SELECT
                    SUM(pass_yards) as pass_yards, SUM(pass_tds) as pass_tds,
                    SUM(pass_completions) as pass_completions, SUM(pass_attempts) as pass_attempts,
                    SUM(interceptions) as interceptions,
                    SUM(rush_yards) as rush_yards, SUM(rush_tds) as rush_tds,
                    SUM(rush_attempts) as rush_attempts,
                    SUM(rec_yards) as rec_yards, SUM(rec_tds) as rec_tds,
                    SUM(receptions) as receptions,
                    SUM(tackles) as tackles, SUM(sacks) as sacks,
                    SUM(interceptions_def) as interceptions_def,
                    SUM(forced_fumbles) as forced_fumbles,
                    SUM(fg_made) as fg_made, SUM(fg_attempts) as fg_attempts,
                    SUM(games_played) as games_played
                 FROM historical_stats
                 WHERE player_id = ? AND league_id = ?"
            );
            $stmt->execute([$playerId, $leagueId]);
            $hist = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($hist) {
                foreach ($stats as $key => &$val) {
                    if (isset($hist[$key]) && $hist[$key] !== null) {
                        $val += $key === 'sacks' ? (float) $hist[$key] : (int) $hist[$key];
                    }
                }
                unset($val);
            }
        } catch (\Throwable $e) {
            // historical_stats table may not exist yet
        }

        // Also aggregate from game_stats for games played in this league
        try {
            $stmt = $this->db->prepare(
                "SELECT
                    SUM(gs.pass_yards) as pass_yards, SUM(gs.pass_tds) as pass_tds,
                    SUM(gs.pass_completions) as pass_completions, SUM(gs.pass_attempts) as pass_attempts,
                    SUM(gs.interceptions) as interceptions,
                    SUM(gs.rush_yards) as rush_yards, SUM(gs.rush_tds) as rush_tds,
                    SUM(gs.rush_attempts) as rush_attempts,
                    SUM(gs.rec_yards) as rec_yards, SUM(gs.rec_tds) as rec_tds,
                    SUM(gs.receptions) as receptions,
                    SUM(gs.tackles) as tackles, SUM(gs.sacks) as sacks,
                    SUM(gs.interceptions_def) as interceptions_def,
                    SUM(gs.forced_fumbles) as forced_fumbles,
                    SUM(gs.fg_made) as fg_made, SUM(gs.fg_attempts) as fg_attempts,
                    COUNT(DISTINCT gs.game_id) as games_played
                 FROM game_stats gs
                 JOIN games g ON g.id = gs.game_id
                 WHERE gs.player_id = ? AND g.league_id = ?
                   AND g.season_id NOT IN (
                       SELECT DISTINCT season_id FROM historical_stats hs
                       JOIN seasons s ON s.year = hs.season_year AND s.league_id = hs.league_id
                       WHERE hs.player_id = ? AND hs.league_id = ?
                   )"
            );
            $stmt->execute([$playerId, $leagueId, $playerId, $leagueId]);
            $gs = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($gs) {
                foreach ($stats as $key => &$val) {
                    if (isset($gs[$key]) && $gs[$key] !== null) {
                        $val += $key === 'sacks' ? (float) $gs[$key] : (int) $gs[$key];
                    }
                }
                unset($val);
            }
        } catch (\Throwable $e) {
            // game_stats aggregation fallback — just use historical
        }

        // Compute passer rating if applicable
        if ($stats['pass_attempts'] > 0) {
            $stats['passer_rating'] = $this->calculatePasserRating(
                $stats['pass_completions'],
                $stats['pass_attempts'],
                $stats['pass_yards'],
                $stats['pass_tds'],
                $stats['interceptions']
            );
        }

        return $stats;
    }

    /**
     * Score career stats based on position-specific thresholds.
     * Each threshold met = 10 pts.
     */
    private function scorePositionStats(string $position, array $stats): array
    {
        $pts = 0;
        $details = [];

        switch ($position) {
            case 'QB':
                if (($stats['pass_yards'] ?? 0) >= 30000) {
                    $pts += 10;
                    $details[] = '30,000+ pass yards';
                }
                if (($stats['pass_tds'] ?? 0) >= 200) {
                    $pts += 10;
                    $details[] = '200+ pass TDs';
                }
                if (isset($stats['passer_rating']) && $stats['passer_rating'] >= 90.0) {
                    $pts += 10;
                    $details[] = 'Passer rating > 90';
                }
                break;

            case 'RB':
            case 'FB':
                if (($stats['rush_yards'] ?? 0) >= 8000) {
                    $pts += 10;
                    $details[] = '8,000+ rush yards';
                }
                if (($stats['rush_tds'] ?? 0) >= 50) {
                    $pts += 10;
                    $details[] = '50+ rush TDs';
                }
                break;

            case 'WR':
            case 'TE':
                if (($stats['rec_yards'] ?? 0) >= 8000) {
                    $pts += 10;
                    $details[] = '8,000+ rec yards';
                }
                if (($stats['rec_tds'] ?? 0) >= 60) {
                    $pts += 10;
                    $details[] = '60+ rec TDs';
                }
                break;

            case 'DE':
            case 'DT':
                if (($stats['sacks'] ?? 0) >= 70) {
                    $pts += 10;
                    $details[] = '70+ sacks';
                }
                break;

            case 'LB':
                if (($stats['tackles'] ?? 0) >= 800) {
                    $pts += 10;
                    $details[] = '800+ tackles';
                }
                break;

            case 'CB':
            case 'S':
                if (($stats['interceptions_def'] ?? 0) >= 30) {
                    $pts += 10;
                    $details[] = '30+ interceptions';
                }
                break;
        }

        return [
            'total' => $pts,
            'thresholds_met' => $details,
        ];
    }

    /**
     * Calculate NFL passer rating.
     */
    private function calculatePasserRating(int $comp, int $att, int $yards, int $tds, int $ints): float
    {
        if ($att === 0) return 0.0;

        $a = max(0, min(2.375, (($comp / $att) - 0.3) * 5));
        $b = max(0, min(2.375, (($yards / $att) - 3) * 0.25));
        $c = max(0, min(2.375, ($tds / $att) * 20));
        $d = max(0, min(2.375, 2.375 - (($ints / $att) * 25)));

        return round((($a + $b + $c + $d) / 6) * 100, 1);
    }

    /**
     * Determine when a player retired (approximate by season year).
     * Looks at the last season they had game stats, then assumes
     * they retired after that season.
     */
    private function getRetiredYear(int $playerId, int $leagueId, int $currentYear): ?int
    {
        // Check historical_stats for last season played
        try {
            $stmt = $this->db->prepare(
                "SELECT MAX(season_year) FROM historical_stats WHERE player_id = ? AND league_id = ?"
            );
            $stmt->execute([$playerId, $leagueId]);
            $lastHistYear = $stmt->fetchColumn();
            if ($lastHistYear) {
                return (int) $lastHistYear;
            }
        } catch (\Throwable $e) {
            // table may not exist
        }

        // Check game_stats for last game season
        try {
            $stmt = $this->db->prepare(
                "SELECT MAX(s.year) FROM game_stats gs
                 JOIN games g ON g.id = gs.game_id
                 JOIN seasons s ON s.id = g.season_id
                 WHERE gs.player_id = ? AND g.league_id = ?"
            );
            $stmt->execute([$playerId, $leagueId]);
            $lastGameYear = $stmt->fetchColumn();
            if ($lastGameYear) {
                return (int) $lastGameYear;
            }
        } catch (\Throwable $e) {
            // fallback
        }

        // If we can't determine retirement year, assume they retired last year
        return $currentYear - 1;
    }
}
