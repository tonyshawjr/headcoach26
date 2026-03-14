<?php

namespace App\Services;

class PlayerGenerator
{
    // ─── Name pools ────────────────────────────────────────────────────
    private array $firstNames = [
        'Marcus', 'Jaylen', 'DeShawn', 'Tyler', 'Caleb', 'Brandon', 'Trevon', 'Malik',
        'Darius', 'Xavier', 'Antonio', 'Cameron', 'Isaiah', 'Jalen', 'Terrell', 'Davon',
        'Khalil', 'Jamal', 'Derek', 'Corey', 'Travis', 'Jordan', 'Andre', 'Damien',
        'Quinton', 'Rashad', 'Tyrone', 'Devin', 'Lamar', 'Marquis', 'Tavon', 'Kenyon',
        'Mitchell', 'Ryan', 'Jake', 'Cody', 'Hunter', 'Austin', 'Cole', 'Garrett',
        'Brock', 'Tanner', 'Logan', 'Dylan', 'Mason', 'Cooper', 'Brady', 'Cade',
        'Carson', 'Connor', 'Nolan', 'Blake', 'Chase', 'Wyatt', 'Luke', 'Grant',
        'Miguel', 'Carlos', 'Roberto', 'Diego', 'Marco', 'Alejandro', 'Rafael', 'Santiago',
        'Dante', 'Keith', 'Jerome', 'Rodney', 'Cedric', 'Wendell', 'Troy', 'Darren',
        'Preston', 'Elijah', 'Micah', 'Josiah', 'Ezekiel', 'Aaron', 'Nathan', 'Ethan',
        'Jadeveon', 'Tremaine', 'Keenan', 'Davante', 'Amari', 'Tyreek', 'Stefon', 'DK',
        'CeeDee', 'Jaire', 'Minkah', 'Budda', 'Roquan', 'Dexter', 'Tua', 'Kyler',
    ];

    private array $lastNames = [
        'Webb', 'Jackson', 'Rodriguez', 'Patterson', 'Williams', 'Brown', 'Davis', 'Johnson',
        'Wilson', 'Thompson', 'Anderson', 'Taylor', 'Thomas', 'Harris', 'Clark', 'Lewis',
        'Robinson', 'Walker', 'Young', 'Allen', 'King', 'Wright', 'Scott', 'Green',
        'Baker', 'Adams', 'Nelson', 'Hill', 'Campbell', 'Mitchell', 'Roberts', 'Carter',
        'Phillips', 'Evans', 'Turner', 'Torres', 'Parker', 'Collins', 'Edwards', 'Stewart',
        'Flores', 'Morris', 'Murphy', 'Rivera', 'Cook', 'Rogers', 'Morgan', 'Peterson',
        'Cooper', 'Reed', 'Bailey', 'Bell', 'Gomez', 'Kelly', 'Howard', 'Ward',
        'Cox', 'Diaz', 'Richardson', 'Wood', 'Watson', 'Brooks', 'Bennett', 'Gray',
        'James', 'Reyes', 'Cruz', 'Hughes', 'Price', 'Myers', 'Long', 'Foster',
        'Sanders', 'Ross', 'Morales', 'Powell', 'Sullivan', 'Russell', 'Ortiz', 'Jenkins',
        'Gutierrez', 'Perry', 'Butler', 'Barnes', 'Fisher', 'Henderson', 'Coleman', 'Simmons',
        'Patterson', 'Jordan', 'Reynolds', 'Hamilton', 'Graham', 'Kim', 'Gonzalez', 'Alexander',
        'Marshall', 'Owens', 'McDaniel', 'Burns', 'Gordon', 'Shaw', 'Warren', 'Hunter',
        'Hicks', 'Dixon', 'Hunt', 'Palmer', 'Wagner', 'Grant', 'Freeman', 'Cunningham',
    ];

    private array $colleges = [
        'Alabama', 'Ohio State', 'Clemson', 'Georgia', 'LSU', 'Oklahoma', 'Michigan',
        'Notre Dame', 'Texas', 'USC', 'Oregon', 'Penn State', 'Florida', 'Auburn',
        'Tennessee', 'Wisconsin', 'Florida State', 'Miami (FL)', 'Texas A&M', 'Iowa',
        'Stanford', 'Virginia Tech', 'North Carolina', 'Mississippi', 'Arkansas',
        'Nebraska', 'UCLA', 'Washington', 'Arizona State', 'Kentucky', 'Missouri',
        'South Carolina', 'Oklahoma State', 'Baylor', 'TCU', 'Utah', 'Colorado',
        'West Virginia', 'Maryland', 'Pittsburgh', 'NC State', 'Minnesota', 'Illinois',
        'Indiana', 'Boston College', 'Wake Forest', 'Duke', 'Syracuse', 'Memphis',
        'Boise State', 'San Diego State', 'BYU', 'Cincinnati', 'Houston', 'UCF',
        'Central Michigan', 'Eastern Michigan', 'Western Michigan', 'Toledo', 'Buffalo',
        'Northern Illinois', 'Ball State', 'Kent State', 'Tulane', 'SMU', 'Tulsa',
    ];

    // ─── Blueprints per position ───────────────────────────────────────
    private function getArchetypes(): array
    {
        return [
            'QB'  => ['Field General', 'Improviser', 'Scrambler', 'Strong Arm'],
            'RB'  => ['Elusive Back', 'Power Back', 'Receiving Back'],
            'WR'  => ['Deep Threat', 'Slot', 'Physical', 'Possession'],
            'TE'  => ['Vertical Threat', 'Possession', 'Blocking'],
            'OT'  => ['Power', 'Agile', 'Pass Protector'],
            'OG'  => ['Power', 'Agile', 'Pass Protector'],
            'C'   => ['Agile', 'Power', 'Pass Protector'],
            'DE'  => ['Speed Rusher', 'Power Rusher', 'Run Stopper'],
            'DT'  => ['Power Rusher', 'Speed Rusher', 'Run Stopper'],
            'LB'  => ['Field General', 'Run Stopper', 'Pass Coverage'],
            'CB'  => ['Man to Man', 'Zone', 'Slot'],
            'S'   => ['Zone', 'Hybrid', 'Run Support'],
            'K'   => ['Power', 'Accurate'],
            'P'   => ['Power', 'Accurate'],
            'LS'  => ['Accurate', 'Athletic'],
            'FB'  => ['Utility', 'Blocking'],
        ];
    }

    // ─── Physical dimensions per position [min_height, max_height, min_weight, max_weight] (inches / lbs) ─
    private function getPhysicalRange(string $pos): array
    {
        return match ($pos) {
            'QB'          => [73, 77, 210, 245],
            'RB', 'FB'    => [68, 72, 195, 230],
            'WR'          => [69, 76, 175, 220],
            'TE'          => [74, 78, 240, 265],
            'OT', 'OG', 'C' => [75, 79, 295, 340],
            'DE', 'DT'    => [73, 78, 265, 310],
            'LB'          => [72, 76, 225, 260],
            'CB'          => [69, 74, 180, 205],
            'S'           => [70, 75, 195, 220],
            'K', 'P', 'LS' => [70, 75, 185, 215],
            default       => [70, 76, 200, 250],
        };
    }

    // ─── Position type mapping ─────────────────────────────────────────
    private function getPositionType(string $pos): string
    {
        return match ($pos) {
            'QB', 'RB', 'WR', 'TE', 'OT', 'OG', 'C', 'FB' => 'Offense',
            'DE', 'DT', 'LB', 'CB', 'S'                     => 'Defense',
            'K', 'P', 'LS'                                     => 'Special Teams',
            default                                           => 'Offense',
        };
    }

    // ─── Edge and Instincts ability pools per position ─────────────
    private function getEdgePool(string $pos): array
    {
        return match ($pos) {
            'QB' => ['Cannon Arm', 'Pocket Poise', 'Moving Target', 'Quick Strike', 'Signal Caller', 'Iron Will', 'Precision Aim'],
            'RB', 'FB' => ['Clean Break', 'Locomotive', 'Ankle Snap', 'Battering Ram'],
            'WR' => ['Uncoverable', 'After the Catch', 'Mismatch', 'Yards After Contact'],
            'TE' => ['Mismatch', 'Slot Machine', 'Yards After Contact'],
            'OT', 'OG', 'C' => ['Flattener', 'Brick Wall', 'Immovable', 'Marathon'],
            'DE', 'DT' => ['Terror', 'Juggernaut', 'Gap Plugger', 'Relentless'],
            'LB' => ['Cavalry', 'Lockdown', 'Gap Plugger', 'Heat Seeker'],
            'CB' => ['Lockdown', 'Ball Hawk', 'High Wire'],
            'S' => ['Cavalry', 'Lockdown', 'Ball Hawk', 'Zone Ghost'],
            'K' => ['Cold Blooded'],
            'P' => ['Pin Drop'],
            'LS' => ['Snapper Elite'],
            default => ['Standout'],
        };
    }

    private function getInstinctsPool(string $pos): array
    {
        return match ($pos) {
            'QB' => [
                'Run-Pass Read', 'Scramble Sense', 'Pocket Precision', 'Plant & Fire',
                'Boundary Accuracy', 'Pocket Escape', 'Audible Expert', 'Quick Release',
                'Rocket Arm', 'Hail Mary', 'No-Look Pass', 'Play Extender',
            ],
            'RB', 'FB' => [
                'Stiff Arm Pro', 'Receiving Threat', 'Pile Driver', 'Ghost Runner',
                'Hip Shake', 'Tornado', 'Punisher', 'Extra Effort',
            ],
            'WR' => [
                'High Wire', 'Deep Post', 'Deep Corner', 'Snag & Go',
                'Crossing Expert', 'Comeback King', 'After the Catch', 'Route Surgeon',
                'Slant Specialist', 'Out Route Pro', 'Slot Machine', 'Burner',
            ],
            'TE' => [
                'High Wire', 'Mismatch', 'Route Surgeon', 'Crossing Expert',
                'Snag & Go', 'Punisher', 'Mean Streak',
            ],
            'OT', 'OG', 'C' => [
                'Mean Streak', 'Brick Wall', 'Sure Hands', 'Edge Seal',
                'Marathon', 'Road Grader', 'Field General',
            ],
            'DE', 'DT' => [
                'Speed Rush', 'Interior Pressure', 'Closer', 'Fourth Quarter',
                'Swim Artist', 'Power Drive', 'Gap Plugger',
            ],
            'LB' => [
                'Hard Hitter', 'Zone Reader', 'Blanket Coverage', 'Heat Seeker',
                'Ball Stripper', 'Overpowered', 'Downhill',
            ],
            'CB' => [
                'High Wire', 'Mirror Step', 'Ball Hawk', 'Press Master',
                'Blanket Coverage', 'Zone Buster', 'Deep Patrol',
            ],
            'S' => [
                'High Wire', 'Hard Hitter', 'Zone Reader', 'Blanket Coverage',
                'Zone Buster', 'Deep Patrol', 'Sixth Sense',
            ],
            'K' => ['Tunnel Vision', 'Ice Water'],
            'P' => ['Tunnel Vision', 'Pin Drop'],
            'LS' => ['Tunnel Vision'],
            default => ['Standout'],
        };
    }

    // ─── Running style pool ────────────────────────────────────────────
    private function getRunningStylePool(string $pos): ?array
    {
        return match ($pos) {
            'RB' => ['Default', 'Long Stride', 'Short Stride', 'Upright', 'Hunched', 'Loose', 'Tight'],
            'QB' => ['Default', 'Upright', 'Loose'],
            default => null,
        };
    }

    // ─── Roster spec (unchanged) ───────────────────────────────────────
    private function getRosterSpec(): array
    {
        return [
            'QB'  => ['starter' => 1, 'backup' => 1, 'practice' => 1],
            'RB'  => ['starter' => 1, 'backup' => 2, 'practice' => 1],
            'WR'  => ['starter' => 3, 'backup' => 3, 'practice' => 2],
            'TE'  => ['starter' => 1, 'backup' => 1, 'practice' => 1],
            'OT'  => ['starter' => 2, 'backup' => 1, 'practice' => 1],
            'OG'  => ['starter' => 2, 'backup' => 1, 'practice' => 1],
            'C'   => ['starter' => 1, 'backup' => 1, 'practice' => 0],
            'DE'  => ['starter' => 2, 'backup' => 2, 'practice' => 1],
            'DT'  => ['starter' => 2, 'backup' => 1, 'practice' => 1],
            'LB'  => ['starter' => 3, 'backup' => 2, 'practice' => 1],
            'CB'  => ['starter' => 2, 'backup' => 2, 'practice' => 0],
            'S'   => ['starter' => 2, 'backup' => 2, 'practice' => 0],
            'K'   => ['starter' => 1, 'backup' => 0, 'practice' => 0],
            'P'   => ['starter' => 1, 'backup' => 0, 'practice' => 0],
            'LS'  => ['starter' => 1, 'backup' => 0, 'practice' => 0],
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    // PUBLIC API
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Generate all players for a team.
     */
    public function generateForTeam(int $teamId, int $leagueId): array
    {
        $players = [];
        $usedNumbers = [];
        $spec = $this->getRosterSpec();

        foreach ($spec as $position => $tiers) {
            foreach ($tiers as $tier => $count) {
                for ($i = 0; $i < $count; $i++) {
                    $jersey = $this->assignJersey($position, $usedNumbers);
                    $usedNumbers[] = $jersey;
                    $players[] = $this->generatePlayer($position, $tier, $teamId, $leagueId, $jersey);
                }
            }
        }

        return $players;
    }

    // ═══════════════════════════════════════════════════════════════════
    // CORE PLAYER GENERATION
    // ═══════════════════════════════════════════════════════════════════

    private function generatePlayer(string $pos, string $tier, int $teamId, int $leagueId, int $jersey): array
    {
        // ── Overall rating via bell curve ──────────────────────────────
        $ratingCenter = match ($tier) {
            'starter'  => 80,
            'backup'   => 70,
            'practice' => 60,
            default    => 65,
        };

        $overall = $this->bellCurveRating($ratingCenter, 6);
        $overall = max(42, min(97, $overall));

        // Elite starters get a boost
        if ($tier === 'starter' && mt_rand(1, 100) <= 15) {
            $overall = min(97, $overall + mt_rand(3, 8));
        }

        // ── Age / Experience ──────────────────────────────────────────
        $age = match ($tier) {
            'starter'  => mt_rand(24, 32),
            'backup'   => mt_rand(23, 30),
            'practice' => mt_rand(22, 26),
            default    => mt_rand(23, 28),
        };

        $experience = max(0, $age - 22);
        $yearsPro   = $experience;

        // ── Potential / Personality ─────────────────────────────────────
        $potential = match (true) {
            $age <= 24 => $this->weightedRandom(['elite' => 10, 'high' => 25, 'average' => 50, 'limited' => 15]),
            $age <= 28 => $this->weightedRandom(['elite' => 5, 'high' => 15, 'average' => 60, 'limited' => 20]),
            default    => $this->weightedRandom(['elite' => 1, 'high' => 5, 'average' => 50, 'limited' => 44]),
        };

        $personality = $this->weightedRandom([
            'team_player'         => 40,
            'competitor'          => 20,
            'quiet_professional'  => 20,
            'vocal_leader'        => 12,
            'troublemaker'        => 8,
        ]);

        // ── Blueprint ──────────────────────────────────────────────────
        $archetypes = $this->getArchetypes();
        $posArchetypes = $archetypes[$pos] ?? ['General'];
        $archetype = $posArchetypes[array_rand($posArchetypes)];
        $archetypeLabel = "{$archetype} - {$pos}";

        // ── Physical dimensions ────────────────────────────────────────
        [$minH, $maxH, $minW, $maxW] = $this->getPhysicalRange($pos);
        $height    = mt_rand($minH, $maxH);
        $weight    = mt_rand($minW, $maxW);
        $handedness = ($pos === 'QB') ? $this->weightedRandom(['1' => 85, '2' => 15]) : 1;
        $birthYear = date('Y') - $age;
        $birthdate = sprintf('%04d-%02d-%02d', $birthYear, mt_rand(1, 12), mt_rand(1, 28));

        // ── Position type ──────────────────────────────────────────────
        $positionType = $this->getPositionType($pos);

        // ── Running style ──────────────────────────────────────────────
        $runStyles = $this->getRunningStylePool($pos);
        $runningStyle = $runStyles ? $runStyles[array_rand($runStyles)] : null;

        // ── Generate all 53 stats ──────────────────────────────────────
        $stats = $this->generateAllStats($pos, $archetype, $overall);

        // ── Edge / Instincts abilities ─────────────────────────────
        $edge = null;
        $instincts = [];

        if ($overall >= 90) {
            $pool = $this->getEdgePool($pos);
            $edge = $pool[array_rand($pool)];
            $instincts = $this->pickAbilities($pos, mt_rand(2, 3));
        } elseif ($overall >= 85) {
            $instincts = $this->pickAbilities($pos, mt_rand(1, 3));
        } elseif ($overall >= 80) {
            $instincts = $this->pickAbilities($pos, mt_rand(0, 1));
        }

        // ── Status ─────────────────────────────────────────────────────
        $status = $tier === 'practice' ? 'practice_squad' : 'active';

        // ── Build return array ─────────────────────────────────────────
        return [
            // Core columns (unchanged from original schema)
            'league_id'       => $leagueId,
            'team_id'         => $teamId,
            'first_name'      => $this->firstNames[array_rand($this->firstNames)],
            'last_name'       => $this->lastNames[array_rand($this->lastNames)],
            'position'        => $pos,
            'age'             => $age,
            'overall_rating'  => $overall,
            'potential'        => $potential,
            'personality'      => $personality,
            'morale'           => 'content',
            'experience'       => $experience,
            'college'          => $this->colleges[array_rand($this->colleges)],
            'jersey_number'    => $jersey,
            'is_rookie'        => ($age <= 23) ? 1 : 0,
            'is_fictional'     => 1,
            'status'           => $status,
            'created_at'       => date('Y-m-d H:i:s'),

            // Bio / metadata
            'height'              => $height,
            'weight'              => $weight,
            'handedness'          => (int) $handedness,
            'birthdate'           => $birthdate,
            'years_pro'           => $yearsPro,
            'archetype'           => $archetypeLabel,
            'position_type'       => $positionType,
            'x_factor'            => $edge,
            'superstar_abilities' => !empty($instincts) ? json_encode($instincts) : null,

            // Physical (speed, strength, stamina exist in original table; awareness also exists)
            'speed'         => $stats['speed'],
            'strength'      => $stats['strength'],
            'awareness'     => $stats['awareness'],
            'stamina'       => $stats['stamina'],
            'injury_prone'  => mt_rand(5, 40),
            'acceleration'  => $stats['acceleration'],
            'agility'       => $stats['agility'],
            'jumping'       => $stats['jumping'],
            'toughness'     => $stats['toughness'],

            // Ball Carrier
            'bc_vision'            => $stats['bc_vision'],
            'break_tackle'         => $stats['break_tackle'],
            'carrying'             => $stats['carrying'],
            'change_of_direction'  => $stats['change_of_direction'],
            'juke_move'            => $stats['juke_move'],
            'spin_move'            => $stats['spin_move'],
            'stiff_arm'            => $stats['stiff_arm'],
            'trucking'             => $stats['trucking'],

            // Receiving
            'catch_in_traffic'     => $stats['catch_in_traffic'],
            'catching'             => $stats['catching'],
            'deep_route_running'   => $stats['deep_route_running'],
            'medium_route_running' => $stats['medium_route_running'],
            'short_route_running'  => $stats['short_route_running'],
            'spectacular_catch'    => $stats['spectacular_catch'],
            'release'              => $stats['release'],

            // Blocking
            'impact_blocking'    => $stats['impact_blocking'],
            'lead_block'         => $stats['lead_block'],
            'pass_block'         => $stats['pass_block'],
            'pass_block_finesse' => $stats['pass_block_finesse'],
            'pass_block_power'   => $stats['pass_block_power'],
            'run_block'          => $stats['run_block'],
            'run_block_finesse'  => $stats['run_block_finesse'],
            'run_block_power'    => $stats['run_block_power'],

            // Defense
            'block_shedding'   => $stats['block_shedding'],
            'finesse_moves'    => $stats['finesse_moves'],
            'hit_power'        => $stats['hit_power'],
            'man_coverage'     => $stats['man_coverage'],
            'play_recognition' => $stats['play_recognition'],
            'power_moves'      => $stats['power_moves'],
            'press'            => $stats['press'],
            'pursuit'          => $stats['pursuit'],
            'tackle'           => $stats['tackle'],
            'zone_coverage'    => $stats['zone_coverage'],

            // Quarterback
            'break_sack'            => $stats['break_sack'],
            'play_action'           => $stats['play_action'],
            'throw_accuracy_deep'   => $stats['throw_accuracy_deep'],
            'throw_accuracy_mid'    => $stats['throw_accuracy_mid'],
            'throw_accuracy_short'  => $stats['throw_accuracy_short'],
            'throw_on_the_run'      => $stats['throw_on_the_run'],
            'throw_power'           => $stats['throw_power'],
            'throw_under_pressure'  => $stats['throw_under_pressure'],

            // Kicking
            'kick_accuracy' => $stats['kick_accuracy'],
            'kick_power'    => $stats['kick_power'],
            'kick_return'   => $stats['kick_return'],

            // Other
            'running_style' => $runningStyle,
        ];
    }

    /**
     * Get default stats for a position/archetype/overall combination.
     * Used by the generic RosterImporter when CSV columns are missing.
     */
    public function getDefaultStats(string $position, ?string $archetype, int $overall): array
    {
        if (!$archetype) {
            $archetypes = $this->getArchetypes();
            $posArchetypes = $archetypes[$position] ?? ['General'];
            $archetype = $posArchetypes[0];
        }
        return $this->generateAllStats($position, $archetype, $overall);
    }

    // ═══════════════════════════════════════════════════════════════════
    // STAT GENERATION ENGINE
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Generate all 53 stat ratings keyed by column name.
     *
     * Each stat is categorised as HIGH, MEDIUM, or LOW relative to the
     * player's overall rating:
     *   HIGH   = overall + rand(-3, 5)      (near or above overall)
     *   MEDIUM = overall - rand(10, 20)
     *   LOW    = overall - rand(30, 50)
     */
    private function generateAllStats(string $pos, string $archetype, int $overall): array
    {
        // Start every stat at LOW by default
        $allStatKeys = [
            // Physical
            'speed', 'strength', 'awareness', 'stamina', 'acceleration', 'agility', 'jumping', 'toughness',
            // Ball Carrier
            'bc_vision', 'break_tackle', 'carrying', 'change_of_direction', 'juke_move', 'spin_move', 'stiff_arm', 'trucking',
            // Receiving
            'catch_in_traffic', 'catching', 'deep_route_running', 'medium_route_running', 'short_route_running', 'spectacular_catch', 'release',
            // Blocking
            'impact_blocking', 'lead_block', 'pass_block', 'pass_block_finesse', 'pass_block_power', 'run_block', 'run_block_finesse', 'run_block_power',
            // Defense
            'block_shedding', 'finesse_moves', 'hit_power', 'man_coverage', 'play_recognition', 'power_moves', 'press', 'pursuit', 'tackle', 'zone_coverage',
            // Quarterback
            'break_sack', 'play_action', 'throw_accuracy_deep', 'throw_accuracy_mid', 'throw_accuracy_short', 'throw_on_the_run', 'throw_power', 'throw_under_pressure',
            // Kicking
            'kick_accuracy', 'kick_power', 'kick_return',
        ];

        // Get the HIGH/MEDIUM categorizations for this position + archetype
        $high   = $this->getHighStats($pos, $archetype);
        $medium = $this->getMediumStats($pos, $archetype);

        $stats = [];
        foreach ($allStatKeys as $key) {
            if (in_array($key, $high, true)) {
                $stats[$key] = $this->clamp($overall + mt_rand(-3, 5));
            } elseif (in_array($key, $medium, true)) {
                $stats[$key] = $this->clamp($overall - mt_rand(10, 20));
            } else {
                // LOW
                $stats[$key] = $this->clamp($overall - mt_rand(30, 50));
            }
        }

        return $stats;
    }

    /**
     * Stats that should be near or above the player's overall.
     */
    private function getHighStats(string $pos, string $archetype): array
    {
        // Universal high stats per position, then archetype-specific boosts
        $base = match ($pos) {
            'QB' => [
                'awareness', 'throw_accuracy_short', 'throw_accuracy_mid', 'throw_accuracy_deep',
                'throw_power', 'play_action', 'throw_under_pressure', 'stamina',
            ],
            'RB' => [
                'speed', 'acceleration', 'agility', 'carrying', 'bc_vision',
                'change_of_direction', 'stamina', 'toughness',
            ],
            'WR' => [
                'speed', 'acceleration', 'catching', 'agility', 'stamina',
            ],
            'TE' => [
                'catching', 'strength', 'toughness', 'awareness', 'stamina',
            ],
            'OT' => [
                'pass_block', 'run_block', 'strength', 'awareness', 'stamina',
                'impact_blocking', 'toughness',
            ],
            'OG' => [
                'pass_block', 'run_block', 'strength', 'awareness', 'stamina',
                'impact_blocking', 'toughness',
            ],
            'C' => [
                'pass_block', 'run_block', 'strength', 'awareness', 'stamina',
                'impact_blocking', 'toughness',
            ],
            'DE' => [
                'speed', 'acceleration', 'block_shedding', 'pursuit', 'tackle',
                'strength', 'toughness', 'stamina',
            ],
            'DT' => [
                'strength', 'block_shedding', 'tackle', 'toughness',
                'power_moves', 'awareness', 'stamina',
            ],
            'LB' => [
                'tackle', 'pursuit', 'play_recognition', 'awareness',
                'toughness', 'stamina', 'hit_power',
            ],
            'CB' => [
                'speed', 'acceleration', 'agility', 'awareness', 'stamina',
            ],
            'S' => [
                'speed', 'awareness', 'tackle', 'pursuit', 'stamina',
                'hit_power', 'toughness',
            ],
            'K' => [
                'kick_accuracy', 'kick_power', 'awareness', 'stamina',
            ],
            'P' => [
                'kick_accuracy', 'kick_power', 'awareness', 'stamina',
            ],
            'LS' => [
                'awareness', 'strength', 'toughness', 'stamina',
            ],
            'FB' => [
                'run_block', 'lead_block', 'impact_blocking', 'strength',
                'toughness', 'carrying', 'stamina',
            ],
            default => ['awareness', 'stamina'],
        };

        // Archetype-specific additions
        $extra = match ("{$pos}:{$archetype}") {
            'QB:Field General'   => ['awareness', 'play_action', 'throw_accuracy_short'],
            'QB:Improviser'      => ['throw_on_the_run', 'break_sack', 'agility', 'speed', 'acceleration'],
            'QB:Scrambler'       => ['speed', 'acceleration', 'agility', 'throw_on_the_run', 'break_sack'],
            'QB:Strong Arm'      => ['throw_power', 'throw_accuracy_deep', 'strength'],
            'RB:Elusive Back'    => ['juke_move', 'spin_move', 'break_tackle', 'agility'],
            'RB:Power Back'      => ['trucking', 'stiff_arm', 'break_tackle', 'strength'],
            'RB:Receiving Back'  => ['catching', 'short_route_running', 'catch_in_traffic'],
            'WR:Deep Threat'     => ['deep_route_running', 'spectacular_catch', 'release'],
            'WR:Slot'            => ['short_route_running', 'medium_route_running', 'catch_in_traffic', 'change_of_direction', 'release'],
            'WR:Physical'        => ['break_tackle', 'stiff_arm', 'catching', 'catch_in_traffic', 'strength', 'toughness', 'release'],
            'WR:Possession'      => ['catching', 'catch_in_traffic', 'short_route_running', 'medium_route_running', 'release'],
            'TE:Vertical Threat' => ['speed', 'deep_route_running', 'spectacular_catch', 'acceleration', 'release'],
            'TE:Possession'      => ['catching', 'catch_in_traffic', 'short_route_running', 'medium_route_running', 'release'],
            'TE:Blocking'        => ['run_block', 'pass_block', 'impact_blocking', 'lead_block'],
            'OT:Power'           => ['run_block_power', 'pass_block_power', 'run_block'],
            'OT:Agile'           => ['agility', 'pass_block_finesse', 'acceleration'],
            'OT:Pass Protector'  => ['pass_block', 'pass_block_finesse', 'pass_block_power'],
            'OG:Power'           => ['run_block_power', 'pass_block_power', 'run_block'],
            'OG:Agile'           => ['agility', 'pass_block_finesse', 'acceleration'],
            'OG:Pass Protector'  => ['pass_block', 'pass_block_finesse', 'pass_block_power'],
            'C:Agile'            => ['agility', 'acceleration', 'pass_block_finesse'],
            'C:Power'            => ['run_block_power', 'pass_block_power'],
            'C:Pass Protector'   => ['pass_block', 'pass_block_finesse', 'pass_block_power'],
            'DE:Speed Rusher'    => ['speed', 'finesse_moves', 'acceleration'],
            'DE:Power Rusher'    => ['power_moves', 'strength', 'block_shedding'],
            'DE:Run Stopper'     => ['block_shedding', 'tackle', 'strength', 'play_recognition'],
            'DT:Power Rusher'    => ['power_moves', 'block_shedding', 'strength'],
            'DT:Speed Rusher'    => ['speed', 'acceleration', 'finesse_moves'],
            'DT:Run Stopper'     => ['block_shedding', 'tackle', 'play_recognition'],
            'LB:Field General'   => ['awareness', 'play_recognition', 'zone_coverage', 'man_coverage'],
            'LB:Run Stopper'     => ['block_shedding', 'power_moves', 'strength', 'tackle'],
            'LB:Pass Coverage'   => ['zone_coverage', 'man_coverage', 'speed', 'acceleration', 'agility'],
            'CB:Man to Man'      => ['man_coverage', 'press', 'play_recognition'],
            'CB:Zone'            => ['zone_coverage', 'play_recognition', 'pursuit'],
            'CB:Slot'            => ['agility', 'man_coverage', 'zone_coverage', 'tackle'],
            'S:Zone'             => ['zone_coverage', 'play_recognition', 'acceleration'],
            'S:Hybrid'           => ['man_coverage', 'zone_coverage', 'acceleration', 'agility'],
            'S:Run Support'      => ['tackle', 'hit_power', 'block_shedding', 'strength'],
            'K:Power'            => ['kick_power'],
            'K:Accurate'         => ['kick_accuracy'],
            'P:Power'            => ['kick_power'],
            'P:Accurate'         => ['kick_accuracy'],
            'FB:Utility'         => ['catching', 'bc_vision', 'speed', 'acceleration'],
            'FB:Blocking'        => ['run_block', 'pass_block', 'impact_blocking', 'lead_block'],
            default              => [],
        };

        return array_unique(array_merge($base, $extra));
    }

    /**
     * Stats that should be moderate (overall - 10 to 20).
     */
    private function getMediumStats(string $pos, string $archetype): array
    {
        $base = match ($pos) {
            'QB' => [
                'speed', 'strength', 'break_sack', 'agility', 'toughness',
                'acceleration', 'jumping', 'carrying', 'bc_vision',
            ],
            'RB' => [
                'catching', 'catch_in_traffic', 'awareness', 'break_tackle',
                'trucking', 'stiff_arm', 'juke_move', 'spin_move', 'jumping',
                'strength', 'short_route_running',
            ],
            'WR' => [
                'catch_in_traffic', 'deep_route_running', 'medium_route_running',
                'short_route_running', 'spectacular_catch', 'release',
                'toughness', 'jumping', 'change_of_direction',
                'bc_vision', 'break_tackle', 'carrying',
            ],
            'TE' => [
                'speed', 'acceleration', 'catch_in_traffic', 'short_route_running',
                'medium_route_running', 'agility', 'carrying', 'bc_vision',
                'run_block', 'pass_block', 'impact_blocking',
                'jumping', 'release',
            ],
            'OT' => [
                'pass_block_finesse', 'pass_block_power', 'run_block_finesse',
                'run_block_power', 'lead_block', 'agility', 'acceleration',
            ],
            'OG' => [
                'pass_block_finesse', 'pass_block_power', 'run_block_finesse',
                'run_block_power', 'lead_block', 'agility', 'acceleration',
            ],
            'C' => [
                'pass_block_finesse', 'pass_block_power', 'run_block_finesse',
                'run_block_power', 'lead_block', 'agility', 'acceleration',
            ],
            'DE' => [
                'finesse_moves', 'power_moves', 'play_recognition',
                'hit_power', 'agility', 'jumping', 'awareness',
            ],
            'DT' => [
                'acceleration', 'pursuit', 'finesse_moves', 'hit_power',
                'jumping', 'toughness',
            ],
            'LB' => [
                'speed', 'acceleration', 'agility', 'block_shedding',
                'strength', 'jumping', 'zone_coverage', 'man_coverage',
            ],
            'CB' => [
                'man_coverage', 'zone_coverage', 'press', 'play_recognition',
                'tackle', 'pursuit', 'toughness', 'jumping',
                'catch_in_traffic', 'catching',
            ],
            'S' => [
                'zone_coverage', 'man_coverage', 'play_recognition',
                'acceleration', 'agility', 'jumping',
            ],
            'K' => ['strength', 'toughness'],
            'P' => ['strength', 'toughness'],
            'LS' => ['speed', 'acceleration'],
            'FB' => [
                'speed', 'acceleration', 'agility', 'catching',
                'awareness', 'bc_vision', 'break_tackle',
            ],
            default => ['stamina', 'toughness'],
        };

        // Remove any that are already in HIGH so they aren't double-counted
        $high = $this->getHighStats($pos, $archetype);
        return array_values(array_diff($base, $high));
    }

    /**
     * Pick N random instincts abilities from the position pool.
     */
    private function pickAbilities(string $pos, int $count): array
    {
        if ($count <= 0) {
            return [];
        }
        $pool = $this->getInstinctsPool($pos);
        if (empty($pool)) {
            return [];
        }
        shuffle($pool);
        return array_slice($pool, 0, min($count, count($pool)));
    }

    // ═══════════════════════════════════════════════════════════════════
    // JERSEY NUMBER ASSIGNMENT (unchanged)
    // ═══════════════════════════════════════════════════════════════════

    private function assignJersey(string $pos, array $used): int
    {
        $ranges = match ($pos) {
            'QB' => [1, 19],
            'RB' => [20, 49],
            'WR' => [10, 19, 80, 89],
            'TE' => [40, 49, 80, 89],
            'OT', 'OG', 'C' => [50, 79],
            'DE', 'DT' => [50, 79, 90, 99],
            'LB' => [40, 59, 90, 99],
            'CB', 'S' => [20, 49],
            'K', 'P', 'LS' => [1, 19],
            default => [1, 99],
        };

        $maxAttempts = 100;
        for ($i = 0; $i < $maxAttempts; $i++) {
            if (count($ranges) === 4) {
                $useSecond = mt_rand(0, 1);
                $min = $ranges[$useSecond * 2];
                $max = $ranges[$useSecond * 2 + 1];
            } else {
                $min = $ranges[0];
                $max = $ranges[1];
            }
            $num = mt_rand($min, $max);
            if (!in_array($num, $used)) {
                return $num;
            }
        }

        // Fallback: find any unused number
        for ($n = 1; $n <= 99; $n++) {
            if (!in_array($n, $used)) return $n;
        }
        return mt_rand(1, 99);
    }

    // ═══════════════════════════════════════════════════════════════════
    // UTILITY HELPERS
    // ═══════════════════════════════════════════════════════════════════

    private function bellCurveRating(int $center, int $stddev): int
    {
        $u1 = mt_rand(1, PHP_INT_MAX) / PHP_INT_MAX;
        $u2 = mt_rand(1, PHP_INT_MAX) / PHP_INT_MAX;
        $z = sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
        return (int) round($center + $z * $stddev);
    }

    private function weightedRandom(array $weights): string
    {
        $total = array_sum($weights);
        $rand = mt_rand(1, $total);
        $cumulative = 0;
        foreach ($weights as $value => $weight) {
            $cumulative += $weight;
            if ($rand <= $cumulative) {
                return (string) $value;
            }
        }
        return array_key_first($weights);
    }

    /**
     * Clamp a stat value between 25 and 99.
     */
    private function clamp(int $value): int
    {
        return max(25, min(99, $value));
    }
}
