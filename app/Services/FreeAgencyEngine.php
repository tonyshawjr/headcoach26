<?php

namespace App\Services;

use App\Database\Connection;

class FreeAgencyEngine
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
        $this->ensureRfaColumns();
    }

    /**
     * Idempotent column migration for RFA fields on free_agents.
     */
    private function ensureRfaColumns(): void
    {
        try {
            $cols = $this->db->query("PRAGMA table_info(free_agents)")->fetchAll(\PDO::FETCH_ASSOC);
            $names = array_column($cols, 'name');
            if (!in_array('is_restricted', $names, true)) {
                $this->db->exec("ALTER TABLE free_agents ADD COLUMN is_restricted INTEGER NOT NULL DEFAULT 0");
            }
            if (!in_array('tender_level', $names, true)) {
                $this->db->exec("ALTER TABLE free_agents ADD COLUMN tender_level VARCHAR(20) NULL");
            }
            if (!in_array('tender_salary', $names, true)) {
                $this->db->exec("ALTER TABLE free_agents ADD COLUMN tender_salary INTEGER NOT NULL DEFAULT 0");
            }
            if (!in_array('original_team_id', $names, true)) {
                $this->db->exec("ALTER TABLE free_agents ADD COLUMN original_team_id INT NULL");
            }
            if (!in_array('original_draft_round', $names, true)) {
                $this->db->exec("ALTER TABLE free_agents ADD COLUMN original_draft_round INT NULL");
            }

            // Ensure rfa_offer_sheets table
            $this->db->exec("CREATE TABLE IF NOT EXISTS rfa_offer_sheets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                free_agent_id INT NOT NULL,
                offering_team_id INT NOT NULL,
                salary INTEGER NOT NULL,
                years INT NOT NULL DEFAULT 1,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                matching_deadline DATETIME NULL,
                created_at DATETIME NOT NULL,
                resolved_at DATETIME NULL,
                FOREIGN KEY (free_agent_id) REFERENCES free_agents(id),
                FOREIGN KEY (offering_team_id) REFERENCES teams(id)
            )");
        } catch (\Throwable $e) {
            // Non-critical -- columns may already exist
        }
    }

    // ================================================================
    //  Existing methods (unchanged)
    // ================================================================

    /**
     * Release a player to free agency.
     * Applies dead cap calculations via ContractEngine when cutting a player with a contract.
     *
     * @param int  $leagueId    The league ID
     * @param int  $playerId    The player to release
     * @param bool $postJune1   If true, use post-June-1 designation to split dead cap over 2 years
     * @return int  The free_agents row ID, or 0 on failure
     */
    public function releasePlayer(int $leagueId, int $playerId, bool $postJune1 = false): int
    {
        $player = $this->getPlayer($playerId);
        if (!$player) return 0;

        // Calculate market value based on rating, age, position
        $marketValue = $this->calculateMarketValue($player);

        // Determine the team releasing the player (before we null it)
        $originalTeamId = $player['team_id'] ?? null;

        // Check if this player qualifies as a restricted free agent
        $isRestricted = $this->isRestrictedFreeAgent($player);
        $originalDraftRound = null;
        if ($isRestricted && $originalTeamId) {
            $originalDraftRound = $this->getPlayerOriginalDraftRound($playerId);
        }

        // Apply dead cap via ContractEngine (handles signing bonus proration, guaranteed money, etc.)
        $deadCapResult = null;
        $contractEngine = new ContractEngine();
        $deadCapResult = $contractEngine->cutPlayer($playerId, $postJune1);

        // Remove from team
        $this->db->prepare("UPDATE players SET team_id = NULL, status = 'free_agent' WHERE id = ?")
            ->execute([$playerId]);

        // Remove from depth chart
        $this->db->prepare("DELETE FROM depth_chart WHERE player_id = ?")->execute([$playerId]);

        // Create free agent listing
        $this->db->prepare(
            "INSERT INTO free_agents (league_id, player_id, asking_salary, market_value, status, released_at, is_restricted, original_team_id, original_draft_round)
             VALUES (?, ?, ?, ?, 'available', ?, ?, ?, ?)"
        )->execute([
            $leagueId, $playerId, $marketValue, $marketValue, date('Y-m-d H:i:s'),
            $isRestricted ? 1 : 0,
            $isRestricted ? $originalTeamId : null,
            $originalDraftRound,
        ]);

        // Store the dead cap result for callers that need it
        $this->lastDeadCapResult = $deadCapResult;

        return (int) $this->db->lastInsertId();
    }

    /**
     * Get the dead cap result from the most recent releasePlayer() call.
     */
    public function getLastDeadCapResult(): ?array
    {
        return $this->lastDeadCapResult ?? null;
    }

    /** @var array|null Dead cap result from the last release operation */
    private ?array $lastDeadCapResult = null;

    /**
     * Release a player specifically as a restricted free agent.
     * Called during offseason when contracts expire for young players.
     * Note: RFA releases are contract expirations, not cuts, so no dead cap applies.
     */
    public function releaseAsRestricted(int $leagueId, int $playerId, int $originalTeamId): int
    {
        $player = $this->getPlayer($playerId);
        if (!$player) return 0;

        $marketValue = $this->calculateMarketValue($player);
        $originalDraftRound = $this->getPlayerOriginalDraftRound($playerId);

        // Remove from depth chart (player stays associated with team until tender set)
        $this->db->prepare("DELETE FROM depth_chart WHERE player_id = ?")->execute([$playerId]);

        // Update player status
        $this->db->prepare("UPDATE players SET team_id = NULL, status = 'free_agent' WHERE id = ?")
            ->execute([$playerId]);

        // Mark contract as completed (expired, not terminated -- no dead cap for RFA expirations)
        $this->db->prepare("UPDATE contracts SET status = 'completed', years_remaining = 0 WHERE player_id = ? AND status = 'active'")
            ->execute([$playerId]);

        // Create restricted free agent listing
        $this->db->prepare(
            "INSERT INTO free_agents (league_id, player_id, asking_salary, market_value, status, released_at, is_restricted, original_team_id, original_draft_round)
             VALUES (?, ?, ?, ?, 'available', ?, 1, ?, ?)"
        )->execute([
            $leagueId, $playerId, $marketValue, $marketValue, date('Y-m-d H:i:s'),
            $originalTeamId, $originalDraftRound,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Get available free agents for a league.
     */
    public function getAvailable(int $leagueId, ?string $position = null, int $limit = 50): array
    {
        $sql = "SELECT fa.*, p.first_name, p.last_name, p.position, p.overall_rating, p.age, p.potential, p.image_url, p.years_pro,
                       t_orig.abbreviation as original_team_abbr, t_orig.city as original_team_city, t_orig.name as original_team_name
                FROM free_agents fa
                JOIN players p ON fa.player_id = p.id
                LEFT JOIN teams t_orig ON fa.original_team_id = t_orig.id
                WHERE fa.league_id = ? AND fa.status = 'available'";
        $params = [$leagueId];

        if ($position) {
            $sql .= " AND p.position = ?";
            $params[] = $position;
        }

        $sql .= " ORDER BY p.overall_rating DESC LIMIT ?";
        $params[] = $limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $agents = $stmt->fetchAll();

        // Attach pending offer sheet info for RFAs
        foreach ($agents as &$agent) {
            if ($agent['is_restricted']) {
                $agent['offer_sheet'] = $this->getPendingOfferSheet((int) $agent['id']);
            }
        }

        return $agents;
    }

    /**
     * Place a bid on a free agent.
     * Restricted free agents that haven't been tendered cannot receive bids.
     * Restricted free agents that have been tendered receive offer sheets instead.
     */
    public function placeBid(int $freeAgentId, int $teamId, int $coachId, int $salaryOffer, int $yearsOffer): array
    {
        $fa = $this->getFreeAgent($freeAgentId);
        if (!$fa || $fa['status'] !== 'available') {
            return ['error' => 'Free agent not available'];
        }

        // If restricted, redirect to offer sheet flow
        if (!empty($fa['is_restricted'])) {
            if (empty($fa['tender_level'])) {
                return ['error' => 'This restricted free agent has not been tendered yet. The original team must set a tender first.'];
            }
            // If bidding team is the original team, they should use match instead
            if ((int) $fa['original_team_id'] === $teamId) {
                return ['error' => 'As the original team, use the match/decline endpoint instead of bidding.'];
            }
            // Route through offer sheet
            return $this->makeOfferSheet($freeAgentId, $teamId, $salaryOffer, $yearsOffer);
        }

        // Check team cap space
        $team = $this->getTeam($teamId);
        $capRemaining = ($team['salary_cap'] ?? 225000000) - ($team['cap_used'] ?? 0);
        if ($salaryOffer > $capRemaining) {
            return ['error' => 'Salary offer exceeds cap space'];
        }

        // Minimum offer is 60% of market value
        if ($salaryOffer < $fa['market_value'] * 0.6) {
            return ['error' => 'Offer too low -- minimum is 60% of market value'];
        }

        $this->db->prepare(
            "INSERT INTO fa_bids (free_agent_id, team_id, coach_id, salary_offer, years_offer, is_winning, created_at)
             VALUES (?, ?, ?, ?, ?, 0, ?)"
        )->execute([$freeAgentId, $teamId, $coachId, $salaryOffer, $yearsOffer, date('Y-m-d H:i:s')]);

        return [
            'success' => true,
            'bid_id' => (int) $this->db->lastInsertId(),
        ];
    }

    /**
     * Resolve free agency bidding for a player (pick the winner).
     */
    public function resolveBidding(int $freeAgentId): ?array
    {
        $bids = $this->getBids($freeAgentId);
        if (empty($bids)) return null;

        // Evaluate bids: highest salary * years + team morale bonus
        $bestBid = null;
        $bestScore = 0;

        foreach ($bids as $bid) {
            $team = $this->getTeam($bid['team_id']);
            $totalValue = $bid['salary_offer'] * $bid['years_offer'];
            $moraleBonus = ($team['morale'] ?? 50) * 1000; // Morale influences FA decisions
            $ratingBonus = ($team['overall_rating'] ?? 50) * 5000; // Better teams attract FAs
            $score = $totalValue + $moraleBonus + $ratingBonus;

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestBid = $bid;
            }
        }

        if (!$bestBid) return null;

        // Mark winning bid
        $this->db->prepare("UPDATE fa_bids SET is_winning = 1 WHERE id = ?")->execute([$bestBid['id']]);

        // Sign the player
        $fa = $this->getFreeAgent($freeAgentId);
        $this->signPlayer($fa['player_id'], $bestBid['team_id'], $bestBid['salary_offer'], $bestBid['years_offer']);

        // Mark FA as signed
        $this->db->prepare("UPDATE free_agents SET status = 'signed', signed_at = ? WHERE id = ?")
            ->execute([date('Y-m-d H:i:s'), $freeAgentId]);

        return [
            'player_id' => $fa['player_id'],
            'team_id' => $bestBid['team_id'],
            'salary' => $bestBid['salary_offer'],
            'years' => $bestBid['years_offer'],
        ];
    }

    /**
     * AI teams make bids on available free agents.
     */
    public function aiMakeBids(int $leagueId): int
    {
        $available = $this->getAvailable($leagueId, null, 100);
        $bidCount = 0;

        // Get all AI-coached teams
        $stmt = $this->db->prepare(
            "SELECT c.id as coach_id, c.team_id, t.salary_cap, t.cap_used, t.overall_rating, c.archetype
             FROM coaches c JOIN teams t ON c.team_id = t.id
             WHERE c.league_id = ? AND c.is_human = 0"
        );
        $stmt->execute([$leagueId]);
        $aiTeams = $stmt->fetchAll();

        foreach ($available as $fa) {
            // Skip restricted FAs without a tender for regular bidding
            if (!empty($fa['is_restricted']) && empty($fa['tender_level'])) {
                continue;
            }

            // Each FA attracts 0-3 AI bids
            $interested = array_filter($aiTeams, function ($team) use ($fa) {
                $capSpace = ($team['salary_cap'] ?? 225000000) - ($team['cap_used'] ?? 0);
                // Don't bid on your own RFA
                if (!empty($fa['is_restricted']) && (int) ($fa['original_team_id'] ?? 0) === (int) $team['team_id']) {
                    return false;
                }
                return $capSpace > $fa['market_value'] * 0.7 && mt_rand(1, 100) <= 30;
            });

            foreach (array_slice($interested, 0, 3) as $team) {
                $offerMultiplier = mt_rand(70, 120) / 100;
                $salary = (int) ($fa['market_value'] * $offerMultiplier);
                $years = mt_rand(1, 3);

                // For RFAs, offer sheet must exceed tender salary
                if (!empty($fa['is_restricted']) && !empty($fa['tender_salary'])) {
                    $salary = max($salary, (int) $fa['tender_salary'] + mt_rand(500000, 3000000));
                }

                $this->placeBid($fa['id'], $team['team_id'], $team['coach_id'], $salary, $years);
                $bidCount++;
            }
        }

        return $bidCount;
    }

    /**
     * Generate the waiver wire (cut players from overstocked positions).
     */
    public function processWaiverWire(int $leagueId): int
    {
        $stmt = $this->db->prepare("SELECT id FROM teams WHERE league_id = ?");
        $stmt->execute([$leagueId]);
        $teamIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        $released = 0;

        foreach ($teamIds as $teamId) {
            // Count active players
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM players WHERE team_id = ? AND status = 'active'");
            $stmt->execute([$teamId]);
            $count = (int) $stmt->fetchColumn();

            // Roster limit: 53 active players
            while ($count > 53) {
                // Cut the lowest-rated player
                $stmt = $this->db->prepare(
                    "SELECT id FROM players WHERE team_id = ? AND status = 'active' ORDER BY overall_rating ASC LIMIT 1"
                );
                $stmt->execute([$teamId]);
                $cutId = $stmt->fetchColumn();
                if (!$cutId) break;

                $this->releasePlayer($leagueId, (int) $cutId);
                $count--;
                $released++;
            }
        }

        return $released;
    }

    /**
     * Get active bids for a team.
     */
    public function getTeamBids(int $teamId): array
    {
        $stmt = $this->db->prepare(
            "SELECT b.*, fa.player_id, p.first_name, p.last_name, p.position, p.overall_rating
             FROM fa_bids b
             JOIN free_agents fa ON b.free_agent_id = fa.id
             JOIN players p ON fa.player_id = p.id
             WHERE b.team_id = ? AND fa.status = 'available'
             ORDER BY b.created_at DESC"
        );
        $stmt->execute([$teamId]);
        return $stmt->fetchAll();
    }

    // ================================================================
    //  Restricted Free Agency (RFA) System
    // ================================================================

    /**
     * Check if a player qualifies as a restricted free agent.
     * Players with 3 or fewer accrued seasons whose contracts expire become RFAs.
     */
    public function isRestrictedFreeAgent(array $player): bool
    {
        $yearsPro = (int) ($player['years_pro'] ?? 0);
        return $yearsPro > 0 && $yearsPro <= 3;
    }

    /**
     * Set a qualifying tender on a restricted free agent.
     *
     * @param int $freeAgentId  The free_agents.id
     * @param string $tenderLevel  'first_round', 'second_round', or 'original_round'
     * @param int $teamId  The team setting the tender (must be the original team)
     * @return array  Result with success/error
     */
    public function setTender(int $freeAgentId, string $tenderLevel, int $teamId): array
    {
        $fa = $this->getFreeAgent($freeAgentId);
        if (!$fa) {
            return ['error' => 'Free agent not found'];
        }
        if (!$fa['is_restricted']) {
            return ['error' => 'This player is not a restricted free agent'];
        }
        if ((int) $fa['original_team_id'] !== $teamId) {
            return ['error' => 'Only the original team can set a tender on this player'];
        }
        if ($fa['tender_level']) {
            return ['error' => 'A tender has already been set for this player'];
        }

        $validLevels = ['first_round', 'second_round', 'original_round'];
        if (!in_array($tenderLevel, $validLevels, true)) {
            return ['error' => 'Invalid tender level. Must be: first_round, second_round, or original_round'];
        }

        $player = $this->getPlayer((int) $fa['player_id']);
        if (!$player) {
            return ['error' => 'Player not found'];
        }

        $tenderSalary = $this->calculateTenderSalary($tenderLevel, $player['position']);

        // Check cap space
        $team = $this->getTeam($teamId);
        $capRemaining = ($team['salary_cap'] ?? 225000000) - ($team['cap_used'] ?? 0);
        if ($tenderSalary > $capRemaining) {
            return ['error' => 'Insufficient cap space for this tender level'];
        }

        $this->db->prepare(
            "UPDATE free_agents SET tender_level = ?, tender_salary = ? WHERE id = ?"
        )->execute([$tenderLevel, $tenderSalary, $freeAgentId]);

        return [
            'success' => true,
            'tender_level' => $tenderLevel,
            'tender_salary' => $tenderSalary,
            'player_id' => $fa['player_id'],
            'player_name' => $player['first_name'] . ' ' . $player['last_name'],
        ];
    }

    /**
     * Calculate tender salary based on level and position.
     * Higher-tier tenders cost more but provide better draft pick compensation.
     */
    public function calculateTenderSalary(string $level, string $position): int
    {
        // Base tender salaries (approximate NFL RFA tender amounts scaled to game economy)
        $baseTenders = match ($level) {
            'first_round'    => 5500000,  // ~$5.5M — highest protection
            'second_round'   => 3900000,  // ~$3.9M — medium protection
            'original_round' => 2500000,  // ~$2.5M — lowest protection
            default          => 2500000,
        };

        // Position multiplier (premium positions cost more)
        $posMultiplier = match ($position) {
            'QB' => 2.0,
            'DE', 'CB' => 1.3,
            'WR', 'OT' => 1.2,
            'LB', 'DT' => 1.1,
            'RB', 'TE', 'S' => 1.0,
            'OG', 'C' => 0.9,
            'K', 'P' => 0.6,
            default => 1.0,
        };

        return (int) ($baseTenders * $posMultiplier);
    }

    /**
     * Another team makes an offer sheet to a restricted free agent.
     * The offer must exceed the tender salary.
     */
    public function makeOfferSheet(int $freeAgentId, int $teamId, int $salary, int $years): array
    {
        $fa = $this->getFreeAgent($freeAgentId);
        if (!$fa) {
            return ['error' => 'Free agent not found'];
        }
        if (!$fa['is_restricted']) {
            return ['error' => 'This player is not a restricted free agent'];
        }
        if (empty($fa['tender_level'])) {
            return ['error' => 'The original team has not set a tender yet'];
        }
        if ((int) $fa['original_team_id'] === $teamId) {
            return ['error' => 'The original team cannot make an offer sheet on their own RFA'];
        }

        // Offer must exceed tender salary
        if ($salary < $fa['tender_salary']) {
            return ['error' => "Offer sheet salary must be at least the tender salary (\${$fa['tender_salary']})"];
        }

        // Check cap space
        $team = $this->getTeam($teamId);
        $capRemaining = ($team['salary_cap'] ?? 225000000) - ($team['cap_used'] ?? 0);
        if ($salary > $capRemaining) {
            return ['error' => 'Salary exceeds cap space'];
        }

        // Check for existing pending offer sheet
        $existing = $this->getPendingOfferSheet($freeAgentId);
        if ($existing) {
            // New offer must exceed existing
            if ($salary <= (int) $existing['salary']) {
                return ['error' => 'Offer must exceed the current pending offer sheet of $' . number_format($existing['salary'])];
            }
            // Void the old offer sheet
            $this->db->prepare("UPDATE rfa_offer_sheets SET status = 'voided', resolved_at = ? WHERE id = ?")
                ->execute([date('Y-m-d H:i:s'), $existing['id']]);
        }

        $now = date('Y-m-d H:i:s');
        $this->db->prepare(
            "INSERT INTO rfa_offer_sheets (free_agent_id, offering_team_id, salary, years, status, created_at)
             VALUES (?, ?, ?, ?, 'pending', ?)"
        )->execute([$freeAgentId, $teamId, $salary, $years, $now]);

        $offerSheetId = (int) $this->db->lastInsertId();

        $player = $this->getPlayer((int) $fa['player_id']);

        return [
            'success' => true,
            'offer_sheet_id' => $offerSheetId,
            'player_id' => $fa['player_id'],
            'player_name' => $player ? $player['first_name'] . ' ' . $player['last_name'] : 'Unknown',
            'salary' => $salary,
            'years' => $years,
            'original_team_id' => $fa['original_team_id'],
            'message' => 'Offer sheet submitted. The original team has until the next advance to match.',
        ];
    }

    /**
     * Original team matches the offer sheet -- player stays on the original team
     * at the offer sheet terms.
     */
    public function matchOfferSheet(int $freeAgentId, int $teamId): array
    {
        $fa = $this->getFreeAgent($freeAgentId);
        if (!$fa) {
            return ['error' => 'Free agent not found'];
        }
        if (!$fa['is_restricted']) {
            return ['error' => 'This player is not a restricted free agent'];
        }
        if ((int) $fa['original_team_id'] !== $teamId) {
            return ['error' => 'Only the original team can match an offer sheet'];
        }

        $offerSheet = $this->getPendingOfferSheet($freeAgentId);
        if (!$offerSheet) {
            return ['error' => 'No pending offer sheet to match'];
        }

        $salary = (int) $offerSheet['salary'];
        $years = (int) $offerSheet['years'];

        // Check cap space
        $team = $this->getTeam($teamId);
        $capRemaining = ($team['salary_cap'] ?? 225000000) - ($team['cap_used'] ?? 0);
        if ($salary > $capRemaining) {
            return ['error' => 'Insufficient cap space to match the offer sheet'];
        }

        // Mark offer sheet as matched
        $this->db->prepare("UPDATE rfa_offer_sheets SET status = 'matched', resolved_at = ? WHERE id = ?")
            ->execute([date('Y-m-d H:i:s'), $offerSheet['id']]);

        // Sign player back to original team at the offer sheet terms
        $this->signPlayer((int) $fa['player_id'], $teamId, $salary, $years);

        // Mark FA as signed
        $this->db->prepare("UPDATE free_agents SET status = 'signed', signed_at = ? WHERE id = ?")
            ->execute([date('Y-m-d H:i:s'), $freeAgentId]);

        $player = $this->getPlayer((int) $fa['player_id']);

        return [
            'success' => true,
            'action' => 'matched',
            'player_id' => $fa['player_id'],
            'player_name' => $player ? $player['first_name'] . ' ' . $player['last_name'] : 'Unknown',
            'team_id' => $teamId,
            'salary' => $salary,
            'years' => $years,
            'message' => 'Offer sheet matched. Player stays with the original team.',
        ];
    }

    /**
     * Original team declines to match the offer sheet -- player goes to the
     * offering team, and the original team receives draft pick compensation.
     */
    public function declineOfferSheet(int $freeAgentId, int $teamId): array
    {
        $fa = $this->getFreeAgent($freeAgentId);
        if (!$fa) {
            return ['error' => 'Free agent not found'];
        }
        if (!$fa['is_restricted']) {
            return ['error' => 'This player is not a restricted free agent'];
        }
        if ((int) $fa['original_team_id'] !== $teamId) {
            return ['error' => 'Only the original team can decline an offer sheet'];
        }

        $offerSheet = $this->getPendingOfferSheet($freeAgentId);
        if (!$offerSheet) {
            return ['error' => 'No pending offer sheet to decline'];
        }

        $newTeamId = (int) $offerSheet['offering_team_id'];
        $salary = (int) $offerSheet['salary'];
        $years = (int) $offerSheet['years'];

        // Mark offer sheet as accepted (player leaves)
        $this->db->prepare("UPDATE rfa_offer_sheets SET status = 'accepted', resolved_at = ? WHERE id = ?")
            ->execute([date('Y-m-d H:i:s'), $offerSheet['id']]);

        // Sign player to new team
        $this->signPlayer((int) $fa['player_id'], $newTeamId, $salary, $years);

        // Mark FA as signed
        $this->db->prepare("UPDATE free_agents SET status = 'signed', signed_at = ? WHERE id = ?")
            ->execute([date('Y-m-d H:i:s'), $freeAgentId]);

        // Award draft pick compensation to original team
        $compensation = $this->awardDraftCompensation(
            $fa,
            $teamId,       // original team gets the pick
            $newTeamId     // new team loses the pick
        );

        $player = $this->getPlayer((int) $fa['player_id']);

        return [
            'success' => true,
            'action' => 'declined',
            'player_id' => $fa['player_id'],
            'player_name' => $player ? $player['first_name'] . ' ' . $player['last_name'] : 'Unknown',
            'new_team_id' => $newTeamId,
            'salary' => $salary,
            'years' => $years,
            'compensation' => $compensation,
            'message' => "Player signs with new team. Original team receives {$compensation['round_label']} pick compensation.",
        ];
    }

    /**
     * Auto-sign an RFA to their tender if no offer sheet was made.
     * Called during offseason advancement when advancing past free agency phase.
     */
    public function autoSignTender(int $freeAgentId): ?array
    {
        $fa = $this->getFreeAgent($freeAgentId);
        if (!$fa || !$fa['is_restricted'] || empty($fa['tender_level'])) {
            return null;
        }

        // Check if there's a pending offer sheet
        $offerSheet = $this->getPendingOfferSheet($freeAgentId);
        if ($offerSheet) {
            return null; // There's an offer sheet -- needs to be matched/declined first
        }

        $tenderSalary = (int) $fa['tender_salary'];
        $originalTeamId = (int) $fa['original_team_id'];

        // Sign at tender salary for 1 year
        $this->signPlayer((int) $fa['player_id'], $originalTeamId, $tenderSalary, 1);

        $this->db->prepare("UPDATE free_agents SET status = 'signed', signed_at = ? WHERE id = ?")
            ->execute([date('Y-m-d H:i:s'), $freeAgentId]);

        $player = $this->getPlayer((int) $fa['player_id']);

        return [
            'player_id' => $fa['player_id'],
            'player_name' => $player ? $player['first_name'] . ' ' . $player['last_name'] : 'Unknown',
            'team_id' => $originalTeamId,
            'salary' => $tenderSalary,
            'years' => 1,
            'action' => 'tender_signed',
        ];
    }

    /**
     * AI teams handle RFA decisions: set tenders and match/decline offer sheets.
     */
    public function aiHandleRFAs(int $leagueId): array
    {
        $results = ['tenders_set' => [], 'matched' => [], 'declined' => []];

        // Get AI teams
        $stmt = $this->db->prepare(
            "SELECT c.id as coach_id, c.team_id, c.archetype, t.salary_cap, t.cap_used, t.overall_rating
             FROM coaches c JOIN teams t ON c.team_id = t.id
             WHERE c.league_id = ? AND c.is_human = 0 AND c.team_id IS NOT NULL"
        );
        $stmt->execute([$leagueId]);
        $aiTeams = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $aiTeamIds = array_column($aiTeams, 'team_id');
        $aiTeamMap = [];
        foreach ($aiTeams as $t) {
            $aiTeamMap[(int) $t['team_id']] = $t;
        }

        // Phase 1: Set tenders on un-tendered RFAs owned by AI teams
        $stmt = $this->db->prepare(
            "SELECT fa.*, p.position, p.overall_rating, p.age, p.first_name, p.last_name
             FROM free_agents fa
             JOIN players p ON fa.player_id = p.id
             WHERE fa.league_id = ? AND fa.is_restricted = 1 AND fa.status = 'available' AND fa.tender_level IS NULL"
        );
        $stmt->execute([$leagueId]);
        $untenderedRFAs = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($untenderedRFAs as $rfa) {
            $origTeamId = (int) $rfa['original_team_id'];
            if (!in_array($origTeamId, $aiTeamIds)) continue;

            $ovr = (int) $rfa['overall_rating'];
            $pos = $rfa['position'];

            // AI logic: higher-rated players get first-round tenders, lower get original
            $tenderLevel = match (true) {
                $ovr >= 82 => 'first_round',
                $ovr >= 72 => 'second_round',
                default    => 'original_round',
            };

            // Check cap space before tendering
            $tenderCost = $this->calculateTenderSalary($tenderLevel, $pos);
            $team = $aiTeamMap[$origTeamId];
            $capSpace = ($team['salary_cap'] ?? 225000000) - ($team['cap_used'] ?? 0);

            // Downgrade tender if cap is tight
            if ($tenderCost > $capSpace * 0.3) {
                $tenderLevel = 'original_round';
                $tenderCost = $this->calculateTenderSalary($tenderLevel, $pos);
            }
            if ($tenderCost > $capSpace) {
                continue; // Can't afford any tender
            }

            $result = $this->setTender((int) $rfa['id'], $tenderLevel, $origTeamId);
            if (!empty($result['success'])) {
                $results['tenders_set'][] = [
                    'player_name' => $rfa['first_name'] . ' ' . $rfa['last_name'],
                    'position' => $pos,
                    'overall' => $ovr,
                    'tender_level' => $tenderLevel,
                    'team_id' => $origTeamId,
                ];
            }
        }

        // Phase 2: Handle pending offer sheets for AI original teams
        $stmt = $this->db->prepare(
            "SELECT fa.*, os.id as offer_sheet_id, os.offering_team_id, os.salary as offer_salary, os.years as offer_years,
                    p.position, p.overall_rating, p.age, p.first_name, p.last_name
             FROM free_agents fa
             JOIN rfa_offer_sheets os ON os.free_agent_id = fa.id AND os.status = 'pending'
             JOIN players p ON fa.player_id = p.id
             WHERE fa.league_id = ? AND fa.is_restricted = 1 AND fa.status = 'available'"
        );
        $stmt->execute([$leagueId]);
        $rfasWithOffers = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rfasWithOffers as $rfa) {
            $origTeamId = (int) $rfa['original_team_id'];
            if (!in_array($origTeamId, $aiTeamIds)) continue;

            $offerSalary = (int) $rfa['offer_salary'];
            $ovr = (int) $rfa['overall_rating'];
            $age = (int) $rfa['age'];

            // AI decision: match if the player is good enough and cap allows
            $team = $aiTeamMap[$origTeamId];
            $capSpace = ($team['salary_cap'] ?? 225000000) - ($team['cap_used'] ?? 0);

            $shouldMatch = false;
            if ($ovr >= 80 && $offerSalary <= $capSpace) {
                $shouldMatch = true; // Always match elite players
            } elseif ($ovr >= 72 && $age <= 27 && $offerSalary <= $capSpace * 0.5) {
                $shouldMatch = true; // Match good young players if affordable
            }

            if ($shouldMatch) {
                $result = $this->matchOfferSheet((int) $rfa['id'], $origTeamId);
                if (!empty($result['success'])) {
                    $results['matched'][] = [
                        'player_name' => $rfa['first_name'] . ' ' . $rfa['last_name'],
                        'salary' => $offerSalary,
                        'team_id' => $origTeamId,
                    ];
                }
            } else {
                $result = $this->declineOfferSheet((int) $rfa['id'], $origTeamId);
                if (!empty($result['success'])) {
                    $results['declined'][] = [
                        'player_name' => $rfa['first_name'] . ' ' . $rfa['last_name'],
                        'new_team_id' => (int) $rfa['offering_team_id'],
                        'compensation' => $result['compensation'] ?? null,
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * Get pending RFA offer sheets for a specific team (as the original team).
     */
    public function getTeamRFAOfferSheets(int $teamId): array
    {
        $stmt = $this->db->prepare(
            "SELECT fa.id as fa_id, fa.player_id, fa.tender_level, fa.tender_salary, fa.original_draft_round,
                    os.id as offer_sheet_id, os.offering_team_id, os.salary, os.years, os.status, os.created_at,
                    p.first_name, p.last_name, p.position, p.overall_rating, p.age,
                    t.abbreviation as offering_team_abbr, t.city as offering_team_city, t.name as offering_team_name
             FROM free_agents fa
             JOIN rfa_offer_sheets os ON os.free_agent_id = fa.id
             JOIN players p ON fa.player_id = p.id
             JOIN teams t ON os.offering_team_id = t.id
             WHERE fa.original_team_id = ? AND fa.is_restricted = 1 AND os.status = 'pending'
             ORDER BY os.created_at DESC"
        );
        $stmt->execute([$teamId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get RFAs that belong to a specific team (as original team).
     */
    public function getTeamRFAs(int $teamId): array
    {
        $stmt = $this->db->prepare(
            "SELECT fa.*, p.first_name, p.last_name, p.position, p.overall_rating, p.age, p.potential, p.image_url
             FROM free_agents fa
             JOIN players p ON fa.player_id = p.id
             WHERE fa.original_team_id = ? AND fa.is_restricted = 1 AND fa.status = 'available'
             ORDER BY p.overall_rating DESC"
        );
        $stmt->execute([$teamId]);
        $rfas = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rfas as &$rfa) {
            $rfa['offer_sheet'] = $this->getPendingOfferSheet((int) $rfa['id']);
        }

        return $rfas;
    }

    // ================================================================
    //  Private helpers
    // ================================================================

    private function signPlayer(int $playerId, int $teamId, int $salary, int $years): void
    {
        $this->db->prepare("UPDATE players SET team_id = ?, status = 'active' WHERE id = ?")
            ->execute([$teamId, $playerId]);

        $now = date('Y-m-d H:i:s');
        $totalValue = $salary * $years;

        // Signing bonus = 40% of guaranteed money, guaranteed = 40% of total value
        $guaranteed = (int) ($totalValue * 0.40);
        $signingBonus = (int) ($guaranteed * 0.60);
        $baseSalary = $salary - (int) ($signingBonus / max(1, $years));
        $capHit = $baseSalary + (int) ($signingBonus / max(1, $years));

        // Dead cap = full signing bonus if cut immediately (all proration accelerates)
        $deadCap = $signingBonus;

        $this->db->prepare(
            "INSERT INTO contracts (player_id, team_id, salary_annual, cap_hit, years_total, years_remaining,
             guaranteed, dead_cap, signing_bonus, base_salary, total_value, contract_type, status, signed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'standard', 'active', ?)"
        )->execute([
            $playerId, $teamId, $salary, $capHit, $years, $years,
            $guaranteed, $deadCap, $signingBonus, $baseSalary, $totalValue,
            $now,
        ]);

        // Update team cap
        $this->db->prepare("UPDATE teams SET cap_used = cap_used + ? WHERE id = ?")
            ->execute([$capHit, $teamId]);
    }

    private function calculateMarketValue(array $player): int
    {
        // Delegate to ContractEngine for a single source of truth
        $contractEngine = new ContractEngine();
        return $contractEngine->calculateMarketValue($player);
    }

    private function getPlayer(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM players WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    private function getFreeAgent(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM free_agents WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    private function getTeam(int $id): ?array
    {
        // Recalculate cap_used from actual contracts to avoid drift
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(cap_hit), 0) FROM contracts WHERE team_id = ? AND status = 'active'"
        );
        $stmt->execute([$id]);
        $actualCapUsed = (int) $stmt->fetchColumn();

        $this->db->prepare("UPDATE teams SET cap_used = ? WHERE id = ?")
            ->execute([$actualCapUsed, $id]);

        $stmt = $this->db->prepare("SELECT * FROM teams WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    private function getBids(int $freeAgentId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM fa_bids WHERE free_agent_id = ? ORDER BY salary_offer DESC");
        $stmt->execute([$freeAgentId]);
        return $stmt->fetchAll();
    }

    /**
     * Get the pending (active) offer sheet for a restricted free agent.
     */
    private function getPendingOfferSheet(int $freeAgentId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT os.*, t.abbreviation as offering_team_abbr, t.city as offering_team_city, t.name as offering_team_name
             FROM rfa_offer_sheets os
             JOIN teams t ON os.offering_team_id = t.id
             WHERE os.free_agent_id = ? AND os.status = 'pending'
             ORDER BY os.salary DESC LIMIT 1"
        );
        $stmt->execute([$freeAgentId]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Look up which round a player was originally drafted in.
     * Returns null if undrafted.
     */
    private function getPlayerOriginalDraftRound(int $playerId): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT round FROM draft_picks WHERE player_id = ? ORDER BY id ASC LIMIT 1"
        );
        $stmt->execute([$playerId]);
        $round = $stmt->fetchColumn();
        return $round ? (int) $round : null;
    }

    /**
     * Award draft pick compensation when an RFA signs with a new team.
     * The compensating round depends on the tender level.
     */
    private function awardDraftCompensation(array $fa, int $originalTeamId, int $newTeamId): array
    {
        $tenderLevel = $fa['tender_level'] ?? 'original_round';
        $compensationRound = match ($tenderLevel) {
            'first_round'    => 1,
            'second_round'   => 2,
            'original_round' => $fa['original_draft_round'] ?? 4, // Default to 4th if undrafted
            default          => 4,
        };

        $roundLabel = match ($compensationRound) {
            1 => '1st round',
            2 => '2nd round',
            3 => '3rd round',
            4 => '4th round',
            5 => '5th round',
            6 => '6th round',
            7 => '7th round',
            default => "{$compensationRound}th round",
        };

        // Find the most recent draft class for this league
        $leagueId = (int) $fa['league_id'];
        $stmt = $this->db->prepare(
            "SELECT id FROM draft_classes WHERE league_id = ? ORDER BY year DESC LIMIT 1"
        );
        $stmt->execute([$leagueId]);
        $draftClass = $stmt->fetch();

        if ($draftClass) {
            $draftClassId = (int) $draftClass['id'];

            // Find the new team's pick in the compensation round
            $stmt = $this->db->prepare(
                "SELECT id FROM draft_picks
                 WHERE draft_class_id = ? AND current_team_id = ? AND round = ? AND is_used = 0
                 ORDER BY pick_number ASC LIMIT 1"
            );
            $stmt->execute([$draftClassId, $newTeamId, $compensationRound]);
            $pick = $stmt->fetch();

            if ($pick) {
                // Transfer the pick to the original team
                $this->db->prepare("UPDATE draft_picks SET current_team_id = ? WHERE id = ?")
                    ->execute([$originalTeamId, $pick['id']]);
            } else {
                // If no pick exists in that round, create a compensatory pick
                // Get the highest pick number in that round
                $stmt = $this->db->prepare(
                    "SELECT MAX(pick_number) FROM draft_picks WHERE draft_class_id = ? AND round = ?"
                );
                $stmt->execute([$draftClassId, $compensationRound]);
                $maxPick = (int) $stmt->fetchColumn();

                $this->db->prepare(
                    "INSERT INTO draft_picks (league_id, draft_class_id, round, pick_number, original_team_id, current_team_id, is_used)
                     VALUES (?, ?, ?, ?, ?, ?, 0)"
                )->execute([$leagueId, $draftClassId, $compensationRound, $maxPick + 1, $newTeamId, $originalTeamId]);
            }
        }

        return [
            'round' => $compensationRound,
            'round_label' => $roundLabel,
            'from_team_id' => $newTeamId,
            'to_team_id' => $originalTeamId,
        ];
    }
}
