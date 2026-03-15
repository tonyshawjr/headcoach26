<?php

namespace App\Services;

/**
 * TeamBrain — Every AI team "thinks" independently.
 *
 * This class represents how a team's front office evaluates trades.
 * Each team has a GM personality, a roster situation, a competitive window,
 * and position-specific needs/strengths that drive every trade decision.
 *
 * No team trades in a vacuum. Every offer is evaluated through the lens of:
 *   "Does this make MY team better, given MY situation?"
 */
class TeamBrain
{
    // ── Team identity ────────────────────────────────────────────────
    private int $teamId;
    private array $team;        // team row (wins, losses, etc.)
    private array $coach;       // coach row (gm_personality, etc.)
    private array $roster;      // active players on this team
    private array $draftPicks;  // available draft picks
    private int $leagueWeek;
    private int $leagueYear;

    // ── Computed analysis (built during analyze()) ───────────────────
    private array $positionGroups = [];  // position => [players sorted by OVR desc]
    private array $needs = [];           // position => need score (0-1)
    private array $strengths = [];       // position => strength score (0-1)
    private string $mode = 'competitive'; // contender / competitive / rebuilding
    private float $windowScore = 50;     // 0-100: how close to competing
    private float $desperation = 30;     // 0-100: how badly they need moves
    private float $flexibility = 50;     // 0-100: cap + picks available
    private array $gmTraits = [];        // personality-derived trade modifiers
    private float $rosterStrength = 75;  // avg OVR of starters

    // ── Position constants ───────────────────────────────────────────
    private const IDEAL_DEPTH = [
        'QB' => 2, 'RB' => 3, 'WR' => 5, 'TE' => 3,
        'OT' => 4, 'OG' => 4, 'C' => 2,
        'DE' => 4, 'DT' => 4, 'LB' => 4, 'CB' => 5, 'S' => 4,
        'K' => 1, 'P' => 1, 'LS' => 1,
    ];

    private const POSITION_VALUES = [
        'QB' => 2.5, 'RB' => 1.3, 'WR' => 1.5, 'TE' => 1.2,
        'OT' => 1.5, 'OG' => 1.1, 'C' => 1.0,
        'DE' => 1.6, 'DT' => 1.3, 'LB' => 1.2, 'CB' => 1.4, 'S' => 1.2,
        'K' => 0.4, 'P' => 0.3, 'LS' => 0.2,
    ];

    // ── GM Personality Definitions ───────────────────────────────────
    private const GM_PROFILES = [
        'aggressive' => [
            'overpay_tolerance'  => 0.20,   // willing to overpay 20%
            'pick_attachment'    => 0.6,     // low attachment to picks (trades them easily)
            'win_now_bias'       => 1.4,     // heavily values proven players
            'youth_bias'         => 0.8,     // slightly undervalues youth
            'risk_tolerance'     => 0.8,     // will make risky trades
            'max_sweetens'       => 2,       // will sweeten twice
            'franchise_threshold' => 94,     // only protects 94+ OVR
        ],
        'conservative' => [
            'overpay_tolerance'  => -0.10,   // wants to win every trade on paper
            'pick_attachment'    => 1.5,      // hoards picks
            'win_now_bias'       => 0.9,
            'youth_bias'         => 1.0,
            'risk_tolerance'     => 0.3,
            'max_sweetens'       => 0,        // final offer is final
            'franchise_threshold' => 88,      // protects more players
        ],
        'analytics' => [
            'overpay_tolerance'  => 0.0,      // strictly by the numbers
            'pick_attachment'    => 1.0,
            'win_now_bias'       => 0.7,
            'youth_bias'         => 1.5,       // loves young upside players
            'risk_tolerance'     => 0.6,
            'max_sweetens'       => 1,
            'franchise_threshold' => 90,
        ],
        'old_school' => [
            'overpay_tolerance'  => 0.10,
            'pick_attachment'    => 1.1,
            'win_now_bias'       => 1.3,       // wants proven vets
            'youth_bias'         => 0.7,        // doesn't trust rookies
            'risk_tolerance'     => 0.4,
            'max_sweetens'       => 1,
            'franchise_threshold' => 90,
        ],
        'balanced' => [
            'overpay_tolerance'  => 0.05,
            'pick_attachment'    => 1.0,
            'win_now_bias'       => 1.0,
            'youth_bias'         => 1.0,
            'risk_tolerance'     => 0.5,
            'max_sweetens'       => 1,
            'franchise_threshold' => 92,
        ],
    ];

    // ════════════════════════════════════════════════════════════════════
    //  CONSTRUCTION
    // ════════════════════════════════════════════════════════════════════

    /**
     * Create a TeamBrain from pre-loaded data (no DB queries).
     */
    public static function create(
        array $team,
        array $coach,
        array $players,
        array $draftPicks,
        int $leagueWeek = 0,
        int $leagueYear = 2026
    ): self {
        $brain = new self();
        $brain->teamId = (int) $team['id'];
        $brain->team = $team;
        $brain->coach = $coach;
        $brain->roster = $players;
        $brain->draftPicks = $draftPicks;
        $brain->leagueWeek = $leagueWeek;
        $brain->leagueYear = $leagueYear;
        $brain->analyze();
        return $brain;
    }

    /**
     * Run the full team analysis. This builds the complete mental model
     * of the team's situation, needs, and trade posture.
     */
    private function analyze(): void
    {
        $this->buildGmTraits();
        $this->analyzePositions();
        $this->calculateMode();
        $this->calculateWindowScore();
        $this->calculateDesperation();
        $this->calculateFlexibility();
    }

    // ════════════════════════════════════════════════════════════════════
    //  ANALYSIS METHODS
    // ════════════════════════════════════════════════════════════════════

    private function buildGmTraits(): void
    {
        $personality = $this->coach['gm_personality'] ?? 'balanced';
        $this->gmTraits = self::GM_PROFILES[$personality] ?? self::GM_PROFILES['balanced'];
    }

    private function analyzePositions(): void
    {
        $this->positionGroups = [];

        foreach ($this->roster as $p) {
            $pos = $p['position'];
            $this->positionGroups[$pos][] = $p;
        }

        // Sort each position group by OVR desc
        foreach ($this->positionGroups as $pos => &$players) {
            usort($players, fn($a, $b) => (int) $b['overall_rating'] <=> (int) $a['overall_rating']);
        }
        unset($players);

        // Calculate needs and strengths
        foreach (self::IDEAL_DEPTH as $pos => $ideal) {
            $group = $this->positionGroups[$pos] ?? [];
            $count = count($group);
            $bestOvr = !empty($group) ? (int) $group[0]['overall_rating'] : 0;
            $avgOvr = $count > 0 ? array_sum(array_map(fn($p) => (int) $p['overall_rating'], $group)) / $count : 0;

            // Need score: 0 = fully stocked, 1 = desperate need
            $needScore = 0.0;
            if ($count === 0 && $ideal > 0) {
                $needScore = 1.0; // completely empty
            } else {
                // Depth need
                if ($count < $ideal) {
                    $needScore += min(0.5, ($ideal - $count) * 0.18);
                }
                // Quality need
                if ($bestOvr > 0) {
                    if ($bestOvr < 68) $needScore += 0.45;
                    elseif ($bestOvr < 73) $needScore += 0.30;
                    elseif ($bestOvr < 78) $needScore += 0.15;
                    elseif ($bestOvr >= 88) $needScore -= 0.15;
                }
            }
            // QB amplifier
            if ($pos === 'QB' && $bestOvr < 78) {
                $needScore += 0.30;
            }
            $this->needs[$pos] = max(0.0, min(1.0, $needScore));

            // Strength score: 0 = weakness, 1 = dominant
            $strengthScore = 0.0;
            if ($count >= $ideal && $bestOvr >= 85) {
                $strengthScore = 0.8;
                if ($count > $ideal) $strengthScore += 0.1;
                if ($avgOvr >= 78) $strengthScore += 0.1;
            } elseif ($count >= $ideal && $bestOvr >= 78) {
                $strengthScore = 0.5;
            } elseif ($count >= $ideal) {
                $strengthScore = 0.3;
            }
            $this->strengths[$pos] = min(1.0, $strengthScore);
        }

        // Calculate overall roster strength (avg of starters)
        $starterOvrs = [];
        foreach ($this->positionGroups as $pos => $group) {
            if (!empty($group)) {
                $starterOvrs[] = (int) $group[0]['overall_rating'];
            }
        }
        $this->rosterStrength = count($starterOvrs) > 0
            ? array_sum($starterOvrs) / count($starterOvrs) : 72;
    }

    private function calculateMode(): void
    {
        $wins = (int) ($this->team['wins'] ?? 0);
        $losses = (int) ($this->team['losses'] ?? 0);
        $total = $wins + $losses;
        $winPct = $total > 0 ? $wins / $total : 0.5;

        if ($winPct >= 0.600 && $this->rosterStrength >= 78) {
            $this->mode = 'contender';
        } elseif ($winPct < 0.375 || $this->rosterStrength < 72) {
            $this->mode = 'rebuilding';
        } else {
            $this->mode = 'competitive';
        }
    }

    private function calculateWindowScore(): void
    {
        // How close is this team to competing for a championship?
        // Based on: roster quality, age of key players, record
        $wins = (int) ($this->team['wins'] ?? 0);
        $losses = (int) ($this->team['losses'] ?? 0);
        $total = $wins + $losses;
        $winPct = $total > 0 ? $wins / $total : 0.5;

        // Roster age — younger roster = longer window
        $ages = array_map(fn($p) => (int) $p['age'], $this->roster);
        $avgAge = count($ages) > 0 ? array_sum($ages) / count($ages) : 26;

        $score = 0;
        $score += $this->rosterStrength * 0.6;        // roster quality (0-60)
        $score += $winPct * 25;                        // winning (0-25)
        $score += max(0, (30 - $avgAge)) * 1.5;       // youth bonus (0-15)

        $this->windowScore = max(0, min(100, $score));
    }

    private function calculateDesperation(): void
    {
        // How badly does this team need to make moves?
        // High desperation = willing to overpay. Low = patient.
        $totalNeed = array_sum($this->needs);
        $avgNeed = count($this->needs) > 0 ? $totalNeed / count($this->needs) : 0;
        $maxNeed = count($this->needs) > 0 ? max($this->needs) : 0;

        $desperation = 0;

        // Position needs drive desperation
        $desperation += $avgNeed * 40;
        $desperation += $maxNeed * 20;

        // Contenders get desperate in-season
        if ($this->mode === 'contender' && $this->leagueWeek >= 6) {
            $desperation += min(25, ($this->leagueWeek - 5) * 4);
        }

        // Rebuilding teams are patient
        if ($this->mode === 'rebuilding') {
            $desperation *= 0.5;
        }

        $this->desperation = max(0, min(100, $desperation));
    }

    private function calculateFlexibility(): void
    {
        // How much room does this team have to make deals?
        $score = 50; // baseline

        // Draft capital
        $pickCount = count($this->draftPicks);
        $firstRoundPicks = count(array_filter($this->draftPicks, fn($p) => (int) $p['round'] === 1));
        $score += $pickCount * 2;
        $score += $firstRoundPicks * 8;

        // Roster size — if bloated, less flexible to take on players
        $rosterSize = count($this->roster);
        if ($rosterSize >= 53) $score -= 15;
        elseif ($rosterSize >= 50) $score -= 5;
        elseif ($rosterSize < 40) $score += 10;

        // Cap space (use salary_cap and cap_used from team if available)
        $capSpace = (int) ($this->team['salary_cap'] ?? 225000000) - (int) ($this->team['cap_used'] ?? 0);
        if ($capSpace > 40000000) $score += 15;
        elseif ($capSpace > 20000000) $score += 8;
        elseif ($capSpace < 5000000) $score -= 20;

        $this->flexibility = max(0, min(100, $score));
    }

    // ════════════════════════════════════════════════════════════════════
    //  PLAYER VALUATION — Contextual to THIS team
    // ════════════════════════════════════════════════════════════════════

    /**
     * How valuable is a player TO THIS TEAM specifically?
     * A 90 OVR CB is worth way more to a team with 68 OVR corners
     * than to a team with 3 pro-bowl corners.
     */
    public function valuePlayerForUs(array $player, float $baseValue): float
    {
        $pos = $player['position'];
        $ovr = (int) $player['overall_rating'];
        $age = (int) $player['age'];

        $value = $baseValue;

        // ── Need multiplier ──────────────────────────────────────────
        $needAtPos = $this->needs[$pos] ?? 0;
        // High need = player is worth more to us (up to 1.5x)
        // No need = player is worth less (down to 0.7x)
        $needMultiplier = 0.7 + ($needAtPos * 0.8);
        $value *= $needMultiplier;

        // ── Upgrade multiplier ───────────────────────────────────────
        $currentBest = $this->getBestOvrAt($pos);
        if ($ovr > $currentBest && $currentBest > 0) {
            $upgradePct = ($ovr - $currentBest) / 100.0;
            $value *= 1.0 + ($upgradePct * 3.0); // +3% value per OVR point of upgrade
        } elseif ($currentBest > 0 && $ovr < $currentBest - 5) {
            // Player is clearly worse than what we have — not very valuable to us
            $value *= 0.75;
        }

        // ── Window multiplier ────────────────────────────────────────
        if ($this->mode === 'contender') {
            // Contenders value proven players more, young projects less
            if ($ovr >= 82) {
                $value *= $this->gmTraits['win_now_bias'];
            } elseif ($age <= 24 && $ovr < 75) {
                $value *= 0.7; // Project player doesn't help us win now
            }
        } elseif ($this->mode === 'rebuilding') {
            // Rebuilding teams value youth/potential, discount aging vets
            if ($age <= 25) {
                $value *= $this->gmTraits['youth_bias'];
            } elseif ($age >= 29) {
                $value *= 0.6; // Aging vet doesn't fit our timeline
            }
        }

        // ── Trade deadline pressure ──────────────────────────────────
        if ($this->leagueWeek >= 8 && $this->mode === 'contender' && $ovr >= 80) {
            $urgency = min(0.25, ($this->leagueWeek - 7) * 0.06);
            $value *= 1.0 + $urgency;
        }

        return max(0.5, round($value, 1));
    }

    // ════════════════════════════════════════════════════════════════════
    //  TRADE CANDIDATE ASSESSMENT — "Would we trade this player?"
    // ════════════════════════════════════════════════════════════════════

    /**
     * Assess whether this team would trade a given player.
     * Returns willingness + asking price + reasoning.
     */
    public function assessTradeCandidate(array $player, float $baseValue): array
    {
        $pos = $player['position'];
        $ovr = (int) $player['overall_rating'];
        $age = (int) $player['age'];
        $name = ($player['first_name'] ?? '') . ' ' . ($player['last_name'] ?? '');
        $playerId = (int) $player['id'];

        // ── Untouchable check ────────────────────────────────────────
        $franchiseThreshold = $this->gmTraits['franchise_threshold'];
        if ($ovr >= $franchiseThreshold && $age < 30) {
            return [
                'available' => false,
                'asking_price' => $baseValue * 3.0,
                'reason' => "{$name} is untouchable. Franchise cornerstone.",
            ];
        }

        // Franchise QB protection (even if below threshold)
        if ($pos === 'QB' && $ovr >= 82 && $age < 32) {
            $qbGroup = $this->positionGroups['QB'] ?? [];
            $isStarter = !empty($qbGroup) && (int) $qbGroup[0]['id'] === $playerId;
            if ($isStarter) {
                return [
                    'available' => false,
                    'asking_price' => $baseValue * 3.0,
                    'reason' => "Can't trade our franchise quarterback.",
                ];
            }
        }

        // ── Depth protection ─────────────────────────────────────────
        $group = $this->positionGroups[$pos] ?? [];
        $ideal = self::IDEAL_DEPTH[$pos] ?? 2;
        $count = count($group);
        $isStarter = !empty($group) && (int) $group[0]['id'] === $playerId;

        if ($isStarter && $count <= $ideal) {
            // Only starter and thin at position — very reluctant
            if ($this->mode === 'contender') {
                return [
                    'available' => false,
                    'asking_price' => $baseValue * 2.5,
                    'reason' => "We can't weaken ourselves at {$pos} during a playoff push.",
                ];
            }
            // Not contending but still starter — need a premium
            $premium = 1.5;
        } elseif ($isStarter && $count > $ideal) {
            // Starter but we have depth — slight premium
            $premium = 1.15;
        } elseif (!$isStarter && $count > $ideal) {
            // Depth piece and we're deep — sell
            $premium = 0.90;
        } else {
            $premium = 1.0;
        }

        // ── Mode-based adjustments ───────────────────────────────────
        if ($this->mode === 'rebuilding') {
            if ($age >= 28 && $ovr < 85) {
                // Rebuilding — sell aging non-stars for assets
                $premium *= 0.85;
            } elseif ($age <= 24) {
                // Rebuilding — protect young players
                $premium *= 1.4;
            }
        }

        // ── Strength surplus ─────────────────────────────────────────
        $strength = $this->strengths[$pos] ?? 0;
        if ($strength >= 0.7 && !$isStarter) {
            // We're loaded at this position — more willing to deal
            $premium *= 0.85;
        }

        $askingPrice = $baseValue * $premium;

        return [
            'available' => true,
            'asking_price' => round($askingPrice, 1),
            'premium' => round($premium, 2),
            'reason' => $this->explainAvailability($player, $premium, $isStarter, $count, $ideal),
        ];
    }

    private function explainAvailability(array $player, float $premium, bool $isStarter, int $depth, int $ideal): string
    {
        $pos = $player['position'];
        if ($premium <= 0.90) return "Surplus {$pos} — willing to move for the right price.";
        if ($premium <= 1.0) return "Available. Not a core piece for us.";
        if ($premium <= 1.15) return "Would consider moving if the return is right.";
        if ($premium <= 1.3) return "Key contributor — would need a solid return.";
        return "Premium asking price. Not actively shopping.";
    }

    // ════════════════════════════════════════════════════════════════════
    //  OFFER BUILDING — "What would we offer for this player?"
    // ════════════════════════════════════════════════════════════════════

    /**
     * Build the best offer this team would make for a target player.
     * Considers what the other team needs and sends relevant pieces.
     *
     * @param array  $targetPlayer  The player we want to acquire
     * @param float  $askingPrice   What the selling team wants
     * @param array  $targetNeeds   The selling team's position needs [pos => score]
     * @param callable $valueCalc   Function to calculate base player value
     * @return array|null  The offer, or null if we wouldn't make one
     */
    public function buildOfferFor(
        array $targetPlayer, float $askingPrice,
        array $targetNeeds, callable $valueCalc
    ): ?array {
        $targetPos = $targetPlayer['position'];
        $targetOvr = (int) $targetPlayer['overall_rating'];

        // ── Would we even want this player? ──────────────────────────
        $ourNeed = $this->needs[$targetPos] ?? 0;
        $currentBest = $this->getBestOvrAt($targetPos);
        $isUpgrade = $targetOvr > $currentBest;
        $targetAge = (int) ($targetPlayer['age'] ?? 30);
        $targetPotential = $targetPlayer['potential'] ?? 'average';
        $isYoungUpside = ($targetAge <= 25 && ($targetPotential === 'elite' || $targetPotential === 'high'));
        $depth = $this->getDepthAt($targetPos);
        $ideal = self::IDEAL_DEPTH[$targetPos] ?? 2;
        $needsDepth = $depth < $ideal;

        if ($ourNeed < 0.05 && !$isUpgrade && !$isYoungUpside && !$needsDepth) {
            return null; // We genuinely don't need this player
        }

        // ── What's our ceiling for this trade? ───────────────────────
        // Use the universal asking price as the target, but our contextual
        // valuation determines our ceiling
        $ourValue = $this->valuePlayerForUs($targetPlayer, $askingPrice);
        $overpayFactor = 1.0 + $this->gmTraits['overpay_tolerance'];

        // Even if our contextual value is lower (we don't desperately need the position),
        // we still might trade for a great player. Use the higher of our value or 60% of asking.
        $effectiveValue = max($ourValue, $askingPrice * 0.65);
        $maxWillingToPay = $effectiveValue * $overpayFactor;

        if ($maxWillingToPay < $askingPrice * 0.50) {
            return null; // Price is way too high for us
        }

        $targetBudget = min($maxWillingToPay, $askingPrice * 1.25);

        // ── Find tradeable players ───────────────────────────────────
        // Prefer sending players at positions the other team needs
        $candidates = [];
        foreach ($this->roster as $p) {
            $pId = (int) $p['id'];
            $pOvr = (int) $p['overall_rating'];
            $pPos = $p['position'];
            $pAge = (int) $p['age'];
            $pValue = $valueCalc($p);

            if ($pValue < 1.0) continue;

            // Don't send our franchise players
            $assessment = $this->assessTradeCandidate($p, $pValue);
            if (!$assessment['available']) continue;

            // Check if this player fills a need for the other team
            $fillsTargetNeed = ($targetNeeds[$pPos] ?? 0) >= 0.15;

            // Relevance score: how good is this player as a trade chip?
            $relevance = 0;
            if ($fillsTargetNeed) $relevance += 40;
            if ($pOvr >= 75) $relevance += 20;
            if ($pAge <= 26) $relevance += 15;
            if ($assessment['premium'] <= 1.0) $relevance += 15; // easy to part with
            if ($pPos === $targetPos) $relevance -= 20; // same position = less useful

            $candidates[] = [
                'player' => $p,
                'value' => $pValue,
                'relevance' => $relevance,
                'fills_need' => $fillsTargetNeed,
                'assessment' => $assessment,
            ];
        }

        // Sort by a blend of relevance and value — need-filling matters but
        // we also need players valuable enough to make deals work
        usort($candidates, function ($a, $b) {
            $scoreA = $a['relevance'] + ($a['value'] * 0.5);
            $scoreB = $b['relevance'] + ($b['value'] * 0.5);
            return $scoreB <=> $scoreA;
        });

        // ── Find available draft picks ───────────────────────────────
        $pickCandidates = [];
        foreach ($this->draftPicks as $pk) {
            $round = (int) $pk['round'];
            $pickInRound = (((int) ($pk['pick_number'] ?? 16)) - 1) % 32 + 1;
            $yearsOut = max(0, (int) ($pk['year'] ?? $this->leagueYear) - $this->leagueYear);
            $pkValue = $this->evaluatePick($round, $pickInRound, $yearsOut);

            // Apply pick attachment — conservative GMs value their picks higher
            $effectiveValue = $pkValue / $this->gmTraits['pick_attachment'];

            // Rebuilding teams protect current 1st rounders
            if ($this->mode === 'rebuilding' && $round === 1 && $yearsOut === 0) continue;
            // Contenders protect far-future 1sts
            if ($this->mode === 'contender' && $round === 1 && $yearsOut >= 2) continue;

            $pickCandidates[] = [
                'pick' => $pk,
                'value' => $pkValue,
                'effective_value' => $effectiveValue,
                'round' => $round,
            ];
        }

        usort($pickCandidates, fn($a, $b) => $b['value'] <=> $a['value']);

        // ── Build package options ────────────────────────────────────
        $packages = [];

        // OPTION A: Best single player match
        foreach ($candidates as $c) {
            if ($c['value'] >= $askingPrice * 0.70 && $c['value'] <= $targetBudget) {
                $packages[] = [
                    'players' => [$c],
                    'picks' => [],
                    'total_value' => $c['value'],
                    'need_filling' => $c['fills_need'] ? 1 : 0,
                    'type' => 'player_for_player',
                ];
                break;
            }
        }

        // OPTION B: Player + pick combo
        foreach ($candidates as $c) {
            if ($c['value'] >= $askingPrice * 0.25 && $c['value'] < $askingPrice * 0.85) {
                $gap = $askingPrice - $c['value'];
                $comboPicks = [];
                $comboVal = 0;
                foreach ($pickCandidates as $pk) {
                    if ($comboVal >= $gap * 1.15) break;
                    if (count($comboPicks) >= 2) break;
                    $comboPicks[] = $pk;
                    $comboVal += $pk['value'];
                }
                $total = $c['value'] + $comboVal;
                if ($total >= $askingPrice * 0.70 && !empty($comboPicks)) {
                    $packages[] = [
                        'players' => [$c],
                        'picks' => $comboPicks,
                        'total_value' => $total,
                        'need_filling' => $c['fills_need'] ? 1 : 0,
                        'type' => 'player_for_player_plus_picks',
                    ];
                    break;
                }
            }
        }

        // OPTION C: Multi-player swap (2-3 players)
        if (count($candidates) >= 2) {
            $multi = [];
            $multiVal = 0;
            $multiFills = 0;
            foreach ($candidates as $c) {
                if (count($multi) >= 3) break;
                if ($multiVal >= $targetBudget) break;
                $multi[] = $c;
                $multiVal += $c['value'];
                if ($c['fills_need']) $multiFills++;
            }
            if ($multiVal >= $askingPrice * 0.70 && count($multi) >= 2) {
                $packages[] = [
                    'players' => $multi,
                    'picks' => [],
                    'total_value' => $multiVal,
                    'need_filling' => $multiFills,
                    'type' => 'multi_player_swap',
                ];
            }
        }

        // OPTION D: Multi-player + picks combo (for high-value targets)
        if (count($candidates) >= 1 && !empty($pickCandidates)) {
            $combo = [];
            $comboVal = 0;
            $comboFills = 0;
            // Add top 1-2 players
            foreach ($candidates as $c) {
                if (count($combo) >= 2) break;
                if ($comboVal >= $targetBudget * 0.8) break;
                $combo[] = $c;
                $comboVal += $c['value'];
                if ($c['fills_need']) $comboFills++;
            }
            // Add picks to close gap
            $gap = $askingPrice - $comboVal;
            $comboPkgs = [];
            if ($gap > 0) {
                foreach ($pickCandidates as $pk) {
                    if ($comboVal >= $targetBudget) break;
                    if (count($comboPkgs) >= 2) break;
                    $comboPkgs[] = $pk;
                    $comboVal += $pk['value'];
                }
            }
            if ($comboVal >= $askingPrice * 0.70 && !empty($combo) && !empty($comboPkgs)) {
                $packages[] = [
                    'players' => $combo,
                    'picks' => $comboPkgs,
                    'total_value' => $comboVal,
                    'need_filling' => $comboFills,
                    'type' => 'multi_player_plus_picks',
                ];
            }
        }

        // OPTION E: Picks only
        if (!empty($pickCandidates)) {
            $picksPkg = [];
            $picksVal = 0;
            foreach ($pickCandidates as $pk) {
                if ($picksVal >= $targetBudget) break;
                if (count($picksPkg) >= 3) break;
                $picksPkg[] = $pk;
                $picksVal += $pk['value'];
            }
            if ($picksVal >= $askingPrice * 0.70 && !empty($picksPkg)) {
                $packages[] = [
                    'players' => [],
                    'picks' => $picksPkg,
                    'total_value' => $picksVal,
                    'need_filling' => 0,
                    'type' => 'player_for_picks_only',
                ];
            }
        }

        if (empty($packages)) return null;

        // ── Pick the best package ────────────────────────────────────
        // Score each package: closeness to fair value + need filling + GM preference
        usort($packages, function ($a, $b) use ($askingPrice) {
            $scoreA = $this->scorePackage($a, $askingPrice);
            $scoreB = $this->scorePackage($b, $askingPrice);
            return $scoreB <=> $scoreA;
        });

        return $packages[0];
    }

    /**
     * Score a package from this team's perspective for sorting.
     */
    private function scorePackage(array $pkg, float $targetValue): float
    {
        $score = 0;

        // Fairness (don't wildly overpay)
        $ratio = min($pkg['total_value'], $targetValue) / max($pkg['total_value'], $targetValue, 1);
        $score += $ratio * 40;

        // Need-filling bonus (sending players the other team needs = more likely accepted)
        $score += ($pkg['need_filling'] ?? 0) * 15;

        // Don't overpay too much
        if ($pkg['total_value'] > $targetValue * 1.2) $score -= 15;

        // Prefer player trades over pick-only trades (unless we're analytics/rebuilding)
        if (!empty($pkg['players']) && $pkg['type'] !== 'player_for_picks_only') {
            $score += 5;
        }
        if ($this->mode === 'rebuilding' && $pkg['type'] === 'player_for_picks_only') {
            $score += 10; // rebuilding teams prefer sending picks for players
        }

        return $score;
    }

    // ════════════════════════════════════════════════════════════════════
    //  OFFER EVALUATION — "Would we accept this deal?"
    // ════════════════════════════════════════════════════════════════════

    /**
     * Evaluate an incoming trade offer from this team's perspective.
     * Both sides "think" — this is called on the RECEIVING team's brain.
     */
    public function evaluateIncomingOffer(
        array $weGive, float $weGiveContextualValue,
        array $weGet, float $weGetContextualValue
    ): array {
        $valueDiff = $weGetContextualValue - $weGiveContextualValue;
        $ratio = $weGiveContextualValue > 0
            ? $weGetContextualValue / $weGiveContextualValue
            : 99;

        // Acceptable range depends on GM personality
        $overpayTolerance = $this->gmTraits['overpay_tolerance'];
        $minAcceptable = 1.0 - $overpayTolerance - 0.10; // e.g., aggressive: 0.70, conservative: 1.00

        if ($ratio >= $minAcceptable) {
            // Deal is acceptable
            $satisfaction = min(1.0, ($ratio - $minAcceptable) / 0.5);
            return [
                'decision' => 'accept',
                'satisfaction' => round($satisfaction, 2),
                'gm_personality' => $personality,
                'reason' => $this->explainAcceptance($ratio, $satisfaction),
            ];
        }

        // Check if close enough to counter
        $personality = $this->coach['gm_personality'] ?? 'balanced';

        if ($ratio >= $minAcceptable - 0.15) {
            return [
                'decision' => 'counter',
                'satisfaction' => 0,
                'gap' => round($weGiveContextualValue - $weGetContextualValue, 1),
                'gm_personality' => $personality,
                'reason' => $this->gmCounterReason($personality),
            ];
        }

        return [
            'decision' => 'reject',
            'satisfaction' => 0,
            'gm_personality' => $personality,
            'reason' => $this->gmRejectReason($personality, $ratio),
        ];
    }

    private function explainAcceptance(float $ratio, float $satisfaction): string
    {
        $personality = $this->coach['gm_personality'] ?? 'balanced';
        return match ($personality) {
            'aggressive' => match (true) {
                $satisfaction >= 0.7 => "Done. I love this deal. Let's make it happen before they change their mind.",
                $satisfaction >= 0.3 => "Yeah, we'll do it. This makes us better right now.",
                default => "It's tight, but we're pulling the trigger. Win-now mode.",
            },
            'conservative' => match (true) {
                $satisfaction >= 0.7 => "After careful analysis, this is a strong deal for us. We accept.",
                $satisfaction >= 0.3 => "The value checks out. We're comfortable with this trade.",
                default => "It's not a slam dunk, but the risk profile is acceptable. We'll proceed.",
            },
            'analytics' => match (true) {
                $satisfaction >= 0.7 => "The numbers love this. Positive expected value across every metric. Done deal.",
                $satisfaction >= 0.3 => "Our models project positive ROI on this deal. Accepted.",
                default => "Marginal positive value, but the positional fit makes it worth it.",
            },
            'old_school' => match (true) {
                $satisfaction >= 0.7 => "That's a football trade right there. Good players for good players. I'm in.",
                $satisfaction >= 0.3 => "I've seen enough tape. This player can help us. We'll make the trade.",
                default => "It's not perfect, but you don't win championships standing still. Let's do it.",
            },
            default => match (true) {
                $satisfaction >= 0.7 => "Great deal for us. Let's get this done.",
                $satisfaction >= 0.3 => "Solid trade. This works for both sides.",
                default => "Fair enough. We can live with this.",
            },
        };
    }

    private function gmCounterReason(string $personality): string
    {
        return match ($personality) {
            'aggressive' => "We're interested, but you're not giving us enough. Come harder or we're moving on.",
            'conservative' => "We see the framework of a deal here, but the value doesn't quite balance. We have a counter-proposal.",
            'analytics' => "Close, but our models show a value gap. Here's what would make the numbers work.",
            'old_school' => "I like where your head's at, but you're going to need to add a little something to get this done.",
            default => "We're not far apart. Here's what we'd need to make this work.",
        };
    }

    private function gmRejectReason(string $personality, float $ratio): string
    {
        return match ($personality) {
            'aggressive' => "Not even close. Don't waste my time with lowball offers.",
            'conservative' => "We've evaluated this thoroughly and it's well below our threshold. We'll pass.",
            'analytics' => "The value disparity is too significant. We'd need a fundamentally different package to engage.",
            'old_school' => "You're not serious with this offer. We know what our guys are worth.",
            default => "We appreciate the call, but we're too far apart on value. This one's a no.",
        };
    }

    // ════════════════════════════════════════════════════════════════════
    //  SWEETENING — "Can we improve our offer?"
    // ════════════════════════════════════════════════════════════════════

    /**
     * Reconsider an offer and potentially improve it.
     * This is a real decision — the team thinks about whether it's worth it.
     */
    public function reconsiderOffer(
        float $currentOfferValue, float $targetAskingPrice,
        float $targetBaseValue, array $currentPlayerIds, array $currentPickIds,
        callable $valueCalc
    ): array {
        $gap = $targetAskingPrice - $currentOfferValue;

        // ── Would we even bother? ────────────────────────────────────
        $maxSweetens = $this->gmTraits['max_sweetens'];
        if ($maxSweetens <= 0) {
            return [
                'sweetened' => false,
                'reason' => "That's our final offer. Take it or leave it.",
            ];
        }

        // If the gap is huge, we walk away
        if ($gap > $targetBaseValue * 0.5) {
            return [
                'sweetened' => false,
                'reason' => "We're too far apart. We'd have to gut our roster to make this work, and that's not happening.",
            ];
        }

        // If we already offered a fair deal (within 15%), don't sweeten
        if ($currentOfferValue >= $targetAskingPrice * 0.90) {
            return [
                'sweetened' => false,
                'reason' => "We think our offer is already fair. We're not adding more.",
            ];
        }

        // ── Find the smallest addition that closes the gap ───────────
        $maxAddition = $gap * 1.3; // Don't wildly overshoot

        // Try adding a pick first (less roster disruption)
        $bestPick = null;
        $bestPickFit = PHP_FLOAT_MAX;
        foreach ($this->draftPicks as $pk) {
            $pkId = (int) $pk['id'];
            if (in_array($pkId, $currentPickIds)) continue;

            $round = (int) $pk['round'];
            $pickInRound = (((int) ($pk['pick_number'] ?? 16)) - 1) % 32 + 1;
            $yearsOut = max(0, (int) ($pk['year'] ?? $this->leagueYear) - $this->leagueYear);
            $pkValue = $this->evaluatePick($round, $pickInRound, $yearsOut);

            // Pick must be proportional to the gap — no 1st rounders for small deals
            if ($pkValue > $maxAddition) continue;
            if ($pkValue < $gap * 0.5) continue; // too small to matter

            // Protect key picks based on personality
            if ($round === 1 && $this->gmTraits['pick_attachment'] >= 1.3) continue;
            if ($round <= 2 && $this->mode === 'rebuilding') continue;

            $fit = abs($pkValue - $gap);
            if ($fit < $bestPickFit) {
                $bestPickFit = $fit;
                $bestPick = array_merge($pk, ['_value' => $pkValue]);
            }
        }

        // Try finding a depth player to add/swap
        $bestPlayerAdd = null;
        $bestPlayerFit = PHP_FLOAT_MAX;
        foreach ($this->roster as $p) {
            $pId = (int) $p['id'];
            if (in_array($pId, $currentPlayerIds)) continue;

            $pValue = $valueCalc($p);
            if ($pValue > $maxAddition || $pValue < $gap * 0.4) continue;

            $assessment = $this->assessTradeCandidate($p, $pValue);
            if (!$assessment['available']) continue;
            if ($assessment['premium'] > 1.2) continue; // not giving up key players to sweeten

            $fit = abs($pValue - $gap);
            if ($fit < $bestPlayerFit) {
                $bestPlayerFit = $fit;
                $bestPlayerAdd = array_merge($p, ['_value' => $pValue]);
            }
        }

        // Pick the better option (closeness to gap)
        if ($bestPick && (!$bestPlayerAdd || $bestPickFit <= $bestPlayerFit)) {
            $roundNum = (int) $bestPick['round'];
            $yearLabel = (int) ($bestPick['year'] ?? $this->leagueYear);
            $pickLabel = "{$yearLabel} Round {$roundNum}";

            return [
                'sweetened' => true,
                'type' => 'added_pick',
                'added_pick' => [
                    'id' => (int) $bestPick['id'],
                    'label' => $pickLabel,
                    'round' => $roundNum,
                    'year' => $yearLabel,
                    'trade_value' => round($bestPick['_value'], 1),
                ],
                'new_total_value' => round($currentOfferValue + $bestPick['_value'], 1),
                'message' => "Alright, we'll add a {$pickLabel} pick. That's our best and final.",
            ];
        }

        if ($bestPlayerAdd) {
            $name = ($bestPlayerAdd['first_name'] ?? '') . ' ' . ($bestPlayerAdd['last_name'] ?? '');
            $ovr = (int) $bestPlayerAdd['overall_rating'];
            $pos = $bestPlayerAdd['position'];

            return [
                'sweetened' => true,
                'type' => 'added_player',
                'added_player' => [
                    'id' => (int) $bestPlayerAdd['id'],
                    'name' => trim($name),
                    'position' => $pos,
                    'overall_rating' => $ovr,
                    'age' => (int) $bestPlayerAdd['age'],
                    'trade_value' => round($bestPlayerAdd['_value'], 1),
                ],
                'new_total_value' => round($currentOfferValue + $bestPlayerAdd['_value'], 1),
                'message' => "We'll throw in {$name} ({$ovr} OVR {$pos}). Final offer.",
            ];
        }

        return [
            'sweetened' => false,
            'reason' => "We've looked at what we can add, and there's nothing that makes sense without hurting our roster. This is what we can do.",
        ];
    }

    // ════════════════════════════════════════════════════════════════════
    //  AI-INITIATED TRADES — "We want to call YOU with an offer"
    // ════════════════════════════════════════════════════════════════════

    /**
     * Scan this team's roster for players worth shopping.
     * Returns players this team would actively try to trade.
     */
    public function identifyTradeCandidates(callable $valueCalc): array
    {
        $candidates = [];

        foreach ($this->roster as $p) {
            $pos = $p['position'];
            $ovr = (int) $p['overall_rating'];
            $age = (int) $p['age'];
            $value = $valueCalc($p);
            $assessment = $this->assessTradeCandidate($p, $value);

            if (!$assessment['available']) continue;

            $tradeMotivation = 0;
            $reason = '';

            // Position of surplus — actively shop
            $strength = $this->strengths[$pos] ?? 0;
            $group = $this->positionGroups[$pos] ?? [];
            $ideal = self::IDEAL_DEPTH[$pos] ?? 2;

            if ($strength >= 0.6 && count($group) > $ideal) {
                $isStarter = !empty($group) && (int) $group[0]['id'] === (int) $p['id'];
                if (!$isStarter) {
                    $tradeMotivation += 40;
                    $reason = "Surplus at {$pos} — looking to convert depth into assets.";
                }
            }

            // Aging player on rebuilding team
            if ($this->mode === 'rebuilding' && $age >= 28 && $ovr >= 78) {
                $tradeMotivation += 50;
                $reason = "Rebuilding — selling veteran {$pos} for future assets.";
            }

            // Expiring value — player is aging out
            if ($age >= 30 && $ovr >= 80 && $this->mode !== 'contender') {
                $tradeMotivation += 30;
                $reason = "Trading aging {$pos} before value drops further.";
            }

            if ($tradeMotivation >= 30) {
                $candidates[] = [
                    'player' => $p,
                    'value' => $value,
                    'asking_price' => $assessment['asking_price'],
                    'motivation' => $tradeMotivation,
                    'reason' => $reason,
                ];
            }
        }

        usort($candidates, fn($a, $b) => $b['motivation'] <=> $a['motivation']);
        return array_slice($candidates, 0, 3); // Top 3 candidates
    }

    /**
     * Check if this team is interested in acquiring a specific player from another team.
     * Used when another team calls us to gauge interest.
     */
    public function isInterestedIn(array $player, float $baseValue): array
    {
        $pos = $player['position'];
        $ovr = (int) $player['overall_rating'];
        $needAtPos = $this->needs[$pos] ?? 0;
        $currentBest = $this->getBestOvrAt($pos);
        $isUpgrade = $ovr > $currentBest;

        $interest = 0.0;
        $reasons = [];

        // Need-based interest
        if ($needAtPos >= 0.5) {
            $interest += 0.4;
            $reasons[] = "Major need at {$pos}";
        } elseif ($needAtPos >= 0.25) {
            $interest += 0.2;
            $reasons[] = "Could use help at {$pos}";
        }

        // Upgrade interest
        if ($isUpgrade && $currentBest > 0) {
            $gap = $ovr - $currentBest;
            $interest += min(0.4, $gap * 0.04);
            $reasons[] = "Upgrade over our {$currentBest} OVR starter";
        }
        // Elite player interest — everyone wants a 90+ OVR player
        if ($ovr >= 90 && ($needAtPos >= 0.05 || $isUpgrade)) {
            $interest += 0.15;
            $reasons[] = "Elite talent at {$pos}";
        }

        // Mode-based interest
        if ($this->mode === 'contender' && $ovr >= 82) {
            $interest += 0.15;
            $reasons[] = "Win-now piece";
        }
        if ($this->mode === 'rebuilding' && (int) $player['age'] >= 29) {
            $interest -= 0.3;
            $reasons[] = "Doesn't fit our timeline";
        }

        // Youth + potential interest — young developing players have trade appeal
        // even if the team doesn't desperately "need" the position
        $age = (int) $player['age'];
        $potential = $player['potential'] ?? 'average';
        if ($age <= 25 && ($potential === 'elite' || $potential === 'high')) {
            $youthBonus = 0.15;
            if ($this->mode === 'rebuilding') $youthBonus = 0.25;
            if ($this->gmTraits['youth_bias'] > 1.0) $youthBonus *= $this->gmTraits['youth_bias'];
            $interest += $youthBonus;
            $reasons[] = "Young with upside";
        }

        // Depth interest — even if starter is better, teams value quality depth
        $depth = $this->getDepthAt($pos);
        $ideal = self::IDEAL_DEPTH[$pos] ?? 2;
        if ($depth < $ideal && $ovr >= 68) {
            $interest += 0.10;
            $reasons[] = "Adds depth at {$pos}";
        }

        // Baseline interest for decent players — any 75+ OVR player has some trade appeal
        if ($ovr >= 75 && $interest < 0.10) {
            $interest = 0.10;
            if (empty($reasons)) $reasons[] = "Solid player worth evaluating";
        }

        // Already stacked AND not an upgrade AND not young = very low interest
        if ($needAtPos < 0.05 && !$isUpgrade && $ovr < 85 && !($age <= 25 && $potential !== 'average' && $potential !== 'limited')) {
            $interest = max(0, $interest - 0.15);
            if ($interest <= 0) $reasons = ["Already set at {$pos}"];
        }

        $interest = max(0, min(1.0, $interest));

        return [
            'interested' => $interest >= 0.08,
            'interest_score' => round($interest, 2),
            'reasons' => $reasons,
            'contextual_value' => $this->valuePlayerForUs($player, $baseValue),
        ];
    }

    // ════════════════════════════════════════════════════════════════════
    //  GM ADVICE — For the user's team
    // ════════════════════════════════════════════════════════════════════

    /**
     * Generate GM advice about a trade opportunity FROM the user's perspective.
     */
    public function adviseOnTrade(
        array $opportunity, array $tradedPlayer, array $userBestOvrByPos
    ): string {
        $youSend = $opportunity['you_send'];
        $theySend = $opportunity['they_send'];
        $fairness = $opportunity['fairness'] ?? 0;
        $tradedName = ($tradedPlayer['first_name'] ?? '') . ' ' . ($tradedPlayer['last_name'] ?? '');
        $tradedPos = $tradedPlayer['position'];
        $tradedOvr = (int) $tradedPlayer['overall_rating'];
        $parts = [];

        // What we're giving up
        if ($tradedOvr >= 90) {
            $parts[] = "We'd be giving up an elite {$tradedPos} in {$tradedName}.";
        } elseif ($tradedOvr >= 82) {
            $parts[] = "We'd be moving a solid starter in {$tradedName}.";
        } else {
            $parts[] = "{$tradedName} is a depth piece — we can afford to move him.";
        }

        if (count($youSend['players']) > 1) {
            $extras = array_filter($youSend['players'], fn($p) => empty($p['is_selected']));
            if (!empty($extras)) {
                $names = array_map(fn($p) => $p['name'] . ' (' . $p['position'] . ')', $extras);
                $parts[] = "They also want " . implode(' and ', $names) . " from us.";
            }
        }
        if (!empty($youSend['picks'])) {
            $labels = array_map(fn($pk) => $pk['label'], $youSend['picks']);
            $parts[] = "We'd also be sending our " . implode(', ', $labels) . ".";
        }

        // Analyze what we're getting
        $fillsNeed = 0;
        foreach ($theySend['players'] as $ip) {
            $ipOvr = (int) $ip['overall_rating'];
            $ipAge = (int) $ip['age'];
            $ipPos = $ip['position'];
            $currentBest = $userBestOvrByPos[$ipPos] ?? 0;

            if (!empty($ip['fills_need'])) {
                $fillsNeed++;
                if ($currentBest > 0 && $ipOvr > $currentBest) {
                    $parts[] = "{$ip['name']} ({$ipOvr} OVR {$ipPos}) fills our need and would be a Day 1 starter over our current best ({$currentBest} OVR).";
                } else {
                    $parts[] = "{$ip['name']} ({$ipOvr} OVR {$ipPos}) addresses our need at {$ipPos}.";
                }
            } elseif ($ipOvr >= 82) {
                $parts[] = "{$ip['name']} is a quality {$ipPos} ({$ipOvr} OVR" . ($ipAge <= 25 ? ", only {$ipAge}" : '') . ").";
            } elseif ($ipAge <= 24 && $ipOvr >= 72) {
                $parts[] = "{$ip['name']} is young ({$ipAge}) with room to develop at {$ipPos}.";
            } else {
                $parts[] = "{$ip['name']} ({$ipOvr} OVR {$ipPos}) is a roster filler — not a difference-maker.";
            }
        }

        if (!empty($theySend['picks'])) {
            $hasFirst = false;
            foreach ($theySend['picks'] as $pk) {
                if ($pk['round'] === 1) $hasFirst = true;
            }
            if ($hasFirst) {
                $parts[] = "Getting a 1st-round pick gives us future flexibility.";
            } elseif (count($theySend['picks']) >= 2) {
                $parts[] = "Multiple draft picks add long-term value.";
            }
        }

        // Verdict
        $valueDiff = ($theySend['total_value'] ?? 0) - ($youSend['total_value'] ?? 0);

        if ($tradedOvr >= 88 && $fillsNeed === 0 && empty($theySend['picks'])) {
            $parts[] = "I'd pass on this, Coach. We're giving up a star and not addressing any roster needs.";
        } elseif ($valueDiff > 5 && $fillsNeed > 0) {
            $parts[] = "I like this deal. We're getting good value back and filling a need.";
        } elseif ($valueDiff > 5) {
            $parts[] = "The value is in our favor here. Worth considering.";
        } elseif ($valueDiff < -5 && $tradedOvr < 80) {
            $parts[] = "We're overpaying a bit, but {$tradedName} isn't a cornerstone piece.";
        } elseif ($valueDiff < -5) {
            $parts[] = "We'd be selling low here, Coach. I think we can do better.";
        } elseif ($fillsNeed > 0) {
            $parts[] = "Fair value, and it fills a hole on our roster. Solid move.";
        } elseif ($fairness > 0.95) {
            $parts[] = "Even deal. Comes down to whether we want to make this move.";
        } else {
            $parts[] = "It's a reasonable offer. Not a slam dunk, but not bad either.";
        }

        return implode(' ', $parts);
    }

    // ════════════════════════════════════════════════════════════════════
    //  ACCESSORS
    // ════════════════════════════════════════════════════════════════════

    public function getTeamId(): int { return $this->teamId; }
    public function getTeam(): array { return $this->team; }
    public function getMode(): string { return $this->mode; }
    public function getNeeds(): array { return $this->needs; }
    public function getStrengths(): array { return $this->strengths; }
    public function getWindowScore(): float { return $this->windowScore; }
    public function getDesperation(): float { return $this->desperation; }
    public function getFlexibility(): float { return $this->flexibility; }
    public function getGmTraits(): array { return $this->gmTraits; }
    public function getGmPersonality(): string { return $this->coach['gm_personality'] ?? 'balanced'; }
    public function getRosterStrength(): float { return $this->rosterStrength; }
    public function getRoster(): array { return $this->roster; }
    public function getDraftPicks(): array { return $this->draftPicks; }

    public function getBestOvrAt(string $position): int
    {
        $group = $this->positionGroups[$position] ?? [];
        return !empty($group) ? (int) $group[0]['overall_rating'] : 0;
    }

    public function getDepthAt(string $position): int
    {
        return count($this->positionGroups[$position] ?? []);
    }

    public function getTopNeeds(int $limit = 5): array
    {
        $sorted = $this->needs;
        arsort($sorted);
        $result = [];
        $i = 0;
        foreach ($sorted as $pos => $score) {
            if ($score < 0.05) continue;
            if ($i >= $limit) break;
            $group = $this->positionGroups[$pos] ?? [];
            $result[] = [
                'position' => $pos,
                'need_score' => round($score, 2),
                'roster_count' => count($group),
                'ideal_count' => self::IDEAL_DEPTH[$pos] ?? 2,
                'best_overall' => !empty($group) ? (int) $group[0]['overall_rating'] : 0,
            ];
            $i++;
        }
        return $result;
    }

    // ════════════════════════════════════════════════════════════════════
    //  PRIVATE HELPERS
    // ════════════════════════════════════════════════════════════════════

    /**
     * Evaluate a draft pick value (mirrors TradeEngine logic).
     */
    private function evaluatePick(int $round, int $pickInRound = 16, int $yearsOut = 0): float
    {
        $pickOverall = ($round - 1) * 32 + $pickInRound;
        $value = 150.0 * pow(1.0 / max(1, $pickOverall), 0.7);
        $value = max(0.5, $value);
        if ($yearsOut > 0) $value *= pow(0.65, $yearsOut);
        return round($value, 1);
    }
}
