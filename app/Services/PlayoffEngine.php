<?php

namespace App\Services;

use App\Database\Connection;
use App\Models\Game;
use App\Models\League;
use App\Models\Team;

class PlayoffEngine
{
    private \PDO $db;
    private Game $game;
    private League $league;
    private Team $team;

    /** Map round names to week offsets (relative to first playoff week) */
    private const ROUND_ORDER = [
        'wild_card' => 0,
        'divisional' => 1,
        'conference_championship' => 2,
        'super_bowl' => 3,
    ];

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
        $this->game = new Game();
        $this->league = new League();
        $this->team = new Team();
    }

    // ─── Seeding ──────────────────────────────────────────────────────────

    /**
     * Calculate playoff seeding for a league.
     *
     * Returns ['conferences' => ['AC' => [seed => teamData, ...], 'PC' => [...]]].
     * Each team entry includes: id, city, name, abbreviation, conference, division,
     * wins, losses, ties, win_pct, points_for, points_against, point_diff,
     * seed, is_division_winner, is_bye, primary_color, secondary_color.
     */
    public function calculatePlayoffSeeding(int $leagueId): array
    {
        $teams = $this->team->getByLeague($leagueId);
        if (empty($teams)) {
            return ['conferences' => []];
        }

        // Group by conference and division
        $conferences = [];
        $divisions = [];
        foreach ($teams as $t) {
            $conf = $t['conference'];
            $div = $t['division'];
            $entry = $this->buildTeamEntry($t);
            $conferences[$conf][] = $entry;
            $divisions[$conf][$div][] = $entry;
        }

        $result = ['conferences' => []];

        foreach ($conferences as $conf => $confTeams) {
            $confDivisions = $divisions[$conf] ?? [];
            $numDivisions = count($confDivisions);

            // Determine wild card spots based on number of divisions
            $wcSpots = match (true) {
                $numDivisions >= 4 => 3,
                $numDivisions === 3 => 3,
                $numDivisions === 2 => 2,
                $numDivisions === 1 => 3,
                default => 0,
            };
            $totalPlayoffTeams = $numDivisions + $wcSpots;

            // Find division winners (best record per division)
            $divWinners = [];
            $nonDivWinners = [];

            foreach ($confDivisions as $divName => $divTeams) {
                $sorted = $this->sortTeams($divTeams);
                $winner = $sorted[0];
                $winner['is_division_winner'] = true;
                $divWinners[] = $winner;

                for ($i = 1; $i < count($sorted); $i++) {
                    $sorted[$i]['is_division_winner'] = false;
                    $nonDivWinners[] = $sorted[$i];
                }
            }

            // Sort division winners by record
            $divWinners = $this->sortTeams($divWinners);

            // Sort non-division-winners for wild card
            $nonDivWinners = $this->sortTeams($nonDivWinners);

            // Take top wild card teams
            $wcTeams = array_slice($nonDivWinners, 0, $wcSpots);
            foreach ($wcTeams as &$wc) {
                $wc['is_division_winner'] = false;
            }
            unset($wc);

            // Merge and assign seeds
            $seeded = [];
            $seed = 1;

            foreach ($divWinners as $dw) {
                $dw['seed'] = $seed;
                $dw['is_bye'] = ($seed === 1);
                $seeded[] = $dw;
                $seed++;
            }

            foreach ($wcTeams as $wc) {
                $wc['seed'] = $seed;
                $wc['is_bye'] = false;
                $seeded[] = $wc;
                $seed++;
            }

            $result['conferences'][$conf] = $seeded;
        }

        return $result;
    }

    // ─── Bracket Generation ───────────────────────────────────────────────

    /**
     * Generate Wild Card round games from seeding.
     * NFL format: #1 gets bye, #2 vs #7, #3 vs #6, #4 vs #5.
     * Higher seed is always home.
     */
    public function generatePlayoffBracket(int $leagueId, int $seasonId): array
    {
        $seeding = $this->calculatePlayoffSeeding($leagueId);
        $firstPlayoffWeek = $this->getFirstPlayoffWeek($leagueId);

        $games = [];

        foreach ($seeding['conferences'] as $conf => $seededTeams) {
            $totalTeams = count($seededTeams);
            if ($totalTeams < 2) continue;

            // Index by seed for easy lookup
            $bySeed = [];
            foreach ($seededTeams as $t) {
                $bySeed[$t['seed']] = $t;
            }

            if ($totalTeams >= 7) {
                // NFL 7-team format: #1 bye, #2 vs #7, #3 vs #6, #4 vs #5
                $matchups = [[2, 7], [3, 6], [4, 5]];
            } elseif ($totalTeams >= 6) {
                // 6-team: #1 bye, #2 bye, #3 vs #6, #4 vs #5
                $matchups = [[3, 6], [4, 5]];
            } elseif ($totalTeams >= 4) {
                // 4-team: #1 vs #4, #2 vs #3
                $matchups = [[1, 4], [2, 3]];
            } else {
                // 2-team: #1 vs #2
                $matchups = [[1, 2]];
            }

            foreach ($matchups as [$homeSeed, $awaySeed]) {
                if (!isset($bySeed[$homeSeed]) || !isset($bySeed[$awaySeed])) continue;
                $games[] = $this->makePlayoffGame(
                    $leagueId, $seasonId, $firstPlayoffWeek,
                    'wild_card', (int)$bySeed[$homeSeed]['id'], (int)$bySeed[$awaySeed]['id']
                );
            }
        }

        $this->insertGames($games);

        return $this->getPlayoffBracket($leagueId);
    }

    // ─── Round Advancement ────────────────────────────────────────────────

    /**
     * Advance to the next playoff round based on completed games.
     * Handles reseeding: highest remaining seed always plays lowest remaining seed.
     * Bye teams are automatically included in the next round they enter.
     */
    public function advancePlayoffRound(int $leagueId): array
    {
        $season = $this->league->getCurrentSeason($leagueId);
        if (!$season) {
            throw new \RuntimeException('No active season');
        }
        $seasonId = (int)$season['id'];

        $currentRound = $this->getCurrentPlayoffRound($leagueId);
        $nextRound = $this->getNextRound($currentRound);

        if ($nextRound === null) {
            return $this->getPlayoffBracket($leagueId);
        }

        // Don't generate games if they already exist for this round
        $existingGames = $this->game->query(
            "SELECT COUNT(*) as cnt FROM games WHERE league_id = ? AND season_id = ? AND game_type = ?",
            [$leagueId, $seasonId, $nextRound]
        );
        if ((int)($existingGames[0]['cnt'] ?? 0) > 0) {
            return $this->getPlayoffBracket($leagueId);
        }

        $firstPlayoffWeek = $this->getFirstPlayoffWeek($leagueId);
        $nextWeek = $firstPlayoffWeek + self::ROUND_ORDER[$nextRound];

        $seeding = $this->calculatePlayoffSeeding($leagueId);

        if ($nextRound === 'super_bowl') {
            return $this->generateSuperBowl($leagueId, $seasonId, $nextWeek, $seeding);
        }

        $games = [];

        foreach ($seeding['conferences'] as $conf => $seededTeams) {
            $seedLookup = [];
            foreach ($seededTeams as $t) {
                $seedLookup[(int)$t['id']] = $t;
            }

            // Get all playoff game winners AND bye teams for this conference
            $confTeamIds = array_map(fn($t) => (int)$t['id'], $seededTeams);
            $advancingTeams = $this->getAdvancingTeams($leagueId, $seasonId, $conf, $confTeamIds, $seedLookup, $currentRound);

            if (count($advancingTeams) < 2) continue;

            // Reseed: sort by original seed (highest = lowest number)
            usort($advancingTeams, fn($a, $b) => $a['seed'] - $b['seed']);

            // Pair: #1 vs lowest, #2 vs next-lowest (NFL reseeding)
            $numGames = intdiv(count($advancingTeams), 2);
            for ($i = 0; $i < $numGames; $i++) {
                $home = $advancingTeams[$i];
                $away = $advancingTeams[count($advancingTeams) - 1 - $i];
                $games[] = $this->makePlayoffGame(
                    $leagueId, $seasonId, $nextWeek,
                    $nextRound, (int)$home['id'], (int)$away['id']
                );
            }
        }

        $this->insertGames($games);

        return $this->getPlayoffBracket($leagueId);
    }

    // ─── Bracket Retrieval ────────────────────────────────────────────────

    /**
     * Get the full playoff bracket with all rounds.
     */
    public function getPlayoffBracket(int $leagueId): array
    {
        $season = $this->league->getCurrentSeason($leagueId);
        if (!$season) {
            return ['rounds' => [], 'seeding' => ['conferences' => []]];
        }
        $seasonId = (int)$season['id'];

        // Get all playoff games for this season
        $playoffGames = $this->game->query(
            "SELECT g.*,
                    ht.city AS home_city, ht.name AS home_name, ht.abbreviation AS home_abbr,
                    ht.primary_color AS home_color, ht.conference AS home_conf,
                    at.city AS away_city, at.name AS away_name, at.abbreviation AS away_abbr,
                    at.primary_color AS away_color, at.conference AS away_conf
             FROM games g
             JOIN teams ht ON ht.id = g.home_team_id
             JOIN teams at ON at.id = g.away_team_id
             WHERE g.league_id = ? AND g.season_id = ? AND g.game_type != 'regular'
             ORDER BY g.week ASC, g.id ASC",
            [$leagueId, $seasonId]
        );

        // Organize by round
        $rounds = [];
        foreach ($playoffGames as $g) {
            $roundName = $g['game_type'];
            $isPlayed = (int)$g['is_simulated'] === 1;
            $winnerId = null;
            if ($isPlayed) {
                $winnerId = ((int)$g['home_score'] > (int)$g['away_score'])
                    ? (int)$g['home_team_id']
                    : (int)$g['away_team_id'];
            }

            $rounds[$roundName][] = [
                'game_id' => (int)$g['id'],
                'week' => (int)$g['week'],
                'round_name' => $this->roundDisplayName($roundName),
                'home_team' => [
                    'id' => (int)$g['home_team_id'],
                    'city' => $g['home_city'],
                    'name' => $g['home_name'],
                    'abbreviation' => $g['home_abbr'],
                    'primary_color' => $g['home_color'],
                    'conference' => $g['home_conf'],
                ],
                'away_team' => [
                    'id' => (int)$g['away_team_id'],
                    'city' => $g['away_city'],
                    'name' => $g['away_name'],
                    'abbreviation' => $g['away_abbr'],
                    'primary_color' => $g['away_color'],
                    'conference' => $g['away_conf'],
                ],
                'home_score' => $isPlayed ? (int)$g['home_score'] : null,
                'away_score' => $isPlayed ? (int)$g['away_score'] : null,
                'is_played' => $isPlayed,
                'winner_id' => $winnerId,
            ];
        }

        // Get seeding for display
        $seeding = $this->calculatePlayoffSeeding($leagueId);

        // Build seed lookup: team_id => seed number
        $seedLookup = [];
        foreach ($seeding['conferences'] as $confSeeds) {
            foreach ($confSeeds as $s) {
                $seedLookup[(int) $s['id']] = (int) $s['seed'];
            }
        }

        // Enrich each game with seed and conference
        foreach ($rounds as &$roundGames) {
            foreach ($roundGames as &$g) {
                $homeId = (int) $g['home_team']['id'];
                $awayId = (int) $g['away_team']['id'];
                $g['home_team']['seed'] = $seedLookup[$homeId] ?? null;
                $g['away_team']['seed'] = $seedLookup[$awayId] ?? null;
                // Conference = same for both teams unless it's the championship
                $homeConf = $g['home_team']['conference'] ?? '';
                $awayConf = $g['away_team']['conference'] ?? '';
                $g['conference'] = ($homeConf === $awayConf) ? $homeConf : 'Championship';
            }
            unset($g);
        }
        unset($roundGames);

        // Determine current round status
        $currentRound = $this->getCurrentPlayoffRound($leagueId);
        $nextRound = $currentRound ? $this->getNextRound($currentRound) : null;
        $isComplete = $this->isPlayoffsComplete($leagueId);

        // Find champion if super bowl is played
        $champion = null;
        if ($isComplete && !empty($rounds['super_bowl'])) {
            $sb = $rounds['super_bowl'][0];
            if ($sb['is_played'] && $sb['winner_id']) {
                $champTeam = $this->team->find($sb['winner_id']);
                if ($champTeam) {
                    $champion = [
                        'id' => (int)$champTeam['id'],
                        'city' => $champTeam['city'],
                        'name' => $champTeam['name'],
                        'abbreviation' => $champTeam['abbreviation'],
                        'primary_color' => $champTeam['primary_color'],
                    ];
                }
            }
        }

        return [
            'rounds' => $rounds,
            'seeding' => $seeding,
            'current_round' => $currentRound,
            'next_round' => $nextRound,
            'is_complete' => $isComplete,
            'champion' => $champion,
        ];
    }

    // ─── Helpers ──────────────────────────────────────────────────────────

    /**
     * Check if a team made the playoffs in the current season.
     */
    public function isPlayoffTeam(int $teamId, int $leagueId): bool
    {
        $seeding = $this->calculatePlayoffSeeding($leagueId);
        foreach ($seeding['conferences'] as $conf => $teams) {
            foreach ($teams as $t) {
                if ((int)$t['id'] === $teamId) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Check if the playoffs are fully complete (super bowl played).
     */
    public function isPlayoffsComplete(int $leagueId): bool
    {
        $season = $this->league->getCurrentSeason($leagueId);
        if (!$season) return false;

        $sb = $this->game->query(
            "SELECT id, is_simulated FROM games
             WHERE league_id = ? AND season_id = ? AND game_type = 'super_bowl'",
            [$leagueId, (int)$season['id']]
        );

        return !empty($sb) && (int)$sb[0]['is_simulated'] === 1;
    }

    // ─── Private Helpers ──────────────────────────────────────────────────

    /**
     * Build a standardized team entry for seeding output.
     */
    private function buildTeamEntry(array $t): array
    {
        $total = (int)$t['wins'] + (int)$t['losses'] + (int)$t['ties'];
        $winPct = $total > 0
            ? round(((int)$t['wins'] + (int)$t['ties'] * 0.5) / $total, 3)
            : 0.0;

        return [
            'id' => (int)$t['id'],
            'city' => $t['city'],
            'name' => $t['name'],
            'abbreviation' => $t['abbreviation'],
            'conference' => $t['conference'],
            'division' => $t['division'],
            'primary_color' => $t['primary_color'],
            'secondary_color' => $t['secondary_color'] ?? '#FFFFFF',
            'wins' => (int)$t['wins'],
            'losses' => (int)$t['losses'],
            'ties' => (int)$t['ties'],
            'win_pct' => $winPct,
            'points_for' => (int)$t['points_for'],
            'points_against' => (int)$t['points_against'],
            'point_diff' => (int)$t['points_for'] - (int)$t['points_against'],
            'seed' => 0,
            'is_division_winner' => false,
            'is_bye' => false,
        ];
    }

    /**
     * Sort teams by tiebreaker rules:
     * 1. Win percentage (descending)
     * 2. Point differential (descending)
     * 3. Points for (descending)
     */
    private function sortTeams(array $teams): array
    {
        usort($teams, function ($a, $b) {
            // Win percentage
            $wpDiff = $b['win_pct'] <=> $a['win_pct'];
            if ($wpDiff !== 0) return $wpDiff;

            // Point differential
            $pdDiff = $b['point_diff'] <=> $a['point_diff'];
            if ($pdDiff !== 0) return $pdDiff;

            // Points for
            return $b['points_for'] <=> $a['points_for'];
        });

        return $teams;
    }

    /**
     * Get the first playoff week number. This is the week after the last
     * regular season game week.
     */
    private function getFirstPlayoffWeek(int $leagueId): int
    {
        $season = $this->league->getCurrentSeason($leagueId);
        if (!$season) return 19; // fallback

        $result = $this->game->query(
            "SELECT MAX(week) AS max_week FROM games
             WHERE league_id = ? AND season_id = ? AND game_type = 'regular'",
            [$leagueId, (int)$season['id']]
        );

        $maxWeek = (int)($result[0]['max_week'] ?? 18);
        return $maxWeek + 1;
    }

    /**
     * Determine the current playoff round based on existing games.
     * Returns the most recently created round that has all games played,
     * or the round that still has unplayed games.
     */
    private function getCurrentPlayoffRound(int $leagueId): ?string
    {
        $season = $this->league->getCurrentSeason($leagueId);
        if (!$season) return null;

        $roundOrder = ['wild_card', 'divisional', 'conference_championship', 'super_bowl'];

        // Find the latest round that has games
        $latestRound = null;
        foreach ($roundOrder as $round) {
            $games = $this->game->query(
                "SELECT COUNT(*) AS total, SUM(is_simulated) AS simmed FROM games
                 WHERE league_id = ? AND season_id = ? AND game_type = ?",
                [$leagueId, (int)$season['id'], $round]
            );

            if ((int)($games[0]['total'] ?? 0) > 0) {
                $latestRound = $round;
            }
        }

        return $latestRound;
    }

    /**
     * Check if all games in a specific round have been simulated.
     */
    private function isRoundComplete(int $leagueId, string $round): bool
    {
        $season = $this->league->getCurrentSeason($leagueId);
        if (!$season) return false;

        $games = $this->game->query(
            "SELECT COUNT(*) AS total, SUM(is_simulated) AS simmed FROM games
             WHERE league_id = ? AND season_id = ? AND game_type = ?",
            [$leagueId, (int)$season['id'], $round]
        );

        $total = (int)($games[0]['total'] ?? 0);
        $simmed = (int)($games[0]['simmed'] ?? 0);

        return $total > 0 && $total === $simmed;
    }

    /**
     * Get the next round after the given round.
     */
    private function getNextRound(string $currentRound): ?string
    {
        $order = array_keys(self::ROUND_ORDER);
        $idx = array_search($currentRound, $order);
        if ($idx === false || $idx >= count($order) - 1) {
            return null;
        }
        return $order[$idx + 1];
    }

    /**
     * Get remaining playoff teams in a conference after all completed rounds.
     * A team is "remaining" if it won its most recent playoff game,
     * or if it had a bye and hasn't lost yet.
     */
    /**
     * Get teams advancing to the next round.
     * Includes: winners of the current round + bye teams who haven't played yet.
     */
    private function getAdvancingTeams(int $leagueId, int $seasonId, string $conf, array $confTeamIds, array $seedLookup, string $currentRound): array
    {
        $placeholders = implode(',', array_fill(0, count($confTeamIds), '?'));
        $params = array_merge([$leagueId, $seasonId, $currentRound], $confTeamIds, $confTeamIds);

        // Get all games from the CURRENT round involving these conference teams
        $currentRoundGames = $this->game->query(
            "SELECT * FROM games
             WHERE league_id = ? AND season_id = ? AND game_type = ?
             AND is_simulated = 1
             AND (home_team_id IN ({$placeholders}) OR away_team_id IN ({$placeholders}))",
            $params
        );

        // Find winners of the current round
        $winners = [];
        $participants = []; // all teams that played in this round
        foreach ($currentRoundGames as $g) {
            $homeId = (int)$g['home_team_id'];
            $awayId = (int)$g['away_team_id'];
            $participants[$homeId] = true;
            $participants[$awayId] = true;

            $winnerId = ((int)$g['home_score'] > (int)$g['away_score']) ? $homeId : $awayId;
            if (isset($seedLookup[$winnerId])) {
                $winners[$winnerId] = $seedLookup[$winnerId];
            }
        }

        // Find bye teams: seeded teams that didn't play in any round up to and including current
        // They advance automatically
        $allRoundsParams = array_merge([$leagueId, $seasonId], $confTeamIds, $confTeamIds);
        $allPlayoffGames = $this->game->query(
            "SELECT home_team_id, away_team_id FROM games
             WHERE league_id = ? AND season_id = ? AND game_type != 'regular'
             AND (home_team_id IN ({$placeholders}) OR away_team_id IN ({$placeholders}))",
            $allRoundsParams
        );

        $everPlayed = [];
        foreach ($allPlayoffGames as $g) {
            $everPlayed[(int)$g['home_team_id']] = true;
            $everPlayed[(int)$g['away_team_id']] = true;
        }

        // Bye teams = seeded teams that have never played any playoff game yet
        $byeTeams = [];
        foreach ($seedLookup as $teamId => $team) {
            if (!isset($everPlayed[$teamId])) {
                $byeTeams[$teamId] = $team;
            }
        }

        // Combine winners + bye teams
        $advancing = array_merge(array_values($winners), array_values($byeTeams));

        return $advancing;
    }

    private function getRemainingTeams(int $leagueId, string $conf, array $confTeamIds, array $seedLookup): array
    {
        $season = $this->league->getCurrentSeason($leagueId);
        if (!$season) return [];

        // Get all completed playoff games involving these teams
        $placeholders = implode(',', array_fill(0, count($confTeamIds), '?'));
        $params = array_merge([$leagueId, (int)$season['id']], $confTeamIds, $confTeamIds);

        $playoffGames = $this->game->query(
            "SELECT * FROM games
             WHERE league_id = ? AND season_id = ? AND game_type != 'regular'
             AND is_simulated = 1
             AND (home_team_id IN ({$placeholders}) OR away_team_id IN ({$placeholders}))
             ORDER BY week ASC",
            $params
        );

        // Find teams that have been eliminated (lost a game)
        $eliminated = [];
        foreach ($playoffGames as $g) {
            $homeScore = (int)$g['home_score'];
            $awayScore = (int)$g['away_score'];
            $loserId = ($homeScore > $awayScore) ? (int)$g['away_team_id'] : (int)$g['home_team_id'];
            $eliminated[$loserId] = true;
        }

        // Remaining = all seeded teams minus eliminated
        $remaining = [];
        foreach ($seedLookup as $teamId => $team) {
            if (!isset($eliminated[$teamId])) {
                $remaining[] = $team;
            }
        }

        return $remaining;
    }

    /**
     * Generate the Super Bowl game.
     * One winner from each conference, higher seed is "home" (neutral site).
     */
    private function generateSuperBowl(int $leagueId, int $seasonId, int $week, array $seeding): array
    {
        $confChamps = [];

        foreach ($seeding['conferences'] as $conf => $seededTeams) {
            $seedLookup = [];
            foreach ($seededTeams as $t) {
                $seedLookup[(int)$t['id']] = $t;
            }
            $confTeamIds = array_map(fn($t) => (int)$t['id'], $seededTeams);

            // Use getRemainingTeams (eliminated-based) for the Super Bowl since all rounds are complete
            $remaining = $this->getRemainingTeams($leagueId, $conf, $confTeamIds, $seedLookup);

            if (count($remaining) === 1) {
                $confChamps[$conf] = $remaining[0];
            }
        }

        if (count($confChamps) !== 2) {
            return $this->getPlayoffBracket($leagueId);
        }

        $champs = array_values($confChamps);
        // Higher seed is "home" (arbitrary for neutral site)
        usort($champs, fn($a, $b) => $a['seed'] - $b['seed']);

        $games = [
            $this->makePlayoffGame(
                $leagueId, $seasonId, $week,
                'super_bowl', (int)$champs[0]['id'], (int)$champs[1]['id']
            )
        ];

        $this->insertGames($games);

        return $this->getPlayoffBracket($leagueId);
    }

    /**
     * Create a playoff game record array.
     */
    private function makePlayoffGame(int $leagueId, int $seasonId, int $week, string $type, int $homeId, int $awayId): array
    {
        return [
            'league_id' => $leagueId,
            'season_id' => $seasonId,
            'week' => $week,
            'game_type' => $type,
            'home_team_id' => $homeId,
            'away_team_id' => $awayId,
            'home_score' => null,
            'away_score' => null,
            'is_simulated' => 0,
            'weather' => $this->randomWeather(),
            'home_game_plan' => null,
            'away_game_plan' => null,
            'box_score' => null,
            'turning_point' => null,
            'player_grades' => null,
            'simulated_at' => null,
        ];
    }

    /**
     * Insert game records into the database.
     */
    private function insertGames(array $games): void
    {
        if (empty($games)) return;

        foreach ($games as $g) {
            $cols = implode(', ', array_keys($g));
            $placeholders = implode(', ', array_fill(0, count($g), '?'));
            $stmt = $this->db->prepare("INSERT INTO games ({$cols}) VALUES ({$placeholders})");
            $stmt->execute(array_values($g));
        }
    }

    /**
     * Human-readable round name.
     */
    private function roundDisplayName(string $gameType): string
    {
        return match ($gameType) {
            'wild_card' => 'Wild Card',
            'divisional' => 'Divisional Round',
            'conference_championship' => 'Conference Championship',
            'super_bowl' => 'Championship',
            default => ucfirst(str_replace('_', ' ', $gameType)),
        };
    }

    private function randomWeather(): string
    {
        $options = ['clear', 'clear', 'clear', 'clear', 'clear',
                    'cloudy', 'cloudy', 'rain', 'wind', 'snow', 'dome'];
        return $options[array_rand($options)];
    }
}
