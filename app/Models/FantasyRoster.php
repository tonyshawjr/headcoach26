<?php

namespace App\Models;

class FantasyRoster extends BaseModel
{
    protected string $table = 'fantasy_rosters';

    /**
     * Check if a player is owned in a fantasy league.
     */
    public function isOwned(int $fantasyLeagueId, int $playerId): bool
    {
        return $this->count([
            'fantasy_league_id' => $fantasyLeagueId,
            'player_id' => $playerId,
        ]) > 0;
    }

    /**
     * Get the owner of a player in a fantasy league.
     */
    public function getOwner(int $fantasyLeagueId, int $playerId): ?array
    {
        $rows = $this->all([
            'fantasy_league_id' => $fantasyLeagueId,
            'player_id' => $playerId,
        ]);
        return $rows[0] ?? null;
    }

    /**
     * Get all starters for a manager.
     */
    public function getStarters(int $managerId): array
    {
        return $this->query(
            "SELECT fr.*, p.first_name, p.last_name, p.position, p.overall_rating
             FROM fantasy_rosters fr
             JOIN players p ON p.id = fr.player_id
             WHERE fr.fantasy_manager_id = ? AND fr.is_starter = 1
             ORDER BY CASE p.position
                WHEN 'QB' THEN 1 WHEN 'RB' THEN 2 WHEN 'WR' THEN 3
                WHEN 'TE' THEN 4 WHEN 'K' THEN 5 ELSE 6
             END",
            [$managerId]
        );
    }

    /**
     * Get all available (unowned) players in a fantasy league.
     */
    public function getAvailablePlayers(int $fantasyLeagueId, int $leagueId, ?string $position = null, int $limit = 50): array
    {
        $sql = "SELECT p.*, t.abbreviation as team_abbr, t.name as team_name
                FROM players p
                LEFT JOIN teams t ON t.id = p.team_id
                WHERE p.league_id = ? AND p.status = 'active'
                AND p.id NOT IN (
                    SELECT player_id FROM fantasy_rosters WHERE fantasy_league_id = ?
                )";
        $params = [$leagueId, $fantasyLeagueId];

        if ($position) {
            $sql .= " AND p.position = ?";
            $params[] = $position;
        }

        $sql .= " ORDER BY p.overall_rating DESC LIMIT ?";
        $params[] = $limit;

        return $this->query($sql, $params);
    }

    /**
     * Get roster count for a manager.
     */
    public function getRosterCount(int $managerId): int
    {
        return $this->count(['fantasy_manager_id' => $managerId]);
    }

    /**
     * Drop a player from a fantasy roster.
     */
    public function dropPlayer(int $managerId, int $playerId): bool
    {
        return $this->deleteWhere([
            'fantasy_manager_id' => $managerId,
            'player_id' => $playerId,
        ]) > 0;
    }
}
