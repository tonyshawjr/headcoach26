<?php

namespace App\Services;

use App\Database\Connection;

/**
 * HistoricalStatsGenerator — Creates realistic career history for all players.
 *
 * For a player with years_pro = 8 in season 2026, generates stats for
 * 2018-2025 based on their position, age, and estimated OVR trajectory.
 *
 * The OVR trajectory is reverse-engineered:
 *   - Players peak at 27-29, develop before that, decline after
 *   - Rookie year OVR is lower (scaled by development potential)
 *   - Each season's stats match what a player at that OVR would produce
 *
 * Stat volume scales with whether they were a starter (OVR 75+) or backup.
 */
class HistoricalStatsGenerator
{
    private \PDO $db;

    // Games per season (17 game NFL season)
    private const GAMES_PER_SEASON = 17;

    // Per-game stat baselines at OVR 80 (starter level)
    private const STAT_BASELINES = [
        'QB' => [
            'pass_attempts' => 33, 'pass_completions' => 21, 'pass_yards' => 240,
            'pass_tds' => 1.5, 'interceptions' => 0.7,
            'rush_attempts' => 3, 'rush_yards' => 12, 'rush_tds' => 0.15,
        ],
        'RB' => [
            'rush_attempts' => 18, 'rush_yards' => 78, 'rush_tds' => 0.55,
            'targets' => 4, 'receptions' => 3, 'rec_yards' => 22, 'rec_tds' => 0.12,
        ],
        'WR' => [
            'targets' => 8, 'receptions' => 5.5, 'rec_yards' => 68, 'rec_tds' => 0.4,
            'rush_attempts' => 0.3, 'rush_yards' => 3, 'rush_tds' => 0.02,
        ],
        'TE' => [
            'targets' => 5, 'receptions' => 3.5, 'rec_yards' => 38, 'rec_tds' => 0.28,
        ],
        'DE' => ['tackles' => 3.5, 'sacks' => 0.5, 'forced_fumbles' => 0.1],
        'DT' => ['tackles' => 3.0, 'sacks' => 0.3, 'forced_fumbles' => 0.08],
        'LB' => ['tackles' => 7.0, 'sacks' => 0.2, 'interceptions_def' => 0.06, 'forced_fumbles' => 0.08],
        'CB' => ['tackles' => 4.0, 'interceptions_def' => 0.2, 'forced_fumbles' => 0.05],
        'S'  => ['tackles' => 5.5, 'interceptions_def' => 0.15, 'sacks' => 0.1, 'forced_fumbles' => 0.06],
        'K'  => ['fg_attempts' => 2.0, 'fg_made' => 1.7],
        'P'  => [],
        'LS' => [],
        'OT' => [],
        'OG' => [],
        'C'  => [],
    ];

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
    }

    /**
     * Generate historical stats for all players in a league.
     * Skips players who already have historical stats.
     *
     * @return array{generated: int, skipped: int}
     */
    public function generateForLeague(int $leagueId, int $currentYear = 2026): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, first_name, last_name, position, age, years_pro, overall_rating, potential, team_id
             FROM players
             WHERE league_id = ? AND years_pro > 0
             AND id NOT IN (SELECT DISTINCT player_id FROM historical_stats WHERE league_id = ?)"
        );
        $stmt->execute([$leagueId, $leagueId]);
        $players = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Get team abbreviations for labeling
        $teamStmt = $this->db->prepare("SELECT id, abbreviation FROM teams WHERE league_id = ?");
        $teamStmt->execute([$leagueId]);
        $teamMap = [];
        foreach ($teamStmt->fetchAll(\PDO::FETCH_ASSOC) as $t) {
            $teamMap[(int) $t['id']] = $t['abbreviation'];
        }

        $generated = 0;
        $skipped = 0;

        $insertStmt = $this->db->prepare(
            "INSERT OR IGNORE INTO historical_stats
             (player_id, league_id, season_year, team_abbr, games_played,
              pass_attempts, pass_completions, pass_yards, pass_tds, interceptions,
              rush_attempts, rush_yards, rush_tds, targets, receptions, rec_yards, rec_tds,
              tackles, sacks, interceptions_def, forced_fumbles, fg_attempts, fg_made, is_synthetic)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)"
        );

        foreach ($players as $p) {
            $yearsPro = (int) $p['years_pro'];
            $currentOvr = (int) $p['overall_rating'];
            $age = (int) $p['age'];
            $position = $p['position'];
            $teamAbbr = $teamMap[(int) ($p['team_id'] ?? 0)] ?? null;

            if ($yearsPro <= 0) {
                $skipped++;
                continue;
            }

            // Generate OVR trajectory (what was their rating each year?)
            $ovrHistory = $this->buildOvrTrajectory($currentOvr, $age, $yearsPro, $p['potential'] ?? 'average');

            for ($i = 0; $i < $yearsPro; $i++) {
                $seasonYear = $currentYear - $yearsPro + $i;
                $yearOvr = $ovrHistory[$i] ?? $currentOvr;
                $yearAge = $age - $yearsPro + $i;

                // Determine games played based on OVR (starters play more)
                $games = $this->estimateGamesPlayed($yearOvr, $yearAge, $i === 0);

                // Generate stats for this season
                $stats = $this->generateSeasonStats($position, $yearOvr, $games);

                $insertStmt->execute([
                    $p['id'], $leagueId, $seasonYear, $teamAbbr, $games,
                    $stats['pass_attempts'], $stats['pass_completions'],
                    $stats['pass_yards'], $stats['pass_tds'], $stats['interceptions'],
                    $stats['rush_attempts'], $stats['rush_yards'], $stats['rush_tds'],
                    $stats['targets'], $stats['receptions'], $stats['rec_yards'], $stats['rec_tds'],
                    $stats['tackles'], $stats['sacks'],
                    $stats['interceptions_def'], $stats['forced_fumbles'],
                    $stats['fg_attempts'], $stats['fg_made'],
                ]);

                $generated++;
            }
        }

        return ['generated' => $generated, 'skipped' => $skipped];
    }

    /**
     * Build a realistic OVR trajectory from rookie year to current.
     * Players develop, peak around 27-29, then gradually decline.
     */
    private function buildOvrTrajectory(int $currentOvr, int $currentAge, int $yearsPro, string $potential): array
    {
        $trajectory = [];

        // Estimate rookie OVR based on current rating and years of development
        $developmentRate = match ($potential) {
            'elite', 'superstar' => 3.0,
            'high', 'star' => 2.0,
            'average', 'normal' => 1.2,
            default => 0.8,
        };

        // Work backwards from current OVR
        for ($i = 0; $i < $yearsPro; $i++) {
            $yearAge = $currentAge - $yearsPro + $i;
            $yearsFromCurrent = $yearsPro - $i;

            if ($yearAge < 27) {
                // Development phase: was lower before
                $drop = $yearsFromCurrent * $developmentRate;
                $ovr = max(55, (int) round($currentOvr - $drop + mt_rand(-2, 2)));
            } elseif ($yearAge <= 30) {
                // Prime years: close to current
                $ovr = max(60, (int) round($currentOvr - mt_rand(0, 3)));
            } else {
                // Decline phase: was higher before
                $boost = ($yearAge - 30) * 1.5;
                $ovr = min(99, (int) round($currentOvr + $boost * ($yearsFromCurrent / max(1, $yearsPro)) + mt_rand(-2, 2)));
            }

            // Rookies shouldn't be higher than current
            $trajectory[] = min($ovr, $currentOvr + 3);
        }

        return $trajectory;
    }

    /**
     * Estimate games played based on OVR and whether it's their rookie year.
     */
    private function estimateGamesPlayed(int $ovr, int $age, bool $isRookie): int
    {
        // Starters (75+) play most games, backups play fewer
        if ($ovr >= 80) {
            $base = mt_rand(14, 17);
        } elseif ($ovr >= 72) {
            $base = mt_rand(12, 17);
        } elseif ($ovr >= 65) {
            $base = mt_rand(8, 16);
        } else {
            $base = mt_rand(4, 12);
        }

        // Rookies might not play full season
        if ($isRookie && $ovr < 78) {
            $base = min($base, mt_rand(6, 14));
        }

        // Older players occasionally miss games
        if ($age >= 33) {
            $base = min($base, mt_rand(10, 16));
        }

        // Random injury seasons (10% chance of shortened season)
        if (mt_rand(1, 100) <= 10) {
            $base = mt_rand(2, 8);
        }

        return max(1, min(17, $base));
    }

    /**
     * Generate a season's worth of stats for a given position and OVR.
     */
    private function generateSeasonStats(string $position, int $ovr, int $games): array
    {
        $baselines = self::STAT_BASELINES[$position] ?? [];

        // Scale factor based on OVR (80 = 1.0, higher = more, lower = less)
        $ovrScale = pow($ovr / 80, 1.8);

        // Starter factor: below 72 OVR means likely a backup with way less volume
        $starterScale = 1.0;
        if ($ovr < 65) $starterScale = 0.2; // deep backup
        elseif ($ovr < 70) $starterScale = 0.4; // backup
        elseif ($ovr < 75) $starterScale = 0.7; // rotational

        $stats = [
            'pass_attempts' => 0, 'pass_completions' => 0, 'pass_yards' => 0,
            'pass_tds' => 0, 'interceptions' => 0,
            'rush_attempts' => 0, 'rush_yards' => 0, 'rush_tds' => 0,
            'targets' => 0, 'receptions' => 0, 'rec_yards' => 0, 'rec_tds' => 0,
            'tackles' => 0, 'sacks' => 0, 'interceptions_def' => 0, 'forced_fumbles' => 0,
            'fg_attempts' => 0, 'fg_made' => 0,
        ];

        foreach ($baselines as $stat => $perGame) {
            $seasonTotal = $perGame * $games * $ovrScale * $starterScale;

            // Add randomness (±15%)
            $variance = $seasonTotal * (mt_rand(-15, 15) / 100);
            $value = max(0, $seasonTotal + $variance);

            // Round appropriately
            if ($stat === 'sacks') {
                $stats[$stat] = round($value, 1);
            } else {
                $stats[$stat] = (int) round($value);
            }
        }

        // Fix completion rate for QBs (should be ~60-68% based on OVR)
        if ($position === 'QB' && $stats['pass_attempts'] > 0) {
            $compRate = 0.55 + ($ovr - 65) * 0.004; // 55% at OVR 65, 67% at OVR 95
            $compRate = max(0.50, min(0.72, $compRate + (mt_rand(-3, 3) / 100)));
            $stats['pass_completions'] = (int) round($stats['pass_attempts'] * $compRate);
        }

        // Fix FG percentage for kickers
        if ($position === 'K' && $stats['fg_attempts'] > 0) {
            $fgRate = 0.75 + ($ovr - 70) * 0.005;
            $fgRate = max(0.70, min(0.95, $fgRate));
            $stats['fg_made'] = (int) round($stats['fg_attempts'] * $fgRate);
        }

        return $stats;
    }

    /**
     * Delete all historical stats for a league.
     */
    public function clearForLeague(int $leagueId): void
    {
        $this->db->prepare("DELETE FROM historical_stats WHERE league_id = ?")->execute([$leagueId]);
    }
}
