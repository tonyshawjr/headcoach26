<?php

namespace App\Controllers;

use App\Database\Connection;
use App\Helpers\Response;
use App\Middleware\AuthMiddleware;
use App\Models\FantasyLeague;
use App\Models\FantasyManager;
use App\Models\FantasyRoster;
use App\Models\FantasyMatchup;
use App\Services\FantasyLeagueEngine;
use App\Services\FantasyScoreEngine;

class FantasyController
{
    private FantasyLeagueEngine $engine;
    private FantasyLeague $leagueModel;
    private FantasyManager $managerModel;
    private FantasyRoster $rosterModel;
    private FantasyMatchup $matchupModel;
    private FantasyScoreEngine $scoreEngine;
    private \PDO $db;

    public function __construct()
    {
        $this->engine = new FantasyLeagueEngine();
        $this->leagueModel = new FantasyLeague();
        $this->managerModel = new FantasyManager();
        $this->rosterModel = new FantasyRoster();
        $this->matchupModel = new FantasyMatchup();
        $this->scoreEngine = new FantasyScoreEngine();
        $this->db = Connection::getInstance()->getPdo();
    }

    // ── League CRUD ────────────────────────────────────────────────────

    /**
     * GET /api/fantasy/leagues
     * List fantasy leagues for the current user's NFL league.
     */
    public function index(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $leagueId = $auth['league_id'];
        if (!$leagueId) {
            Response::error('No league associated with session');
            return;
        }

        $leagues = $this->leagueModel->getByLeague($leagueId);

        // Also get which ones this user is in
        $myLeagues = $this->leagueModel->getByManager($auth['coach_id']);
        $myLeagueIds = array_column($myLeagues, 'id');

        foreach ($leagues as &$l) {
            $l['is_member'] = in_array($l['id'], $myLeagueIds);
            $managers = $this->managerModel->getByLeague($l['id']);
            $l['manager_count'] = count($managers);
            $l['human_count'] = count(array_filter($managers, fn($m) => !$m['is_ai']));
        }
        unset($l);

        Response::json(['fantasy_leagues' => $leagues]);
    }

    /**
     * POST /api/fantasy/leagues
     * Create a new fantasy league.
     */
    public function create(): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $body = Response::getJsonBody();

        $result = $this->engine->createLeague([
            'league_id' => $auth['league_id'],
            'name' => $body['name'] ?? 'Fantasy League',
            'coach_id' => $auth['coach_id'],
            'user_id' => $auth['user_id'],
            'num_teams' => $body['num_teams'] ?? 10,
            'max_humans' => $body['max_humans'] ?? 1,
            'scoring_type' => $body['scoring_type'] ?? 'ppr',
            'scoring_rules' => $body['scoring_rules'] ?? null,
            'roster_slots' => $body['roster_slots'] ?? null,
            'playoff_start_week' => $body['playoff_start_week'] ?? 14,
            'num_playoff_teams' => $body['num_playoff_teams'] ?? 4,
            'draft_type' => $body['draft_type'] ?? 'snake',
            'draft_rounds' => $body['draft_rounds'] ?? 15,
            'waiver_type' => $body['waiver_type'] ?? 'priority',
            'faab_budget' => $body['faab_budget'] ?? 100,
            'trade_review_hours' => $body['trade_review_hours'] ?? 24,
            'team_name' => $body['team_name'] ?? 'My Fantasy Team',
            'owner_name' => $body['owner_name'] ?? $auth['username'] ?? 'Commissioner',
        ]);

        if (isset($result['error'])) {
            Response::error($result['error']);
            return;
        }

        Response::json($result, 201);
    }

    /**
     * GET /api/fantasy/leagues/{id}
     * Get full fantasy league details.
     */
    public function show(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $league = $this->leagueModel->getWithManagers($params['id']);
        if (!$league) {
            Response::notFound('Fantasy league not found');
            return;
        }

        // Add computed standings
        $league['standings'] = $this->engine->getStandings($league['id']);

        // Find current user's manager
        $myManager = $this->managerModel->findByCoach($league['id'], $auth['coach_id']);
        $league['my_manager'] = $myManager;

        Response::json(['fantasy_league' => $league]);
    }

    /**
     * POST /api/fantasy/leagues/{id}/join
     * Join a fantasy league via invite code.
     */
    public function join(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $body = Response::getJsonBody();
        $inviteCode = $body['invite_code'] ?? '';
        $teamName = $body['team_name'] ?? 'My Team';
        $ownerName = $body['owner_name'] ?? $auth['username'] ?? 'Player';

        $result = $this->engine->joinLeague($inviteCode, $auth['coach_id'], $auth['user_id'], $teamName, $ownerName);

        if (isset($result['error'])) {
            Response::error($result['error']);
            return;
        }

        Response::json($result);
    }

    // ── Draft ──────────────────────────────────────────────────────────

    /**
     * POST /api/fantasy/leagues/{id}/draft
     * Execute the draft (snake draft runs to completion).
     */
    public function draft(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $league = $this->leagueModel->find($params['id']);
        if (!$league) {
            Response::notFound('Fantasy league not found');
            return;
        }

        // Only commissioner can start draft
        if ($league['commissioner_coach_id'] !== $auth['coach_id']) {
            Response::error('Only the commissioner can start the draft', 403);
            return;
        }

        $result = $this->engine->executeDraft($params['id']);

        if (isset($result['error'])) {
            Response::error($result['error']);
            return;
        }

        Response::json($result);
    }

    /**
     * GET /api/fantasy/leagues/{id}/draft-results
     * Get the draft results/board.
     */
    public function draftResults(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $transactions = $this->db->prepare(
            "SELECT ft.*, fm.owner_name, fm.team_name, fm.draft_position,
                    p.first_name, p.last_name, p.position, p.overall_rating,
                    t.abbreviation as team_abbr
             FROM fantasy_transactions ft
             JOIN fantasy_managers fm ON fm.id = ft.fantasy_manager_id
             JOIN players p ON p.id = ft.player_id
             LEFT JOIN teams t ON t.id = p.team_id
             WHERE ft.fantasy_league_id = ? AND ft.type = 'draft'
             ORDER BY ft.id"
        );
        $transactions->execute([$params['id']]);
        $picks = $transactions->fetchAll();

        Response::json(['draft_picks' => $picks]);
    }

    // ── Roster Management ──────────────────────────────────────────────

    /**
     * GET /api/fantasy/leagues/{id}/roster
     * Get current user's roster.
     */
    public function roster(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $myManager = $this->managerModel->findByCoach($params['id'], $auth['coach_id']);
        if (!$myManager) {
            Response::error('You are not in this fantasy league');
            return;
        }

        $roster = $this->managerModel->getRosterOrdered($myManager['id']);

        Response::json([
            'manager' => $myManager,
            'roster' => $roster,
        ]);
    }

    /**
     * GET /api/fantasy/managers/{id}/roster
     * Get any manager's roster (for viewing opponents).
     */
    public function managerRoster(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $manager = $this->managerModel->find($params['id']);
        if (!$manager) {
            Response::notFound('Manager not found');
            return;
        }

        $roster = $this->managerModel->getRosterOrdered($manager['id']);

        Response::json([
            'manager' => $manager,
            'roster' => $roster,
        ]);
    }

    /**
     * PUT /api/fantasy/leagues/{id}/lineup
     * Set lineup (swap starter/bench).
     */
    public function setLineup(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $body = Response::getJsonBody();
        $myManager = $this->managerModel->findByCoach($params['id'], $auth['coach_id']);
        if (!$myManager) {
            Response::error('You are not in this fantasy league');
            return;
        }

        // Expect: {lineup: [{player_id, roster_slot, is_starter}]}
        $lineup = $body['lineup'] ?? [];
        if (empty($lineup)) {
            Response::error('No lineup changes provided');
            return;
        }

        foreach ($lineup as $slot) {
            $this->db->prepare(
                "UPDATE fantasy_rosters SET roster_slot = ?, is_starter = ?
                 WHERE fantasy_manager_id = ? AND player_id = ?"
            )->execute([
                $slot['roster_slot'], $slot['is_starter'] ? 1 : 0,
                $myManager['id'], $slot['player_id'],
            ]);
        }

        $roster = $this->managerModel->getRosterOrdered($myManager['id']);
        Response::json(['roster' => $roster]);
    }

    /**
     * POST /api/fantasy/leagues/{id}/add-drop
     * Add a free agent and drop a player.
     */
    public function addDrop(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $body = Response::getJsonBody();
        $addPlayerId = $body['add_player_id'] ?? null;
        $dropPlayerId = $body['drop_player_id'] ?? null;

        if (!$addPlayerId || !$dropPlayerId) {
            Response::error('Must specify add_player_id and drop_player_id');
            return;
        }

        $myManager = $this->managerModel->findByCoach($params['id'], $auth['coach_id']);
        if (!$myManager) {
            Response::error('You are not in this fantasy league');
            return;
        }

        // Check player is available
        if ($this->rosterModel->isOwned($params['id'], $addPlayerId)) {
            Response::error('Player is already owned');
            return;
        }

        // Drop
        $this->rosterModel->dropPlayer($myManager['id'], $dropPlayerId);

        // Add
        $league = $this->leagueModel->find($params['id']);
        $this->rosterModel->create([
            'fantasy_league_id' => $params['id'],
            'fantasy_manager_id' => $myManager['id'],
            'player_id' => $addPlayerId,
            'roster_slot' => 'BN',
            'is_starter' => 0,
            'acquired_via' => 'free_agent',
            'acquired_week' => $league['created_week'] ?? 0,
        ]);

        // Log transaction
        $this->db->prepare(
            "INSERT INTO fantasy_transactions (fantasy_league_id, fantasy_manager_id, type, player_id, player2_id, details, week, created_at)
             VALUES (?, ?, 'add_drop', ?, ?, NULL, ?, ?)"
        )->execute([
            $params['id'], $myManager['id'], $addPlayerId, $dropPlayerId,
            $league['created_week'] ?? 0, date('Y-m-d H:i:s'),
        ]);

        Response::success('Player added successfully');
    }

    // ── Matchups & Standings ───────────────────────────────────────────

    /**
     * GET /api/fantasy/leagues/{id}/matchups/{week}
     * Get matchups for a specific week.
     */
    public function matchups(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $matchups = $this->matchupModel->getByWeek($params['id'], $params['week']);

        // Enrich with player scores if the week has been scored
        foreach ($matchups as &$m) {
            $m['team1_roster'] = $this->getManagerWeekScores($m['manager1_id'], $params['id'], $params['week']);
            $m['team2_roster'] = $this->getManagerWeekScores($m['manager2_id'], $params['id'], $params['week']);
        }
        unset($m);

        Response::json(['matchups' => $matchups]);
    }

    /**
     * GET /api/fantasy/leagues/{id}/standings
     * Get league standings.
     */
    public function standings(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $standings = $this->engine->getStandings($params['id']);
        Response::json(['standings' => $standings]);
    }

    /**
     * GET /api/fantasy/leagues/{id}/schedule
     * Get current user's full season schedule.
     */
    public function schedule(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $myManager = $this->managerModel->findByCoach($params['id'], $auth['coach_id']);
        if (!$myManager) {
            // Show all matchups grouped by week instead
            $league = $this->leagueModel->find($params['id']);
            $allMatchups = [];
            $endWeek = $league['championship_week'] ?? 16;
            $startWeek = ($league['created_week'] ?? 0) + 1;
            for ($w = $startWeek; $w <= $endWeek; $w++) {
                $allMatchups[$w] = $this->matchupModel->getByWeek($params['id'], $w);
            }
            Response::json(['schedule' => $allMatchups]);
            return;
        }

        $schedule = $this->matchupModel->getManagerSchedule($params['id'], $myManager['id']);
        Response::json(['schedule' => $schedule, 'manager' => $myManager]);
    }

    // ── Available Players & Waivers ────────────────────────────────────

    /**
     * GET /api/fantasy/leagues/{id}/available
     * Get available (unowned) players.
     */
    public function availablePlayers(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $league = $this->leagueModel->find($params['id']);
        if (!$league) {
            Response::notFound('League not found');
            return;
        }

        $position = $_GET['position'] ?? null;
        $limit = (int) ($_GET['limit'] ?? 50);

        $players = $this->rosterModel->getAvailablePlayers(
            $params['id'], $league['league_id'], $position, $limit
        );

        Response::json(['players' => $players]);
    }

    // ── Trades ─────────────────────────────────────────────────────────

    /**
     * GET /api/fantasy/leagues/{id}/trades
     * Get trade proposals for current user.
     */
    public function trades(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $myManager = $this->managerModel->findByCoach($params['id'], $auth['coach_id']);

        $stmt = $this->db->prepare(
            "SELECT ftp.*,
                    p1.owner_name as proposer_name, p1.team_name as proposer_team,
                    p2.owner_name as recipient_name, p2.team_name as recipient_team
             FROM fantasy_trade_proposals ftp
             JOIN fantasy_managers p1 ON p1.id = ftp.proposer_id
             JOIN fantasy_managers p2 ON p2.id = ftp.recipient_id
             WHERE ftp.fantasy_league_id = ?
             ORDER BY ftp.created_at DESC
             LIMIT 50"
        );
        $stmt->execute([$params['id']]);
        $trades = $stmt->fetchAll();

        // Decode player arrays and add names
        foreach ($trades as &$t) {
            $t['players_offered'] = $this->enrichPlayerIds(json_decode($t['players_offered'], true) ?? []);
            $t['players_requested'] = $this->enrichPlayerIds(json_decode($t['players_requested'], true) ?? []);
            $t['is_mine'] = $myManager && ($t['proposer_id'] === $myManager['id'] || $t['recipient_id'] === $myManager['id']);
            $t['can_respond'] = $myManager && $t['recipient_id'] === $myManager['id'] && $t['status'] === 'pending';
        }
        unset($t);

        Response::json(['trades' => $trades]);
    }

    /**
     * POST /api/fantasy/leagues/{id}/trades
     * Propose a trade.
     */
    public function proposeTrade(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $body = Response::getJsonBody();
        $myManager = $this->managerModel->findByCoach($params['id'], $auth['coach_id']);
        if (!$myManager) {
            Response::error('You are not in this league');
            return;
        }

        $recipientId = $body['recipient_id'] ?? null;
        $offered = $body['players_offered'] ?? [];
        $requested = $body['players_requested'] ?? [];

        if (!$recipientId || empty($offered) || empty($requested)) {
            Response::error('Must specify recipient, players_offered, and players_requested');
            return;
        }

        $propId = $this->db->prepare(
            "INSERT INTO fantasy_trade_proposals (fantasy_league_id, proposer_id, recipient_id, players_offered, players_requested, message, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)"
        );
        $propId->execute([
            $params['id'], $myManager['id'], $recipientId,
            json_encode($offered), json_encode($requested),
            $body['message'] ?? null, date('Y-m-d H:i:s'),
        ]);

        // If recipient is AI, evaluate immediately
        $recipient = $this->managerModel->find($recipientId);
        if ($recipient && $recipient['is_ai']) {
            $league = $this->leagueModel->getWithManagers($params['id']);
            $brain = new \App\Services\FantasyBrain($recipient, $league);

            $offeredPlayers = $this->enrichPlayerIds($offered);
            $requestedPlayers = $this->enrichPlayerIds($requested);

            $accepted = $brain->evaluateTradeProposal($offeredPlayers, $requestedPlayers);
            $lastId = $this->db->lastInsertId();

            if ($accepted) {
                $this->engine->executeTrade($params['id'], $myManager['id'], $recipientId, $offered, $requested);
                $this->db->prepare(
                    "UPDATE fantasy_trade_proposals SET status = 'accepted', responded_at = ? WHERE id = ?"
                )->execute([date('Y-m-d H:i:s'), $lastId]);

                Response::json(['status' => 'accepted', 'message' => 'Trade accepted!']);
                return;
            } else {
                $this->db->prepare(
                    "UPDATE fantasy_trade_proposals SET status = 'rejected', responded_at = ? WHERE id = ?"
                )->execute([date('Y-m-d H:i:s'), $lastId]);

                Response::json(['status' => 'rejected', 'message' => 'Trade rejected.']);
                return;
            }
        }

        Response::json(['status' => 'pending', 'message' => 'Trade proposed. Waiting for response.'], 201);
    }

    /**
     * PUT /api/fantasy/trades/{id}/respond
     * Accept or reject a trade proposal.
     */
    public function respondTrade(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $body = Response::getJsonBody();
        $action = $body['action'] ?? ''; // 'accept' or 'reject'

        $stmt = $this->db->prepare("SELECT * FROM fantasy_trade_proposals WHERE id = ?");
        $stmt->execute([$params['id']]);
        $proposal = $stmt->fetch();

        if (!$proposal) {
            Response::notFound('Trade proposal not found');
            return;
        }

        $myManager = $this->managerModel->findByCoach($proposal['fantasy_league_id'], $auth['coach_id']);
        if (!$myManager || $myManager['id'] !== $proposal['recipient_id']) {
            Response::error('You cannot respond to this trade', 403);
            return;
        }

        if ($proposal['status'] !== 'pending') {
            Response::error('This trade has already been resolved');
            return;
        }

        if ($action === 'accept') {
            $offered = json_decode($proposal['players_offered'], true);
            $requested = json_decode($proposal['players_requested'], true);
            $this->engine->executeTrade(
                $proposal['fantasy_league_id'],
                $proposal['proposer_id'], $proposal['recipient_id'],
                $offered, $requested
            );
            $this->db->prepare(
                "UPDATE fantasy_trade_proposals SET status = 'accepted', responded_at = ? WHERE id = ?"
            )->execute([date('Y-m-d H:i:s'), $params['id']]);
            Response::success('Trade accepted');
        } else {
            $this->db->prepare(
                "UPDATE fantasy_trade_proposals SET status = 'rejected', responded_at = ? WHERE id = ?"
            )->execute([date('Y-m-d H:i:s'), $params['id']]);
            Response::success('Trade rejected');
        }
    }

    // ── Transactions ───────────────────────────────────────────────────

    /**
     * GET /api/fantasy/leagues/{id}/transactions
     * Get the transaction log.
     */
    public function transactions(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $log = $this->engine->getTransactionLog($params['id']);
        Response::json(['transactions' => $log]);
    }

    // ── Player Rankings ────────────────────────────────────────────────

    /**
     * GET /api/fantasy/leagues/{id}/rankings
     * Get player fantasy point rankings.
     */
    public function rankings(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $position = $_GET['position'] ?? null;
        $rankings = $this->scoreEngine->getPlayerRankings($params['id'], $position);

        Response::json(['rankings' => $rankings]);
    }

    // ── Playoff Bracket ────────────────────────────────────────────────

    /**
     * GET /api/fantasy/leagues/{id}/playoffs
     * Get playoff bracket.
     */
    public function playoffs(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $bracket = $this->matchupModel->getPlayoffBracket($params['id']);
        Response::json(['bracket' => $bracket]);
    }

    // ── Helpers ────────────────────────────────────────────────────────

    /**
     * Get a manager's starter scores for a given week.
     */
    private function getManagerWeekScores(int $managerId, int $fantasyLeagueId, int $week): array
    {
        $stmt = $this->db->prepare(
            "SELECT fr.player_id, fr.roster_slot, fr.is_starter,
                    p.first_name, p.last_name, p.position,
                    COALESCE(fs.points, 0) as points
             FROM fantasy_rosters fr
             JOIN players p ON p.id = fr.player_id
             LEFT JOIN fantasy_scores fs ON fs.player_id = fr.player_id
                AND fs.fantasy_league_id = ? AND fs.week = ?
             WHERE fr.fantasy_manager_id = ?
             ORDER BY fr.is_starter DESC,
                CASE p.position
                    WHEN 'QB' THEN 1 WHEN 'RB' THEN 2 WHEN 'WR' THEN 3
                    WHEN 'TE' THEN 4 WHEN 'K' THEN 5 ELSE 6
                END"
        );
        $stmt->execute([$fantasyLeagueId, $week, $managerId]);
        return $stmt->fetchAll();
    }

    /**
     * Enrich an array of player IDs with name/position/OVR data.
     */
    private function enrichPlayerIds(array $playerIds): array
    {
        if (empty($playerIds)) return [];

        $players = [];
        $playerModel = new \App\Models\Player();
        foreach ($playerIds as $pid) {
            $p = $playerModel->find($pid);
            if ($p) {
                $players[] = [
                    'id' => $p['id'],
                    'first_name' => $p['first_name'],
                    'last_name' => $p['last_name'],
                    'position' => $p['position'],
                    'overall_rating' => $p['overall_rating'],
                    'team_abbr' => $p['team_abbr'] ?? '',
                ];
            }
        }
        return $players;
    }
}
