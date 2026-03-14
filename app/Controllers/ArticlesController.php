<?php

namespace App\Controllers;

use App\Helpers\Response;
use App\Middleware\AuthMiddleware;
use App\Models\League;
use App\Database\Connection;

class ArticlesController
{
    private \PDO $db;
    private League $league;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
        $this->league = new League();
    }

    /**
     * GET /api/leagues/{league_id}/articles
     * List articles by league, filterable by type, week, and team_id via query params.
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

        $sql = "SELECT * FROM articles WHERE league_id = ?";
        $queryParams = [$leagueId];

        // Optional filters
        if (isset($_GET['type']) && $_GET['type'] !== '') {
            $sql .= " AND type = ?";
            $queryParams[] = $_GET['type'];
        }

        if (isset($_GET['week']) && $_GET['week'] !== '') {
            $sql .= " AND week = ?";
            $queryParams[] = (int) $_GET['week'];
        }

        if (isset($_GET['team_id']) && $_GET['team_id'] !== '') {
            $sql .= " AND team_id = ?";
            $queryParams[] = (int) $_GET['team_id'];
        }

        $sql .= " ORDER BY published_at DESC";

        // Pagination
        $limit = isset($_GET['limit']) ? min(50, max(1, (int) $_GET['limit'])) : 20;
        $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
        $offset = ($page - 1) * $limit;

        $sql .= " LIMIT {$limit} OFFSET {$offset}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($queryParams);
        $articles = $stmt->fetchAll();

        // Get total count for pagination
        $countSql = "SELECT COUNT(*) as total FROM articles WHERE league_id = ?";
        $countParams = [$leagueId];

        if (isset($_GET['type']) && $_GET['type'] !== '') {
            $countSql .= " AND type = ?";
            $countParams[] = $_GET['type'];
        }
        if (isset($_GET['week']) && $_GET['week'] !== '') {
            $countSql .= " AND week = ?";
            $countParams[] = (int) $_GET['week'];
        }
        if (isset($_GET['team_id']) && $_GET['team_id'] !== '') {
            $countSql .= " AND team_id = ?";
            $countParams[] = (int) $_GET['team_id'];
        }

        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($countParams);
        $total = (int) $countStmt->fetch()['total'];

        Response::json([
            'articles' => $articles,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => (int) ceil($total / $limit),
        ]);
    }

    /**
     * GET /api/articles/{id}
     * Single article detail.
     */
    public function show(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $stmt = $this->db->prepare("SELECT * FROM articles WHERE id = ?");
        $stmt->execute([(int) $params['id']]);
        $article = $stmt->fetch();

        if (!$article) {
            Response::notFound('Article not found');
            return;
        }

        Response::json(['article' => $article]);
    }

    /**
     * GET /api/leagues/{league_id}/ticker
     * Latest 20 ticker items for the league.
     */
    public function ticker(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $leagueId = (int) $params['league_id'];

        $stmt = $this->db->prepare(
            "SELECT * FROM ticker_items
             WHERE league_id = ?
             ORDER BY created_at DESC
             LIMIT 20"
        );
        $stmt->execute([$leagueId]);
        $items = $stmt->fetchAll();

        Response::json(['ticker' => $items]);
    }
}
