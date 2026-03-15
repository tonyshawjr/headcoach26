<?php

namespace App\Services;

use App\Database\Connection;

/**
 * AllProEngine -- Selects All-League teams (First + Second) and Gridiron Classic rosters.
 *
 * Selection uses a weighted composite: 60% season stats score + 40% overall rating.
 * Gridiron Classic selections are made per-conference (AFC/NFC).
 */
class AllProEngine
{
    private \PDO $db;

    /** All-League slots per position (First Team = Second Team) */
    private const ALL_PRO_SLOTS = [
        // Offense
        'QB' => 1, 'RB' => 1, 'WR' => 2, 'TE' => 1,
        'OT' => 2, 'OG' => 2, 'C'  => 1,
        // Defense
        'DE' => 2, 'DT' => 2, 'LB' => 3, 'CB' => 2, 'S' => 2,
        // Special Teams
        'K' => 1, 'P' => 1,
    ];

    /** Gridiron Classic slots per position PER CONFERENCE */
    private const GRIDIRON_CLASSIC_SLOTS = [
        // Offense
        'QB' => 3, 'RB' => 3, 'WR' => 4, 'TE' => 2,
        'OT' => 3, 'OG' => 3, 'C'  => 2,
        // Defense
        'DE' => 3, 'DT' => 2, 'LB' => 4, 'CB' => 3, 'S' => 2,
        // Special Teams
        'K' => 1, 'P' => 1,
    ];

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
    }

    // ================================================================
    //  Public API
    // ================================================================

    /**
     * Select All-League First Team and Second Team.
     * Saves to season_awards and returns the selections.
     */
    public function selectAllPro(int $leagueId, int $seasonYear): array
    {
        $scores = $this->buildPlayerScores($leagueId);

        $firstTeam = [];
        $secondTeam = [];

        foreach (self::ALL_PRO_SLOTS as $position => $slots) {
            // Get players at this position sorted by composite score
            $candidates = $this->filterByPosition($scores, $position);
            usort($candidates, fn($a, $b) => $b['composite'] <=> $a['composite']);

            // First Team: top N
            $firstPicks = array_slice($candidates, 0, $slots);
            $firstTeam[$position] = $firstPicks;

            // Second Team: next N
            $secondPicks = array_slice($candidates, $slots, $slots);
            $secondTeam[$position] = $secondPicks;

            // Save First Team awards
            foreach ($firstPicks as $player) {
                $this->saveAward(
                    $leagueId,
                    $seasonYear,
                    'all_league_first',
                    'player',
                    (int) $player['player_id'],
                    json_encode($this->buildAwardStats($player))
                );
            }

            // Save Second Team awards
            foreach ($secondPicks as $player) {
                $this->saveAward(
                    $leagueId,
                    $seasonYear,
                    'all_league_second',
                    'player',
                    (int) $player['player_id'],
                    json_encode($this->buildAwardStats($player))
                );
            }
        }

        return [
            'first_team'  => $this->formatTeam($firstTeam),
            'second_team' => $this->formatTeam($secondTeam),
        ];
    }

    /**
     * Select Gridiron Classic rosters — NBA All-Star draft style.
     *
     * The two highest-scoring players overall become team captains.
     * All selected players are split between the two teams via alternating draft.
     * Teams are named "Team [Captain's Last Name]".
     */
    public function selectGridironClassic(int $leagueId, int $seasonYear): array
    {
        $scores = $this->buildPlayerScores($leagueId);

        // Select the best players at each position across the entire league
        $allSelected = [];
        foreach (self::GRIDIRON_CLASSIC_SLOTS as $position => $slots) {
            $candidates = $this->filterByPosition($scores, $position);
            usort($candidates, fn($a, $b) => $b['composite'] <=> $a['composite']);
            $picks = array_slice($candidates, 0, $slots);
            foreach ($picks as $p) {
                $allSelected[] = $p;
            }
        }

        // Sort all selected players by composite score descending
        usort($allSelected, fn($a, $b) => $b['composite'] <=> $a['composite']);

        if (count($allSelected) < 2) {
            return ['team1' => [], 'team2' => [], 'captain1' => null, 'captain2' => null];
        }

        // Top 2 players become captains
        $captain1 = $allSelected[0];
        $captain2 = $allSelected[1];
        $team1Name = 'Team ' . ($captain1['last_name'] ?? 'A');
        $team2Name = 'Team ' . ($captain2['last_name'] ?? 'B');

        // Alternate remaining players between teams (snake draft style)
        $team1 = [$captain1];
        $team2 = [$captain2];
        $remaining = array_slice($allSelected, 2);

        foreach ($remaining as $i => $player) {
            if ($i % 2 === 0) {
                $team1[] = $player;
            } else {
                $team2[] = $player;
            }
        }

        // Save awards for all selected players
        foreach ($allSelected as $player) {
            $teamLabel = in_array($player, $team1) ? $team1Name : $team2Name;
            $isCaptain = ($player['player_id'] === $captain1['player_id'] || $player['player_id'] === $captain2['player_id']);

            $this->saveAward(
                $leagueId,
                $seasonYear,
                'gridiron_classic',
                'player',
                (int) $player['player_id'],
                json_encode(array_merge(
                    $this->buildAwardStats($player),
                    ['team' => $teamLabel, 'is_captain' => $isCaptain]
                ))
            );
        }

        return [
            'captain1' => [
                'name' => $team1Name,
                'player' => $this->formatPlayerSummary($captain1),
            ],
            'captain2' => [
                'name' => $team2Name,
                'player' => $this->formatPlayerSummary($captain2),
            ],
            'team1' => [
                'name' => $team1Name,
                'players' => array_map([$this, 'formatPlayerSummary'], $team1),
            ],
            'team2' => [
                'name' => $team2Name,
                'players' => array_map([$this, 'formatPlayerSummary'], $team2),
            ],
        ];
    }

    private function formatPlayerSummary(array $player): array
    {
        return [
            'player_id' => (int) ($player['player_id'] ?? 0),
            'first_name' => $player['first_name'] ?? '',
            'last_name' => $player['last_name'] ?? '',
            'position' => $player['position'] ?? '',
            'team' => $player['team'] ?? '',
            'overall_rating' => (int) ($player['overall_rating'] ?? 0),
            'composite_score' => round((float) ($player['composite'] ?? 0), 1),
        ];
    }

    // ================================================================
    //  Scoring
    // ================================================================

    /**
     * Build composite scores for every player who has game stats this season.
     * Returns an array of player records with 'stat_score', 'rating_score', and 'composite'.
     */
    private function buildPlayerScores(int $leagueId): array
    {
        // Get current season
        $stmt = $this->db->prepare(
            "SELECT id FROM seasons WHERE league_id = ? AND is_current = 1 LIMIT 1"
        );
        $stmt->execute([$leagueId]);
        $season = $stmt->fetch();
        if (!$season) {
            // Fallback: get most recent season
            $stmt = $this->db->prepare(
                "SELECT id FROM seasons WHERE league_id = ? ORDER BY year DESC LIMIT 1"
            );
            $stmt->execute([$leagueId]);
            $season = $stmt->fetch();
        }
        if (!$season) return [];

        $seasonId = (int) $season['id'];

        // Aggregate season stats for all players
        $stmt = $this->db->prepare(
            "SELECT gs.player_id,
                    p.first_name, p.last_name, p.position, p.overall_rating, p.age,
                    p.team_id, t.abbreviation AS team, t.conference, t.city, t.name AS team_name,
                    SUM(gs.pass_yards) AS pass_yards,
                    SUM(gs.pass_tds) AS pass_tds,
                    SUM(gs.pass_attempts) AS pass_attempts,
                    SUM(gs.pass_completions) AS pass_completions,
                    SUM(COALESCE(gs.interceptions, 0)) AS interceptions,
                    SUM(gs.rush_yards) AS rush_yards,
                    SUM(gs.rush_tds) AS rush_tds,
                    SUM(gs.rush_attempts) AS rush_attempts,
                    SUM(gs.receptions) AS receptions,
                    SUM(gs.rec_yards) AS rec_yards,
                    SUM(gs.rec_tds) AS rec_tds,
                    SUM(gs.targets) AS targets,
                    SUM(gs.tackles) AS tackles,
                    SUM(gs.sacks) AS sacks,
                    SUM(gs.interceptions_def) AS interceptions_def,
                    SUM(gs.forced_fumbles) AS forced_fumbles,
                    SUM(gs.fg_attempts) AS fg_attempts,
                    SUM(gs.fg_made) AS fg_made,
                    SUM(gs.punt_yards) AS punt_yards,
                    COUNT(DISTINCT gs.game_id) AS games_played
             FROM game_stats gs
             JOIN games g ON g.id = gs.game_id
             JOIN players p ON p.id = gs.player_id
             JOIN teams t ON t.id = p.team_id
             WHERE g.league_id = ? AND g.season_id = ? AND p.team_id IS NOT NULL
             GROUP BY gs.player_id"
        );
        $stmt->execute([$leagueId, $seasonId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Also get OL players who may not have stats but are on rosters
        $olPlayers = $this->getOLPlayers($leagueId);

        // Build scored list
        $scored = [];

        foreach ($rows as $row) {
            $pos = $row['position'];
            $statScore = $this->calculateStatScore($pos, $row);
            $ratingScore = (int) $row['overall_rating'];
            $composite = ($statScore * 0.6) + ($ratingScore * 0.4);

            $row['stat_score'] = round($statScore, 2);
            $row['rating_score'] = $ratingScore;
            $row['composite'] = round($composite, 2);
            $scored[$row['player_id']] = $row;
        }

        // Merge OL players who had no game_stats (they still get rated by OVR)
        foreach ($olPlayers as $ol) {
            $pid = (int) $ol['id'];
            if (!isset($scored[$pid])) {
                $ratingScore = (int) $ol['overall_rating'];
                // OL uses rating only, so stat_score = rating too
                $composite = $ratingScore; // 0.6 * rating + 0.4 * rating = rating

                $scored[$pid] = [
                    'player_id' => $pid,
                    'first_name' => $ol['first_name'],
                    'last_name' => $ol['last_name'],
                    'position' => $ol['position'],
                    'overall_rating' => $ratingScore,
                    'age' => (int) $ol['age'],
                    'team_id' => (int) $ol['team_id'],
                    'team' => $ol['abbreviation'] ?? '',
                    'conference' => $ol['conference'] ?? '',
                    'city' => $ol['city'] ?? '',
                    'team_name' => $ol['name'] ?? '',
                    'pass_yards' => 0, 'pass_tds' => 0, 'pass_attempts' => 0,
                    'pass_completions' => 0, 'interceptions' => 0,
                    'rush_yards' => 0, 'rush_tds' => 0, 'rush_attempts' => 0,
                    'receptions' => 0, 'rec_yards' => 0, 'rec_tds' => 0, 'targets' => 0,
                    'tackles' => 0, 'sacks' => 0, 'interceptions_def' => 0,
                    'forced_fumbles' => 0, 'fg_attempts' => 0, 'fg_made' => 0,
                    'punt_yards' => 0, 'games_played' => 0,
                    'stat_score' => (float) $ratingScore,
                    'rating_score' => $ratingScore,
                    'composite' => (float) $ratingScore,
                ];
            }
        }

        return array_values($scored);
    }

    /**
     * Get all OL players (OT, OG, C) who are active on teams.
     */
    private function getOLPlayers(int $leagueId): array
    {
        $stmt = $this->db->prepare(
            "SELECT p.id, p.first_name, p.last_name, p.position, p.overall_rating, p.age,
                    p.team_id, t.abbreviation, t.conference, t.city, t.name
             FROM players p
             JOIN teams t ON t.id = p.team_id
             WHERE p.league_id = ? AND p.position IN ('OT', 'OG', 'C')
               AND p.status = 'active' AND p.team_id IS NOT NULL"
        );
        $stmt->execute([$leagueId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Calculate a stat-based score for a player at their position.
     * The score is normalized to roughly a 0-99 scale so it blends well with OVR.
     */
    private function calculateStatScore(string $position, array $stats): float
    {
        return match ($position) {
            'QB' => $this->scoreQB($stats),
            'RB' => $this->scoreRB($stats),
            'WR' => $this->scoreWR($stats),
            'TE' => $this->scoreTE($stats),
            'OT', 'OG', 'C' => $this->scoreOL($stats),
            'DE' => $this->scoreDEDT($stats),
            'DT' => $this->scoreDEDT($stats),
            'LB' => $this->scoreLB($stats),
            'CB', 'S' => $this->scoreCBS($stats),
            'K'  => $this->scoreK($stats),
            'P'  => $this->scoreP($stats),
            default => (float) ($stats['overall_rating'] ?? 50),
        };
    }

    /**
     * QB: pass_yards + pass_tds*10 - interceptions*2 + passer_rating_approx
     * Normalized to ~0-99 scale (elite QB season ~5000 yards, 40 TDs = raw ~5400)
     */
    private function scoreQB(array $s): float
    {
        $passYards = (int) ($s['pass_yards'] ?? 0);
        $passTds = (int) ($s['pass_tds'] ?? 0);
        $ints = (int) ($s['interceptions'] ?? 0);
        $attempts = (int) ($s['pass_attempts'] ?? 0);

        // Simplified passer rating component
        $passerRating = 0;
        if ($attempts > 0) {
            $compPct = ((int) ($s['pass_completions'] ?? 0)) / $attempts;
            $ypa = $passYards / $attempts;
            $passerRating = ($compPct * 100 + $ypa * 10 + ($passTds / $attempts) * 200 - ($ints / $attempts) * 200);
            $passerRating = max(0, min(158.3, $passerRating));
        }

        $raw = $passYards + ($passTds * 10) - ($ints * 2) + $passerRating;
        // Normalize: elite ~5500 raw -> 99
        return min(99, ($raw / 5500) * 99);
    }

    /**
     * RB: rush_yards + rush_tds*10 + rec_yards
     */
    private function scoreRB(array $s): float
    {
        $raw = (int) ($s['rush_yards'] ?? 0)
             + (int) ($s['rush_tds'] ?? 0) * 10
             + (int) ($s['rec_yards'] ?? 0);
        // Normalize: elite ~1800 raw -> 99
        return min(99, ($raw / 1800) * 99);
    }

    /**
     * WR: rec_yards + rec_tds*10 + receptions
     */
    private function scoreWR(array $s): float
    {
        $raw = (int) ($s['rec_yards'] ?? 0)
             + (int) ($s['rec_tds'] ?? 0) * 10
             + (int) ($s['receptions'] ?? 0);
        // Normalize: elite ~1600 raw -> 99
        return min(99, ($raw / 1600) * 99);
    }

    /**
     * TE: rec_yards + rec_tds*10 + receptions (scaled down since TEs produce less)
     */
    private function scoreTE(array $s): float
    {
        $raw = (int) ($s['rec_yards'] ?? 0)
             + (int) ($s['rec_tds'] ?? 0) * 10
             + (int) ($s['receptions'] ?? 0);
        // Normalize: elite TE ~1100 raw -> 99
        return min(99, ($raw / 1100) * 99);
    }

    /**
     * OL: overall_rating only (no individual stats tracked).
     */
    private function scoreOL(array $s): float
    {
        return (float) ($s['overall_rating'] ?? 50);
    }

    /**
     * DE/DT: sacks*10 + tackles + forced_fumbles*5
     */
    private function scoreDEDT(array $s): float
    {
        $raw = (float) ($s['sacks'] ?? 0) * 10
             + (int) ($s['tackles'] ?? 0)
             + (int) ($s['forced_fumbles'] ?? 0) * 5;
        // Normalize: elite DE ~200 raw -> 99
        return min(99, ($raw / 200) * 99);
    }

    /**
     * LB: tackles + sacks*8 + interceptions_def*10
     */
    private function scoreLB(array $s): float
    {
        $raw = (int) ($s['tackles'] ?? 0)
             + (float) ($s['sacks'] ?? 0) * 8
             + (int) ($s['interceptions_def'] ?? 0) * 10;
        // Normalize: elite LB ~200 raw -> 99
        return min(99, ($raw / 200) * 99);
    }

    /**
     * CB/S: interceptions_def*15 + tackles + forced_fumbles*5
     */
    private function scoreCBS(array $s): float
    {
        $raw = (int) ($s['interceptions_def'] ?? 0) * 15
             + (int) ($s['tackles'] ?? 0)
             + (int) ($s['forced_fumbles'] ?? 0) * 5;
        // Normalize: elite CB ~160 raw -> 99
        return min(99, ($raw / 160) * 99);
    }

    /**
     * K: fg_made*3 + fg_pct_bonus
     */
    private function scoreK(array $s): float
    {
        $fgMade = (int) ($s['fg_made'] ?? 0);
        $fgAttempts = (int) ($s['fg_attempts'] ?? 0);
        $fgPctBonus = 0;
        if ($fgAttempts > 0) {
            $pct = $fgMade / $fgAttempts;
            $fgPctBonus = $pct * 40; // up to 40 bonus points for 100% FG
        }
        $raw = ($fgMade * 3) + $fgPctBonus;
        // Normalize: elite K ~130 raw -> 99
        return min(99, ($raw / 130) * 99);
    }

    /**
     * P: punt_yards / games_played (avg per game) as a proxy.
     */
    private function scoreP(array $s): float
    {
        $puntYards = (int) ($s['punt_yards'] ?? 0);
        $games = max(1, (int) ($s['games_played'] ?? 1));
        $avgPerGame = $puntYards / $games;
        // Normalize: elite P ~200 yards/game -> 99
        return min(99, ($avgPerGame / 200) * 99);
    }

    // ================================================================
    //  Helpers
    // ================================================================

    /**
     * Filter a scored player list to a single position.
     */
    private function filterByPosition(array $players, string $position): array
    {
        return array_values(array_filter($players, fn($p) => ($p['position'] ?? '') === $position));
    }

    /**
     * Build the stats JSON payload for a season award record.
     */
    private function buildAwardStats(array $player): array
    {
        $stats = [
            'position' => $player['position'] ?? '',
            'team' => $player['team'] ?? '',
            'overall_rating' => (int) ($player['overall_rating'] ?? 0),
            'composite_score' => (float) ($player['composite'] ?? 0),
        ];

        $pos = $player['position'] ?? '';
        switch ($pos) {
            case 'QB':
                $stats['pass_yards'] = (int) ($player['pass_yards'] ?? 0);
                $stats['pass_tds'] = (int) ($player['pass_tds'] ?? 0);
                $stats['interceptions'] = (int) ($player['interceptions'] ?? 0);
                break;
            case 'RB':
                $stats['rush_yards'] = (int) ($player['rush_yards'] ?? 0);
                $stats['rush_tds'] = (int) ($player['rush_tds'] ?? 0);
                $stats['rec_yards'] = (int) ($player['rec_yards'] ?? 0);
                break;
            case 'WR':
            case 'TE':
                $stats['rec_yards'] = (int) ($player['rec_yards'] ?? 0);
                $stats['rec_tds'] = (int) ($player['rec_tds'] ?? 0);
                $stats['receptions'] = (int) ($player['receptions'] ?? 0);
                break;
            case 'DE':
            case 'DT':
                $stats['sacks'] = (float) ($player['sacks'] ?? 0);
                $stats['tackles'] = (int) ($player['tackles'] ?? 0);
                $stats['forced_fumbles'] = (int) ($player['forced_fumbles'] ?? 0);
                break;
            case 'LB':
                $stats['tackles'] = (int) ($player['tackles'] ?? 0);
                $stats['sacks'] = (float) ($player['sacks'] ?? 0);
                $stats['interceptions_def'] = (int) ($player['interceptions_def'] ?? 0);
                break;
            case 'CB':
            case 'S':
                $stats['interceptions_def'] = (int) ($player['interceptions_def'] ?? 0);
                $stats['tackles'] = (int) ($player['tackles'] ?? 0);
                $stats['forced_fumbles'] = (int) ($player['forced_fumbles'] ?? 0);
                break;
            case 'K':
                $stats['fg_made'] = (int) ($player['fg_made'] ?? 0);
                $stats['fg_attempts'] = (int) ($player['fg_attempts'] ?? 0);
                break;
            case 'P':
                $stats['punt_yards'] = (int) ($player['punt_yards'] ?? 0);
                break;
        }

        return $stats;
    }

    /**
     * Format a positional team array into a flat display-ready list.
     */
    private function formatTeam(array $teamByPosition): array
    {
        $flat = [];
        foreach ($teamByPosition as $position => $players) {
            foreach ($players as $player) {
                $flat[] = [
                    'player_id' => (int) ($player['player_id'] ?? 0),
                    'first_name' => $player['first_name'] ?? '',
                    'last_name' => $player['last_name'] ?? '',
                    'position' => $position,
                    'team' => $player['team'] ?? '',
                    'conference' => $player['conference'] ?? '',
                    'overall_rating' => (int) ($player['overall_rating'] ?? 0),
                    'composite_score' => round((float) ($player['composite'] ?? 0), 1),
                    'stat_score' => round((float) ($player['stat_score'] ?? 0), 1),
                ];
            }
        }
        return $flat;
    }

    /**
     * Save an award, avoiding duplicates (same league + year + type + winner).
     */
    private function saveAward(int $leagueId, int $year, string $type, string $winnerType, int $winnerId, string $stats): void
    {
        // Check for existing award for this player with this type in this season
        $stmt = $this->db->prepare(
            "SELECT id FROM season_awards WHERE league_id = ? AND season_year = ? AND award_type = ? AND winner_id = ?"
        );
        $stmt->execute([$leagueId, $year, $type, $winnerId]);
        if ($stmt->fetch()) return;

        $this->db->prepare(
            "INSERT INTO season_awards (league_id, season_year, award_type, winner_type, winner_id, stats)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([$leagueId, $year, $type, $winnerType, $winnerId, $stats]);
    }
}
