<?php

namespace App\Services;

use App\Database\Connection;

class TradeEngine
{
    private \PDO $db;

    // Position value weights — reflects real NFL market (QB premium, RB devalued)
    private array $positionValues = [
        'QB' => 3.5, 'RB' => 0.85, 'WR' => 1.6, 'TE' => 1.0,
        'OT' => 1.6, 'OG' => 1.0, 'C' => 0.9,
        'DE' => 1.8, 'DT' => 1.3, 'LB' => 1.1, 'CB' => 1.5, 'S' => 1.1,
        'K' => 0.3, 'P' => 0.2, 'LS' => 0.15,
    ];

    // Ideal roster depth by position
    private array $idealDepth = [
        'QB' => 2, 'RB' => 3, 'WR' => 5, 'TE' => 3,
        'OT' => 4, 'OG' => 4, 'C' => 2,
        'DE' => 4, 'DT' => 4, 'LB' => 4, 'CB' => 5, 'S' => 4,
        'K' => 1, 'P' => 1, 'LS' => 1,
    ];

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
    }

    /**
     * List all trades for a league, with team names and items.
     */
    public function listTrades(int $leagueId): array
    {
        $stmt = $this->db->prepare(
            "SELECT t.*, pt.city as proposing_city, pt.name as proposing_name, pt.abbreviation as proposing_abbr,
                    rt.city as receiving_city, rt.name as receiving_name, rt.abbreviation as receiving_abbr
             FROM trades t
             JOIN teams pt ON t.proposing_team_id = pt.id
             JOIN teams rt ON t.receiving_team_id = rt.id
             WHERE t.league_id = ?
             ORDER BY t.proposed_at DESC"
        );
        $stmt->execute([$leagueId]);
        $trades = $stmt->fetchAll();

        // Attach items to each trade
        foreach ($trades as &$trade) {
            $itemStmt = $this->db->prepare(
                "SELECT ti.*, p.first_name, p.last_name, p.position, p.overall_rating
                 FROM trade_items ti
                 LEFT JOIN players p ON ti.player_id = p.id
                 WHERE ti.trade_id = ?"
            );
            $itemStmt->execute([$trade['id']]);
            $trade['items'] = $itemStmt->fetchAll();
        }

        return $trades;
    }

    /**
     * Evaluate a player's trade value using NFL-research-backed model.
     * Uses exponential rating curve, position-specific age decline,
     * and contract surplus value.
     */
    public function evaluatePlayer(int $playerId): float
    {
        $player = $this->getPlayer($playerId);
        if (!$player) return 0;

        $overall = (int) $player['overall_rating'];
        $position = $player['position'];
        $age = (int) $player['age'];

        $ratingBase = $this->getRatingBase($overall);
        $posWeight = $this->positionValues[$position] ?? 1.0;
        $ageFactor = $this->getAgeFactor($age, $position);

        $potentialFactor = match ($player['potential'] ?? 'average') {
            'elite' => 1.30,
            'high' => 1.12,
            'average' => 1.0,
            'limited' => 0.80,
            default => 1.0,
        };

        // Contract: rookie deals = premium, expiring = discount
        $contract = $this->getContract($playerId);
        $contractFactor = 1.0;
        if ($contract) {
            $yrs = (int) $contract['years_remaining'];
            $salary = (int) ($contract['salary_annual'] ?? 0);
            if ($salary < 5000000 && $yrs >= 2) {
                $contractFactor = 1.15; // Rookie deal surplus value
            } elseif ($yrs <= 1) {
                $contractFactor = 0.70; // Rental / expiring
            } elseif ($yrs >= 4) {
                $contractFactor = 0.85; // Long expensive deal
            }
        }

        return round($ratingBase * $posWeight * $ageFactor * $potentialFactor * $contractFactor, 1);
    }

    /**
     * Exponential rating base — steeper curve for elites.
     * 99 OVR = 45.4, 95 = 28.7, 90 = 18.0, 85 = 12.3, 80 = 9.0, 75 = 6.3, 70 = 4.0, 65 = 2.3
     */
    private function getRatingBase(int $overall): float
    {
        $normalized = ($overall - 50) / 10;
        if ($overall >= 85) {
            // Steeper curve for elite players — they're exponentially rarer
            return pow($normalized, 2.5);
        }
        return pow($normalized, 2.0);
    }

    /**
     * Position-specific age curves based on NFL research.
     * QBs play into late 30s. RBs fall off a cliff at 27. WRs peak 25-29.
     */
    private function getAgeFactor(int $age, string $position): float
    {
        if ($position === 'QB') {
            return match (true) {
                $age <= 24 => 1.20,
                $age <= 27 => 1.15,
                $age <= 30 => 1.05,
                $age <= 33 => 0.95,
                $age <= 36 => 0.75,
                $age <= 38 => 0.55,
                default => 0.30,
            };
        }
        if ($position === 'RB') {
            return match (true) {
                $age <= 23 => 1.15,
                $age <= 25 => 1.10,
                $age <= 27 => 0.90,
                $age <= 28 => 0.70,
                $age <= 30 => 0.50,
                default => 0.25,
            };
        }
        if ($position === 'WR') {
            return match (true) {
                $age <= 24 => 1.15,
                $age <= 28 => 1.05,
                $age <= 30 => 0.85,
                $age <= 32 => 0.65,
                default => 0.40,
            };
        }
        // Default: DE, DT, OT, CB, LB, S, TE, OG, C
        return match (true) {
            $age <= 24 => 1.15,
            $age <= 27 => 1.05,
            $age <= 29 => 1.00,
            $age <= 31 => 0.85,
            $age <= 33 => 0.65,
            default => 0.45,
        };
    }

    /**
     * Evaluate a draft pick's trade value.
     */
    /**
     * Evaluate a draft pick's trade value using Jimmy Johnson chart.
     * Values are scaled to match our player value system.
     * $pickOverall = overall pick number (1-224), or calculated from round + pickInRound.
     * $yearsOut = 0 for current year, 1 for next year (future pick discount).
     */
    public function evaluateDraftPick(int $round, int $pickInRound = 16, int $yearsOut = 0): float
    {
        // Jimmy Johnson chart values for key picks, scaled to our system.
        // Pick 1 = ~150 (matches elite QB value), Pick 32 = ~35, Pick 64 = ~16, etc.
        // Formula approximation: value = 150 * (1 / pickOverall)^0.7
        $pickOverall = ($round - 1) * 32 + $pickInRound;
        $value = 150.0 * pow(1.0 / max(1, $pickOverall), 0.7);

        // Minimum floor for late-round picks
        $value = max(0.5, $value);

        // Future pick discount: ~35% per year
        if ($yearsOut > 0) {
            $value *= pow(0.65, $yearsOut);
        }

        return round($value, 1);
    }

    /**
     * Get a team's available (unused) draft picks.
     */
    private function getTeamDraftPicks(int $teamId, int $leagueId): array
    {
        $stmt = $this->db->prepare(
            "SELECT dp.id, dp.round, dp.pick_number, dp.original_team_id, dp.current_team_id,
                    dc.year, dc.status,
                    ot.abbreviation as original_team_abbr
             FROM draft_picks dp
             JOIN draft_classes dc ON dp.draft_class_id = dc.id
             LEFT JOIN teams ot ON dp.original_team_id = ot.id
             WHERE dp.current_team_id = ? AND dp.league_id = ? AND dp.is_used = 0
             ORDER BY dc.year ASC, dp.round ASC, dp.pick_number ASC"
        );
        $stmt->execute([$teamId, $leagueId]);
        return $stmt->fetchAll();
    }

    /**
     * Evaluate if a trade is fair.
     * Returns: ['fair' => bool, 'proposing_value' => float, 'receiving_value' => float, 'difference' => float]
     */
    public function evaluateTrade(array $proposingItems, array $receivingItems): array
    {
        $proposingValue = 0;
        $receivingValue = 0;

        foreach ($proposingItems as $item) {
            if ($item['item_type'] === 'player') {
                $proposingValue += $this->evaluatePlayer($item['player_id']);
            } elseif ($item['item_type'] === 'draft_pick') {
                $pick = $this->getDraftPick($item['draft_pick_id']);
                if ($pick) $proposingValue += $this->evaluateDraftPick($pick['round'], $pick['pick_number'] % 32);
            }
        }

        foreach ($receivingItems as $item) {
            if ($item['item_type'] === 'player') {
                $receivingValue += $this->evaluatePlayer($item['player_id']);
            } elseif ($item['item_type'] === 'draft_pick') {
                $pick = $this->getDraftPick($item['draft_pick_id']);
                if ($pick) $receivingValue += $this->evaluateDraftPick($pick['round'], $pick['pick_number'] % 32);
            }
        }

        $diff = abs($proposingValue - $receivingValue);
        $maxVal = max($proposingValue, $receivingValue, 1);
        $fairThreshold = $maxVal * 0.25; // 25% difference allowed

        return [
            'fair' => $diff <= $fairThreshold,
            'proposing_value' => round($proposingValue, 1),
            'receiving_value' => round($receivingValue, 1),
            'difference' => round($diff, 1),
            'advantage' => $proposingValue > $receivingValue ? 'proposing' : 'receiving',
        ];
    }

    /**
     * Propose a trade.
     */
    public function proposeTrade(int $leagueId, int $proposingTeamId, int $receivingTeamId, array $proposingItems, array $receivingItems): array
    {
        $evaluation = $this->evaluateTrade($proposingItems, $receivingItems);

        $this->db->prepare(
            "INSERT INTO trades (league_id, proposing_team_id, receiving_team_id, status, proposed_at)
             VALUES (?, ?, ?, 'proposed', ?)"
        )->execute([$leagueId, $proposingTeamId, $receivingTeamId, date('Y-m-d H:i:s')]);
        $tradeId = (int) $this->db->lastInsertId();

        // Insert items
        foreach ($proposingItems as $item) {
            $this->db->prepare(
                "INSERT INTO trade_items (trade_id, direction, item_type, player_id, draft_pick_id) VALUES (?, 'outgoing', ?, ?, ?)"
            )->execute([$tradeId, $item['item_type'], $item['player_id'] ?? null, $item['draft_pick_id'] ?? null]);
        }

        foreach ($receivingItems as $item) {
            $this->db->prepare(
                "INSERT INTO trade_items (trade_id, direction, item_type, player_id, draft_pick_id) VALUES (?, 'incoming', ?, ?, ?)"
            )->execute([$tradeId, $item['item_type'], $item['player_id'] ?? null, $item['draft_pick_id'] ?? null]);
        }

        return ['trade_id' => $tradeId, 'evaluation' => $evaluation];
    }

    /**
     * AI decides whether to accept a trade.
     */
    public function aiEvaluateTrade(int $tradeId): string
    {
        $evaluation = $this->getTradeEvaluation($tradeId);

        // AI logic: accept if fair or slightly in their favor
        if ($evaluation['advantage'] === 'proposing' && !$evaluation['fair']) {
            return 'rejected'; // Bad deal for AI
        }

        // Some randomness in AI acceptance
        if ($evaluation['fair']) {
            return mt_rand(1, 100) <= 70 ? 'accepted' : 'counter'; // 70% accept fair trades
        }

        return 'accepted'; // Good deal for AI
    }

    /**
     * Execute an accepted trade (swap players/picks between teams).
     */
    public function executeTrade(int $tradeId): bool
    {
        $stmt = $this->db->prepare("SELECT * FROM trades WHERE id = ?");
        $stmt->execute([$tradeId]);
        $trade = $stmt->fetch();
        if (!$trade || $trade['status'] !== 'accepted') return false;

        $stmt = $this->db->prepare("SELECT * FROM trade_items WHERE trade_id = ?");
        $stmt->execute([$tradeId]);
        $items = $stmt->fetchAll();

        foreach ($items as $item) {
            if ($item['item_type'] === 'player' && $item['player_id']) {
                $newTeam = $item['direction'] === 'outgoing'
                    ? $trade['receiving_team_id']
                    : $trade['proposing_team_id'];

                $this->db->prepare("UPDATE players SET team_id = ? WHERE id = ?")
                    ->execute([$newTeam, $item['player_id']]);
            }

            if ($item['item_type'] === 'draft_pick' && $item['draft_pick_id']) {
                $newTeam = $item['direction'] === 'outgoing'
                    ? $trade['receiving_team_id']
                    : $trade['proposing_team_id'];

                $this->db->prepare("UPDATE draft_picks SET current_team_id = ? WHERE id = ?")
                    ->execute([$newTeam, $item['draft_pick_id']]);
            }
        }

        $this->db->prepare("UPDATE trades SET status = 'completed', resolved_at = ? WHERE id = ?")
            ->execute([date('Y-m-d H:i:s'), $tradeId]);

        return true;
    }

    /**
     * Generate an AI counter-offer.
     */
    public function generateCounterOffer(int $tradeId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM trades WHERE id = ?");
        $stmt->execute([$tradeId]);
        $trade = $stmt->fetch();
        if (!$trade) return null;

        $stmt = $this->db->prepare("SELECT * FROM trade_items WHERE trade_id = ?");
        $stmt->execute([$tradeId]);
        $items = $stmt->fetchAll();

        // AI wants more: ask for an additional draft pick
        $counterItems = [];
        foreach ($items as $item) {
            $counterItems[] = $item;
        }

        // Try to find an available draft pick from the proposing team
        $stmt = $this->db->prepare(
            "SELECT id FROM draft_picks WHERE current_team_id = ? AND is_used = 0 ORDER BY round DESC LIMIT 1"
        );
        $stmt->execute([$trade['proposing_team_id']]);
        $extraPick = $stmt->fetch();

        if ($extraPick) {
            return [
                'original_trade_id' => $tradeId,
                'additional_ask' => [
                    'item_type' => 'draft_pick',
                    'draft_pick_id' => $extraPick['id'],
                ],
                'message' => "We like the framework of this deal, but we'd need a draft pick sweetener to make it work.",
            ];
        }

        return null;
    }

    /**
     * Respond to a trade proposal (accept, reject, counter).
     */
    public function respondToTrade(int $tradeId, int $teamId, string $action, array $counterData = []): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM trades WHERE id = ? AND receiving_team_id = ?");
        $stmt->execute([$tradeId, $teamId]);
        $trade = $stmt->fetch();
        if (!$trade || $trade['status'] !== 'proposed') return null;

        if ($action === 'accept') {
            $this->db->prepare("UPDATE trades SET status = 'accepted', resolved_at = ? WHERE id = ?")
                ->execute([date('Y-m-d H:i:s'), $tradeId]);
            $this->executeTrade($tradeId);
        } elseif ($action === 'reject') {
            $this->db->prepare("UPDATE trades SET status = 'rejected', resolved_at = ? WHERE id = ?")
                ->execute([date('Y-m-d H:i:s'), $tradeId]);
        } elseif ($action === 'counter') {
            $this->db->prepare("UPDATE trades SET status = 'countered', resolved_at = ? WHERE id = ?")
                ->execute([date('Y-m-d H:i:s'), $tradeId]);
        }

        $stmt = $this->db->prepare("SELECT * FROM trades WHERE id = ?");
        $stmt->execute([$tradeId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get all trade block entries for a league.
     */
    public function getTradeBlock(int $leagueId): array
    {
        $stmt = $this->db->prepare(
            "SELECT tb.*, p.first_name, p.last_name, p.position, p.overall_rating, p.age,
                    t.city as team_city, t.name as team_name, t.abbreviation
             FROM trade_block tb
             JOIN players p ON tb.player_id = p.id
             JOIN teams t ON p.team_id = t.id
             WHERE t.league_id = ?
             ORDER BY p.overall_rating DESC"
        );
        $stmt->execute([$leagueId]);
        return $stmt->fetchAll();
    }

    /**
     * Add a player to the trade block.
     */
    public function addToTradeBlock(int $teamId, int $playerId, string $notes = ''): ?array
    {
        // Verify player is on this team
        $stmt = $this->db->prepare("SELECT id FROM players WHERE id = ? AND team_id = ?");
        $stmt->execute([$playerId, $teamId]);
        if (!$stmt->fetch()) return null;

        $this->db->prepare(
            "INSERT INTO trade_block (team_id, player_id, notes, created_at) VALUES (?, ?, ?, ?)"
        )->execute([$teamId, $playerId, $notes, date('Y-m-d H:i:s')]);

        return ['id' => (int)$this->db->lastInsertId(), 'player_id' => $playerId, 'team_id' => $teamId, 'notes' => $notes];
    }

    /**
     * Remove a player from the trade block.
     */
    public function removeFromTradeBlock(int $entryId, int $teamId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM trade_block WHERE id = ? AND team_id = ?");
        $stmt->execute([$entryId, $teamId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Find trade opportunities for a given player.
     * Uses NFL-research-backed AI: team mode (contender/rebuilding),
     * positional need, franchise player protection, and realistic packaging.
     */
    public function findTradeOpportunities(int $playerId, int $requestingTeamId): array
    {
        $player = $this->getPlayer($playerId);
        if (!$player) return ['player' => null, 'opportunities' => []];

        $playerValue = $this->evaluatePlayer($playerId);
        $playerOvr = (int) $player['overall_rating'];
        $playerPos = $player['position'];
        $playerAge = (int) $player['age'];
        $playerTeamId = (int) ($player['team_id'] ?? 0);
        $leagueId = (int) $player['league_id'];

        // Get all teams with win/loss records
        $stmt = $this->db->prepare(
            "SELECT id, city, name, abbreviation, primary_color, secondary_color,
                    wins, losses FROM teams WHERE league_id = ?"
        );
        $stmt->execute([$leagueId]);
        $allTeams = $stmt->fetchAll();

        // Bulk-load ALL active players
        $allPlayersStmt = $this->db->prepare(
            "SELECT id, team_id, first_name, last_name, position, overall_rating, age, potential
             FROM players WHERE league_id = ? AND status = 'active'"
        );
        $allPlayersStmt->execute([$leagueId]);
        $allLeaguePlayers = $allPlayersStmt->fetchAll();

        // Index players by team, track position depth and best OVR per position
        $playersByTeam = [];
        $posCountsByTeam = [];
        $bestOvrByTeamPos = [];
        $teamAvgRating = [];
        foreach ($allLeaguePlayers as $p) {
            $tid = (int) $p['team_id'];
            $playersByTeam[$tid][] = $p;
            $posCountsByTeam[$tid][$p['position']] = ($posCountsByTeam[$tid][$p['position']] ?? 0) + 1;
            $ovr = (int) $p['overall_rating'];
            if ($ovr > ($bestOvrByTeamPos[$tid][$p['position']] ?? 0)) {
                $bestOvrByTeamPos[$tid][$p['position']] = $ovr;
            }
            $teamAvgRating[$tid][] = $ovr;
        }

        // Bulk-load all available draft picks, indexed by team
        $picksStmt = $this->db->prepare(
            "SELECT dp.id, dp.round, dp.pick_number, dp.current_team_id,
                    dp.original_team_id, dc.year, dc.status,
                    ot.abbreviation as original_team_abbr
             FROM draft_picks dp
             JOIN draft_classes dc ON dp.draft_class_id = dc.id
             LEFT JOIN teams ot ON dp.original_team_id = ot.id
             WHERE dp.league_id = ? AND dp.is_used = 0
             ORDER BY dc.year ASC, dp.round ASC"
        );
        $picksStmt->execute([$leagueId]);
        $allPicks = $picksStmt->fetchAll();

        $picksByTeam = [];
        $leagueYear = 2026; // Current season year
        $leagueStmt = $this->db->prepare("SELECT season_year FROM leagues WHERE id = ?");
        $leagueStmt->execute([$leagueId]);
        $leagueRow = $leagueStmt->fetch();
        if ($leagueRow) $leagueYear = (int) $leagueRow['season_year'];

        foreach ($allPicks as $pick) {
            $tid = (int) $pick['current_team_id'];
            $yearsOut = max(0, (int) $pick['year'] - $leagueYear);
            $pickInRound = ((int) $pick['pick_number'] - 1) % 32 + 1;
            $pickVal = $this->evaluateDraftPick((int) $pick['round'], $pickInRound, $yearsOut);
            $picksByTeam[$tid][] = array_merge($pick, [
                '_value' => $pickVal,
                '_pick_in_round' => $pickInRound,
                '_years_out' => $yearsOut,
            ]);
        }

        $opportunities = [];

        foreach ($allTeams as $team) {
            $teamId = (int) $team['id'];
            if ($teamId === $playerTeamId) continue;

            $teamPlayers = $playersByTeam[$teamId] ?? [];
            if (empty($teamPlayers)) continue;

            // ── TEAM MODE: contender / competitive / rebuilding ──
            $wins = (int) ($team['wins'] ?? 0);
            $losses = (int) ($team['losses'] ?? 0);
            $totalGames = $wins + $losses;
            $winPct = $totalGames > 0 ? $wins / $totalGames : 0.5;
            $avgOvr = count($teamAvgRating[$teamId] ?? []) > 0
                ? array_sum($teamAvgRating[$teamId]) / count($teamAvgRating[$teamId]) : 72;

            if ($winPct >= 0.625 && $avgOvr >= 76) $teamMode = 'contender';
            elseif ($winPct < 0.375 || $avgOvr < 72) $teamMode = 'rebuilding';
            else $teamMode = 'competitive';

            // ── Rebuilding teams don't want aging players ──
            if ($teamMode === 'rebuilding' && $playerAge >= 30) continue;

            // ── POSITIONAL NEED SCORE ──
            $teamPosCount = $posCountsByTeam[$teamId][$playerPos] ?? 0;
            $idealCount = $this->idealDepth[$playerPos] ?? 2;
            $bestAtPos = $bestOvrByTeamPos[$teamId][$playerPos] ?? 0;

            $needScore = 0.0;
            // Thin at position
            if ($teamPosCount < $idealCount) {
                $needScore += min(0.4, ($idealCount - $teamPosCount) * 0.15);
            }
            // Weak starter at position
            if ($bestAtPos < 75) $needScore += 0.3;
            elseif ($bestAtPos < 80) $needScore += 0.15;
            elseif ($bestAtPos >= 88) $needScore -= 0.15; // Already stacked

            // Upgrade bonus
            $upgradeBonus = 0.0;
            if ($playerOvr > $bestAtPos) {
                $upgradeBonus = min(0.4, ($playerOvr - $bestAtPos) * 0.03);
            }
            // QB need is amplified
            if ($playerPos === 'QB' && $bestAtPos < 78) {
                $needScore += 0.4;
            }
            $needScore = max(0, min(1.0, $needScore + $upgradeBonus));

            // Low need = skip (they don't want this player)
            if ($needScore < 0.05 && $upgradeBonus <= 0) continue;

            // ── BUILD THEIR OFFER (what the opposing team sends) ──
            $playerCandidates = [];
            foreach ($teamPlayers as $tp) {
                $tpOvr = (int) $tp['overall_rating'];
                $tpPos = $tp['position'];
                $tpValue = $this->quickEvaluatePlayer($tp);
                if ($tpValue < 1) continue;

                // FRANCHISE PLAYER PROTECTION: AI won't trade their 90+ stars
                if ($tpOvr >= 90 && in_array($tpPos, ['QB', 'DE', 'OT', 'WR', 'CB'])) {
                    if ((int) $tp['age'] < 30) continue;
                }
                if ($tpPos === 'QB' && $tpOvr >= 82) continue;
                if ($tpValue > $playerValue * 1.25) continue;

                // Rebuilding teams keep young players
                if ($teamMode === 'rebuilding' && (int) $tp['age'] < 25 && $tpOvr >= 75) continue;

                // Don't trade starter if thin at position
                $depthAtTpPos = $posCountsByTeam[$teamId][$tpPos] ?? 0;
                if ($tpOvr === ($bestOvrByTeamPos[$teamId][$tpPos] ?? 0) && $depthAtTpPos <= ($this->idealDepth[$tpPos] ?? 2)) {
                    continue;
                }

                $playerCandidates[] = array_merge($tp, ['_value' => $tpValue, '_type' => 'player']);
            }

            // Draft pick candidates from opposing team
            $pickCandidates = [];
            $teamPicks = $picksByTeam[$teamId] ?? [];
            foreach ($teamPicks as $pk) {
                $pkValue = $pk['_value'];
                if ($pkValue < 0.5) continue;
                if ($pkValue > $playerValue * 1.25) continue;
                if ($teamMode === 'rebuilding' && (int) $pk['round'] <= 3) continue;
                if ($teamMode === 'competitive' && (int) $pk['round'] <= 1 && $pk['_years_out'] === 0) continue;

                $pickCandidates[] = array_merge($pk, ['_type' => 'pick']);
            }

            // Merge all candidates and sort by closest to target value
            $allCandidates = array_merge($playerCandidates, $pickCandidates);
            if (empty($allCandidates)) continue;

            usort($allCandidates, function ($a, $b) use ($playerValue) {
                return abs($a['_value'] - $playerValue) <=> abs($b['_value'] - $playerValue);
            });

            // Build their side: prefer fewer pieces, max 3 players + 2 picks
            $theySendPlayers = [];
            $theySendPicks = [];
            $theirValue = 0;
            $targetMin = $playerValue * 0.75;
            $targetMax = $playerValue * 1.25;
            $playerCount = 0;
            $pickCount = 0;

            foreach ($allCandidates as $c) {
                if ($theirValue >= $targetMax) break;
                if ($theirValue + $c['_value'] > $targetMax && $theirValue >= $targetMin) break;

                if ($c['_type'] === 'player') {
                    if ($playerCount >= 3) continue;
                    $theySendPlayers[] = [
                        'id' => (int) $c['id'],
                        'name' => $c['first_name'] . ' ' . $c['last_name'],
                        'position' => $c['position'],
                        'overall_rating' => (int) $c['overall_rating'],
                        'age' => (int) $c['age'],
                        'trade_value' => round($c['_value'], 1),
                    ];
                    $theirValue += $c['_value'];
                    $playerCount++;
                } else {
                    if ($pickCount >= 2) continue;
                    $yearLabel = (int) $c['year'];
                    $roundNum = (int) $c['round'];
                    $origAbbr = $c['original_team_abbr'] ?? '';
                    $ownPick = ((int) $c['original_team_id'] === $teamId);
                    $pickLabel = $yearLabel . ' Round ' . $roundNum;
                    if (!$ownPick && $origAbbr) {
                        $pickLabel .= ' (via ' . $origAbbr . ')';
                    }
                    $theySendPicks[] = [
                        'id' => (int) $c['id'],
                        'label' => $pickLabel,
                        'round' => $roundNum,
                        'year' => $yearLabel,
                        'trade_value' => round($c['_value'], 1),
                    ];
                    $theirValue += $c['_value'];
                    $pickCount++;
                }
            }

            if ((empty($theySendPlayers) && empty($theySendPicks)) || $theirValue < $targetMin) continue;

            // ── BUILD YOUR SIDE (what user sends) ──
            // Determine positions the opposing team needs
            $opposingNeedPositions = [];
            foreach ($this->idealDepth as $pos => $ideal) {
                $count = $posCountsByTeam[$teamId][$pos] ?? 0;
                if ($count < $ideal) $opposingNeedPositions[] = $pos;
            }

            // User sends the selected player + possible extras
            $userPlayers = $playersByTeam[$requestingTeamId] ?? [];
            $userPicks = $picksByTeam[$requestingTeamId] ?? [];

            $theirAsk = $this->buildTheirAsk(
                $theirValue,
                $playerValue,
                $playerId,
                $userPlayers,
                $userPicks,
                $opposingNeedPositions,
                $teamMode,
                $posCountsByTeam,
                $bestOvrByTeamPos,
                $requestingTeamId
            );

            // Assemble you_send side
            $youSendPlayers = [
                [
                    'id' => (int) $player['id'],
                    'name' => $player['first_name'] . ' ' . $player['last_name'],
                    'position' => $playerPos,
                    'overall_rating' => $playerOvr,
                    'age' => $playerAge,
                    'trade_value' => round($playerValue, 1),
                    'is_selected' => true,
                ],
            ];
            $youSendPicks = [];
            $youSendTotal = $playerValue;

            // Add extras from buildTheirAsk
            foreach ($theirAsk['players'] as $ep) {
                $youSendPlayers[] = $ep;
            }
            foreach ($theirAsk['picks'] as $epk) {
                $youSendPicks[] = $epk;
            }
            $youSendTotal += $theirAsk['total_value'];

            $youSend = ['players' => $youSendPlayers, 'picks' => $youSendPicks, 'total_value' => round($youSendTotal, 1)];
            $theySend = ['players' => $theySendPlayers, 'picks' => $theySendPicks, 'total_value' => round($theirValue, 1)];

            // ── FAIRNESS (tighter: 80-120%) ──
            $minSide = min($youSendTotal, $theirValue);
            $maxSide = max($youSendTotal, $theirValue, 1);
            $fairnessRatio = $minSide / $maxSide;
            if ($fairnessRatio < 0.80) continue;
            $fairness = $fairnessRatio;

            // ── PACKAGE TYPE & GM NOTE ──
            $packageType = $this->classifyPackageType($youSend, $theySend);
            $gmNote = $this->generateGmNote($packageType, $teamMode, $fairness, $youSendTotal, $theirValue);

            // ── INTEREST SCORE ──
            $modeBonus = match ($teamMode) {
                'contender' => $needScore * 0.15,
                'rebuilding' => -0.05,
                default => 0.0,
            };
            $interestScore = ($needScore * 0.40) + ($fairness * 0.35) + ($upgradeBonus * 0.25) + $modeBonus;
            $interestScore = max(0, min(1, $interestScore));

            $interest = match (true) {
                $interestScore >= 0.65 => 'high',
                $interestScore >= 0.40 => 'medium',
                default => 'low',
            };

            $reason = $this->generateTradeReason(
                $player, $team, $teamPosCount, (float) $idealCount,
                $bestAtPos > 0 ? ['overall_rating' => $bestAtPos] : null,
                $teamMode
            );

            $opportunities[] = [
                'team' => [
                    'id' => $teamId,
                    'city' => $team['city'],
                    'name' => $team['name'],
                    'abbreviation' => $team['abbreviation'],
                    'primary_color' => $team['primary_color'],
                    'secondary_color' => $team['secondary_color'],
                ],
                'interest' => $interest,
                'interest_score' => round($interestScore, 3),
                'package_type' => $packageType,
                'you_send' => $youSend,
                'they_send' => $theySend,
                'fairness' => round($fairness, 2),
                'reason' => $reason,
                'team_mode' => $teamMode,
                'gm_note' => $gmNote,
            ];
        }

        // ── COMPUTE USER'S TOP POSITION NEEDS ──
        // Count league-wide position usage to skip positions the roster data barely uses
        // (e.g. Madden imports may classify all edge rushers as DT, leaving DE near-zero)
        $leaguePosCounts = [];
        foreach ($allLeaguePlayers as $lp) {
            $leaguePosCounts[$lp['position']] = ($leaguePosCounts[$lp['position']] ?? 0) + 1;
        }
        $totalLeaguePlayers = count($allLeaguePlayers);
        $teamCount = count($allTeams);

        $userNeeds = [];
        foreach ($this->idealDepth as $pos => $ideal) {
            // Skip positions with very low league-wide usage — the data doesn't really use them
            // If fewer than 1 player per team on average league-wide, this position label isn't meaningful
            $leagueCount = $leaguePosCounts[$pos] ?? 0;
            $avgPerTeam = $teamCount > 0 ? $leagueCount / $teamCount : 0;
            if ($avgPerTeam < 1.0) continue;

            // Scale ideal depth to match actual league usage patterns
            // If league averages 2 DTs per team but idealDepth says 4, use the league average
            $effectiveIdeal = min($ideal, max(1, (int) round($avgPerTeam)));

            $count = $posCountsByTeam[$requestingTeamId][$pos] ?? 0;
            $bestOvr = $bestOvrByTeamPos[$requestingTeamId][$pos] ?? 0;

            $needScore = 0.0;
            // Thin at position
            if ($count < $effectiveIdeal) {
                $needScore += min(0.5, ($effectiveIdeal - $count) * 0.20);
            }
            // Weak starter (only score if team actually has someone there)
            if ($count > 0) {
                if ($bestOvr < 70) $needScore += 0.35;
                elseif ($bestOvr < 75) $needScore += 0.25;
                elseif ($bestOvr < 80) $needScore += 0.10;
                elseif ($bestOvr >= 88) $needScore -= 0.15;
            } elseif ($effectiveIdeal >= 2) {
                // Completely empty at a position that should have multiple — big need
                $needScore += 0.40;
            }

            if ($needScore > 0.05) {
                $userNeeds[] = [
                    'position' => $pos,
                    'need_score' => round($needScore, 2),
                    'roster_count' => $count,
                    'ideal_count' => $effectiveIdeal,
                    'best_overall' => $bestOvr,
                ];
            }
        }
        usort($userNeeds, fn($a, $b) => $b['need_score'] <=> $a['need_score']);
        $userNeeds = array_slice($userNeeds, 0, 5);
        $userNeedPositions = array_map(fn($n) => $n['position'], $userNeeds);

        // Tag they_send players that fill a user need
        foreach ($opportunities as &$opp) {
            foreach ($opp['they_send']['players'] as &$tp) {
                $tp['fills_need'] = in_array($tp['position'], $userNeedPositions);
            }
            unset($tp);
        }
        unset($opp);

        // Sort by interest score descending, return top 10
        usort($opportunities, fn($a, $b) => $b['interest_score'] <=> $a['interest_score']);
        $opportunities = array_slice($opportunities, 0, 10);

        return [
            'player' => [
                'id' => (int) $player['id'],
                'name' => $player['first_name'] . ' ' . $player['last_name'],
                'position' => $player['position'],
                'overall_rating' => $playerOvr,
                'age' => $playerAge,
                'trade_value' => round($playerValue, 1),
            ],
            'team_needs' => $userNeeds,
            'opportunities' => $opportunities,
        ];
    }

    /**
     * Fast trade value estimate from player array data (no DB queries).
     * Mirrors evaluatePlayer() logic but works from pre-fetched data.
     */
    private function quickEvaluatePlayer(array $p): float
    {
        $overall = (int) $p['overall_rating'];
        $position = $p['position'];
        $age = (int) $p['age'];

        $ratingBase = $this->getRatingBase($overall);
        $posWeight = $this->positionValues[$position] ?? 1.0;
        $ageFactor = $this->getAgeFactor($age, $position);

        $potentialFactor = match ($p['potential'] ?? 'average') {
            'elite' => 1.30,
            'high' => 1.12,
            'average' => 1.0,
            'limited' => 0.80,
            default => 1.0,
        };

        return round($ratingBase * $posWeight * $ageFactor * $potentialFactor, 1);
    }

    /**
     * Generate a human-readable reason for a trade opportunity.
     */
    private function generateTradeReason(array $player, array $team, int $teamPosCount, float $idealCount, ?array $bestAtPos, string $teamMode = 'competitive'): string
    {
        $pos = $player['position'];
        $ovr = (int) $player['overall_rating'];
        $age = (int) $player['age'];

        // Team mode context
        $modePrefix = match ($teamMode) {
            'contender' => 'Playoff contender',
            'rebuilding' => 'Rebuilding team',
            default => '',
        };

        if ($teamPosCount < $idealCount && $bestAtPos && $ovr > (int) $bestAtPos['overall_rating']) {
            $base = "Thin at {$pos} — would be an immediate upgrade";
            return $modePrefix ? "{$modePrefix}: {$base}" : $base;
        }

        if ($teamPosCount < $idealCount) {
            $base = "Need {$pos} depth — only {$teamPosCount} on roster";
            return $modePrefix ? "{$modePrefix}: {$base}" : $base;
        }

        if ($bestAtPos && $ovr > (int) $bestAtPos['overall_rating'] + 3) {
            $base = "Major upgrade at {$pos} — best is " . $bestAtPos['overall_rating'] . " OVR";
            return $modePrefix ? "{$modePrefix}: {$base}" : $base;
        }

        if ($bestAtPos && $ovr > (int) $bestAtPos['overall_rating']) {
            return "Would be a starter upgrade at {$pos}";
        }

        if ($teamMode === 'contender' && $ovr >= 82) {
            return "Contender looking to add proven {$pos} for playoff push";
        }

        if ($age <= 25 && $ovr >= 75) {
            return "Interested in young {$pos} with upside";
        }

        if ($teamMode === 'rebuilding' && $age <= 26) {
            return "Rebuilding — interested in young talent";
        }

        return "Open to acquiring a {$pos}";
    }

    /**
     * After determining what the opposing team offers, figure out what extras
     * they want from the user to balance the deal. Zero new DB queries.
     */
    private function buildTheirAsk(
        float $theirOfferValue,
        float $selectedPlayerValue,
        int $selectedPlayerId,
        array $userPlayers,
        array $userPicks,
        array $opposingTeamNeedPositions,
        string $teamMode,
        array $posCountsByTeam,
        array $bestOvrByTeamPos,
        int $requestingTeamId
    ): array {
        $gap = $theirOfferValue - $selectedPlayerValue;

        // Ask multiplier varies by mode
        $askMultiplier = match ($teamMode) {
            'contender' => 0.85,
            'rebuilding' => 1.15,
            default => 1.0,
        };

        $adjustedGap = $gap * $askMultiplier;

        // If gap ≤ 10% of offer → straight swap, no extras needed
        if (abs($adjustedGap) <= $theirOfferValue * 0.10) {
            return ['players' => [], 'picks' => [], 'total_value' => 0];
        }

        // They need extras from the user to balance the deal
        // (adjustedGap is negative — user's player is worth less than their offer)
        // OR user's player is worth MORE, so user needs to send less
        if ($adjustedGap > 0) {
            // Their offer is MORE valuable — no extras needed from user
            return ['players' => [], 'picks' => [], 'total_value' => 0];
        }

        $deficit = abs($adjustedGap);
        $extraPlayers = [];
        $extraPicks = [];
        $extraValue = 0;

        // Try picks first (universal currency, less disruptive)
        $sortedPicks = $userPicks;
        usort($sortedPicks, fn($a, $b) => ($a['_value'] ?? 0) <=> ($b['_value'] ?? 0));

        foreach ($sortedPicks as $pk) {
            if ($extraValue >= $deficit) break;
            $pkVal = $pk['_value'] ?? 0;
            if ($pkVal < 0.5) continue;
            // Don't grab picks worth way more than the deficit
            if ($pkVal > $deficit * 2.5 && $extraValue === 0) continue;

            $yearLabel = (int) $pk['year'];
            $roundNum = (int) $pk['round'];
            $origAbbr = $pk['original_team_abbr'] ?? '';
            $ownPick = ((int) ($pk['original_team_id'] ?? 0) === $requestingTeamId);
            $pickLabel = $yearLabel . ' Round ' . $roundNum;
            if (!$ownPick && $origAbbr) {
                $pickLabel .= ' (via ' . $origAbbr . ')';
            }

            $extraPicks[] = [
                'id' => (int) $pk['id'],
                'label' => $pickLabel,
                'round' => $roundNum,
                'year' => $yearLabel,
                'trade_value' => round($pkVal, 1),
            ];
            $extraValue += $pkVal;
        }

        // Then try a player if picks aren't enough
        if ($extraValue < $deficit * 0.70) {
            $remaining = $deficit - $extraValue;
            $playerCandidates = [];

            foreach ($userPlayers as $up) {
                $upId = (int) $up['id'];
                if ($upId === $selectedPlayerId) continue;
                $upOvr = (int) $up['overall_rating'];
                // Exclude 85+ OVR franchise-level players
                if ($upOvr >= 85) continue;
                $upPos = $up['position'];
                $upValue = $this->quickEvaluatePlayer($up);
                if ($upValue < 1) continue;
                if ($upValue > $remaining * 2.0) continue;

                // Exclude user's best player at a position if thin there
                $userDepth = $posCountsByTeam[$requestingTeamId][$upPos] ?? 0;
                $userBest = $bestOvrByTeamPos[$requestingTeamId][$upPos] ?? 0;
                if ($upOvr >= $userBest && $userDepth <= ($this->idealDepth[$upPos] ?? 2)) continue;

                // Score: prefer backups/depth, boost if opposing team needs the position
                $score = abs($upValue - $remaining); // closer to deficit = better
                if (in_array($upPos, $opposingTeamNeedPositions)) {
                    $score *= 0.5; // boost candidates at positions they need
                }
                $playerCandidates[] = ['player' => $up, 'value' => $upValue, 'score' => $score];
            }

            usort($playerCandidates, fn($a, $b) => $a['score'] <=> $b['score']);

            if (!empty($playerCandidates)) {
                $best = $playerCandidates[0];
                $bp = $best['player'];
                $extraPlayers[] = [
                    'id' => (int) $bp['id'],
                    'name' => $bp['first_name'] . ' ' . $bp['last_name'],
                    'position' => $bp['position'],
                    'overall_rating' => (int) $bp['overall_rating'],
                    'age' => (int) $bp['age'],
                    'trade_value' => round($best['value'], 1),
                ];
                $extraValue += $best['value'];
            }
        }

        return [
            'players' => $extraPlayers,
            'picks' => $extraPicks,
            'total_value' => round($extraValue, 1),
        ];
    }

    /**
     * Classify the package type for display.
     */
    private function classifyPackageType(array $youSend, array $theySend): string
    {
        $youPlayers = count($youSend['players']);
        $youPicks = count($youSend['picks']);
        $theyPlayers = count($theySend['players']);
        $theyPicks = count($theySend['picks']);

        $totalYou = $youPlayers + $youPicks;
        $totalThem = $theyPlayers + $theyPicks;

        if ($theyPlayers === 0 && $theyPicks > 0) return 'player_for_picks_only';
        if ($youPlayers >= 2 || $theyPlayers >= 2) {
            if ($youPicks > 0 || $theyPicks > 0) return 'multi_player_plus_picks';
            return 'multi_player_swap';
        }
        if ($youPlayers === 1 && $youPicks > 0 && $theyPlayers >= 1) return 'player_plus_pick_for_player';
        if ($theyPlayers >= 1 && $theyPicks > 0 && $youPlayers === 1 && $youPicks === 0) return 'player_for_player_plus_picks';
        return 'player_for_player';
    }

    /**
     * Generate a short personality-driven GM note.
     */
    private function generateGmNote(string $packageType, string $teamMode, float $fairness, float $youSendTotal, float $theySendTotal): string
    {
        $notes = [];

        if ($teamMode === 'contender') {
            $notes = [
                "We're willing to overpay — we need this player for our playoff push.",
                "We're in win-now mode and ready to make a move.",
                "This fills a hole we need for a championship run.",
            ];
        } elseif ($teamMode === 'rebuilding') {
            $notes = [
                "We want future value back in this deal.",
                "We're building for the long term — need assets we can develop.",
                "Don't expect us to give up picks cheaply.",
            ];
        } else {
            $notes = [
                "This deal makes sense for both sides.",
                "Fair deal — let's get it done.",
                "We think this trade helps both rosters.",
            ];
        }

        // Add context-specific notes
        if ($packageType === 'player_plus_pick_for_player') {
            return "We need a mid-round pick thrown in to make the math work.";
        }
        if ($packageType === 'player_for_picks_only') {
            return "We'll send you picks — we're not ready to part with roster players.";
        }
        if ($fairness > 0.95) {
            return "Textbook even swap — works for everyone.";
        }
        if ($theySendTotal > $youSendTotal * 1.15) {
            if ($teamMode === 'contender') {
                return "We're willing to overpay — we need this player for our playoff push.";
            }
        }
        if ($youSendTotal > $theySendTotal * 1.10) {
            return "You're sending a lot of value — but we think our piece is worth it.";
        }

        // Deterministic pick from remaining notes using a hash
        $idx = crc32($packageType . $teamMode) % count($notes);
        return $notes[abs($idx)];
    }

    private function getTradeEvaluation(int $tradeId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM trade_items WHERE trade_id = ?");
        $stmt->execute([$tradeId]);
        $items = $stmt->fetchAll();

        $proposing = array_filter($items, fn($i) => $i['direction'] === 'outgoing');
        $receiving = array_filter($items, fn($i) => $i['direction'] === 'incoming');

        return $this->evaluateTrade(array_values($proposing), array_values($receiving));
    }

    private function getPlayer(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM players WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    private function getContract(int $playerId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM contracts WHERE player_id = ? AND years_remaining > 0 LIMIT 1");
        $stmt->execute([$playerId]);
        return $stmt->fetch() ?: null;
    }

    private function getDraftPick(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM draft_picks WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
}
