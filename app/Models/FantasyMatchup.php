<?php

namespace App\Models;

class FantasyMatchup extends BaseModel
{
    protected string $table = 'fantasy_matchups';

    /**
     * Get all matchups for a given week.
     */
    public function getByWeek(int $fantasyLeagueId, int $week): array
    {
        return $this->query(
            "SELECT fm.*,
                m1.team_name as team1_name, m1.owner_name as owner1_name,
                m1.wins as team1_wins, m1.losses as team1_losses,
                m2.team_name as team2_name, m2.owner_name as owner2_name,
                m2.wins as team2_wins, m2.losses as team2_losses
             FROM fantasy_matchups fm
             JOIN fantasy_managers m1 ON m1.id = fm.manager1_id
             JOIN fantasy_managers m2 ON m2.id = fm.manager2_id
             WHERE fm.fantasy_league_id = ? AND fm.week = ?
             ORDER BY fm.id",
            [$fantasyLeagueId, $week]
        );
    }

    /**
     * Get a manager's matchup for a given week.
     */
    public function getManagerMatchup(int $fantasyLeagueId, int $managerId, int $week): ?array
    {
        $rows = $this->query(
            "SELECT fm.*,
                m1.team_name as team1_name, m1.owner_name as owner1_name,
                m2.team_name as team2_name, m2.owner_name as owner2_name
             FROM fantasy_matchups fm
             JOIN fantasy_managers m1 ON m1.id = fm.manager1_id
             JOIN fantasy_managers m2 ON m2.id = fm.manager2_id
             WHERE fm.fantasy_league_id = ? AND fm.week = ?
                AND (fm.manager1_id = ? OR fm.manager2_id = ?)",
            [$fantasyLeagueId, $week, $managerId, $managerId]
        );
        return $rows[0] ?? null;
    }

    /**
     * Get full season schedule for a manager.
     */
    public function getManagerSchedule(int $fantasyLeagueId, int $managerId): array
    {
        return $this->query(
            "SELECT fm.*,
                m1.team_name as team1_name, m1.owner_name as owner1_name,
                m2.team_name as team2_name, m2.owner_name as owner2_name
             FROM fantasy_matchups fm
             JOIN fantasy_managers m1 ON m1.id = fm.manager1_id
             JOIN fantasy_managers m2 ON m2.id = fm.manager2_id
             WHERE fm.fantasy_league_id = ?
                AND (fm.manager1_id = ? OR fm.manager2_id = ?)
             ORDER BY fm.week",
            [$fantasyLeagueId, $managerId, $managerId]
        );
    }

    /**
     * Get playoff matchups.
     */
    public function getPlayoffBracket(int $fantasyLeagueId): array
    {
        return $this->query(
            "SELECT fm.*,
                m1.team_name as team1_name, m1.owner_name as owner1_name,
                m1.playoff_seed as seed1,
                m2.team_name as team2_name, m2.owner_name as owner2_name,
                m2.playoff_seed as seed2
             FROM fantasy_matchups fm
             JOIN fantasy_managers m1 ON m1.id = fm.manager1_id
             JOIN fantasy_managers m2 ON m2.id = fm.manager2_id
             WHERE fm.fantasy_league_id = ? AND fm.is_playoff = 1
             ORDER BY fm.week, fm.id",
            [$fantasyLeagueId]
        );
    }
}
