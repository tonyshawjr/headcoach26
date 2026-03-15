<?php

namespace App\Services;

use App\Database\Connection;

/**
 * Import Madden 26 roster CSV into the Head Coach 26 database.
 *
 * Handles position mapping (NFL → our system), NFL-to-fictional team mapping,
 * stat column translation (camelCase → snake_case), and depth-chart regeneration.
 */
class MaddenRosterImporter
{
    private \PDO $db;

    // ─── Position mapping: Madden position → our position ───────────────
    private const POSITION_MAP = [
        'QB'   => 'QB',
        'HB'   => 'RB',
        'FB'   => 'RB',
        'WR'   => 'WR',
        'TE'   => 'TE',
        'LT'   => 'OT',
        'RT'   => 'OT',
        'LG'   => 'OG',
        'RG'   => 'OG',
        'C'    => 'C',
        'LE'   => 'DE',
        'RE'   => 'DE',
        'DT'   => 'DT',
        'MLB'  => 'LB',
        'ROLB' => 'LB',
        'LOLB' => 'LB',
        'CB'   => 'CB',
        'SS'   => 'S',
        'FS'   => 'S',
        'K'    => 'K',
        'P'    => 'P',
        'LS'   => 'LS',
    ];

    // ─── Archetype suffix → our position (fallback when Position column is blank) ──
    // Madden archetypes look like "Power Rusher - DE", "Run Stopper - OLB", etc.
    private const ARCHETYPE_POSITION_MAP = [
        'DE'  => 'DE',
        'DT'  => 'DT',
        'OLB' => 'LB',
        'MLB' => 'LB',
        'CB'  => 'CB',
        'SS'  => 'S',
        'FS'  => 'S',
        'QB'  => 'QB',
        'HB'  => 'RB',
        'FB'  => 'RB',
        'WR'  => 'WR',
        'TE'  => 'TE',
        'LT'  => 'OT',
        'RT'  => 'OT',
        'LG'  => 'OG',
        'RG'  => 'OG',
        'C'   => 'C',
        'K'   => 'K',
        'P'   => 'P',
        'LE'  => 'DE',
        'RE'  => 'DE',
        'LS'  => 'LS',
    ];

    // Positions to skip entirely (not in our system)
    private const SKIP_POSITIONS = [];

    // Valid positions in our system
    private const VALID_POSITIONS = [
        'QB', 'RB', 'WR', 'TE', 'OT', 'OG', 'C',
        'DE', 'DT', 'LB', 'CB', 'S', 'K', 'P', 'LS',
    ];

    // ─── Stat column mapping: Madden CSV header → our DB column ────────
    private const STAT_MAP = [
        'stats/acceleration/value'       => 'acceleration',
        'stats/agility/value'            => 'agility',
        'stats/jumping/value'            => 'jumping',
        'stats/stamina/value'            => 'stamina',
        'stats/strength/value'           => 'strength',
        'stats/awareness/value'          => 'awareness',
        'stats/bCVision/value'           => 'bc_vision',
        'stats/blockShedding/value'      => 'block_shedding',
        'stats/breakSack/value'          => 'break_sack',
        'stats/breakTackle/value'        => 'break_tackle',
        'stats/carrying/value'           => 'carrying',
        'stats/catchInTraffic/value'     => 'catch_in_traffic',
        'stats/catching/value'           => 'catching',
        'stats/changeOfDirection/value'  => 'change_of_direction',
        'stats/deepRouteRunning/value'   => 'deep_route_running',
        'stats/finesseMoves/value'       => 'finesse_moves',
        'stats/hitPower/value'           => 'hit_power',
        'stats/impactBlocking/value'     => 'impact_blocking',
        'stats/injury/value'             => 'injury_prone',
        'stats/jukeMove/value'           => 'juke_move',
        'stats/kickAccuracy/value'       => 'kick_accuracy',
        'stats/kickPower/value'          => 'kick_power',
        'stats/kickReturn/value'         => 'kick_return',
        'stats/leadBlock/value'          => 'lead_block',
        'stats/manCoverage/value'        => 'man_coverage',
        'stats/mediumRouteRunning/value' => 'medium_route_running',
        'stats/overall/value'            => 'overall_rating',
        'stats/passBlock/value'          => 'pass_block',
        'stats/passBlockFinesse/value'   => 'pass_block_finesse',
        'stats/passBlockPower/value'     => 'pass_block_power',
        'stats/playAction/value'         => 'play_action',
        'stats/playRecognition/value'    => 'play_recognition',
        'stats/powerMoves/value'         => 'power_moves',
        'stats/press/value'              => 'press',
        'stats/pursuit/value'            => 'pursuit',
        'stats/release/value'            => 'release',
        'stats/runBlock/value'           => 'run_block',
        'stats/runBlockFinesse/value'    => 'run_block_finesse',
        'stats/runBlockPower/value'      => 'run_block_power',
        'stats/runningStyle/value'       => 'running_style',
        'stats/shortRouteRunning/value'  => 'short_route_running',
        'stats/spectacularCatch/value'   => 'spectacular_catch',
        'stats/speed/value'              => 'speed',
        'stats/spinMove/value'           => 'spin_move',
        'stats/stiffArm/value'           => 'stiff_arm',
        'stats/tackle/value'             => 'tackle',
        'stats/throwAccuracyDeep/value'  => 'throw_accuracy_deep',
        'stats/throwAccuracyMid/value'   => 'throw_accuracy_mid',
        'stats/throwAccuracyShort/value' => 'throw_accuracy_short',
        'stats/throwOnTheRun/value'      => 'throw_on_the_run',
        'stats/throwPower/value'         => 'throw_power',
        'stats/throwUnderPressure/value' => 'throw_under_pressure',
        'stats/toughness/value'          => 'toughness',
        'stats/trucking/value'           => 'trucking',
        'stats/zoneCoverage/value'       => 'zone_coverage',
    ];

    // ─── NFL team → fictional team mapping ─────────────────────────────
    // Mapped by conference/division similarity:
    //   AFC ↔ AC (Atlantic Conference)
    //   NFC ↔ PC (Pacific Conference)
    //
    // AFC East  → AC East    |  NFC East  → PC East
    // AFC North → AC North   |  NFC North → PC North
    // AFC South → AC South   |  NFC South → PC South (partial) + PC East (partial)
    // Each NFL team maps to its same-market fictional team.
    //
    private const NFL_TEAM_MAP = [
        // AFC North → AC North
        'Baltimore Ravens'       => 'BAL',  // Baltimore Sentinels
        'Cincinnati Bengals'     => 'CIN',  // Cincinnati Forge
        'Cleveland Browns'       => 'CLE',  // Cleveland Ironclads
        'Pittsburgh Steelers'    => 'PIT',  // Pittsburgh Smokestacks

        // AFC South → AC South
        'Houston Texans'         => 'HOU',  // Houston Roughnecks
        'Indianapolis Colts'     => 'IND',  // Indianapolis Racers
        'Jacksonville Jaguars'   => 'JAX',  // Jacksonville Gators
        'Tennessee Titans'       => 'NSH',  // Nashville Outlaws

        // AFC East → AC East
        'Buffalo Bills'          => 'BUF',  // Buffalo Blizzard
        'Miami Dolphins'         => 'MIA',  // Miami Surge
        'New England Patriots'   => 'NE',   // New England Minutemen
        'NY Jets'                => 'NYT',  // New York Titans

        // AFC West → AC West
        'Denver Broncos'         => 'DEN',  // Denver Altitude
        'Kansas City Chiefs'     => 'KC',   // Kansas City Arrows
        'Las Vegas Raiders'      => 'LV',   // Las Vegas Vipers
        'Los Angeles Chargers'   => 'LAS',  // Los Angeles Sharks

        // NFC North → PC North
        'Chicago Bears'          => 'CHI',  // Chicago Blaze
        'Detroit Lions'          => 'DET',  // Detroit Ironworks
        'Green Bay Packers'      => 'GB',   // Green Bay Tundra
        'Minnesota Vikings'      => 'MIN',  // Minnesota Frost

        // NFC South → PC South
        'Atlanta Falcons'        => 'ATL',  // Atlanta Firebirds
        'Carolina Panthers'      => 'CAR',  // Carolina Bobcats
        'New Orleans Saints'     => 'NO',   // New Orleans Bayou
        'Tampa Bay Buccaneers'   => 'TB',   // Tampa Bay Thunder

        // NFC East → PC East
        'Dallas Cowboys'         => 'DAL',  // Dallas Stampede
        'NY Giants'              => 'NYE',  // New York Empire
        'Philadelphia Eagles'    => 'PHI',  // Philadelphia Liberty
        'Washington Commanders'  => 'WAS',  // Washington Monuments

        // NFC West → PC West
        'Arizona Cardinals'      => 'PHX',  // Phoenix Scorpions
        'Los Angeles Rams'       => 'LAQ',  // Los Angeles Quake
        'San Francisco 49ers'    => 'SF',   // San Francisco Fog
        'Seattle Seahawks'       => 'SEA',  // Seattle Storm
    ];

    // ─── Personality options (same as PlayerGenerator) ──────────────────
    private const PERSONALITIES = [
        'team_player', 'competitor', 'quiet_professional',
        'vocal_leader', 'troublemaker',
    ];

    // ─── Madden → HC26 ability name conversion maps ─────────────────────
    // When importing from Madden CSV, these convert EA's names to our original names.
    private const EDGE_NAME_MAP = [
        // QB
        'Bazooka'            => 'Cannon Arm',
        'Fearless'           => 'Pocket Poise',
        'Truzz'              => 'Iron Will',
        'Pass Lead Elite'    => 'Precision Aim',
        'Dual Threat'        => 'Two-Way Weapon',
        'Lofting Deadeye'    => 'Touch Passer',
        'Run & Gun'          => 'Gunfire',
        'Gutsy Scrambler'    => 'Scramble Sense',
        'Escape Artist'      => 'Pocket Escape',
        'Phenom'             => 'Prodigy',
        // RB/FB
        'First One Free'     => 'Clean Break',
        'Freight Train'      => 'Locomotive',
        'Ankle Breaker'      => 'Ankle Snap',
        'Wrecking Ball'      => 'Battering Ram',
        'Bruiser'            => 'Punisher',
        'Juke Box'           => 'Hip Shake',
        'Speedster'          => 'Burner',
        'Ironman'            => 'Workhorse',
        'Momentum Shift'     => 'Turning Point',
        // WR
        'Double Me'          => 'Uncoverable',
        'Matchup Nightmare'  => 'Mismatch',
        'Route Technician'   => 'Route Surgeon',
        'Deep In Elite'      => 'Deep Post',
        'Deep Out Elite'     => 'Deep Corner',
        'Mid In Elite'       => 'Crossing Expert',
        'Mid Out Elite'      => 'Comeback King',
        'Short In Elite'     => 'Slant Specialist',
        'Short Out Elite'    => 'Out Route Pro',
        'Deep Route KO'      => 'Deep Neutralizer',
        'Mid Zone KO'        => 'Zone Buster',
        'Short Route KO'     => 'Short Circuit',
        'Slot-O-Matic'       => 'Slot Machine',
        'Natural Talent'     => 'Raw Ability',
        "RAC 'em Up"         => 'After the Catch',
        "RAC \xe2\x80\x98em Up" => 'After the Catch',
        "YAC 'Em Up"         => 'Yards After Contact',
        "YAC \xe2\x80\x98Em Up" => 'Yards After Contact',
        'Reach For It'       => 'Extra Effort',
        'Reach Elite'        => 'Extension Play',
        // TE
        'B.O.G.O.'           => 'Two-for-One',
        // OL
        'Post Up'            => 'Brick Wall',
        'All Day'            => 'Marathon',
        'Edge Protector'     => 'Edge Seal',
        'Secure Protector'   => 'Sure Hands',
        'Puller Elite'       => 'Road Grader',
        'Anchored Extender'  => 'Play Extender',
        'Linchpin'           => 'Anchor Point',
        'El Toro'            => 'Bull Rush',
        'Runoff Elite'       => 'Drive Finisher',
        'Screen Protector'   => 'Screen Shield',
        // DL
        'Fearmonger'         => 'Terror',
        'Unstoppable Force'  => 'Juggernaut',
        'Edge Threat'        => 'Speed Rush',
        'Inside Stuff'       => 'Interior Pressure',
        'Under Pressure'     => 'Closer',
        'Swim Club'          => 'Swim Artist',
        'Run Stopper'        => 'Gap Plugger',
        'Nasty Streak'       => 'Mean Streak',
        'Demoralizer'        => 'Spirit Breaker',
        'Bottleneck'         => 'Lane Clogger',
        'Tackle Supreme'     => 'Sure Tackler',
        'Out My Way'         => 'Pancake Artist',
        // LB
        'Reinforcement'      => 'Cavalry',
        'Shutdown'           => 'Lockdown',
        'Blitz'              => 'Heat Seeker',
        'Universal Coverage'  => 'Blanket Coverage',
        'Enforcer'           => 'Hard Hitter',
        'Relentless'         => 'Fourth Quarter',
        'Pick Artist'        => 'Ball Hawk',
        'On The Ball'        => 'Instinctive',
        // CB
        'Acrobat'            => 'High Wire',
        'No Outsiders'       => 'Boundary Lock',
        'Inside Shade'       => 'Inside Leverage',
        'Outside Shade'      => 'Outside Leverage',
        'Max Security'       => 'Blanket Shadow',
        // S
        'Fool Me Once'       => 'Pattern Reader',
        // K/P
        'Zen Kicker'         => 'Ice Water',
        'Arm Bar'            => 'Stiff Arm Pro',
    ];

    private const INSTINCT_NAME_MAP = [
        // QB
        'Dashing Deadeye'    => 'Run-Pass Read',
        'Gutsy Scrambler'    => 'Scramble Sense',
        'Set Feet Lead'      => 'Plant & Fire',
        'Sideline Deadeye'   => 'Boundary Accuracy',
        'Gunslinger'         => 'Rocket Arm',
        'No-Look Deadeye'    => 'No-Look Pass',
        'Anchored Extender'  => 'Play Extender',
        'Pass Lead Elite'    => 'Precision Aim',
        'Inside Deadeye'     => 'Interior Accuracy',
        'High Point Deadeye' => 'Skyball Accuracy',
        'Red Zone Deadeye'   => 'Goal Line Precision',
        'Roaming Deadeye'    => 'Moving Target',
        'Fearless'           => 'Pocket Poise',
        'Omniscient'         => 'Field Vision',
        'Playmaker'          => 'Play Designer',
        'Unpredictable'      => 'Wildcard',
        // RB/FB
        'Arm Bar'            => 'Stiff Arm Pro',
        'Backfield Mismatch' => 'Receiving Threat',
        'Evasive'            => 'Ghost Runner',
        'Juke Box'           => 'Hip Shake',
        'Bruiser'            => 'Punisher',
        'Reach For It'       => 'Extra Effort',
        'Human Joystick'     => 'Wiggle',
        'Speedster'          => 'Burner',
        'Leap Frog'          => 'Hurdler',
        'Goal Line Back'     => 'Short Yardage',
        'Fastbreak'          => 'Quick Strike',
        'Energizer'          => 'Second Wind',
        'Extra Credit'       => 'Bonus Yards',
        // WR/TE
        'Acrobat'            => 'High Wire',
        'Deep In Elite'      => 'Deep Post',
        'Deep Out Elite'     => 'Deep Corner',
        'Mid In Elite'       => 'Crossing Expert',
        'Mid Out Elite'      => 'Comeback King',
        'Short In Elite'     => 'Slant Specialist',
        'Short Out Elite'    => 'Out Route Pro',
        'Route Technician'   => 'Route Surgeon',
        'Matchup Nightmare'  => 'Mismatch',
        'Nasty Streak'       => 'Mean Streak',
        'Deep Route KO'      => 'Deep Neutralizer',
        'Mid Zone KO'        => 'Zone Buster',
        'Short Route KO'     => 'Short Circuit',
        'Deep In Zone KO'    => 'Deep Interior Breaker',
        'Deep Out Zone KO'   => 'Deep Boundary Breaker',
        'Flat Zone KO'       => 'Flat Buster',
        'Natural Talent'     => 'Raw Ability',
        'B.O.G.O.'           => 'Two-for-One',
        'Tight Out'          => 'Seam Stretcher',
        'Reach Elite'        => 'Extension Play',
        '3rd Down Threat'    => 'Chain Mover',
        'Red Zone Threat'    => 'Scoring Threat',
        // OL
        'Post Up'            => 'Brick Wall',
        'Secure Protector'   => 'Sure Hands',
        'Edge Protector'     => 'Edge Seal',
        'All Day'            => 'Marathon',
        'Puller Elite'       => 'Road Grader',
        'El Toro'            => 'Bull Rush',
        'Runoff Elite'       => 'Drive Finisher',
        'Steamroller'        => 'Flattener',
        'Run Protector'      => 'Run Lane Guard',
        'Lumberjack'         => 'Timber Block',
        // DL
        'Edge Threat'        => 'Speed Rush',
        'Edge Threat Elite'  => 'Relentless Rush',
        'Inside Stuff'       => 'Interior Pressure',
        'Under Pressure'     => 'Closer',
        'Swim Club'          => 'Swim Artist',
        'Run Stopper'        => 'Gap Plugger',
        'Interior Threat'    => 'Inside Havoc',
        'Demoralizer'        => 'Spirit Breaker',
        'Tackle Supreme'     => 'Sure Tackler',
        'Out My Way'         => 'Pancake Artist',
        'Deflator'           => 'Pass Deflector',
        'Extra Pop'          => 'Knockout Blow',
        'Tank'               => 'Immovable Object',
        // LB
        'Enforcer Supreme'   => 'Enforcer Elite',
        'Lurk Artist'        => 'Zone Reader',
        'Universal Coverage'  => 'Blanket Coverage',
        'Blitz'              => 'Heat Seeker',
        'Strip Specialist'   => 'Ball Stripper',
        'Outmatched'         => 'Overpowered',
        'Form Tackler'       => 'Textbook Tackler',
        'Secure Tackler'     => 'Wrap-Up Artist',
        'Goal Line Stuff'    => 'Goal Line Wall',
        'Recuperation'       => 'Quick Recovery',
        'Backlash'           => 'Counter Strike',
        // CB
        'One Step Ahead'     => 'Mirror Step',
        'Pick Artist'        => 'Ball Hawk',
        'Bench Press'        => 'Press Master',
        'Inside Shade'       => 'Inside Leverage',
        'Outside Shade'      => 'Outside Leverage',
        'Fool Me Once'       => 'Pattern Reader',
        'No Outsiders'       => 'Boundary Lock',
        'Tip Drill'          => 'Deflection Drill',
        'Unfakeable'         => 'Eyes Discipline',
        'Instant Rebate'     => 'Quick Turnover',
    ];

    // ─── Depth chart starter slots (same as installer step 3) ──────────
    private const DEPTH_CHART_POSITIONS = [
        'QB', 'RB', 'WR', 'WR', 'WR', 'TE', 'OT', 'OT', 'OG', 'OG', 'C',
        'DE', 'DE', 'DT', 'DT', 'LB', 'LB', 'LB', 'CB', 'CB', 'S', 'S', 'K', 'P', 'LS',
    ];

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
    }

    // ════════════════════════════════════════════════════════════════════
    //  PUBLIC API
    // ════════════════════════════════════════════════════════════════════

    /**
     * Validate a Madden CSV before importing.
     *
     * @return array{valid: bool, total_rows: int, warnings: string[], errors: string[], headers?: string[]}
     */
    public function validate(string $csvPath): array
    {
        $report = ['valid' => true, 'total_rows' => 0, 'warnings' => [], 'errors' => []];

        if (!file_exists($csvPath)) {
            $report['valid'] = false;
            $report['errors'][] = "File not found: {$csvPath}";
            return $report;
        }

        $handle = fopen($csvPath, 'r');
        if (!$handle) {
            $report['valid'] = false;
            $report['errors'][] = "Cannot open file: {$csvPath}";
            return $report;
        }

        $headers = fgetcsv($handle, 0, ',', '"', '');
        if (!$headers || count($headers) < 10) {
            $report['valid'] = false;
            $report['errors'][] = 'CSV must have a header row with at least 10 columns';
            fclose($handle);
            return $report;
        }

        $headers = array_map('trim', $headers);
        $report['headers'] = $headers;

        // Check for key expected columns
        $required = ['lastName', 'Position', 'overallRating'];
        foreach ($required as $col) {
            if (!in_array($col, $headers)) {
                $report['valid'] = false;
                $report['errors'][] = "Missing expected column: {$col}";
            }
        }

        // Check first column is firstName (could be "Costa Vida" or "firstName")
        $firstHeader = $headers[0];
        if ($firstHeader !== 'firstName' && $firstHeader !== 'Costa Vida') {
            $report['warnings'][] = "First column header is '{$firstHeader}' (expected 'firstName' or 'Costa Vida')";
        }

        // Check stat columns
        $statHeaders = array_filter($headers, fn($h) => str_starts_with($h, 'stats/') && str_ends_with($h, '/value'));
        $report['stat_columns_found'] = count($statHeaders);

        $missingStats = [];
        foreach (array_keys(self::STAT_MAP) as $expected) {
            if (!in_array($expected, $headers)) {
                $missingStats[] = $expected;
            }
        }
        if (!empty($missingStats)) {
            $report['warnings'][] = 'Missing stat columns (defaults will be used): ' . implode(', ', array_slice($missingStats, 0, 10));
        }

        // Count rows
        $rowCount = 0;
        while (fgetcsv($handle, 0, ',', '"', '') !== false) {
            $rowCount++;
        }
        fclose($handle);
        $report['total_rows'] = $rowCount;

        if ($rowCount === 0) {
            $report['valid'] = false;
            $report['errors'][] = 'CSV has no data rows';
        }

        return $report;
    }

    /**
     * Import Madden roster CSV into the league.
     *
     * @return array{total_rows: int, imported: int, skipped: int, errors: string[]}
     */
    public function import(int $leagueId, string $csvPath): array
    {
        // ── Validate first ──────────────────────────────────────────────
        $validation = $this->validate($csvPath);
        if (!$validation['valid']) {
            return [
                'total_rows' => 0,
                'imported'   => 0,
                'skipped'    => 0,
                'errors'     => $validation['errors'],
            ];
        }

        // ── Build team abbreviation → id map ────────────────────────────
        $teamMap = $this->buildTeamMap($leagueId);
        if (empty($teamMap)) {
            return [
                'total_rows' => 0,
                'imported'   => 0,
                'skipped'    => 0,
                'errors'     => ['No teams found for league ' . $leagueId . '. Please create the league first.'],
            ];
        }

        // ── Clear existing players and depth charts for this league ─────
        $this->clearLeagueRoster($leagueId, $teamMap);

        // ── Parse and import ────────────────────────────────────────────
        $handle = fopen($csvPath, 'r');
        $headers = array_map('trim', fgetcsv($handle, 0, ',', '"', ''));

        // Build column-index lookup
        $colIndex = array_flip($headers);

        // Identify the firstName column (could be "Costa Vida" or "firstName")
        $firstNameKey = 'firstName';
        if (!isset($colIndex[$firstNameKey])) {
            // Fallback: use the very first column regardless of header name
            $firstNameKey = $headers[0];
        }

        // Build stat column index map: CSV header → our DB column name
        $statColMap = [];
        foreach (self::STAT_MAP as $csvHeader => $dbCol) {
            if (isset($colIndex[$csvHeader])) {
                $statColMap[$colIndex[$csvHeader]] = $dbCol;
            }
        }

        $imported = 0;
        $skipped = 0;
        $errors = [];
        $skipReasons = [];  // Categorized skip tracking
        $rowNum = 1; // 1-based (header was row 0)

        while (($fields = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $rowNum++;

            if (count($fields) < 10) {
                $errors[] = "Row {$rowNum}: insufficient columns (" . count($fields) . ')';
                $skipped++;
                $skipReasons['bad_row'][] = "Row {$rowNum}: only " . count($fields) . " columns";
                continue;
            }

            $firstName = trim($fields[$colIndex[$firstNameKey]] ?? '');
            $lastName  = trim($fields[$colIndex['lastName']] ?? '');

            // Skip rows with no name
            if ($firstName === '' && $lastName === '') {
                $skipped++;
                $skipReasons['no_name'][] = "Row {$rowNum}: blank name";
                continue;
            }

            // ── Position ────────────────────────────────────────────────
            $maddenPos = strtoupper(trim($fields[$colIndex['Position']] ?? ''));
            $archetype = trim($fields[$colIndex['Archetype']] ?? '');

            if (in_array($maddenPos, self::SKIP_POSITIONS)) {
                $skipped++;
                $skipReasons['unsupported_position'][] = "{$firstName} {$lastName} ({$maddenPos})";
                continue;
            }

            $position = null;
            if ($maddenPos !== '') {
                $position = self::POSITION_MAP[$maddenPos] ?? null;
            }

            // Fallback: extract position from Archetype (e.g. "Power Rusher - DE" → DE)
            if (!$position && $archetype !== '') {
                $dashPos = strrpos($archetype, ' - ');
                if ($dashPos !== false) {
                    $archetypeSuffix = strtoupper(trim(substr($archetype, $dashPos + 3)));
                    $position = self::ARCHETYPE_POSITION_MAP[$archetypeSuffix] ?? null;
                }
            }

            if (!$position || !in_array($position, self::VALID_POSITIONS)) {
                $playerLabel = ($firstName || $lastName) ? "{$firstName} {$lastName}" : "Row {$rowNum}";
                $errors[] = "{$playerLabel}: unknown position '{$maddenPos}'" . ($archetype ? " archetype '{$archetype}'" : '');
                $skipped++;
                $skipReasons['unknown_position'][] = "{$playerLabel} (pos='{$maddenPos}', arch='{$archetype}')";
                continue;
            }

            // ── Team ────────────────────────────────────────────────────
            $nflTeam = trim($fields[$colIndex['Team']] ?? '');
            $teamId = null;
            $status = 'free_agent';

            if ($nflTeam !== '') {
                $abbr = self::NFL_TEAM_MAP[$nflTeam] ?? null;
                if ($abbr && isset($teamMap[$abbr])) {
                    $teamId = $teamMap[$abbr];
                    $status = 'active';
                } else {
                    $errors[] = "Row {$rowNum}: unmapped NFL team '{$nflTeam}'";
                    // Still import as free agent
                }
            }

            // ── Basic bio columns ───────────────────────────────────────
            $age           = max(18, min(50, (int) ($fields[$colIndex['Age']] ?? 22)));
            $overallRating = max(40, min(99, (int) ($fields[$colIndex['overallRating']] ?? 60)));
            $college       = trim($fields[$colIndex['college']] ?? 'Unknown') ?: 'Unknown';
            $birthdate     = trim($fields[$colIndex['birthdate']] ?? '');
            $height        = (int) ($fields[$colIndex['height']] ?? 0) ?: null;
            $weight        = (int) ($fields[$colIndex['weight']] ?? 0) ?: null;
            $handedness    = (int) ($fields[$colIndex['handedness']] ?? 1);
            $jerseyNumber  = (int) ($fields[$colIndex['Jersey Number']] ?? mt_rand(1, 99));
            $yearsPro      = max(0, (int) ($fields[$colIndex['yearsPro']] ?? 0));

            // Metadata — read from Madden CSV columns, convert to our names
            $rawEdge    = trim($fields[$colIndex['X-Factor']] ?? '') ?: null;
            $edge       = $rawEdge ? (self::EDGE_NAME_MAP[$rawEdge] ?? $rawEdge) : null;
            $archetype  = trim($fields[$colIndex['Archetype']] ?? '') ?: null;
            $posType    = trim($fields[$colIndex['Position Type']] ?? '') ?: null;

            // Instincts — combine non-empty values from Madden CSV 'Superstar' columns, convert names
            $instincts = [];
            for ($s = 1; $s <= 5; $s++) {
                $key = "Superstar {$s}";
                if (isset($colIndex[$key])) {
                    $val = trim($fields[$colIndex[$key]] ?? '');
                    if ($val !== '') {
                        $instincts[] = self::INSTINCT_NAME_MAP[$val] ?? $val;
                    }
                }
            }

            // ── Stat columns ────────────────────────────────────────────
            $stats = [];
            foreach ($statColMap as $csvIdx => $dbCol) {
                $raw = trim($fields[$csvIdx] ?? '');
                if ($dbCol === 'running_style') {
                    // Text field, keep as-is
                    $stats[$dbCol] = ($raw !== '' && $raw !== '0') ? $raw : null;
                } else {
                    $stats[$dbCol] = ($raw !== '') ? max(0, min(99, (int) $raw)) : 50;
                }
            }

            // Use the stats/overall/value if present; otherwise fall back to the overallRating column
            $finalOverall = $stats['overall_rating'] ?? $overallRating;
            $stats['overall_rating'] = max(40, min(99, (int) $finalOverall));

            // ── Derived fields ──────────────────────────────────────────
            $isRookie   = ($yearsPro === 0) ? 1 : 0;
            $experience = max(0, $yearsPro);
            $potential   = $this->inferPotential($stats['overall_rating'], $age);
            $personality = self::PERSONALITIES[array_rand(self::PERSONALITIES)];

            // ── Build INSERT data ───────────────────────────────────────
            $playerData = [
                'league_id'            => $leagueId,
                'team_id'              => $teamId,
                'first_name'           => $firstName,
                'last_name'            => $lastName,
                'position'             => $position,
                'age'                  => $age,
                'overall_rating'       => $stats['overall_rating'],
                'speed'                => $stats['speed'] ?? 50,
                'strength'             => $stats['strength'] ?? 50,
                'awareness'            => $stats['awareness'] ?? 50,
                'stamina'              => $stats['stamina'] ?? 50,
                'injury_prone'         => $stats['injury_prone'] ?? 20,
                'positional_ratings'   => null, // Will be built separately if needed
                'potential'            => $potential,
                'personality'          => $personality,
                'morale'               => 'content',
                'experience'           => $experience,
                'college'              => $college,
                'jersey_number'        => $jerseyNumber,
                'is_rookie'            => $isRookie,
                'is_fictional'         => 0,
                'status'               => $status,
                'created_at'           => date('Y-m-d H:i:s'),

                // Migration 007 columns
                'height'               => $height,
                'weight'               => $weight,
                'handedness'           => $handedness,
                'birthdate'            => $birthdate ?: null,
                'years_pro'            => $yearsPro,
                'archetype'            => $archetype,
                'position_type'        => $posType,
                'x_factor'             => $edge,
                'superstar_abilities'  => !empty($instincts) ? json_encode($instincts) : null,

                // Physical
                'acceleration'         => $stats['acceleration'] ?? 50,
                'agility'              => $stats['agility'] ?? 50,
                'jumping'              => $stats['jumping'] ?? 50,
                'toughness'            => $stats['toughness'] ?? 50,

                // Ball Carrier
                'bc_vision'            => $stats['bc_vision'] ?? 50,
                'break_tackle'         => $stats['break_tackle'] ?? 50,
                'carrying'             => $stats['carrying'] ?? 50,
                'change_of_direction'  => $stats['change_of_direction'] ?? 50,
                'juke_move'            => $stats['juke_move'] ?? 50,
                'spin_move'            => $stats['spin_move'] ?? 50,
                'stiff_arm'            => $stats['stiff_arm'] ?? 50,
                'trucking'             => $stats['trucking'] ?? 50,

                // Receiving
                'catch_in_traffic'     => $stats['catch_in_traffic'] ?? 50,
                'catching'             => $stats['catching'] ?? 50,
                'deep_route_running'   => $stats['deep_route_running'] ?? 50,
                'medium_route_running' => $stats['medium_route_running'] ?? 50,
                'short_route_running'  => $stats['short_route_running'] ?? 50,
                'spectacular_catch'    => $stats['spectacular_catch'] ?? 50,
                'release'              => $stats['release'] ?? 50,

                // Blocking
                'impact_blocking'      => $stats['impact_blocking'] ?? 50,
                'lead_block'           => $stats['lead_block'] ?? 50,
                'pass_block'           => $stats['pass_block'] ?? 50,
                'pass_block_finesse'   => $stats['pass_block_finesse'] ?? 50,
                'pass_block_power'     => $stats['pass_block_power'] ?? 50,
                'run_block'            => $stats['run_block'] ?? 50,
                'run_block_finesse'    => $stats['run_block_finesse'] ?? 50,
                'run_block_power'      => $stats['run_block_power'] ?? 50,

                // Defense
                'block_shedding'       => $stats['block_shedding'] ?? 50,
                'finesse_moves'        => $stats['finesse_moves'] ?? 50,
                'hit_power'            => $stats['hit_power'] ?? 50,
                'man_coverage'         => $stats['man_coverage'] ?? 50,
                'play_recognition'     => $stats['play_recognition'] ?? 50,
                'power_moves'          => $stats['power_moves'] ?? 50,
                'press'                => $stats['press'] ?? 50,
                'pursuit'              => $stats['pursuit'] ?? 50,
                'tackle'               => $stats['tackle'] ?? 50,
                'zone_coverage'        => $stats['zone_coverage'] ?? 50,

                // QB
                'break_sack'           => $stats['break_sack'] ?? 50,
                'play_action'          => $stats['play_action'] ?? 50,
                'throw_accuracy_deep'  => $stats['throw_accuracy_deep'] ?? 50,
                'throw_accuracy_mid'   => $stats['throw_accuracy_mid'] ?? 50,
                'throw_accuracy_short' => $stats['throw_accuracy_short'] ?? 50,
                'throw_on_the_run'     => $stats['throw_on_the_run'] ?? 50,
                'throw_power'          => $stats['throw_power'] ?? 50,
                'throw_under_pressure' => $stats['throw_under_pressure'] ?? 50,

                // Kicking
                'kick_accuracy'        => $stats['kick_accuracy'] ?? 50,
                'kick_power'           => $stats['kick_power'] ?? 50,
                'kick_return'          => $stats['kick_return'] ?? 50,

                // Other
                'running_style'        => $stats['running_style'] ?? null,
            ];

            try {
                $columns = implode(', ', array_keys($playerData));
                $placeholders = implode(', ', array_fill(0, count($playerData), '?'));
                $this->db->prepare(
                    "INSERT INTO players ({$columns}) VALUES ({$placeholders})"
                )->execute(array_values($playerData));
                $imported++;
            } catch (\PDOException $e) {
                $errors[] = "{$firstName} {$lastName}: DB error - " . $e->getMessage();
                $skipped++;
                $skipReasons['db_error'][] = "{$firstName} {$lastName}";
            }
        }

        fclose($handle);

        // ── Post-import: depth charts + team ratings ────────────────────
        $teamIds = array_values($teamMap);
        foreach ($teamIds as $tId) {
            $this->generateDepthChart($tId);
            $this->recalculateTeamRating($tId);
        }

        // ── Create free_agents entries for players imported without a team ──
        $faStmt = $this->db->prepare(
            "INSERT INTO free_agents (league_id, player_id, asking_salary, market_value, status, released_at)
             SELECT ?, id, 750000, 750000, 'available', ?
             FROM players WHERE league_id = ? AND (team_id IS NULL OR status = 'free_agent')
             AND id NOT IN (SELECT player_id FROM free_agents WHERE league_id = ?)"
        );
        $faStmt->execute([$leagueId, date('Y-m-d H:i:s'), $leagueId, $leagueId]);

        $totalRows = $imported + $skipped;

        // Build human-readable skip summary
        $skipSummary = [];
        $reasonLabels = [
            'unsupported_position' => 'Unsupported position (not in our system)',
            'no_name'             => 'No name (blank row)',
            'unknown_position'    => 'Unknown/unmappable position',
            'bad_row'             => 'Malformed row (too few columns)',
            'db_error'            => 'Database error during insert',
        ];
        foreach ($skipReasons as $reason => $entries) {
            $label = $reasonLabels[$reason] ?? $reason;
            $count = count($entries);
            // Show first few player names for context
            $examples = array_slice($entries, 0, 5);
            $exampleStr = implode(', ', $examples);
            if ($count > 5) $exampleStr .= ' ...and ' . ($count - 5) . ' more';
            $skipSummary[] = "{$label}: {$count} — {$exampleStr}";
        }

        return [
            'total_rows'   => $totalRows,
            'imported'     => $imported,
            'skipped'      => $skipped,
            'errors'       => array_slice($errors, 0, 50),
            'skip_summary' => $skipSummary,
        ];
    }

    // ════════════════════════════════════════════════════════════════════
    //  PRIVATE HELPERS
    // ════════════════════════════════════════════════════════════════════

    /**
     * Build abbreviation → team_id map for the given league.
     */
    private function buildTeamMap(int $leagueId): array
    {
        $stmt = $this->db->prepare(
            "SELECT abbreviation, id FROM teams WHERE league_id = ?"
        );
        $stmt->execute([$leagueId]);

        $map = [];
        while ($row = $stmt->fetch()) {
            $map[strtoupper($row['abbreviation'])] = (int) $row['id'];
        }
        return $map;
    }

    /**
     * Delete all existing players and depth chart entries for this league.
     */
    private function clearLeagueRoster(int $leagueId, array $teamMap): void
    {
        $teamIds = array_values($teamMap);

        // Disable foreign key checks for the duration of the clear
        $isSqlite = Connection::getInstance()->isSqlite();
        if ($isSqlite) {
            $this->db->exec("PRAGMA foreign_keys = OFF");
        }

        if (!empty($teamIds)) {
            $placeholders = implode(',', array_fill(0, count($teamIds), '?'));

            // Delete depth chart entries for all teams in this league
            $this->db->prepare(
                "DELETE FROM depth_chart WHERE team_id IN ({$placeholders})"
            )->execute($teamIds);
        }

        // Delete dependent records that reference players in this league
        $this->db->prepare(
            "DELETE FROM free_agents WHERE player_id IN (SELECT id FROM players WHERE league_id = ?)"
        )->execute([$leagueId]);
        $this->db->prepare(
            "DELETE FROM contracts WHERE player_id IN (SELECT id FROM players WHERE league_id = ?)"
        )->execute([$leagueId]);
        $this->db->prepare(
            "DELETE FROM game_stats WHERE player_id IN (SELECT id FROM players WHERE league_id = ?)"
        )->execute([$leagueId]);
        $this->db->prepare(
            "DELETE FROM injuries WHERE player_id IN (SELECT id FROM players WHERE league_id = ?)"
        )->execute([$leagueId]);
        $this->db->prepare(
            "DELETE FROM trade_items WHERE player_id IN (SELECT id FROM players WHERE league_id = ?)"
        )->execute([$leagueId]);
        $this->db->prepare(
            "DELETE FROM trade_block WHERE player_id IN (SELECT id FROM players WHERE league_id = ?)"
        )->execute([$leagueId]);

        // Delete ALL players for this league (including free agents)
        $this->db->prepare(
            "DELETE FROM players WHERE league_id = ?"
        )->execute([$leagueId]);

        if ($isSqlite) {
            $this->db->exec("PRAGMA foreign_keys = ON");
        }
    }

    /**
     * Auto-generate depth chart for a team (same logic as installer step 3).
     * Picks the highest-rated players per position as starters, then backups.
     */
    private function generateDepthChart(int $teamId): void
    {
        // Count how many starters each position needs
        $positionCounts = [];
        foreach (self::DEPTH_CHART_POSITIONS as $pos) {
            $positionCounts[$pos] = ($positionCounts[$pos] ?? 0) + 1;
        }

        foreach ($positionCounts as $pos => $neededStarters) {
            $stmt = $this->db->prepare(
                "SELECT id FROM players WHERE team_id = ? AND position = ? AND status = 'active'
                 ORDER BY overall_rating DESC LIMIT ?"
            );
            $stmt->execute([$teamId, $pos, $neededStarters + 2]); // starters + backups
            $posPlayers = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            foreach ($posPlayers as $slot => $playerId) {
                $this->db->prepare(
                    "INSERT INTO depth_chart (team_id, position_group, slot, player_id) VALUES (?, ?, ?, ?)"
                )->execute([$teamId, $pos, $slot + 1, $playerId]);
            }
        }
    }

    /**
     * Recalculate team overall rating from starter averages.
     */
    private function recalculateTeamRating(int $teamId): void
    {
        $stmt = $this->db->prepare(
            "SELECT AVG(p.overall_rating) as avg_rating
             FROM depth_chart dc
             JOIN players p ON dc.player_id = p.id
             WHERE dc.team_id = ? AND dc.slot = 1"
        );
        $stmt->execute([$teamId]);
        $avgRating = (int) ($stmt->fetch()['avg_rating'] ?? 75);
        $this->db->prepare(
            "UPDATE teams SET overall_rating = ? WHERE id = ?"
        )->execute([$avgRating, $teamId]);
    }

    /**
     * Infer potential label from rating + age (same logic as RosterImporter).
     */
    private function inferPotential(int $rating, int $age): string
    {
        if ($age <= 23 && $rating >= 75) return 'elite';
        if ($age <= 25 && $rating >= 70) return 'high';
        if ($age <= 27) return 'average';
        return 'limited';
    }

    /**
     * Get the NFL-to-fictional team mapping (useful for debugging / display).
     */
    public function getTeamMapping(): array
    {
        return self::NFL_TEAM_MAP;
    }

    /**
     * Get the position mapping (useful for debugging / display).
     */
    public function getPositionMapping(): array
    {
        return self::POSITION_MAP;
    }
}
