<?php

namespace App\Controllers;

use App\Database\Connection;
use App\Database\Seeds\TeamsSeeder;
use App\Helpers\Response;
use App\Middleware\AuthMiddleware;
use App\Services\PlayerGenerator;
use App\Services\ScheduleGenerator;

class FranchiseController
{
    /**
     * GET /api/franchise/teams-config
     * Return all available teams, conference/division structure for the setup wizard.
     */
    public function teamsConfig(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $teams = TeamsSeeder::getTeams();
        $indexed = [];
        foreach ($teams as $i => $t) {
            $t['index'] = $i + 1;
            $indexed[] = $t;
        }

        // Group by conference → division
        $structure = [];
        foreach ($indexed as $t) {
            $conf = $t['conference'];
            $div = $t['division'];
            if (!isset($structure[$conf])) $structure[$conf] = [];
            if (!isset($structure[$conf][$div])) $structure[$conf][$div] = [];
            $structure[$conf][$div][] = $t;
        }

        Response::json([
            'teams' => $indexed,
            'structure' => $structure,
            'team_count_options' => [4, 6, 8, 10, 12, 14, 16, 20, 24, 28, 32],
        ]);
    }

    /**
     * POST /api/franchise/restart
     * Clear all game data and re-run setup from scratch.
     */
    public function restart(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $body = Response::getJsonBody();

        $leagueName = trim($body['league_name'] ?? 'My League');
        $teamCount = (int) ($body['team_count'] ?? 32);
        $userTeamId = (int) ($body['user_team_id'] ?? 1);
        $coachName = trim($body['coach_name'] ?? 'Coach');

        if ($leagueName === '') {
            Response::error('League name is required');
            return;
        }

        if ($teamCount < 4 || $teamCount > 32) {
            Response::error('Team count must be between 4 and 32');
            return;
        }

        if ($userTeamId < 1 || $userTeamId > $teamCount) {
            Response::error('User team ID must be between 1 and ' . $teamCount);
            return;
        }

        try {
            $pdo = Connection::getInstance()->getPdo();
            $userId = $auth['user_id'];
            $now = date('Y-m-d H:i:s');

            // Delete all game data in FK-safe order
            $tablesToClear = [
                // Dependent/child tables first
                'game_plan_submissions', 'game_stats', 'games',
                'depth_chart', 'injuries', 'contracts',
                'fa_bids', 'free_agents',
                'trade_reviews', 'trade_items', 'trades', 'trade_block',
                'draft_picks', 'draft_prospects', 'draft_classes',
                'coaching_staff', 'coach_history', 'coach_career_history',
                'legacy_scores', 'season_awards', 'milestone_tracking',
                'media_ratings', 'narrative_arcs',
                'ai_generations', 'roster_imports',
                'articles', 'social_posts', 'ticker_items',
                'press_conferences', 'notifications',
                'league_messages', 'league_invites',
                'commissioner_settings',
                // Core tables last
                'players', 'coaches', 'seasons', 'teams', 'leagues',
            ];

            // Disable FK checks for clean wipe
            $isSqlite = Connection::getInstance()->isSqlite();
            if ($isSqlite) {
                $pdo->exec("PRAGMA foreign_keys = OFF");
            } else {
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
            }

            foreach ($tablesToClear as $table) {
                try {
                    $pdo->exec("DELETE FROM {$table}");
                } catch (\Exception $e) {
                    // Table may not exist; skip
                }
            }

            // Re-enable FK checks
            if ($isSqlite) {
                $pdo->exec("PRAGMA foreign_keys = ON");
            } else {
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
            }

            // Player headshot images are kept across restarts since
            // the same real players get re-imported from Madden.
            // Images only need re-fetching if a player has no image_url after import.

            // Create League with unique slug
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '-', $leagueName)) . '-' . time();
            $stmt = $pdo->prepare(
                "INSERT INTO leagues (name, slug, season_year, current_week, phase, commissioner_id, created_at, updated_at)
                 VALUES (?, ?, 2026, 0, 'preseason', ?, ?, ?)"
            );
            $stmt->execute([$leagueName, $slug, $userId, $now, $now]);
            $leagueId = (int) $pdo->lastInsertId();

            // Create Season
            $stmt = $pdo->prepare(
                "INSERT INTO seasons (league_id, year, is_current, created_at) VALUES (?, 2026, 1, ?)"
            );
            $stmt->execute([$leagueId, $now]);
            $seasonId = (int) $pdo->lastInsertId();

            // Create Teams
            $teamsData = TeamsSeeder::getTeamsForCount($teamCount);
            $teamIds = [];
            $teamRecords = [];

            foreach ($teamsData as $t) {
                $stmt = $pdo->prepare(
                    "INSERT INTO teams (league_id, city, name, abbreviation, conference, division,
                     primary_color, secondary_color, logo_emoji, overall_rating, salary_cap, cap_used,
                     wins, losses, ties, points_for, points_against, streak, home_field_advantage, morale)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 75, 301200000, 0, 0, 0, 0, 0, 0, '', ?, 70)"
                );
                $hfa = mt_rand(2, 5);
                $stmt->execute([
                    $leagueId, $t['city'], $t['name'], $t['abbreviation'],
                    $t['conference'], $t['division'],
                    $t['primary_color'], $t['secondary_color'], $t['logo_emoji'], $hfa,
                ]);
                $id = (int) $pdo->lastInsertId();
                $teamIds[] = $id;
                $teamRecords[] = array_merge($t, ['id' => $id]);
            }

            // Create Coaches
            $archetypes = TeamsSeeder::getCoachArchetypes();
            $humanTeamDbId = $teamIds[$userTeamId - 1] ?? $teamIds[0];

            foreach ($teamIds as $tId) {
                $isHuman = ($tId === $humanTeamDbId) ? 1 : 0;
                $archetype = $isHuman ? null : $archetypes[array_rand($archetypes)];
                $name = $isHuman ? $coachName : TeamsSeeder::generateCoachName();

                $gmPersonalities = ['aggressive', 'conservative', 'analytics', 'old_school', 'balanced'];
                $gmPersonality = $isHuman ? 'balanced' : $gmPersonalities[array_rand($gmPersonalities)];

                $stmt = $pdo->prepare(
                    "INSERT INTO coaches (league_id, team_id, user_id, name, is_human, archetype,
                     influence, job_security, media_rating, contract_years, contract_salary, gm_personality, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 50, 3, 5000000, ?, ?)"
                );
                $stmt->execute([
                    $leagueId, $tId,
                    $isHuman ? $userId : null,
                    $name, $isHuman, $archetype,
                    $isHuman ? 50 : mt_rand(40, 70),
                    $isHuman ? 70 : mt_rand(50, 80),
                    $gmPersonality,
                    $now,
                ]);

                if ($isHuman) {
                    $coachId = (int) $pdo->lastInsertId();
                }
            }

            // Generate Schedule
            $schedGen = new ScheduleGenerator();
            $schedule = $schedGen->generate($leagueId, $seasonId, $teamRecords);

            foreach ($schedule as $g) {
                $cols = implode(', ', array_keys($g));
                $placeholders = implode(', ', array_fill(0, count($g), '?'));
                $stmt = $pdo->prepare("INSERT INTO games ({$cols}) VALUES ({$placeholders})");
                $stmt->execute(array_values($g));
            }

            // Generate Draft Classes and Picks (current year + next 2 years)
            $currentYear = 2026;
            for ($yr = $currentYear; $yr <= $currentYear + 2; $yr++) {
                $stmt = $pdo->prepare(
                    "INSERT INTO draft_classes (league_id, year, status, created_at) VALUES (?, ?, ?, ?)"
                );
                $dcStatus = ($yr === $currentYear) ? 'upcoming' : 'future';
                $stmt->execute([$leagueId, $yr, $dcStatus, $now]);
                $draftClassId = (int) $pdo->lastInsertId();

                // Each team gets 7 picks (rounds 1-7)
                $pickNumber = 1;
                for ($round = 1; $round <= 7; $round++) {
                    // Randomize pick order within each round (simulates prior season standings)
                    $shuffledTeamIds = $teamIds;
                    shuffle($shuffledTeamIds);
                    foreach ($shuffledTeamIds as $tId) {
                        $stmt = $pdo->prepare(
                            "INSERT INTO draft_picks (league_id, draft_class_id, round, pick_number, original_team_id, current_team_id, is_used)
                             VALUES (?, ?, ?, ?, ?, ?, 0)"
                        );
                        $stmt->execute([$leagueId, $draftClassId, $round, $pickNumber, $tId, $tId]);
                        $pickNumber++;
                    }
                }
            }

            // Generate draft prospects for the upcoming class
            try {
                $draftEngine = new \App\Services\DraftEngine();
                $upcomingClassId = $pdo->query(
                    "SELECT id FROM draft_classes WHERE league_id = {$leagueId} AND status = 'upcoming' LIMIT 1"
                )->fetchColumn();
                if ($upcomingClassId) {
                    $draftEngine->generateProspectsForClass((int) $upcomingClassId);
                }
            } catch (\Exception $e) {
                // Don't fail franchise setup if prospect generation fails
            }

            // Update session
            $_SESSION['coach_id'] = $coachId ?? 1;
            $_SESSION['league_id'] = $leagueId;
            $_SESSION['team_id'] = $humanTeamDbId;

            Response::json([
                'success' => true,
                'league_id' => $leagueId,
                'message' => 'Franchise reset. Ready for roster setup.',
            ]);
        } catch (\Exception $e) {
            Response::error('Franchise restart failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/franchise/generate-roster
     * Generate random players for all teams + a free agent pool.
     */
    public function generateRoster(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $body = Response::getJsonBody();

        $leagueId = (int) ($body['league_id'] ?? 0);
        $freeAgentCount = (int) ($body['free_agent_count'] ?? 150);

        if ($leagueId <= 0) {
            Response::error('league_id is required');
            return;
        }

        try {
            $pdo = Connection::getInstance()->getPdo();

            // Get all teams in the league
            $stmt = $pdo->prepare("SELECT id FROM teams WHERE league_id = ?");
            $stmt->execute([$leagueId]);
            $teamIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            if (empty($teamIds)) {
                Response::error('No teams found for this league');
                return;
            }

            $generator = new PlayerGenerator();
            $totalPlayers = 0;

            foreach ($teamIds as $tId) {
                // Generate players
                $players = $generator->generateForTeam($tId, $leagueId);
                foreach ($players as $player) {
                    $cols = implode(', ', array_keys($player));
                    $placeholders = implode(', ', array_fill(0, count($player), '?'));
                    $stmt = $pdo->prepare("INSERT INTO players ({$cols}) VALUES ({$placeholders})");
                    $stmt->execute(array_values($player));
                }
                $totalPlayers += count($players);

                // Generate depth chart
                $this->generateDepthChart($pdo, $tId);

                // Recalculate team overall rating from starters
                $this->recalculateTeamRating($pdo, $tId);
            }

            // Generate free agents
            $faCreated = $this->generateFreeAgentPool($pdo, $generator, $leagueId, $freeAgentCount);

            // Try real NFL contracts from Over The Cap, fall back to generated
            $contractStats = ['contracts_created' => 0, 'matched' => 0, 'unmatched' => 0];
            try {
                $realImporter = new \App\Services\RealContractImporter();
                $contractStats = $realImporter->importRealContracts($leagueId);
                $contractStats['contracts_created'] = ($contractStats['matched'] ?? 0) + ($contractStats['unmatched'] ?? 0);
            } catch (\Exception $e) {
                try {
                    $contractEngine = new \App\Services\ContractEngine();
                    $contractStats = $contractEngine->generateAllContracts($leagueId);
                } catch (\Exception $e2) {}
            }

            // Assign player images — cache first (instant), then ESPN for remaining
            $imagesAssigned = 0;
            try {
                $imageService = new \App\Services\PlayerImageService();
                $cacheResult = $imageService->assignFromCache($pdo, $leagueId);
                $imagesAssigned = $cacheResult['assigned'] ?? 0;

                // Download from ESPN for any still missing
                $imgResult = $imageService->assignImages($pdo, $leagueId);
                $imagesAssigned += $imgResult['espn_matched'] ?? 0;
            } catch (\Exception $e) {
                // Don't fail if image fetch fails
            }

            // Generate historical career stats for all players
            $historicalStats = 0;
            try {
                $histGen = new \App\Services\HistoricalStatsGenerator();
                $histResult = $histGen->generateForLeague($leagueId);
                $historicalStats = $histResult['generated'] ?? 0;
            } catch (\Exception $e) {
                error_log("Historical stats generation error: " . $e->getMessage());
            }

            Response::json([
                'success' => true,
                'players_created' => $totalPlayers,
                'free_agents_created' => $faCreated,
                'contracts_created' => $contractStats['contracts_created'],
                'images_assigned' => $imagesAssigned,
                'historical_stats' => $historicalStats,
            ]);
        } catch (\Exception $e) {
            Response::error('Roster generation failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/franchise/generate-free-agents
     * Generate only free agents (useful after Madden import).
     */
    public function generateFreeAgents(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $body = Response::getJsonBody();

        $leagueId = (int) ($body['league_id'] ?? 0);
        $count = (int) ($body['count'] ?? 150);

        if ($leagueId <= 0) {
            Response::error('league_id is required');
            return;
        }

        if ($count < 1 || $count > 500) {
            Response::error('Count must be between 1 and 500');
            return;
        }

        try {
            $pdo = Connection::getInstance()->getPdo();
            $generator = new PlayerGenerator();

            $faCreated = $this->generateFreeAgentPool($pdo, $generator, $leagueId, $count, true);

            Response::json([
                'success' => true,
                'free_agents_created' => $faCreated,
            ]);
        } catch (\Exception $e) {
            Response::error('Free agent generation failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Generate depth chart for a team (starters by highest rating per position).
     */
    private function generateDepthChart(\PDO $pdo, int $teamId): void
    {
        $positions = [
            'QB', 'RB', 'WR', 'WR', 'WR', 'TE', 'OT', 'OT', 'OG', 'OG', 'C',
            'DE', 'DE', 'DT', 'DT', 'LB', 'LB', 'LB', 'CB', 'CB', 'S', 'S', 'K', 'P',
        ];

        $positionCounts = [];
        foreach ($positions as $pos) {
            $positionCounts[$pos] = ($positionCounts[$pos] ?? 0) + 1;
        }

        foreach ($positionCounts as $pos => $neededStarters) {
            $stmtP = $pdo->prepare(
                "SELECT id FROM players WHERE team_id = ? AND position = ? AND status = 'active'
                 ORDER BY overall_rating DESC LIMIT ?"
            );
            $stmtP->execute([$teamId, $pos, $neededStarters + 2]);
            $posPlayers = $stmtP->fetchAll(\PDO::FETCH_COLUMN);

            foreach ($posPlayers as $slot => $playerId) {
                $stmtDC = $pdo->prepare(
                    "INSERT INTO depth_chart (team_id, position_group, slot, player_id) VALUES (?, ?, ?, ?)"
                );
                $stmtDC->execute([$teamId, $pos, $slot + 1, $playerId]);
            }
        }
    }

    /**
     * Recalculate a team's overall rating from its starters.
     */
    private function recalculateTeamRating(\PDO $pdo, int $teamId): void
    {
        $stmtAvg = $pdo->prepare(
            "SELECT AVG(p.overall_rating) as avg_rating
             FROM depth_chart dc
             JOIN players p ON dc.player_id = p.id
             WHERE dc.team_id = ? AND dc.slot = 1"
        );
        $stmtAvg->execute([$teamId]);
        $avgRating = (int) ($stmtAvg->fetch()['avg_rating'] ?? 75);
        $pdo->prepare("UPDATE teams SET overall_rating = ? WHERE id = ?")->execute([$avgRating, $teamId]);
    }

    /**
     * Generate a pool of free agents and insert into both players and free_agents tables.
     *
     * Uses generateForTeam() in batches (each call produces ~53 players), then picks
     * from the pool to avoid calling it once per free agent.
     *
     * @param bool $lowerTier When true, reduces overall ratings for backup/practice quality.
     */
    private function generateFreeAgentPool(\PDO $pdo, PlayerGenerator $generator, int $leagueId, int $count, bool $lowerTier = false): int
    {
        $created = 0;
        $now = date('Y-m-d H:i:s');
        $pool = [];

        // Temporarily disable FK checks for free agent inserts (team_id will be NULL)
        $isSqlite = Connection::getInstance()->isSqlite();
        if ($isSqlite) {
            $pdo->exec("PRAGMA foreign_keys = OFF");
        } else {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        }

        while ($created < $count) {
            // Refill the pool when empty by generating a fake team's worth of players
            if (empty($pool)) {
                // Use a real team ID to avoid FK issues during generation, then override
                $anyTeamId = (int) ($pdo->query("SELECT id FROM teams WHERE league_id = {$leagueId} LIMIT 1")->fetchColumn() ?: 0);
                $pool = $generator->generateForTeam($anyTeamId ?: 0, $leagueId);
                shuffle($pool);
            }

            $faPlayer = array_pop($pool);

            // Override for free agent status
            $faPlayer['team_id'] = null;
            $faPlayer['status'] = 'free_agent';

            // Lower tier: reduce overall rating for backup/practice quality
            if ($lowerTier) {
                $reduction = mt_rand(8, 18);
                $faPlayer['overall_rating'] = max(42, $faPlayer['overall_rating'] - $reduction);
            }

            // Insert into players table
            $cols = implode(', ', array_keys($faPlayer));
            $placeholders = implode(', ', array_fill(0, count($faPlayer), '?'));
            $stmt = $pdo->prepare("INSERT INTO players ({$cols}) VALUES ({$placeholders})");
            $stmt->execute(array_values($faPlayer));
            $playerId = (int) $pdo->lastInsertId();

            // Calculate market value (same formula as FreeAgencyEngine)
            $marketValue = $this->calculateMarketValue($faPlayer);

            // Insert into free_agents table
            $stmt = $pdo->prepare(
                "INSERT INTO free_agents (league_id, player_id, asking_salary, market_value, status, released_at)
                 VALUES (?, ?, ?, ?, 'available', ?)"
            );
            $stmt->execute([$leagueId, $playerId, $marketValue, $marketValue, $now]);

            $created++;
        }

        // Re-enable FK checks
        if ($isSqlite) {
            $pdo->exec("PRAGMA foreign_keys = ON");
        } else {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        }

        return $created;
    }

    /**
     * Calculate market value for a player (mirrors FreeAgencyEngine::calculateMarketValue).
     */
    private function calculateMarketValue(array $player): int
    {
        $base = 500000;
        $ratingBonus = pow($player['overall_rating'] / 100, 2) * 15000000;
        $positionMultiplier = match ($player['position']) {
            'QB' => 2.5, 'DE' => 1.4, 'CB' => 1.3, 'WR' => 1.3, 'OT' => 1.2,
            'LB' => 1.1, 'DT' => 1.1, 'RB' => 1.0, 'TE' => 1.0, 'S' => 1.0,
            'OG' => 0.9, 'C' => 0.9, 'K' => 0.5, 'P' => 0.4, 'LS' => 0.3,
            default => 1.0,
        };

        $ageFactor = $player['age'] <= 26 ? 1.1 : ($player['age'] >= 31 ? 0.7 : 1.0);

        return max($base, (int) ($ratingBonus * $positionMultiplier * $ageFactor));
    }
}
