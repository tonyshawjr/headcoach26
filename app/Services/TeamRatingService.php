<?php

namespace App\Services;

use App\Database\Connection;

class TeamRatingService
{
    private \PDO $db;

    private const OFFENSE_POSITIONS = ['QB', 'RB', 'WR', 'TE', 'OT', 'OG', 'C'];
    private const DEFENSE_POSITIONS = ['DE', 'DT', 'LB', 'CB', 'S'];
    private const EXCLUDED_FROM_OVERALL = ['K', 'P', 'LS'];

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
    }

    /**
     * Recalculate a team's overall, offense, and defense ratings from depth chart starters.
     * Call this after trades, depth chart changes, roster moves, etc.
     */
    public function recalculate(int $teamId): array
    {
        // Get all starters (slot 1) with their positions
        $stmt = $this->db->prepare(
            "SELECT p.overall_rating, p.position
             FROM depth_chart dc
             JOIN players p ON dc.player_id = p.id
             WHERE dc.team_id = ? AND dc.slot = 1"
        );
        $stmt->execute([$teamId]);
        $starters = $stmt->fetchAll();

        if (empty($starters)) {
            // Fallback: use top players by position if no depth chart
            $stmt = $this->db->prepare(
                "SELECT overall_rating, position FROM players
                 WHERE team_id = ? AND status = 'active'
                 ORDER BY overall_rating DESC LIMIT 22"
            );
            $stmt->execute([$teamId]);
            $starters = $stmt->fetchAll();
        }

        $offRatings = [];
        $defRatings = [];
        $allRatings = [];

        foreach ($starters as $s) {
            $ovr = (int) $s['overall_rating'];
            $pos = $s['position'];

            // K, P, LS don't count toward team overall
            if (!in_array($pos, self::EXCLUDED_FROM_OVERALL)) {
                $allRatings[] = $ovr;
            }

            if (in_array($pos, self::OFFENSE_POSITIONS)) {
                $offRatings[] = $ovr;
            } elseif (in_array($pos, self::DEFENSE_POSITIONS)) {
                $defRatings[] = $ovr;
            }
        }

        $overall = !empty($allRatings) ? (int) round(array_sum($allRatings) / count($allRatings)) : 75;
        $offense = !empty($offRatings) ? (int) round(array_sum($offRatings) / count($offRatings)) : 75;
        $defense = !empty($defRatings) ? (int) round(array_sum($defRatings) / count($defRatings)) : 75;

        // Update — handle case where offense/defense columns might not exist yet
        try {
            $this->db->prepare(
                "UPDATE teams SET overall_rating = ?, offense_rating = ?, defense_rating = ? WHERE id = ?"
            )->execute([$overall, $offense, $defense, $teamId]);
        } catch (\PDOException $e) {
            // Fallback: just update overall if new columns don't exist
            $this->db->prepare(
                "UPDATE teams SET overall_rating = ? WHERE id = ?"
            )->execute([$overall, $teamId]);
        }

        return [
            'overall' => $overall,
            'offense' => $offense,
            'defense' => $defense,
        ];
    }

    /**
     * Recalculate ratings for multiple teams (e.g., both sides of a trade).
     */
    public function recalculateMultiple(array $teamIds): void
    {
        foreach ($teamIds as $teamId) {
            $this->recalculate((int) $teamId);
        }
    }
}
