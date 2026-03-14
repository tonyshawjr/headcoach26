<?php

namespace App\Services;

use App\Database\Connection;

class NarrativeEngine
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
    }

    /**
     * Generate all narrative content for a simulated game.
     */
    public function generateGameContent(array $game, array $result, int $seasonId): void
    {
        $home = $this->getTeam($game['home_team_id']);
        $away = $this->getTeam($game['away_team_id']);

        $winner = $result['home_score'] > $result['away_score'] ? $home : $away;
        $loser = $result['home_score'] > $result['away_score'] ? $away : $home;
        $winScore = max($result['home_score'], $result['away_score']);
        $loseScore = min($result['home_score'], $result['away_score']);
        $margin = $winScore - $loseScore;

        $context = [
            'home' => $home,
            'away' => $away,
            'winner' => $winner,
            'loser' => $loser,
            'home_score' => $result['home_score'],
            'away_score' => $result['away_score'],
            'win_score' => $winScore,
            'lose_score' => $loseScore,
            'margin' => $margin,
            'week' => $game['week'],
            'weather' => $game['weather'] ?? 'clear',
            'turning_point' => $result['turning_point'],
            'is_divisional' => $home['conference'] === $away['conference'] && $home['division'] === $away['division'],
        ];

        // Find top performer
        $topPerformer = $this->findTopPerformer($result);
        $context['top_performer'] = $topPerformer;

        // Generate game recap article
        $this->generateGameRecap($game, $context, $seasonId);

        // Generate social posts
        $this->generateSocialPosts($game, $context, $seasonId);

        // Generate ticker items
        $this->generateTickerItems($game, $context);
    }

    /**
     * Generate weekly content after all games in a week are simulated.
     */
    public function generateWeeklyContent(int $leagueId, int $seasonId, int $week): void
    {
        $this->generatePowerRankings($leagueId, $seasonId, $week);
    }

    private function generateGameRecap(array $game, array $c, int $seasonId): void
    {
        $margin = $c['margin'];
        $w = $c['winner'];
        $l = $c['loser'];
        $wName = $w['city'] . ' ' . $w['name'];
        $lName = $l['city'] . ' ' . $l['name'];
        $topName = $c['top_performer'] ? $c['top_performer']['first_name'] . ' ' . $c['top_performer']['last_name'] : 'the offense';

        // Select template based on margin
        if ($margin >= 17) {
            $headlines = [
                "{$wName} Dominate {$lName} in {$c['win_score']}-{$c['lose_score']} Blowout",
                "{$wName} Roll Past {$lName}, Win {$c['win_score']}-{$c['lose_score']}",
                "Rout: {$wName} Cruise to Easy Victory Over {$lName}",
                "{$topName} Leads {$wName} to Convincing Win Over {$lName}",
            ];
            $bodyIntro = "It was never close. The {$wName} thoroughly outclassed the {$lName} in a {$c['win_score']}-{$c['lose_score']} victory that was decided well before the fourth quarter.";
        } elseif ($margin <= 3) {
            $headlines = [
                "{$wName} Survive Scare, Edge {$lName} {$c['win_score']}-{$c['lose_score']}",
                "Down to the Wire: {$wName} Hold Off {$lName} in Thriller",
                "{$topName} Delivers as {$wName} Escape with {$margin}-Point Win",
                "Nail-Biter: {$wName} Prevail {$c['win_score']}-{$c['lose_score']}",
            ];
            $bodyIntro = "It wasn't pretty, but the {$wName} will take the result. A {$margin}-point margin was all that separated these two teams in a Week {$c['week']} " . ($c['is_divisional'] ? 'divisional showdown' : 'matchup') . " that went down to the wire.";
        } else {
            $headlines = [
                "{$wName} Top {$lName} {$c['win_score']}-{$c['lose_score']}",
                "{$topName} Stars as {$wName} Beat {$lName}",
                "{$wName} Earn Solid Win Over {$lName}, {$c['win_score']}-{$c['lose_score']}",
                "Week {$c['week']}: {$wName} Handle Business Against {$lName}",
            ];
            $bodyIntro = "The {$wName} took care of business against the {$lName}, earning a {$c['win_score']}-{$c['lose_score']} victory in Week {$c['week']}.";
        }

        $headline = $headlines[array_rand($headlines)];

        $paragraphs = [$bodyIntro];

        if ($c['top_performer']) {
            $tp = $c['top_performer'];
            $statLine = $this->formatStatLine($tp);
            $paragraphs[] = "{$tp['first_name']} {$tp['last_name']} was the difference-maker, {$statLine}.";
        }

        $paragraphs[] = "The turning point came {$c['turning_point']}.";

        $winRecord = "{$w['wins']}-{$w['losses']}";
        $loseRecord = "{$l['wins']}-{$l['losses']}";
        $paragraphs[] = "The {$w['name']} improve to {$winRecord} on the season, while the {$l['name']} fall to {$loseRecord}.";

        if ($c['weather'] !== 'clear' && $c['weather'] !== 'dome') {
            $paragraphs[] = "The {$c['weather']} conditions played a factor, particularly affecting the passing game.";
        }

        $body = implode("\n\n", $paragraphs);

        // Choose author
        $authors = [
            ['name' => 'Terry Hollis', 'persona' => 'terry_hollis'],
            ['name' => 'Dana Reeves', 'persona' => 'dana_reeves'],
            ['name' => 'Marcus Bell', 'persona' => 'marcus_bell'],
        ];
        $author = $authors[array_rand($authors)];

        $stmt = $this->db->prepare(
            "INSERT INTO articles (league_id, season_id, week, type, headline, body, author_name, author_persona, team_id, game_id, is_ai_generated, published_at)
             VALUES (?, ?, ?, 'game_recap', ?, ?, ?, ?, ?, ?, 0, ?)"
        );
        $stmt->execute([
            $game['league_id'], $seasonId, $game['week'],
            $headline, $body,
            $author['name'], $author['persona'],
            $c['winner']['id'], $game['id'],
            date('Y-m-d H:i:s'),
        ]);
    }

    private function generateSocialPosts(array $game, array $c, int $seasonId): void
    {
        $now = date('Y-m-d H:i:s');
        $posts = [];

        // Winner's top player celebration
        if ($c['top_performer']) {
            $tp = $c['top_performer'];
            $handle = '@' . $tp['first_name'] . $tp['last_name'] . ($tp['jersey_number'] ?? '');
            $displayName = $tp['first_name'] . ' ' . $tp['last_name'];
            $teamEmoji = $c['winner']['logo_emoji'];

            $texts = [
                "That's what we do. {$teamEmoji} {$c['win_score']}-{$c['lose_score']}. On to next week.",
                "Great team win today. Couldn't do it without my guys. {$teamEmoji}",
                "God is good. Blessed to play this game. {$teamEmoji}",
                $this->formatStatLine($tp) . ". We keep working. {$teamEmoji}",
            ];

            $posts[] = [
                'league_id' => $game['league_id'], 'season_id' => $seasonId, 'week' => $game['week'],
                'handle' => $handle, 'display_name' => $displayName, 'avatar_type' => 'player',
                'team_id' => $c['winner']['id'], 'player_id' => $tp['id'],
                'body' => $texts[array_rand($texts)],
                'likes' => mt_rand(500, 15000), 'reposts' => mt_rand(50, 2000),
                'is_ai_generated' => 0, 'posted_at' => $now,
            ];
        }

        // Fan reaction
        $fanTexts = $c['margin'] >= 14
            ? [
                "Absolutely dominant performance by the {$c['winner']['name']}. This team is LEGIT.",
                "{$c['loser']['name']} fans in shambles right now. {$c['win_score']}-{$c['lose_score']} is embarrassing.",
            ]
            : [
                "What a game! {$c['winner']['name']} vs {$c['loser']['name']} was must-watch TV.",
                "I aged 10 years watching that {$c['winner']['name']}-{$c['loser']['name']} game. {$c['win_score']}-{$c['lose_score']}.",
            ];

        $posts[] = [
            'league_id' => $game['league_id'], 'season_id' => $seasonId, 'week' => $game['week'],
            'handle' => '@GridironFan' . mt_rand(100, 999), 'display_name' => 'Gridiron Fan',
            'avatar_type' => 'fan', 'team_id' => null, 'player_id' => null,
            'body' => $fanTexts[array_rand($fanTexts)],
            'likes' => mt_rand(100, 5000), 'reposts' => mt_rand(10, 500),
            'is_ai_generated' => 0, 'posted_at' => $now,
        ];

        // Analyst take
        $analystTexts = [
            "The {$c['winner']['name']} are quietly building something special. Week {$c['week']} showed real growth.",
            "Concerning signs for the {$c['loser']['name']}. That's {$c['loser']['losses']} losses now and the schedule doesn't get easier.",
        ];

        $posts[] = [
            'league_id' => $game['league_id'], 'season_id' => $seasonId, 'week' => $game['week'],
            'handle' => '@GridironInsider', 'display_name' => 'Gridiron Insider',
            'avatar_type' => 'analyst', 'team_id' => null, 'player_id' => null,
            'body' => $analystTexts[array_rand($analystTexts)],
            'likes' => mt_rand(1000, 8000), 'reposts' => mt_rand(100, 1000),
            'is_ai_generated' => 0, 'posted_at' => $now,
        ];

        foreach ($posts as $post) {
            $cols = implode(', ', array_keys($post));
            $placeholders = implode(', ', array_fill(0, count($post), '?'));
            $stmt = $this->db->prepare("INSERT INTO social_posts ({$cols}) VALUES ({$placeholders})");
            $stmt->execute(array_values($post));
        }
    }

    private function generateTickerItems(array $game, array $c): void
    {
        $w = $c['winner'];
        $l = $c['loser'];
        $items = [
            "{$w['abbreviation']} {$c['win_score']}, {$l['abbreviation']} {$c['lose_score']} — FINAL",
        ];

        if ($c['top_performer']) {
            $tp = $c['top_performer'];
            $items[] = "{$tp['last_name']} leads {$w['abbreviation']} to Week {$c['week']} victory";
        }

        foreach ($items as $text) {
            $stmt = $this->db->prepare(
                "INSERT INTO ticker_items (league_id, text, type, team_id, week, created_at) VALUES (?, ?, 'score', ?, ?, ?)"
            );
            $stmt->execute([$game['league_id'], $text, $w['id'], $game['week'], date('Y-m-d H:i:s')]);
        }
    }

    private function generatePowerRankings(int $leagueId, int $seasonId, int $week): void
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM teams WHERE league_id = ? ORDER BY wins DESC, points_for DESC"
        );
        $stmt->execute([$leagueId]);
        $teams = $stmt->fetchAll();

        $lines = [];
        foreach ($teams as $rank => $team) {
            $r = $rank + 1;
            $record = "{$team['wins']}-{$team['losses']}";
            $name = "{$team['city']} {$team['name']}";

            $blurbs = [
                "The {$team['name']} sit at {$record} and look" . ($team['wins'] > $team['losses'] ? ' like contenders.' : ' like they have work to do.'),
                "At {$record}, " . ($rank < 8 ? "the {$team['name']} are firmly in the playoff picture." : "the {$team['name']} need to turn things around quickly."),
            ];
            $lines[] = "**{$r}. {$name}** ({$record}) — " . $blurbs[array_rand($blurbs)];
        }

        $body = "# Week {$week} Power Rankings\n\n" . implode("\n\n", $lines);
        $headline = "Week {$week} Power Rankings: " . $teams[0]['city'] . ' ' . $teams[0]['name'] . ' Hold Top Spot';

        $stmt = $this->db->prepare(
            "INSERT INTO articles (league_id, season_id, week, type, headline, body, author_name, author_persona, team_id, is_ai_generated, published_at)
             VALUES (?, ?, ?, 'power_rankings', ?, ?, 'Dana Reeves', 'dana_reeves', NULL, 0, ?)"
        );
        $stmt->execute([$leagueId, $seasonId, $week, $headline, $body, date('Y-m-d H:i:s')]);
    }

    private function findTopPerformer(array $result): ?array
    {
        $allStats = array_merge($result['home_stats'] ?? [], $result['away_stats'] ?? []);
        $topId = null;
        $topScore = 0;

        foreach ($allStats as $playerId => $stat) {
            $s = ($stat['pass_yards'] ?? 0) + ($stat['rush_yards'] ?? 0) * 2
                + ($stat['rec_yards'] ?? 0) * 1.5
                + ($stat['pass_tds'] ?? 0) * 20 + ($stat['rush_tds'] ?? 0) * 20
                + ($stat['rec_tds'] ?? 0) * 20;
            if ($s > $topScore) {
                $topScore = $s;
                $topId = $playerId;
            }
        }

        if (!$topId) return null;

        $stmt = $this->db->prepare("SELECT * FROM players WHERE id = ?");
        $stmt->execute([$topId]);
        $player = $stmt->fetch();

        if ($player && isset($allStats[$topId])) {
            $player['game_stats'] = $allStats[$topId];
        }

        return $player ?: null;
    }

    private function formatStatLine(array $player): string
    {
        $stats = $player['game_stats'] ?? [];
        $pos = $player['position'];

        if ($pos === 'QB' && isset($stats['pass_yards'])) {
            return "completing {$stats['pass_completions']} of {$stats['pass_attempts']} for {$stats['pass_yards']} yards and " . ($stats['pass_tds'] ?? 0) . " touchdowns";
        }
        if ($pos === 'RB' && isset($stats['rush_yards'])) {
            return "rushing for {$stats['rush_yards']} yards on {$stats['rush_attempts']} carries";
        }
        if (in_array($pos, ['WR', 'TE']) && isset($stats['rec_yards'])) {
            return "catching {$stats['receptions']} passes for {$stats['rec_yards']} yards";
        }

        return "contributing key plays when the team needed them most";
    }

    private function getTeam(int $teamId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM teams WHERE id = ?");
        $stmt->execute([$teamId]);
        return $stmt->fetch();
    }
}
