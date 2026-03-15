<?php

namespace App\Services;

use App\Models\FantasyLeague;
use App\Models\FantasyRoster;
use App\Models\GameStat;
use App\Database\Connection;

/**
 * FantasyScoreEngine — Computes fantasy points from game stats.
 *
 * After games are simulated, this reads from game_stats and applies the
 * fantasy league's scoring rules to produce per-player weekly fantasy points.
 */
class FantasyScoreEngine
{
    private \PDO $db;
    private FantasyLeague $fantasyLeagueModel;
    private GameStat $gameStatModel;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
        $this->fantasyLeagueModel = new FantasyLeague();
        $this->gameStatModel = new GameStat();
    }

    /**
     * Score all players for a given week in a fantasy league.
     * Called after SimEngine finishes the week's games.
     */
    public function scoreWeek(int $fantasyLeagueId, int $week): array
    {
        $league = $this->fantasyLeagueModel->find($fantasyLeagueId);
        if (!$league) return [];

        $scoringRules = $league['scoring_rules']
            ? json_decode($league['scoring_rules'], true)
            : FantasyLeague::SCORING_PRESETS[$league['scoring_type']] ?? FantasyLeague::SCORING_PRESETS['ppr'];

        // Get all game stats for this week in the parent NFL league
        $stats = $this->db->prepare(
            "SELECT gs.* FROM game_stats gs
             JOIN games g ON g.id = gs.game_id
             WHERE g.league_id = ? AND g.week = ? AND g.is_simulated = 1"
        );
        $stats->execute([$league['league_id'], $week]);
        $allStats = $stats->fetchAll();

        $scores = [];
        foreach ($allStats as $stat) {
            $points = $this->calculatePoints($stat, $scoringRules);
            $breakdown = $this->getBreakdown($stat, $scoringRules);

            $scores[$stat['player_id']] = [
                'fantasy_league_id' => $fantasyLeagueId,
                'player_id' => $stat['player_id'],
                'week' => $week,
                'points' => round($points, 2),
                'breakdown' => json_encode($breakdown),
            ];
        }

        // Upsert scores into fantasy_scores
        $this->saveScores($scores);

        return $scores;
    }

    /**
     * Calculate total fantasy points for a single player's game stats.
     */
    public function calculatePoints(array $stat, array $rules): float
    {
        $points = 0.0;

        // Passing
        $points += ($stat['pass_yards'] ?? 0) * ($rules['pass_yard'] ?? 0.04);
        $points += ($stat['pass_tds'] ?? 0) * ($rules['pass_td'] ?? 4);
        $points += ($stat['interceptions'] ?? 0) * ($rules['interception'] ?? -2);

        // Rushing
        $points += ($stat['rush_yards'] ?? 0) * ($rules['rush_yard'] ?? 0.1);
        $points += ($stat['rush_tds'] ?? 0) * ($rules['rush_td'] ?? 6);

        // Receiving
        $points += ($stat['receptions'] ?? 0) * ($rules['reception'] ?? 0);
        $points += ($stat['rec_yards'] ?? 0) * ($rules['rec_yard'] ?? 0.1);
        $points += ($stat['rec_tds'] ?? 0) * ($rules['rec_td'] ?? 6);

        // Defense/IDP
        $points += ($stat['tackles'] ?? 0) * ($rules['tackle'] ?? 0);
        $points += ($stat['sacks'] ?? 0) * ($rules['sack'] ?? 1);
        $points += ($stat['interceptions_def'] ?? 0) * ($rules['def_interception'] ?? 2);
        $points += ($stat['forced_fumbles'] ?? 0) * ($rules['forced_fumble'] ?? 1);

        // Kicking
        $fgMade = $stat['fg_made'] ?? 0;
        if ($fgMade > 0) {
            // For simplicity, score all FGs at the mid-tier rate
            // A more detailed approach would need FG distance tracking in SimEngine
            $points += $fgMade * ($rules['fg_0_39'] ?? 3);
        }

        // Fumbles lost (if tracked)
        $points += ($stat['fumbles_lost'] ?? 0) * ($rules['fumble_lost'] ?? -2);

        return $points;
    }

    /**
     * Build a human-readable breakdown of how points were scored.
     */
    private function getBreakdown(array $stat, array $rules): array
    {
        $breakdown = [];

        if (($stat['pass_yards'] ?? 0) > 0) {
            $pts = round($stat['pass_yards'] * ($rules['pass_yard'] ?? 0.04), 2);
            $breakdown['pass_yards'] = ['value' => $stat['pass_yards'], 'points' => $pts];
        }
        if (($stat['pass_tds'] ?? 0) > 0) {
            $pts = $stat['pass_tds'] * ($rules['pass_td'] ?? 4);
            $breakdown['pass_tds'] = ['value' => $stat['pass_tds'], 'points' => $pts];
        }
        if (($stat['interceptions'] ?? 0) > 0) {
            $pts = $stat['interceptions'] * ($rules['interception'] ?? -2);
            $breakdown['interceptions'] = ['value' => $stat['interceptions'], 'points' => $pts];
        }
        if (($stat['rush_yards'] ?? 0) > 0) {
            $pts = round($stat['rush_yards'] * ($rules['rush_yard'] ?? 0.1), 2);
            $breakdown['rush_yards'] = ['value' => $stat['rush_yards'], 'points' => $pts];
        }
        if (($stat['rush_tds'] ?? 0) > 0) {
            $pts = $stat['rush_tds'] * ($rules['rush_td'] ?? 6);
            $breakdown['rush_tds'] = ['value' => $stat['rush_tds'], 'points' => $pts];
        }
        if (($stat['receptions'] ?? 0) > 0) {
            $pts = round($stat['receptions'] * ($rules['reception'] ?? 0), 2);
            $breakdown['receptions'] = ['value' => $stat['receptions'], 'points' => $pts];
        }
        if (($stat['rec_yards'] ?? 0) > 0) {
            $pts = round($stat['rec_yards'] * ($rules['rec_yard'] ?? 0.1), 2);
            $breakdown['rec_yards'] = ['value' => $stat['rec_yards'], 'points' => $pts];
        }
        if (($stat['rec_tds'] ?? 0) > 0) {
            $pts = $stat['rec_tds'] * ($rules['rec_td'] ?? 6);
            $breakdown['rec_tds'] = ['value' => $stat['rec_tds'], 'points' => $pts];
        }
        if (($stat['sacks'] ?? 0) > 0) {
            $pts = $stat['sacks'] * ($rules['sack'] ?? 1);
            $breakdown['sacks'] = ['value' => $stat['sacks'], 'points' => $pts];
        }
        if (($stat['interceptions_def'] ?? 0) > 0) {
            $pts = $stat['interceptions_def'] * ($rules['def_interception'] ?? 2);
            $breakdown['interceptions_def'] = ['value' => $stat['interceptions_def'], 'points' => $pts];
        }
        if (($stat['forced_fumbles'] ?? 0) > 0) {
            $pts = $stat['forced_fumbles'] * ($rules['forced_fumble'] ?? 1);
            $breakdown['forced_fumbles'] = ['value' => $stat['forced_fumbles'], 'points' => $pts];
        }
        if (($stat['fg_made'] ?? 0) > 0) {
            $pts = $stat['fg_made'] * ($rules['fg_0_39'] ?? 3);
            $breakdown['fg_made'] = ['value' => $stat['fg_made'], 'points' => $pts];
        }
        if (($stat['tackles'] ?? 0) > 0) {
            $pts = $stat['tackles'] * ($rules['tackle'] ?? 0);
            if ($pts != 0) {
                $breakdown['tackles'] = ['value' => $stat['tackles'], 'points' => $pts];
            }
        }

        return $breakdown;
    }

    /**
     * Save computed scores (upsert pattern for SQLite).
     */
    private function saveScores(array $scores): void
    {
        if (empty($scores)) return;

        $stmt = $this->db->prepare(
            "INSERT INTO fantasy_scores (fantasy_league_id, player_id, week, points, breakdown)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT(fantasy_league_id, player_id, week)
             DO UPDATE SET points = excluded.points, breakdown = excluded.breakdown"
        );

        foreach ($scores as $score) {
            $stmt->execute([
                $score['fantasy_league_id'],
                $score['player_id'],
                $score['week'],
                $score['points'],
                $score['breakdown'],
            ]);
        }
    }

    /**
     * Get top available fantasy players (for draft/waiver rankings).
     */
    public function getPlayerRankings(int $fantasyLeagueId, ?string $position = null, int $limit = 200): array
    {
        $league = $this->fantasyLeagueModel->find($fantasyLeagueId);
        if (!$league) return [];

        $sql = "SELECT p.id, p.first_name, p.last_name, p.position, p.overall_rating,
                       p.team_id, t.abbreviation as team_abbr,
                       COALESCE(SUM(fs.points), 0) as total_points,
                       COUNT(fs.id) as games_played,
                       CASE WHEN COUNT(fs.id) > 0
                            THEN ROUND(COALESCE(SUM(fs.points), 0) / COUNT(fs.id), 2)
                            ELSE 0 END as ppg
                FROM players p
                LEFT JOIN teams t ON t.id = p.team_id
                LEFT JOIN fantasy_scores fs ON fs.player_id = p.id AND fs.fantasy_league_id = ?
                WHERE p.league_id = ? AND p.status = 'active'";
        $params = [$fantasyLeagueId, $league['league_id']];

        if ($position) {
            $sql .= " AND p.position = ?";
            $params[] = $position;
        }

        $sql .= " GROUP BY p.id ORDER BY p.overall_rating DESC LIMIT ?";
        $params[] = $limit;

        return (new \App\Models\Player())->query($sql, $params);
    }

    /**
     * Project points for a player based on their OVR and position.
     * Used before any games have been played (pre-draft rankings).
     */
    public function projectPoints(array $player, array $scoringRules): float
    {
        $ovr = $player['overall_rating'] ?? 70;
        $pos = $player['position'] ?? 'WR';

        // Base projection curves by position (points per game at OVR 80)
        $basePPG = [
            'QB' => 18, 'RB' => 12, 'WR' => 11, 'TE' => 8,
            'K' => 8, 'DE' => 5, 'DT' => 4, 'LB' => 6,
            'CB' => 4, 'S' => 5,
        ];

        $base = $basePPG[$pos] ?? 3;
        // Scale by OVR: each point above/below 80 is ~2.5% more/less
        $scale = 1 + (($ovr - 80) * 0.025);

        // PPR bonus for pass catchers
        $pprBonus = ($scoringRules['reception'] ?? 0);
        if (in_array($pos, ['WR', 'TE', 'RB'])) {
            $recsPerGame = ['WR' => 5, 'TE' => 4, 'RB' => 3][$pos];
            $base += $recsPerGame * $pprBonus * ($ovr / 85);
        }

        return round(max(0, $base * $scale), 2);
    }
}
