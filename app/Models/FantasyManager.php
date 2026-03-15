<?php

namespace App\Models;

class FantasyManager extends BaseModel
{
    protected string $table = 'fantasy_managers';

    /**
     * Get all managers in a fantasy league, sorted by standings.
     */
    public function getByLeague(int $fantasyLeagueId): array
    {
        return $this->all(
            ['fantasy_league_id' => $fantasyLeagueId],
            'wins DESC, points_for DESC'
        );
    }

    /**
     * Get a manager by coach_id in a given fantasy league.
     */
    public function findByCoach(int $fantasyLeagueId, int $coachId): ?array
    {
        $rows = $this->all(['fantasy_league_id' => $fantasyLeagueId, 'coach_id' => $coachId]);
        return $rows[0] ?? null;
    }

    /**
     * Get a manager's full roster with player details.
     */
    public function getRoster(int $managerId): array
    {
        return $this->query(
            "SELECT fr.*, p.first_name, p.last_name, p.position, p.overall_rating,
                    p.team_id, t.abbreviation as team_abbr, t.name as team_name
             FROM fantasy_rosters fr
             JOIN players p ON p.id = fr.player_id
             LEFT JOIN teams t ON t.id = p.team_id
             WHERE fr.fantasy_manager_id = ?
             ORDER BY fr.is_starter DESC, FIELD_ORDER(p.position), p.overall_rating DESC",
            [$managerId]
        );
    }

    /**
     * Get roster with SQLite-compatible ordering.
     */
    public function getRosterOrdered(int $managerId): array
    {
        return $this->query(
            "SELECT fr.*, p.first_name, p.last_name, p.position, p.overall_rating,
                    p.team_id, t.abbreviation as team_abbr, t.name as team_name
             FROM fantasy_rosters fr
             JOIN players p ON p.id = fr.player_id
             LEFT JOIN teams t ON t.id = p.team_id
             WHERE fr.fantasy_manager_id = ?
             ORDER BY fr.is_starter DESC,
                CASE p.position
                    WHEN 'QB' THEN 1 WHEN 'RB' THEN 2 WHEN 'WR' THEN 3
                    WHEN 'TE' THEN 4 WHEN 'K' THEN 5 ELSE 6
                END,
                p.overall_rating DESC",
            [$managerId]
        );
    }

    /**
     * Get manager's total score for a given week from their starters.
     */
    public function getWeekScore(int $managerId, int $fantasyLeagueId, int $week): float
    {
        $row = $this->query(
            "SELECT COALESCE(SUM(fs.points), 0) as total
             FROM fantasy_rosters fr
             JOIN fantasy_scores fs ON fs.player_id = fr.player_id
                AND fs.fantasy_league_id = fr.fantasy_league_id
                AND fs.week = ?
             WHERE fr.fantasy_manager_id = ? AND fr.fantasy_league_id = ?
                AND fr.is_starter = 1",
            [$week, $managerId, $fantasyLeagueId]
        );
        return (float) ($row[0]['total'] ?? 0);
    }

    /**
     * Update standings after a matchup resolves.
     */
    public function recordResult(int $managerId, string $result, float $pointsFor, float $pointsAgainst): void
    {
        $manager = $this->find($managerId);
        if (!$manager) return;

        $data = [
            'points_for' => $manager['points_for'] + $pointsFor,
            'points_against' => $manager['points_against'] + $pointsAgainst,
        ];

        if ($result === 'win') {
            $data['wins'] = $manager['wins'] + 1;
        } elseif ($result === 'loss') {
            $data['losses'] = $manager['losses'] + 1;
        } else {
            $data['ties'] = $manager['ties'] + 1;
        }

        // Update streak
        $currentStreak = $manager['streak'];
        if ($result === 'win') {
            $data['streak'] = (str_starts_with($currentStreak, 'W'))
                ? 'W' . ((int) substr($currentStreak, 1) + 1)
                : 'W1';
        } elseif ($result === 'loss') {
            $data['streak'] = (str_starts_with($currentStreak, 'L'))
                ? 'L' . ((int) substr($currentStreak, 1) + 1)
                : 'L1';
        } else {
            $data['streak'] = 'T1';
        }

        $this->update($managerId, $data);
    }
}
