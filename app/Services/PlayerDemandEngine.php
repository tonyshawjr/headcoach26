<?php

namespace App\Services;

use App\Database\Connection;

/**
 * PlayerDemandEngine — Players react to narrative arcs and game situations.
 *
 * When a breakout player is outperforming his contract, he demands more money.
 * When a declining veteran gets benched, he reacts based on personality.
 * When a star's morale drops too low, he threatens to hold out.
 *
 * Runs after NarrativeArcTracker + MoraleEngine each week.
 */
class PlayerDemandEngine
{
    private \PDO $db;

    // Don't spam demands — minimum weeks between demands from the same player
    private const DEMAND_COOLDOWN_WEEKS = 3;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
    }

    /**
     * Process all player demands/reactions after a week.
     */
    public function processWeek(int $leagueId, int $seasonId, int $week): array
    {
        $demands = [];

        // 1. Breakout players want better contracts
        $demands = array_merge($demands, $this->processBreakoutDemands($leagueId, $seasonId, $week));

        // 2. Benched veterans react
        $demands = array_merge($demands, $this->processBenchedVeteranReactions($leagueId, $week));

        // 3. Low-morale stars threaten holdouts
        $demands = array_merge($demands, $this->processLowMoraleDemands($leagueId, $week));

        // 4. Contract-year players playing well want extensions
        $demands = array_merge($demands, $this->processContractYearDemands($leagueId, $seasonId, $week));

        // 5. Escalate unresolved demands — morale keeps dropping if ignored
        $this->escalateUnresolvedDemands($leagueId, $week);

        return $demands;
    }

    // ── Breakout Player Demands ─────────────────────────────────────────

    private function processBreakoutDemands(int $leagueId, int $seasonId, int $week): array
    {
        // Find active breakout_player arcs
        $stmt = $this->db->prepare(
            "SELECT * FROM narrative_arcs
             WHERE league_id = ? AND status = 'active' AND type = 'breakout_player'
             AND player_id IS NOT NULL"
        );
        $stmt->execute([$leagueId]);
        $arcs = $stmt->fetchAll();

        $demands = [];

        foreach ($arcs as $arc) {
            $playerId = (int) $arc['player_id'];
            $teamId = $arc['team_id'] ? (int) $arc['team_id'] : null;

            if (!$teamId || $this->isOnCooldown($playerId, $week)) {
                continue;
            }

            $player = $this->loadPlayer($playerId);
            if (!$player) continue;

            // Only starters can demand contracts — backups and special teamers don't have leverage
            $starterCheck = $this->db->prepare(
                "SELECT 1 FROM depth_chart WHERE player_id = ? AND team_id = ? AND slot = 1 LIMIT 1"
            );
            $starterCheck->execute([$playerId, $teamId]);
            if (!$starterCheck->fetch()) continue;

            // Skip injured players
            $injCheck = $this->db->prepare(
                "SELECT 1 FROM injuries WHERE player_id = ? AND team_id = ? AND weeks_remaining > 0 LIMIT 1"
            );
            $injCheck->execute([$playerId, $teamId]);
            if ($injCheck->fetch()) continue;

            // Check if player is underpaid relative to performance
            $contract = $this->getActiveContract($playerId);
            if (!$contract) continue;

            $marketValue = (new ContractEngine())->calculateMarketValue($player);
            $currentSalary = (int) ($contract['salary_annual'] ?? 0);

            // Only demand if significantly underpaid (earning < 60% of market value)
            if ($currentSalary >= $marketValue * 0.6) {
                continue;
            }

            $personality = $player['personality'] ?? 'team_player';
            $playerName = trim($player['first_name'] . ' ' . $player['last_name']);
            $pos = $player['position'];

            $demand = $this->generateBreakoutDemand($playerName, $pos, $personality, $currentSalary, $marketValue, $player);

            // Apply morale impact
            $moraleChange = match ($personality) {
                'diva' => -3,
                'mercenary' => -2,
                'intense' => -1,
                default => -1,
            };
            $this->adjustMorale($playerId, $moraleChange);

            // Create notification for the user's team
            $this->createDemandNotification($leagueId, $teamId, $demand);
            $this->createTickerItem($leagueId, $demand['ticker']);
            $this->recordDemand($playerId, $week, 'breakout_contract', $demand);

            $demands[] = $demand;
        }

        return $demands;
    }

    private function generateBreakoutDemand(string $name, string $pos, string $personality, int $currentSalary, int $marketValue, array $player): array
    {
        $salaryStr = $this->formatSalary($currentSalary);
        $marketStr = $this->formatSalary($marketValue);
        $firstName = $player['first_name'];

        $message = match ($personality) {
            'diva' => "{$name} is making waves in the locker room. \"I'm playing like a top-5 {$pos} and getting paid like a backup. That's disrespectful.\" He wants a new deal now — not after the season.",
            'mercenary' => "{$name}'s agent has reached out to the front office about restructuring his contract. Currently earning {$salaryStr}, his camp believes his market value is closer to {$marketStr} given his breakout performance.",
            'intense' => "{$name} pulled his coach aside after practice. \"I'm not complaining, but I've earned more than what I'm getting. I just want what's fair.\" The message was clear — reward the production or risk losing his fire.",
            'leader' => "{$name} hasn't said a word publicly, but teammates say he's quietly frustrated with his contract situation. \"He'd never say it, but {$firstName} deserves more,\" one veteran said. \"Everyone in this locker room knows it.\"",
            'quiet' => "{$name}'s agent has formally requested a meeting with the front office about a contract adjustment. The {$pos}'s breakout season has made his current deal look like a bargain.",
            default => "{$name} has expressed interest in a contract restructuring through his representation. After a breakout stretch of games, the {$pos} believes his current deal ({$salaryStr}) doesn't reflect his level of play.",
        };

        $ticker = match ($personality) {
            'diva' => "CONTRACT DEMANDS: {$name} publicly demands new deal, calls current salary \"disrespectful\"",
            'mercenary' => "CONTRACT: {$name}'s agent pushing for restructured deal amid breakout season",
            default => "CONTRACT: {$name} seeking new deal after breakout performance",
        };

        return [
            'type' => 'contract_demand',
            'player_id' => (int) $player['id'],
            'player_name' => $name,
            'position' => $pos,
            'personality' => $personality,
            'message' => $message,
            'ticker' => $ticker,
            'current_salary' => $currentSalary,
            'market_value' => $marketValue,
        ];
    }

    // ── Benched Veteran Reactions ────────────────────────────────────────

    private function processBenchedVeteranReactions(int $leagueId, int $week): array
    {
        // Find veterans (4+ years pro, 75+ OVR) who are NOT starting
        // Exclude injured players — they're out because they're hurt, not benched
        $stmt = $this->db->prepare(
            "SELECT p.id, p.first_name, p.last_name, p.position, p.overall_rating,
                    p.personality, p.morale, p.years_pro, p.team_id
             FROM players p
             JOIN teams t ON t.id = p.team_id AND t.league_id = ?
             WHERE p.status = 'active' AND p.years_pro >= 4 AND p.overall_rating >= 75
               AND p.id NOT IN (
                   SELECT dc.player_id FROM depth_chart dc WHERE dc.team_id = p.team_id AND dc.slot = 1
               )
               AND p.id NOT IN (
                   SELECT i.player_id FROM injuries i WHERE i.team_id = p.team_id AND i.weeks_remaining > 0
               )"
        );
        $stmt->execute([$leagueId]);
        $benchedVets = $stmt->fetchAll();

        $demands = [];

        foreach ($benchedVets as $player) {
            $playerId = (int) $player['id'];

            if ($this->isOnCooldown($playerId, $week)) {
                continue;
            }

            // Only react 30% of weeks (not every single week)
            if (mt_rand(1, 100) > 30) {
                continue;
            }

            $personality = $player['personality'] ?? 'team_player';
            $playerName = trim($player['first_name'] . ' ' . $player['last_name']);
            $pos = $player['position'];
            $morale = $player['morale'] ?? 'content';

            // Quiet and team_player personalities are less likely to react
            if (in_array($personality, ['quiet', 'team_player']) && mt_rand(1, 100) > 40) {
                continue;
            }

            $demand = $this->generateBenchedReaction($playerName, $pos, $personality, $morale, $player);

            $moraleChange = match ($personality) {
                'diva' => -3,
                'intense' => -2,
                default => -1,
            };
            $this->adjustMorale($playerId, $moraleChange);

            $this->createDemandNotification($leagueId, (int) $player['team_id'], $demand);
            $this->createTickerItem($leagueId, $demand['ticker']);
            $this->recordDemand($playerId, $week, 'benched_reaction', $demand);

            $demands[] = $demand;
        }

        return $demands;
    }

    private function generateBenchedReaction(string $name, string $pos, string $personality, string $morale, array $player): array
    {
        $firstName = $player['first_name'];
        $ovr = $player['overall_rating'];

        $message = match ($personality) {
            'diva' => "\"{$firstName}\" {$player['last_name']} didn't hold back after being left out of the starting lineup again. \"I'm a {$ovr}-rated {$pos} sitting on the bench. Either play me or trade me.\" Sources say he's requested a trade.",
            'intense' => "{$name} was seen having a heated conversation with the position coach on the sideline. The {$ovr}-rated {$pos} believes he should be starting and isn't hiding his frustration. \"I didn't come here to watch,\" he reportedly told teammates.",
            'mercenary' => "{$name}'s agent has been calling around the league gauging trade interest. The veteran {$pos} sees no path to playing time and wants out before the deadline.",
            'leader' => "{$name} is handling the benching professionally, but the locker room feels the tension. \"He's a pro's pro, but you can tell it's eating at him,\" a teammate said. \"A guy with his resume deserves to be on the field.\"",
            'quiet' => "{$name} has said nothing publicly about losing his starting role, but his body language in practice tells a different story. The coaching staff is aware of the situation.",
            default => "{$name} is unhappy with his role as a backup and has made it known through his representation that he'd welcome a change of scenery.",
        };

        $ticker = match ($personality) {
            'diva' => "UNHAPPY: {$name} demands trade after being benched — \"Play me or trade me\"",
            'intense' => "BENCHED: {$name} visibly frustrated with backup role",
            'mercenary' => "TRADE REQUEST: {$name}'s agent shopping veteran {$pos} to other teams",
            default => "ROSTER: {$name} unhappy with backup role, wants more playing time",
        };

        return [
            'type' => 'benched_reaction',
            'player_id' => (int) $player['id'],
            'player_name' => $name,
            'position' => $pos,
            'personality' => $personality,
            'message' => $message,
            'ticker' => $ticker,
        ];
    }

    // ── Low Morale / Holdout Threats ────────────────────────────────────

    private function processLowMoraleDemands(int $leagueId, int $week): array
    {
        // Find star players (80+ OVR) with very low morale (angry or frustrated)
        $stmt = $this->db->prepare(
            "SELECT p.id, p.first_name, p.last_name, p.position, p.overall_rating,
                    p.personality, p.morale, p.team_id
             FROM players p
             JOIN teams t ON t.id = p.team_id AND t.league_id = ?
             WHERE p.status = 'active' AND p.overall_rating >= 80
               AND p.morale IN ('angry', 'frustrated')"
        );
        $stmt->execute([$leagueId]);
        $unhappyStars = $stmt->fetchAll();

        $demands = [];

        foreach ($unhappyStars as $player) {
            $playerId = (int) $player['id'];

            if ($this->isOnCooldown($playerId, $week)) {
                continue;
            }

            // 25% chance per week when morale is critically low
            if (mt_rand(1, 100) > 25) {
                continue;
            }

            $personality = $player['personality'] ?? 'team_player';
            $playerName = trim($player['first_name'] . ' ' . $player['last_name']);
            $pos = $player['position'];
            $ovr = $player['overall_rating'];

            $message = match ($personality) {
                'diva' => "{$playerName} has gone public with his frustration. The {$ovr}-rated {$pos} posted on social media: \"When you give everything and get nothing back, what's the point?\" The organization is scrambling to contain the situation.",
                'intense' => "{$playerName} was absent from voluntary workouts this week. Sources close to the {$pos} say he's \"mentally checked out\" and considering his options. At {$ovr} overall, losing him would be devastating.",
                'mercenary' => "{$playerName}'s camp has informed the team that the {$pos} will not participate in team activities until his concerns are addressed. \"This is a business decision,\" his agent said.",
                default => "Concerns are mounting around {$playerName}'s state of mind. The {$ovr}-rated {$pos} has been quiet and withdrawn, and teammates are starting to notice. \"We need him locked in,\" the head coach said. \"We're working on it.\"",
            };

            $ticker = match ($personality) {
                'diva' => "UNHAPPY STAR: {$playerName} goes public with frustration, morale at an all-time low",
                'intense' => "CONCERN: {$playerName} skipping voluntary workouts amid growing frustration",
                'mercenary' => "HOLDOUT: {$playerName}'s agent threatens to withhold {$pos} from team activities",
                default => "MORALE: {$playerName} struggling with frustration, coaches working to resolve situation",
            };

            $demand = [
                'type' => 'low_morale',
                'player_id' => $playerId,
                'player_name' => $playerName,
                'position' => $pos,
                'personality' => $personality,
                'message' => $message,
                'ticker' => $ticker,
            ];

            $this->createDemandNotification($leagueId, (int) $player['team_id'], $demand);
            $this->createTickerItem($leagueId, $demand['ticker']);
            $this->recordDemand($playerId, $week, 'low_morale', $demand);

            $demands[] = $demand;
        }

        return $demands;
    }

    // ── Contract Year Performance Demands ────────────────────────────────

    private function processContractYearDemands(int $leagueId, int $seasonId, int $week): array
    {
        if ($week < 6) return []; // Wait until mid-season

        // Find players in their final contract year who are performing well
        $stmt = $this->db->prepare(
            "SELECT p.id, p.first_name, p.last_name, p.position, p.overall_rating,
                    p.personality, p.team_id,
                    c.salary_annual, c.years_remaining
             FROM players p
             JOIN teams t ON t.id = p.team_id AND t.league_id = ?
             JOIN contracts c ON c.player_id = p.id AND c.status = 'active'
             WHERE p.status = 'active' AND p.overall_rating >= 78
               AND c.years_remaining = 1"
        );
        $stmt->execute([$leagueId]);
        $contractYearPlayers = $stmt->fetchAll();

        $demands = [];

        foreach ($contractYearPlayers as $player) {
            $playerId = (int) $player['id'];
            $teamId = (int) $player['team_id'];

            if ($this->isOnCooldown($playerId, $week)) {
                continue;
            }

            // Must be a starter to have leverage for extension demands
            $starterCheck = $this->db->prepare(
                "SELECT 1 FROM depth_chart WHERE player_id = ? AND team_id = ? AND slot = 1 LIMIT 1"
            );
            $starterCheck->execute([$playerId, $teamId]);
            if (!$starterCheck->fetch()) continue;

            // Skip injured players
            $injCheck = $this->db->prepare(
                "SELECT 1 FROM injuries WHERE player_id = ? AND weeks_remaining > 0 LIMIT 1"
            );
            $injCheck->execute([$playerId]);
            if ($injCheck->fetch()) continue;

            // 15% chance per week after week 6
            if (mt_rand(1, 100) > 15) {
                continue;
            }

            // Check if they have good recent performance (avg grade >= B)
            $gradeStmt = $this->db->prepare(
                "SELECT AVG(CASE WHEN gs.grade LIKE 'A%' THEN 90 WHEN gs.grade LIKE 'B%' THEN 80
                 WHEN gs.grade LIKE 'C%' THEN 70 ELSE 60 END) as avg_grade
                 FROM game_stats gs JOIN games g ON g.id = gs.game_id
                 WHERE gs.player_id = ? AND g.season_id = ?"
            );
            $gradeStmt->execute([$playerId, $seasonId]);
            $avgGrade = (float) ($gradeStmt->fetchColumn() ?: 0);

            if ($avgGrade < 78) continue;

            $personality = $player['personality'] ?? 'team_player';
            $playerName = trim($player['first_name'] . ' ' . $player['last_name']);
            $pos = $player['position'];
            $currentSalary = (int) $player['salary_annual'];
            $marketValue = (new ContractEngine())->calculateMarketValue($player);
            $marketStr = $this->formatSalary($marketValue);

            $message = match ($personality) {
                'diva' => "{$playerName} has made it clear: he wants an extension before the season ends. \"I'm not playing for a discount,\" the {$pos} told reporters. \"Look at the tape, look at the numbers. Pay me what I'm worth or I walk.\" His camp is seeking {$marketStr} per year.",
                'mercenary' => "{$playerName}'s agent has initiated extension talks with the front office. The {$pos} is in the final year of his deal and testing the open market is a real possibility. Sources say he's seeking north of {$marketStr} annually.",
                'leader' => "{$playerName} loves the organization but business is business. With one year left, the veteran {$pos} has quietly let management know he'd like to discuss an extension. \"I want to be here, but I need to know the feeling is mutual,\" he said.",
                default => "{$playerName} is entering the final stretch of his contract and wants clarity. The {$pos}'s representative has reached out about extension talks ahead of free agency.",
            };

            $ticker = "CONTRACT: {$playerName} seeking extension — final year of deal, playing at a high level";

            $demand = [
                'type' => 'extension_request',
                'player_id' => $playerId,
                'player_name' => $playerName,
                'position' => $pos,
                'personality' => $personality,
                'message' => $message,
                'ticker' => $ticker,
                'current_salary' => $currentSalary,
                'market_value' => $marketValue,
            ];

            $this->createDemandNotification($leagueId, (int) $player['team_id'], $demand);
            $this->createTickerItem($leagueId, $demand['ticker']);
            $this->recordDemand($playerId, $week, 'extension_request', $demand);

            $demands[] = $demand;
        }

        return $demands;
    }

    // ── Escalation: Unresolved demands get worse ─────────────────────────

    private function escalateUnresolvedDemands(int $leagueId, int $currentWeek): void
    {
        // Find active demand arcs that are 2+ weeks old and still unresolved
        $stmt = $this->db->prepare(
            "SELECT na.*, p.first_name, p.last_name, p.position, p.morale, p.personality, p.team_id, p.overall_rating
             FROM narrative_arcs na
             JOIN players p ON p.id = na.player_id
             WHERE na.league_id = ? AND na.status = 'active'
               AND na.type IN ('breakout_player')
               AND na.player_id IS NOT NULL
               AND na.started_week <= ?"
        );
        $stmt->execute([$leagueId, $currentWeek - 2]);
        $activeArcs = $stmt->fetchAll();

        foreach ($activeArcs as $arc) {
            $playerId = (int) $arc['player_id'];
            $playerName = trim($arc['first_name'] . ' ' . $arc['last_name']);
            $personality = $arc['personality'] ?? 'team_player';
            $morale = $arc['morale'] ?? 'content';
            $weeksUnresolved = $currentWeek - (int) $arc['started_week'];

            // Check if the player got a new contract since the arc started
            $contractCheck = $this->db->prepare(
                "SELECT id FROM contracts WHERE player_id = ? AND status = 'active' AND signed_at > ?
                 ORDER BY id DESC LIMIT 1"
            );
            $contractCheck->execute([$playerId, date('Y-m-d', strtotime("-{$weeksUnresolved} weeks"))]);
            if ($contractCheck->fetch()) {
                // Demand was addressed — resolve the arc, boost morale
                $this->db->prepare("UPDATE narrative_arcs SET status = 'resolved', resolved_week = ? WHERE id = ?")
                    ->execute([$currentWeek, $arc['id']]);
                $this->adjustMorale($playerId, 2); // relief boost
                continue;
            }

            // Still unresolved — escalate
            // Drop morale by 1 step each week the demand goes unanswered
            $this->adjustMorale($playerId, -1);

            // Every 3 weeks, generate escalation ticker
            if ($weeksUnresolved % 3 === 0) {
                $escalationMsg = match ($personality) {
                    'diva' => "HOLDOUT THREAT: {$playerName} says he will \"sit out\" if contract situation isn't addressed",
                    'mercenary' => "CONTRACT: {$playerName}'s agent warns team they're \"running out of time\" on extension talks",
                    'intense' => "FRUSTRATION: {$playerName} visibly upset in practice, teammates concerned",
                    default => "CONTRACT: {$playerName} growing increasingly frustrated with contract situation",
                };

                $this->createTickerItem($leagueId, $escalationMsg);

                if ($arc['team_id']) {
                    $this->createDemandNotification($leagueId, (int) $arc['team_id'], [
                        'type' => 'escalation',
                        'player_id' => $playerId,
                        'player_name' => $playerName,
                        'position' => $arc['position'],
                        'personality' => $personality,
                        'message' => $escalationMsg,
                        'ticker' => $escalationMsg,
                    ]);
                }
            }
        }
    }

    // ── Holdout Processing (called during preseason) ────────────────────

    /**
     * Check for players holding out of training camp.
     * Called when the league enters preseason phase.
     * Players who are angry + in final contract year may refuse to practice.
     */
    public function processHoldouts(int $leagueId): array
    {
        $holdouts = [];

        // Find angry players in their final contract year with 80+ OVR
        $stmt = $this->db->prepare(
            "SELECT p.id, p.first_name, p.last_name, p.position, p.overall_rating,
                    p.personality, p.morale, p.team_id, c.years_remaining, c.salary_annual
             FROM players p
             JOIN teams t ON t.id = p.team_id AND t.league_id = ?
             JOIN contracts c ON c.player_id = p.id AND c.status = 'active'
             WHERE p.status = 'active' AND p.overall_rating >= 80
               AND p.morale = 'angry' AND c.years_remaining <= 1"
        );
        $stmt->execute([$leagueId]);
        $candidates = $stmt->fetchAll();

        foreach ($candidates as $player) {
            $playerId = (int) $player['id'];
            $playerName = trim($player['first_name'] . ' ' . $player['last_name']);
            $pos = $player['position'];
            $personality = $player['personality'] ?? 'team_player';
            $ovr = (int) $player['overall_rating'];

            // Holdout probability based on personality
            $holdoutChance = match ($personality) {
                'diva' => 70,
                'mercenary' => 55,
                'intense' => 35,
                'leader' => 10,
                'quiet' => 15,
                default => 20,
            };

            if (mt_rand(1, 100) > $holdoutChance) {
                continue;
            }

            // Mark player as holding out
            $this->db->prepare("UPDATE players SET status = 'holdout' WHERE id = ?")
                ->execute([$playerId]);

            $message = match ($personality) {
                'diva' => "{$playerName} is officially holding out. The {$ovr}-rated {$pos} did not report to training camp and has no plans to until he gets a new contract. \"I've made my position very clear,\" he said on social media.",
                'mercenary' => "{$playerName} has not reported to training camp. His agent confirmed the {$pos} will not participate in team activities until a new contract is in place. \"This is strictly business.\"",
                'intense' => "{$playerName} is holding out of camp. The fiery {$pos} was seen working out privately away from the team facility. Sources say he feels \"disrespected\" by the organization's unwillingness to negotiate.",
                default => "{$playerName} has not reported to training camp amid a contract dispute. The {$ovr}-rated {$pos} is seeking a new deal before returning to the team.",
            };

            $ticker = "HOLDOUT: {$playerName} ({$pos}, {$ovr} OVR) not reporting to camp — wants new contract";

            $this->createTickerItem($leagueId, $ticker);
            if ($player['team_id']) {
                $this->createDemandNotification($leagueId, (int) $player['team_id'], [
                    'type' => 'holdout',
                    'player_id' => $playerId,
                    'player_name' => $playerName,
                    'position' => $pos,
                    'personality' => $personality,
                    'message' => $message,
                    'ticker' => $ticker,
                ]);
            }

            $holdouts[] = [
                'player_id' => $playerId,
                'player_name' => $playerName,
                'position' => $pos,
                'overall_rating' => $ovr,
                'message' => $message,
            ];
        }

        return $holdouts;
    }

    /**
     * End a holdout when a player signs a new contract.
     * Called from the contract extension/signing flow.
     */
    public static function resolveHoldout(int $playerId): void
    {
        $db = \App\Database\Connection::getInstance()->getPdo();

        // If player is in holdout status, return them to active
        $stmt = $db->prepare("SELECT status, morale FROM players WHERE id = ?");
        $stmt->execute([$playerId]);
        $player = $stmt->fetch();

        if ($player && $player['status'] === 'holdout') {
            $db->prepare("UPDATE players SET status = 'active', morale = 'content' WHERE id = ?")
                ->execute([$playerId]);
        }

        // Resolve any active demand arcs
        $db->prepare(
            "UPDATE narrative_arcs SET status = 'resolved', resolved_week = -1
             WHERE player_id = ? AND status = 'active' AND type IN ('breakout_player', 'demand_breakout_contract', 'demand_extension_request')"
        )->execute([$playerId]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function loadPlayer(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM players WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    private function getActiveContract(int $playerId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM contracts WHERE player_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$playerId]);
        return $stmt->fetch() ?: null;
    }

    private function isOnCooldown(int $playerId, int $currentWeek): bool
    {
        $stmt = $this->db->prepare(
            "SELECT MAX(CAST(json_extract(data, '$.week') AS INTEGER)) as last_week
             FROM narrative_arcs
             WHERE player_id = ? AND type LIKE 'demand_%'"
        );
        $stmt->execute([$playerId]);
        $row = $stmt->fetch();

        if (!$row || !$row['last_week']) return false;

        return ($currentWeek - (int) $row['last_week']) < self::DEMAND_COOLDOWN_WEEKS;
    }

    private function recordDemand(int $playerId, int $week, string $type, array $demand): void
    {
        // Store as a narrative arc so the cooldown system can track it
        $this->db->prepare(
            "INSERT INTO narrative_arcs (league_id, season_id, type, title, description, status,
             player_id, team_id, started_week, data)
             SELECT t.league_id, COALESCE(
                 (SELECT id FROM seasons WHERE league_id = t.league_id AND is_current = 1 LIMIT 1), 0
             ), ?, ?, ?, 'resolved', ?, p.team_id, ?, ?
             FROM players p JOIN teams t ON t.id = p.team_id WHERE p.id = ?"
        )->execute([
            'demand_' . $type,
            $demand['ticker'] ?? $demand['player_name'] . ' demand',
            $demand['message'] ?? '',
            $playerId, $week,
            json_encode(['week' => $week, 'type' => $type, 'personality' => $demand['personality'] ?? null]),
            $playerId,
        ]);
    }

    private function adjustMorale(int $playerId, int $steps): void
    {
        $scale = ['angry', 'frustrated', 'content', 'happy', 'ecstatic'];
        $stmt = $this->db->prepare("SELECT morale FROM players WHERE id = ?");
        $stmt->execute([$playerId]);
        $current = $stmt->fetchColumn() ?: 'content';

        $idx = array_search($current, $scale);
        if ($idx === false) $idx = 2;

        $newIdx = max(0, min(4, $idx + $steps));
        $newMorale = $scale[$newIdx];

        $this->db->prepare("UPDATE players SET morale = ? WHERE id = ?")
            ->execute([$newMorale, $playerId]);
    }

    private function createDemandNotification(int $leagueId, int $teamId, array $demand): void
    {
        // Only notify human coaches (find users who coach this team)
        $stmt = $this->db->prepare(
            "SELECT u.id FROM users u
             JOIN coaches c ON c.user_id = u.id
             WHERE c.team_id = ? AND c.is_human = 1"
        );
        $stmt->execute([$teamId]);
        $users = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $now = date('Y-m-d H:i:s');
        $title = match ($demand['type']) {
            'contract_demand' => 'Contract Demand',
            'benched_reaction' => 'Player Unhappy',
            'low_morale' => 'Morale Crisis',
            'extension_request' => 'Extension Request',
            default => 'Player Demand',
        };

        foreach ($users as $userId) {
            $this->db->prepare(
                "INSERT INTO notifications (user_id, league_id, type, title, body, data, created_at)
                 VALUES (?, ?, 'player_demand', ?, ?, ?, ?)"
            )->execute([
                $userId, $leagueId, $title, $demand['message'],
                json_encode($demand), $now,
            ]);
        }
    }

    private function createTickerItem(int $leagueId, string $message): void
    {
        $now = date('Y-m-d H:i:s');
        try {
            $this->db->prepare(
                "INSERT INTO ticker_items (league_id, type, message, created_at) VALUES (?, 'player_demand', ?, ?)"
            )->execute([$leagueId, $message, $now]);
        } catch (\Throwable $e) {
            // Non-critical
        }
    }

    private function formatSalary(int $amount): string
    {
        if ($amount >= 1000000) return '$' . number_format($amount / 1000000, 1) . 'M';
        if ($amount >= 1000) return '$' . number_format($amount / 1000, 0) . 'K';
        return '$' . number_format($amount);
    }
}
