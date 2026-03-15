<?php

namespace App\Services;

use App\Database\Connection;

/**
 * PlayerDecisionEngine — Every player "thinks" independently about contracts.
 *
 * Players evaluate offers through the lens of their personality, age, talent,
 * and current situation. No two players make decisions the same way.
 *
 * Five factors drive every decision:
 *   1. Money         — Is the offer fair? Am I being paid what I'm worth?
 *   2. Winning       — Can this team win a championship?
 *   3. Playing Time  — Will I start, or ride the bench?
 *   4. Loyalty       — Am I comfortable here? Do I want to stay?
 *   5. Market        — Big market glamour vs. small market opportunity
 */
class PlayerDecisionEngine
{
    private \PDO $db;
    private ContractEngine $contractEngine;

    // ── Default factor weights (personality modifies these) ───────────
    private const BASE_WEIGHTS = [
        'money'        => 0.35,
        'winning'      => 0.25,
        'playing_time' => 0.20,
        'loyalty'      => 0.10,
        'market'       => 0.10,
    ];

    // ── Big-market teams (top ~10 media markets) ─────────────────────
    private const BIG_MARKET_ABBRS = [
        'NYG', 'NYJ', 'DAL', 'LAR', 'LAC', 'CHI', 'NE', 'SF', 'PHI', 'MIA',
    ];

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
        $this->contractEngine = new ContractEngine();
    }

    // ================================================================
    //  Core: Evaluate a specific contract offer
    // ================================================================

    /**
     * Player evaluates a contract offer from any team.
     *
     * @return array{interested: bool, score: float, willingness: string, reasoning: string, counter_offer: ?array, factors: array}
     */
    public function evaluateContractOffer(int $playerId, int $offeringTeamId, int $annualSalary, int $years): array
    {
        $player = $this->loadPlayer($playerId);
        if (!$player) {
            return ['interested' => false, 'score' => 0, 'willingness' => 'refusing', 'reasoning' => 'Player not found.', 'counter_offer' => null, 'factors' => []];
        }

        $offeringTeam = $this->loadTeam($offeringTeamId);
        if (!$offeringTeam) {
            return ['interested' => false, 'score' => 0, 'willingness' => 'refusing', 'reasoning' => 'Team not found.', 'counter_offer' => null, 'factors' => []];
        }

        $currentTeam = $player['team_id'] ? $this->loadTeam((int) $player['team_id']) : null;
        $isCurrentTeam = $currentTeam && (int) $currentTeam['id'] === $offeringTeamId;
        $marketValue = $this->contractEngine->calculateMarketValue($player);
        $weights = $this->getWeightsForPlayer($player);

        // Calculate each factor (0-100 scale)
        $moneyScore = $this->scoreMoney($player, $annualSalary, $years, $marketValue);
        $winningScore = $this->scoreWinning($player, $offeringTeam);
        $playingTimeScore = $this->scorePlayingTime($player, $offeringTeamId);
        $loyaltyScore = $this->scoreLoyalty($player, $isCurrentTeam);
        $marketScore = $this->scoreMarket($player, $offeringTeam);

        $factors = [
            'money'        => ['score' => $moneyScore,      'weight' => $weights['money']],
            'winning'      => ['score' => $winningScore,     'weight' => $weights['winning']],
            'playing_time' => ['score' => $playingTimeScore, 'weight' => $weights['playing_time']],
            'loyalty'      => ['score' => $loyaltyScore,     'weight' => $weights['loyalty']],
            'market'       => ['score' => $marketScore,      'weight' => $weights['market']],
        ];

        // Weighted composite score
        $totalScore = 0;
        foreach ($factors as $f) {
            $totalScore += $f['score'] * $f['weight'];
        }

        // Personality noise: small random nudge so identical situations don't always produce identical results
        $noise = mt_rand(-3, 3);
        $totalScore = max(0, min(100, $totalScore + $noise));

        // Determine willingness
        $willingness = $this->scoreToWillingness($totalScore);
        $interested = in_array($willingness, ['eager', 'willing']);
        $reasoning = $this->generateReasoning($player, $factors, $willingness, $isCurrentTeam, $annualSalary, $marketValue);

        // Counter offer if reluctant
        $counterOffer = null;
        if ($willingness === 'reluctant') {
            $counterOffer = $this->generateCounterOffer($player, $annualSalary, $years, $marketValue, $totalScore);
        }

        return [
            'interested'    => $interested,
            'score'         => round($totalScore, 1),
            'willingness'   => $willingness,
            'reasoning'     => $reasoning,
            'counter_offer' => $counterOffer,
            'factors'       => $factors,
            'market_value'  => $marketValue,
        ];
    }

    // ================================================================
    //  Extension: Current team re-sign evaluation
    // ================================================================

    /**
     * Evaluate an extension offer from the player's current team.
     * Current team gets a loyalty bonus (+10-15 points).
     */
    public function evaluateExtensionOffer(int $playerId, int $annualSalary, int $years): array
    {
        $player = $this->loadPlayer($playerId);
        if (!$player || !$player['team_id']) {
            return ['interested' => false, 'score' => 0, 'willingness' => 'refusing', 'reasoning' => 'Player has no current team.', 'counter_offer' => null, 'factors' => []];
        }

        $teamId = (int) $player['team_id'];
        $result = $this->evaluateContractOffer($playerId, $teamId, $annualSalary, $years);

        // Apply loyalty bonus for current team
        $loyaltyBonus = $this->calculateLoyaltyBonus($player);
        $result['score'] = min(100, $result['score'] + $loyaltyBonus);
        $result['willingness'] = $this->scoreToWillingness($result['score']);
        $result['interested'] = in_array($result['willingness'], ['eager', 'willing']);

        // Recalculate reasoning with loyalty context
        if ($loyaltyBonus > 0) {
            $result['loyalty_bonus'] = $loyaltyBonus;
        }

        // If the adjusted score makes them willing, clear the counter offer
        if ($result['interested'] && $result['counter_offer']) {
            $result['counter_offer'] = null;
        }

        // If still reluctant, generate a counter
        if ($result['willingness'] === 'reluctant' && !$result['counter_offer']) {
            $marketValue = $this->contractEngine->calculateMarketValue($player);
            $result['counter_offer'] = $this->generateCounterOffer($player, $annualSalary, $years, $marketValue, $result['score']);
        }

        return $result;
    }

    // ================================================================
    //  Quick check: Would player re-sign with current team?
    // ================================================================

    /**
     * Quick assessment of whether a player is open to re-signing.
     */
    public function wouldReSignWithCurrentTeam(int $playerId): array
    {
        $player = $this->loadPlayer($playerId);
        if (!$player || !$player['team_id']) {
            return [
                'open_to_extension' => false,
                'minimum_salary' => 0,
                'preferred_years' => 0,
                'reasoning' => 'Player has no current team.',
            ];
        }

        $marketValue = $this->contractEngine->calculateMarketValue($player);
        $team = $this->loadTeam((int) $player['team_id']);
        $personality = $player['personality'] ?? 'team_player';
        $morale = $player['morale'] ?? 'content';
        $age = (int) $player['age'];
        $ovr = (int) $player['overall_rating'];
        $experience = (int) ($player['experience'] ?? 0);

        // Base openness to re-signing
        $openness = 60; // start at moderate openness

        // Morale impact
        $openness += match ($morale) {
            'ecstatic' => 20,
            'happy'    => 10,
            'content'  => 0,
            'frustrated' => -15,
            'angry'    => -30,
            default    => 0,
        };

        // Personality impact
        $openness += match ($personality) {
            'team_player'         => 15,
            'quiet_professional'  => 5,
            'vocal_leader'        => 0,
            'competitor'          => -5,
            'troublemaker'        => -15,
            default               => 0,
        };

        // Tenure bonus
        if ($experience >= 3) $openness += 10;
        if ($experience >= 5) $openness += 5;

        // Winning team bonus
        if ($team) {
            $wins = (int) ($team['wins'] ?? 0);
            $losses = (int) ($team['losses'] ?? 0);
            $winPct = ($wins + $losses > 0) ? $wins / ($wins + $losses) : 0.5;
            if ($winPct >= 0.625) $openness += 10; // Winning team
            if ($winPct < 0.375) $openness -= 10; // Losing team
        }

        // Young stars want to test the market
        if ($age <= 25 && $ovr >= 80) {
            $openness -= 15;
        }

        // Aging veterans want security
        if ($age >= 32) {
            $openness += 15;
        }

        $openness = max(0, min(100, $openness));
        $isOpen = $openness >= 45;

        // Calculate minimum salary they'd accept
        $minimumSalary = $this->calculateMinimumAcceptable($player, $marketValue, $openness);

        // Preferred years
        $preferredYears = $this->calculatePreferredYears($player);

        // Generate reasoning
        $reasoning = $this->generateWouldReSignReasoning($player, $team, $openness, $morale, $personality);

        return [
            'open_to_extension' => $isOpen,
            'openness_score'    => $openness,
            'minimum_salary'    => $minimumSalary,
            'preferred_years'   => $preferredYears,
            'market_value'      => $marketValue,
            'reasoning'         => $reasoning,
        ];
    }

    // ================================================================
    //  Free agency: Rank multiple offers
    // ================================================================

    /**
     * Player ranks multiple FA offers by preference (not just highest money).
     *
     * @param array $offers [['team_id' => int, 'salary' => int, 'years' => int], ...]
     * @return array Sorted offers with scores and reasoning
     */
    public function rankFreeAgencyOffers(int $playerId, array $offers): array
    {
        $player = $this->loadPlayer($playerId);
        if (!$player || empty($offers)) {
            return [];
        }

        $results = [];

        foreach ($offers as $offer) {
            $teamId = (int) $offer['team_id'];
            $salary = (int) $offer['salary'];
            $years = (int) $offer['years'];

            $evaluation = $this->evaluateContractOffer($playerId, $teamId, $salary, $years);
            $team = $this->loadTeam($teamId);

            $results[] = [
                'team_id'     => $teamId,
                'team_name'   => $team ? ($team['city'] . ' ' . $team['name']) : 'Unknown',
                'salary'      => $salary,
                'years'       => $years,
                'score'       => $evaluation['score'],
                'willingness' => $evaluation['willingness'],
                'reasoning'   => $evaluation['reasoning'],
                'factors'     => $evaluation['factors'],
            ];
        }

        // Sort by score descending
        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);

        return $results;
    }

    // ================================================================
    //  Player preferences (public info for UI)
    // ================================================================

    /**
     * Returns what this player values most, for display purposes.
     */
    public function getPlayerPreferences(int $playerId): array
    {
        $player = $this->loadPlayer($playerId);
        if (!$player) {
            return ['money_weight' => 0.35, 'winning_weight' => 0.25, 'playing_time_weight' => 0.20, 'loyalty_weight' => 0.10, 'market_weight' => 0.10, 'priorities' => []];
        }

        $weights = $this->getWeightsForPlayer($player);
        $priorities = $this->describePriorities($player, $weights);

        return [
            'money_weight'        => round($weights['money'], 2),
            'winning_weight'      => round($weights['winning'], 2),
            'playing_time_weight' => round($weights['playing_time'], 2),
            'loyalty_weight'      => round($weights['loyalty'], 2),
            'market_weight'       => round($weights['market'], 2),
            'priorities'          => $priorities,
        ];
    }

    // ================================================================
    //  Factor scoring (each returns 0-100)
    // ================================================================

    /**
     * Money score: How does the offer compare to market value?
     */
    private function scoreMoney(array $player, int $salary, int $years, int $marketValue): float
    {
        if ($marketValue <= 0) return 50;

        $ratio = $salary / $marketValue;

        // Base score from ratio (1.0 = market value = 70 points)
        if ($ratio >= 1.2) {
            $score = 95; // Way above market
        } elseif ($ratio >= 1.1) {
            $score = 88;
        } elseif ($ratio >= 1.0) {
            $score = 75;
        } elseif ($ratio >= 0.9) {
            $score = 60;
        } elseif ($ratio >= 0.8) {
            $score = 45;
        } elseif ($ratio >= 0.7) {
            $score = 30;
        } else {
            $score = 15; // Lowball
        }

        // Older players value security (longer deals)
        $age = (int) $player['age'];
        if ($age >= 30 && $years >= 3) {
            $score += 5; // Security bonus
        }
        if ($age >= 32 && $years >= 2) {
            $score += 5;
        }

        // Young stars want max money
        $ovr = (int) $player['overall_rating'];
        if ($age <= 25 && $ovr >= 80 && $ratio < 0.95) {
            $score -= 10; // "I know I'm worth more"
        }

        return max(0, min(100, $score));
    }

    /**
     * Winning score: How competitive is this team?
     */
    private function scoreWinning(array $player, array $team): float
    {
        $wins = (int) ($team['wins'] ?? 0);
        $losses = (int) ($team['losses'] ?? 0);
        $totalGames = $wins + $losses;
        $winPct = $totalGames > 0 ? $wins / $totalGames : 0.5;
        $teamRating = (int) ($team['overall_rating'] ?? 75);

        // Win percentage score (0.500 = 50 points, 0.750 = 85 points)
        $score = $winPct * 100;

        // Team overall rating influence
        if ($teamRating >= 85) $score += 10;
        elseif ($teamRating >= 80) $score += 5;
        elseif ($teamRating < 70) $score -= 10;

        return max(0, min(100, $score));
    }

    /**
     * Playing time score: Will this player start on this team?
     */
    private function scorePlayingTime(array $player, int $teamId): float
    {
        $ovr = (int) $player['overall_rating'];
        $pos = $player['position'] ?? 'LB';

        // Find the best player at this position on the target team
        $stmt = $this->db->prepare(
            "SELECT MAX(overall_rating) as best_ovr FROM players
             WHERE team_id = ? AND position = ? AND status = 'active' AND id != ?"
        );
        $stmt->execute([$teamId, $pos, $player['id']]);
        $bestAtPos = (int) ($stmt->fetch()['best_ovr'] ?? 0);

        if ($bestAtPos === 0) {
            return 95; // No competition, guaranteed starter
        }

        $diff = $ovr - $bestAtPos;

        if ($diff >= 5) return 95;       // Clear starter
        if ($diff >= 0) return 80;       // Likely starter
        if ($diff >= -5) return 60;      // Competition
        if ($diff >= -10) return 35;     // Likely backup
        return 15;                        // Deep bench
    }

    /**
     * Loyalty score: How attached is the player to their current team?
     */
    private function scoreLoyalty(array $player, bool $isCurrentTeam): float
    {
        if (!$isCurrentTeam) {
            return 40; // Baseline for a new team — no loyalty bonus, no penalty
        }

        $score = 65; // Base loyalty for current team
        $experience = (int) ($player['experience'] ?? 0);
        $morale = $player['morale'] ?? 'content';

        // Tenure bonus
        if ($experience >= 5) $score += 15;
        elseif ($experience >= 3) $score += 10;
        elseif ($experience >= 1) $score += 5;

        // Morale
        $score += match ($morale) {
            'ecstatic'   => 15,
            'happy'      => 8,
            'content'    => 0,
            'frustrated' => -12,
            'angry'      => -25,
            default      => 0,
        };

        return max(0, min(100, $score));
    }

    /**
     * Market score: Does the player prefer this team's market size?
     */
    private function scoreMarket(array $player, array $team): float
    {
        $abbr = $team['abbreviation'] ?? '';
        $isBigMarket = in_array($abbr, self::BIG_MARKET_ABBRS);
        $personality = $player['personality'] ?? 'team_player';

        // Most players are neutral (score 50)
        $score = 50;

        if ($personality === 'vocal_leader' || $personality === 'troublemaker') {
            // Likes the spotlight
            $score = $isBigMarket ? 75 : 35;
        } elseif ($personality === 'quiet_professional') {
            // Doesn't care about market
            $score = 50;
        } elseif ($personality === 'team_player') {
            // Slight preference for smaller markets (less media pressure)
            $score = $isBigMarket ? 45 : 55;
        } elseif ($personality === 'competitor') {
            // Wants to win — market is secondary but big markets often = bigger stage
            $score = $isBigMarket ? 55 : 50;
        }

        return max(0, min(100, $score));
    }

    // ================================================================
    //  Weight customization by personality
    // ================================================================

    /**
     * Adjust the base weights based on player personality, age, and talent.
     */
    private function getWeightsForPlayer(array $player): array
    {
        $weights = self::BASE_WEIGHTS;
        $personality = $player['personality'] ?? 'team_player';
        $age = (int) $player['age'];
        $ovr = (int) $player['overall_rating'];

        // Personality-driven adjustments
        switch ($personality) {
            case 'troublemaker':
                // Wants the most money, period
                $weights['money'] = 0.50;
                $weights['winning'] = 0.15;
                $weights['loyalty'] = 0.05;
                $weights['playing_time'] = 0.15;
                $weights['market'] = 0.15;
                break;

            case 'competitor':
                // Wants to win championships
                $weights['money'] = 0.25;
                $weights['winning'] = 0.40;
                $weights['playing_time'] = 0.20;
                $weights['loyalty'] = 0.05;
                $weights['market'] = 0.10;
                break;

            case 'vocal_leader':
                // Values winning and being "the guy"
                $weights['money'] = 0.25;
                $weights['winning'] = 0.35;
                $weights['playing_time'] = 0.25;
                $weights['loyalty'] = 0.05;
                $weights['market'] = 0.10;
                break;

            case 'team_player':
                // Loyal, wants to stay
                $weights['money'] = 0.25;
                $weights['winning'] = 0.20;
                $weights['playing_time'] = 0.15;
                $weights['loyalty'] = 0.25;
                $weights['market'] = 0.15;
                break;

            case 'quiet_professional':
                // Balanced, cares about playing time
                $weights['money'] = 0.30;
                $weights['winning'] = 0.25;
                $weights['playing_time'] = 0.25;
                $weights['loyalty'] = 0.15;
                $weights['market'] = 0.05;
                break;
        }

        // Age adjustments
        if ($age >= 32) {
            // Veterans value security and winning
            $weights['money'] += 0.05;
            $weights['winning'] += 0.05;
            $weights['playing_time'] -= 0.05;
            $weights['market'] -= 0.05;
        }

        if ($age <= 25 && $ovr >= 80) {
            // Young stars want to get paid
            $weights['money'] += 0.08;
            $weights['loyalty'] -= 0.05;
            $weights['market'] -= 0.03;
        }

        // Normalize to 1.0
        $total = array_sum($weights);
        if ($total > 0) {
            foreach ($weights as &$w) {
                $w = $w / $total;
            }
        }

        return $weights;
    }

    // ================================================================
    //  Helpers
    // ================================================================

    private function loadPlayer(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM players WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    private function loadTeam(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM teams WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    private function scoreToWillingness(float $score): string
    {
        if ($score >= 75) return 'eager';
        if ($score >= 55) return 'willing';
        if ($score >= 35) return 'reluctant';
        return 'refusing';
    }

    private function calculateLoyaltyBonus(array $player): int
    {
        $personality = $player['personality'] ?? 'team_player';
        $morale = $player['morale'] ?? 'content';
        $experience = (int) ($player['experience'] ?? 0);

        $bonus = 10; // Base loyalty bonus for current team

        // Personality modifiers
        $bonus += match ($personality) {
            'team_player'        => 5,
            'quiet_professional' => 3,
            'vocal_leader'       => 0,
            'competitor'         => -2,
            'troublemaker'       => -5,
            default              => 0,
        };

        // Morale modifiers
        $bonus += match ($morale) {
            'ecstatic'   => 5,
            'happy'      => 3,
            'content'    => 0,
            'frustrated' => -5,
            'angry'      => -8,
            default      => 0,
        };

        // Tenure
        if ($experience >= 5) $bonus += 3;
        elseif ($experience >= 3) $bonus += 2;

        return max(0, $bonus);
    }

    private function calculateMinimumAcceptable(array $player, int $marketValue, int $openness): int
    {
        $personality = $player['personality'] ?? 'team_player';
        $age = (int) $player['age'];

        // Base: a percentage of market value
        $minPct = match ($personality) {
            'troublemaker'       => 1.05, // Wants above market
            'competitor'         => 0.85, // Will take discount to win
            'vocal_leader'       => 0.90,
            'team_player'        => 0.80, // Most willing to take discount
            'quiet_professional' => 0.88,
            default              => 0.90,
        };

        // Older players accept less
        if ($age >= 32) $minPct -= 0.08;
        if ($age >= 35) $minPct -= 0.05;

        // Young stars demand more
        if ($age <= 25 && (int) $player['overall_rating'] >= 80) {
            $minPct += 0.05;
        }

        // High openness = willing to take less
        if ($openness >= 70) $minPct -= 0.05;

        return max(ContractEngine::VETERAN_MINIMUM, (int) ($marketValue * $minPct));
    }

    private function calculatePreferredYears(array $player): int
    {
        $age = (int) $player['age'];
        $ovr = (int) $player['overall_rating'];

        if ($age >= 34) return 1;
        if ($age >= 32) return mt_rand(1, 2);
        if ($age >= 30) return mt_rand(2, 3);
        if ($ovr >= 85 && $age <= 28) return mt_rand(4, 5);
        if ($ovr >= 80) return mt_rand(3, 4);
        if ($ovr >= 75) return mt_rand(2, 3);
        return mt_rand(1, 2);
    }

    private function generateCounterOffer(array $player, int $offeredSalary, int $offeredYears, int $marketValue, float $currentScore): ?array
    {
        $personality = $player['personality'] ?? 'team_player';
        $age = (int) $player['age'];

        // How much more do they want?
        $gap = 55 - $currentScore; // How far from "willing" (55)
        $multiplier = 1.0 + ($gap / 200); // Modest bump
        $counterSalary = max($offeredSalary, (int) ($marketValue * $multiplier));

        // Personality tweaks
        if ($personality === 'troublemaker') {
            $counterSalary = (int) ($counterSalary * 1.10); // Always wants more
        } elseif ($personality === 'team_player') {
            $counterSalary = (int) ($counterSalary * 0.98); // More reasonable
        }

        // Preferred years
        $counterYears = $this->calculatePreferredYears($player);
        if ($age >= 30 && $offeredYears < 2) {
            $counterYears = max($counterYears, 2); // Wants security
        }

        // Don't counter with the same or worse deal
        if ($counterSalary <= $offeredSalary && $counterYears === $offeredYears) {
            $counterSalary = (int) ($offeredSalary * 1.10);
        }

        return [
            'salary' => $counterSalary,
            'years'  => $counterYears,
        ];
    }

    // ================================================================
    //  Reasoning generators — make it feel like a real player
    // ================================================================

    private function generateReasoning(array $player, array $factors, string $willingness, bool $isCurrentTeam, int $salary, int $marketValue): string
    {
        $name = $player['first_name'] ?? 'Player';
        $personality = $player['personality'] ?? 'team_player';
        $age = (int) $player['age'];

        // Find the dominant factor
        $maxFactor = '';
        $maxWeighted = 0;
        foreach ($factors as $key => $f) {
            $weighted = $f['score'] * $f['weight'];
            if ($weighted > $maxWeighted) {
                $maxWeighted = $weighted;
                $maxFactor = $key;
            }
        }

        // Find the weakest factor
        $minFactor = '';
        $minWeighted = 999;
        foreach ($factors as $key => $f) {
            $weighted = $f['score'] * $f['weight'];
            if ($weighted < $minWeighted) {
                $minWeighted = $weighted;
                $minFactor = $key;
            }
        }

        $ratio = $marketValue > 0 ? $salary / $marketValue : 1;

        if ($willingness === 'eager') {
            return $this->eagerQuote($personality, $maxFactor, $isCurrentTeam, $age, $ratio);
        }

        if ($willingness === 'willing') {
            return $this->willingQuote($personality, $maxFactor, $isCurrentTeam, $age);
        }

        if ($willingness === 'reluctant') {
            return $this->reluctantQuote($personality, $minFactor, $isCurrentTeam, $age, $ratio);
        }

        // Refusing
        return $this->refusingQuote($personality, $minFactor, $isCurrentTeam, $ratio);
    }

    private function eagerQuote(string $personality, string $topFactor, bool $currentTeam, int $age, float $moneyRatio): string
    {
        $quotes = [];

        if ($currentTeam) {
            $quotes = [
                "I love it here and want to finish my career in this city.",
                "This is home. Let's get this done.",
                "I've built something special here and I'm not leaving.",
                "The fans, the coaches, the guys in the locker room — this is where I belong.",
            ];
        } elseif ($topFactor === 'money' || $moneyRatio >= 1.1) {
            $quotes = [
                "That's a deal that takes care of my family. I'm in.",
                "The money is right. Let's do this.",
                "You're showing me the respect I deserve. I'm ready to sign.",
            ];
        } elseif ($topFactor === 'winning') {
            $quotes = [
                "I want a ring. This team can get me there.",
                "I've seen what this team is building. I want to be part of it.",
                "Winning is everything. This roster is special.",
            ];
        } elseif ($topFactor === 'playing_time') {
            $quotes = [
                "I want to be THE guy. This is my opportunity to prove it.",
                "A chance to start and show what I can do? Sign me up.",
            ];
        } else {
            $quotes = [
                "Everything about this opportunity feels right.",
                "The fit is perfect. I'm excited about this.",
            ];
        }

        return $quotes[array_rand($quotes)];
    }

    private function willingQuote(string $personality, string $topFactor, bool $currentTeam, int $age): string
    {
        if ($currentTeam) {
            return match ($personality) {
                'team_player'        => "I'm comfortable here. The deal is fair — let's make it work.",
                'competitor'         => "If this team is committed to winning, I can see myself staying.",
                'quiet_professional' => "The situation works for me. I'm willing to stay.",
                'vocal_leader'       => "I want to keep leading this group. Let's talk numbers.",
                default              => "It's a solid offer. I'm open to it.",
            };
        }

        if ($age >= 32) {
            return "At this point in my career, stability matters. This could work.";
        }

        return match ($topFactor) {
            'money'        => "The money is fair. I can see myself here.",
            'winning'      => "I like what this team is building. The offer is reasonable.",
            'playing_time' => "I like the opportunity to compete for a starting spot.",
            default        => "It's a solid offer. I'm interested.",
        };
    }

    private function reluctantQuote(string $personality, string $weakFactor, bool $currentTeam, int $age, float $moneyRatio): string
    {
        if ($weakFactor === 'money' || $moneyRatio < 0.85) {
            return match ($personality) {
                'troublemaker' => "Show me the money. That offer is disrespectful.",
                'competitor'   => "I understand the team has priorities, but I've earned more than that.",
                'vocal_leader' => "I'm a leader on this team. Pay me like one.",
                default        => "I appreciate the interest, but the numbers need to come up.",
            };
        }

        if ($weakFactor === 'winning' && !$currentTeam) {
            return "I need to see a plan to compete. I don't want to waste my prime years.";
        }

        if ($weakFactor === 'playing_time') {
            return "I'm not interested in a backup role. I want to play.";
        }

        if (!$currentTeam && $weakFactor === 'loyalty') {
            return "I need to see what's out there. Leaving home isn't easy.";
        }

        return "I'm not saying no, but we're not there yet. Let's keep talking.";
    }

    private function refusingQuote(string $personality, string $weakFactor, bool $currentTeam, float $moneyRatio): string
    {
        if ($moneyRatio < 0.7) {
            return match ($personality) {
                'troublemaker' => "That's insulting. We're done here.",
                default        => "With all due respect, that offer doesn't reflect my value.",
            };
        }

        if ($weakFactor === 'winning') {
            return match ($personality) {
                'competitor'   => "I'm not going to a team that can't compete. My window is closing.",
                'vocal_leader' => "I want to win. That team isn't there yet.",
                default        => "I've thought about it, and it's just not the right fit.",
            };
        }

        if ($weakFactor === 'playing_time') {
            return "I'm not going somewhere to sit on the bench. I need to play.";
        }

        return "I've weighed everything and I have to pass. Nothing personal.";
    }

    private function generateWouldReSignReasoning(array $player, ?array $team, int $openness, string $morale, string $personality): string
    {
        $teamName = $team ? $team['name'] : 'the team';
        $age = (int) $player['age'];

        if ($openness >= 75) {
            return match ($personality) {
                'team_player'  => "I want to stay with the {$teamName}. This is home.",
                'competitor'   => "If {$teamName} makes a fair offer, I'd love to run it back.",
                'vocal_leader' => "I'm the heart of this team. I want to be here long-term.",
                default        => "I'm happy here and open to an extension.",
            };
        }

        if ($openness >= 55) {
            if ($morale === 'frustrated' || $morale === 'angry') {
                return "Things haven't been great, but I'm not closing the door. Show me it can get better.";
            }
            return "I'd consider an extension if the terms are right. But I'm not ruling anything out.";
        }

        if ($openness >= 40) {
            if ($age <= 25) {
                return "I'm still young. I need to see what the market has for me before committing.";
            }
            return "I need to weigh my options. I'm not sure staying is the best move.";
        }

        // Low openness
        if ($morale === 'angry') {
            return "I need a fresh start. It's time to move on.";
        }

        return match ($personality) {
            'troublemaker' => "I'm looking for a change. Time to test the market.",
            'competitor'   => "I don't think {$teamName} can win it all. I need to explore my options.",
            default        => "I think a change of scenery would be best for everyone.",
        };
    }

    private function describePriorities(array $player, array $weights): array
    {
        // Sort by weight descending
        arsort($weights);
        $priorities = [];

        foreach ($weights as $factor => $weight) {
            if ($weight >= 0.30) {
                $priorities[] = match ($factor) {
                    'money'        => 'Getting paid what he\'s worth',
                    'winning'      => 'Winning a championship',
                    'playing_time' => 'Starting and being a featured player',
                    'loyalty'      => 'Staying with his current team',
                    'market'       => 'Playing in a major market',
                    default        => ucfirst(str_replace('_', ' ', $factor)),
                };
            } elseif ($weight >= 0.20) {
                $priorities[] = match ($factor) {
                    'money'        => 'Fair compensation',
                    'winning'      => 'Competitive team',
                    'playing_time' => 'Significant playing time',
                    'loyalty'      => 'Team loyalty',
                    'market'       => 'Market fit',
                    default        => ucfirst(str_replace('_', ' ', $factor)),
                };
            }
        }

        return $priorities;
    }
}
