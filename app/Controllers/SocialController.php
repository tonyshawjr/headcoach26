<?php

namespace App\Controllers;

use App\Helpers\Response;
use App\Middleware\AuthMiddleware;
use App\Models\League;
use App\Database\Connection;

class SocialController
{
    private \PDO $db;
    private League $league;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
        $this->league = new League();
    }

    /**
     * GET /api/leagues/{league_id}/social
     * GridironX social posts by league, filterable by week via query param.
     */
    public function index(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $leagueId = (int) $params['league_id'];
        $league = $this->league->find($leagueId);
        if (!$league) {
            Response::notFound('League not found');
            return;
        }

        $sql = "SELECT sp.*,
                       t.abbreviation as team_abbr, t.primary_color as team_color
                FROM social_posts sp
                LEFT JOIN teams t ON t.id = sp.team_id
                WHERE sp.league_id = ?";
        $queryParams = [$leagueId];

        if (isset($_GET['week']) && $_GET['week'] !== '') {
            $sql .= " AND sp.week = ?";
            $queryParams[] = (int) $_GET['week'];
        }

        if (isset($_GET['team_id']) && $_GET['team_id'] !== '') {
            $sql .= " AND sp.team_id = ?";
            $queryParams[] = (int) $_GET['team_id'];
        }

        $sql .= " ORDER BY sp.posted_at DESC";

        // Pagination
        $limit = isset($_GET['limit']) ? min(50, max(1, (int) $_GET['limit'])) : 25;
        $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
        $offset = ($page - 1) * $limit;

        $sql .= " LIMIT {$limit} OFFSET {$offset}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($queryParams);
        $posts = $stmt->fetchAll();

        Response::json($posts);
    }
}
