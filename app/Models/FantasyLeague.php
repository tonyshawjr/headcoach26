<?php

namespace App\Models;

class FantasyLeague extends BaseModel
{
    protected string $table = 'fantasy_leagues';

    // Default roster slot configuration
    public const DEFAULT_ROSTER_SLOTS = [
        'QB' => 1, 'RB' => 2, 'WR' => 2, 'TE' => 1,
        'FLEX' => 1, 'K' => 1, 'DEF' => 1, 'BN' => 6, 'IR' => 1,
    ];

    // Scoring presets
    public const SCORING_PRESETS = [
        'standard' => [
            'pass_yard' => 0.04, 'pass_td' => 4, 'interception' => -2, 'pass_2pt' => 2,
            'rush_yard' => 0.1, 'rush_td' => 6, 'rush_2pt' => 2,
            'reception' => 0, 'rec_yard' => 0.1, 'rec_td' => 6, 'rec_2pt' => 2,
            'fumble_lost' => -2,
            'fg_0_39' => 3, 'fg_40_49' => 4, 'fg_50_plus' => 5, 'pat' => 1,
            'sack' => 1, 'def_interception' => 2, 'fumble_recovery' => 2,
            'def_td' => 6, 'safety' => 2, 'forced_fumble' => 1,
        ],
        'ppr' => [
            'pass_yard' => 0.04, 'pass_td' => 4, 'interception' => -2, 'pass_2pt' => 2,
            'rush_yard' => 0.1, 'rush_td' => 6, 'rush_2pt' => 2,
            'reception' => 1, 'rec_yard' => 0.1, 'rec_td' => 6, 'rec_2pt' => 2,
            'fumble_lost' => -2,
            'fg_0_39' => 3, 'fg_40_49' => 4, 'fg_50_plus' => 5, 'pat' => 1,
            'sack' => 1, 'def_interception' => 2, 'fumble_recovery' => 2,
            'def_td' => 6, 'safety' => 2, 'forced_fumble' => 1,
        ],
        'half_ppr' => [
            'pass_yard' => 0.04, 'pass_td' => 4, 'interception' => -2, 'pass_2pt' => 2,
            'rush_yard' => 0.1, 'rush_td' => 6, 'rush_2pt' => 2,
            'reception' => 0.5, 'rec_yard' => 0.1, 'rec_td' => 6, 'rec_2pt' => 2,
            'fumble_lost' => -2,
            'fg_0_39' => 3, 'fg_40_49' => 4, 'fg_50_plus' => 5, 'pat' => 1,
            'sack' => 1, 'def_interception' => 2, 'fumble_recovery' => 2,
            'def_td' => 6, 'safety' => 2, 'forced_fumble' => 1,
        ],
    ];

    /**
     * Get all fantasy leagues for a given NFL league.
     */
    public function getByLeague(int $leagueId): array
    {
        return $this->all(['league_id' => $leagueId], 'created_at DESC');
    }

    /**
     * Get fantasy leagues a specific coach/user belongs to.
     */
    public function getByManager(int $coachId): array
    {
        return $this->query(
            "SELECT fl.*, fm.id as manager_id, fm.team_name, fm.wins, fm.losses
             FROM fantasy_leagues fl
             JOIN fantasy_managers fm ON fm.fantasy_league_id = fl.id
             WHERE fm.coach_id = ?
             ORDER BY fl.created_at DESC",
            [$coachId]
        );
    }

    /**
     * Get league with full manager list.
     */
    public function getWithManagers(int $id): ?array
    {
        $league = $this->find($id);
        if (!$league) return null;

        $league['roster_slots'] = $league['roster_slots']
            ? json_decode($league['roster_slots'], true)
            : self::DEFAULT_ROSTER_SLOTS;
        $league['scoring_rules'] = $league['scoring_rules']
            ? json_decode($league['scoring_rules'], true)
            : self::SCORING_PRESETS[$league['scoring_type']] ?? self::SCORING_PRESETS['ppr'];

        $league['managers'] = $this->query(
            "SELECT * FROM fantasy_managers
             WHERE fantasy_league_id = ?
             ORDER BY wins DESC, points_for DESC",
            [$id]
        );

        return $league;
    }

    /**
     * Find league by invite code.
     */
    public function findByInviteCode(string $code): ?array
    {
        $rows = $this->all(['invite_code' => $code]);
        return $rows[0] ?? null;
    }
}
