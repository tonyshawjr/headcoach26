<?php

namespace App\Services;

use App\Database\Connection;

/**
 * WeeklyLeagueEngine — the "brain" that runs every time the league advances a week.
 *
 * Generates AI trades, roster moves, free agency activity, and ticker items
 * so the league feels alive beyond just game simulation.
 */
class WeeklyLeagueEngine
{
    private \PDO $db;

    // Ideal roster depth by position (mirrors TradeEngine)
    private array $idealDepth = [
        'QB' => 2, 'RB' => 3, 'WR' => 5, 'TE' => 3,
        'OT' => 4, 'OG' => 4, 'C' => 2,
        'DE' => 4, 'DT' => 4, 'LB' => 4, 'CB' => 5, 'S' => 4,
        'K' => 1, 'P' => 1, 'LS' => 1,
    ];

    // Minimum roster requirements by position
    private array $minimumDepth = [
        'QB' => 2, 'RB' => 2, 'WR' => 3, 'TE' => 2,
        'OT' => 2, 'OG' => 2, 'C' => 1,
        'DE' => 2, 'DT' => 2, 'LB' => 2, 'CB' => 2, 'S' => 2,
        'K' => 1, 'P' => 1, 'LS' => 1,
    ];

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
    }

    // ────────────────────────────────────────────────────────────────
    // Main entry point
    // ────────────────────────────────────────────────────────────────

    /**
     * Process all weekly AI activity for a league.
     * Returns a summary array with counts and ticker items generated.
     */
    public function processWeek(int $leagueId, int $week): array
    {
        // Clear old ticker items — each week starts fresh
        $this->db->prepare("DELETE FROM ticker_items WHERE league_id = ?")->execute([$leagueId]);

        // Idempotency check — skip if we already processed this week
        if ($this->weekAlreadyProcessed($leagueId, $week)) {
            return [
                'skipped' => true,
                'reason' => "Week {$week} already processed",
                'trades' => 0,
                'ir_moves' => 0,
                'signings' => 0,
                'releases' => 0,
                'ticker_items' => 0,
            ];
        }

        $summary = [
            'trades' => 0,
            'ir_moves' => 0,
            'signings' => 0,
            'releases' => 0,
            'ticker_items' => 0,
        ];

        // 1. AI-to-AI trades
        $tradeResult = $this->processAiTrades($leagueId, $week);
        $summary['trades'] = $tradeResult['count'];
        $summary['ticker_items'] += $tradeResult['ticker_count'];

        // 2. Injury reserve management
        $irResult = $this->processInjuryReserve($leagueId);
        $summary['ir_moves'] = $irResult['count'];
        $summary['ticker_items'] += $irResult['ticker_count'];

        // 3. Free agency (signings and cuts)
        $faResult = $this->processFreeAgency($leagueId);
        $summary['signings'] = $faResult['signings'];
        $summary['releases'] = $faResult['releases'];
        $summary['ticker_items'] += $faResult['ticker_count'];

        return $summary;
    }

    // ────────────────────────────────────────────────────────────────
    // 1. AI-to-AI Trades
    // ────────────────────────────────────────────────────────────────

    /**
     * Generate 1-3 AI-to-AI trades per week across the league.
     * Respects the trade deadline — no AI trades after the deadline week.
     */
    public function processAiTrades(int $leagueId, int $week): array
    {
        $result = ['count' => 0, 'ticker_count' => 0, 'trades' => []];

        // Check commissioner settings: AI trades enabled + trade deadline
        $commish = new CommissionerService();
        $settings = $commish->getSettings($leagueId);

        if (!(int) ($settings['allow_ai_trades'] ?? 1)) {
            return $result;
        }

        $deadlineWeek = (int) ($settings['trade_deadline_week'] ?? 12);
        if ($week > $deadlineWeek) {
            return $result;
        }

        $maxTrades = mt_rand(1, 3);
        $humanTeamId = $this->getHumanTeamId($leagueId);
        $aiTeams = $this->getAiTeams($leagueId);

        if (count($aiTeams) < 2) {
            return $result;
        }

        // Shuffle so different teams get chances each week
        shuffle($aiTeams);

        $teamsInvolvedThisWeek = [];

        foreach ($aiTeams as $team) {
            if ($result['count'] >= $maxTrades) break;

            $teamId = (int) $team['team_id'];

            // Skip if this team is already involved in a trade this week
            if (in_array($teamId, $teamsInvolvedThisWeek)) continue;

            // Skip human player's team
            if ($teamId === $humanTeamId) continue;

            // Find roster needs for this team
            $needs = $this->evaluateRosterNeeds($teamId);
            if (empty($needs)) continue;

            // Try to find a trade partner
            foreach ($needs as $need) {
                $position = $need['position'];

                // Find another AI team with surplus at this position
                $partner = $this->findTradePartner(
                    $aiTeams, $teamId, $position, $humanTeamId, $teamsInvolvedThisWeek
                );

                if (!$partner) continue;

                $partnerId = (int) $partner['team_id'];

                // Find the player to acquire (surplus player from partner)
                $targetPlayer = $this->findSurplusPlayer($partnerId, $position);
                if (!$targetPlayer) continue;

                // Find a player to send back (not the team's best, something fair)
                $sendPlayer = $this->findPlayerToSend($teamId, $targetPlayer);
                if (!$sendPlayer) continue;

                // Check fairness using TradeEngine evaluation
                $tradeEngine = new TradeEngine();
                $targetValue = $tradeEngine->evaluatePlayer((int) $targetPlayer['id']);
                $sendValue = $tradeEngine->evaluatePlayer((int) $sendPlayer['id']);

                // 30% chance to add a draft pick sweetener
                $draftPickId = null;
                $draftPickLabel = null;
                if (mt_rand(1, 100) <= 30) {
                    $pickInfo = $this->findAvailableDraftPick($leagueId, $teamId);
                    if ($pickInfo) {
                        $draftPickId = (int) $pickInfo['id'];
                        $draftPickLabel = "Round {$pickInfo['round']} Pick";
                        // Add ~10-30 points of value for the pick
                        $pickValueBonus = (8 - min(7, (int) $pickInfo['round'])) * 5;
                        $sendValue += $pickValueBonus;
                    }
                }

                // Require the trade to be roughly fair (within 35% of each other)
                if ($sendValue <= 0 || $targetValue <= 0) continue;
                $fairness = min($sendValue, $targetValue) / max($sendValue, $targetValue);
                if ($fairness < 0.65) continue;

                // Don't trade away the team's best player at any position
                if ($this->isTeamsBestAtPosition($teamId, (int) $sendPlayer['id'])) continue;
                if ($this->isTeamsBestAtPosition($partnerId, (int) $targetPlayer['id'])) continue;

                // Execute the trade
                $tradeId = $this->executeTrade(
                    $leagueId, $teamId, $partnerId,
                    (int) $sendPlayer['id'], (int) $targetPlayer['id'],
                    $draftPickId, $week
                );

                if ($tradeId) {
                    $teamsInvolvedThisWeek[] = $teamId;
                    $teamsInvolvedThisWeek[] = $partnerId;

                    // Build what was given up description
                    $spId = (int) $sendPlayer['id'];
                    $tpId = (int) $targetPlayer['id'];
                    $gaveUp = "{$sendPlayer['position']} [player:{$spId}:{$sendPlayer['first_name']} {$sendPlayer['last_name']}]";
                    if ($draftPickLabel) {
                        $gaveUp .= " + {$draftPickLabel}";
                    }

                    $tickerText = "TRADE: [team:{$teamId}:{$team['city']}] acquires {$targetPlayer['position']} [player:{$tpId}:{$targetPlayer['first_name']} {$targetPlayer['last_name']}] from [team:{$partnerId}:{$partner['city']}] for {$gaveUp}";

                    $this->generateTickerItem($this->db, $leagueId, $tickerText, $week);
                    $result['count']++;
                    $result['ticker_count']++;
                    $result['trades'][] = $tickerText;

                    break; // Move to the next team
                }
            }
        }

        return $result;
    }

    // ────────────────────────────────────────────────────────────────
    // 2. Injury Reserve Management
    // ────────────────────────────────────────────────────────────────

    /**
     * AI teams manage their injured reserve:
     * - Place seriously injured players on IR
     * - Activate recovered players from IR
     */
    public function processInjuryReserve(int $leagueId): array
    {
        $result = ['count' => 0, 'ticker_count' => 0, 'moves' => []];

        $humanTeamId = $this->getHumanTeamId($leagueId);

        // Find AI team players with serious injuries (>= 3 weeks) who are still active
        $stmt = $this->db->prepare(
            "SELECT i.*, p.first_name, p.last_name, p.position, p.status as player_status,
                    t.city, t.name as team_name
             FROM injuries i
             JOIN players p ON i.player_id = p.id
             JOIN teams t ON i.team_id = t.id
             WHERE t.league_id = ?
               AND i.weeks_remaining >= 3
               AND p.status = 'active'
               AND i.team_id != ?"
        );
        $stmt->execute([$leagueId, $humanTeamId ?? 0]);
        $toIR = $stmt->fetchAll();

        foreach ($toIR as $injury) {
            // Move player to injured reserve
            $this->db->prepare("UPDATE players SET status = 'injured_reserve' WHERE id = ?")
                ->execute([$injury['player_id']]);

            $weeksLabel = (int) $injury['weeks_remaining'] >= 16 ? 'out for season' : "{$injury['weeks_remaining']} weeks";
            $tickerText = "ROSTER: [team:{$injury['team_id']}:{$injury['city']}] places {$injury['position']} [player:{$injury['player_id']}:{$injury['first_name']} {$injury['last_name']}] on injured reserve ({$injury['type']}, {$weeksLabel})";
            $this->generateTickerItem($this->db, $leagueId, $tickerText);
            $result['count']++;
            $result['ticker_count']++;
            $result['moves'][] = $tickerText;
        }

        // Find AI team players on IR whose injuries have healed (weeks_remaining = 0)
        $stmt = $this->db->prepare(
            "SELECT i.*, p.first_name, p.last_name, p.position, p.status as player_status,
                    t.city, t.name as team_name
             FROM injuries i
             JOIN players p ON i.player_id = p.id
             JOIN teams t ON i.team_id = t.id
             WHERE t.league_id = ?
               AND i.weeks_remaining = 0
               AND p.status = 'injured_reserve'
               AND i.team_id != ?"
        );
        $stmt->execute([$leagueId, $humanTeamId ?? 0]);
        $fromIR = $stmt->fetchAll();

        foreach ($fromIR as $injury) {
            // Activate player from injured reserve
            $this->db->prepare("UPDATE players SET status = 'active' WHERE id = ?")
                ->execute([$injury['player_id']]);

            $tickerText = "ROSTER: [team:{$injury['team_id']}:{$injury['city']}] activates {$injury['position']} [player:{$injury['player_id']}:{$injury['first_name']} {$injury['last_name']}] from injured reserve";
            $this->generateTickerItem($this->db, $leagueId, $tickerText);
            $result['count']++;
            $result['ticker_count']++;
            $result['moves'][] = $tickerText;
        }

        return $result;
    }

    // ────────────────────────────────────────────────────────────────
    // 3. Free Agency (Signings + Cuts)
    // ────────────────────────────────────────────────────────────────

    /**
     * AI teams sign free agents to fill critical needs and cut underperformers.
     */
    public function processFreeAgency(int $leagueId): array
    {
        $result = ['signings' => 0, 'releases' => 0, 'ticker_count' => 0, 'moves' => []];

        $humanTeamId = $this->getHumanTeamId($leagueId);
        $aiTeams = $this->getAiTeams($leagueId);

        $totalSignings = 0;
        $totalReleases = 0;
        $maxSignings = 5;
        $maxReleases = 3;

        shuffle($aiTeams);

        foreach ($aiTeams as $team) {
            $teamId = (int) $team['team_id'];
            if ($teamId === $humanTeamId) continue;

            // ── Signings: fill critical position needs ──
            if ($totalSignings < $maxSignings) {
                $roster = $this->getTeamRosterByPosition($teamId);

                foreach ($this->minimumDepth as $position => $minimum) {
                    if ($totalSignings >= $maxSignings) break;

                    $currentCount = $roster[$position] ?? 0;
                    if ($currentCount >= $minimum) continue;

                    // Find the best available free agent at this position
                    $bestFA = $this->findBestFreeAgent($leagueId, $position, $teamId);
                    if (!$bestFA) continue;

                    // Don't sign someone worse than what we have (if we have anyone)
                    if ($currentCount > 0) {
                        $teamBestOvr = $this->getTeamBestOvrAtPosition($teamId, $position);
                        if ((int) $bestFA['overall_rating'] < $teamBestOvr - 20) continue;
                    }

                    // Sign the free agent
                    $this->signFreeAgent($leagueId, $teamId, $bestFA);

                    $faPlayerId = (int) $bestFA['player_id'];
                    $tickerText = "SIGNING: [team:{$teamId}:{$team['city']}] signs free agent {$bestFA['position']} [player:{$faPlayerId}:{$bestFA['first_name']} {$bestFA['last_name']}]";
                    $this->generateTickerItem($this->db, $leagueId, $tickerText);
                    $totalSignings++;
                    $result['ticker_count']++;
                    $result['moves'][] = $tickerText;
                }
            }

            // ── Cuts: release underperformers (max 1 per team per week) ──
            if ($totalReleases < $maxReleases) {
                $cutPlayer = $this->findPlayerToCut($teamId);
                if ($cutPlayer) {
                    $faEngine = new FreeAgencyEngine();
                    $faEngine->releasePlayer($leagueId, (int) $cutPlayer['id']);

                    $cutPlayerId = (int) $cutPlayer['id'];
                    $tickerText = "RELEASED: [team:{$teamId}:{$team['city']}] releases {$cutPlayer['position']} [player:{$cutPlayerId}:{$cutPlayer['first_name']} {$cutPlayer['last_name']}]";
                    $this->generateTickerItem($this->db, $leagueId, $tickerText);
                    $totalReleases++;
                    $result['ticker_count']++;
                    $result['moves'][] = $tickerText;
                }
            }
        }

        $result['signings'] = $totalSignings;
        $result['releases'] = $totalReleases;

        return $result;
    }

    // ────────────────────────────────────────────────────────────────
    // 4. Ticker Helper
    // ────────────────────────────────────────────────────────────────

    /**
     * Insert a ticker item into the ticker_items table.
     */
    public function generateTickerItem(\PDO $pdo, int $leagueId, string $text, ?int $week = null): void
    {
        $pdo->prepare(
            "INSERT INTO ticker_items (league_id, text, type, week, created_at)
             VALUES (?, ?, 'transaction', ?, ?)"
        )->execute([$leagueId, $text, $week, date('Y-m-d H:i:s')]);
    }

    // ────────────────────────────────────────────────────────────────
    // Private helpers
    // ────────────────────────────────────────────────────────────────

    /**
     * Check if this week was already processed (idempotency).
     * We look for any ticker items of type 'transaction' for this week.
     */
    private function weekAlreadyProcessed(int $leagueId, int $week): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM ticker_items
             WHERE league_id = ? AND week = ? AND type = 'transaction'"
        );
        $stmt->execute([$leagueId, $week]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Get the human player's team ID for this league.
     */
    private function getHumanTeamId(int $leagueId): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT team_id FROM coaches WHERE league_id = ? AND is_human = 1 LIMIT 1"
        );
        $stmt->execute([$leagueId]);
        $row = $stmt->fetch();
        return $row ? (int) $row['team_id'] : null;
    }

    /**
     * Get all AI-coached teams with team info.
     */
    private function getAiTeams(int $leagueId): array
    {
        $stmt = $this->db->prepare(
            "SELECT c.id as coach_id, c.team_id, c.archetype,
                    t.city, t.name as team_name, t.abbreviation,
                    t.salary_cap, t.cap_used, t.overall_rating
             FROM coaches c
             JOIN teams t ON c.team_id = t.id
             WHERE c.league_id = ? AND c.is_human = 0 AND c.team_id IS NOT NULL"
        );
        $stmt->execute([$leagueId]);
        return $stmt->fetchAll();
    }

    /**
     * Evaluate a team's roster needs: positions where depth < ideal or best OVR < 73.
     */
    private function evaluateRosterNeeds(int $teamId): array
    {
        $stmt = $this->db->prepare(
            "SELECT position, COUNT(*) as cnt, MAX(overall_rating) as best_ovr
             FROM players
             WHERE team_id = ? AND status IN ('active', 'injured_reserve')
             GROUP BY position"
        );
        $stmt->execute([$teamId]);
        $roster = $stmt->fetchAll();

        $rosterMap = [];
        foreach ($roster as $row) {
            $rosterMap[$row['position']] = [
                'count' => (int) $row['cnt'],
                'best_ovr' => (int) $row['best_ovr'],
            ];
        }

        $needs = [];
        foreach ($this->idealDepth as $position => $ideal) {
            $count = $rosterMap[$position]['count'] ?? 0;
            $bestOvr = $rosterMap[$position]['best_ovr'] ?? 0;

            if ($count < $ideal || $bestOvr < 73) {
                $needs[] = [
                    'position' => $position,
                    'count' => $count,
                    'ideal' => $ideal,
                    'best_ovr' => $bestOvr,
                    'urgency' => ($ideal - $count) + max(0, 73 - $bestOvr),
                ];
            }
        }

        // Sort by urgency descending
        usort($needs, fn($a, $b) => $b['urgency'] <=> $a['urgency']);

        return $needs;
    }

    /**
     * Find an AI trade partner with surplus at a given position.
     */
    private function findTradePartner(
        array $aiTeams, int $excludeTeamId, string $position,
        ?int $humanTeamId, array $excludeTeams
    ): ?array {
        foreach ($aiTeams as $team) {
            $partnerId = (int) $team['team_id'];
            if ($partnerId === $excludeTeamId) continue;
            if ($partnerId === $humanTeamId) continue;
            if (in_array($partnerId, $excludeTeams)) continue;

            $ideal = $this->idealDepth[$position] ?? 2;

            // Check if this team has surplus at the position
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM players
                 WHERE team_id = ? AND position = ? AND status IN ('active', 'injured_reserve')"
            );
            $stmt->execute([$partnerId, $position]);
            $count = (int) $stmt->fetchColumn();

            if ($count > $ideal) {
                return $team;
            }
        }

        return null;
    }

    /**
     * Find a surplus player from a team at a position (not the best, OVR > 70).
     */
    private function findSurplusPlayer(int $teamId, string $position): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM players
             WHERE team_id = ? AND position = ? AND status = 'active' AND overall_rating > 70
             ORDER BY overall_rating ASC
             LIMIT 1"
        );
        $stmt->execute([$teamId, $position]);
        $player = $stmt->fetch();
        return $player ?: null;
    }

    /**
     * Find a fair player to send in a trade (similar value, not the team's best overall).
     */
    private function findPlayerToSend(int $teamId, array $targetPlayer): ?array
    {
        $targetOvr = (int) $targetPlayer['overall_rating'];
        $ovrFloor = max(60, $targetOvr - 8);
        $ovrCeiling = $targetOvr + 8;

        // Find a player in a similar OVR range that the team can part with
        // Exclude their best player at each position
        $stmt = $this->db->prepare(
            "SELECT p.* FROM players p
             WHERE p.team_id = ? AND p.status = 'active'
               AND p.overall_rating BETWEEN ? AND ?
               AND p.position != ?
             ORDER BY ABS(p.overall_rating - ?) ASC
             LIMIT 5"
        );
        $stmt->execute([$teamId, $ovrFloor, $ovrCeiling, $targetPlayer['position'], $targetOvr]);
        $candidates = $stmt->fetchAll();

        foreach ($candidates as $candidate) {
            // Don't send someone if it leaves the team below minimum depth
            $posCount = $this->getPositionCount($teamId, $candidate['position']);
            $minRequired = $this->minimumDepth[$candidate['position']] ?? 1;
            if ($posCount <= $minRequired) continue;

            // Don't send the team's best at that position
            if ($this->isTeamsBestAtPosition($teamId, (int) $candidate['id'])) continue;

            return $candidate;
        }

        return null;
    }

    /**
     * Check if a player is the best at their position on their team.
     */
    private function isTeamsBestAtPosition(int $teamId, int $playerId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT p2.id FROM players p
             JOIN players p2 ON p2.team_id = p.team_id AND p2.position = p.position
             WHERE p.id = ? AND p2.status IN ('active', 'injured_reserve')
             ORDER BY p2.overall_rating DESC
             LIMIT 1"
        );
        $stmt->execute([$playerId]);
        $bestId = $stmt->fetchColumn();
        return (int) $bestId === $playerId;
    }

    /**
     * Count how many players a team has at a position.
     */
    private function getPositionCount(int $teamId, string $position): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM players
             WHERE team_id = ? AND position = ? AND status IN ('active', 'injured_reserve')"
        );
        $stmt->execute([$teamId, $position]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Find an available draft pick owned by the team.
     */
    private function findAvailableDraftPick(int $leagueId, int $teamId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT dp.* FROM draft_picks dp
             WHERE dp.league_id = ? AND dp.current_team_id = ? AND dp.is_used = 0
             ORDER BY dp.round DESC
             LIMIT 1"
        );
        $stmt->execute([$leagueId, $teamId]);
        $pick = $stmt->fetch();
        return $pick ?: null;
    }

    /**
     * Execute a trade: create trade record, trade items, swap players, transfer pick.
     */
    private function executeTrade(
        int $leagueId, int $teamAId, int $teamBId,
        int $sendPlayerId, int $receivePlayerId,
        ?int $draftPickId, int $week
    ): ?int {
        try {
            $this->db->beginTransaction();

            $now = date('Y-m-d H:i:s');

            // Create trade record
            $this->db->prepare(
                "INSERT INTO trades (league_id, proposing_team_id, receiving_team_id, status, proposed_at, resolved_at)
                 VALUES (?, ?, ?, 'completed', ?, ?)"
            )->execute([$leagueId, $teamAId, $teamBId, $now, $now]);

            $tradeId = (int) $this->db->lastInsertId();

            // Trade items: team A sends player
            $this->db->prepare(
                "INSERT INTO trade_items (trade_id, direction, item_type, player_id, draft_pick_id)
                 VALUES (?, 'outgoing', 'player', ?, NULL)"
            )->execute([$tradeId, $sendPlayerId]);

            // Trade items: team A sends draft pick (if applicable)
            if ($draftPickId) {
                $this->db->prepare(
                    "INSERT INTO trade_items (trade_id, direction, item_type, player_id, draft_pick_id)
                     VALUES (?, 'outgoing', 'draft_pick', NULL, ?)"
                )->execute([$tradeId, $draftPickId]);
            }

            // Trade items: team B sends player
            $this->db->prepare(
                "INSERT INTO trade_items (trade_id, direction, item_type, player_id, draft_pick_id)
                 VALUES (?, 'incoming', 'player', ?, NULL)"
            )->execute([$tradeId, $receivePlayerId]);

            // Swap player team assignments
            $this->db->prepare("UPDATE players SET team_id = ? WHERE id = ?")
                ->execute([$teamBId, $sendPlayerId]);
            $this->db->prepare("UPDATE players SET team_id = ? WHERE id = ?")
                ->execute([$teamAId, $receivePlayerId]);

            // Remove traded players from depth charts
            $this->db->prepare("DELETE FROM depth_chart WHERE player_id = ?")
                ->execute([$sendPlayerId]);
            $this->db->prepare("DELETE FROM depth_chart WHERE player_id = ?")
                ->execute([$receivePlayerId]);

            // Transfer draft pick ownership if applicable
            if ($draftPickId) {
                $this->db->prepare("UPDATE draft_picks SET current_team_id = ? WHERE id = ?")
                    ->execute([$teamBId, $draftPickId]);
            }

            $this->db->commit();
            return $tradeId;

        } catch (\Throwable $e) {
            $this->db->rollBack();
            error_log("WeeklyLeagueEngine trade execution error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get a team's roster grouped by position with counts.
     */
    private function getTeamRosterByPosition(int $teamId): array
    {
        $stmt = $this->db->prepare(
            "SELECT position, COUNT(*) as cnt
             FROM players
             WHERE team_id = ? AND status IN ('active', 'injured_reserve')
             GROUP BY position"
        );
        $stmt->execute([$teamId]);
        $rows = $stmt->fetchAll();

        $map = [];
        foreach ($rows as $row) {
            $map[$row['position']] = (int) $row['cnt'];
        }
        return $map;
    }

    /**
     * Get the best OVR at a position for a team.
     */
    private function getTeamBestOvrAtPosition(int $teamId, string $position): int
    {
        $stmt = $this->db->prepare(
            "SELECT MAX(overall_rating) FROM players
             WHERE team_id = ? AND position = ? AND status IN ('active', 'injured_reserve')"
        );
        $stmt->execute([$teamId, $position]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Find the best available free agent at a position.
     */
    private function findBestFreeAgent(int $leagueId, string $position, int $teamId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT fa.id as fa_id, fa.market_value, p.*
             FROM free_agents fa
             JOIN players p ON fa.player_id = p.id
             WHERE fa.league_id = ? AND fa.status = 'available' AND p.position = ?
             ORDER BY p.overall_rating DESC
             LIMIT 1"
        );
        $stmt->execute([$leagueId, $position]);
        $fa = $stmt->fetch();
        return $fa ?: null;
    }

    /**
     * Sign a free agent to a team with a 1-year contract.
     */
    private function signFreeAgent(int $leagueId, int $teamId, array $fa): void
    {
        $playerId = (int) $fa['id'];
        $marketValue = (int) ($fa['market_value'] ?? 500000);
        $now = date('Y-m-d H:i:s');

        // Update player: assign to team, set active
        $this->db->prepare("UPDATE players SET team_id = ?, status = 'active' WHERE id = ?")
            ->execute([$teamId, $playerId]);

        // Mark free agent listing as signed
        $this->db->prepare("UPDATE free_agents SET status = 'signed', signed_at = ? WHERE id = ?")
            ->execute([$now, (int) $fa['fa_id']]);

        // Create a 1-year contract (using the column pattern from FreeAgencyEngine)
        $this->db->prepare(
            "INSERT INTO contracts (player_id, team_id, total_value, salary_annual, cap_hit, years_total, years_remaining, status, signed_at)
             VALUES (?, ?, ?, ?, ?, 1, 1, 'active', ?)"
        )->execute([$playerId, $teamId, $marketValue, $marketValue, $marketValue, $now]);

        // Update team cap usage
        $this->db->prepare("UPDATE teams SET cap_used = cap_used + ? WHERE id = ?")
            ->execute([$marketValue, $teamId]);
    }

    /**
     * Find a player to cut: OVR significantly below team average (OVR < avg - 15).
     * Returns at most 1 player per team. Skips QBs if team only has 2.
     */
    private function findPlayerToCut(int $teamId): ?array
    {
        // Calculate team average OVR
        $stmt = $this->db->prepare(
            "SELECT AVG(overall_rating) as avg_ovr FROM players
             WHERE team_id = ? AND status = 'active'"
        );
        $stmt->execute([$teamId]);
        $avgOvr = (float) $stmt->fetchColumn();

        if ($avgOvr <= 0) return null;

        $cutThreshold = $avgOvr - 15;

        // Find the worst player below the threshold
        $stmt = $this->db->prepare(
            "SELECT * FROM players
             WHERE team_id = ? AND status = 'active' AND overall_rating < ?
             ORDER BY overall_rating ASC
             LIMIT 5"
        );
        $stmt->execute([$teamId, $cutThreshold]);
        $candidates = $stmt->fetchAll();

        foreach ($candidates as $player) {
            // Don't cut if it would leave the team below minimum depth
            $posCount = $this->getPositionCount($teamId, $player['position']);
            $minRequired = $this->minimumDepth[$player['position']] ?? 1;
            if ($posCount <= $minRequired) continue;

            return $player;
        }

        return null;
    }
}
