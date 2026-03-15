<?php

namespace App\Services;

use App\Database\Connection;

class NarrativeEngine
{
    private \PDO $db;

    // Columnist profiles
    private const COLUMNISTS = [
        'terry_hollis' => [
            'name' => 'Terry Hollis',
            'style' => 'old_school',
            'game_types' => ['defensive_battle', 'blowout', 'solid_win'],
            'phrases' => [
                'lede' => ['This was football the way it was meant to be played.', 'You win in the trenches, and that was never more evident than today.', 'Some games are won on talent. This one was won on will.'],
                'praise_defense' => ['The defense set the tone from the opening snap.', 'Fundamentally sound from start to finish.', 'That is how you play championship-caliber defense.'],
                'praise_run' => ['They imposed their will on the ground.', 'Old-fashioned, smash-mouth football.', 'You could see the fight leave the defense in the fourth quarter.'],
                'criticism' => ['There is no excuse for that kind of performance.', 'Discipline was nonexistent.', 'They were outcoached, outworked, and outplayed.'],
            ],
        ],
        'dana_reeves' => [
            'name' => 'Dana Reeves',
            'style' => 'analytical',
            'game_types' => ['shootout', 'solid_win', 'back_and_forth', 'defensive_battle', 'blowout', 'comeback', 'thriller'],
            'phrases' => [
                'lede' => ['The numbers tell the story.', 'From an efficiency standpoint, this was a clinic.', 'The metrics painted a clear picture before halftime.'],
                'analysis' => ['The expected points added differential was stark.', 'When you look at the drive efficiency numbers, this result was inevitable.', 'A completion percentage that high against man coverage is remarkable.'],
                'stat_intro' => ['Consider the numbers:', 'The stat sheet speaks volumes:', 'Dig into the efficiency metrics and the picture becomes clear:'],
            ],
        ],
        'marcus_bell' => [
            'name' => 'Marcus Bell',
            'style' => 'narrative',
            'game_types' => ['comeback', 'thriller', 'back_and_forth', 'shootout'],
            'phrases' => [
                'lede' => ['You could feel it building.', 'Sometimes a game becomes something more.', 'This was the kind of game you tell your grandchildren about.'],
                'emotion' => ['The sideline erupted.', 'You could see the belief drain from their eyes.', 'Every fan in the stadium knew what was coming.'],
                'hero' => ['This was his moment, and he seized it.', 'Heroes are made in moments like these.', 'He carried an entire franchise on his shoulders today.'],
                'quote_intro' => ['After the game,', 'In the locker room,', 'Standing at the podium,'],
            ],
        ],
    ];

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

        $homeScore = (int)($result['home_score'] ?? 0);
        $awayScore = (int)($result['away_score'] ?? 0);
        $winner = $homeScore > $awayScore ? $home : $away;
        $loser = $homeScore > $awayScore ? $away : $home;
        $winScore = max($homeScore, $awayScore);
        $loseScore = min($homeScore, $awayScore);
        $margin = $winScore - $loseScore;

        // Extract game log and classification from box_score or result
        $boxScore = $result['box_score'] ?? [];
        $gameLog = $result['game_log'] ?? $boxScore['game_log'] ?? [];
        $gameClass = $result['game_class'] ?? $boxScore['game_class'] ?? $this->classifyFromScores($homeScore, $awayScore, $gameLog);

        $homeStats = $result['home_stats'] ?? $boxScore['home']['stats'] ?? [];
        $awayStats = $result['away_stats'] ?? $boxScore['away']['stats'] ?? [];

        $homeUnits = $boxScore['home']['units'] ?? [];
        $awayUnits = $boxScore['away']['units'] ?? [];

        $context = [
            'home' => $home,
            'away' => $away,
            'winner' => $winner,
            'loser' => $loser,
            'home_score' => $homeScore,
            'away_score' => $awayScore,
            'win_score' => $winScore,
            'lose_score' => $loseScore,
            'margin' => $margin,
            'week' => $game['week'],
            'weather' => $game['weather'] ?? 'clear',
            'turning_point' => $result['turning_point'] ?? '',
            'is_divisional' => ($home['conference'] ?? '') === ($away['conference'] ?? '') && ($home['division'] ?? '') === ($away['division'] ?? ''),
            'game_log' => $gameLog,
            'game_class' => $gameClass,
            'home_stats' => $homeStats,
            'away_stats' => $awayStats,
            'home_units' => $homeUnits,
            'away_units' => $awayUnits,
            'winner_is_home' => $homeScore > $awayScore,
        ];

        // Find top performers for each team
        $winnerStats = $context['winner_is_home'] ? $homeStats : $awayStats;
        $loserStats = $context['winner_is_home'] ? $awayStats : $homeStats;
        $context['winner_star'] = $this->findTopPerformer($winnerStats);
        $context['loser_star'] = $this->findTopPerformer($loserStats);

        // Extract in-game injuries from the game log for narrative use
        $context['injuries'] = $this->extractInjuriesFromLog($gameLog);

        // Select columnist based on game type
        $columnist = $this->selectColumnist($gameClass['type'] ?? 'solid_win');

        // Generate two recap articles: winner perspective + loser perspective
        $this->generateTeamRecap($game, $context, $seasonId, 'winner', $columnist);
        $this->generateTeamRecap($game, $context, $seasonId, 'loser', $columnist);

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

    // ─── Game Classification ───────────────────────────────────────────

    /**
     * Fallback classification when game_class not provided by SimEngine.
     */
    private function classifyFromScores(int $homeScore, int $awayScore, array $gameLog): array
    {
        $margin = abs($homeScore - $awayScore);
        $combined = $homeScore + $awayScore;
        $loserScore = min($homeScore, $awayScore);

        $leadChanges = 0;
        $prevLeader = null;
        foreach ($gameLog as $entry) {
            $hs = $entry['home_score'] ?? 0;
            $as = $entry['away_score'] ?? 0;
            if ($hs === $as) continue;
            $leader = $hs > $as ? 'home' : 'away';
            if ($prevLeader !== null && $leader !== $prevLeader) {
                $leadChanges++;
            }
            $prevLeader = $leader;
        }

        $isComeback = false;
        $winnerSide = $homeScore >= $awayScore ? 'home' : 'away';
        foreach ($gameLog as $entry) {
            if (($entry['quarter'] ?? 0) >= 4) {
                $ws = $winnerSide === 'home' ? ($entry['home_score'] ?? 0) : ($entry['away_score'] ?? 0);
                $ls = $winnerSide === 'home' ? ($entry['away_score'] ?? 0) : ($entry['home_score'] ?? 0);
                if ($ls > $ws) {
                    $isComeback = true;
                    break;
                }
            }
        }

        $type = 'solid_win';
        if ($margin >= 21) $type = 'blowout';
        elseif ($isComeback) $type = 'comeback';
        elseif ($margin <= 3) $type = 'thriller';
        elseif ($combined >= 55) $type = 'shootout';
        elseif ($homeScore <= 17 && $awayScore <= 17 && $loserScore <= 10) $type = 'defensive_battle';
        elseif ($margin <= 7 && $leadChanges >= 3) $type = 'back_and_forth';

        $tags = [];
        if ($leadChanges >= 3) $tags[] = 'lead_changes';
        if ($loserScore <= 10) $tags[] = 'defensive_score';

        return [
            'type' => $type,
            'tags' => $tags,
            'lead_changes' => $leadChanges,
            'margin' => $margin,
            'combined_score' => $combined,
        ];
    }

    // ─── Columnist Selection ───────────────────────────────────────────

    private function selectColumnist(string $gameType): array
    {
        $candidates = [];
        foreach (self::COLUMNISTS as $key => $col) {
            if (in_array($gameType, $col['game_types'])) {
                $candidates[$key] = $col;
            }
        }

        if (empty($candidates)) {
            $candidates = self::COLUMNISTS;
        }

        $keys = array_keys($candidates);
        $selectedKey = $keys[array_rand($keys)];
        return self::COLUMNISTS[$selectedKey];
    }

    // ─── Team Recap Article (4-6 paragraphs) ───────────────────────────

    private function generateTeamRecap(array $game, array $c, int $seasonId, string $perspective, array $columnist): void
    {
        $isWinner = $perspective === 'winner';
        $team = $isWinner ? $c['winner'] : $c['loser'];
        $opponent = $isWinner ? $c['loser'] : $c['winner'];
        $star = $isWinner ? $c['winner_star'] : $c['loser_star'];
        $gameType = $c['game_class']['type'] ?? 'solid_win';

        $teamName = $team['city'] . ' ' . $team['name'];
        $oppName = $opponent['city'] . ' ' . $opponent['name'];

        // Paragraph 1: Lede
        $lede = $this->generateLede($c, $isWinner, $teamName, $oppName, $gameType, $columnist);

        // Paragraph 2: Star Performance
        $starParagraph = $this->generateStarParagraph($star, $teamName, $isWinner, $columnist);

        // Paragraph 3: Key Sequence from game log
        $keySequence = $this->generateKeySequenceParagraph($c, $isWinner, $teamName, $oppName);

        // Paragraph 4: Matchup Analysis
        $matchup = $this->generateMatchupParagraph($c, $isWinner, $teamName, $oppName);

        // Paragraph 5: Injury drama (if a significant injury occurred)
        $injuryParagraph = $this->generateInjuryParagraph($c, $team, $opponent, $isWinner, $columnist);

        // Paragraph 6: Context (record, implications)
        $contextParagraph = $this->generateContextParagraph($c, $team, $opponent, $isWinner);

        // Paragraph 7: Quote (optional, based on columnist)
        $quote = null;
        if ($columnist['style'] === 'narrative' || mt_rand(1, 100) <= 40) {
            $quote = $this->generateQuoteParagraph($star, $team, $isWinner, $gameType, $columnist);
        }

        // Assemble paragraphs
        $paragraphs = array_filter([$lede, $starParagraph, $keySequence, $matchup, $injuryParagraph, $contextParagraph, $quote]);
        $body = implode("\n\n", $paragraphs);

        // Generate headline
        $headline = $this->generateHeadline($c, $isWinner, $teamName, $oppName, $star, $gameType);

        $stmt = $this->db->prepare(
            "INSERT INTO articles (league_id, season_id, week, type, headline, body, author_name, author_persona, team_id, game_id, is_ai_generated, published_at)
             VALUES (?, ?, ?, 'game_recap', ?, ?, ?, ?, ?, ?, 0, ?)"
        );
        $stmt->execute([
            $game['league_id'], $seasonId, $game['week'],
            $headline, $body,
            $columnist['name'], array_search($columnist, self::COLUMNISTS) ?: $columnist['name'],
            $team['id'], $game['id'],
            date('Y-m-d H:i:s'),
        ]);
    }

    // ─── Paragraph Generators ──────────────────────────────────────────

    private function generateLede(array $c, bool $isWinner, string $teamName, string $oppName, string $gameType, array $columnist): string
    {
        $winScore = $c['win_score'];
        $loseScore = $c['lose_score'];
        $margin = $c['margin'];
        $week = $c['week'];
        $divisional = $c['is_divisional'] ? 'divisional ' : '';

        if ($isWinner) {
            return match ($gameType) {
                'blowout' => $this->pickOne([
                    "It was never close. The {$teamName} dismantled the {$oppName} in a {$winScore}-{$loseScore} rout that was effectively over by halftime. From the opening drive, it was clear which team came to play.",
                    "The {$teamName} left no doubt in a commanding {$winScore}-{$loseScore} victory over the {$oppName} in Week {$week}. This was a thorough, systematic demolition.",
                    "Dominant doesn't begin to describe it. The {$teamName} put on a clinic in their {$winScore}-{$loseScore} destruction of the {$oppName}, a performance that should put the rest of the league on notice.",
                ]),
                'comeback' => $this->pickOne([
                    "Left for dead in the fourth quarter, the {$teamName} authored one of the most dramatic comebacks of the season, rallying to defeat the {$oppName} {$winScore}-{$loseScore}.",
                    "They were down, but never out. The {$teamName} stormed back from a fourth-quarter deficit to stun the {$oppName} {$winScore}-{$loseScore} in a Week {$week} {$divisional}showdown that will be remembered for a long time.",
                    "Write the obituary, tear it up, and start over. The {$teamName} erased a late deficit and escaped with a {$winScore}-{$loseScore} comeback victory over the {$oppName}.",
                ]),
                'thriller' => $this->pickOne([
                    "It came down to the final possession, and the {$teamName} made the plays that mattered most to edge the {$oppName} {$winScore}-{$loseScore} in a Week {$week} thriller.",
                    "In a game that could have gone either way, the {$teamName} found just enough to hold off the {$oppName} {$winScore}-{$loseScore}. A {$margin}-point margin was all that separated two teams that gave everything they had.",
                    "Nail-biter. Heart-stopper. Pick your cliche. The {$teamName} survived a {$winScore}-{$loseScore} {$divisional}battle with the {$oppName} that wasn't decided until the final moments.",
                ]),
                'shootout' => $this->pickOne([
                    "Defense was optional in a wild {$winScore}-{$loseScore} {$divisional}affair that the {$teamName} ultimately claimed over the {$oppName}. Points came in bunches, and neither team could slow the other down.",
                    "The {$teamName} outgunned the {$oppName} in a {$winScore}-{$loseScore} offensive showcase that had everything: big plays, momentum swings, and a finish that left fans breathless.",
                ]),
                'defensive_battle' => $this->pickOne([
                    "It wasn't pretty, but it was effective. The {$teamName} ground out a {$winScore}-{$loseScore} victory over the {$oppName} in a Week {$week} defensive slugfest.",
                    "In a game defined by defense, the {$teamName} made just enough plays on that side of the ball to escape with a {$winScore}-{$loseScore} win over the {$oppName}.",
                ]),
                'back_and_forth' => $this->pickOne([
                    "The lead changed hands multiple times, but the {$teamName} had the final say in a {$winScore}-{$loseScore} victory over the {$oppName}.",
                    "Neither team could pull away in a back-and-forth {$divisional}battle, but the {$teamName} made the last run count, defeating the {$oppName} {$winScore}-{$loseScore}.",
                ]),
                default => $this->pickOne([
                    "The {$teamName} took care of business against the {$oppName}, earning a {$winScore}-{$loseScore} victory in Week {$week}.",
                    "A workmanlike {$winScore}-{$loseScore} win over the {$oppName} is exactly what the {$teamName} needed in Week {$week}.",
                    "The {$teamName} handled the {$oppName} with a professional {$winScore}-{$loseScore} victory, controlling the game from start to finish.",
                ]),
            };
        } else {
            // Loser perspective
            return match ($gameType) {
                'blowout' => $this->pickOne([
                    "There is no sugar-coating it. The {$teamName} were thoroughly outplayed in a {$winScore}-{$loseScore} loss to the {$oppName} that exposed significant issues on both sides of the ball.",
                    "It was an afternoon to forget for the {$teamName}, who were handed a {$winScore}-{$loseScore} defeat by the {$oppName}. Nothing went right from the opening whistle.",
                    "The {$teamName} had no answers in a {$winScore}-{$loseScore} loss to the {$oppName}. The film from this one will be difficult to watch.",
                ]),
                'comeback' => $this->pickOne([
                    "The {$teamName} let one slip away. Leading in the fourth quarter, they watched the {$oppName} mount a furious rally in what became a gut-wrenching {$winScore}-{$loseScore} defeat.",
                    "A game they should have won turned into a collapse for the books. The {$teamName} couldn't hold their fourth-quarter lead and fell to the {$oppName} {$winScore}-{$loseScore}.",
                    "How do you lose a game you were winning with minutes to go? Ask the {$teamName}, who surrendered a lead and fell {$winScore}-{$loseScore} to the {$oppName}.",
                ]),
                'thriller' => $this->pickOne([
                    "The {$teamName} came up just short in a {$winScore}-{$loseScore} loss to the {$oppName}, a {$margin}-point margin that will sting for days.",
                    "So close, yet so far. The {$teamName} gave everything they had but fell {$winScore}-{$loseScore} to the {$oppName} in a game decided by the smallest of margins.",
                ]),
                'shootout' => $this->pickOne([
                    "The {$teamName} put up a fight, but ultimately came out on the wrong end of a {$winScore}-{$loseScore} shootout with the {$oppName}.",
                    "Despite a prolific offensive performance, the {$teamName} couldn't keep pace with the {$oppName} in a {$winScore}-{$loseScore} defeat.",
                ]),
                'defensive_battle' => $this->pickOne([
                    "The {$teamName}'s defense kept them in it, but the offense couldn't generate enough to overcome the {$oppName} in a {$winScore}-{$loseScore} loss.",
                    "The {$teamName} couldn't find the end zone often enough in a {$winScore}-{$loseScore} defensive grind against the {$oppName}.",
                ]),
                default => $this->pickOne([
                    "The {$teamName} fell to the {$oppName} {$winScore}-{$loseScore} in Week {$week}, a loss that raises questions about the direction of this team.",
                    "It was a step backward for the {$teamName}, who dropped a {$winScore}-{$loseScore} decision to the {$oppName}.",
                    "The {$teamName} came out flat in a {$winScore}-{$loseScore} loss to the {$oppName}, never quite finding their rhythm.",
                ]),
            };
        }
    }

    private function generateStarParagraph(?array $star, string $teamName, bool $isWinner, array $columnist): string
    {
        if (!$star) {
            return $isWinner
                ? "The {$teamName}'s victory was a true team effort, with contributions up and down the roster."
                : "No one player could lift the {$teamName} out of this one, as the team struggled to find a rhythm.";
        }

        $name = $star['first_name'] . ' ' . $star['last_name'];
        $stats = $star['game_stats'] ?? [];
        $pos = $star['position'];
        $statLine = $this->formatDetailedStatLine($star);

        $positionVerb = match ($pos) {
            'QB' => $this->pickOne(['was surgical from the pocket', 'orchestrated the offense with precision', 'put on a passing clinic', 'was in complete command of the offense']),
            'RB' => $this->pickOne(['was a force between the tackles', 'ran with authority all day', 'punished the defense on the ground', 'couldn\'t be stopped on the ground']),
            'WR' => $this->pickOne(['made himself uncoverable', 'torched the secondary', 'was the go-to target all afternoon', 'ran routes that left defenders grasping at air']),
            'TE' => $this->pickOne(['was a mismatch nightmare', 'created problems all over the middle of the field', 'was too big and too fast for the defense to handle']),
            'DE', 'DT' => $this->pickOne(['wreaked havoc in the backfield', 'was unblockable off the edge', 'dominated the line of scrimmage', 'terrorized the quarterback all day']),
            'LB' => $this->pickOne(['was everywhere', 'flew sideline to sideline', 'set the tone on defense', 'made play after play']),
            'CB', 'S' => $this->pickOne(['locked down his assignment', 'was a ball hawk', 'made the opposition pay for throwing his way', 'shut down everything in his zone']),
            default => 'delivered a standout performance',
        };

        if ($isWinner) {
            if ($columnist['style'] === 'analytical') {
                return "{$name} {$positionVerb}, {$statLine}. Those are elite numbers by any measure, and they were the engine that drove the {$teamName}'s offense.";
            } elseif ($columnist['style'] === 'narrative') {
                return "{$name} {$positionVerb}. {$statLine}. When the moment called for a star, {$name} answered, carrying the {$teamName} on a performance that won't soon be forgotten.";
            } else {
                return "{$name} {$positionVerb}, {$statLine}. That's how you earn a gameday check. The {$teamName} go as {$name} goes, and today he was at his best.";
            }
        } else {
            return "Despite the loss, {$name} {$positionVerb}, {$statLine}. But individual brilliance wasn't enough to overcome the {$teamName}'s collective struggles.";
        }
    }

    private function generateKeySequenceParagraph(array $c, bool $isWinner, string $teamName, string $oppName): string
    {
        $gameLog = $c['game_log'] ?? [];

        if (empty($gameLog)) {
            return "The turning point came {$c['turning_point']}.";
        }

        // Find the best key moment: scoring plays in critical situations
        $bestEntry = null;
        $bestWeight = 0;
        foreach ($gameLog as $entry) {
            $w = 0;
            $note = $entry['note'] ?? '';
            if (str_contains($note, 'touchdown')) $w += 20;
            if (str_contains($note, 'Interception')) $w += 18;
            if (str_contains($note, 'Fumble')) $w += 15;
            if (str_contains($note, 'Field goal good')) $w += 10;
            if (str_contains($note, '4th down')) $w += 12;
            if (($entry['quarter'] ?? 0) >= 3) $w += 8;
            if (($entry['quarter'] ?? 0) >= 4) $w += 12;
            if (abs($entry['play']['yards'] ?? 0) >= 25) $w += 6;
            // Prefer plays where score was close
            $scoreDiff = abs(($entry['home_score'] ?? 0) - ($entry['away_score'] ?? 0));
            if ($scoreDiff <= 7) $w += 5;

            if ($w > $bestWeight) {
                $bestWeight = $w;
                $bestEntry = $entry;
            }
        }

        if (!$bestEntry) {
            return "The turning point came {$c['turning_point']}.";
        }

        $quarter = match ($bestEntry['quarter'] ?? 1) {
            1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth', 5 => 'overtime', default => 'fourth',
        };

        $clock = $bestEntry['clock'] ?? 0;
        $timeStr = sprintf("%d:%02d", intdiv(max(0, $clock), 60), max(0, $clock) % 60);
        $note = $bestEntry['note'] ?? 'a key play';
        $playerName = $bestEntry['play']['player'] ?? 'the offense';
        $yards = abs($bestEntry['play']['yards'] ?? 0);
        $playType = $bestEntry['play']['type'] ?? '';
        $hs = $bestEntry['home_score'] ?? 0;
        $as = $bestEntry['away_score'] ?? 0;

        $possession = $bestEntry['possession'] ?? 'home';
        $possTeam = $possession === 'home' ? ($c['home']['city'] . ' ' . $c['home']['name']) : ($c['away']['city'] . ' ' . $c['away']['name']);

        if (str_contains($note, 'touchdown')) {
            $targetName = $bestEntry['play']['target'] ?? null;
            $depth = $bestEntry['play']['depth'] ?? '';
            if ($playType === 'completion' && $targetName) {
                return "The key sequence came with {$timeStr} remaining in the {$quarter} quarter when {$playerName} connected with {$targetName} on a {$yards}-yard {$depth} strike for the score, making it {$hs}-{$as}. That play shifted the momentum decisively.";
            } else {
                return "The decisive moment came with {$timeStr} left in the {$quarter} quarter when {$playerName} punched it in from {$yards} yards out, making it {$hs}-{$as}. It was the kind of play that breaks a defense's spirit.";
            }
        } elseif (str_contains($note, 'Interception')) {
            $defenderName = $bestEntry['play']['defender'] ?? 'the defense';
            return "The game turned with {$timeStr} remaining in the {$quarter} when {$defenderName} jumped a route and came away with an interception. With the score at {$hs}-{$as}, that turnover took the life out of {$possTeam}'s offense.";
        } elseif (str_contains($note, 'Fumble')) {
            return "A fumble with {$timeStr} left in the {$quarter} quarter proved costly. With the score {$hs}-{$as}, {$possTeam} coughed up the football and any chance at momentum with it.";
        } elseif (str_contains($note, 'Field goal good')) {
            $fgDist = $bestEntry['play']['distance'] ?? 0;
            return "A {$fgDist}-yard field goal with {$timeStr} remaining in the {$quarter} made it {$hs}-{$as} and proved to be a pivotal moment in the game.";
        } elseif (str_contains($note, '4th down')) {
            return "With {$timeStr} left in the {$quarter} and the score at {$hs}-{$as}, {$possTeam} gambled on fourth down. That decision shaped the remainder of the contest.";
        }

        return "The turning point came {$c['turning_point']}.";
    }

    private function generateMatchupParagraph(array $c, bool $isWinner, string $teamName, string $oppName): string
    {
        $homeUnits = $c['home_units'] ?? [];
        $awayUnits = $c['away_units'] ?? [];

        if (empty($homeUnits) || empty($awayUnits)) {
            return $isWinner
                ? "The {$teamName} won the battle at the point of attack and that made all the difference."
                : "The {$teamName} were simply outmatched in the key areas of the game.";
        }

        // Find the biggest unit differential
        $winnerUnits = $c['winner_is_home'] ? $homeUnits : $awayUnits;
        $loserUnits = $c['winner_is_home'] ? $awayUnits : $homeUnits;

        $biggestDiff = 0;
        $biggestUnit = '';
        $unitComparisons = [
            'pass_offense' => ['winner' => $winnerUnits['pass_offense'] ?? 50, 'loser' => $loserUnits['pass_defense'] ?? 50, 'desc_win' => 'pass offense', 'desc_lose' => 'pass defense'],
            'run_offense' => ['winner' => $winnerUnits['run_offense'] ?? 50, 'loser' => $loserUnits['run_defense'] ?? 50, 'desc_win' => 'ground game', 'desc_lose' => 'run defense'],
            'pass_rush' => ['winner' => $winnerUnits['pass_rush'] ?? 50, 'loser' => $loserUnits['pass_block'] ?? 50, 'desc_win' => 'pass rush', 'desc_lose' => 'pass protection'],
            'pass_defense' => ['winner' => $winnerUnits['pass_defense'] ?? 50, 'loser' => $loserUnits['pass_offense'] ?? 50, 'desc_win' => 'secondary', 'desc_lose' => 'passing attack'],
        ];

        foreach ($unitComparisons as $key => $comp) {
            $diff = abs($comp['winner'] - $comp['loser']);
            if ($diff > $biggestDiff) {
                $biggestDiff = $diff;
                $biggestUnit = $key;
            }
        }

        $comp = $unitComparisons[$biggestUnit] ?? $unitComparisons['pass_offense'];
        $wName = $isWinner ? $teamName : $oppName;
        $lName = $isWinner ? $oppName : $teamName;

        if ($isWinner) {
            return match ($biggestUnit) {
                'pass_offense' => $this->pickOne([
                    "The {$wName}'s passing attack carved up the {$lName}'s secondary all day. The {$lName} had no answer for the deep ball, and it showed on the scoreboard.",
                    "This game was decided through the air. The {$wName}'s receivers consistently found soft spots in the coverage, and the quarterback made them pay for it.",
                ]),
                'run_offense' => $this->pickOne([
                    "The {$wName} controlled the line of scrimmage and imposed their will on the ground. The {$lName}'s front seven couldn't hold up at the point of attack.",
                    "It was a ground-and-pound afternoon for the {$wName}, whose offensive line opened holes all game long. The {$lName} simply couldn't stop the run.",
                ]),
                'pass_rush' => $this->pickOne([
                    "The {$wName}'s pass rush was relentless, collapsing the pocket and never letting the quarterback get comfortable. The {$lName}'s offensive line was overmatched from start to finish.",
                    "Pressure. That was the story. The {$wName}'s defensive line lived in the backfield, disrupting everything the {$lName} tried to do through the air.",
                ]),
                'pass_defense' => $this->pickOne([
                    "The {$wName}'s secondary locked down the {$lName}'s receiving corps. Tight coverage and disciplined play made it nearly impossible for the {$lName} to move the ball through the air.",
                    "Coverage was suffocating. The {$wName}'s defensive backs were in phase on nearly every route, taking away the deep ball and forcing checkdowns.",
                ]),
                default => "The {$wName} won the critical matchup battles across the board, and that advantage translated directly to the scoreboard.",
            };
        } else {
            return match ($biggestUnit) {
                'pass_offense' => "The {$teamName}'s pass defense had no answer for what the {$oppName} brought. The secondary was consistently beaten, and the lack of pressure allowed the quarterback too much time.",
                'run_offense' => "The {$teamName}'s run defense was gashed all afternoon. The inability to set the edge and fill gaps allowed the {$oppName} to control the clock and the game.",
                'pass_rush' => "The {$teamName}'s offensive line was overwhelmed. Constant pressure disrupted the timing of the passing game and kept the offense from establishing any rhythm.",
                'pass_defense' => "The {$teamName}'s passing attack simply couldn't get going against the {$oppName}'s coverage. Receivers were blanketed, and the few windows that opened were slammed shut.",
                default => "The {$teamName} were outmatched in the key matchups, and the deficit in the trenches was too much to overcome.",
            };
        }
    }

    private function generateContextParagraph(array $c, array $team, array $opponent, bool $isWinner): string
    {
        $winRecord = $c['winner']['wins'] . '-' . $c['winner']['losses'];
        $loseRecord = $c['loser']['wins'] . '-' . $c['loser']['losses'];
        $week = $c['week'];

        $teamRecord = $isWinner ? $winRecord : $loseRecord;
        $teamObj = $isWinner ? $c['winner'] : $c['loser'];
        $oppObj = $isWinner ? $c['loser'] : $c['winner'];

        $teamNameShort = $team['name'];
        $oppNameShort = $opponent['name'];

        $base = "With the result, the {$teamNameShort} move to {$teamRecord} on the season.";

        // Playoff implications
        $implications = '';
        if ($week >= 10) {
            $wins = (int)$team['wins'];
            $losses = (int)$team['losses'];

            if ($isWinner) {
                if ($wins >= 8) {
                    $implications = " They're firmly in the playoff picture and looking like a team that could make some noise in January.";
                } elseif ($wins >= 6) {
                    $implications = " They remain in the hunt for a postseason berth.";
                }
            } else {
                if ($losses >= 8) {
                    $implications = " Their playoff hopes are all but extinguished.";
                } elseif ($losses >= 6) {
                    $implications = " Time is running out for a team that can't afford many more losses.";
                }
            }
        }

        // Streak
        $streak = $team['streak'] ?? '';
        $streakNote = '';
        if (preg_match('/^W(\d+)$/', $streak, $m) && (int)$m[1] >= 3) {
            $streakNote = " That's {$m[1]} straight wins.";
        } elseif (preg_match('/^L(\d+)$/', $streak, $m) && (int)$m[1] >= 3) {
            $streakNote = " That's now {$m[1]} consecutive losses.";
        }

        // Weather note
        $weatherNote = '';
        $weather = $c['weather'] ?? 'clear';
        if ($weather !== 'clear' && $weather !== 'dome') {
            $weatherNote = match ($weather) {
                'rain' => " The rainy conditions hampered both passing games throughout.",
                'snow' => " Snow made conditions treacherous, favoring the ground game.",
                'wind' => " Gusty winds affected the deep passing game and special teams.",
                default => '',
            };
        }

        // Divisional note
        $divNote = '';
        if ($c['is_divisional']) {
            $divNote = $isWinner
                ? " The division win could loom large come tiebreaker season."
                : " A division loss only makes the climb that much steeper.";
        }

        return $base . $implications . $streakNote . $divNote . $weatherNote;
    }

    private function generateQuoteParagraph(?array $star, array $team, bool $isWinner, string $gameType, array $columnist): ?string
    {
        $teamName = $team['name'];

        if ($star) {
            $name = $star['first_name'] . ' ' . $star['last_name'];
        } else {
            $name = 'Head Coach';
        }

        if ($isWinner) {
            $quotes = match ($gameType) {
                'blowout' => [
                    "\"{$teamName} came out with the right mentality today. We executed the game plan and played our brand of football,\" {$name} said after the game.",
                    "\"We prepared all week for this and it showed. Everyone did their job,\" {$name} said.",
                    "\"That's {$teamName} football right there. We came out and imposed our will,\" {$name} said with a grin in the postgame press conference.",
                ],
                'comeback' => [
                    "\"We never quit. That's the DNA of this team. We've been in those situations before and we trust each other,\" {$name} said, still catching his breath.",
                    "\"I looked around the huddle and I saw belief. Nobody panicked. That's what championship teams do,\" {$name} said.",
                    "\"Down but never out. That's the motto around here,\" {$name} said in the locker room.",
                ],
                'thriller' => [
                    "\"That's why you play the game. Those are the moments you live for,\" {$name} said.",
                    "\"My heart was beating out of my chest on that last drive. But we made the plays when we needed them,\" {$name} admitted.",
                    "\"Close games come down to execution, and today we executed when it mattered most,\" {$name} said.",
                ],
                default => [
                    "\"Good team win. We still have things to clean up, but I'm proud of how we competed today,\" {$name} said.",
                    "\"We're just focused on getting better every week. Today was a step in the right direction,\" {$name} said.",
                ],
            };
        } else {
            $quotes = match ($gameType) {
                'blowout' => [
                    "\"We have to look in the mirror. That's not who we are as a team. We'll get back to work,\" {$name} said.",
                    "\"I'm not going to sugarcoat it. That was unacceptable. We have to be better,\" {$name} said bluntly.",
                ],
                'comeback' => [
                    "\"We had it. We had the game in our hands and we let it go. That's tough to swallow,\" a visibly frustrated {$name} said.",
                    "\"You can't take your foot off the gas in this league. We learned that the hard way today,\" {$name} said.",
                ],
                'thriller' => [
                    "\"It stings because we were right there. A play here, a play there, and it's a different outcome,\" {$name} said.",
                    "\"You tip your cap sometimes. Both teams played hard. We just came up a play short,\" {$name} said.",
                ],
                default => [
                    "\"Back to the drawing board. We know what we need to fix, and we'll get after it this week,\" {$name} said.",
                    "\"It's a long season. We have a lot of football left to play. We'll respond,\" {$name} said.",
                ],
            };
        }

        return $this->pickOne($quotes);
    }

    // ─── Headline Generation ───────────────────────────────────────────

    private function generateHeadline(array $c, bool $isWinner, string $teamName, string $oppName, ?array $star, string $gameType): string
    {
        $starName = $star ? $star['last_name'] : null;
        $ws = $c['win_score'];
        $ls = $c['lose_score'];

        if ($isWinner) {
            $options = match ($gameType) {
                'blowout' => [
                    "{$teamName} Dominate {$oppName} in {$ws}-{$ls} Rout",
                    "{$teamName} Roll Past {$oppName}, {$ws}-{$ls}",
                    $starName ? "{$starName} Leads {$teamName} Rout of {$oppName}" : "{$teamName} Cruise Past {$oppName}",
                ],
                'comeback' => [
                    "{$teamName} Storm Back to Stun {$oppName}, {$ws}-{$ls}",
                    "Comeback Complete: {$teamName} Rally Past {$oppName}",
                    $starName ? "{$starName} Sparks {$teamName} Comeback vs. {$oppName}" : "{$teamName} Author Dramatic Comeback",
                ],
                'thriller' => [
                    "{$teamName} Edge {$oppName} in {$ws}-{$ls} Thriller",
                    $starName ? "{$starName} Delivers as {$teamName} Survive {$oppName}" : "{$teamName} Hold Off {$oppName} in Nail-Biter",
                    "Down to the Wire: {$teamName} {$ws}, {$oppName} {$ls}",
                ],
                'shootout' => [
                    "{$teamName} Outgun {$oppName} in {$ws}-{$ls} Shootout",
                    "Offensive Fireworks: {$teamName} Top {$oppName}, {$ws}-{$ls}",
                ],
                'defensive_battle' => [
                    "{$teamName} Grind Out {$ws}-{$ls} Win Over {$oppName}",
                    "Defense Rules as {$teamName} Edge {$oppName}, {$ws}-{$ls}",
                ],
                default => [
                    "{$teamName} Top {$oppName}, {$ws}-{$ls}",
                    $starName ? "{$starName} Stars as {$teamName} Beat {$oppName}" : "{$teamName} Handle {$oppName}, {$ws}-{$ls}",
                    "{$teamName} Earn Week {$c['week']} Win Over {$oppName}",
                ],
            };
        } else {
            $options = match ($gameType) {
                'blowout' => [
                    "{$teamName} Blown Out by {$oppName}, {$ws}-{$ls}",
                    "{$teamName} Have No Answers in Lopsided Loss to {$oppName}",
                ],
                'comeback' => [
                    "{$teamName} Collapse in Fourth Quarter, Fall to {$oppName}",
                    "Lead Evaporates as {$teamName} Drop Heartbreaker to {$oppName}",
                ],
                'thriller' => [
                    "{$teamName} Fall Short in {$ws}-{$ls} Loss to {$oppName}",
                    "{$teamName} Come Up Empty in Tight Loss to {$oppName}",
                ],
                default => [
                    "{$teamName} Fall to {$oppName}, {$ws}-{$ls}",
                    "{$teamName} Drop Week {$c['week']} Contest to {$oppName}",
                ],
            };
        }

        return $this->pickOne($options);
    }

    // ─── Stat Line Formatting ──────────────────────────────────────────

    private function formatDetailedStatLine(array $player): string
    {
        $stats = $player['game_stats'] ?? [];
        $pos = $player['position'];

        if ($pos === 'QB' && ($stats['pass_attempts'] ?? 0) > 0) {
            $comp = $stats['pass_completions'] ?? 0;
            $att = $stats['pass_attempts'] ?? 0;
            $yds = $stats['pass_yards'] ?? 0;
            $tds = $stats['pass_tds'] ?? 0;
            $ints = $stats['interceptions'] ?? 0;

            $line = "completing {$comp} of {$att} for {$yds} yards and {$tds} touchdown" . ($tds !== 1 ? 's' : '');
            if ($ints > 0) {
                $line .= " with {$ints} interception" . ($ints !== 1 ? 's' : '');
            } else {
                $line .= " without turning the ball over";
            }

            // Add rushing if notable
            $rushYds = $stats['rush_yards'] ?? 0;
            $rushTds = $stats['rush_tds'] ?? 0;
            if ($rushYds > 15 || $rushTds > 0) {
                $line .= ", adding {$rushYds} yards on the ground";
                if ($rushTds > 0) $line .= " with a rushing score";
            }
            return $line;
        }

        if ($pos === 'RB') {
            $att = $stats['rush_attempts'] ?? 0;
            $yds = $stats['rush_yards'] ?? 0;
            $tds = $stats['rush_tds'] ?? 0;
            $line = "carrying the ball {$att} times for {$yds} yards";
            if ($tds > 0) $line .= " and {$tds} score" . ($tds > 1 ? 's' : '');

            $rec = $stats['receptions'] ?? 0;
            $recYds = $stats['rec_yards'] ?? 0;
            if ($rec > 0) {
                $line .= ", adding {$rec} catch" . ($rec > 1 ? 'es' : '') . " for {$recYds} receiving yards";
            }
            return $line;
        }

        if (in_array($pos, ['WR', 'TE'])) {
            $rec = $stats['receptions'] ?? 0;
            $tgt = $stats['targets'] ?? 0;
            $yds = $stats['rec_yards'] ?? 0;
            $tds = $stats['rec_tds'] ?? 0;
            $line = "hauling in {$rec} catch" . ($rec > 1 ? 'es' : '') . " on {$tgt} targets for {$yds} yards";
            if ($tds > 0) $line .= " and " . ($tds === 1 ? "a touchdown" : "{$tds} touchdowns");
            return $line;
        }

        if (in_array($pos, ['DE', 'DT', 'LB', 'CB', 'S'])) {
            $tkl = $stats['tackles'] ?? 0;
            $sacks = $stats['sacks'] ?? 0;
            $ints = $stats['interceptions_def'] ?? 0;
            $ff = $stats['forced_fumbles'] ?? 0;

            $parts = [];
            if ($tkl > 0) $parts[] = "{$tkl} tackle" . ($tkl > 1 ? 's' : '');
            if ($sacks > 0) $parts[] = ($sacks == 1 ? 'a sack' : "{$sacks} sacks");
            if ($ints > 0) $parts[] = ($ints == 1 ? 'an interception' : "{$ints} interceptions");
            if ($ff > 0) $parts[] = ($ff == 1 ? 'a forced fumble' : "{$ff} forced fumbles");

            if (empty($parts)) return "contributing key plays on defense";
            return "racking up " . $this->joinList($parts);
        }

        return "contributing key plays when the team needed them most";
    }

    // ─── Social Posts ──────────────────────────────────────────────────

    private function generateSocialPosts(array $game, array $c, int $seasonId): void
    {
        $now = date('Y-m-d H:i:s');
        $posts = [];
        $gameType = $c['game_class']['type'] ?? 'solid_win';

        // Winner's top player celebration
        if ($c['winner_star']) {
            $tp = $c['winner_star'];
            $handle = '@' . $tp['first_name'] . $tp['last_name'] . ($tp['jersey_number'] ?? '');
            $displayName = $tp['first_name'] . ' ' . $tp['last_name'];
            $teamEmoji = $c['winner']['logo_emoji'] ?? '';

            $texts = match ($gameType) {
                'comeback' => [
                    "Never count us out. {$teamEmoji} {$c['win_score']}-{$c['lose_score']}",
                    "We FIGHT. That's what this team is about. {$teamEmoji}",
                ],
                'thriller' => [
                    "Heart was pounding on that last drive. Big W. {$teamEmoji}",
                    "Games like this are why I play. {$teamEmoji} {$c['win_score']}-{$c['lose_score']}",
                ],
                'blowout' => [
                    "Statement made. {$teamEmoji} {$c['win_score']}-{$c['lose_score']}",
                    "That's what we do. On to next week. {$teamEmoji}",
                ],
                default => [
                    "Great team win today. Couldn't do it without my guys. {$teamEmoji}",
                    "God is good. Blessed to play this game. {$teamEmoji}",
                    $this->formatDetailedStatLine($tp) . ". We keep working. {$teamEmoji}",
                ],
            };

            $posts[] = [
                'league_id' => $game['league_id'], 'season_id' => $seasonId, 'week' => $game['week'],
                'handle' => $handle, 'display_name' => $displayName, 'avatar_type' => 'player',
                'team_id' => $c['winner']['id'], 'player_id' => $tp['id'],
                'body' => $this->pickOne($texts),
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
            'body' => $this->pickOne($fanTexts),
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
            'body' => $this->pickOne($analystTexts),
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

        $star = $c['winner_star'];
        if ($star) {
            $items[] = "{$star['last_name']} leads {$w['abbreviation']} to Week {$c['week']} victory";
        }

        foreach ($items as $text) {
            $stmt = $this->db->prepare(
                "INSERT INTO ticker_items (league_id, text, type, team_id, week, created_at) VALUES (?, ?, 'score', ?, ?, ?)"
            );
            $stmt->execute([$game['league_id'], $text, $w['id'], $game['week'], date('Y-m-d H:i:s')]);
        }
    }

    // ─── Power Rankings ────────────────────────────────────────────────

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
            $lines[] = "**{$r}. {$name}** ({$record}) — " . $this->pickOne($blurbs);
        }

        $body = "# Week {$week} Power Rankings\n\n" . implode("\n\n", $lines);
        $headline = "Week {$week} Power Rankings: " . ($teams[0]['city'] ?? '') . ' ' . ($teams[0]['name'] ?? '') . ' Hold Top Spot';

        $stmt = $this->db->prepare(
            "INSERT INTO articles (league_id, season_id, week, type, headline, body, author_name, author_persona, team_id, is_ai_generated, published_at)
             VALUES (?, ?, ?, 'power_rankings', ?, ?, 'Dana Reeves', 'dana_reeves', NULL, 0, ?)"
        );
        $stmt->execute([$leagueId, $seasonId, $week, $headline, $body, date('Y-m-d H:i:s')]);
    }

    // ─── Top Performer Finder ──────────────────────────────────────────

    private function findTopPerformer(array $teamStats): ?array
    {
        $topId = null;
        $topScore = 0;

        foreach ($teamStats as $playerId => $stat) {
            $s = ($stat['pass_yards'] ?? 0) + ($stat['rush_yards'] ?? 0) * 2
                + ($stat['rec_yards'] ?? 0) * 1.5
                + ($stat['pass_tds'] ?? 0) * 20 + ($stat['rush_tds'] ?? 0) * 20
                + ($stat['rec_tds'] ?? 0) * 20
                + ($stat['sacks'] ?? 0) * 15 + ($stat['interceptions_def'] ?? 0) * 25;
            if ($s > $topScore) {
                $topScore = $s;
                $topId = $playerId;
            }
        }

        if (!$topId) return null;

        $stmt = $this->db->prepare("SELECT * FROM players WHERE id = ?");
        $stmt->execute([$topId]);
        $player = $stmt->fetch();

        if ($player && isset($teamStats[$topId])) {
            $player['game_stats'] = $teamStats[$topId];
        }

        return $player ?: null;
    }

    // ─── Helpers ───────────────────────────────────────────────────────

    private function getTeam(int $teamId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM teams WHERE id = ?");
        $stmt->execute([$teamId]);
        return $stmt->fetch() ?: [];
    }

    private function pickOne(array $options): string
    {
        return $options[array_rand($options)];
    }

    private function joinList(array $items): string
    {
        if (count($items) === 0) return '';
        if (count($items) === 1) return $items[0];
        if (count($items) === 2) return $items[0] . ' and ' . $items[1];
        $last = array_pop($items);
        return implode(', ', $items) . ' and ' . $last;
    }

    // ─── Injury Narrative ──────────────────────────────────────────────

    /**
     * Extract injury events from the game log.
     */
    private function extractInjuriesFromLog(array $gameLog): array
    {
        $injuries = [];
        foreach ($gameLog as $entry) {
            $type = $entry['play']['type'] ?? '';
            if ($type === 'injury' && !empty($entry['note'])) {
                $note = $entry['note'];
                // Parse: "INJURY: QB John Smith (knee) — Questionable to return"
                $injuries[] = [
                    'quarter' => $entry['quarter'] ?? 0,
                    'note' => $note,
                    'possession' => $entry['possession'] ?? 'home',
                ];
            }
        }
        return $injuries;
    }

    /**
     * Generate a paragraph about in-game injuries if any significant ones occurred.
     */
    private function generateInjuryParagraph(array $c, array $team, array $opponent, bool $isWinner, array $columnist): ?string
    {
        $injuries = $c['injuries'] ?? [];
        if (empty($injuries)) return null;

        // Only write about injuries relevant to this team's recap
        $teamId = (int) $team['id'];
        $oppId = (int) $opponent['id'];

        // Parse injury notes to get details
        $teamInjuries = [];
        $oppInjuries = [];

        foreach ($injuries as $inj) {
            $note = $inj['note'] ?? '';
            $quarter = $inj['quarter'] ?? 0;
            $possession = $inj['possession'] ?? '';

            // Determine which team the injury belongs to
            // The possession tells us who had the ball; offensive injuries = possession team,
            // defensive injuries = other team. Since we can't perfectly tell, include all.
            // Parse the note for details
            if (preg_match('/INJURY:\s*(\w+)\s+(.+?)\s*\((\w+)\)\s*—\s*(.+)/', $note, $m)) {
                $injData = [
                    'position' => $m[1],
                    'name' => trim($m[2]),
                    'injury_type' => $m[3],
                    'severity' => trim($m[4]),
                    'quarter' => $quarter,
                ];

                // We'll include in both recaps — the writer would mention a key opponent going down too
                $teamInjuries[] = $injData;
                $oppInjuries[] = $injData;
            }
        }

        if (empty($teamInjuries) && empty($oppInjuries)) return null;

        // Pick the most dramatic injury to write about
        $allInj = array_merge($teamInjuries, $oppInjuries);

        // Prefer serious/out injuries over questionable
        usort($allInj, function ($a, $b) {
            $sevOrder = ['Serious' => 0, 'Out' => 1, 'Questionable' => 2];
            $aOrder = 2;
            $bOrder = 2;
            foreach ($sevOrder as $key => $val) {
                if (stripos($a['severity'], $key) !== false) $aOrder = $val;
                if (stripos($b['severity'], $key) !== false) $bOrder = $val;
            }
            return $aOrder - $bOrder;
        });

        $primary = $allInj[0];
        $pos = $primary['position'];
        $name = $primary['name'];
        $injType = $primary['injury_type'];
        $severity = $primary['severity'];
        $quarter = $primary['quarter'];
        $quarterLabel = $quarter <= 4 ? "the " . match ($quarter) {
            1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth', default => 'fourth'
        } . " quarter" : 'overtime';

        $isSerious = stripos($severity, 'Serious') !== false || stripos($severity, 'Out') !== false;

        $style = $columnist['style'] ?? 'balanced';

        $paragraph = match ($style) {
            'old_school' => $isSerious
                ? "The game took a tough turn in {$quarterLabel} when {$pos} {$name} went down with a {$injType} injury. {$name} had to be helped off the field and did not return. That is the kind of loss that changes a season, not just a game. You hate to see it."
                : "There was a scare in {$quarterLabel} when {$pos} {$name} went down with a {$injType} issue. {$name} was listed as {$severity} but the initial word is it is not as bad as it looked. Still, you hold your breath on those.",
            'analytical' => $isSerious
                ? "A significant development occurred in {$quarterLabel}: {$pos} {$name} sustained a {$injType} injury and was ruled {$severity}. The backup entered, and the impact on the unit was measurable. Losing a player of {$name}'s caliber could have long-term roster implications."
                : "{$pos} {$name} left briefly in {$quarterLabel} with a {$injType} issue ({$severity}) but the overall impact on the game was minimal.",
            'narrative' => $isSerious
                ? "Then came the moment that silenced the stadium. In {$quarterLabel}, {$name} crumpled to the turf clutching the {$injType}. The training staff sprinted out. Teammates gathered, helmets off, watching. When {$name} couldn't put weight on the leg, when the cart came out, you could feel the game shift. Everything that happened after happened in the shadow of that injury."
                : "There was a brief scare in {$quarterLabel} when {$name} grabbed at the {$injType} after a play. The {$pos} walked gingerly to the sideline, was evaluated, and was listed as {$severity}. The collective exhale was audible.",
            default => $isSerious
                ? "{$pos} {$name} left the game in {$quarterLabel} with a {$injType} injury and did not return. The severity of the injury is a concern going forward."
                : "{$pos} {$name} dealt with a {$injType} issue in {$quarterLabel} but was listed as {$severity}.",
        };

        // If there are multiple injuries, mention them
        if (count($allInj) > 1) {
            $otherCount = count($allInj) - 1;
            $paragraph .= " " . ($otherCount === 1 ? "One other player" : "{$otherCount} other players") . " also left with injuries during the contest.";
        }

        return $paragraph;
    }
}
