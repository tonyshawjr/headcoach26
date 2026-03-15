<?php

namespace App\Services;

use App\Models\FantasyLeague;
use App\Models\FantasyManager;
use App\Models\FantasyRoster;
use App\Models\FantasyMatchup;
use App\Database\Connection;

/**
 * FantasyLeagueEngine — Orchestrates all fantasy football operations.
 *
 * Handles league creation (with AI backfill), snake/auction drafts,
 * weekly processing (scoring, matchups, waivers, lineups), and playoffs.
 *
 * Key rules:
 *   - Leagues must be created within the first 2 weeks of the NFL season
 *   - User configures: team count, playoff weeks, human vs AI split
 *   - AI managers are generated with distinct personalities via FantasyBrain
 */
class FantasyLeagueEngine
{
    private \PDO $db;
    private FantasyLeague $leagueModel;
    private FantasyManager $managerModel;
    private FantasyRoster $rosterModel;
    private FantasyMatchup $matchupModel;
    private FantasyScoreEngine $scoreEngine;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
        $this->leagueModel = new FantasyLeague();
        $this->managerModel = new FantasyManager();
        $this->rosterModel = new FantasyRoster();
        $this->matchupModel = new FantasyMatchup();
        $this->scoreEngine = new FantasyScoreEngine();
    }

    // ── League Creation ────────────────────────────────────────────────

    /**
     * Create a new fantasy league.
     *
     * @param array $config {
     *   league_id: int,       — parent NFL league
     *   name: string,
     *   coach_id: int,        — commissioner (human creator)
     *   user_id: int,
     *   num_teams: int,       — total teams (4-20)
     *   max_humans: int,      — how many human slots (rest filled by AI)
     *   scoring_type: string, — standard|ppr|half_ppr|custom
     *   scoring_rules: ?array,— custom scoring rules (if type=custom)
     *   roster_slots: ?array, — custom roster config
     *   playoff_start_week: int,
     *   num_playoff_teams: int,
     *   draft_type: string,   — snake|auction
     *   draft_rounds: int,
     *   waiver_type: string,  — priority|faab
     *   faab_budget: int,
     *   team_name: string,    — commissioner's fantasy team name
     * }
     */
    public function createLeague(array $config): array
    {
        // Validate: must be within first 2 weeks
        $nflLeague = (new \App\Models\League())->find($config['league_id']);
        if (!$nflLeague) {
            return ['error' => 'NFL league not found'];
        }
        if ($nflLeague['current_week'] > 2) {
            return ['error' => 'Fantasy leagues must be created within the first 2 weeks of the season'];
        }

        $numTeams = max(4, min(20, $config['num_teams'] ?? 10));
        $maxHumans = max(1, min($numTeams, $config['max_humans'] ?? 1));
        $playoffStart = $config['playoff_start_week'] ?? 14;
        $numPlayoffTeams = $config['num_playoff_teams'] ?? 4;

        // Ensure playoff teams is power of 2 and doesn't exceed league size
        $validPlayoffSizes = [2, 4, 6, 8];
        if (!in_array($numPlayoffTeams, $validPlayoffSizes)) {
            $numPlayoffTeams = 4;
        }
        $numPlayoffTeams = min($numPlayoffTeams, $numTeams);

        // Calculate championship week based on playoff format
        $playoffRounds = (int) ceil(log($numPlayoffTeams, 2));
        $championshipWeek = $playoffStart + $playoffRounds - 1;

        // Generate invite code
        $inviteCode = strtoupper(substr(md5(uniqid()), 0, 8));

        $scoringRules = $config['scoring_rules'] ?? null;
        $scoringType = $config['scoring_type'] ?? 'ppr';
        if (!$scoringRules && isset(FantasyLeague::SCORING_PRESETS[$scoringType])) {
            $scoringRules = FantasyLeague::SCORING_PRESETS[$scoringType];
        }

        $rosterSlots = $config['roster_slots'] ?? FantasyLeague::DEFAULT_ROSTER_SLOTS;

        // Create the league
        $leagueId = $this->leagueModel->create([
            'league_id' => $config['league_id'],
            'name' => $config['name'] ?? 'Fantasy League',
            'commissioner_coach_id' => $config['coach_id'],
            'num_teams' => $numTeams,
            'scoring_type' => $scoringType,
            'scoring_rules' => json_encode($scoringRules),
            'roster_slots' => json_encode($rosterSlots),
            'num_playoff_teams' => $numPlayoffTeams,
            'playoff_start_week' => $playoffStart,
            'championship_week' => $championshipWeek,
            'regular_season_end_week' => $playoffStart - 1,
            'waiver_type' => $config['waiver_type'] ?? 'priority',
            'faab_budget' => $config['faab_budget'] ?? 100,
            'trade_review_hours' => $config['trade_review_hours'] ?? 24,
            'draft_type' => $config['draft_type'] ?? 'snake',
            'draft_rounds' => $config['draft_rounds'] ?? 15,
            'draft_status' => 'pending',
            'status' => 'setup',
            'invite_code' => $inviteCode,
            'max_human_players' => $maxHumans,
            'created_week' => $nflLeague['current_week'],
            'season_year' => $nflLeague['season_year'],
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // Create the commissioner as manager #1
        $this->managerModel->create([
            'fantasy_league_id' => $leagueId,
            'coach_id' => $config['coach_id'],
            'user_id' => $config['user_id'] ?? null,
            'team_name' => $config['team_name'] ?? 'My Fantasy Team',
            'owner_name' => $config['owner_name'] ?? 'Commissioner',
            'avatar_color' => '#3B82F6',
            'is_ai' => 0,
            'personality' => null,
            'draft_position' => 1,
            'faab_remaining' => $config['faab_budget'] ?? 100,
            'waiver_priority' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // Fill remaining slots with AI managers
        $aiCount = $numTeams - 1; // -1 for commissioner
        $aiManagers = FantasyBrain::generateAIManagers($aiCount);

        foreach ($aiManagers as $i => $ai) {
            $ai['fantasy_league_id'] = $leagueId;
            $ai['draft_position'] = $i + 2; // commissioner is 1
            $ai['faab_remaining'] = $config['faab_budget'] ?? 100;
            $ai['waiver_priority'] = $i + 2;
            $this->managerModel->create($ai);
        }

        // Generate the fantasy schedule (regular season matchups)
        $this->generateSchedule($leagueId);

        return [
            'id' => $leagueId,
            'invite_code' => $inviteCode,
            'num_teams' => $numTeams,
            'ai_managers' => $aiCount,
            'status' => 'setup',
        ];
    }

    /**
     * Allow a human to join via invite code, replacing an AI manager.
     */
    public function joinLeague(string $inviteCode, int $coachId, int $userId, string $teamName, string $ownerName): array
    {
        $league = $this->leagueModel->findByInviteCode($inviteCode);
        if (!$league) {
            return ['error' => 'Invalid invite code'];
        }

        // Check if already in the league
        $existing = $this->managerModel->findByCoach($league['id'], $coachId);
        if ($existing) {
            return ['error' => 'You are already in this league'];
        }

        // Check human slots
        $humans = $this->db->prepare(
            "SELECT COUNT(*) as cnt FROM fantasy_managers WHERE fantasy_league_id = ? AND is_ai = 0"
        );
        $humans->execute([$league['id']]);
        $humanCount = (int) $humans->fetch()['cnt'];

        if ($humanCount >= $league['max_human_players']) {
            return ['error' => 'No human slots available'];
        }

        if ($league['draft_status'] !== 'pending') {
            return ['error' => 'Cannot join after the draft has started'];
        }

        // Replace the first available AI manager
        $aiManagers = $this->db->prepare(
            "SELECT id FROM fantasy_managers WHERE fantasy_league_id = ? AND is_ai = 1 ORDER BY id LIMIT 1"
        );
        $aiManagers->execute([$league['id']]);
        $aiToReplace = $aiManagers->fetch();

        if (!$aiToReplace) {
            return ['error' => 'No AI slots available to replace'];
        }

        // Update the AI slot to become human
        $this->managerModel->update($aiToReplace['id'], [
            'coach_id' => $coachId,
            'user_id' => $userId,
            'team_name' => $teamName,
            'owner_name' => $ownerName,
            'is_ai' => 0,
            'personality' => null,
            'favorite_nfl_teams' => null,
        ]);

        return ['success' => true, 'manager_id' => $aiToReplace['id']];
    }

    // ── Draft ──────────────────────────────────────────────────────────

    /**
     * Execute a complete snake draft.
     * All AI picks are made automatically. Returns draft results.
     */
    public function executeDraft(int $fantasyLeagueId): array
    {
        $league = $this->leagueModel->getWithManagers($fantasyLeagueId);
        if (!$league) return ['error' => 'League not found'];
        if ($league['draft_status'] === 'complete') return ['error' => 'Draft already complete'];

        $managers = $league['managers'];
        $numManagers = count($managers);
        $totalRounds = $league['draft_rounds'] ?? 15;

        // Randomize draft order
        shuffle($managers);
        foreach ($managers as $i => &$m) {
            $this->managerModel->update($m['id'], ['draft_position' => $i + 1]);
            $m['draft_position'] = $i + 1;
        }
        unset($m);

        // Get all draftable players
        $available = $this->db->prepare(
            "SELECT p.*, t.abbreviation as team_abbr
             FROM players p
             LEFT JOIN teams t ON t.id = p.team_id
             WHERE p.league_id = ? AND p.status = 'active'
             AND p.position IN ('QB', 'RB', 'WR', 'TE', 'K')
             ORDER BY p.overall_rating DESC"
        );
        $available->execute([$league['league_id']]);
        $availablePlayers = $available->fetchAll();

        $draftResults = [];
        $managerRosters = []; // track what each manager has drafted

        // Initialize empty rosters
        foreach ($managers as $m) {
            $managerRosters[$m['id']] = [];
        }

        // Snake draft: round 1 goes 1→N, round 2 goes N→1, etc.
        for ($round = 1; $round <= $totalRounds; $round++) {
            $order = ($round % 2 === 1) ? $managers : array_reverse($managers);

            foreach ($order as $m) {
                if (empty($availablePlayers)) break;

                $pickPlayerId = null;

                if ($m['is_ai']) {
                    // AI makes the pick
                    $brain = new FantasyBrain($m, $league);
                    $pickPlayerId = $brain->makeDraftPick(
                        $availablePlayers,
                        $managerRosters[$m['id']],
                        $round,
                        $totalRounds
                    );
                } else {
                    // Human auto-draft: pick best available by projected points
                    $best = $this->autoPick($availablePlayers, $managerRosters[$m['id']], $league, $round, $totalRounds);
                    $pickPlayerId = $best;
                }

                if (!$pickPlayerId) continue;

                // Find the picked player and remove from available
                $pickedPlayer = null;
                $availablePlayers = array_values(array_filter($availablePlayers, function ($p) use ($pickPlayerId, &$pickedPlayer) {
                    if ($p['id'] === $pickPlayerId) {
                        $pickedPlayer = $p;
                        return false;
                    }
                    return true;
                }));

                if (!$pickedPlayer) continue;

                // Add to roster
                $isStarter = count(array_filter($managerRosters[$m['id']], fn($p) => $p['position'] === $pickedPlayer['position'])) < 2;

                $this->rosterModel->create([
                    'fantasy_league_id' => $fantasyLeagueId,
                    'fantasy_manager_id' => $m['id'],
                    'player_id' => $pickPlayerId,
                    'roster_slot' => $pickedPlayer['position'],
                    'is_starter' => 0, // will set lineups after draft
                    'acquired_via' => 'draft',
                    'acquired_week' => $league['created_week'],
                ]);

                $managerRosters[$m['id']][] = $pickedPlayer;

                // Log the transaction
                $this->db->prepare(
                    "INSERT INTO fantasy_transactions (fantasy_league_id, fantasy_manager_id, type, player_id, details, week, created_at)
                     VALUES (?, ?, 'draft', ?, ?, ?, ?)"
                )->execute([
                    $fantasyLeagueId, $m['id'], $pickPlayerId,
                    json_encode(['round' => $round, 'pick' => $m['draft_position']]),
                    $league['created_week'],
                    date('Y-m-d H:i:s'),
                ]);

                $draftResults[] = [
                    'round' => $round,
                    'pick' => $m['draft_position'],
                    'manager_id' => $m['id'],
                    'manager_name' => $m['owner_name'],
                    'team_name' => $m['team_name'],
                    'player_id' => $pickPlayerId,
                    'player_name' => $pickedPlayer['first_name'] . ' ' . $pickedPlayer['last_name'],
                    'position' => $pickedPlayer['position'],
                    'overall' => $pickedPlayer['overall_rating'],
                    'is_ai' => $m['is_ai'],
                ];
            }
        }

        // Set initial lineups for all AI managers
        foreach ($managers as $m) {
            if ($m['is_ai']) {
                $brain = new FantasyBrain($m, $league);
                $brain->setLineup($league['created_week'] + 1);
            }
        }

        // Also set initial lineups for human managers (auto-optimal)
        foreach ($managers as $m) {
            if (!$m['is_ai']) {
                $this->autoSetLineup($m['id'], $league);
            }
        }

        // Mark draft as complete, league as active
        $this->leagueModel->update($fantasyLeagueId, [
            'draft_status' => 'complete',
            'status' => 'active',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return [
            'success' => true,
            'picks' => $draftResults,
            'total_picks' => count($draftResults),
        ];
    }

    /**
     * Auto-pick best available for human managers.
     */
    private function autoPick(array $available, array $currentRoster, array $league, int $round, int $totalRounds): ?int
    {
        $rosterSlots = $league['roster_slots'] ?? FantasyLeague::DEFAULT_ROSTER_SLOTS;
        $scoringRules = $league['scoring_rules'] ?? FantasyLeague::SCORING_PRESETS['ppr'];

        $posCounts = array_count_values(array_column($currentRoster, 'position'));

        $best = null;
        $bestValue = -999;

        foreach ($available as $p) {
            $projected = $this->scoreEngine->projectPoints($p, $scoringRules);
            $posCount = $posCounts[$p['position']] ?? 0;
            $maxSlots = $rosterSlots[$p['position']] ?? 0;
            if (in_array($p['position'], ['RB', 'WR', 'TE'])) {
                $maxSlots += ($rosterSlots['FLEX'] ?? 0);
            }

            // Penalize if position is full
            $needMod = ($posCount < $maxSlots) ? 1.0 : 0.5;
            if ($posCount >= ($maxSlots + 3)) $needMod = 0.1;

            // Delay K until later rounds
            if ($p['position'] === 'K' && $round < ($totalRounds - 1)) {
                $needMod *= 0.3;
            }

            $value = $projected * $needMod;
            if ($value > $bestValue) {
                $bestValue = $value;
                $best = $p['id'];
            }
        }

        return $best;
    }

    /**
     * Auto-set optimal lineup for a human manager.
     */
    private function autoSetLineup(int $managerId, array $league): void
    {
        $roster = $this->rosterModel->getStarters($managerId);
        $allPlayers = $this->managerModel->getRosterOrdered($managerId);

        if (empty($allPlayers)) return;

        $rosterSlots = $league['roster_slots'] ?? FantasyLeague::DEFAULT_ROSTER_SLOTS;
        $scoringRules = $league['scoring_rules'] ?? FantasyLeague::SCORING_PRESETS['ppr'];

        // Score all players
        $scored = [];
        foreach ($allPlayers as $p) {
            $projected = $this->scoreEngine->projectPoints($p, $scoringRules);
            $scored[] = array_merge($p, ['proj' => $projected]);
        }

        // Greedily assign starters
        $assigned = [];
        $slotOrder = ['QB', 'RB', 'WR', 'TE', 'FLEX', 'K'];

        foreach ($slotOrder as $slot) {
            $numSlots = $rosterSlots[$slot] ?? 0;
            $eligible = array_filter($scored, function ($p) use ($slot, $assigned) {
                if (in_array($p['player_id'], $assigned)) return false;
                if ($slot === 'FLEX') return in_array($p['position'], ['RB', 'WR', 'TE']);
                return $p['position'] === $slot;
            });

            usort($eligible, fn($a, $b) => $b['proj'] <=> $a['proj']);
            $filled = 0;
            foreach ($eligible as $p) {
                if ($filled >= $numSlots) break;
                $assigned[] = $p['player_id'];
                $this->db->prepare(
                    "UPDATE fantasy_rosters SET is_starter = 1, roster_slot = ?
                     WHERE fantasy_manager_id = ? AND player_id = ?"
                )->execute([$slot, $managerId, $p['player_id']]);
                $filled++;
            }
        }

        // Bench everyone else
        $this->db->prepare(
            "UPDATE fantasy_rosters SET is_starter = 0, roster_slot = 'BN'
             WHERE fantasy_manager_id = ? AND player_id NOT IN (" .
            implode(',', array_map('intval', $assigned)) . ")"
        )->execute([$managerId]);
    }

    // ── Schedule Generation ────────────────────────────────────────────

    /**
     * Generate round-robin matchup schedule for the regular season.
     */
    public function generateSchedule(int $fantasyLeagueId): void
    {
        $league = $this->leagueModel->find($fantasyLeagueId);
        if (!$league) return;

        $managers = $this->managerModel->getByLeague($fantasyLeagueId);
        $numManagers = count($managers);
        $managerIds = array_column($managers, 'id');

        $startWeek = ($league['created_week'] ?? 0) + 1;
        $endWeek = $league['regular_season_end_week'] ?? 13;

        // Round-robin algorithm
        // If odd number of teams, add a "bye" placeholder
        $teams = $managerIds;
        $hasBye = false;
        if ($numManagers % 2 !== 0) {
            $teams[] = -1; // bye
            $hasBye = true;
        }
        $n = count($teams);

        $matchups = [];
        $week = $startWeek;
        $rotations = 0;

        while ($week <= $endWeek) {
            $weekMatchups = [];

            // Standard round-robin: fix first team, rotate rest
            for ($i = 0; $i < $n / 2; $i++) {
                $home = $teams[$i];
                $away = $teams[$n - 1 - $i];

                // Skip bye matchups
                if ($home === -1 || $away === -1) continue;

                $weekMatchups[] = [
                    'fantasy_league_id' => $fantasyLeagueId,
                    'week' => $week,
                    'manager1_id' => $home,
                    'manager2_id' => $away,
                    'is_playoff' => 0,
                    'is_championship' => 0,
                    'is_consolation' => 0,
                ];
            }

            foreach ($weekMatchups as $m) {
                $this->matchupModel->create($m);
            }

            // Rotate: keep first team fixed, rotate the rest
            $fixed = $teams[0];
            $rest = array_slice($teams, 1);
            $last = array_pop($rest);
            array_unshift($rest, $last);
            $teams = array_merge([$fixed], $rest);

            $week++;
            $rotations++;

            // If we've done a full rotation, reshuffle for variety
            if ($rotations >= ($n - 1)) {
                shuffle($teams);
                if ($hasBye && !in_array(-1, $teams)) {
                    $teams[] = -1;
                }
                $rotations = 0;
            }
        }
    }

    // ── Weekly Processing ──────────────────────────────────────────────

    /**
     * Process a fantasy week after NFL games are simulated.
     * Called from SimulationController after advanceWeek().
     */
    public function processWeek(int $fantasyLeagueId, int $week): array
    {
        $league = $this->leagueModel->getWithManagers($fantasyLeagueId);
        if (!$league || $league['status'] === 'complete') {
            return ['error' => 'League not active'];
        }

        $results = [];

        // 1. Score all players for this week
        $scores = $this->scoreEngine->scoreWeek($fantasyLeagueId, $week);
        $results['players_scored'] = count($scores);

        // 2. Calculate matchup results
        $matchupResults = $this->resolveMatchups($fantasyLeagueId, $week);
        $results['matchups'] = $matchupResults;

        // 3. Process waivers
        if ($week < ($league['regular_season_end_week'] ?? 13)) {
            $waiverResults = $this->processWaivers($fantasyLeagueId, $week);
            $results['waivers'] = $waiverResults;
        }

        // 4. AI managers set lineups for next week
        $nextWeek = $week + 1;
        foreach ($league['managers'] as $m) {
            if ($m['is_ai']) {
                $brain = new FantasyBrain($m, $league);
                $brain->setLineup($nextWeek);
            }
        }
        $results['lineups_set'] = true;

        // 5. AI managers generate trade proposals
        $tradeResults = $this->processAITrades($fantasyLeagueId, $league);
        $results['trade_proposals'] = $tradeResults;

        // 6. Check if regular season is over → seed playoffs
        if ($week >= ($league['regular_season_end_week'] ?? 13) && $league['status'] === 'active') {
            $this->seedPlayoffs($fantasyLeagueId);
            $this->leagueModel->update($fantasyLeagueId, [
                'status' => 'playoffs',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $results['playoffs_seeded'] = true;
        }

        // 7. Check if this was the championship week
        if ($week >= ($league['championship_week'] ?? 16)) {
            $this->crowdChampion($fantasyLeagueId, $week);
            $this->leagueModel->update($fantasyLeagueId, [
                'status' => 'complete',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $results['season_complete'] = true;
        }

        return $results;
    }

    /**
     * Resolve all matchups for a given week.
     */
    private function resolveMatchups(int $fantasyLeagueId, int $week): array
    {
        $matchups = $this->matchupModel->getByWeek($fantasyLeagueId, $week);
        $results = [];

        foreach ($matchups as $matchup) {
            $score1 = $this->managerModel->getWeekScore($matchup['manager1_id'], $fantasyLeagueId, $week);
            $score2 = $this->managerModel->getWeekScore($matchup['manager2_id'], $fantasyLeagueId, $week);

            $winnerId = null;
            if ($score1 > $score2) {
                $winnerId = $matchup['manager1_id'];
                $this->managerModel->recordResult($matchup['manager1_id'], 'win', $score1, $score2);
                $this->managerModel->recordResult($matchup['manager2_id'], 'loss', $score2, $score1);
            } elseif ($score2 > $score1) {
                $winnerId = $matchup['manager2_id'];
                $this->managerModel->recordResult($matchup['manager2_id'], 'win', $score2, $score1);
                $this->managerModel->recordResult($matchup['manager1_id'], 'loss', $score1, $score2);
            } else {
                $this->managerModel->recordResult($matchup['manager1_id'], 'tie', $score1, $score2);
                $this->managerModel->recordResult($matchup['manager2_id'], 'tie', $score2, $score1);
            }

            $this->matchupModel->update($matchup['id'], [
                'manager1_score' => $score1,
                'manager2_score' => $score2,
                'winner_id' => $winnerId,
            ]);

            $results[] = [
                'team1' => $matchup['team1_name'],
                'score1' => $score1,
                'team2' => $matchup['team2_name'],
                'score2' => $score2,
                'winner' => $winnerId ? ($winnerId === $matchup['manager1_id'] ? $matchup['team1_name'] : $matchup['team2_name']) : 'Tie',
            ];
        }

        return $results;
    }

    /**
     * Process waiver claims (priority-based or FAAB).
     */
    private function processWaivers(int $fantasyLeagueId, int $week): array
    {
        $league = $this->leagueModel->getWithManagers($fantasyLeagueId);
        if (!$league) return [];

        $allClaims = [];

        // Gather AI waiver claims
        foreach ($league['managers'] as $m) {
            if (!$m['is_ai']) continue;

            $brain = new FantasyBrain($m, $league);
            $claims = $brain->generateWaiverClaims($week);

            foreach ($claims as $claim) {
                $claim['manager_id'] = $m['id'];
                $claim['manager_name'] = $m['owner_name'];
                $allClaims[] = $claim;
            }
        }

        if (empty($allClaims)) return [];

        // Process by waiver type
        if ($league['waiver_type'] === 'faab') {
            // Sort by FAAB bid descending
            usort($allClaims, fn($a, $b) => $b['faab_bid'] <=> $a['faab_bid']);
        } else {
            // Sort by waiver priority (lower = earlier)
            usort($allClaims, function ($a, $b) use ($league) {
                $aPri = 999;
                $bPri = 999;
                foreach ($league['managers'] as $m) {
                    if ($m['id'] === $a['manager_id']) $aPri = $m['waiver_priority'];
                    if ($m['id'] === $b['manager_id']) $bPri = $m['waiver_priority'];
                }
                return $aPri <=> $bPri;
            });
        }

        $processed = [];
        $claimedPlayers = [];

        foreach ($allClaims as $claim) {
            // Skip if player already claimed this cycle
            if (in_array($claim['player_id'], $claimedPlayers)) continue;

            // Check player is still available
            if ($this->rosterModel->isOwned($fantasyLeagueId, $claim['player_id'])) continue;

            // Drop the designated player
            $this->rosterModel->dropPlayer($claim['manager_id'], $claim['drop_player_id']);

            // Add the new player
            $this->rosterModel->create([
                'fantasy_league_id' => $fantasyLeagueId,
                'fantasy_manager_id' => $claim['manager_id'],
                'player_id' => $claim['player_id'],
                'roster_slot' => 'BN',
                'is_starter' => 0,
                'acquired_via' => 'waivers',
                'acquired_week' => $week,
            ]);

            // Deduct FAAB if applicable
            if ($league['waiver_type'] === 'faab' && ($claim['faab_bid'] ?? 0) > 0) {
                $mgr = $this->managerModel->find($claim['manager_id']);
                $this->managerModel->update($claim['manager_id'], [
                    'faab_remaining' => max(0, ($mgr['faab_remaining'] ?? 100) - $claim['faab_bid']),
                ]);
            }

            // Log transactions
            $this->db->prepare(
                "INSERT INTO fantasy_transactions (fantasy_league_id, fantasy_manager_id, type, player_id, player2_id, details, week, created_at)
                 VALUES (?, ?, 'waiver_add', ?, ?, ?, ?, ?)"
            )->execute([
                $fantasyLeagueId, $claim['manager_id'], $claim['player_id'], $claim['drop_player_id'],
                json_encode(['faab_bid' => $claim['faab_bid'] ?? 0]),
                $week, date('Y-m-d H:i:s'),
            ]);

            $claimedPlayers[] = $claim['player_id'];
            $processed[] = [
                'manager' => $claim['manager_name'],
                'added' => $claim['player_id'],
                'dropped' => $claim['drop_player_id'],
                'bid' => $claim['faab_bid'] ?? 0,
            ];
        }

        // Rotate waiver priority (worst record gets highest priority)
        $this->updateWaiverPriority($fantasyLeagueId);

        return $processed;
    }

    /**
     * Process AI trade proposals and auto-respond.
     */
    private function processAITrades(int $fantasyLeagueId, array $league): int
    {
        $count = 0;

        foreach ($league['managers'] as $m) {
            if (!$m['is_ai']) continue;

            $brain = new FantasyBrain($m, $league);
            $proposals = $brain->generateTradeProposals($league['managers']);

            foreach ($proposals as $prop) {
                // Save the proposal
                $propId = $this->db->prepare(
                    "INSERT INTO fantasy_trade_proposals (fantasy_league_id, proposer_id, recipient_id, players_offered, players_requested, message, status, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)"
                );
                $propId->execute([
                    $fantasyLeagueId, $m['id'], $prop['recipient_id'],
                    $prop['players_offered'], $prop['players_requested'],
                    $prop['message'], date('Y-m-d H:i:s'),
                ]);

                // If recipient is AI, evaluate immediately
                $recipient = null;
                foreach ($league['managers'] as $rm) {
                    if ($rm['id'] === $prop['recipient_id']) {
                        $recipient = $rm;
                        break;
                    }
                }

                if ($recipient && $recipient['is_ai']) {
                    $recipientBrain = new FantasyBrain($recipient, $league);

                    // Load offered/requested player details
                    $offeredIds = json_decode($prop['players_offered'], true);
                    $requestedIds = json_decode($prop['players_requested'], true);

                    $offeredPlayers = [];
                    $requestedPlayers = [];
                    foreach ($offeredIds as $pid) {
                        $p = (new \App\Models\Player())->find($pid);
                        if ($p) $offeredPlayers[] = $p;
                    }
                    foreach ($requestedIds as $pid) {
                        $p = (new \App\Models\Player())->find($pid);
                        if ($p) $requestedPlayers[] = $p;
                    }

                    $accepted = $recipientBrain->evaluateTradeProposal($offeredPlayers, $requestedPlayers);
                    $lastId = $this->db->lastInsertId();

                    if ($accepted) {
                        $this->executeTrade($fantasyLeagueId, $m['id'], $prop['recipient_id'], $offeredIds, $requestedIds);
                        $this->db->prepare(
                            "UPDATE fantasy_trade_proposals SET status = 'accepted', responded_at = ? WHERE id = ?"
                        )->execute([date('Y-m-d H:i:s'), $lastId]);
                    } else {
                        $this->db->prepare(
                            "UPDATE fantasy_trade_proposals SET status = 'rejected', responded_at = ? WHERE id = ?"
                        )->execute([date('Y-m-d H:i:s'), $lastId]);
                    }
                }
                // If recipient is human, leave as pending for them to respond

                $count++;
            }
        }

        return $count;
    }

    /**
     * Execute a trade between two managers.
     */
    public function executeTrade(int $fantasyLeagueId, int $fromId, int $toId, array $fromPlayerIds, array $toPlayerIds): void
    {
        $league = $this->leagueModel->find($fantasyLeagueId);
        $week = $league['created_week'] ?? 0;

        // Swap ownership
        foreach ($fromPlayerIds as $pid) {
            $this->db->prepare(
                "UPDATE fantasy_rosters SET fantasy_manager_id = ?, is_starter = 0, roster_slot = 'BN', acquired_via = 'trade', acquired_week = ?
                 WHERE fantasy_league_id = ? AND player_id = ? AND fantasy_manager_id = ?"
            )->execute([$toId, $week, $fantasyLeagueId, $pid, $fromId]);
        }

        foreach ($toPlayerIds as $pid) {
            $this->db->prepare(
                "UPDATE fantasy_rosters SET fantasy_manager_id = ?, is_starter = 0, roster_slot = 'BN', acquired_via = 'trade', acquired_week = ?
                 WHERE fantasy_league_id = ? AND player_id = ? AND fantasy_manager_id = ?"
            )->execute([$fromId, $week, $fantasyLeagueId, $pid, $toId]);
        }

        // Log
        $this->db->prepare(
            "INSERT INTO fantasy_transactions (fantasy_league_id, fantasy_manager_id, type, details, week, created_at)
             VALUES (?, ?, 'trade', ?, ?, ?)"
        )->execute([
            $fantasyLeagueId, $fromId,
            json_encode(['sent' => $fromPlayerIds, 'received' => $toPlayerIds, 'partner' => $toId]),
            $week, date('Y-m-d H:i:s'),
        ]);
    }

    // ── Playoffs ───────────────────────────────────────────────────────

    /**
     * Seed playoff matchups based on final standings.
     */
    private function seedPlayoffs(int $fantasyLeagueId): void
    {
        $league = $this->leagueModel->find($fantasyLeagueId);
        if (!$league) return;

        $managers = $this->managerModel->getByLeague($fantasyLeagueId);
        $numPlayoff = $league['num_playoff_teams'] ?? 4;
        $playoffStart = $league['playoff_start_week'] ?? 14;

        // Top N by record (already sorted by wins DESC, points_for DESC)
        $playoffTeams = array_slice($managers, 0, $numPlayoff);

        // Assign seeds
        foreach ($playoffTeams as $seed => $m) {
            $this->managerModel->update($m['id'], ['playoff_seed' => $seed + 1]);
        }

        // Eliminate non-playoff teams
        foreach (array_slice($managers, $numPlayoff) as $m) {
            $this->managerModel->update($m['id'], ['is_eliminated' => 1]);
        }

        // Create playoff matchups: 1v4, 2v3 (for 4-team), 1v8, 2v7, etc.
        $week = $playoffStart;
        $remaining = $playoffTeams;

        while (count($remaining) > 1) {
            $roundMatchups = [];
            $half = count($remaining) / 2;

            for ($i = 0; $i < $half; $i++) {
                $isChampionship = (count($remaining) === 2);
                $this->matchupModel->create([
                    'fantasy_league_id' => $fantasyLeagueId,
                    'week' => $week,
                    'manager1_id' => $remaining[$i]['id'],
                    'manager2_id' => $remaining[count($remaining) - 1 - $i]['id'],
                    'is_playoff' => 1,
                    'is_championship' => $isChampionship ? 1 : 0,
                    'is_consolation' => 0,
                ]);
            }

            // For next round, we'll determine winners when scores come in
            // Create placeholder for next round structure
            $remaining = array_slice($remaining, 0, (int) $half);
            $week++;
        }
    }

    /**
     * Crown the fantasy league champion after the championship week.
     */
    private function crowdChampion(int $fantasyLeagueId, int $week): void
    {
        // Find the championship matchup
        $championship = $this->db->prepare(
            "SELECT * FROM fantasy_matchups
             WHERE fantasy_league_id = ? AND is_championship = 1 AND week = ?"
        );
        $championship->execute([$fantasyLeagueId, $week]);
        $match = $championship->fetch();

        if (!$match || !$match['winner_id']) return;

        $this->managerModel->update($match['winner_id'], ['is_champion' => 1]);
    }

    /**
     * Update waiver priority: worst record gets priority 1.
     */
    private function updateWaiverPriority(int $fantasyLeagueId): void
    {
        $managers = $this->db->prepare(
            "SELECT id, wins, losses, points_for FROM fantasy_managers
             WHERE fantasy_league_id = ?
             ORDER BY wins ASC, points_for ASC"
        );
        $managers->execute([$fantasyLeagueId]);
        $all = $managers->fetchAll();

        foreach ($all as $i => $m) {
            $this->managerModel->update($m['id'], ['waiver_priority' => $i + 1]);
        }
    }

    // ── League Status Queries ──────────────────────────────────────────

    /**
     * Get full league standings with computed stats.
     */
    public function getStandings(int $fantasyLeagueId): array
    {
        $managers = $this->managerModel->getByLeague($fantasyLeagueId);

        foreach ($managers as &$m) {
            $total = $m['wins'] + $m['losses'] + $m['ties'];
            $m['win_pct'] = $total > 0 ? round($m['wins'] / $total, 3) : 0;
            $m['ppg'] = $total > 0 ? round($m['points_for'] / $total, 2) : 0;
            $m['games_played'] = $total;
        }
        unset($m);

        return $managers;
    }

    /**
     * Get the full transaction log for a league.
     */
    public function getTransactions(int $fantasyLeagueId, int $limit = 50): array
    {
        return $this->db->prepare(
            "SELECT ft.*, fm.owner_name, fm.team_name,
                    p.first_name as player_first, p.last_name as player_last, p.position as player_pos
             FROM fantasy_transactions ft
             JOIN fantasy_managers fm ON fm.id = ft.fantasy_manager_id
             LEFT JOIN players p ON p.id = ft.player_id
             WHERE ft.fantasy_league_id = ?
             ORDER BY ft.created_at DESC
             LIMIT ?"
        )->execute([$fantasyLeagueId, $limit]) ? $this->db->prepare(
            "SELECT ft.*, fm.owner_name, fm.team_name,
                    p.first_name as player_first, p.last_name as player_last, p.position as player_pos
             FROM fantasy_transactions ft
             JOIN fantasy_managers fm ON fm.id = ft.fantasy_manager_id
             LEFT JOIN players p ON p.id = ft.player_id
             WHERE ft.fantasy_league_id = ?
             ORDER BY ft.created_at DESC
             LIMIT ?"
        ) : [];
    }

    /**
     * Get transaction log (cleaner query).
     */
    public function getTransactionLog(int $fantasyLeagueId, int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            "SELECT ft.*, fm.owner_name, fm.team_name,
                    p.first_name as player_first, p.last_name as player_last, p.position as player_pos
             FROM fantasy_transactions ft
             JOIN fantasy_managers fm ON fm.id = ft.fantasy_manager_id
             LEFT JOIN players p ON p.id = ft.player_id
             WHERE ft.fantasy_league_id = ?
             ORDER BY ft.created_at DESC
             LIMIT ?"
        );
        $stmt->execute([$fantasyLeagueId, $limit]);
        return $stmt->fetchAll();
    }
}
