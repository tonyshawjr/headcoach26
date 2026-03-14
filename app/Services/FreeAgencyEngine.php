<?php

namespace App\Services;

use App\Database\Connection;

class FreeAgencyEngine
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
    }

    /**
     * Release a player to free agency.
     */
    public function releasePlayer(int $leagueId, int $playerId): int
    {
        $player = $this->getPlayer($playerId);
        if (!$player) return 0;

        // Calculate market value based on rating, age, position
        $marketValue = $this->calculateMarketValue($player);

        // Remove from team
        $this->db->prepare("UPDATE players SET team_id = NULL, status = 'free_agent' WHERE id = ?")
            ->execute([$playerId]);

        // Remove from depth chart
        $this->db->prepare("DELETE FROM depth_chart WHERE player_id = ?")->execute([$playerId]);

        // Cancel contract
        $this->db->prepare("UPDATE contracts SET status = 'terminated' WHERE player_id = ? AND status = 'active'")
            ->execute([$playerId]);

        // Create free agent listing
        $this->db->prepare(
            "INSERT INTO free_agents (league_id, player_id, asking_salary, market_value, status, released_at)
             VALUES (?, ?, ?, ?, 'available', ?)"
        )->execute([$leagueId, $playerId, $marketValue, $marketValue, date('Y-m-d H:i:s')]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Get available free agents for a league.
     */
    public function getAvailable(int $leagueId, ?string $position = null, int $limit = 50): array
    {
        $sql = "SELECT fa.*, p.first_name, p.last_name, p.position, p.overall_rating, p.age, p.potential
                FROM free_agents fa
                JOIN players p ON fa.player_id = p.id
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
        return $stmt->fetchAll();
    }

    /**
     * Place a bid on a free agent.
     */
    public function placeBid(int $freeAgentId, int $teamId, int $coachId, int $salaryOffer, int $yearsOffer): array
    {
        $fa = $this->getFreeAgent($freeAgentId);
        if (!$fa || $fa['status'] !== 'available') {
            return ['error' => 'Free agent not available'];
        }

        // Check team cap space
        $team = $this->getTeam($teamId);
        $capRemaining = ($team['salary_cap'] ?? 225000000) - ($team['cap_used'] ?? 0);
        if ($salaryOffer > $capRemaining) {
            return ['error' => 'Salary offer exceeds cap space'];
        }

        // Minimum offer is 60% of market value
        if ($salaryOffer < $fa['market_value'] * 0.6) {
            return ['error' => 'Offer too low — minimum is 60% of market value'];
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
            // Each FA attracts 0-3 AI bids
            $interested = array_filter($aiTeams, function ($team) use ($fa) {
                $capSpace = ($team['salary_cap'] ?? 225000000) - ($team['cap_used'] ?? 0);
                return $capSpace > $fa['market_value'] * 0.7 && mt_rand(1, 100) <= 30;
            });

            foreach (array_slice($interested, 0, 3) as $team) {
                $offerMultiplier = mt_rand(70, 120) / 100;
                $salary = (int) ($fa['market_value'] * $offerMultiplier);
                $years = mt_rand(1, 3);

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

    private function signPlayer(int $playerId, int $teamId, int $salary, int $years): void
    {
        $this->db->prepare("UPDATE players SET team_id = ?, status = 'active' WHERE id = ?")
            ->execute([$teamId, $playerId]);

        $now = date('Y-m-d H:i:s');
        $this->db->prepare(
            "INSERT INTO contracts (player_id, team_id, total_value, yearly_salary, years_total, years_remaining, status, signed_at)
             VALUES (?, ?, ?, ?, ?, ?, 'active', ?)"
        )->execute([$playerId, $teamId, $salary * $years, $salary, $years, $years, $now]);

        // Update team cap
        $this->db->prepare("UPDATE teams SET cap_used = cap_used + ? WHERE id = ?")
            ->execute([$salary, $teamId]);
    }

    private function calculateMarketValue(array $player): int
    {
        $base = 500000; // Minimum salary
        $ratingBonus = pow($player['overall_rating'] / 100, 2) * 15000000;
        $positionMultiplier = match ($player['position']) {
            'QB' => 2.5, 'DE' => 1.4, 'CB' => 1.3, 'WR' => 1.3, 'OT' => 1.2,
            'LB' => 1.1, 'DT' => 1.1, 'RB' => 1.0, 'TE' => 1.0, 'S' => 1.0,
            'OG' => 0.9, 'C' => 0.9, 'K' => 0.5, 'P' => 0.4, 'LS' => 0.3,
            default => 1.0,
        };

        $ageFactor = $player['age'] <= 26 ? 1.1 : ($player['age'] >= 31 ? 0.7 : 1.0);

        return max($base, (int) ($ratingBonus * $positionMultiplier * $ageFactor));
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
}
