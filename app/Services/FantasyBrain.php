<?php

namespace App\Services;

use App\Models\FantasyLeague;
use App\Models\FantasyManager;
use App\Models\FantasyRoster;
use App\Models\FantasyMatchup;
use App\Database\Connection;

/**
 * FantasyBrain — AI manager logic for fantasy football.
 *
 * Each AI manager has a personality archetype that drives every decision:
 * drafting, lineup setting, trades, and waiver claims. They adapt their
 * behavior based on their record (contending, bubble, eliminated).
 *
 * Personality archetypes:
 *   analyst     — strictly value-based, no emotion
 *   gut_player  — rides hot hands, reaches for favorites
 *   shark       — exploits value, lowballs trades, aggressive waivers
 *   casual      — slightly suboptimal, sometimes forgets bye weeks
 *   homer       — favors players from 2-3 NFL teams
 *   zero_rb     — loads WRs early, hunts RBs later
 *   rb_heavy    — stockpiles RBs, always flexes RB
 *   streamer    — streams K/DEF weekly, most active on waivers
 */
class FantasyBrain
{
    private \PDO $db;
    private array $manager;
    private array $league;
    private array $scoringRules;
    private array $personality;
    private array $roster = [];
    private FantasyScoreEngine $scoreEngine;

    // ── Personality Definitions ────────────────────────────────────────
    private const PERSONALITIES = [
        'analyst' => [
            'draft_noise' => 2,           // minimal randomness in draft picks
            'reach_tendency' => 0.0,      // never reaches
            'recency_bias' => 0.0,        // ignores recent performance streaks
            'trade_frequency' => 0.3,     // trades occasionally
            'trade_aggression' => 0.0,    // only fair trades
            'accept_threshold' => 0.95,   // needs near-equal value
            'waiver_aggression' => 0.6,   // moderately active
            'faab_early_pct' => 0.15,     // spreads FAAB evenly
            'bye_forget_pct' => 0.0,      // never forgets byes
            'hot_hand_weight' => 0.0,     // no hot-hand bias
            'position_bias' => [],        // no bias
            'favorite_teams_influence' => 0.0,
        ],
        'gut_player' => [
            'draft_noise' => 15,
            'reach_tendency' => 0.3,
            'recency_bias' => 0.4,
            'trade_frequency' => 0.4,
            'trade_aggression' => 0.15,   // overpays for guys they like
            'accept_threshold' => 0.80,
            'waiver_aggression' => 0.5,
            'faab_early_pct' => 0.3,
            'bye_forget_pct' => 0.0,
            'hot_hand_weight' => 0.4,     // heavily weights recent games
            'position_bias' => [],
            'favorite_teams_influence' => 0.0,
        ],
        'shark' => [
            'draft_noise' => 3,
            'reach_tendency' => 0.0,
            'recency_bias' => 0.1,
            'trade_frequency' => 0.8,     // constantly proposing
            'trade_aggression' => -0.25,  // lowballs
            'accept_threshold' => 1.15,   // only accepts clear wins
            'waiver_aggression' => 0.9,
            'faab_early_pct' => 0.40,     // blows budget early
            'bye_forget_pct' => 0.0,
            'hot_hand_weight' => 0.1,
            'position_bias' => [],
            'favorite_teams_influence' => 0.0,
        ],
        'casual' => [
            'draft_noise' => 10,
            'reach_tendency' => 0.1,
            'recency_bias' => 0.2,
            'trade_frequency' => 0.1,     // rarely trades
            'trade_aggression' => 0.0,
            'accept_threshold' => 0.85,   // accepts reasonable deals
            'waiver_aggression' => 0.2,   // only when forced
            'faab_early_pct' => 0.1,
            'bye_forget_pct' => 0.10,     // 10% chance to leave bye player in
            'hot_hand_weight' => 0.2,
            'position_bias' => [],
            'favorite_teams_influence' => 0.0,
        ],
        'homer' => [
            'draft_noise' => 8,
            'reach_tendency' => 0.25,
            'recency_bias' => 0.15,
            'trade_frequency' => 0.2,
            'trade_aggression' => 0.1,
            'accept_threshold' => 0.90,
            'waiver_aggression' => 0.4,
            'faab_early_pct' => 0.2,
            'bye_forget_pct' => 0.0,
            'hot_hand_weight' => 0.15,
            'position_bias' => [],
            'favorite_teams_influence' => 0.35,  // big boost for fav team players
        ],
        'zero_rb' => [
            'draft_noise' => 4,
            'reach_tendency' => 0.1,
            'recency_bias' => 0.1,
            'trade_frequency' => 0.5,
            'trade_aggression' => 0.05,
            'accept_threshold' => 0.90,
            'waiver_aggression' => 0.7,
            'faab_early_pct' => 0.25,
            'bye_forget_pct' => 0.0,
            'hot_hand_weight' => 0.1,
            'position_bias' => ['RB' => -25, 'WR' => 15, 'TE' => 10],  // push RBs down in draft
            'favorite_teams_influence' => 0.0,
        ],
        'rb_heavy' => [
            'draft_noise' => 4,
            'reach_tendency' => 0.15,
            'recency_bias' => 0.1,
            'trade_frequency' => 0.4,
            'trade_aggression' => 0.05,
            'accept_threshold' => 0.90,
            'waiver_aggression' => 0.6,
            'faab_early_pct' => 0.2,
            'bye_forget_pct' => 0.0,
            'hot_hand_weight' => 0.1,
            'position_bias' => ['RB' => 20, 'WR' => -10],  // push RBs up
            'favorite_teams_influence' => 0.0,
        ],
        'streamer' => [
            'draft_noise' => 5,
            'reach_tendency' => 0.05,
            'recency_bias' => 0.2,
            'trade_frequency' => 0.5,
            'trade_aggression' => 0.0,
            'accept_threshold' => 0.90,
            'waiver_aggression' => 0.95,  // most active on waivers
            'faab_early_pct' => 0.05,     // spreads budget across season
            'bye_forget_pct' => 0.0,
            'hot_hand_weight' => 0.3,
            'position_bias' => ['K' => -30, 'DEF' => -30],  // streams these late
            'favorite_teams_influence' => 0.0,
        ],
    ];

    // ── AI Manager Names Pool ──────────────────────────────────────────
    private const FIRST_NAMES = [
        'Derek', 'Marcus', 'Sarah', 'James', 'Priya', 'Carlos', 'Emily', 'Tyrone',
        'Megan', 'Brian', 'Aaliyah', 'Kevin', 'Jessica', 'Dave', 'Nicole', 'Andre',
        'Lauren', 'Travis', 'Kayla', 'Mike', 'Olivia', 'Jordan', 'Tiffany', 'Chris',
        'Aisha', 'Brandon', 'Rebecca', 'Dante', 'Amanda', 'Tyler',
    ];

    private const LAST_NAMES = [
        'Vaughn', 'Chen', 'Rodriguez', 'Williams', 'Patel', 'O\'Brien', 'Jackson',
        'Kim', 'Martinez', 'Thompson', 'Shah', 'Murphy', 'Davis', 'Nguyen', 'Garcia',
        'Wilson', 'Lee', 'Brown', 'Anderson', 'Taylor', 'Moore', 'Clark', 'Lewis',
        'Hall', 'Young', 'Allen', 'Wright', 'Scott', 'Torres', 'Hill',
    ];

    private const TEAM_NAMES = [
        'Touchdown Titans', 'Gridiron Gurus', 'The Waiver Wires', 'Fantasy Phenoms',
        'Blitz Brigade', 'The Armchair QBs', 'Sunday Funday', 'Red Zone Rockets',
        'The Draft Kings', 'Fumble Force', 'Pigskin Prophets', 'End Zone Express',
        'The Sleeper Picks', 'Hail Mary Heroes', 'Bench Warmers', 'Fourth & Long',
        'The Bye Week Blues', 'Sack Attack', 'Trade Deadline Terrors', 'Point Chasers',
        'The Underdogs', 'Weekly Waivers', 'Championship Chasers', 'The Roster Rotators',
        'Snap Count Savants', 'The Flex Position', 'Target Monsters', 'Pick Six Pack',
        'The Commissioner\'s Crew', 'Playoff Pushers',
    ];

    private const AVATAR_COLORS = [
        '#EF4444', '#F97316', '#EAB308', '#22C55E', '#14B8A6',
        '#3B82F6', '#6366F1', '#A855F7', '#EC4899', '#F43F5E',
        '#0EA5E9', '#10B981', '#8B5CF6', '#D946EF', '#F59E0B',
    ];

    // NFL team abbreviations for homer personality
    private const NFL_TEAMS = [
        'ARI', 'ATL', 'BAL', 'BUF', 'CAR', 'CHI', 'CIN', 'CLE',
        'DAL', 'DEN', 'DET', 'GB', 'HOU', 'IND', 'JAX', 'KC',
        'LV', 'LAC', 'LAR', 'MIA', 'MIN', 'NE', 'NO', 'NYG',
        'NYJ', 'PHI', 'PIT', 'SF', 'SEA', 'TB', 'TEN', 'WAS',
    ];

    // ── Constructor ────────────────────────────────────────────────────

    public function __construct(array $manager, array $league)
    {
        $this->db = Connection::getInstance()->getPdo();
        $this->manager = $manager;
        $this->league = $league;
        $this->scoringRules = $league['scoring_rules']
            ? (is_string($league['scoring_rules']) ? json_decode($league['scoring_rules'], true) : $league['scoring_rules'])
            : FantasyLeague::SCORING_PRESETS[$league['scoring_type']] ?? FantasyLeague::SCORING_PRESETS['ppr'];

        $personalityKey = $manager['personality'] ?? 'analyst';
        $this->personality = self::PERSONALITIES[$personalityKey] ?? self::PERSONALITIES['analyst'];

        $this->scoreEngine = new FantasyScoreEngine();
    }

    // ── Static Helpers ─────────────────────────────────────────────────

    /**
     * Generate AI managers to fill a fantasy league.
     */
    public static function generateAIManagers(int $count, array $usedNames = []): array
    {
        $personalities = array_keys(self::PERSONALITIES);
        $managers = [];
        $usedFirstLast = $usedNames;
        $usedTeamNames = [];

        for ($i = 0; $i < $count; $i++) {
            // Assign personality — cycle through all types, then randomize
            $personality = $personalities[$i % count($personalities)];

            // Generate unique name
            do {
                $first = self::FIRST_NAMES[array_rand(self::FIRST_NAMES)];
                $last = self::LAST_NAMES[array_rand(self::LAST_NAMES)];
                $fullName = "$first $last";
            } while (in_array($fullName, $usedFirstLast));
            $usedFirstLast[] = $fullName;

            // Generate unique team name
            do {
                $teamName = self::TEAM_NAMES[array_rand(self::TEAM_NAMES)];
            } while (in_array($teamName, $usedTeamNames));
            $usedTeamNames[] = $teamName;

            // Pick color
            $color = self::AVATAR_COLORS[$i % count(self::AVATAR_COLORS)];

            // Homer gets 2-3 favorite NFL teams
            $favoriteTeams = [];
            if ($personality === 'homer') {
                $teamKeys = array_rand(self::NFL_TEAMS, 3);
                $favoriteTeams = array_map(fn($k) => self::NFL_TEAMS[$k], $teamKeys);
            }

            $managers[] = [
                'team_name' => $teamName,
                'owner_name' => $fullName,
                'avatar_color' => $color,
                'is_ai' => 1,
                'personality' => $personality,
                'favorite_nfl_teams' => !empty($favoriteTeams) ? json_encode($favoriteTeams) : null,
                'created_at' => date('Y-m-d H:i:s'),
            ];
        }

        return $managers;
    }

    /**
     * Get a random personality type.
     */
    public static function randomPersonality(): string
    {
        $types = array_keys(self::PERSONALITIES);
        return $types[array_rand($types)];
    }

    // ── Draft Logic ────────────────────────────────────────────────────

    /**
     * Make a draft pick. Returns the player_id to draft.
     *
     * @param array $availablePlayers Players not yet drafted [{id, position, overall_rating, team_id, ...}]
     * @param array $currentRoster   Players already drafted by this manager
     * @param int   $round           Current draft round (1-based)
     * @param int   $totalRounds     Total rounds in the draft
     */
    public function makeDraftPick(array $availablePlayers, array $currentRoster, int $round, int $totalRounds): ?int
    {
        if (empty($availablePlayers)) return null;

        $rosterSlots = is_string($this->league['roster_slots'] ?? null)
            ? json_decode($this->league['roster_slots'], true)
            : ($this->league['roster_slots'] ?? FantasyLeague::DEFAULT_ROSTER_SLOTS);

        // Build personal rankings
        $ranked = $this->rankPlayersForDraft($availablePlayers, $currentRoster, $round, $totalRounds, $rosterSlots);

        // Pick the top-ranked player
        return $ranked[0]['id'] ?? null;
    }

    /**
     * Rank available players through this AI's personality lens.
     */
    private function rankPlayersForDraft(array $players, array $roster, int $round, int $totalRounds, array $slots): array
    {
        $rosterPositions = array_count_values(array_column($roster, 'position'));
        $favoriteTeams = $this->manager['favorite_nfl_teams']
            ? (is_string($this->manager['favorite_nfl_teams'])
                ? json_decode($this->manager['favorite_nfl_teams'], true)
                : $this->manager['favorite_nfl_teams'])
            : [];

        foreach ($players as &$p) {
            // Base value: projected fantasy points per game
            $projected = $this->scoreEngine->projectPoints($p, $this->scoringRules);
            $baseValue = $projected * 17; // season-long projection

            // Position need modifier
            $posCount = $rosterPositions[$p['position']] ?? 0;
            $maxForPos = $this->getMaxRosterSlots($p['position'], $slots);
            $needMod = ($posCount < $maxForPos) ? 1.1 : 0.7;

            // Positional cap — don't draft more than needed + bench depth
            if ($posCount >= ($maxForPos + 2)) {
                $needMod = 0.2; // severe penalty
            }

            // Personality: position bias
            $posBias = $this->personality['position_bias'][$p['position']] ?? 0;

            // Personality: homer boost
            $homerBoost = 0;
            if ($this->personality['favorite_teams_influence'] > 0 && !empty($favoriteTeams)) {
                $teamAbbr = $p['team_abbr'] ?? '';
                if (in_array($teamAbbr, $favoriteTeams)) {
                    $homerBoost = $baseValue * $this->personality['favorite_teams_influence'];
                }
            }

            // Personality: reach tendency (random boost for "their guys")
            $reachBoost = 0;
            if ($this->personality['reach_tendency'] > 0) {
                // Deterministic seed based on manager + player so the preference is consistent
                $seed = crc32($this->manager['id'] . '_' . $p['id']);
                mt_srand($seed);
                if (mt_rand(0, 100) / 100 < $this->personality['reach_tendency']) {
                    $reachBoost = $baseValue * 0.2;
                }
                mt_srand(); // reset
            }

            // Personality: random noise
            $noise = (mt_rand(-100, 100) / 100) * $this->personality['draft_noise'];

            // Late-round strategy: streamer skips K/DEF until last rounds
            $lateRoundPenalty = 0;
            if (in_array($p['position'], ['K']) && $round < ($totalRounds - 1)) {
                if ($this->manager['personality'] === 'streamer') {
                    $lateRoundPenalty = -200;
                }
            }

            $p['draft_value'] = ($baseValue * $needMod) + $posBias + $homerBoost + $reachBoost + $noise + $lateRoundPenalty;
        }
        unset($p);

        usort($players, fn($a, $b) => $b['draft_value'] <=> $a['draft_value']);
        return $players;
    }

    /**
     * How many starting slots exist for a position.
     */
    private function getMaxRosterSlots(string $position, array $slots): int
    {
        $count = $slots[$position] ?? 0;
        // FLEX can hold RB, WR, or TE
        if (in_array($position, ['RB', 'WR', 'TE'])) {
            $count += ($slots['FLEX'] ?? 0);
        }
        return $count;
    }

    // ── Lineup Setting ─────────────────────────────────────────────────

    /**
     * Set the optimal lineup for the upcoming week.
     * Returns array of roster moves: [{player_id, roster_slot, is_starter}]
     */
    public function setLineup(int $week): array
    {
        $rosterModel = new FantasyRoster();
        $this->roster = $rosterModel->getRosterOrdered($this->manager['id']);

        if (empty($this->roster)) return [];

        $rosterSlots = is_string($this->league['roster_slots'] ?? null)
            ? json_decode($this->league['roster_slots'], true)
            : ($this->league['roster_slots'] ?? FantasyLeague::DEFAULT_ROSTER_SLOTS);

        // Get bye week teams for this week
        $byeTeams = $this->getByeTeams($week);

        // Score each player for this week
        $scored = [];
        foreach ($this->roster as $player) {
            $isOnBye = in_array($player['team_id'], $byeTeams);
            $projected = $this->scoreEngine->projectPoints($player, $this->scoringRules);

            // Personality: casual might forget to check byes
            if ($isOnBye && $this->personality['bye_forget_pct'] > 0) {
                if (mt_rand(0, 100) / 100 < $this->personality['bye_forget_pct']) {
                    $isOnBye = false; // "forgets" the bye — leaves player in
                }
            }

            // Personality: hot hand (boost players who scored well recently)
            $hotHandBonus = 0;
            if ($this->personality['hot_hand_weight'] > 0 && $week > 1) {
                $recentPoints = $this->getRecentPoints($player['player_id'], $week, 2);
                $hotHandBonus = $recentPoints * $this->personality['hot_hand_weight'] * 0.1;
            }

            $score = $isOnBye ? -100 : ($projected + $hotHandBonus);

            $scored[] = array_merge($player, [
                'lineup_score' => $score,
                'on_bye' => $isOnBye,
            ]);
        }

        // Assign starters greedily by slot priority
        $assignments = [];
        $assigned = [];

        $slotOrder = ['QB', 'RB', 'WR', 'TE', 'FLEX', 'K'];
        $flexPositions = ['RB', 'WR', 'TE'];

        foreach ($slotOrder as $slot) {
            $numSlots = $rosterSlots[$slot] ?? 0;
            $eligible = array_filter($scored, function ($p) use ($slot, $flexPositions, $assigned) {
                if (in_array($p['player_id'], $assigned)) return false;
                if ($slot === 'FLEX') return in_array($p['position'], $flexPositions);
                return $p['position'] === $slot;
            });

            // Sort by lineup score desc
            usort($eligible, fn($a, $b) => $b['lineup_score'] <=> $a['lineup_score']);

            $filled = 0;
            foreach ($eligible as $p) {
                if ($filled >= $numSlots) break;
                $assignments[] = [
                    'player_id' => $p['player_id'],
                    'roster_slot' => $slot . ($filled > 0 ? ($filled + 1) : ''),
                    'is_starter' => 1,
                ];
                $assigned[] = $p['player_id'];
                $filled++;
            }
        }

        // Everyone else goes to bench
        foreach ($scored as $p) {
            if (!in_array($p['player_id'], $assigned)) {
                $assignments[] = [
                    'player_id' => $p['player_id'],
                    'roster_slot' => 'BN',
                    'is_starter' => 0,
                ];
            }
        }

        // Apply to database
        foreach ($assignments as $a) {
            $this->db->prepare(
                "UPDATE fantasy_rosters SET roster_slot = ?, is_starter = ?
                 WHERE fantasy_manager_id = ? AND player_id = ?"
            )->execute([$a['roster_slot'], $a['is_starter'], $this->manager['id'], $a['player_id']]);
        }

        return $assignments;
    }

    // ── Trade Logic ────────────────────────────────────────────────────

    /**
     * Generate trade proposals this AI wants to make.
     * Returns array of proposals: [{recipient_id, players_offered, players_requested, message}]
     */
    public function generateTradeProposals(array $allManagers): array
    {
        // Check if this personality even wants to trade this week
        if (mt_rand(0, 100) / 100 > $this->personality['trade_frequency']) {
            return [];
        }

        // Adapt based on record
        $mode = $this->getCompetitiveMode();
        $proposals = [];

        // Load our roster
        $rosterModel = new FantasyRoster();
        $myRoster = $rosterModel->getRosterOrdered($this->manager['id']);
        if (count($myRoster) < 3) return [];

        // Identify our weakest starting position
        $weakness = $this->identifyWeakness($myRoster);
        if (!$weakness) return [];

        // Identify our strongest bench depth (what we can trade away)
        $surplus = $this->identifySurplus($myRoster);
        if (!$surplus) return [];

        // Look at other managers' rosters for targets
        foreach ($allManagers as $other) {
            if ($other['id'] === $this->manager['id']) continue;
            if ($other['is_ai'] && mt_rand(0, 100) < 30) continue; // don't flood AI-to-AI

            $theirRoster = $rosterModel->getRosterOrdered($other['id']);
            if (empty($theirRoster)) continue;

            // Find a player at our weak position on their roster
            $target = $this->findTradeTarget($theirRoster, $weakness);
            if (!$target) continue;

            // Find a player to offer from our surplus
            $offering = $this->findTradeOffer($myRoster, $surplus, $target);
            if (!$offering) continue;

            // Value check with personality modifier
            $targetValue = $this->playerTradeValue($target);
            $offerValue = $this->playerTradeValue($offering);
            $ratio = $offerValue / max($targetValue, 1);

            // Apply trade aggression (negative = lowball, positive = overpay)
            $minRatio = 0.85 + $this->personality['trade_aggression'];

            if ($ratio >= $minRatio) {
                $messages = $this->getTradeMessage($mode);
                $proposals[] = [
                    'recipient_id' => $other['id'],
                    'players_offered' => json_encode([$offering['player_id']]),
                    'players_requested' => json_encode([$target['player_id']]),
                    'message' => $messages,
                ];

                // Most personalities only propose 1-2 per week
                if (count($proposals) >= ($this->manager['personality'] === 'shark' ? 3 : 1)) {
                    break;
                }
            }
        }

        return $proposals;
    }

    /**
     * Evaluate an incoming trade proposal. Returns true to accept.
     */
    public function evaluateTradeProposal(array $playersOffered, array $playersRequested): bool
    {
        $offeredValue = array_sum(array_map([$this, 'playerTradeValue'], $playersOffered));
        $requestedValue = array_sum(array_map([$this, 'playerTradeValue'], $playersRequested));

        if ($requestedValue <= 0) return false;

        $ratio = $offeredValue / $requestedValue;

        // Homer won't trade favorites easily
        if ($this->personality['favorite_teams_influence'] > 0) {
            $favoriteTeams = $this->manager['favorite_nfl_teams']
                ? (is_string($this->manager['favorite_nfl_teams'])
                    ? json_decode($this->manager['favorite_nfl_teams'], true)
                    : $this->manager['favorite_nfl_teams'])
                : [];

            foreach ($playersRequested as $p) {
                $teamAbbr = $p['team_abbr'] ?? '';
                if (in_array($teamAbbr, $favoriteTeams)) {
                    $ratio *= 0.7; // requires much more to give up favorite
                }
            }
        }

        // Competitive mode adjustments
        $mode = $this->getCompetitiveMode();
        if ($mode === 'eliminated' && $this->manager['personality'] === 'casual') {
            return false; // checked out, rejects everything
        }
        if ($mode === 'bubble') {
            // More desperate, lowers threshold
            return $ratio >= ($this->personality['accept_threshold'] - 0.1);
        }

        return $ratio >= $this->personality['accept_threshold'];
    }

    // ── Waiver Logic ───────────────────────────────────────────────────

    /**
     * Generate waiver claims for this week.
     * Returns array: [{player_id (to add), drop_player_id, faab_bid}]
     */
    public function generateWaiverClaims(int $week): array
    {
        $rosterModel = new FantasyRoster();
        $myRoster = $rosterModel->getRosterOrdered($this->manager['id']);
        $claims = [];

        // Check if personality is active enough
        $activityRoll = mt_rand(0, 100) / 100;
        if ($activityRoll > $this->personality['waiver_aggression']) {
            return [];
        }

        // Find available players worth picking up
        $available = $rosterModel->getAvailablePlayers(
            $this->league['id'],
            $this->league['league_id'],
            null,
            100
        );

        if (empty($available)) return [];

        // Score available players
        $scoredAvailable = [];
        foreach ($available as $p) {
            $projected = $this->scoreEngine->projectPoints($p, $this->scoringRules);

            // Streamer bonus for K/DEF
            if ($this->manager['personality'] === 'streamer' && in_array($p['position'], ['K'])) {
                $projected *= 1.3;
            }

            // Hot hand: boost players who just had a big game
            if ($this->personality['hot_hand_weight'] > 0 && $week > 1) {
                $lastWeekPts = $this->getPlayerWeekPoints($p['id'], $week - 1);
                if ($lastWeekPts > 15) {
                    $projected += $lastWeekPts * $this->personality['hot_hand_weight'] * 0.3;
                }
            }

            $scoredAvailable[] = array_merge($p, ['waiver_value' => $projected]);
        }

        usort($scoredAvailable, fn($a, $b) => $b['waiver_value'] <=> $a['waiver_value']);

        // Find worst bench player to drop
        $benchPlayers = array_filter($myRoster, fn($p) => !$p['is_starter']);
        if (empty($benchPlayers)) return [];

        usort($benchPlayers, function ($a, $b) {
            return $this->scoreEngine->projectPoints($a, $this->scoringRules)
                <=> $this->scoreEngine->projectPoints($b, $this->scoringRules);
        });

        $worstBench = reset($benchPlayers);
        $worstValue = $this->scoreEngine->projectPoints($worstBench, $this->scoringRules);

        // Claim the best available if better than worst bench
        $maxClaims = ($this->manager['personality'] === 'streamer') ? 3 : 1;
        $dropCandidates = array_values($benchPlayers);
        $dropIdx = 0;

        foreach ($scoredAvailable as $target) {
            if (count($claims) >= $maxClaims) break;
            if ($dropIdx >= count($dropCandidates)) break;

            $targetProjected = $target['waiver_value'];
            $dropValue = $this->scoreEngine->projectPoints($dropCandidates[$dropIdx], $this->scoringRules);

            if ($targetProjected > $dropValue * 1.1) {
                // Calculate FAAB bid
                $faabBid = $this->calculateFaabBid($target, $week);

                $claims[] = [
                    'player_id' => $target['id'],
                    'drop_player_id' => $dropCandidates[$dropIdx]['player_id'],
                    'faab_bid' => $faabBid,
                ];
                $dropIdx++;
            }
        }

        return $claims;
    }

    // ── Helper Methods ─────────────────────────────────────────────────

    /**
     * Determine competitive mode based on record.
     */
    private function getCompetitiveMode(): string
    {
        $wins = $this->manager['wins'] ?? 0;
        $losses = $this->manager['losses'] ?? 0;
        $total = $wins + $losses;

        if ($total < 4) return 'early'; // too early to tell

        $winPct = $total > 0 ? $wins / $total : 0.5;

        if ($winPct >= 0.6) return 'contending';
        if ($winPct >= 0.4) return 'bubble';
        return 'eliminated';
    }

    /**
     * Identify weakest starting position.
     */
    private function identifyWeakness(array $roster): ?string
    {
        $starters = array_filter($roster, fn($p) => $p['is_starter']);
        $positionScores = [];

        foreach ($starters as $p) {
            $pos = $p['position'];
            if (!isset($positionScores[$pos])) $positionScores[$pos] = [];
            $positionScores[$pos][] = $p['overall_rating'];
        }

        // Find position with lowest average starter OVR
        $weakest = null;
        $lowestAvg = 999;
        foreach ($positionScores as $pos => $ratings) {
            $avg = array_sum($ratings) / count($ratings);
            if ($avg < $lowestAvg && in_array($pos, ['QB', 'RB', 'WR', 'TE'])) {
                $lowestAvg = $avg;
                $weakest = $pos;
            }
        }

        return $weakest;
    }

    /**
     * Identify position where we have surplus bench depth.
     */
    private function identifySurplus(array $roster): ?string
    {
        $posCounts = [];
        foreach ($roster as $p) {
            $pos = $p['position'];
            $posCounts[$pos] = ($posCounts[$pos] ?? 0) + 1;
        }

        // Find position with most depth beyond starters
        $bestSurplus = null;
        $maxDepth = 0;
        foreach ($posCounts as $pos => $count) {
            if ($count > 2 && $count > $maxDepth && in_array($pos, ['QB', 'RB', 'WR', 'TE'])) {
                $maxDepth = $count;
                $bestSurplus = $pos;
            }
        }

        return $bestSurplus;
    }

    /**
     * Find a trade target from another team's roster.
     */
    private function findTradeTarget(array $theirRoster, string $targetPosition): ?array
    {
        $candidates = array_filter($theirRoster, fn($p) =>
            $p['position'] === $targetPosition && !$p['is_starter']
        );

        if (empty($candidates)) {
            // Try starters if no bench options
            $candidates = array_filter($theirRoster, fn($p) =>
                $p['position'] === $targetPosition
            );
        }

        if (empty($candidates)) return null;

        // Pick the best available
        usort($candidates, fn($a, $b) => $b['overall_rating'] <=> $a['overall_rating']);
        return reset($candidates);
    }

    /**
     * Find a player to offer in trade from our surplus position.
     */
    private function findTradeOffer(array $myRoster, string $surplusPosition, array $target): ?array
    {
        $candidates = array_filter($myRoster, fn($p) =>
            $p['position'] === $surplusPosition && !$p['is_starter']
        );

        if (empty($candidates)) return null;

        // Find the best bench player at our surplus position
        usort($candidates, fn($a, $b) => $b['overall_rating'] <=> $a['overall_rating']);
        return reset($candidates);
    }

    /**
     * Simple trade value based on OVR + position scarcity.
     */
    private function playerTradeValue(array $player): float
    {
        $ovr = $player['overall_rating'] ?? 70;
        $posMultiplier = [
            'QB' => 1.2, 'RB' => 1.1, 'WR' => 1.1, 'TE' => 1.0,
            'K' => 0.4, 'DEF' => 0.5,
        ];
        $mult = $posMultiplier[$player['position'] ?? 'WR'] ?? 0.8;
        return $ovr * $mult;
    }

    /**
     * Calculate FAAB bid based on personality and remaining budget.
     */
    private function calculateFaabBid(array $player, int $week): int
    {
        $remaining = $this->manager['faab_remaining'] ?? 100;
        if ($remaining <= 0) return 0;

        $projected = $player['waiver_value'] ?? $player['overall_rating'] ?? 70;
        $maxBid = $remaining;

        // Base bid: 5-15% of remaining budget for average players
        $basePct = 0.10;

        // Shark goes big early
        if ($week <= 4) {
            $basePct += $this->personality['faab_early_pct'];
        }

        // Scale by player quality
        $qualityMult = $projected / 12; // 12 ppg is average starter

        $bid = (int) round($maxBid * $basePct * $qualityMult);

        // Casual bids minimum
        if ($this->manager['personality'] === 'casual') {
            $bid = min($bid, 3);
        }

        return max(0, min($bid, $remaining));
    }

    /**
     * Get a player's fantasy points from a recent week.
     */
    private function getPlayerWeekPoints(int $playerId, int $week): float
    {
        $stmt = $this->db->prepare(
            "SELECT points FROM fantasy_scores
             WHERE fantasy_league_id = ? AND player_id = ? AND week = ?"
        );
        $stmt->execute([$this->league['id'], $playerId, $week]);
        $row = $stmt->fetch();
        return (float) ($row['points'] ?? 0);
    }

    /**
     * Get a player's average recent fantasy points.
     */
    private function getRecentPoints(int $playerId, int $currentWeek, int $numWeeks): float
    {
        $startWeek = max(1, $currentWeek - $numWeeks);
        $stmt = $this->db->prepare(
            "SELECT AVG(points) as avg_pts FROM fantasy_scores
             WHERE fantasy_league_id = ? AND player_id = ? AND week >= ? AND week < ?"
        );
        $stmt->execute([$this->league['id'], $playerId, $startWeek, $currentWeek]);
        $row = $stmt->fetch();
        return (float) ($row['avg_pts'] ?? 0);
    }

    /**
     * Get teams on bye this week (simplified — uses game schedule).
     */
    private function getByeTeams(int $week): array
    {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT home_team_id FROM games WHERE league_id = ? AND week = ?
             UNION
             SELECT DISTINCT away_team_id FROM games WHERE league_id = ? AND week = ?"
        );
        $stmt->execute([$this->league['league_id'], $week, $this->league['league_id'], $week]);
        $playingTeams = array_column($stmt->fetchAll(), 'home_team_id');

        // All teams minus playing teams = bye teams
        $allTeams = $this->db->prepare(
            "SELECT id FROM teams WHERE league_id = ?"
        );
        $allTeams->execute([$this->league['league_id']]);
        $allTeamIds = array_column($allTeams->fetchAll(), 'id');

        return array_diff($allTeamIds, $playingTeams);
    }

    /**
     * Get a contextual trade message based on personality and competitive mode.
     */
    private function getTradeMessage(string $mode): string
    {
        $personality = $this->manager['personality'];
        $messages = [
            'shark' => [
                'early' => "I think this could help both of us.",
                'contending' => "Win-win deal. Let's make it happen.",
                'bubble' => "I'm making moves. You in or not?",
                'eliminated' => "Selling off pieces. This is a steal for you.",
            ],
            'analyst' => [
                'early' => "The numbers say this is fair for both sides.",
                'contending' => "Optimizing my roster. This trade grades out evenly.",
                'bubble' => "Statistically this benefits both rosters.",
                'eliminated' => "Retooling. Fair value exchange.",
            ],
            'gut_player' => [
                'early' => "I've got a good feeling about this trade!",
                'contending' => "Let's shake things up! This could be fun.",
                'bubble' => "I need to make a move. What do you say?",
                'eliminated' => "Going with my gut on this one.",
            ],
            'casual' => [
                'early' => "Hey, want to trade?",
                'contending' => "Trade?",
                'bubble' => "Need some help, want to swap?",
                'eliminated' => "Sure why not, want to trade?",
            ],
            'homer' => [
                'early' => "Looking to consolidate my guys.",
                'contending' => "Building around my core. Interested?",
                'bubble' => "Need to make a move here.",
                'eliminated' => "Rebuilding around my favorites.",
            ],
        ];

        $pool = $messages[$personality] ?? $messages['casual'];
        return $pool[$mode] ?? $pool['early'];
    }
}
