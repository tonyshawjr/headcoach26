<?php

namespace App\Services;

use App\Database\Connection;

/**
 * TradeEngine — Orchestrates all trade operations using TeamBrain.
 *
 * Every trade decision flows through TeamBrain instances. When a user shops
 * a player, each AI team "thinks" independently about whether they want
 * that player, what they'd offer, and what they'd be willing to give up.
 *
 * No random algorithms. Every team thinks.
 */
class TradeEngine
{
    private \PDO $db;

    // Position value weights
    private array $positionValues = [
        'QB' => 2.5, 'RB' => 1.3, 'WR' => 1.5, 'TE' => 1.2,
        'OT' => 1.5, 'OG' => 1.1, 'C' => 1.0,
        'DE' => 1.6, 'DT' => 1.3, 'LB' => 1.2, 'CB' => 1.4, 'S' => 1.2,
        'K' => 0.4, 'P' => 0.3, 'LS' => 0.2,
    ];

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

    // ════════════════════════════════════════════════════════════════════
    //  PLAYER VALUATION — Base (universal) value
    // ════════════════════════════════════════════════════════════════════

    public function evaluatePlayer(int $playerId): float
    {
        $player = $this->getPlayer($playerId);
        if (!$player) return 0;
        return $this->calculatePlayerValue($player, $playerId);
    }

    /**
     * Core value calculation — universal base value before team context.
     */
    public function calculatePlayerValue(array $player, ?int $playerId = null): float
    {
        $overall = (int) $player['overall_rating'];
        $position = $player['position'];
        $age = (int) $player['age'];
        $potential = $player['potential'] ?? 'average';

        $ratingBase = $this->getRatingBase($overall);
        $posWeight = $this->positionValues[$position] ?? 1.0;
        $ageFactor = $this->getAgeFactor($age, $position);

        $potentialFactor = match ($potential) {
            'elite' => 1.50,
            'high' => 1.25,
            'average' => 1.0,
            'limited' => 0.75,
            default => 1.0,
        };

        // Projected value bonus for young high-potential players
        $projectedBonus = 0.0;
        if ($age <= 27 && ($potential === 'elite' || $potential === 'high')) {
            $yearsToGrow = 27 - $age;
            $projectedGrowth = match ($potential) {
                'elite' => 4.0 * $yearsToGrow,
                'high' => 2.5 * $yearsToGrow,
                default => 0,
            };
            $projectedOvr = min(99, $overall + (int) $projectedGrowth);
            $projectedBase = $this->getRatingBase($projectedOvr);

            $blendWeight = match ($potential) {
                'elite' => min(0.50, $yearsToGrow * 0.18),
                'high' => min(0.35, $yearsToGrow * 0.14),
                default => 0,
            };
            $projectedBonus = $projectedBase * $posWeight * $ageFactor * $blendWeight;
        }

        $currentValue = $ratingBase * $posWeight * $ageFactor * $potentialFactor;

        // Contract factor
        $contractFactor = 1.0;
        if ($playerId) {
            $contract = $this->getContract($playerId);
            if ($contract) {
                $yrs = (int) $contract['years_remaining'];
                $salary = (int) ($contract['salary_annual'] ?? 0);
                if ($salary < 5000000 && $yrs >= 2) {
                    $contractFactor = 1.15;
                } elseif ($yrs <= 1) {
                    $contractFactor = 0.70;
                } elseif ($yrs >= 4) {
                    $contractFactor = 0.85;
                }
            }
        }

        return round(($currentValue + $projectedBonus) * $contractFactor, 1);
    }

    private function getRatingBase(int $overall): float
    {
        $normalized = ($overall - 50) / 10;
        if ($overall >= 85) {
            return pow($normalized, 2.5);
        }
        return pow($normalized, 2.0);
    }

    private function getAgeFactor(int $age, string $position): float
    {
        if ($position === 'QB') {
            return match (true) {
                $age <= 24 => 1.20, $age <= 27 => 1.15, $age <= 30 => 1.05,
                $age <= 33 => 0.95, $age <= 36 => 0.75, $age <= 38 => 0.55,
                default => 0.30,
            };
        }
        if ($position === 'RB') {
            return match (true) {
                $age <= 23 => 1.15, $age <= 25 => 1.10, $age <= 27 => 1.00,
                $age <= 29 => 0.80, $age <= 31 => 0.55,
                default => 0.30,
            };
        }
        if ($position === 'WR') {
            return match (true) {
                $age <= 24 => 1.15, $age <= 28 => 1.05, $age <= 30 => 0.85,
                $age <= 32 => 0.65,
                default => 0.40,
            };
        }
        return match (true) {
            $age <= 24 => 1.15, $age <= 27 => 1.05, $age <= 29 => 1.00,
            $age <= 31 => 0.85, $age <= 33 => 0.65,
            default => 0.45,
        };
    }

    public function evaluateDraftPick(int $round, int $pickInRound = 16, int $yearsOut = 0): float
    {
        $pickOverall = ($round - 1) * 32 + $pickInRound;
        $value = 150.0 * pow(1.0 / max(1, $pickOverall), 0.7);
        $value = max(0.5, $value);
        if ($yearsOut > 0) $value *= pow(0.65, $yearsOut);
        return round($value, 1);
    }

    // ════════════════════════════════════════════════════════════════════
    //  LEAGUE DATA LOADING — Used to build TeamBrain instances
    // ════════════════════════════════════════════════════════════════════

    /**
     * Load all league data and create TeamBrain instances for every team.
     * Returns [teamId => TeamBrain, ...] plus raw data arrays.
     */
    private function loadLeagueContext(int $leagueId): array
    {
        // Teams
        $stmt = $this->db->prepare(
            "SELECT * FROM teams WHERE league_id = ?"
        );
        $stmt->execute([$leagueId]);
        $allTeams = $stmt->fetchAll();

        // Coaches (with GM personality)
        $stmt = $this->db->prepare(
            "SELECT * FROM coaches WHERE league_id = ?"
        );
        $stmt->execute([$leagueId]);
        $allCoaches = $stmt->fetchAll();
        $coachByTeam = [];
        foreach ($allCoaches as $c) {
            $coachByTeam[(int) $c['team_id']] = $c;
        }

        // All active players
        $stmt = $this->db->prepare(
            "SELECT * FROM players WHERE league_id = ? AND status = 'active'"
        );
        $stmt->execute([$leagueId]);
        $allPlayers = $stmt->fetchAll();

        $playersByTeam = [];
        foreach ($allPlayers as $p) {
            $tid = (int) $p['team_id'];
            $playersByTeam[$tid][] = $p;
        }

        // All draft picks
        $stmt = $this->db->prepare(
            "SELECT dp.*, dc.year
             FROM draft_picks dp
             JOIN draft_classes dc ON dp.draft_class_id = dc.id
             LEFT JOIN teams ot ON dp.original_team_id = ot.id
             WHERE dp.league_id = ? AND dp.is_used = 0
             ORDER BY dc.year ASC, dp.round ASC"
        );
        $stmt->execute([$leagueId]);
        $allPicks = $stmt->fetchAll();

        $picksByTeam = [];
        foreach ($allPicks as $pk) {
            $tid = (int) $pk['current_team_id'];
            $picksByTeam[$tid][] = $pk;
        }

        // League info
        $stmt = $this->db->prepare("SELECT * FROM leagues WHERE id = ?");
        $stmt->execute([$leagueId]);
        $league = $stmt->fetch();
        $leagueWeek = (int) ($league['current_week'] ?? 0);
        $leagueYear = (int) ($league['season_year'] ?? 2026);

        // Build TeamBrain for every team
        $brains = [];
        foreach ($allTeams as $team) {
            $tid = (int) $team['id'];
            $coach = $coachByTeam[$tid] ?? ['gm_personality' => 'balanced'];
            $players = $playersByTeam[$tid] ?? [];
            $picks = $picksByTeam[$tid] ?? [];

            $brains[$tid] = TeamBrain::create($team, $coach, $players, $picks, $leagueWeek, $leagueYear);
        }

        return [
            'brains' => $brains,
            'teams' => $allTeams,
            'players' => $allPlayers,
            'players_by_team' => $playersByTeam,
            'picks_by_team' => $picksByTeam,
            'league_week' => $leagueWeek,
            'league_year' => $leagueYear,
        ];
    }

    // ════════════════════════════════════════════════════════════════════
    //  FIND TRADE OPPORTUNITIES — The big one
    // ════════════════════════════════════════════════════════════════════

    /**
     * Find trade opportunities for a given player.
     * Every opposing team THINKS independently about whether they want
     * this player and what they'd offer.
     */
    public function findTradeOpportunities(int $playerId, int $requestingTeamId): array
    {
        $player = $this->getPlayer($playerId);
        if (!$player) return ['player' => null, 'opportunities' => []];

        $baseValue = $this->calculatePlayerValue($player, $playerId);
        $leagueId = (int) $player['league_id'];

        // Load all league data and build TeamBrain for every team
        $ctx = $this->loadLeagueContext($leagueId);
        $brains = $ctx['brains'];

        $userBrain = $brains[$requestingTeamId] ?? null;
        if (!$userBrain) return ['player' => null, 'opportunities' => []];

        // User is actively shopping this player — use market value as asking price
        // (assessTradeCandidate might mark them untouchable, but user decided to trade)
        $askingPrice = $baseValue;
        $userNeeds = $userBrain->getNeeds();
        $userNeedPositions = array_keys(array_filter($userNeeds, fn($s) => $s >= 0.15));

        // ── LEVERAGE: Count how many teams are interested ────────────
        $interestedCount = 0;
        $teamInterest = [];
        foreach ($brains as $tid => $brain) {
            if ($tid === $requestingTeamId) continue;
            $interest = $brain->isInterestedIn($player, $baseValue);
            if ($interest['interested']) {
                $interestedCount++;
                $teamInterest[$tid] = $interest;
            }
        }

        // Leverage multiplier: more demand = higher price (but capped)
        $leverageMultiplier = match (true) {
            $interestedCount >= 8 => 1.10,
            $interestedCount >= 5 => 1.05,
            $interestedCount >= 3 => 1.00,
            $interestedCount >= 1 => 0.95,
            default => 0.85,
        };
        $adjustedAskingPrice = $askingPrice * $leverageMultiplier;

        // ── Each interested team builds their best offer ─────────────
        $opportunities = [];
        $valueCalc = fn(array $p) => $this->calculatePlayerValue($p);

        foreach ($teamInterest as $tid => $interest) {
            $brain = $brains[$tid];
            $team = $brain->getTeam();

            // Team thinks about what to offer
            $offer = $brain->buildOfferFor(
                $player, $adjustedAskingPrice,
                $userNeeds, $valueCalc
            );

            if (!$offer) continue;

            // Format the offer for the API response
            $theySendPlayers = [];
            $theySendPicks = [];
            $theirValue = 0;

            $oppTeamColor = $team['primary_color'] ?? null;
            foreach ($offer['players'] as $c) {
                $p = $c['player'];
                $theySendPlayers[] = [
                    'id' => (int) $p['id'],
                    'name' => ($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''),
                    'position' => $p['position'],
                    'overall_rating' => (int) $p['overall_rating'],
                    'age' => (int) $p['age'],
                    'trade_value' => round($c['value'], 1),
                    'image_url' => $p['image_url'] ?? null,
                    'dev_trait' => $p['potential'] ?? null,
                    'speed' => (int) ($p['speed'] ?? 0),
                    'team_color' => $oppTeamColor,
                ];
                $theirValue += $c['value'];
            }

            foreach ($offer['picks'] as $c) {
                $pk = $c['pick'];
                $formatted = $this->formatPickLabel($pk, $c['value']);
                $theySendPicks[] = $formatted;
                $theirValue += $c['value'];
            }

            if (empty($theySendPlayers) && empty($theySendPicks)) continue;

            // Build user's send side
            $userTeamData = $userBrain->getTeam();
            $userTeamColor = $userTeamData['primary_color'] ?? null;
            $youSendPlayers = [[
                'id' => (int) $player['id'],
                'name' => ($player['first_name'] ?? '') . ' ' . ($player['last_name'] ?? ''),
                'position' => $player['position'],
                'overall_rating' => (int) $player['overall_rating'],
                'age' => (int) $player['age'],
                'trade_value' => round($baseValue, 1),
                'is_selected' => true,
                'image_url' => $player['image_url'] ?? null,
                'dev_trait' => $player['potential'] ?? null,
                'speed' => (int) ($player['speed'] ?? 0),
                'team_color' => $userTeamColor,
            ]];
            $youSendPicks = [];
            $youSendTotal = $baseValue;

            // Check if the deal needs extras from the user side
            if ($theirValue < $baseValue * 0.85) {
                // Their offer is low — this trade might not work without user adding
                // For now, skip (the sweeten mechanism handles this)
            }

            $youSend = ['players' => $youSendPlayers, 'picks' => $youSendPicks, 'total_value' => round($youSendTotal, 1)];
            $theySend = ['players' => $theySendPlayers, 'picks' => $theySendPicks, 'total_value' => round($theirValue, 1)];

            // Fairness
            $minSide = min($youSendTotal, $theirValue);
            $maxSide = max($youSendTotal, $theirValue, 1);
            $fairness = $minSide / $maxSide;
            if ($fairness < 0.60) continue;

            // Package type
            $packageType = $this->classifyPackageType($youSend, $theySend);

            // Interest label
            $interestLabel = match (true) {
                $interest['interest_score'] >= 0.50 => 'high',
                $interest['interest_score'] >= 0.25 => 'medium',
                default => 'low',
            };

            // Their reason (from their perspective)
            $reason = implode('. ', array_slice($interest['reasons'], 0, 2));

            $opportunities[] = [
                'team' => [
                    'id' => (int) $team['id'],
                    'city' => $team['city'],
                    'name' => $team['name'],
                    'abbreviation' => $team['abbreviation'],
                    'primary_color' => $team['primary_color'],
                    'secondary_color' => $team['secondary_color'],
                ],
                'interest' => $interestLabel,
                'interest_score' => round($interest['interest_score'], 3),
                'package_type' => $packageType,
                'you_send' => $youSend,
                'they_send' => $theySend,
                'fairness' => round($fairness, 2),
                'reason' => $reason,
                'team_mode' => $brain->getMode(),
                'gm_personality' => $brain->getGmPersonality(),
                'gm_note' => '', // filled after needs tagging
            ];
        }

        // ── User's top needs ─────────────────────────────────────────
        $userTopNeeds = $userBrain->getTopNeeds(5);

        // Tag incoming players that fill needs + generate GM advice
        $userBestOvrByPos = [];
        foreach ($userBrain->getNeeds() as $pos => $score) {
            $userBestOvrByPos[$pos] = $userBrain->getBestOvrAt($pos);
        }

        foreach ($opportunities as &$opp) {
            foreach ($opp['they_send']['players'] as &$tp) {
                $tp['fills_need'] = in_array($tp['position'], $userNeedPositions);
            }
            unset($tp);

            $opp['gm_note'] = $userBrain->adviseOnTrade($opp, $player, $userBestOvrByPos);
        }
        unset($opp);

        // Sort by deal quality for the user
        usort($opportunities, function ($a, $b) {
            return $this->scoreDealQuality($b) <=> $this->scoreDealQuality($a);
        });

        $opportunities = array_slice($opportunities, 0, 10);

        return [
            'player' => [
                'id' => (int) $player['id'],
                'name' => ($player['first_name'] ?? '') . ' ' . ($player['last_name'] ?? ''),
                'position' => $player['position'],
                'overall_rating' => (int) $player['overall_rating'],
                'age' => (int) $player['age'],
                'trade_value' => round($baseValue, 1),
                'image_url' => $player['image_url'] ?? null,
                'dev_trait' => $player['potential'] ?? null,
            ],
            'team_needs' => $userTopNeeds,
            'opportunities' => $opportunities,
            'leverage' => [
                'interested_teams' => $interestedCount,
                'multiplier' => $leverageMultiplier,
            ],
        ];
    }

    // ════════════════════════════════════════════════════════════════════
    //  ACQUIRE PLAYER — "I want THEIR player, what do I need to send?"
    // ════════════════════════════════════════════════════════════════════

    /**
     * Find what it would cost to acquire a player from another team.
     * The opposing team's brain evaluates: would they trade this player?
     * If yes, what would they need from the user?
     */
    public function findAcquisitionPackages(int $targetPlayerId, int $userTeamId): array
    {
        $targetPlayer = $this->getPlayer($targetPlayerId);
        if (!$targetPlayer) return ['player' => null, 'packages' => []];

        $targetTeamId = (int) ($targetPlayer['team_id'] ?? 0);
        if ($targetTeamId === $userTeamId || $targetTeamId === 0) {
            return ['player' => null, 'packages' => []];
        }

        $leagueId = (int) $targetPlayer['league_id'];
        $baseValue = $this->calculatePlayerValue($targetPlayer, $targetPlayerId);

        // Load league context
        $ctx = $this->loadLeagueContext($leagueId);
        $brains = $ctx['brains'];

        $opponentBrain = $brains[$targetTeamId] ?? null;
        $userBrain = $brains[$userTeamId] ?? null;
        if (!$opponentBrain || !$userBrain) return ['player' => null, 'packages' => []];

        // Would the opposing team trade this player?
        $assessment = $opponentBrain->assessTradeCandidate($targetPlayer, $baseValue);

        if (!$assessment['available']) {
            return [
                'player' => [
                    'id' => (int) $targetPlayer['id'],
                    'name' => ($targetPlayer['first_name'] ?? '') . ' ' . ($targetPlayer['last_name'] ?? ''),
                    'position' => $targetPlayer['position'],
                    'overall_rating' => (int) $targetPlayer['overall_rating'],
                    'age' => (int) $targetPlayer['age'],
                    'trade_value' => round($baseValue, 1),
                    'image_url' => $targetPlayer['image_url'] ?? null,
                    'dev_trait' => $targetPlayer['potential'] ?? null,
                ],
                'available' => false,
                'reason' => $assessment['reason'],
                'packages' => [],
                'team' => [
                    'id' => (int) $opponentBrain->getTeam()['id'],
                    'city' => $opponentBrain->getTeam()['city'],
                    'name' => $opponentBrain->getTeam()['name'],
                    'abbreviation' => $opponentBrain->getTeam()['abbreviation'],
                    'primary_color' => $opponentBrain->getTeam()['primary_color'],
                    'secondary_color' => $opponentBrain->getTeam()['secondary_color'],
                    'gm_personality' => $opponentBrain->getGmPersonality(),
                    'mode' => $opponentBrain->getMode(),
                ],
            ];
        }

        $askingPrice = $assessment['asking_price'];

        // Now build packages from the USER's roster that the opponent would accept
        $valueCalc = fn(array $p) => $this->calculatePlayerValue($p);
        $opponentNeeds = $opponentBrain->getNeeds();

        // User brain builds offers (what we could send)
        $packages = [];

        // Get user's tradeable players
        $userRoster = $userBrain->getRoster();
        $candidates = [];
        foreach ($userRoster as $p) {
            $pValue = $valueCalc($p);
            if ($pValue < 1.0) continue;
            $userAssess = $userBrain->assessTradeCandidate($p, $pValue);
            if (!$userAssess['available']) continue;

            $fillsOpponentNeed = ($opponentNeeds[$p['position']] ?? 0) >= 0.15;

            $candidates[] = [
                'player' => $p,
                'value' => $pValue,
                'fills_need' => $fillsOpponentNeed,
                'relevance' => ($fillsOpponentNeed ? 40 : 0) + ($pValue * 0.3),
            ];
        }
        usort($candidates, fn($a, $b) => $b['relevance'] <=> $a['relevance']);

        // Get user's picks
        $userPicks = $userBrain->getDraftPicks();
        $pickCandidates = [];
        foreach ($userPicks as $pk) {
            $round = (int) $pk['round'];
            $pickInRound = (((int) ($pk['pick_number'] ?? 16)) - 1) % 32 + 1;
            $yearsOut = max(0, (int) ($pk['year'] ?? $ctx['league_year']) - $ctx['league_year']);
            $pkValue = $this->evaluateDraftPick($round, $pickInRound, $yearsOut);
            $pickCandidates[] = ['pick' => $pk, 'value' => $pkValue];
        }
        usort($pickCandidates, fn($a, $b) => $b['value'] <=> $a['value']);

        // ── Build BIDIRECTIONAL packages ────────────────────────────────
        // Packages can go both ways:
        //   "You send player A for Jenkins"
        //   "You send player A, they add Joe Flacco + Jenkins for your star"
        //   "You send a pick for Jenkins"
        //   "They send Jenkins + depth piece, you send your starter"

        $targetMin = $askingPrice * 0.80;
        $targetMax = $askingPrice * 1.20;

        // Get opponent's tradeable depth pieces (they might sweeten with extras)
        $opponentSweeteners = [];
        $opponentRoster = $opponentBrain->getRoster();
        foreach ($opponentRoster as $opp) {
            if ((int) $opp['id'] === $targetPlayerId) continue; // skip the target player
            $oppVal = $valueCalc($opp);
            $oppAssess = $opponentBrain->assessTradeCandidate($opp, $oppVal);
            if ($oppAssess['available'] && $oppAssess['premium'] <= 1.3) {
                $opponentSweeteners[] = ['player' => $opp, 'value' => $oppVal];
            }
        }
        usort($opponentSweeteners, fn($a, $b) => $a['value'] <=> $b['value']); // lowest value first

        // Get opponent's picks
        $opponentPicks = $opponentBrain->getDraftPicks();
        $opponentPickCandidates = [];
        foreach ($opponentPicks as $pk) {
            $round = (int) $pk['round'];
            $pickInRound = (((int) ($pk['pick_number'] ?? 16)) - 1) % 32 + 1;
            $yearsOut = max(0, (int) ($pk['year'] ?? $ctx['league_year']) - $ctx['league_year']);
            $pkValue = $this->evaluateDraftPick($round, $pickInRound, $yearsOut);
            $opponentPickCandidates[] = ['pick' => $pk, 'value' => $pkValue];
        }
        usort($opponentPickCandidates, fn($a, $b) => $a['value'] <=> $b['value']);

        // ── Generate package options ──
        // Goal: create diverse, non-redundant packages ranked by cost to user

        // A) Single pick options — try each individual pick that meets the threshold
        foreach ($pickCandidates as $pk) {
            if ($pk['value'] >= $targetMin) {
                $packages[] = $this->formatAcquisitionPackage([], [$pk], $pk['value'], [], []);
            }
        }

        // B) Pick combos — 2 lower picks together (e.g., R3 + R4)
        for ($i = 0; $i < count($pickCandidates); $i++) {
            if ($pickCandidates[$i]['value'] >= $targetMin) continue; // already covered as single
            for ($j = $i + 1; $j < count($pickCandidates); $j++) {
                $comboVal = $pickCandidates[$i]['value'] + $pickCandidates[$j]['value'];
                if ($comboVal >= $targetMin && $comboVal <= $targetMax * 1.3) {
                    $packages[] = $this->formatAcquisitionPackage([], [$pickCandidates[$i], $pickCandidates[$j]], $comboVal, [], []);
                    break; // one combo per starting pick
                }
            }
        }

        // C) Single player match
        foreach ($candidates as $c) {
            if ($c['value'] >= $targetMin && $c['value'] <= $targetMax) {
                $packages[] = $this->formatAcquisitionPackage([$c], [], $c['value'], [], []);
            }
            if (count($packages) >= 8) break; // don't generate too many
        }

        // D) Player + pick (for when single player isn't quite enough)
        foreach ($candidates as $c) {
            if ($c['value'] >= $targetMin) continue; // already works alone
            if ($c['value'] < $askingPrice * 0.25) continue; // too small to matter
            $gap = $askingPrice - $c['value'];
            // Find smallest pick to fill the gap
            foreach ($pickCandidates as $pk) {
                if ($pk['value'] >= $gap * 0.65 && $pk['value'] <= $gap * 1.5) {
                    $total = $c['value'] + $pk['value'];
                    if ($total >= $targetMin) {
                        $packages[] = $this->formatAcquisitionPackage([$c], [$pk], $total, [], []);
                        break; // one combo per player
                    }
                }
            }
            if (count($packages) >= 10) break;
        }

        // E) BIDIRECTIONAL — they add sweeteners (Jenkins + depth/pick for your star)
        foreach ($candidates as $c) {
            if ($c['value'] <= $askingPrice * 1.3) continue; // only for higher-value user players
            if ($c['value'] > $askingPrice * 5.0) continue;

            $deficit = $c['value'] - $askingPrice;

            // Try opponent players as sweetener
            foreach ($opponentSweeteners as $sw) {
                if ($sw['value'] >= $deficit * 0.5 && $sw['value'] <= $deficit * 1.5) {
                    $packages[] = $this->formatAcquisitionPackage(
                        [$c], [], $c['value'],
                        [$sw], []
                    );
                    break;
                }
            }

            // Try opponent picks as sweetener
            foreach ($opponentPickCandidates as $pk) {
                if ($pk['value'] >= $deficit * 0.4 && $pk['value'] <= $deficit * 1.5) {
                    $packages[] = $this->formatAcquisitionPackage(
                        [$c], [], $c['value'],
                        [], [$pk]
                    );
                    break;
                }
            }

            // Try opponent player + pick
            if ($deficit > 5) {
                foreach ($opponentSweeteners as $sw) {
                    foreach ($opponentPickCandidates as $opk) {
                        $combo = $sw['value'] + $opk['value'];
                        if ($combo >= $deficit * 0.7 && $combo <= $deficit * 1.4) {
                            $packages[] = $this->formatAcquisitionPackage(
                                [$c], [], $c['value'],
                                [$sw], [$opk]
                            );
                            break 2;
                        }
                    }
                }
            }

            if (count($packages) >= 12) break;
        }

        // F) Multi-player (2 depth pieces for Jenkins)
        if (count($candidates) >= 2) {
            $multi = []; $multiVal = 0;
            foreach ($candidates as $c) {
                if (count($multi) >= 2) break;
                if ($multiVal + $c['value'] > $targetMax * 1.1) continue;
                $multi[] = $c;
                $multiVal += $c['value'];
            }
            if ($multiVal >= $targetMin && count($multi) >= 2) {
                $packages[] = $this->formatAcquisitionPackage($multi, [], $multiVal, [], []);
            }
        }

        // ── Eliminate dominated packages ──
        // If package A gives away strictly more than package B for the same return, remove A
        $filtered = [];
        foreach ($packages as $pkg) {
            $dominated = false;
            foreach ($packages as $other) {
                if ($pkg === $other) continue;
                // $other dominates $pkg if $other costs less AND gives back same or more
                $otherTheyGet = $other['you_send_value'] ?? $other['total_value'];
                $pkgTheyGet = $pkg['you_send_value'] ?? $pkg['total_value'];
                $otherYouGet = $other['they_send_value'] ?? 0;
                $pkgYouGet = $pkg['they_send_value'] ?? 0;
                if ($otherTheyGet < $pkgTheyGet && $otherYouGet >= $pkgYouGet) {
                    $dominated = true;
                    break;
                }
            }
            if (!$dominated) $filtered[] = $pkg;
        }
        $packages = $filtered;

        // ── Sort: best deal for USER first (lowest net cost) ──
        usort($packages, function ($a, $b) {
            $netA = ($a['you_send_value'] ?? $a['total_value']) - ($a['they_send_value'] ?? 0);
            $netB = ($b['you_send_value'] ?? $b['total_value']) - ($b['they_send_value'] ?? 0);
            return $netA <=> $netB; // lowest net cost first
        });

        // ── Add fairness + GM note per package ──
        $userBestOvrByPos = [];
        foreach ($userBrain->getNeeds() as $pos => $score) {
            $userBestOvrByPos[$pos] = $userBrain->getBestOvrAt($pos);
        }

        foreach ($packages as &$pkg) {
            $youSendVal = $pkg['you_send_value'] ?? $pkg['total_value'];
            $youGetVal = $askingPrice + ($pkg['they_send_value'] ?? 0);
            $maxSide = max($youSendVal, $youGetVal, 1);
            $minSide = min($youSendVal, $youGetVal);
            $pkg['fairness'] = round($minSide / $maxSide, 2);
            $pkg['you_get_value'] = round($youGetVal, 1);

            // GM note — for acquire mode, the "traded player" is the highest-value
            // player WE are giving up, and "they_send" includes the target player.
            // Find the most valuable player we're sending as the "traded player" for the GM.
            $gmTradedPlayer = null;
            $gmTradedVal = 0;
            foreach ($pkg['players'] as $gp) {
                $gpVal = (float) ($gp['trade_value'] ?? 0);
                if ($gpVal > $gmTradedVal) {
                    $gmTradedVal = $gpVal;
                    $gmTradedPlayer = [
                        'first_name' => explode(' ', $gp['name'])[0] ?? '',
                        'last_name' => implode(' ', array_slice(explode(' ', $gp['name']), 1)),
                        'position' => $gp['position'],
                        'overall_rating' => $gp['overall_rating'],
                        'age' => $gp['age'],
                    ];
                }
            }
            // If we're only sending picks, create a dummy "traded player" descriptor
            if (!$gmTradedPlayer) {
                $pickLabels = array_map(fn($pk) => $pk['label'] ?? "Round {$pk['round']}", $pkg['picks']);
                $gmTradedPlayer = [
                    'first_name' => 'our',
                    'last_name' => implode(' + ', $pickLabels),
                    'position' => 'PICK',
                    'overall_rating' => 0,
                    'age' => 0,
                ];
            }

            $targetName = ($targetPlayer['first_name'] ?? '') . ' ' . ($targetPlayer['last_name'] ?? '');
            $targetPos = $targetPlayer['position'];
            $targetOvr = (int) $targetPlayer['overall_rating'];
            $targetAge = (int) $targetPlayer['age'];
            $targetFills = ($userBrain->getNeeds()[$targetPos] ?? 0) >= 0.15;

            // Build receiving side with fills_need flags from USER perspective
            $receivePlayers = [
                ['position' => $targetPos, 'overall_rating' => $targetOvr, 'age' => $targetAge, 'name' => $targetName, 'fills_need' => $targetFills],
            ];
            foreach (($pkg['they_also_send_players'] ?? []) as $sw) {
                $swPos = $sw['position'];
                $sw['fills_need'] = ($userBrain->getNeeds()[$swPos] ?? 0) >= 0.15;
                $receivePlayers[] = $sw;
            }

            $gmOpp = [
                'you_send' => ['total_value' => $youSendVal, 'players' => $pkg['players'], 'picks' => $pkg['picks']],
                'they_send' => ['total_value' => $youGetVal, 'players' => $receivePlayers, 'picks' => $pkg['they_also_send_picks'] ?? []],
                'fairness' => $pkg['fairness'],
            ];
            $pkg['gm_note'] = $userBrain->adviseOnTrade($gmOpp, $gmTradedPlayer, $userBestOvrByPos);
        }
        unset($pkg);

        $opTeam = $opponentBrain->getTeam();

        $targetFillsUserNeed = ($userBrain->getNeeds()[$targetPlayer['position']] ?? 0) >= 0.15;
        $targetContract = $this->getContract($targetPlayerId);

        return [
            'player' => [
                'id' => (int) $targetPlayer['id'],
                'name' => ($targetPlayer['first_name'] ?? '') . ' ' . ($targetPlayer['last_name'] ?? ''),
                'position' => $targetPlayer['position'],
                'overall_rating' => (int) $targetPlayer['overall_rating'],
                'age' => (int) $targetPlayer['age'],
                'trade_value' => round($baseValue, 1),
                'image_url' => $targetPlayer['image_url'] ?? null,
                'dev_trait' => $targetPlayer['potential'] ?? null,
                'fills_need' => $targetFillsUserNeed,
                'salary' => $targetContract ? (int) ($targetContract['cap_hit'] ?? $targetContract['salary_annual'] ?? 0) : null,
                'contract_years' => $targetContract ? (int) ($targetContract['years_remaining'] ?? 0) : null,
            ],
            'available' => true,
            'asking_price' => round($askingPrice, 1),
            'reason' => $assessment['reason'],
            'team' => [
                'id' => (int) $opTeam['id'],
                'city' => $opTeam['city'],
                'name' => $opTeam['name'],
                'abbreviation' => $opTeam['abbreviation'],
                'primary_color' => $opTeam['primary_color'],
                'secondary_color' => $opTeam['secondary_color'],
                'gm_personality' => $opponentBrain->getGmPersonality(),
                'mode' => $opponentBrain->getMode(),
            ],
            'their_needs' => $opponentBrain->getTopNeeds(5),
            'user_team_color' => $userBrain->getTeam()['primary_color'] ?? null,
            'opponent_team_color' => $opTeam['primary_color'] ?? null,
            'packages' => array_slice($packages, 0, 6),
        ];
    }

    private function formatAcquisitionPackage(
        array $youSendPlayers, array $youSendPicks, float $youSendValue,
        array $theySendPlayers = [], array $theySendPicks = []
    ): array {
        $players = [];
        foreach ($youSendPlayers as $c) {
            $p = $c['player'];
            // Get contract for cap info
            $contract = $this->getContract((int) $p['id']);
            $players[] = [
                'id' => (int) $p['id'],
                'name' => ($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''),
                'position' => $p['position'],
                'overall_rating' => (int) $p['overall_rating'],
                'age' => (int) $p['age'],
                'trade_value' => round($c['value'], 1),
                'fills_need' => $c['fills_need'] ?? false,
                'image_url' => $p['image_url'] ?? null,
                'dev_trait' => $p['potential'] ?? null,
                'salary' => $contract ? (int) ($contract['cap_hit'] ?? $contract['salary_annual'] ?? 0) : null,
                'contract_years' => $contract ? (int) ($contract['years_remaining'] ?? 0) : null,
            ];
        }

        $picks = [];
        foreach ($youSendPicks as $c) {
            $pk = $c['pick'];
            $picks[] = $this->formatPickLabel($pk, $c['value']);
        }

        // They send (beyond the target player) — sweeteners
        $theySendPlayersList = [];
        $theySendValue = 0;
        foreach ($theySendPlayers as $c) {
            $p = $c['player'];
            $contract = $this->getContract((int) $p['id']);
            $theySendPlayersList[] = [
                'id' => (int) $p['id'],
                'name' => ($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''),
                'position' => $p['position'],
                'overall_rating' => (int) $p['overall_rating'],
                'age' => (int) $p['age'],
                'trade_value' => round($c['value'], 1),
                'image_url' => $p['image_url'] ?? null,
                'dev_trait' => $p['potential'] ?? null,
                'salary' => $contract ? (int) ($contract['cap_hit'] ?? $contract['salary_annual'] ?? 0) : null,
                'contract_years' => $contract ? (int) ($contract['years_remaining'] ?? 0) : null,
            ];
            $theySendValue += $c['value'];
        }

        $theySendPicksList = [];
        foreach ($theySendPicks as $c) {
            $pk = $c['pick'];
            $theySendPicksList[] = $this->formatPickLabel($pk, $c['value']);
            $theySendValue += $c['value'];
        }

        return [
            'players' => $players,
            'picks' => $picks,
            'total_value' => round($youSendValue, 1),
            'you_send_value' => round($youSendValue, 1),
            'they_also_send_players' => $theySendPlayersList,
            'they_also_send_picks' => $theySendPicksList,
            'they_send_value' => round($theySendValue, 1),
        ];
    }

    /**
     * Build a fully descriptive pick label including original team, pick number, and via info.
     */
    private function formatPickLabel(array $pk, float $value): array
    {
        $pickId = (int) $pk['id'];
        $round = (int) $pk['round'];
        $year = (int) ($pk['year'] ?? 2026);
        $pickNumber = (int) ($pk['pick_number'] ?? 0);

        // Get original team info
        $origTeamId = (int) ($pk['original_team_id'] ?? 0);
        $currTeamId = (int) ($pk['current_team_id'] ?? 0);
        $origAbbr = $pk['original_team_abbr'] ?? null;

        if (!$origAbbr && $origTeamId) {
            $stmt = $this->db->prepare("SELECT abbreviation FROM teams WHERE id = ?");
            $stmt->execute([$origTeamId]);
            $origAbbr = $stmt->fetchColumn() ?: null;
        }

        $isVia = ($origTeamId !== $currTeamId && $origAbbr);

        $label = "{$year} Round {$round}";
        if ($pickNumber > 0) {
            $label .= " (#{$pickNumber})";
        }
        if ($isVia) {
            $label .= " via {$origAbbr}";
        }

        return [
            'id' => $pickId,
            'label' => $label,
            'round' => $round,
            'year' => $year,
            'pick_number' => $pickNumber,
            'original_team' => $origAbbr,
            'is_via' => $isVia,
            'trade_value' => round($value, 1),
        ];
    }

    /**
     * Score a trade opportunity for sorting — best deals for the user first.
     */
    /**
     * Score a deal for sorting — best deals for the USER first.
     * Prioritizes: value you receive, need-filling, player quality.
     */
    private function scoreDealQuality(array $opp): float
    {
        $theyGive = (float) ($opp['they_send']['total_value'] ?? 0);
        $youGive = (float) ($opp['you_send']['total_value'] ?? 0);

        // 1. Value you receive (50 points max) — more value back = better deal
        $valueScore = min(50, $theyGive * 2);

        // 2. Need-filling bonus (25 points max) — fills roster holes
        $needScore = 0;
        foreach ($opp['they_send']['players'] as $p) {
            if (!empty($p['fills_need'])) $needScore += 12;
        }
        $needScore = min(25, $needScore);

        // 3. Player quality (15 points max) — higher OVR players are better
        $qualityScore = 0;
        foreach ($opp['they_send']['players'] as $p) {
            $ovr = (int) ($p['overall_rating'] ?? 0);
            if ($ovr >= 85) $qualityScore += 8;
            elseif ($ovr >= 78) $qualityScore += 5;
            elseif ($ovr >= 72) $qualityScore += 2;
        }
        $qualityScore = min(15, $qualityScore);

        // 4. Value surplus bonus (10 points max) — getting more than you give
        $surplus = $theyGive - $youGive;
        $surplusScore = max(0, min(10, $surplus * 1.5));

        return $valueScore + $needScore + $qualityScore + $surplusScore;
    }

    // ════════════════════════════════════════════════════════════════════
    //  SWEETEN DEAL — Team re-thinks their offer
    // ════════════════════════════════════════════════════════════════════

    /**
     * Ask the opposing team to sweeten their deal.
     * They THINK about it — not a random algorithm.
     */
    public function sweetenDeal(int $playerId, int $opposingTeamId, int $userTeamId, array $currentPlayerIds, array $currentPickIds): array
    {
        $player = $this->getPlayer($playerId);
        if (!$player) return ['sweetened' => false, 'reason' => 'Player not found'];

        $leagueId = (int) $player['league_id'];
        $baseValue = $this->calculatePlayerValue($player, $playerId);

        // Load league context and get the opposing team's brain
        $ctx = $this->loadLeagueContext($leagueId);
        $brains = $ctx['brains'];

        $opponentBrain = $brains[$opposingTeamId] ?? null;
        $userBrain = $brains[$userTeamId] ?? null;

        if (!$opponentBrain || !$userBrain) {
            return ['sweetened' => false, 'reason' => 'Team not found'];
        }

        // Calculate current offer value
        $currentOfferValue = 0;
        foreach ($currentPlayerIds as $pid) {
            $p = $this->getPlayer((int) $pid);
            if ($p) $currentOfferValue += $this->calculatePlayerValue($p, (int) $pid);
        }
        foreach ($currentPickIds as $pid) {
            $pick = $this->getDraftPick((int) $pid);
            if ($pick) {
                $pickInRound = (((int) $pick['pick_number'] - 1) % 32) + 1;
                $currentOfferValue += $this->evaluateDraftPick((int) $pick['round'], $pickInRound);
            }
        }

        // User's asking price (what the player is worth to sell)
        $userAssessment = $userBrain->assessTradeCandidate($player, $baseValue);

        // Opposing team re-thinks: "Can we add more? Is it worth it?"
        $valueCalc = fn(array $p) => $this->calculatePlayerValue($p);
        $result = $opponentBrain->reconsiderOffer(
            $currentOfferValue,
            $userAssessment['asking_price'],
            $baseValue,
            $currentPlayerIds,
            $currentPickIds,
            $valueCalc
        );

        return $result;
    }

    // ════════════════════════════════════════════════════════════════════
    //  AI-INITIATED TRADES — CPU teams make offers to the user
    // ════════════════════════════════════════════════════════════════════

    /**
     * Generate incoming trade offers from AI teams to the user.
     * Each AI team scans its roster for trade candidates, then looks
     * at the user's roster for players they want.
     */
    public function generateIncomingOffers(int $leagueId, int $userTeamId): array
    {
        $ctx = $this->loadLeagueContext($leagueId);
        $brains = $ctx['brains'];
        $userBrain = $brains[$userTeamId] ?? null;
        if (!$userBrain) return [];

        $valueCalc = fn(array $p) => $this->calculatePlayerValue($p);
        $incomingOffers = [];

        foreach ($brains as $tid => $brain) {
            if ($tid === $userTeamId) continue;

            // What does this AI team want from the user?
            $aiNeeds = $brain->getNeeds();
            $userRoster = $userBrain->getRoster();

            // Find user players this team is interested in
            foreach ($userRoster as $userPlayer) {
                $pos = $userPlayer['position'];
                $baseVal = $this->calculatePlayerValue($userPlayer);

                $interest = $brain->isInterestedIn($userPlayer, $baseVal);
                if (!$interest['interested'] || $interest['interest_score'] < 0.30) continue;

                // Build what they'd offer
                $offer = $brain->buildOfferFor(
                    $userPlayer, $baseVal * 1.1, // slightly overpay to initiate
                    $userBrain->getNeeds(), $valueCalc
                );

                if (!$offer || $offer['total_value'] < $baseVal * 0.75) continue;

                // Format the offer
                $offerPlayers = [];
                foreach ($offer['players'] as $c) {
                    $p = $c['player'];
                    $offerPlayers[] = [
                        'id' => (int) $p['id'],
                        'name' => ($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''),
                        'position' => $p['position'],
                        'overall_rating' => (int) $p['overall_rating'],
                        'age' => (int) $p['age'],
                        'trade_value' => round($c['value'], 1),
                    ];
                }

                $offerPicks = [];
                foreach ($offer['picks'] as $c) {
                    $pk = $c['pick'];
                    $offerPicks[] = [
                        'id' => (int) $pk['id'],
                        'label' => ((int) ($pk['year'] ?? 2026)) . ' Round ' . ((int) $pk['round']),
                        'round' => (int) $pk['round'],
                        'trade_value' => round($c['value'], 1),
                    ];
                }

                $team = $brain->getTeam();
                $incomingOffers[] = [
                    'from_team' => [
                        'id' => (int) $team['id'],
                        'city' => $team['city'],
                        'name' => $team['name'],
                        'abbreviation' => $team['abbreviation'],
                        'primary_color' => $team['primary_color'],
                        'secondary_color' => $team['secondary_color'],
                    ],
                    'target_player' => [
                        'id' => (int) $userPlayer['id'],
                        'name' => ($userPlayer['first_name'] ?? '') . ' ' . ($userPlayer['last_name'] ?? ''),
                        'position' => $pos,
                        'overall_rating' => (int) $userPlayer['overall_rating'],
                    ],
                    'they_offer' => ['players' => $offerPlayers, 'picks' => $offerPicks, 'total_value' => round($offer['total_value'], 1)],
                    'interest_score' => $interest['interest_score'],
                    'reason' => implode('. ', $interest['reasons']),
                    'team_mode' => $brain->getMode(),
                    'gm_personality' => $brain->getGmPersonality(),
                ];

                break; // One offer per AI team max
            }
        }

        // Sort by interest score
        usort($incomingOffers, fn($a, $b) => $b['interest_score'] <=> $a['interest_score']);
        return array_slice($incomingOffers, 0, 5);
    }

    // ════════════════════════════════════════════════════════════════════
    //  TRADE LIFECYCLE — Propose, evaluate, execute
    // ════════════════════════════════════════════════════════════════════

    public function listTrades(int $leagueId): array
    {
        $stmt = $this->db->prepare(
            "SELECT t.*,
                    pt.city as prop_city, pt.name as prop_name, pt.abbreviation as prop_abbr,
                    pt.primary_color as prop_primary, pt.secondary_color as prop_secondary,
                    rt.city as recv_city, rt.name as recv_name, rt.abbreviation as recv_abbr,
                    rt.primary_color as recv_primary, rt.secondary_color as recv_secondary
             FROM trades t
             JOIN teams pt ON t.proposing_team_id = pt.id
             JOIN teams rt ON t.receiving_team_id = rt.id
             WHERE t.league_id = ?
             ORDER BY t.proposed_at DESC"
        );
        $stmt->execute([$leagueId]);
        $trades = $stmt->fetchAll();

        foreach ($trades as &$trade) {
            // Build team objects the frontend expects
            $trade['proposing_team'] = [
                'id' => (int) $trade['proposing_team_id'],
                'city' => $trade['prop_city'],
                'name' => $trade['prop_name'],
                'abbreviation' => $trade['prop_abbr'],
                'primary_color' => $trade['prop_primary'],
                'secondary_color' => $trade['prop_secondary'],
            ];
            $trade['receiving_team'] = [
                'id' => (int) $trade['receiving_team_id'],
                'city' => $trade['recv_city'],
                'name' => $trade['recv_name'],
                'abbreviation' => $trade['recv_abbr'],
                'primary_color' => $trade['recv_primary'],
                'secondary_color' => $trade['recv_secondary'],
            ];

            $stmt2 = $this->db->prepare("SELECT * FROM trade_items WHERE trade_id = ?");
            $stmt2->execute([$trade['id']]);
            $items = $stmt2->fetchAll();

            $trade['offered_players'] = [];
            $trade['requested_players'] = [];
            $trade['offered_picks'] = [];
            $trade['requested_picks'] = [];

            foreach ($items as $item) {
                if ($item['item_type'] === 'player' && $item['player_id']) {
                    $p = $this->getPlayer((int) $item['player_id']);
                    if ($p) {
                        $pData = [
                            'id' => (int) $p['id'],
                            'first_name' => $p['first_name'],
                            'last_name' => $p['last_name'],
                            'position' => $p['position'],
                            'overall_rating' => (int) $p['overall_rating'],
                            'age' => (int) $p['age'],
                        ];
                        if ($item['direction'] === 'outgoing') $trade['offered_players'][] = $pData;
                        else $trade['requested_players'][] = $pData;
                    }
                }
                if ($item['item_type'] === 'draft_pick' && $item['draft_pick_id']) {
                    $pick = $this->getDraftPick((int) $item['draft_pick_id']);
                    if ($pick) {
                        // Get year from draft_class
                        $dcStmt = $this->db->prepare(
                            "SELECT dc.year FROM draft_picks dp JOIN draft_classes dc ON dp.draft_class_id = dc.id WHERE dp.id = ?"
                        );
                        $dcStmt->execute([(int) $item['draft_pick_id']]);
                        $dcRow = $dcStmt->fetch();
                        $year = $dcRow ? (int) $dcRow['year'] : 2026;

                        $pkData = [
                            'id' => (int) $pick['id'],
                            'round' => (int) $pick['round'],
                            'pick_number' => (int) $pick['pick_number'],
                            'year' => $year,
                            'label' => $year . ' Round ' . (int) $pick['round'],
                        ];
                        if ($item['direction'] === 'outgoing') $trade['offered_picks'][] = $pkData;
                        else $trade['requested_picks'][] = $pkData;
                    }
                }
            }
        }
        unset($trade);

        return $trades;
    }

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
        $fairThreshold = $maxVal * 0.25;

        return [
            'fair' => $diff <= $fairThreshold,
            'proposing_value' => round($proposingValue, 1),
            'receiving_value' => round($receivingValue, 1),
            'difference' => round($diff, 1),
            'advantage' => $proposingValue > $receivingValue ? 'proposing' : 'receiving',
        ];
    }

    public function proposeTrade(int $leagueId, int $proposingTeamId, int $receivingTeamId, array $proposingItems, array $receivingItems): array
    {
        $evaluation = $this->evaluateTrade($proposingItems, $receivingItems);

        $this->db->prepare(
            "INSERT INTO trades (league_id, proposing_team_id, receiving_team_id, status, proposed_at) VALUES (?, ?, ?, 'proposed', ?)"
        )->execute([$leagueId, $proposingTeamId, $receivingTeamId, date('Y-m-d H:i:s')]);
        $tradeId = (int) $this->db->lastInsertId();

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
     * AI decides whether to accept a trade — using TeamBrain.
     * Returns full decision with reason, not just the action string.
     */
    public function aiEvaluateTrade(int $tradeId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM trades WHERE id = ?");
        $stmt->execute([$tradeId]);
        $trade = $stmt->fetch();
        if (!$trade) return ['decision' => 'rejected', 'reason' => 'Trade not found.'];

        $leagueId = (int) $trade['league_id'];
        $receivingTeamId = (int) $trade['receiving_team_id'];

        $ctx = $this->loadLeagueContext($leagueId);
        $brain = $ctx['brains'][$receivingTeamId] ?? null;

        if (!$brain) {
            $evaluation = $this->getTradeEvaluation($tradeId);
            if ($evaluation['advantage'] === 'proposing' && !$evaluation['fair']) {
                return ['decision' => 'rejected', 'reason' => "The value doesn't add up for us. We'd be giving up too much."];
            }
            return $evaluation['fair']
                ? ['decision' => 'accepted', 'reason' => 'Fair deal. We can work with this.']
                : ['decision' => 'counter', 'reason' => "We're close, but we'd need a little more to make this work."];
        }

        // Get trade items
        $stmt = $this->db->prepare("SELECT * FROM trade_items WHERE trade_id = ?");
        $stmt->execute([$tradeId]);
        $items = $stmt->fetchAll();

        $weGiveValue = 0;
        $weGetValue = 0;
        $weGetPlayers = [];
        $weGivePlayers = [];

        foreach ($items as $item) {
            $val = 0;
            $player = null;
            if ($item['item_type'] === 'player' && $item['player_id']) {
                $player = $this->getPlayer((int) $item['player_id']);
                if ($player) $val = $this->calculatePlayerValue($player, (int) $item['player_id']);
            } elseif ($item['item_type'] === 'draft_pick' && $item['draft_pick_id']) {
                $pick = $this->getDraftPick((int) $item['draft_pick_id']);
                if ($pick) $val = $this->evaluateDraftPick((int) $pick['round'], ((int) $pick['pick_number'] - 1) % 32 + 1);
            }

            if ($item['direction'] === 'incoming') {
                $weGiveValue += $val;
                if ($player) $weGivePlayers[] = $player;
            } else {
                $weGetValue += $val;
                if ($player) $weGetPlayers[] = $player;
            }
        }

        $result = $brain->evaluateIncomingOffer($weGetPlayers, $weGiveValue, $weGivePlayers, $weGetValue);

        // Generate counter-offer details if the decision is 'counter'
        if ($result['decision'] === 'counter') {
            $counter = $this->generateCounterOffer($tradeId);
            if ($counter) {
                $result['counter_offer'] = $counter;
            }
        }

        return $result;
    }

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
                    ? $trade['receiving_team_id'] : $trade['proposing_team_id'];
                $this->db->prepare("UPDATE players SET team_id = ? WHERE id = ?")
                    ->execute([$newTeam, $item['player_id']]);
            }
            if ($item['item_type'] === 'draft_pick' && $item['draft_pick_id']) {
                $pickId = (int) $item['draft_pick_id'];
                $oldTeam = $item['direction'] === 'outgoing'
                    ? $trade['proposing_team_id'] : $trade['receiving_team_id'];
                $newTeam = $item['direction'] === 'outgoing'
                    ? $trade['receiving_team_id'] : $trade['proposing_team_id'];

                $this->db->prepare("UPDATE draft_picks SET current_team_id = ? WHERE id = ?")
                    ->execute([$newTeam, $pickId]);

                // Log pick trade history (original_team_id stays the same — only current_team_id changes)
                $draftEngine = new DraftEngine();
                $draftEngine->logPickTrade($pickId, (int) $oldTeam, (int) $newTeam, $tradeId);
            }
        }

        $this->db->prepare("UPDATE trades SET status = 'completed', resolved_at = ? WHERE id = ?")
            ->execute([date('Y-m-d H:i:s'), $tradeId]);

        // Recalculate team ratings for both sides
        $ratingService = new TeamRatingService();
        $ratingService->recalculateMultiple([
            (int) $trade['proposing_team_id'],
            (int) $trade['receiving_team_id'],
        ]);

        return true;
    }

    /**
     * Generate a real counter-proposal using the receiving team's brain.
     * Instead of just "add a pick," the opposing GM builds what they'd actually accept.
     */
    public function generateCounterOffer(int $tradeId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM trades WHERE id = ?");
        $stmt->execute([$tradeId]);
        $trade = $stmt->fetch();
        if (!$trade) return null;

        $leagueId = (int) $trade['league_id'];
        $receivingTeamId = (int) $trade['receiving_team_id'];
        $proposingTeamId = (int) $trade['proposing_team_id'];

        // Get trade items to understand what they want from us
        $stmt = $this->db->prepare("SELECT * FROM trade_items WHERE trade_id = ?");
        $stmt->execute([$tradeId]);
        $items = $stmt->fetchAll();

        // Identify the primary player the proposing team wants (incoming = what receiving team gives)
        $targetPlayer = null;
        $weGiveValue = 0;
        $weGetValue = 0;
        $weGetPlayers = [];
        $weGetPicks = [];

        foreach ($items as $item) {
            $val = 0;
            if ($item['item_type'] === 'player' && $item['player_id']) {
                $player = $this->getPlayer((int) $item['player_id']);
                if ($player) $val = $this->calculatePlayerValue($player, (int) $item['player_id']);
            } elseif ($item['item_type'] === 'draft_pick' && $item['draft_pick_id']) {
                $pick = $this->getDraftPick((int) $item['draft_pick_id']);
                if ($pick) $val = $this->evaluateDraftPick((int) $pick['round'], ((int) $pick['pick_number'] - 1) % 32 + 1);
            }

            if ($item['direction'] === 'incoming') {
                // What the receiving team gives up (what proposing team wants)
                $weGiveValue += $val;
                if ($item['item_type'] === 'player' && !$targetPlayer) {
                    $targetPlayer = $this->getPlayer((int) $item['player_id']);
                }
            } else {
                // What the receiving team gets
                $weGetValue += $val;
                if ($item['item_type'] === 'player' && $item['player_id']) {
                    $p = $this->getPlayer((int) $item['player_id']);
                    if ($p) $weGetPlayers[] = $p;
                }
                if ($item['item_type'] === 'draft_pick' && $item['draft_pick_id']) {
                    $weGetPicks[] = (int) $item['draft_pick_id'];
                }
            }
        }

        $gap = $weGiveValue - $weGetValue;
        if ($gap <= 0) return null; // We'd already accept this

        // Load context and get the brain
        $ctx = $this->loadLeagueContext($leagueId);
        $brain = $ctx['brains'][$receivingTeamId] ?? null;
        if (!$brain) return null;

        $gmPersonality = $brain->getGmPersonality();
        $proposingTeamNeeds = ($ctx['brains'][$proposingTeamId] ?? null)?->getNeeds() ?? [];

        // Build the counter-offer: what ELSE would the receiving team need?
        // The brain figures out what additional assets from the proposing team would close the gap
        $currentPlayerIds = array_map(fn($p) => (int) $p['id'], $weGetPlayers);
        $valueCalcFn = fn($p) => $this->calculatePlayerValue($p, (int) $p['id']);

        $sweetenResult = $brain->reconsiderOffer(
            $weGetValue, $weGiveValue, $weGiveValue,
            $currentPlayerIds, $weGetPicks,
            $valueCalcFn
        );

        // Also try: the receiving team offers to add something from THEIR side
        // to rebalance — e.g., "We'll do this if you also include X, and we'll throw in Y"
        $counterPackage = [
            'original_trade_id' => $tradeId,
            'gap' => round($gap, 1),
            'gm_personality' => $gmPersonality,
        ];

        if ($sweetenResult['sweetened'] ?? false) {
            // The brain found something the proposing team could add
            if ($sweetenResult['type'] === 'added_pick') {
                $counterPackage['ask_addition'] = [
                    'type' => 'draft_pick',
                    'draft_pick_id' => $sweetenResult['added_pick']['id'],
                    'label' => $sweetenResult['added_pick']['label'],
                    'round' => $sweetenResult['added_pick']['round'],
                    'value' => $sweetenResult['added_pick']['trade_value'],
                ];
            } elseif ($sweetenResult['type'] === 'added_player') {
                $counterPackage['ask_addition'] = [
                    'type' => 'player',
                    'player_id' => $sweetenResult['added_player']['id'],
                    'name' => $sweetenResult['added_player']['name'],
                    'position' => $sweetenResult['added_player']['position'],
                    'overall_rating' => $sweetenResult['added_player']['overall_rating'],
                    'value' => $sweetenResult['added_player']['trade_value'],
                ];
            }
        } else {
            // Brain couldn't find a sweetener — try asking for any pick from proposing team
            $pickStmt = $this->db->prepare(
                "SELECT dp.* FROM draft_picks dp
                 WHERE dp.current_team_id = ? AND dp.is_used = 0
                 ORDER BY dp.round ASC, dp.pick_number ASC"
            );
            $pickStmt->execute([$proposingTeamId]);
            $availPicks = $pickStmt->fetchAll();

            foreach ($availPicks as $pk) {
                $pkValue = $this->evaluateDraftPick((int) $pk['round'], ((int) ($pk['pick_number'] ?? 16) - 1) % 32 + 1);
                if ($pkValue >= $gap * 0.5 && $pkValue <= $gap * 1.5) {
                    $year = (int) ($pk['year'] ?? date('Y'));
                    $round = (int) $pk['round'];
                    $counterPackage['ask_addition'] = [
                        'type' => 'draft_pick',
                        'draft_pick_id' => (int) $pk['id'],
                        'label' => "{$year} Round {$round}",
                        'round' => $round,
                        'value' => round($pkValue, 1),
                    ];
                    break;
                }
            }
        }

        // Generate personality-driven message
        $counterPackage['message'] = $this->gmCounterMessage($gmPersonality, $counterPackage);

        return $counterPackage;
    }

    private function gmCounterMessage(string $personality, array $counter): string
    {
        $hasAsk = !empty($counter['ask_addition']);
        $askType = $hasAsk ? $counter['ask_addition']['type'] : '';
        $askLabel = '';
        if ($askType === 'draft_pick') {
            $askLabel = $counter['ask_addition']['label'] ?? 'a draft pick';
        } elseif ($askType === 'player') {
            $name = $counter['ask_addition']['name'] ?? 'a player';
            $ovr = $counter['ask_addition']['overall_rating'] ?? '?';
            $pos = $counter['ask_addition']['position'] ?? '';
            $askLabel = "{$name} ({$ovr} OVR {$pos})";
        }

        if (!$hasAsk) {
            return match ($personality) {
                'aggressive' => "Look, we're not close on this one. Come back with a serious offer and we'll talk.",
                'conservative' => "We've analyzed the value and we're too far apart. We'd need significantly more to consider this.",
                'analytics' => "The numbers don't work. The value gap is too large for us to propose a realistic counter.",
                'old_school' => "Son, you're going to have to do a lot better than that if you want one of our guys.",
                default => "We appreciate the interest, but we're pretty far apart on value. We'd need a lot more to make this work.",
            };
        }

        return match ($personality) {
            'aggressive' => "Here's the deal — throw in {$askLabel} and we'll shake on it right now. Don't overthink it.",
            'conservative' => "We've looked at this carefully. We would consider the trade if you include {$askLabel}. That's what makes the value work for us.",
            'analytics' => "Our models show the gap is about " . round($counter['gap'], 0) . " value points. Adding {$askLabel} brings this into the acceptable range.",
            'old_school' => "I like the bones of this deal, but you need to sweeten the pot. Add {$askLabel} and we've got ourselves a trade.",
            default => "We're close, but not quite there. If you can include {$askLabel}, we'd be willing to make this deal.",
        };
    }

    public function respondToTrade(int $tradeId, int $teamId, string $action, array $counterData = []): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM trades WHERE id = ? AND receiving_team_id = ?");
        $stmt->execute([$tradeId, $teamId]);
        $trade = $stmt->fetch();
        if (!$trade || $trade['status'] !== 'proposed') return null;

        $now = date('Y-m-d H:i:s');
        $reason = null;
        $counterOffer = null;

        if ($action === 'accept') {
            $this->db->prepare("UPDATE trades SET status = 'accepted', resolved_at = ? WHERE id = ?")
                ->execute([$now, $tradeId]);
            $this->executeTrade($tradeId);
        } elseif ($action === 'reject') {
            $reason = $counterData['reason'] ?? 'Trade rejected.';
            $this->db->prepare("UPDATE trades SET status = 'rejected', veto_reason = ?, resolved_at = ? WHERE id = ?")
                ->execute([$reason, $now, $tradeId]);
        } elseif ($action === 'counter') {
            $reason = $counterData['reason'] ?? 'We want to counter.';
            $counterOffer = $counterData['counter_offer'] ?? null;
            $this->db->prepare("UPDATE trades SET status = 'countered', veto_reason = ?, resolved_at = ? WHERE id = ?")
                ->execute([$reason, $now, $tradeId]);
        }

        $stmt = $this->db->prepare("SELECT * FROM trades WHERE id = ?");
        $stmt->execute([$tradeId]);
        $result = $stmt->fetch() ?: null;

        if ($result) {
            $result['reason'] = $reason;
            if ($counterOffer) {
                $result['counter_offer'] = $counterOffer;
            }
        }

        return $result;
    }

    // ════════════════════════════════════════════════════════════════════
    //  TRADE BLOCK
    // ════════════════════════════════════════════════════════════════════

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

    public function addToTradeBlock(int $teamId, int $playerId, string $notes = ''): ?array
    {
        $stmt = $this->db->prepare("SELECT id FROM players WHERE id = ? AND team_id = ?");
        $stmt->execute([$playerId, $teamId]);
        if (!$stmt->fetch()) return null;

        $this->db->prepare(
            "INSERT INTO trade_block (team_id, player_id, notes, created_at) VALUES (?, ?, ?, ?)"
        )->execute([$teamId, $playerId, $notes, date('Y-m-d H:i:s')]);

        return ['id' => (int) $this->db->lastInsertId(), 'player_id' => $playerId, 'team_id' => $teamId, 'notes' => $notes];
    }

    public function removeFromTradeBlock(int $entryId, int $teamId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM trade_block WHERE id = ? AND team_id = ?");
        $stmt->execute([$entryId, $teamId]);
        return $stmt->rowCount() > 0;
    }

    // ════════════════════════════════════════════════════════════════════
    //  PRIVATE HELPERS
    // ════════════════════════════════════════════════════════════════════

    private function classifyPackageType(array $youSend, array $theySend): string
    {
        $youPlayers = count($youSend['players']);
        $youPicks = count($youSend['picks']);
        $theyPlayers = count($theySend['players']);
        $theyPicks = count($theySend['picks']);

        if ($theyPlayers === 0 && $theyPicks > 0) return 'player_for_picks_only';
        if ($theyPlayers === 1 && $theyPicks === 0 && $youPlayers === 1) return 'player_for_player';
        if ($theyPlayers >= 1 && $theyPicks >= 1) return 'player_for_player_plus_picks';
        if ($theyPlayers >= 2 && $theyPicks === 0) return 'multi_player_swap';
        if ($youPlayers >= 1 && $youPicks >= 1) return 'player_plus_pick_for_player';
        return 'player_for_player';
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

    private function getTeamDraftPicks(int $teamId, int $leagueId): array
    {
        $stmt = $this->db->prepare(
            "SELECT dp.id, dp.round, dp.pick_number, dp.original_team_id, dp.current_team_id,
                    dc.year, dc.status, ot.abbreviation as original_team_abbr
             FROM draft_picks dp
             JOIN draft_classes dc ON dp.draft_class_id = dc.id
             LEFT JOIN teams ot ON dp.original_team_id = ot.id
             WHERE dp.current_team_id = ? AND dp.league_id = ? AND dp.is_used = 0
             ORDER BY dc.year ASC, dp.round ASC, dp.pick_number ASC"
        );
        $stmt->execute([$teamId, $leagueId]);
        return $stmt->fetchAll();
    }
}
