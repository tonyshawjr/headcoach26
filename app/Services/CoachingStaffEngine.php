<?php

namespace App\Services;

use App\Database\Connection;

class CoachingStaffEngine
{
    private \PDO $db;

    private array $firstNames = [
        'Mike', 'Steve', 'John', 'Bob', 'Tom', 'Dave', 'Bill', 'Jim', 'Joe', 'Rick',
        'Dan', 'Mark', 'Tony', 'Greg', 'Ray', 'Sean', 'Pat', 'Ron', 'Ken', 'Doug',
    ];

    private array $lastNames = [
        'Miller', 'Johnson', 'Williams', 'Brown', 'Jones', 'Davis', 'Garcia', 'Rodriguez',
        'Wilson', 'Martinez', 'Anderson', 'Taylor', 'Thomas', 'Moore', 'Jackson', 'White',
    ];

    private array $roles = [
        'OC' => ['specialty_options' => ['passing', 'rushing', 'balanced'], 'base_salary' => 1500000],
        'DC' => ['specialty_options' => ['coverage', 'pass_rush', 'run_stuff'], 'base_salary' => 1500000],
        'STC' => ['specialty_options' => ['kickoff', 'punt', 'returns'], 'base_salary' => 800000],
        'QB_coach' => ['specialty_options' => ['arm_strength', 'accuracy', 'reads'], 'base_salary' => 600000],
        'RB_coach' => ['specialty_options' => ['vision', 'power', 'speed'], 'base_salary' => 500000],
        'WR_coach' => ['specialty_options' => ['routes', 'catching', 'release'], 'base_salary' => 500000],
        'OL_coach' => ['specialty_options' => ['pass_block', 'run_block', 'technique'], 'base_salary' => 500000],
        'DL_coach' => ['specialty_options' => ['pass_rush', 'run_stuff', 'technique'], 'base_salary' => 500000],
        'DB_coach' => ['specialty_options' => ['coverage', 'ball_skills', 'tackling'], 'base_salary' => 500000],
        'development' => ['specialty_options' => ['young_players', 'veterans', 'all_around'], 'base_salary' => 400000],
    ];

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
    }

    /**
     * Generate initial coaching staff for a team.
     */
    public function generateStaff(int $teamId, int $leagueId): void
    {
        $now = date('Y-m-d H:i:s');

        foreach ($this->roles as $role => $info) {
            $name = $this->firstNames[array_rand($this->firstNames)] . ' ' . $this->lastNames[array_rand($this->lastNames)];
            $rating = mt_rand(40, 75);
            $specialty = $info['specialty_options'][array_rand($info['specialty_options'])];
            $salary = (int) ($info['base_salary'] * (0.8 + ($rating / 100) * 0.4));

            $this->db->prepare(
                "INSERT INTO coaching_staff (team_id, league_id, role, name, rating, specialty, salary, contract_years, is_available, hired_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?)"
            )->execute([$teamId, $leagueId, $role, $name, $rating, $specialty, $salary, mt_rand(1, 3), $now]);
        }
    }

    /**
     * Get a team's coaching staff.
     */
    public function getStaff(int $teamId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM coaching_staff WHERE team_id = ? AND is_available = 0 ORDER BY role");
        $stmt->execute([$teamId]);
        return $stmt->fetchAll();
    }

    /**
     * Get available coaches on the market.
     */
    public function getAvailableCoaches(int $leagueId, ?string $role = null): array
    {
        $sql = "SELECT * FROM coaching_staff WHERE league_id = ? AND is_available = 1";
        $params = [$leagueId];

        if ($role) {
            $sql .= " AND role = ?";
            $params[] = $role;
        }

        $sql .= " ORDER BY rating DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Fire a staff member.
     */
    public function fireCoach(int $staffId, int $teamId): bool
    {
        $stmt = $this->db->prepare("SELECT * FROM coaching_staff WHERE id = ? AND team_id = ?");
        $stmt->execute([$staffId, $teamId]);
        $coach = $stmt->fetch();
        if (!$coach) return false;

        // Make them available on the market
        $this->db->prepare("UPDATE coaching_staff SET team_id = 0, is_available = 1 WHERE id = ?")
            ->execute([$staffId]);

        return true;
    }

    /**
     * Hire a staff member.
     */
    public function hireCoach(int $staffId, int $teamId): bool
    {
        $stmt = $this->db->prepare("SELECT * FROM coaching_staff WHERE id = ? AND is_available = 1");
        $stmt->execute([$staffId]);
        $coach = $stmt->fetch();
        if (!$coach) return false;

        $now = date('Y-m-d H:i:s');
        $this->db->prepare("UPDATE coaching_staff SET team_id = ?, is_available = 0, hired_at = ? WHERE id = ?")
            ->execute([$teamId, $now, $staffId]);

        return true;
    }

    /**
     * Calculate coaching staff bonus for simulation.
     * Returns modifiers for team stats during sim.
     */
    public function getStaffBonuses(int $teamId): array
    {
        $staff = $this->getStaff($teamId);
        $bonuses = [
            'pass_offense' => 0,
            'rush_offense' => 0,
            'pass_defense' => 0,
            'rush_defense' => 0,
            'special_teams' => 0,
            'development' => 0,
        ];

        foreach ($staff as $coach) {
            $modifier = ($coach['rating'] - 50) / 200; // -0.25 to +0.25

            switch ($coach['role']) {
                case 'OC':
                    $bonuses['pass_offense'] += $modifier * 0.5;
                    $bonuses['rush_offense'] += $modifier * 0.5;
                    break;
                case 'DC':
                    $bonuses['pass_defense'] += $modifier * 0.5;
                    $bonuses['rush_defense'] += $modifier * 0.5;
                    break;
                case 'STC':
                    $bonuses['special_teams'] += $modifier;
                    break;
                case 'QB_coach':
                case 'WR_coach':
                    $bonuses['pass_offense'] += $modifier * 0.3;
                    break;
                case 'RB_coach':
                case 'OL_coach':
                    $bonuses['rush_offense'] += $modifier * 0.3;
                    break;
                case 'DL_coach':
                    $bonuses['rush_defense'] += $modifier * 0.3;
                    break;
                case 'DB_coach':
                    $bonuses['pass_defense'] += $modifier * 0.3;
                    break;
                case 'development':
                    $bonuses['development'] += $modifier;
                    break;
            }
        }

        return $bonuses;
    }

    /**
     * End-of-season staff rating changes and poaching.
     */
    public function processOffseason(int $leagueId): array
    {
        $changes = [];

        // Staff rating changes based on team performance
        $stmt = $this->db->prepare(
            "SELECT cs.*, t.wins, t.losses FROM coaching_staff cs
             JOIN teams t ON cs.team_id = t.id
             WHERE cs.league_id = ? AND cs.is_available = 0"
        );
        $stmt->execute([$leagueId]);
        $allStaff = $stmt->fetchAll();

        foreach ($allStaff as $coach) {
            $winPct = $coach['wins'] / max(1, $coach['wins'] + $coach['losses']);
            $ratingChange = 0;

            if ($winPct > 0.6) $ratingChange = mt_rand(1, 3);
            elseif ($winPct < 0.4) $ratingChange = mt_rand(-3, 0);
            else $ratingChange = mt_rand(-1, 1);

            $newRating = max(30, min(95, $coach['rating'] + $ratingChange));
            $this->db->prepare("UPDATE coaching_staff SET rating = ? WHERE id = ?")
                ->execute([$newRating, $coach['id']]);

            // Contract expiration
            $yearsLeft = $coach['contract_years'] - 1;
            if ($yearsLeft <= 0) {
                // Coach becomes available (poachable)
                $this->db->prepare("UPDATE coaching_staff SET is_available = 1, team_id = 0 WHERE id = ?")
                    ->execute([$coach['id']]);
                $changes[] = ['type' => 'contract_expired', 'coach' => $coach['name'], 'role' => $coach['role']];
            } else {
                $this->db->prepare("UPDATE coaching_staff SET contract_years = ? WHERE id = ?")
                    ->execute([$yearsLeft, $coach['id']]);
            }
        }

        // Generate new available coaches to fill the market
        $availableCount = count($this->getAvailableCoaches($leagueId));
        $needed = max(0, 15 - $availableCount);
        for ($i = 0; $i < $needed; $i++) {
            $roleKeys = array_keys($this->roles);
            $role = $roleKeys[array_rand($roleKeys)];
            $info = $this->roles[$role];
            $name = $this->firstNames[array_rand($this->firstNames)] . ' ' . $this->lastNames[array_rand($this->lastNames)];
            $rating = mt_rand(40, 80);
            $specialty = $info['specialty_options'][array_rand($info['specialty_options'])];
            $salary = (int) ($info['base_salary'] * (0.8 + ($rating / 100) * 0.4));

            $this->db->prepare(
                "INSERT INTO coaching_staff (team_id, league_id, role, name, rating, specialty, salary, contract_years, is_available)
                 VALUES (0, ?, ?, ?, ?, ?, ?, ?, 1)"
            )->execute([$leagueId, $role, $name, $rating, $specialty, $salary, mt_rand(1, 3)]);
        }

        return $changes;
    }
}
