<?php

namespace App\Services;

use App\Database\Connection;

/**
 * FranchiseTagEngine -- Handles franchise tag calculations and application.
 *
 * Three tag types:
 *   - exclusive:      Player cannot negotiate with other teams. Salary = avg of top 5 at position.
 *   - non_exclusive:  Player can negotiate, but original team can match any offer. Same salary as exclusive.
 *   - transition:     Player can negotiate, original team gets right of first refusal. Salary = avg of top 10.
 *
 * Rules:
 *   - Each team may use ONE franchise tag per offseason year.
 *   - Only players in the final year of their contract (years_remaining <= 1) can be tagged.
 *   - Tagging creates a guaranteed 1-year contract at the computed tag value.
 *   - Tagged players are excluded from the free agency pool.
 */
class FranchiseTagEngine
{
    private \PDO $db;

    /** All valid NFL positions for tag calculation */
    private const POSITIONS = [
        'QB', 'RB', 'WR', 'TE', 'OT', 'OG', 'C',
        'DE', 'DT', 'LB', 'CB', 'S', 'K', 'P',
    ];

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
        $this->ensureColumns();
    }

    // ================================================================
    //  Schema migration (idempotent)
    // ================================================================

    private function ensureColumns(): void
    {
        // contracts.franchise_tag_type
        $cols = $this->db->query("PRAGMA table_info(contracts)")->fetchAll(\PDO::FETCH_ASSOC);
        $names = array_column($cols, 'name');
        if (!in_array('franchise_tag_type', $names, true)) {
            $this->db->exec("ALTER TABLE contracts ADD COLUMN franchise_tag_type VARCHAR(20) NULL");
        }

        // teams.franchise_tags_used
        $cols = $this->db->query("PRAGMA table_info(teams)")->fetchAll(\PDO::FETCH_ASSOC);
        $names = array_column($cols, 'name');
        if (!in_array('franchise_tags_used', $names, true)) {
            $this->db->exec("ALTER TABLE teams ADD COLUMN franchise_tags_used INT NOT NULL DEFAULT 0");
        }
    }

    // ================================================================
    //  Tag Value Calculation
    // ================================================================

    /**
     * Calculate the exclusive/non-exclusive franchise tag salary for a position.
     * Returns the average of the top 5 salaries at that position league-wide.
     */
    public function calculateTagValue(string $position, int $leagueId): int
    {
        return $this->averageTopSalaries($position, $leagueId, 5);
    }

    /**
     * Calculate the transition tag salary for a position.
     * Returns the average of the top 10 salaries at that position league-wide.
     */
    public function calculateTransitionTagValue(string $position, int $leagueId): int
    {
        return $this->averageTopSalaries($position, $leagueId, 10);
    }

    /**
     * Get tag values for ALL positions in a league.
     * Returns ['QB' => ['exclusive' => ..., 'transition' => ...], ...]
     */
    public function getAllTagValues(int $leagueId): array
    {
        $values = [];
        foreach (self::POSITIONS as $pos) {
            $exclusive = $this->calculateTagValue($pos, $leagueId);
            $transition = $this->calculateTransitionTagValue($pos, $leagueId);
            $values[$pos] = [
                'exclusive'     => $exclusive,
                'non_exclusive' => $exclusive, // same as exclusive
                'transition'    => $transition,
            ];
        }
        return $values;
    }

    // ================================================================
    //  Validation
    // ================================================================

    /**
     * Check whether a team can franchise-tag a given player.
     *
     * @return array ['allowed' => bool, 'reason' => ?string, 'player' => ?array, 'tag_salary' => ?int]
     */
    public function canTagPlayer(int $teamId, int $playerId): array
    {
        // 1. Player must exist and belong to this team
        $stmt = $this->db->prepare(
            "SELECT p.*, c.id as contract_id, c.years_remaining, c.franchise_tag_type, c.status as contract_status
             FROM players p
             LEFT JOIN contracts c ON c.player_id = p.id AND c.status = 'active'
             WHERE p.id = ? AND p.team_id = ?"
        );
        $stmt->execute([$playerId, $teamId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return ['allowed' => false, 'reason' => 'Player not found on this team'];
        }

        // 2. Player must have an active contract in its final year
        if (!$row['contract_id']) {
            return ['allowed' => false, 'reason' => 'Player has no active contract'];
        }
        if ((int) $row['years_remaining'] > 1) {
            return ['allowed' => false, 'reason' => 'Player is not in the final year of their contract'];
        }

        // 3. Player must not already be franchise-tagged
        if ($row['franchise_tag_type']) {
            return ['allowed' => false, 'reason' => 'Player already has a franchise tag'];
        }

        // 4. Team must not have already used their franchise tag this year
        $team = $this->getTeam($teamId);
        if ((int) ($team['franchise_tags_used'] ?? 0) >= 1) {
            return ['allowed' => false, 'reason' => 'Team has already used their franchise tag this year'];
        }

        // 5. Get the league from the player
        $leagueId = (int) $row['league_id'];
        $position = $row['position'];

        // Calculate what the tag would cost
        $exclusiveValue = $this->calculateTagValue($position, $leagueId);
        $transitionValue = $this->calculateTransitionTagValue($position, $leagueId);

        return [
            'allowed'          => true,
            'reason'           => null,
            'player'           => [
                'id'             => (int) $row['id'],
                'first_name'     => $row['first_name'],
                'last_name'      => $row['last_name'],
                'position'       => $position,
                'overall_rating' => (int) $row['overall_rating'],
                'age'            => (int) $row['age'],
            ],
            'contract_id'      => (int) $row['contract_id'],
            'tag_values'       => [
                'exclusive'     => $exclusiveValue,
                'non_exclusive' => $exclusiveValue,
                'transition'    => $transitionValue,
            ],
        ];
    }

    // ================================================================
    //  Apply Tags
    // ================================================================

    /**
     * Apply an exclusive franchise tag. Player cannot negotiate with other teams.
     */
    public function applyExclusiveTag(int $teamId, int $playerId): array
    {
        return $this->applyTag($teamId, $playerId, 'exclusive');
    }

    /**
     * Apply a non-exclusive franchise tag. Player can negotiate but team can match.
     */
    public function applyNonExclusiveTag(int $teamId, int $playerId): array
    {
        return $this->applyTag($teamId, $playerId, 'non_exclusive');
    }

    /**
     * Apply a transition tag. Lower salary, team has right of first refusal.
     */
    public function applyTransitionTag(int $teamId, int $playerId): array
    {
        return $this->applyTag($teamId, $playerId, 'transition');
    }

    /**
     * Core tag application logic.
     */
    private function applyTag(int $teamId, int $playerId, string $type): array
    {
        // Validate
        $check = $this->canTagPlayer($teamId, $playerId);
        if (!$check['allowed']) {
            return ['error' => $check['reason']];
        }

        $player = $check['player'];
        $contractId = $check['contract_id'];
        $leagueId = $this->getPlayerLeagueId($playerId);

        // Determine tag salary
        $tagSalary = ($type === 'transition')
            ? $this->calculateTransitionTagValue($player['position'], $leagueId)
            : $this->calculateTagValue($player['position'], $leagueId);

        // Apply a floor: tag salary should be at least the player's current salary
        $currentSalary = $this->getCurrentSalary($playerId);
        if ($tagSalary < $currentSalary) {
            $tagSalary = $currentSalary;
        }

        $now = date('Y-m-d H:i:s');

        // Delete the expiring contract
        $this->db->prepare("DELETE FROM contracts WHERE id = ?")->execute([$contractId]);

        // Create new 1-year franchise tag contract
        $this->db->prepare(
            "INSERT INTO contracts
                (player_id, team_id, years_total, years_remaining, salary_annual,
                 cap_hit, guaranteed, dead_cap, signing_bonus, base_salary,
                 contract_type, total_value, franchise_tag_type, status, signed_at)
             VALUES (?, ?, 1, 1, ?, ?, ?, 0, 0, ?, 'franchise_tag', ?, ?, 'active', ?)"
        )->execute([
            $playerId,
            $teamId,
            $tagSalary,        // salary_annual
            $tagSalary,        // cap_hit (fully counts against cap)
            $tagSalary,        // guaranteed (100% guaranteed 1-year deal)
            $tagSalary,        // base_salary
            $tagSalary,        // total_value
            $type,             // franchise_tag_type
            $now,
        ]);

        $newContractId = (int) $this->db->lastInsertId();

        // Mark team as having used their franchise tag
        $this->db->prepare(
            "UPDATE teams SET franchise_tags_used = franchise_tags_used + 1 WHERE id = ?"
        )->execute([$teamId]);

        // Update team cap_used
        $this->recalculateTeamCap($teamId);

        return [
            'success'        => true,
            'contract_id'    => $newContractId,
            'player_id'      => $playerId,
            'player_name'    => $player['first_name'] . ' ' . $player['last_name'],
            'position'       => $player['position'],
            'tag_type'       => $type,
            'tag_salary'     => $tagSalary,
            'cap_hit'        => $tagSalary,
        ];
    }

    // ================================================================
    //  Remove Tag
    // ================================================================

    /**
     * Remove a franchise tag from a player. Deletes the tag contract;
     * the player will become a free agent at the next re-sign window.
     */
    public function removeTag(int $playerId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT c.id, c.team_id, c.cap_hit
             FROM contracts c
             WHERE c.player_id = ? AND c.franchise_tag_type IS NOT NULL AND c.status = 'active'
             LIMIT 1"
        );
        $stmt->execute([$playerId]);
        $contract = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$contract) {
            return false;
        }

        $teamId = (int) $contract['team_id'];

        // Delete the franchise tag contract
        $this->db->prepare("DELETE FROM contracts WHERE id = ?")->execute([$contract['id']]);

        // Decrement team's franchise_tags_used
        $this->db->prepare(
            "UPDATE teams SET franchise_tags_used = MAX(0, franchise_tags_used - 1) WHERE id = ?"
        )->execute([$teamId]);

        // Recalculate cap
        $this->recalculateTeamCap($teamId);

        return true;
    }

    // ================================================================
    //  Queries
    // ================================================================

    /**
     * Get all franchise-tagged players in a league.
     */
    public function getTaggedPlayers(int $leagueId): array
    {
        $stmt = $this->db->prepare(
            "SELECT p.id, p.first_name, p.last_name, p.position, p.overall_rating, p.age,
                    p.team_id, t.abbreviation as team_abbr, t.name as team_name,
                    c.franchise_tag_type, c.salary_annual as tag_salary, c.cap_hit, c.id as contract_id
             FROM contracts c
             JOIN players p ON c.player_id = p.id
             JOIN teams t ON c.team_id = t.id
             WHERE p.league_id = ?
               AND c.franchise_tag_type IS NOT NULL
               AND c.status = 'active'
             ORDER BY c.salary_annual DESC"
        );
        $stmt->execute([$leagueId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get the franchise-tagged player for a specific team (if any).
     */
    public function getTeamTaggedPlayer(int $teamId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT p.id, p.first_name, p.last_name, p.position, p.overall_rating, p.age,
                    c.franchise_tag_type, c.salary_annual as tag_salary, c.cap_hit, c.id as contract_id
             FROM contracts c
             JOIN players p ON c.player_id = p.id
             WHERE c.team_id = ? AND c.franchise_tag_type IS NOT NULL AND c.status = 'active'
             LIMIT 1"
        );
        $stmt->execute([$teamId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Check if a player is franchise-tagged.
     */
    public function isTagged(int $playerId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM contracts
             WHERE player_id = ? AND franchise_tag_type IS NOT NULL AND status = 'active'"
        );
        $stmt->execute([$playerId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    // ================================================================
    //  Offseason Integration
    // ================================================================

    /**
     * Get player IDs that are franchise-tagged in a league.
     * Used by OffseasonFlowEngine to exclude them from the free agency pool.
     */
    public function getTaggedPlayerIds(int $leagueId): array
    {
        $stmt = $this->db->prepare(
            "SELECT p.id
             FROM contracts c
             JOIN players p ON c.player_id = p.id
             WHERE p.league_id = ?
               AND c.franchise_tag_type IS NOT NULL
               AND c.status = 'active'"
        );
        $stmt->execute([$leagueId]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Reset franchise_tags_used for all teams in a league (called at start of new offseason).
     */
    public function resetTagsForNewSeason(int $leagueId): void
    {
        $this->db->prepare(
            "UPDATE teams SET franchise_tags_used = 0 WHERE league_id = ?"
        )->execute([$leagueId]);
    }

    /**
     * AI teams apply franchise tags to their best expiring players.
     * Called during the franchise_tag offseason phase.
     */
    public function aiApplyTags(int $leagueId): array
    {
        $tagged = [];

        // Get AI coaches and their teams
        $stmt = $this->db->prepare(
            "SELECT c.id as coach_id, c.team_id, c.archetype, t.salary_cap, t.cap_used,
                    t.overall_rating, t.franchise_tags_used
             FROM coaches c
             JOIN teams t ON c.team_id = t.id
             WHERE c.league_id = ? AND c.is_human = 0 AND c.team_id IS NOT NULL
               AND t.franchise_tags_used = 0"
        );
        $stmt->execute([$leagueId]);
        $aiTeams = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($aiTeams as $team) {
            $teamId = (int) $team['team_id'];
            $capSpace = (int) $team['salary_cap'] - (int) $team['cap_used'];

            // Find best expiring player on this team
            $stmt = $this->db->prepare(
                "SELECT p.id, p.first_name, p.last_name, p.position, p.overall_rating, p.age
                 FROM players p
                 JOIN contracts c ON c.player_id = p.id
                 WHERE p.team_id = ? AND c.years_remaining <= 1 AND c.status = 'active'
                   AND c.franchise_tag_type IS NULL
                 ORDER BY p.overall_rating DESC
                 LIMIT 1"
            );
            $stmt->execute([$teamId]);
            $bestExpiring = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$bestExpiring) continue;

            $ovr = (int) $bestExpiring['overall_rating'];
            $age = (int) $bestExpiring['age'];

            // AI decision: only tag elite/very good players who aren't too old
            if ($ovr < 82 || $age > 31) continue;

            // Check if they can afford the tag
            $tagValue = $this->calculateTagValue($bestExpiring['position'], $leagueId);
            if ($tagValue > $capSpace * 0.3) continue; // Don't spend more than 30% of remaining cap

            // Apply non-exclusive tag (most common in NFL)
            $result = $this->applyNonExclusiveTag($teamId, (int) $bestExpiring['id']);
            if (isset($result['success'])) {
                $tagged[] = $result;
            }
        }

        return $tagged;
    }

    // ================================================================
    //  Private helpers
    // ================================================================

    /**
     * Average the top N salaries at a position across the league.
     * Falls back to ContractEngine position market values if not enough data.
     */
    private function averageTopSalaries(string $position, int $leagueId, int $topN): int
    {
        $stmt = $this->db->prepare(
            "SELECT c.salary_annual
             FROM contracts c
             JOIN players p ON c.player_id = p.id
             WHERE p.league_id = ? AND p.position = ? AND c.status = 'active'
             ORDER BY c.salary_annual DESC
             LIMIT ?"
        );
        $stmt->execute([$leagueId, $position, $topN]);
        $salaries = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        if (count($salaries) >= $topN) {
            return (int) (array_sum($salaries) / count($salaries));
        }

        // Not enough players at this position -- use position market fallback
        if (!empty($salaries)) {
            // Pad with the ContractEngine position market midpoint
            $fallback = $this->getPositionMarketMidpoint($position);
            while (count($salaries) < $topN) {
                $salaries[] = $fallback;
            }
            return (int) (array_sum($salaries) / count($salaries));
        }

        // No data at all -- pure fallback
        return $this->getPositionMarketMidpoint($position);
    }

    /**
     * Get the midpoint of ContractEngine's position market range.
     */
    private function getPositionMarketMidpoint(string $position): int
    {
        $markets = ContractEngine::POSITION_MARKET;
        $range = $markets[$position] ?? [5000000, 15000000];
        return (int) (($range[0] + $range[1]) / 2);
    }

    private function getCurrentSalary(int $playerId): int
    {
        $stmt = $this->db->prepare(
            "SELECT salary_annual FROM contracts WHERE player_id = ? AND status = 'active' LIMIT 1"
        );
        $stmt->execute([$playerId]);
        $val = $stmt->fetchColumn();
        return $val ? (int) $val : 0;
    }

    private function getPlayerLeagueId(int $playerId): int
    {
        $stmt = $this->db->prepare("SELECT league_id FROM players WHERE id = ?");
        $stmt->execute([$playerId]);
        return (int) $stmt->fetchColumn();
    }

    private function getTeam(int $teamId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM teams WHERE id = ?");
        $stmt->execute([$teamId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    private function recalculateTeamCap(int $teamId): void
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(cap_hit), 0) FROM contracts WHERE team_id = ? AND status = 'active'"
        );
        $stmt->execute([$teamId]);
        $capUsed = (int) $stmt->fetchColumn();

        $this->db->prepare("UPDATE teams SET cap_used = ? WHERE id = ?")->execute([$capUsed, $teamId]);
    }
}
