<?php
/**
 * Head Coach 26 — Installer
 * Three-step wizard: Database → Admin → League
 */

session_start();
header('Content-Type: application/json');

// Ensure Connection class is always available
require_once __DIR__ . '/../app/Database/Connection.php';

// Handle API requests from the installer frontend
$method = $_SERVER['REQUEST_METHOD'];
$uri = strtok($_SERVER['REQUEST_URI'], '?');

// Strip /install prefix
$path = preg_replace('#^/install#', '', $uri);
$path = rtrim($path, '/') ?: '/';

if ($method === 'GET' && $path === '/') {
    // Serve installer HTML page
    header('Content-Type: text/html');
    readfile(__DIR__ . '/installer.html');
    exit;
}

if ($method === 'GET' && $path === '/status') {
    $configExists = file_exists(__DIR__ . '/../config/database.php');
    $fullyInstalled = false;

    // Only consider "installed" if DB exists AND has at least one user and one league
    if ($configExists) {
        try {
            require_once __DIR__ . '/../app/Database/Connection.php';
            $cfg = require __DIR__ . '/../config/database.php';
            $pdo = \App\Database\Connection::testConnection($cfg);
            $users = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            $leagues = (int) $pdo->query("SELECT COUNT(*) FROM leagues")->fetchColumn();
            $fullyInstalled = ($users > 0 && $leagues > 0);
        } catch (\Exception $e) {
            $fullyInstalled = false;
        }
    }

    echo json_encode([
        'installed' => $fullyInstalled,
        'step' => $_SESSION['install_step'] ?? ($configExists ? 2 : 1),
    ]);
    exit;
}

if ($method === 'POST' && $path === '/step1') {
    // Step 1: Database Configuration
    $body = json_decode(file_get_contents('php://input'), true);

    $driver = $body['driver'] ?? 'sqlite';

    $config = ['driver' => $driver];

    if ($driver === 'sqlite') {
        $config['sqlite_path'] = __DIR__ . '/../storage/headcoach.db';
    } else {
        $config['host'] = $body['host'] ?? 'localhost';
        $config['database'] = $body['database'] ?? '';
        $config['username'] = $body['username'] ?? '';
        $config['password'] = $body['password'] ?? '';

        if (empty($config['database']) || empty($config['username'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Database name and username are required for MySQL.']);
            exit;
        }
    }

    // Test connection
    try {
        require_once __DIR__ . '/../app/Database/Connection.php';
        $pdo = \App\Database\Connection::testConnection($config);

        // Write config file
        $configDir = __DIR__ . '/../config';
        if (!is_dir($configDir)) mkdir($configDir, 0755, true);

        $configContent = "<?php\nreturn " . var_export($config, true) . ";\n";
        file_put_contents($configDir . '/database.php', $configContent);

        // Write app config
        file_put_contents($configDir . '/app.php', "<?php\nreturn [\n    'debug' => true,\n    'name' => 'Head Coach 26',\n];\n");

        // Run migrations
        require_once __DIR__ . '/../app/Database/Migrator.php';
        $migrator = new \App\Database\Migrator($pdo, $driver);
        $ran = $migrator->migrate();

        $_SESSION['install_step'] = 2;
        $_SESSION['install_driver'] = $driver;

        echo json_encode([
            'success' => true,
            'message' => 'Database connected and tables created.',
            'migrations' => $ran,
        ]);
    } catch (\Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    }
    exit;
}

if ($method === 'POST' && $path === '/step2') {
    // Step 2: Create Admin Account
    $body = json_decode(file_get_contents('php://input'), true);

    $username = trim($body['username'] ?? '');
    $email = trim($body['email'] ?? '');
    $password = $body['password'] ?? '';

    if (strlen($username) < 3) {
        http_response_code(400);
        echo json_encode(['error' => 'Username must be at least 3 characters.']);
        exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid email address.']);
        exit;
    }
    if (strlen($password) < 6) {
        http_response_code(400);
        echo json_encode(['error' => 'Password must be at least 6 characters.']);
        exit;
    }

    try {
        $config = require __DIR__ . '/../config/database.php';
        $pdo = \App\Database\Connection::testConnection($config);

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare(
            "INSERT INTO users (username, email, password_hash, is_admin, created_at) VALUES (?, ?, ?, 1, ?)"
        );
        $stmt->execute([$username, $email, $hash, date('Y-m-d H:i:s')]);
        $userId = $pdo->lastInsertId();

        $_SESSION['install_step'] = 3;
        $_SESSION['install_user_id'] = $userId;

        echo json_encode([
            'success' => true,
            'user_id' => $userId,
            'message' => 'Admin account created.',
        ]);
    } catch (\Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if ($method === 'POST' && $path === '/step3') {
    // Step 3: Create League and Seed Data
    $body = json_decode(file_get_contents('php://input'), true);

    $leagueName = trim($body['league_name'] ?? 'My League');
    $teamId = (int)($body['team_id'] ?? 1); // Which team to coach (1-based index)
    $coachName = trim($body['coach_name'] ?? 'Coach');
    $numTeams = (int)($body['num_teams'] ?? 32);
    $customTeams = $body['custom_teams'] ?? null; // Array of team overrides

    try {
        $config = require __DIR__ . '/../config/database.php';
        $pdo = \App\Database\Connection::testConnection($config);

        $userId = $_SESSION['install_user_id'] ?? 1;
        $now = date('Y-m-d H:i:s');

        // Create League with settings
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '-', $leagueName));
        $leagueSettings = json_encode(['num_teams' => $numTeams]);
        $stmt = $pdo->prepare(
            "INSERT INTO leagues (name, slug, season_year, current_week, phase, commissioner_id, created_at, updated_at)
             VALUES (?, ?, 2026, 0, 'preseason', ?, ?, ?)"
        );
        $stmt->execute([$leagueName, $slug, $userId, $now, $now]);
        $leagueId = (int)$pdo->lastInsertId();

        // Create Season
        $stmt = $pdo->prepare(
            "INSERT INTO seasons (league_id, year, is_current, created_at) VALUES (?, 2026, 1, ?)"
        );
        $stmt->execute([$leagueId, $now]);
        $seasonId = (int)$pdo->lastInsertId();

        // Build teams data — use custom teams if provided, otherwise default
        require_once __DIR__ . '/../app/Database/seeds/TeamsSeeder.php';

        if ($customTeams && is_array($customTeams) && count($customTeams) > 0) {
            // Use the custom teams array directly
            $teamsData = $customTeams;
        } else {
            // Use default teams, sliced to requested count
            $teamsData = \App\Database\Seeds\TeamsSeeder::getTeamsForCount($numTeams);
        }

        $teamIds = [];
        $teamRecords = [];

        foreach ($teamsData as $t) {
            $stmt = $pdo->prepare(
                "INSERT INTO teams (league_id, city, name, abbreviation, conference, division,
                 primary_color, secondary_color, logo_emoji, overall_rating, salary_cap, cap_used,
                 wins, losses, ties, points_for, points_against, streak, home_field_advantage, morale)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 75, 225000000, 0, 0, 0, 0, 0, 0, '', ?, 70)"
            );
            $hfa = mt_rand(2, 5);
            $stmt->execute([
                $leagueId, $t['city'], $t['name'], $t['abbreviation'],
                $t['conference'], $t['division'],
                $t['primary_color'], $t['secondary_color'], $t['logo_emoji'], $hfa,
            ]);
            $id = (int)$pdo->lastInsertId();
            $teamIds[] = $id;
            $teamRecords[] = array_merge($t, ['id' => $id]);
        }

        // Generate Players for all teams
        require_once __DIR__ . '/../app/Services/PlayerGenerator.php';
        $generator = new \App\Services\PlayerGenerator();

        foreach ($teamIds as $tId) {
            $players = $generator->generateForTeam($tId, $leagueId);
            foreach ($players as $player) {
                $cols = implode(', ', array_keys($player));
                $placeholders = implode(', ', array_fill(0, count($player), '?'));
                $stmt = $pdo->prepare("INSERT INTO players ({$cols}) VALUES ({$placeholders})");
                $stmt->execute(array_values($player));
            }

            // Auto-generate depth chart (starters by highest rating per position)
            $positions = ['QB', 'RB', 'WR', 'WR', 'WR', 'TE', 'OT', 'OT', 'OG', 'OG', 'C',
                          'DE', 'DE', 'DT', 'DT', 'LB', 'LB', 'LB', 'CB', 'CB', 'S', 'S', 'K', 'P'];

            $positionCounts = [];
            foreach ($positions as $pos) {
                $positionCounts[$pos] = ($positionCounts[$pos] ?? 0) + 1;
            }

            foreach ($positionCounts as $pos => $neededStarters) {
                $stmtP = $pdo->prepare(
                    "SELECT id FROM players WHERE team_id = ? AND position = ? AND status = 'active'
                     ORDER BY overall_rating DESC LIMIT ?"
                );
                $stmtP->execute([$tId, $pos, $neededStarters + 2]); // starters + backups
                $posPlayers = $stmtP->fetchAll(\PDO::FETCH_COLUMN);

                foreach ($posPlayers as $slot => $playerId) {
                    $stmtDC = $pdo->prepare(
                        "INSERT INTO depth_chart (team_id, position_group, slot, player_id) VALUES (?, ?, ?, ?)"
                    );
                    $stmtDC->execute([$tId, $pos, $slot + 1, $playerId]);
                }
            }

            // Calculate team overall rating from starters
            $stmtAvg = $pdo->prepare(
                "SELECT AVG(p.overall_rating) as avg_rating
                 FROM depth_chart dc
                 JOIN players p ON dc.player_id = p.id
                 WHERE dc.team_id = ? AND dc.slot = 1"
            );
            $stmtAvg->execute([$tId]);
            $avgRating = (int)($stmtAvg->fetch()['avg_rating'] ?? 75);
            $pdo->prepare("UPDATE teams SET overall_rating = ? WHERE id = ?")->execute([$avgRating, $tId]);
        }

        // Create AI coaches for all teams
        $archetypes = \App\Database\Seeds\TeamsSeeder::getCoachArchetypes();
        $humanTeamDbId = $teamIds[$teamId - 1] ?? $teamIds[0]; // Convert 1-based selection to team ID

        foreach ($teamIds as $tId) {
            $isHuman = ($tId === $humanTeamDbId) ? 1 : 0;
            $archetype = $isHuman ? null : $archetypes[array_rand($archetypes)];
            $name = $isHuman ? $coachName : \App\Database\Seeds\TeamsSeeder::generateCoachName();

            $stmt = $pdo->prepare(
                "INSERT INTO coaches (league_id, team_id, user_id, name, is_human, archetype,
                 influence, job_security, media_rating, contract_years, contract_salary, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 50, 3, 5000000, ?)"
            );
            $stmt->execute([
                $leagueId, $tId,
                $isHuman ? $userId : null,
                $name, $isHuman, $archetype,
                $isHuman ? 50 : mt_rand(40, 70),
                $isHuman ? 70 : mt_rand(50, 80),
                $now,
            ]);

            if ($isHuman) {
                $coachId = (int)$pdo->lastInsertId();
            }
        }

        // Generate Schedule
        require_once __DIR__ . '/../app/Services/ScheduleGenerator.php';
        $schedGen = new \App\Services\ScheduleGenerator();
        $schedule = $schedGen->generate($leagueId, $seasonId, $teamRecords);

        foreach ($schedule as $g) {
            $cols = implode(', ', array_keys($g));
            $placeholders = implode(', ', array_fill(0, count($g), '?'));
            $stmt = $pdo->prepare("INSERT INTO games ({$cols}) VALUES ({$placeholders})");
            $stmt->execute(array_values($g));
        }

        // Set up session for the new user
        $_SESSION['user_id'] = $userId;
        $_SESSION['coach_id'] = $coachId ?? 1;
        $_SESSION['league_id'] = $leagueId;
        $_SESSION['team_id'] = $humanTeamDbId;
        $_SESSION['is_admin'] = true;

        echo json_encode([
            'success' => true,
            'message' => 'League created! Redirecting to dashboard...',
            'league_id' => $leagueId,
            'team_id' => $humanTeamDbId,
            'coach_id' => $coachId ?? 1,
            'teams_created' => count($teamIds),
            'games_scheduled' => count($schedule),
        ]);
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    }
    exit;
}

// List teams (for team selection in step 3)
if ($method === 'GET' && $path === '/teams') {
    require_once __DIR__ . '/../app/Database/seeds/TeamsSeeder.php';
    $teams = \App\Database\Seeds\TeamsSeeder::getTeams();
    $indexed = [];
    foreach ($teams as $i => $t) {
        $t['index'] = $i + 1;
        $indexed[] = $t;
    }
    echo json_encode($indexed);
    exit;
}

// Full teams config for customizable league creation
if ($method === 'GET' && $path === '/teams-config') {
    require_once __DIR__ . '/../app/Database/seeds/TeamsSeeder.php';
    $seeder = \App\Database\Seeds\TeamsSeeder::class;

    $teams = $seeder::getTeams();
    $indexed = [];
    foreach ($teams as $i => $t) {
        $t['index'] = $i + 1;
        $indexed[] = $t;
    }

    echo json_encode([
        'teams' => $indexed,
        'city_pool' => $seeder::getCityPool(),
        'team_count_options' => [4, 6, 8, 10, 12, 14, 16, 20, 24, 28, 32],
        'default_structure' => [
            4  => $seeder::getDefaultStructure(4),
            6  => $seeder::getDefaultStructure(6),
            8  => $seeder::getDefaultStructure(8),
            10 => $seeder::getDefaultStructure(10),
            12 => $seeder::getDefaultStructure(12),
            14 => $seeder::getDefaultStructure(14),
            16 => $seeder::getDefaultStructure(16),
            20 => $seeder::getDefaultStructure(20),
            24 => $seeder::getDefaultStructure(24),
            28 => $seeder::getDefaultStructure(28),
            32 => $seeder::getDefaultStructure(32),
        ],
    ]);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Not found']);
