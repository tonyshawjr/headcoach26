<?php

namespace App\Database\Seeds;

class TeamsSeeder
{
    /**
     * Return all 32 teams organized by conference and division.
     *
     * Each fictional team maps 1:1 to an NFL market (same city/region)
     * so that Madden roster imports slot players into the correct teams.
     *
     * AC (Atlantic Conference) ↔ AFC
     * PC (Pacific Conference) ↔ NFC
     */
    public static function getTeams(): array
    {
        return [
            // ═══ ATLANTIC CONFERENCE (AC) ═══

            // AC North
            ['city' => 'Baltimore',     'name' => 'Sentinels',    'abbreviation' => 'BAL', 'conference' => 'AC', 'division' => 'North',
             'primary_color' => '#241773', 'secondary_color' => '#000000', 'logo_emoji' => ''],
            ['city' => 'Cincinnati',    'name' => 'Forge',        'abbreviation' => 'CIN', 'conference' => 'AC', 'division' => 'North',
             'primary_color' => '#FB4F14', 'secondary_color' => '#000000', 'logo_emoji' => ''],
            ['city' => 'Cleveland',     'name' => 'Ironclads',    'abbreviation' => 'CLE', 'conference' => 'AC', 'division' => 'North',
             'primary_color' => '#311D00', 'secondary_color' => '#FF3C00', 'logo_emoji' => ''],
            ['city' => 'Pittsburgh',    'name' => 'Smokestacks',  'abbreviation' => 'PIT', 'conference' => 'AC', 'division' => 'North',
             'primary_color' => '#FFB612', 'secondary_color' => '#101820', 'logo_emoji' => ''],

            // AC South
            ['city' => 'Houston',       'name' => 'Roughnecks',   'abbreviation' => 'HOU', 'conference' => 'AC', 'division' => 'South',
             'primary_color' => '#03202F', 'secondary_color' => '#A71930', 'logo_emoji' => ''], // Texans
            ['city' => 'Indianapolis',  'name' => 'Racers',       'abbreviation' => 'IND', 'conference' => 'AC', 'division' => 'South',
             'primary_color' => '#002C5F', 'secondary_color' => '#FFFFFF', 'logo_emoji' => ''],
            ['city' => 'Jacksonville',  'name' => 'Gators',       'abbreviation' => 'JAX', 'conference' => 'AC', 'division' => 'South',
             'primary_color' => '#006778', 'secondary_color' => '#D7A22A', 'logo_emoji' => ''],
            ['city' => 'Tennessee',     'name' => 'Outlaws',      'abbreviation' => 'NSH', 'conference' => 'AC', 'division' => 'South',
             'primary_color' => '#4B92DB', 'secondary_color' => '#C8102E', 'logo_emoji' => ''],

            // AC East
            ['city' => 'Buffalo',       'name' => 'Blizzard',     'abbreviation' => 'BUF', 'conference' => 'AC', 'division' => 'East',
             'primary_color' => '#00338D', 'secondary_color' => '#C60C30', 'logo_emoji' => ''],
            ['city' => 'Miami',         'name' => 'Surge',        'abbreviation' => 'MIA', 'conference' => 'AC', 'division' => 'East',
             'primary_color' => '#008E97', 'secondary_color' => '#FC4C02', 'logo_emoji' => ''],
            ['city' => 'New England',   'name' => 'Minutemen',    'abbreviation' => 'NE',  'conference' => 'AC', 'division' => 'East',
             'primary_color' => '#002244', 'secondary_color' => '#C60C30', 'logo_emoji' => ''],
            ['city' => 'New York',      'name' => 'Titans',       'abbreviation' => 'NYT', 'conference' => 'AC', 'division' => 'East',
             'primary_color' => '#125740', 'secondary_color' => '#FFFFFF', 'logo_emoji' => ''],

            // AC West
            ['city' => 'Denver',        'name' => 'Altitude',     'abbreviation' => 'DEN', 'conference' => 'AC', 'division' => 'West',
             'primary_color' => '#FB4F14', 'secondary_color' => '#002244', 'logo_emoji' => ''],
            ['city' => 'Kansas City',   'name' => 'Arrows',       'abbreviation' => 'KC',  'conference' => 'AC', 'division' => 'West',
             'primary_color' => '#E31837', 'secondary_color' => '#FFB81C', 'logo_emoji' => ''],
            ['city' => 'Las Vegas',     'name' => 'Vipers',       'abbreviation' => 'LV',  'conference' => 'AC', 'division' => 'West',
             'primary_color' => '#A5ACAF', 'secondary_color' => '#000000', 'logo_emoji' => ''],
            ['city' => 'Los Angeles',   'name' => 'Sharks',       'abbreviation' => 'LAS', 'conference' => 'AC', 'division' => 'West',
             'primary_color' => '#0080C6', 'secondary_color' => '#FFC20E', 'logo_emoji' => ''],

            // ═══ PACIFIC CONFERENCE (PC) ═══

            // PC North
            ['city' => 'Chicago',       'name' => 'Blaze',        'abbreviation' => 'CHI', 'conference' => 'PC', 'division' => 'North',
             'primary_color' => '#0B162A', 'secondary_color' => '#C83200', 'logo_emoji' => ''],
            ['city' => 'Detroit',       'name' => 'Ironworks',    'abbreviation' => 'DET', 'conference' => 'PC', 'division' => 'North',
             'primary_color' => '#0076B6', 'secondary_color' => '#B0B7BC', 'logo_emoji' => ''],
            ['city' => 'Green Bay',     'name' => 'Tundra',       'abbreviation' => 'GB',  'conference' => 'PC', 'division' => 'North',
             'primary_color' => '#203731', 'secondary_color' => '#FFB612', 'logo_emoji' => ''],
            ['city' => 'Minnesota',     'name' => 'Frost',        'abbreviation' => 'MIN', 'conference' => 'PC', 'division' => 'North',
             'primary_color' => '#4F2683', 'secondary_color' => '#FFC62F', 'logo_emoji' => ''],

            // PC South
            ['city' => 'Atlanta',       'name' => 'Firebirds',    'abbreviation' => 'ATL', 'conference' => 'PC', 'division' => 'South',
             'primary_color' => '#A71930', 'secondary_color' => '#000000', 'logo_emoji' => ''],
            ['city' => 'Carolina',      'name' => 'Bobcats',      'abbreviation' => 'CAR', 'conference' => 'PC', 'division' => 'South',
             'primary_color' => '#0085CA', 'secondary_color' => '#101820', 'logo_emoji' => ''],
            ['city' => 'New Orleans',   'name' => 'Bayou',        'abbreviation' => 'NO',  'conference' => 'PC', 'division' => 'South',
             'primary_color' => '#D3BC8D', 'secondary_color' => '#101820', 'logo_emoji' => ''],
            ['city' => 'Tampa Bay',     'name' => 'Thunder',      'abbreviation' => 'TB',  'conference' => 'PC', 'division' => 'South',
             'primary_color' => '#D50A0A', 'secondary_color' => '#34302B', 'logo_emoji' => ''],

            // PC East
            ['city' => 'Dallas',        'name' => 'Stampede',     'abbreviation' => 'DAL', 'conference' => 'PC', 'division' => 'East',
             'primary_color' => '#003594', 'secondary_color' => '#869397', 'logo_emoji' => ''],
            ['city' => 'New York',      'name' => 'Empire',       'abbreviation' => 'NYE', 'conference' => 'PC', 'division' => 'East',
             'primary_color' => '#0B2265', 'secondary_color' => '#A71930', 'logo_emoji' => ''],
            ['city' => 'Philadelphia',  'name' => 'Liberty',      'abbreviation' => 'PHI', 'conference' => 'PC', 'division' => 'East',
             'primary_color' => '#004C54', 'secondary_color' => '#A5ACAF', 'logo_emoji' => ''],
            ['city' => 'Washington',    'name' => 'Monuments',    'abbreviation' => 'WAS', 'conference' => 'PC', 'division' => 'East',
             'primary_color' => '#5A1414', 'secondary_color' => '#FFB612', 'logo_emoji' => ''],

            // PC West
            ['city' => 'Arizona',       'name' => 'Scorpions',    'abbreviation' => 'PHX', 'conference' => 'PC', 'division' => 'West',
             'primary_color' => '#97233F', 'secondary_color' => '#000000', 'logo_emoji' => ''],
            ['city' => 'Los Angeles',   'name' => 'Quake',        'abbreviation' => 'LAQ', 'conference' => 'PC', 'division' => 'West',
             'primary_color' => '#003594', 'secondary_color' => '#FFA300', 'logo_emoji' => ''],
            ['city' => 'San Francisco', 'name' => 'Fog',          'abbreviation' => 'SF',  'conference' => 'PC', 'division' => 'West',
             'primary_color' => '#AA0000', 'secondary_color' => '#B3995D', 'logo_emoji' => ''],
            ['city' => 'Seattle',       'name' => 'Storm',        'abbreviation' => 'SEA', 'conference' => 'PC', 'division' => 'West',
             'primary_color' => '#002244', 'secondary_color' => '#69BE28', 'logo_emoji' => ''],
        ];
    }

    /**
     * Generate a fictional coach name.
     */
    public static function generateCoachName(): string
    {
        $firstNames = [
            'Mike', 'Dan', 'Bill', 'Ron', 'Jim', 'Steve', 'Tony', 'Dave',
            'Kevin', 'Brian', 'Mark', 'Jeff', 'Tom', 'Rick', 'Bob', 'Chris',
            'Gary', 'Pat', 'Dennis', 'Frank', 'Wayne', 'Greg', 'Doug', 'Ray',
            'Marcus', 'Darnell', 'Terrence', 'Andre', 'Jerome', 'Calvin',
            'Roberto', 'Carlos', 'Miguel', 'Antonio',
        ];

        $lastNames = [
            'Sullivan', 'Morrison', 'Callahan', 'Henderson', 'Brooks', 'Crawford',
            'Patterson', 'Mitchell', 'Reynolds', 'Harrison', 'Bennett', 'Campbell',
            'Peterson', 'Jenkins', 'Marshall', 'Fletcher', 'Donovan', 'Garrison',
            'Whitfield', 'Blackwell', 'Thornton', 'Chambers', 'Benson', 'Dalton',
            'Rivera', 'Sandoval', 'Freeman', 'Montgomery', 'Hawkins', 'Simmons',
            'Fitzgerald', 'O\'Brien', 'McAllister', 'Brennan',
        ];

        return $firstNames[array_rand($firstNames)] . ' ' . $lastNames[array_rand($lastNames)];
    }

    /**
     * Get the default league structure for a given team count.
     */
    public static function getDefaultStructure(int $teamCount): array
    {
        return match ($teamCount) {
            4  => ['conferences' => 1, 'divisions_per_conference' => 1, 'teams_per_division' => 4],
            6  => ['conferences' => 1, 'divisions_per_conference' => 2, 'teams_per_division' => 3],
            8  => ['conferences' => 2, 'divisions_per_conference' => 1, 'teams_per_division' => 4],
            10 => ['conferences' => 2, 'divisions_per_conference' => 1, 'teams_per_division' => 5],
            12 => ['conferences' => 2, 'divisions_per_conference' => 2, 'teams_per_division' => 3],
            14 => ['conferences' => 2, 'divisions_per_conference' => 2, 'teams_per_division' => 4], // 2 extra
            16 => ['conferences' => 2, 'divisions_per_conference' => 2, 'teams_per_division' => 4],
            20 => ['conferences' => 2, 'divisions_per_conference' => 2, 'teams_per_division' => 5],
            24 => ['conferences' => 2, 'divisions_per_conference' => 3, 'teams_per_division' => 4],
            28 => ['conferences' => 2, 'divisions_per_conference' => 2, 'teams_per_division' => 7],
            32 => ['conferences' => 2, 'divisions_per_conference' => 4, 'teams_per_division' => 4],
            default => ['conferences' => 2, 'divisions_per_conference' => 4, 'teams_per_division' => 4],
        };
    }

    /**
     * Get the first N teams from the default pool, distributed across the requested structure.
     */
    public static function getTeamsForCount(int $count, ?array $conferenceConfig = null): array
    {
        $allTeams = self::getTeams();
        if ($count >= 32 || $count >= count($allTeams)) {
            return $allTeams;
        }

        // If custom conference config provided, use it to assign teams
        if ($conferenceConfig) {
            return array_slice($allTeams, 0, $count);
        }

        return array_slice($allTeams, 0, $count);
    }

    /**
     * Get a pool of US cities for the team city dropdown.
     */
    public static function getCityPool(): array
    {
        return [
            'New York', 'Los Angeles', 'Chicago', 'Houston', 'Phoenix',
            'Philadelphia', 'San Antonio', 'San Diego', 'Dallas', 'San Jose',
            'Austin', 'Jacksonville', 'San Francisco', 'Indianapolis', 'Columbus',
            'Charlotte', 'Seattle', 'Denver', 'Nashville', 'Baltimore',
            'Boston', 'Las Vegas', 'Portland', 'Oklahoma City', 'Memphis',
            'Louisville', 'Milwaukee', 'Albuquerque', 'Tucson', 'Fresno',
            'Sacramento', 'Kansas City', 'Mesa', 'Atlanta', 'Omaha',
            'Colorado Springs', 'Raleigh', 'Long Beach', 'Virginia Beach', 'Miami',
            'Oakland', 'Minneapolis', 'Tampa', 'Tulsa', 'Arlington',
            'New Orleans', 'Cleveland', 'Pittsburgh', 'Cincinnati', 'Orlando',
            'St. Louis', 'Detroit', 'Green Bay', 'Buffalo', 'Salt Lake City',
            'Honolulu', 'Anchorage', 'Birmingham', 'Richmond', 'Hartford',
            'Carolina', 'New England',
        ];
    }

    /**
     * Get the default division names for a given count.
     */
    public static function getDefaultDivisionNames(int $count): array
    {
        return match ($count) {
            1 => ['Division'],
            2 => ['North', 'South'],
            3 => ['North', 'South', 'Central'],
            4 => ['North', 'South', 'East', 'West'],
            default => array_map(fn($i) => "Division " . ($i + 1), range(0, $count - 1)),
        };
    }

    /**
     * Get the default conference names.
     */
    public static function getDefaultConferenceNames(int $count): array
    {
        return match ($count) {
            1 => [['name' => 'League', 'abbreviation' => 'LG']],
            2 => [
                ['name' => 'Atlantic Conference', 'abbreviation' => 'AC'],
                ['name' => 'Pacific Conference', 'abbreviation' => 'PC'],
            ],
            default => array_map(fn($i) => [
                'name' => 'Conference ' . ($i + 1),
                'abbreviation' => 'C' . ($i + 1),
            ], range(0, $count - 1)),
        };
    }

    /**
     * Get coach archetype options.
     */
    public static function getCoachArchetypes(): array
    {
        return ['rebuilder', 'win_now', 'conservative', 'gambler', 'developer'];
    }
}
