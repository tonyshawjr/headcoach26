<?php

namespace App\Models;

class GameStat extends BaseModel
{
    protected string $table = 'game_stats';

    public function getByGame(int $gameId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE game_id = ? ORDER BY player_id ASC");
        $stmt->execute([$gameId]);
        return $stmt->fetchAll();
    }

    public function getByPlayer(int $playerId, int $seasonId): array
    {
        $stmt = $this->db->prepare(
            "SELECT gs.* FROM {$this->table} gs
             JOIN games g ON g.id = gs.game_id
             WHERE gs.player_id = ? AND g.season_id = ?
             ORDER BY g.week ASC"
        );
        $stmt->execute([$playerId, $seasonId]);
        return $stmt->fetchAll();
    }

    public function getSeasonLeaders(int $leagueId, int $seasonId, string $stat, int $limit = 10): array
    {
        $allowedStats = [
            'pass_yards', 'pass_tds', 'rush_yards', 'rush_tds',
            'rec_yards', 'rec_tds', 'receptions', 'tackles',
            'sacks', 'interceptions', 'interceptions_def',
            'forced_fumbles', 'total_yards', 'points'
        ];

        if (!in_array($stat, $allowedStats, true)) {
            return [];
        }

        $stmt = $this->db->prepare(
            "SELECT gs.player_id, p.first_name, p.last_name, p.position, t.abbreviation AS team,
                    SUM(gs.{$stat}) AS total
             FROM {$this->table} gs
             JOIN games g ON g.id = gs.game_id
             JOIN players p ON p.id = gs.player_id
             JOIN teams t ON t.id = p.team_id
             WHERE g.league_id = ? AND g.season_id = ?
             GROUP BY gs.player_id, p.first_name, p.last_name, p.position, t.abbreviation
             ORDER BY total DESC
             LIMIT ?"
        );
        $stmt->execute([$leagueId, $seasonId, $limit]);
        return $stmt->fetchAll();
    }
}
