<?php
/**
 * Backfill script — generates all missing articles for the current league.
 *
 * Run: php backfill_articles.php
 */

// CLI bootstrap — load autoloader and DB without web server assumptions
spl_autoload_register(function (string $class) {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) return;
    $relativeClass = substr($class, strlen($prefix));
    $file = __DIR__ . '/app/' . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) require_once $file;
});

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
set_exception_handler(function (Throwable $e) {
    fwrite(STDERR, "\nFATAL: {$e->getMessage()}\n  at {$e->getFile()}:{$e->getLine()}\n");
    exit(1);
});

use App\Database\Connection;

$db = Connection::getInstance()->getPdo();

// ── Get league info ──────────────────────────────────
$league = $db->query("SELECT * FROM leagues ORDER BY id DESC LIMIT 1")->fetch();
if (!$league) { die("No league found.\n"); }

$leagueId = (int) $league['id'];
$season = $db->prepare("SELECT * FROM seasons WHERE league_id = ? AND is_current = 1");
$season->execute([$leagueId]);
$season = $season->fetch();
$seasonId = $season ? (int) $season['id'] : 0;

echo "League: {$league['name']} (ID: $leagueId)\n";
echo "Season: {$league['season_year']} (ID: $seasonId)\n";
echo "Phase: {$league['phase']}, Week: {$league['current_week']}\n\n";

// ── Get existing article counts ─────────────────────
$existing = $db->prepare("SELECT type, week, COUNT(*) as cnt FROM articles WHERE league_id = ? GROUP BY type, week");
$existing->execute([$leagueId]);
$articleMap = [];
foreach ($existing->fetchAll() as $row) {
    $articleMap[$row['type'] . '_' . $row['week']] = (int) $row['cnt'];
}

function hasArticle(string $type, int $week): bool {
    global $articleMap;
    return ($articleMap[$type . '_' . $week] ?? 0) > 0;
}

// ── Initialize engines ──────────────────────────────
$narrative = new \App\Services\NarrativeEngine();
$totalGenerated = 0;

// ── 1. GAME RECAPS — for all simulated games missing recaps ─────
echo "=== GAME RECAPS ===\n";

$games = $db->prepare(
    "SELECT g.*, g.box_score as box_score_json
     FROM games g
     WHERE g.league_id = ? AND g.is_simulated = 1
     ORDER BY g.week ASC, g.id ASC"
);
$games->execute([$leagueId]);
$allGames = $games->fetchAll();

// Check which games already have recaps
$existingRecapGames = $db->prepare(
    "SELECT DISTINCT game_id FROM articles WHERE league_id = ? AND type = 'game_recap' AND game_id IS NOT NULL"
);
$existingRecapGames->execute([$leagueId]);
$recappedGameIds = array_column($existingRecapGames->fetchAll(), 'game_id');

$recapCount = 0;
foreach ($allGames as $game) {
    if (in_array($game['id'], $recappedGameIds)) continue;

    $boxScore = json_decode($game['box_score_json'] ?? '{}', true);
    if (empty($boxScore)) continue;

    $result = [
        'home_score' => (int) $game['home_score'],
        'away_score' => (int) $game['away_score'],
        'box_score' => $boxScore,
        'game_log' => $boxScore['game_log'] ?? [],
        'game_class' => $boxScore['game_class'] ?? [],
        'home_stats' => $boxScore['home']['stats'] ?? [],
        'away_stats' => $boxScore['away']['stats'] ?? [],
        'turning_point' => $boxScore['turning_point'] ?? '',
    ];

    try {
        $narrative->generateGameContent($game, $result, $seasonId);
        $recapCount++;
        if ($recapCount % 20 == 0) echo "  Generated $recapCount recaps...\n";
    } catch (\Throwable $e) {
        echo "  ERROR week {$game['week']} game {$game['id']}: {$e->getMessage()}\n";
    }
}
echo "  Generated $recapCount new game recaps\n\n";
$totalGenerated += $recapCount * 2; // 2 per game (winner + loser)

// ── 2. PLAYOFF RECAPS — for playoff games ───────────
echo "=== PLAYOFF RECAPS ===\n";
$playoffCount = 0;

$playoffTypes = ['wild_card', 'divisional', 'conference_championship', 'super_bowl', 'big_game'];
foreach ($allGames as $game) {
    if (!in_array($game['game_type'] ?? '', $playoffTypes)) continue;

    // Check if playoff recap already exists
    $check = $db->prepare("SELECT COUNT(*) FROM articles WHERE league_id = ? AND game_id = ? AND type = 'playoff_recap'");
    $check->execute([$leagueId, $game['id']]);
    if ((int) $check->fetchColumn() > 0) continue;

    $boxScore = json_decode($game['box_score_json'] ?? '{}', true);
    if (empty($boxScore)) continue;

    $result = [
        'home_score' => (int) $game['home_score'],
        'away_score' => (int) $game['away_score'],
        'box_score' => $boxScore,
        'game_log' => $boxScore['game_log'] ?? [],
        'game_class' => $boxScore['game_class'] ?? [],
        'home_stats' => $boxScore['home']['stats'] ?? [],
        'away_stats' => $boxScore['away']['stats'] ?? [],
        'turning_point' => $boxScore['turning_point'] ?? '',
    ];

    try {
        $narrative->generatePlayoffContent($leagueId, $seasonId, (int) $game['week'], $game, $result);
        $playoffCount++;
    } catch (\Throwable $e) {
        echo "  ERROR playoff game {$game['id']}: {$e->getMessage()}\n";
    }
}
echo "  Generated $playoffCount playoff recap sets\n\n";
$totalGenerated += $playoffCount;

// ── 3. WEEKLY COLUMNS ───────────────────────────────
echo "=== WEEKLY COLUMNS ===\n";
$columnCount = 0;
$maxWeek = (int) $league['current_week'];

for ($w = 1; $w <= min($maxWeek, 22); $w++) {
    if (hasArticle('column', $w)) continue;
    try {
        $narrative->generateWeeklyColumn($leagueId, $seasonId, $w);
        $columnCount++;
    } catch (\Throwable $e) {
        echo "  ERROR column week $w: {$e->getMessage()}\n";
    }
}
echo "  Generated $columnCount weekly columns\n\n";
$totalGenerated += $columnCount;

// ── 4. MORNING BLITZ ────────────────────────────────
echo "=== MORNING BLITZ ===\n";
$blitzCount = 0;

for ($w = 1; $w <= min($maxWeek, 22); $w++) {
    if (hasArticle('morning_blitz', $w)) continue;
    try {
        $narrative->generateMorningBlitz($leagueId, $seasonId, $w);
        $blitzCount++;
    } catch (\Throwable $e) {
        echo "  ERROR blitz week $w: {$e->getMessage()}\n";
    }
}
echo "  Generated $blitzCount morning blitz articles\n\n";
$totalGenerated += $blitzCount;

// ── 5. POWER RANKINGS ───────────────────────────────
echo "=== POWER RANKINGS ===\n";
$rankCount = 0;

for ($w = 1; $w <= min($maxWeek, 22); $w++) {
    if (hasArticle('power_rankings', $w)) continue;
    try {
        $narrative->generateWeeklyContent($leagueId, $seasonId, $w);
        $rankCount++;
    } catch (\Throwable $e) {
        echo "  ERROR rankings week $w: {$e->getMessage()}\n";
    }
}
echo "  Generated $rankCount power rankings\n\n";
$totalGenerated += $rankCount;

// ── 6. FEATURE STORIES ──────────────────────────────
echo "=== FEATURE STORIES ===\n";
$featureCount = 0;

$featureWeeks = [
    4 => 'rookie_watch',
    8 => 'rookie_watch',
    9 => 'midseason_report',
    12 => 'rookie_watch',
    14 => 'playoff_race',
    15 => 'playoff_race',
    16 => 'playoff_race',
    17 => 'playoff_race',
];

foreach ($featureWeeks as $w => $topic) {
    if ($w > $maxWeek) continue;
    if (hasArticle('feature', $w)) continue;
    try {
        $narrative->generateFeatureStory($leagueId, $seasonId, $w, $topic, []);
        $featureCount++;
    } catch (\Throwable $e) {
        echo "  ERROR feature week $w: {$e->getMessage()}\n";
    }
}
echo "  Generated $featureCount feature stories\n\n";
$totalGenerated += $featureCount;

// ── 7. TRADE STORIES ────────────────────────────────
echo "=== TRADE STORIES ===\n";
$tradeCount = 0;

$trades = $db->prepare(
    "SELECT t.* FROM trades t WHERE t.league_id = ? AND t.status = 'completed' ORDER BY t.proposed_at ASC"
);
$trades->execute([$leagueId]);

// Check trade_items schema
$tiSchema = $db->query("PRAGMA table_info(trade_items)")->fetchAll();
$tiCols = array_column($tiSchema, 'name');

foreach ($trades->fetchAll() as $trade) {
    $items = $db->prepare("SELECT ti.*, p.first_name, p.last_name, p.position, p.overall_rating
                           FROM trade_items ti
                           LEFT JOIN players p ON ti.player_id = p.id
                           WHERE ti.trade_id = ?");
    $items->execute([$trade['id']]);
    $tradeItems = $items->fetchAll();

    $playersSent = [];
    $playersReceived = [];
    $picksSent = [];
    $picksReceived = [];

    // Determine direction column
    $fromCol = in_array('from_team_id', $tiCols) ? 'from_team_id' : 'team_id';

    foreach ($tradeItems as $item) {
        $isFromProposer = ($item[$fromCol] ?? 0) == $trade['proposing_team_id'];
        if ($item['player_id']) {
            $pData = [
                'id' => $item['player_id'],
                'first_name' => $item['first_name'] ?? '',
                'last_name' => $item['last_name'] ?? '',
                'position' => $item['position'] ?? '',
                'overall_rating' => $item['overall_rating'] ?? 0,
            ];
            if ($isFromProposer) $playersSent[] = $pData;
            else $playersReceived[] = $pData;
        } else {
            $pickData = ['round' => $item['draft_round'] ?? 0, 'year' => $item['draft_year'] ?? 2026];
            if ($isFromProposer) $picksSent[] = $pickData;
            else $picksReceived[] = $pickData;
        }
    }

    if (empty($playersSent) && empty($playersReceived)) continue;

    $tradeData = [
        'team1_id' => (int) $trade['proposing_team_id'],
        'team2_id' => (int) $trade['receiving_team_id'],
        'players_sent' => $playersSent,
        'players_received' => $playersReceived,
        'picks_sent' => $picksSent,
        'picks_received' => $picksReceived,
    ];

    $tradeWeek = 10; // Default to mid-season

    try {
        $narrative->generateTradeStory($leagueId, $seasonId, $tradeWeek, $tradeData);
        $tradeCount++;
    } catch (\Throwable $e) {
        echo "  ERROR trade {$trade['id']}: {$e->getMessage()}\n";
    }
}
echo "  Generated $tradeCount trade stories\n\n";
$totalGenerated += $tradeCount;

// ── 8. DRAFT SCOUT COVERAGE ─────────────────────────
echo "=== DRAFT SCOUT COVERAGE ===\n";
$draftArticles = 0;

if (class_exists('App\\Services\\DraftScoutEngine')) {
    $scout = new \App\Services\DraftScoutEngine();

    // Get current draft class
    $dc = $db->prepare("SELECT id FROM draft_classes WHERE league_id = ? ORDER BY id DESC LIMIT 1");
    $dc->execute([$leagueId]);
    $draftClass = $dc->fetch();

    if ($draftClass) {
        $draftClassId = (int) $draftClass['id'];

        // Pre-draft coverage
        $check = $db->prepare("SELECT COUNT(*) FROM articles WHERE league_id = ? AND type = 'draft_coverage'");
        $check->execute([$leagueId]);
        $existingDraft = (int) $check->fetchColumn();

        if ($existingDraft < 3) {
            try {
                $scout->generatePreDraftCoverage($leagueId, $seasonId, $draftClassId);
                $draftArticles += 3;
                echo "  Generated pre-draft coverage (Big Board, Player to Watch, Team Needs)\n";
            } catch (\Throwable $e) {
                echo "  ERROR pre-draft: {$e->getMessage()}\n";
            }
        }

        // Weekly draft updates (simulate 4 weeks of offseason coverage)
        for ($w = 1; $w <= 4; $w++) {
            try {
                $scout->generateWeeklyDraftUpdate($leagueId, $seasonId, 22 + $w, $draftClassId);
                $draftArticles++;
            } catch (\Throwable $e) {
                echo "  ERROR draft update week $w: {$e->getMessage()}\n";
            }
        }
        echo "  Generated 4 weekly draft updates\n";

        // Draft day coverage (if draft happened)
        $draftPicks = $db->prepare(
            "SELECT dp.*, p.first_name, p.last_name, p.position, p.team_id, t.city, t.name as team_name
             FROM draft_picks dp
             JOIN players p ON dp.player_id = p.id
             JOIN teams t ON dp.current_team_id = t.id
             WHERE dp.league_id = ? AND dp.is_used = 1
             ORDER BY dp.round ASC, dp.pick_number ASC"
        );
        $draftPicks->execute([$leagueId]);
        $picks = $draftPicks->fetchAll();

        if (count($picks) > 0) {
            $pickData = array_map(fn($p) => [
                'round' => (int) $p['round'],
                'pick' => (int) $p['pick_number'],
                'team_id' => (int) ($p['team_id'] ?? $p['current_team_id']),
                'player_id' => (int) $p['player_id'],
                'player_name' => $p['first_name'] . ' ' . $p['last_name'],
                'position' => $p['position'],
                'team_name' => $p['city'] . ' ' . $p['team_name'],
            ], $picks);

            try {
                $scout->generateDraftDayNarrative($leagueId, $seasonId, $pickData);
                $draftArticles += 2;
                echo "  Generated draft day narratives (Drama + Grades)\n";
            } catch (\Throwable $e) {
                echo "  ERROR draft day: {$e->getMessage()}\n";
            }

            // Also generate NarrativeEngine draft coverage
            try {
                $narrative->generateDraftCoverage($leagueId, $seasonId, $pickData);
                $draftArticles += 3;
                echo "  Generated NarrativeEngine draft coverage\n";
            } catch (\Throwable $e) {
                echo "  ERROR NE draft: {$e->getMessage()}\n";
            }
        }
    }
}
echo "  Generated $draftArticles total draft articles\n\n";
$totalGenerated += $draftArticles;

// ── SUMMARY ─────────────────────────────────────────
$finalCount = $db->prepare("SELECT COUNT(*) FROM articles WHERE league_id = ?");
$finalCount->execute([$leagueId]);
$total = (int) $finalCount->fetchColumn();

$byType = $db->prepare("SELECT type, COUNT(*) as cnt FROM articles WHERE league_id = ? GROUP BY type ORDER BY cnt DESC");
$byType->execute([$leagueId]);

echo "═══════════════════════════════════════\n";
echo "BACKFILL COMPLETE\n";
echo "Generated ~$totalGenerated new articles\n";
echo "Total articles in database: $total\n\n";
echo "By type:\n";
foreach ($byType->fetchAll() as $row) {
    echo "  {$row['type']}: {$row['cnt']}\n";
}
echo "═══════════════════════════════════════\n";
