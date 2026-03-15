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

    // ─── Playoff Content ──────────────────────────────────────────────

    /**
     * Generate playoff-specific articles after each playoff game.
     */
    public function generatePlayoffContent(int $leagueId, int $seasonId, int $week, array $game, array $result): void
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

        $winnerName = $winner['city'] . ' ' . $winner['name'];
        $loserName = $loser['city'] . ' ' . $loser['name'];

        $boxScore = $result['box_score'] ?? [];
        $gameLog = $result['game_log'] ?? $boxScore['game_log'] ?? [];
        $gameClass = $result['game_class'] ?? $boxScore['game_class'] ?? $this->classifyFromScores($homeScore, $awayScore, $gameLog);
        $gameType = $gameClass['type'] ?? 'solid_win';

        $homeStats = $result['home_stats'] ?? $boxScore['home']['stats'] ?? [];
        $awayStats = $result['away_stats'] ?? $boxScore['away']['stats'] ?? [];
        $winnerStats = $homeScore > $awayScore ? $homeStats : $awayStats;
        $loserStats = $homeScore > $awayScore ? $awayStats : $homeStats;
        $winnerStar = $this->findTopPerformer($winnerStats);
        $loserStar = $this->findTopPerformer($loserStats);

        // Determine round
        $gameType2 = $game['game_type'] ?? 'wild_card';
        $roundLabels = [
            'wild_card' => 'Wild Card',
            'divisional' => 'Divisional Round',
            'conference_championship' => 'Conference Championship',
            'super_bowl' => 'Super Bowl',
        ];
        $roundLabel = $roundLabels[$gameType2] ?? 'Playoff';
        $isSuperBowl = $gameType2 === 'super_bowl';

        $winnerSeed = $winner['seed'] ?? '';
        $loserSeed = $loser['seed'] ?? '';
        $seedContext = '';
        if ($winnerSeed && $loserSeed) {
            $seedContext = "The No. {$winnerSeed} seed {$winnerName} over the No. {$loserSeed} seed {$loserName}";
            $isUpset = (int)$winnerSeed > (int)$loserSeed;
        } else {
            $isUpset = false;
        }

        // Select columnist based on game drama
        if (in_array($gameType, ['thriller', 'comeback'])) {
            $columnist = self::COLUMNISTS['marcus_bell'];
            $columnistKey = 'marcus_bell';
        } elseif (in_array($gameType, ['blowout', 'defensive_battle'])) {
            $columnist = self::COLUMNISTS['terry_hollis'];
            $columnistKey = 'terry_hollis';
        } else {
            $columnist = $this->selectColumnist($gameType);
            $columnistKey = array_search($columnist, self::COLUMNISTS) ?: 'dana_reeves';
        }

        $starName = $winnerStar ? ($winnerStar['first_name'] . ' ' . $winnerStar['last_name']) : null;
        $starLine = $winnerStar ? $this->formatDetailedStatLine($winnerStar) : '';
        $loserStarName = $loserStar ? ($loserStar['first_name'] . ' ' . $loserStar['last_name']) : null;
        $loserStarLine = $loserStar ? $this->formatDetailedStatLine($loserStar) : '';
        $now = date('Y-m-d H:i:s');

        // --- Winner article ---
        $headline = $this->generatePlayoffHeadline($roundLabel, $gameType2, $winnerName, $loserName, $winScore, $loseScore, $isUpset, $starName, $winnerSeed, $loserSeed);

        $paragraphs = [];

        // Paragraph 1: Lede
        if ($isSuperBowl) {
            $paragraphs[] = $this->pickOne([
                "Confetti rains down. The {$winnerName} are Super Bowl Champions. In a {$winScore}-{$loseScore} victory over the {$loserName}, the {$winner['name']} etched their names into football immortality and delivered a championship to a franchise that has waited for this moment.",
                "It is over. The {$winnerName} have done it. With a {$winScore}-{$loseScore} triumph over the {$loserName} in Super Bowl, the {$winner['name']} stand alone at the mountaintop, crowned as the best team in football.",
                "Champions. The {$winnerName} defeated the {$loserName} {$winScore}-{$loseScore} in a Super Bowl that will be remembered for generations. When the final whistle blew, the celebration erupted. A city can finally exhale.",
            ]);
        } elseif ($isUpset && $gameType2 === 'wild_card') {
            $paragraphs[] = $this->pickOne([
                "Upset! The {$winnerName}, a No. {$winnerSeed} seed, walked into hostile territory and stunned the No. {$loserSeed} {$loserName} {$winScore}-{$loseScore} in a Wild Card game that nobody saw coming.",
                "So much for the home-field advantage. The {$winnerName} pulled off the upset of the postseason, knocking off the favored {$loserName} {$winScore}-{$loseScore} in the Wild Card round.",
            ]);
        } else {
            $paragraphs[] = $this->pickOne([
                "The {$winnerName} are moving on. A {$winScore}-{$loseScore} {$roundLabel} victory over the {$loserName} punches their ticket to the next round, and this team looks like it has no intention of slowing down.",
                "{$roundLabel}: Mission accomplished. The {$winnerName} dispatched the {$loserName} {$winScore}-{$loseScore} and advance in the postseason with a performance that showcased everything this team is about.",
                "One more down, and the {$winnerName}'s championship dream stays alive. A {$winScore}-{$loseScore} victory over the {$loserName} in the {$roundLabel} sends them marching forward into the next round.",
            ]);
        }

        // Paragraph 2: Star performance
        if ($starName) {
            $paragraphs[] = $this->pickOne([
                "{$starName} delivered when the stage was biggest, {$starLine}. In a game of this magnitude, you need your best players to be their best, and {$starName} answered the call in emphatic fashion.",
                "On the biggest stage of the season, {$starName} was sensational, {$starLine}. This is the kind of performance that cements a legacy, the kind of game film that future generations will study.",
                "Give the game ball to {$starName}. {$starLine}. When playoff pressure tightened its grip, {$starName} played loose, played free, and played at an elite level.",
            ]);
        } else {
            $paragraphs[] = "This was a collective effort from the {$winnerName}, with contributions up and down the roster. No single star, just a team that believed and executed when it mattered most.";
        }

        // Paragraph 3: Game flow / key moment
        if ($gameType === 'comeback') {
            $paragraphs[] = "The {$winnerName} trailed in the fourth quarter, and the season hung in the balance. But playoff teams find a way, and the {$winner['name']} did exactly that, stringing together a rally that will be replayed for years. The {$loserName} had victory in their grasp and watched helplessly as it slipped away.";
        } elseif ($gameType === 'thriller') {
            $paragraphs[] = "Neither team could build a comfortable margin, and the game came down to the final minutes. With the score {$winScore}-{$loseScore}, every snap carried the weight of an entire season. The {$winnerName} made one more play than the {$loserName}, and in the playoffs, that is all the difference in the world.";
        } elseif ($gameType === 'blowout') {
            $paragraphs[] = "This one was over early. The {$winnerName} imposed their will from the opening possession, building a lead that felt insurmountable by halftime. The {$loserName} never found their footing, never gained traction, and never truly threatened. A {$margin}-point margin flatters the losing side.";
        } else {
            $paragraphs[] = "The {$winnerName} controlled the game's tempo throughout, methodically building their lead and never giving the {$loserName} an opening. When the {$loser['name']} made a push, the {$winner['name']} had an answer. That is what separates playoff teams from pretenders.";
        }

        // Paragraph 4: What's next
        if ($isSuperBowl) {
            $paragraphs[] = "For the {$winnerName}, the journey is complete. From training camp through the regular season, through every playoff battle, it all led to this moment. The parade route is being planned. The rings are being designed. The {$winner['name']} are champions, and nobody can take that away from them.";
            $paragraphs[] = "In the losing locker room, the {$loserName} sit in stunned silence. They came within {$margin} points of the ultimate prize. The sting of this loss will fuel them through the offseason, but tonight, it simply hurts. They gave everything they had, and it was not quite enough.";
        } elseif ($gameType2 === 'conference_championship') {
            $paragraphs[] = "Next stop: the Super Bowl. The {$winnerName} have punched their ticket to the biggest game in sports. After dismantling the {$loserName} in the Conference Championship, this team has earned its place on the grandest stage. The question now is whether anyone can stop them.";
        } elseif ($gameType2 === 'divisional') {
            $paragraphs[] = "The {$winnerName} advance to the Conference Championship, where the stakes only get higher and the margin for error shrinks to nothing. But this team has proven it belongs, and after dispatching the {$loserName}, confidence is sky-high.";
        } else {
            $paragraphs[] = "The {$winnerName} move on to the Divisional Round, where they will face a tougher test. But after tonight's performance, there is reason to believe this team has the talent and the toughness to keep advancing.";
        }

        // Paragraph 5: Elimination narrative for the loser
        if ($isSuperBowl) {
            // Already covered above
        } else {
            $paragraphs[] = "For the {$loserName}, the season is over. The lockers will be cleaned out, the exit interviews will be conducted, and an offseason of reflection begins. " . ($loserStarName
                ? "{$loserStarName} gave everything in a losing effort, {$loserStarLine}, but it was not enough to extend the season."
                : "Despite a valiant effort, the {$loser['name']} come up short in their bid to advance.");
        }

        $body = implode("\n\n", $paragraphs);

        $stmt = $this->db->prepare(
            "INSERT INTO articles (league_id, season_id, week, type, headline, body, author_name, author_persona, team_id, game_id, is_ai_generated, published_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)"
        );
        $stmt->execute([
            $leagueId, $seasonId, $week, 'playoff_recap',
            $headline, $body,
            $columnist['name'], $columnistKey,
            $winner['id'], $game['id'] ?? null,
            $now,
        ]);

        // Generate ticker items for the playoff result
        $tickerText = $isSuperBowl
            ? "SUPER BOWL CHAMPIONS: {$winner['abbreviation']} {$winScore}, {$loser['abbreviation']} {$loseScore} - FINAL"
            : "{$roundLabel}: {$winner['abbreviation']} {$winScore}, {$loser['abbreviation']} {$loseScore} - FINAL";

        $stmt = $this->db->prepare(
            "INSERT INTO ticker_items (league_id, text, type, team_id, week, created_at) VALUES (?, ?, 'playoff', ?, ?, ?)"
        );
        $stmt->execute([$leagueId, $tickerText, $winner['id'], $week, $now]);
    }

    /**
     * Generate a playoff-specific headline.
     */
    private function generatePlayoffHeadline(string $roundLabel, string $gameType, string $winnerName, string $loserName, int $winScore, int $loseScore, bool $isUpset, ?string $starName, $winnerSeed, $loserSeed): string
    {
        if ($gameType === 'super_bowl') {
            return $this->pickOne([
                "SUPER BOWL CHAMPIONS: {$winnerName} Crowned After {$winScore}-{$loseScore} Victory",
                "SUPER BOWL: {$winnerName} Defeat {$loserName} {$winScore}-{$loseScore} to Win It All",
                $starName ? "SUPER BOWL: {$starName} Leads {$winnerName} to Championship Glory" : "SUPER BOWL: {$winnerName} Are Champions of the World",
            ]);
        }

        if ($isUpset && $gameType === 'wild_card') {
            return $this->pickOne([
                "Wild Card Upset: {$winnerName} Stun No. {$loserSeed} {$loserName}, {$winScore}-{$loseScore}",
                "UPSET: No. {$winnerSeed} {$winnerName} Knock Off {$loserName} in Wild Card Stunner",
                $starName ? "{$starName} Powers Wild Card Upset as {$winnerName} Shock {$loserName}" : "Wild Card Shocker: {$winnerName} Topple Favored {$loserName}",
            ]);
        }

        if ($gameType === 'conference_championship') {
            return $this->pickOne([
                "{$winnerName} Headed to the Super Bowl After {$winScore}-{$loseScore} Win",
                "Conference Championship: {$winnerName} Punch Super Bowl Ticket",
                $starName ? "{$starName} Sends {$winnerName} to the Super Bowl" : "{$winnerName} Advance to Super Bowl with Dominant Performance",
            ]);
        }

        if ($gameType === 'divisional') {
            return $this->pickOne([
                "{$roundLabel}: {$winnerName} Punch Ticket to Conference Championship",
                "{$winnerName} Advance Past {$loserName} in {$roundLabel}, {$winScore}-{$loseScore}",
                $starName ? "{$starName} Leads {$winnerName} Past {$loserName} in {$roundLabel}" : "{$winnerName} Roll Past {$loserName} in Divisional Showdown",
            ]);
        }

        // Generic wild card / default
        return $this->pickOne([
            "{$winnerName} Advance Past {$loserName} in {$roundLabel} Win, {$winScore}-{$loseScore}",
            "{$roundLabel}: {$winnerName} Defeat {$loserName} {$winScore}-{$loseScore}",
            $starName ? "{$starName} Stars as {$winnerName} Win {$roundLabel} Matchup" : "{$winnerName} Earn {$roundLabel} Victory Over {$loserName}",
        ]);
    }

    // ─── Playoff Preview ──────────────────────────────────────────────

    /**
     * Generate preview articles for upcoming playoff matchups.
     */
    public function generatePlayoffPreview(int $leagueId, int $seasonId, int $week, array $matchups): void
    {
        $now = date('Y-m-d H:i:s');
        $columnist = self::COLUMNISTS['dana_reeves'];
        $columnistKey = 'dana_reeves';

        foreach ($matchups as $matchup) {
            $home = $this->getTeam($matchup['home_team_id']);
            $away = $this->getTeam($matchup['away_team_id']);

            if (empty($home) || empty($away)) continue;

            $homeName = $home['city'] . ' ' . $home['name'];
            $awayName = $away['city'] . ' ' . $away['name'];
            $homeRecord = ($home['wins'] ?? 0) . '-' . ($home['losses'] ?? 0);
            $awayRecord = ($away['wins'] ?? 0) . '-' . ($away['losses'] ?? 0);
            $homeSeed = $home['seed'] ?? '';
            $awaySeed = $away['seed'] ?? '';
            $gameType = $matchup['game_type'] ?? 'wild_card';
            $roundLabels = [
                'wild_card' => 'Wild Card',
                'divisional' => 'Divisional Round',
                'conference_championship' => 'Conference Championship',
                'super_bowl' => 'Super Bowl',
            ];
            $roundLabel = $roundLabels[$gameType] ?? 'Playoff';
            $isSuperBowl = $gameType === 'super_bowl';

            // Determine the "underdog" for narrative purposes
            $homeWins = (int)($home['wins'] ?? 0);
            $awayWins = (int)($away['wins'] ?? 0);
            $favored = $homeWins >= $awayWins ? $home : $away;
            $underdog = $homeWins >= $awayWins ? $away : $home;
            $favoredName = $favored['city'] . ' ' . $favored['name'];
            $underdogName = $underdog['city'] . ' ' . $underdog['name'];
            $underdogSeed = $underdog === $home ? $homeSeed : $awaySeed;
            $favoredSeed = $favored === $home ? $homeSeed : $awaySeed;

            // Headline
            if ($isSuperBowl) {
                $headline = $this->pickOne([
                    "Super Bowl Preview: {$homeName} vs. {$awayName} for All the Marbles",
                    "The Big Game: Breaking Down {$homeName} vs. {$awayName}",
                    "Super Bowl Breakdown: Can the {$underdog['name']} Pull Off the Upset?",
                ]);
            } else {
                $headline = $this->pickOne([
                    "{$roundLabel} Preview: Can the " . ($underdogSeed ? "No. {$underdogSeed} " : '') . "{$underdogName} Pull Off the Upset?",
                    "{$roundLabel} Preview: {$homeName} ({$homeRecord}) vs. {$awayName} ({$awayRecord})",
                    "{$roundLabel} Breakdown: {$favoredName} Host {$underdogName} with Season on the Line",
                ]);
            }

            // Build preview paragraphs
            $paragraphs = [];

            // Paragraph 1: Setting the stage
            if ($isSuperBowl) {
                $paragraphs[] = "This is it. The {$homeName} ({$homeRecord}) and the {$awayName} ({$awayRecord}) meet in the Super Bowl, the culmination of an entire season's worth of blood, sweat, and Sunday afternoons. Both teams have survived the gauntlet of the playoffs, and now just sixty minutes of football stand between one of them and immortality.";
            } else {
                $paragraphs[] = $this->pickOne([
                    "The {$roundLabel} brings us a fascinating matchup between the {$homeName} ({$homeRecord}) and the {$awayName} ({$awayRecord}). Both teams earned their place in the postseason, but only one will advance. The stakes could not be higher.",
                    "When the {$homeName} ({$homeRecord}) host the {$awayName} ({$awayRecord}) in the {$roundLabel}, something has to give. These are two teams that have proven they belong, but the postseason is unforgiving, and one of them will be headed home.",
                ]);
            }

            // Paragraph 2: Strengths
            $paragraphs[] = $this->pickOne([
                "The {$favoredName} enter as the favorites, and for good reason. Their " . ($favoredSeed ? "No. {$favoredSeed} seed reflects " : "record reflects ") . "a season of consistent excellence. They have the roster depth, the coaching, and the experience to thrive under postseason pressure.",
                "On paper, the {$favoredName} have the edge. They posted a superior record during the regular season and have home-field advantage working in their favor. Their ability to win in different ways throughout the year makes them a dangerous opponent in any single-elimination scenario.",
            ]);

            // Paragraph 3: The underdog case
            $paragraphs[] = $this->pickOne([
                "But do not count out the {$underdogName}. The regular season record does not always tell the full story, and this is a team that has shown flashes of brilliance throughout the year. If they can execute their game plan and avoid critical turnovers, they have the talent to pull off the upset.",
                "The {$underdogName}, however, are not here to play the role of sacrificial lamb. They earned their postseason berth the hard way, and playoff football is its own animal. Records get thrown out the window, and this {$underdog['name']} squad has the kind of players who thrive when the pressure mounts.",
            ]);

            // Paragraph 4: Key matchups
            $paragraphs[] = $this->pickOne([
                "The key matchup to watch will be in the trenches. Whichever team wins the battle at the line of scrimmage will likely win the game. The ability to control the clock through the run game and generate pressure on the opposing quarterback will be the difference between advancing and going home.",
                "This game could come down to turnovers and third-down efficiency. In playoff football, the team that protects the ball and converts on third down almost always prevails. Both teams have the defensive talent to force mistakes, so discipline and execution will be paramount.",
            ]);

            // Paragraph 5: Prediction
            $paragraphs[] = $this->pickOne([
                "Prediction: The {$favoredName} have earned the right to be favorites, and I expect them to advance in a competitive game. But the {$underdogName} will not go quietly. This has the makings of a game that comes down to the fourth quarter.",
                "If forced to pick, I lean toward the {$favoredName}, but this is the playoffs, where certainty goes to die. The {$underdogName} have nothing to lose, and that makes them the most dangerous opponent imaginable.",
            ]);

            $body = implode("\n\n", $paragraphs);

            $stmt = $this->db->prepare(
                "INSERT INTO articles (league_id, season_id, week, type, headline, body, author_name, author_persona, team_id, game_id, is_ai_generated, published_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)"
            );
            $stmt->execute([
                $leagueId, $seasonId, $week, 'feature',
                $headline, $body,
                $columnist['name'], $columnistKey,
                null, null,
                $now,
            ]);
        }
    }

    // ─── Draft Coverage ───────────────────────────────────────────────

    /**
     * Generate draft coverage articles after the draft completes.
     */
    public function generateDraftCoverage(int $leagueId, int $seasonId, array $picks): void
    {
        if (empty($picks)) return;

        $now = date('Y-m-d H:i:s');

        // Article 1: First overall pick spotlight (Marcus Bell — human drama)
        $firstPick = null;
        foreach ($picks as $pick) {
            if (($pick['pick'] ?? 0) === 1 && ($pick['round'] ?? 0) === 1) {
                $firstPick = $pick;
                break;
            }
        }
        if (!$firstPick) {
            $firstPick = $picks[0]; // fallback
        }

        $firstTeam = $this->getTeam($firstPick['team_id']);
        $firstTeamName = $firstTeam['city'] . ' ' . $firstTeam['name'];
        $playerName = $firstPick['player_name'] ?? 'Unknown';
        $position = $firstPick['position'] ?? 'Unknown';
        $pickNum = $firstPick['pick'] ?? 1;
        $pickOrdinal = $this->ordinal($pickNum);

        $columnist = self::COLUMNISTS['marcus_bell'];

        $paragraphs = [];
        $paragraphs[] = $this->pickOne([
            "The moment {$playerName}'s name was called, a new chapter began. The {$firstTeamName} selected the {$position} with the {$pickOrdinal} overall pick, and in doing so, placed the future of their franchise on his shoulders. It is a weight few can carry, but the {$firstTeam['name']} believe they have found their cornerstone.",
            "With the {$pickOrdinal} overall pick in the draft, the {$firstTeamName} selected {$position} {$playerName}, ending months of speculation and beginning what they hope will be a franchise-altering career. The crowd erupted. The commissioner shook his hand. And just like that, {$playerName} became the face of the {$firstTeam['name']}.",
        ]);
        $paragraphs[] = $this->pickOne([
            "{$playerName} arrives with enormous expectations. The {$position} is expected to contribute immediately, bringing a combination of athleticism and instincts that scouts raved about throughout the pre-draft process. For a {$firstTeam['name']} franchise searching for a spark, he represents the brightest hope.",
            "The selection of {$playerName} represents a clear vision from the {$firstTeam['name']} front office. They identified their biggest need, found the best player to fill it, and pulled the trigger without hesitation. {$playerName} is the kind of prospect teams build around.",
        ]);
        $paragraphs[] = $this->pickOne([
            "\"This is a dream come true,\" {$playerName} will likely tell reporters. But the real work starts now. The transition from college to the pros is unforgiving, and the {$firstTeamName} will need {$playerName} to accelerate that development quickly if they want to turn the franchise around.",
            "For {$playerName}, draft night is just the beginning. The celebrations will fade, the jersey will be fitted, and then comes the hard part: proving that the {$firstTeam['name']} made the right call. History is littered with top picks who never panned out, but it is also filled with those who became legends. Which category {$playerName} falls into remains to be written.",
        ]);

        $headline = $this->pickOne([
            "Draft Day: {$firstTeamName} Select {$playerName} with the {$pickOrdinal} Overall Pick",
            "{$playerName} Goes No. {$pickNum} Overall to the {$firstTeamName}",
            "The {$firstTeam['name']}' Future Arrives: {$playerName} Selected {$pickOrdinal} Overall",
        ]);

        $body = implode("\n\n", $paragraphs);

        $stmt = $this->db->prepare(
            "INSERT INTO articles (league_id, season_id, week, type, headline, body, author_name, author_persona, team_id, game_id, is_ai_generated, published_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)"
        );
        $stmt->execute([
            $leagueId, $seasonId, 0, 'draft_coverage',
            $headline, $body,
            $columnist['name'], 'marcus_bell',
            $firstTeam['id'], null,
            $now,
        ]);

        // Article 2: Draft Winners and Losers (Dana Reeves — analytical)
        $columnist = self::COLUMNISTS['dana_reeves'];
        $teamPicks = [];
        foreach ($picks as $pick) {
            $tid = $pick['team_id'];
            if (!isset($teamPicks[$tid])) $teamPicks[$tid] = [];
            $teamPicks[$tid][] = $pick;
        }

        $paragraphs = [];
        $paragraphs[] = "The draft is in the books, and now comes the fun part: grading the results. Some teams came away with exactly what they needed. Others left value on the board, reached for need over talent, or simply failed to address their most glaring holes. Here is an early look at the winners and losers.";

        // Winners: teams with the most picks or first-round picks
        $teamsByPicks = $teamPicks;
        uasort($teamsByPicks, fn($a, $b) => count($b) - count($a));
        $winnerTeams = array_slice($teamsByPicks, 0, 3, true);

        $paragraphs[] = "**WINNERS**";
        foreach ($winnerTeams as $tid => $tPicks) {
            $team = $this->getTeam($tid);
            $teamName = $team['city'] . ' ' . $team['name'];
            $count = count($tPicks);
            $firstRounders = array_filter($tPicks, fn($p) => ($p['round'] ?? 99) === 1);
            $topPick = $tPicks[0];
            $topName = $topPick['player_name'] ?? 'Unknown';
            $topPos = $topPick['position'] ?? '';

            $paragraphs[] = $this->pickOne([
                "**{$teamName}** — With {$count} total selections, the {$team['name']} had the draft capital to reshape their roster, and they used it wisely. The addition of {$topPos} {$topName} gives them an immediate upgrade at a position of need. This class has a chance to accelerate the rebuild significantly.",
                "**{$teamName}** — The {$team['name']} came into the draft with a clear plan and executed it. Landing {$topName} at {$topPos} was the headline, but the depth of this class is what will pay dividends down the road. {$count} new faces is a lot of fresh competition in training camp.",
            ]);
        }

        // Losers: teams with fewest picks
        $loserTeams = array_slice(array_reverse($teamsByPicks, true), 0, 2, true);
        $paragraphs[] = "**LOSERS**";
        foreach ($loserTeams as $tid => $tPicks) {
            $team = $this->getTeam($tid);
            $teamName = $team['city'] . ' ' . $team['name'];
            $count = count($tPicks);

            $paragraphs[] = $this->pickOne([
                "**{$teamName}** — With only {$count} selection" . ($count !== 1 ? 's' : '') . ", the {$team['name']} simply did not have enough ammunition to address their roster holes. Whether they traded away picks earlier or not, the result is a thin draft class that puts pressure on free agency to fill the gaps.",
                "**{$teamName}** — The {$team['name']} had limited draft capital and it showed. Just {$count} pick" . ($count !== 1 ? 's' : '') . " means this front office is banking on the current roster and free agent additions. That is a risky bet for a team with clear needs.",
            ]);
        }

        $headline = "Draft Winners and Losers: Who Nailed It and Who Missed the Mark";
        $body = implode("\n\n", $paragraphs);

        $stmt = $this->db->prepare(
            "INSERT INTO articles (league_id, season_id, week, type, headline, body, author_name, author_persona, team_id, game_id, is_ai_generated, published_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)"
        );
        $stmt->execute([
            $leagueId, $seasonId, 0, 'draft_coverage',
            $headline, $body,
            $columnist['name'], 'dana_reeves',
            null, null,
            $now,
        ]);

        // Article 3: Round-by-Round Breakdown (Terry Hollis)
        $columnist = self::COLUMNISTS['terry_hollis'];
        $paragraphs = [];
        $paragraphs[] = "Every pick tells a story. Some teams solved problems. Others created new ones. Here is a round-by-round look at every selection in this year's draft.";

        $rounds = [];
        foreach ($picks as $pick) {
            $r = $pick['round'] ?? 1;
            if (!isset($rounds[$r])) $rounds[$r] = [];
            $rounds[$r][] = $pick;
        }
        ksort($rounds);

        foreach ($rounds as $roundNum => $roundPicks) {
            $roundOrd = $this->ordinal($roundNum);
            $lines = [];
            foreach ($roundPicks as $pick) {
                $team = $this->getTeam($pick['team_id']);
                $abbr = $team['abbreviation'] ?? $team['name'] ?? '???';
                $pName = $pick['player_name'] ?? 'Unknown';
                $pPos = $pick['position'] ?? '';
                $pickNum = $pick['pick'] ?? '?';
                $lines[] = "Pick {$pickNum}: {$abbr} — {$pPos} {$pName}";
            }
            $paragraphs[] = "**Round {$roundNum}**\n" . implode("\n", $lines);
        }

        $paragraphs[] = $this->pickOne([
            "When the dust settles, this draft class will be judged not by the names on the board today, but by the names that emerge on Sundays. Every pick is a projection, a gamble, a bet on potential. Time will tell which teams got it right.",
            "Draft grades are a fool's errand in real time. The real evaluation begins when these young men strap on the pads and compete against the best players in the world. Ask me again in three years who won this draft.",
        ]);

        $headline = "Round-by-Round Draft Breakdown: Every Pick, Every Team";
        $body = implode("\n\n", $paragraphs);

        $stmt = $this->db->prepare(
            "INSERT INTO articles (league_id, season_id, week, type, headline, body, author_name, author_persona, team_id, game_id, is_ai_generated, published_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)"
        );
        $stmt->execute([
            $leagueId, $seasonId, 0, 'draft_coverage',
            $headline, $body,
            $columnist['name'], 'terry_hollis',
            null, null,
            $now,
        ]);
    }

    // ─── Trade Story ──────────────────────────────────────────────────

    /**
     * Generate an article when a trade is completed.
     */
    public function generateTradeStory(int $leagueId, int $seasonId, int $week, array $trade): void
    {
        $now = date('Y-m-d H:i:s');

        $team1 = $this->getTeam($trade['team1_id']);
        $team2 = $this->getTeam($trade['team2_id']);
        $team1Name = $team1['city'] . ' ' . $team1['name'];
        $team2Name = $team2['city'] . ' ' . $team2['name'];

        $playersSent = $trade['players_sent'] ?? [];
        $playersReceived = $trade['players_received'] ?? [];
        $picksSent = $trade['picks_sent'] ?? [];
        $picksReceived = $trade['picks_received'] ?? [];

        // Identify the "headliner" — highest rated player in the trade
        $allPlayers = array_merge($playersSent, $playersReceived);
        $headliner = null;
        $headlinerRating = 0;
        $headlinerTeam = null;
        foreach ($playersSent as $p) {
            $rating = (int)($p['overall_rating'] ?? $p['overall'] ?? 0);
            if ($rating > $headlinerRating) {
                $headlinerRating = $rating;
                $headliner = $p;
                $headlinerTeam = $team2; // team2 receives players_sent
            }
        }
        foreach ($playersReceived as $p) {
            $rating = (int)($p['overall_rating'] ?? $p['overall'] ?? 0);
            if ($rating > $headlinerRating) {
                $headlinerRating = $rating;
                $headliner = $p;
                $headlinerTeam = $team1; // team1 receives players_received
            }
        }

        // Determine drama level for columnist selection
        $isBlockbuster = $headlinerRating >= 85 || (count($allPlayers) >= 3) || !empty($picksSent) || !empty($picksReceived);
        if ($isBlockbuster) {
            $columnist = self::COLUMNISTS['marcus_bell'];
            $columnistKey = 'marcus_bell';
        } else {
            $columnist = self::COLUMNISTS['dana_reeves'];
            $columnistKey = 'dana_reeves';
        }

        $headlinerName = $headliner ? (($headliner['first_name'] ?? '') . ' ' . ($headliner['last_name'] ?? '')) : 'players';
        $headlinerPos = $headliner ? ($headliner['position'] ?? '') : '';
        $receivingTeamName = $headlinerTeam ? ($headlinerTeam['city'] . ' ' . $headlinerTeam['name']) : $team1Name;
        $sendingTeamName = $headlinerTeam === $team2 ? $team1Name : $team2Name;

        // Build the trade details string
        $sentNames = array_map(fn($p) => ($p['position'] ?? '') . ' ' . ($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''), $playersSent);
        $receivedNames = array_map(fn($p) => ($p['position'] ?? '') . ' ' . ($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''), $playersReceived);
        $sentPickDescs = array_map(fn($p) => ($p['round'] ?? '?') . '-round pick' . (isset($p['year']) ? " ({$p['year']})" : ''), $picksSent);
        $receivedPickDescs = array_map(fn($p) => ($p['round'] ?? '?') . '-round pick' . (isset($p['year']) ? " ({$p['year']})" : ''), $picksReceived);

        $team1Gets = array_merge($receivedNames, $receivedPickDescs);
        $team2Gets = array_merge($sentNames, $sentPickDescs);

        // Headline
        if ($isBlockbuster && $headliner) {
            $headline = $this->pickOne([
                "Breaking: {$receivingTeamName} Acquire {$headlinerPos} {$headlinerName} from {$sendingTeamName} in Blockbuster Deal",
                "TRADE: {$headlinerName} Headed to {$receivingTeamName} in Major Shakeup",
                "Blockbuster: {$receivingTeamName} Land {$headlinerName} in Franchise-Altering Trade",
            ]);
        } else {
            $headline = $this->pickOne([
                "{$team1Name} and {$team2Name} Complete Trade",
                "Trade Alert: {$team1Name} Acquire " . ($receivedNames[0] ?? 'New Pieces') . " from {$team2Name}",
                "{$team1Name}, {$team2Name} Swap Assets in Deadline Deal",
            ]);
        }

        $paragraphs = [];

        // Paragraph 1: The news
        $paragraphs[] = $this->pickOne([
            "The {$team1Name} and {$team2Name} have agreed to a trade that sends shockwaves through the league. In the deal, {$team1Name} receive " . $this->joinList($team1Gets) . ", while {$team2Name} get " . $this->joinList($team2Gets) . " in return.",
            "A major trade has been completed. The {$team1Name} have acquired " . $this->joinList($team1Gets) . " from the {$team2Name} in exchange for " . $this->joinList($team2Gets) . ". The deal reshapes both rosters heading into the stretch.",
        ]);

        // Paragraph 2: Impact on the receiving team
        if ($headliner) {
            $salary = $headliner['salary'] ?? $headliner['contract_salary'] ?? null;
            $salaryStr = $salary ? ' (' . ($salary >= 1000000 ? '$' . number_format($salary / 1000000, 1) . 'M' : '$' . number_format($salary)) . ' salary)' : '';

            $paragraphs[] = $this->pickOne([
                "For the {$receivingTeamName}, this is a statement move. Adding {$headlinerName}{$salaryStr} gives them an immediate upgrade at {$headlinerPos} and signals that this front office believes the team is ready to compete right now. The {$headlinerRating}-overall rated {$headlinerPos} brings a level of talent that transforms the roster.",
                "{$headlinerName} is the centerpiece of this deal, and for good reason. The {$headlinerRating}-overall rated {$headlinerPos}{$salaryStr} is the kind of player who can change the trajectory of a franchise. The {$receivingTeamName} are betting big on that talent, and the rest of the league should take notice.",
            ]);
        }

        // Paragraph 3: Impact on the sending team
        $paragraphs[] = $this->pickOne([
            "On the other side, the {$sendingTeamName} are clearly looking toward the future. Parting with a talent like {$headlinerName} is never easy, but the return of " . $this->joinList($headlinerTeam === $team2 ? $team2Gets : $team1Gets) . " provides building blocks for the next chapter. Sometimes you have to take a step back to take two steps forward.",
            "The {$sendingTeamName} made a calculated decision to move {$headlinerName} and stockpile assets. It is the kind of move that might not be popular with the fanbase today, but could look brilliant in hindsight. The pieces they received give them flexibility and youth, two currencies that appreciate over time.",
        ]);

        // Paragraph 4: League-wide impact
        $paragraphs[] = $this->pickOne([
            "Around the league, phones were buzzing as soon as news broke. This trade reshuffles the competitive landscape and could have ripple effects on other teams' plans. The balance of power may have just shifted, and rival front offices are recalculating their own moves accordingly.",
            "Make no mistake: this trade changes the picture for both conferences. The {$receivingTeamName} just got significantly better, and opposing coaches are already adjusting their game plans. This is the kind of mid-season move that can define a franchise for years to come.",
        ]);

        $body = implode("\n\n", $paragraphs);

        $stmt = $this->db->prepare(
            "INSERT INTO articles (league_id, season_id, week, type, headline, body, author_name, author_persona, team_id, game_id, is_ai_generated, published_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)"
        );
        $stmt->execute([
            $leagueId, $seasonId, $week, 'trade_story',
            $headline, $body,
            $columnist['name'], $columnistKey,
            $headlinerTeam['id'] ?? $team1['id'], null,
            $now,
        ]);

        // Ticker item
        $tickerText = "TRADE: {$receivingTeamName} acquire {$headlinerName} from {$sendingTeamName}";
        $stmt = $this->db->prepare(
            "INSERT INTO ticker_items (league_id, text, type, team_id, week, created_at) VALUES (?, ?, 'trade', ?, ?, ?)"
        );
        $stmt->execute([$leagueId, $tickerText, $headlinerTeam['id'] ?? $team1['id'], $week, $now]);
    }

    // ─── Free Agent Signing Story ─────────────────────────────────────

    /**
     * Generate an article when a significant free agent signs.
     */
    public function generateSigningStory(int $leagueId, int $seasonId, int $week, array $signing): void
    {
        $overallRating = (int)($signing['overall_rating'] ?? 0);
        $salary = (int)($signing['salary'] ?? 0);

        // Only generate for significant signings
        if ($overallRating < 75 && $salary < 5000000) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $team = $this->getTeam($signing['team_id']);
        $teamName = $team['city'] . ' ' . $team['name'];
        $playerName = $signing['player_name'] ?? 'Unknown';
        $position = $signing['position'] ?? '';
        $years = (int)($signing['years'] ?? 1);
        $salaryStr = $salary >= 1000000 ? '$' . number_format($salary / 1000000, 1) . 'M' : '$' . number_format($salary);
        $totalStr = $salary * $years >= 1000000 ? '$' . number_format(($salary * $years) / 1000000, 1) . 'M' : '$' . number_format($salary * $years);

        // Higher rated = more drama
        if ($overallRating >= 85) {
            $columnist = self::COLUMNISTS['marcus_bell'];
            $columnistKey = 'marcus_bell';
        } elseif ($overallRating >= 80) {
            $columnist = self::COLUMNISTS['dana_reeves'];
            $columnistKey = 'dana_reeves';
        } else {
            $columnist = self::COLUMNISTS['terry_hollis'];
            $columnistKey = 'terry_hollis';
        }

        $headline = $this->pickOne([
            "{$teamName} Sign {$position} {$playerName} to {$years}-Year, {$totalStr} Deal",
            "Free Agency: {$playerName} Joins the {$team['name']} on {$years}-Year Contract",
            "SIGNING: {$teamName} Land {$playerName} in Free Agent Splash",
        ]);

        $paragraphs = [];

        // Paragraph 1: The news
        $paragraphs[] = $this->pickOne([
            "The {$teamName} have made a significant addition to their roster, signing {$position} {$playerName} to a {$years}-year deal worth {$totalStr} ({$salaryStr} per year). The move immediately upgrades the {$team['name']}' depth chart at a position that was identified as a priority this offseason.",
            "Free agency has delivered a blockbuster. The {$teamName} have landed {$position} {$playerName}, inking the {$overallRating}-overall rated veteran to a {$years}-year, {$totalStr} contract. It is the kind of signing that changes the perception of a franchise overnight.",
        ]);

        // Paragraph 2: Player profile
        $paragraphs[] = $this->pickOne([
            "{$playerName} brings an {$overallRating}-overall rating and a proven track record of production. At {$position}, he fills a glaring need for a {$team['name']} team that was searching for an upgrade at the position. His combination of experience and talent makes this signing a potential game-changer.",
            "What the {$teamName} are getting in {$playerName} is a {$overallRating}-overall rated {$position} who has consistently performed at a high level. He is the type of player who elevates everyone around him, and his presence in the locker room will be felt just as much as his impact on the field.",
        ]);

        // Paragraph 3: Cap impact and team fit
        $paragraphs[] = $this->pickOne([
            "The financial commitment is significant — {$salaryStr} annually over {$years} years — but the {$team['name']} clearly view this as an investment in winning now. The cap implications will need to be managed carefully, but when a player of {$playerName}'s caliber hits the market, you find a way to make the numbers work.",
            "At {$salaryStr} per season, {$playerName} is not cheap, but the {$teamName} are betting that the production will justify the price tag. With {$years} years on the deal, both sides have a window to accomplish something special together. The {$team['name']} have signaled their intentions: this is a team that expects to compete.",
        ]);

        // Paragraph 4: Context
        $record = ($team['wins'] ?? 0) . '-' . ($team['losses'] ?? 0);
        $paragraphs[] = $this->pickOne([
            "For a {$team['name']} team sitting at {$record}, this signing represents a commitment to improving the roster in real time. The front office is not content to wait, and the addition of {$playerName} should pay immediate dividends. The rest of the league has been put on notice.",
            "The {$teamName} ({$record}) needed a boost, and {$playerName} provides exactly that. Whether this is the move that pushes them over the top or simply one piece of a larger puzzle remains to be seen, but there is no denying the talent and intent behind this signing.",
        ]);

        $body = implode("\n\n", $paragraphs);

        $stmt = $this->db->prepare(
            "INSERT INTO articles (league_id, season_id, week, type, headline, body, author_name, author_persona, team_id, game_id, is_ai_generated, published_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)"
        );
        $stmt->execute([
            $leagueId, $seasonId, $week, 'free_agency',
            $headline, $body,
            $columnist['name'], $columnistKey,
            $team['id'], null,
            $now,
        ]);

        // Ticker item
        $tickerText = "SIGNING: {$team['abbreviation']} sign {$position} {$playerName} ({$years}yr/{$totalStr})";
        $stmt = $this->db->prepare(
            "INSERT INTO ticker_items (league_id, text, type, team_id, week, created_at) VALUES (?, ?, 'signing', ?, ?, ?)"
        );
        $stmt->execute([$leagueId, $tickerText, $team['id'], $week, $now]);
    }

    // ─── Awards Coverage ──────────────────────────────────────────────

    /**
     * Generate a combined awards article after season awards are determined.
     */
    public function generateAwardsCoverage(int $leagueId, int $seasonId, array $awards): void
    {
        if (empty($awards)) return;

        $now = date('Y-m-d H:i:s');

        // Find MVP for headline
        $mvp = null;
        foreach ($awards as $award) {
            if (strtoupper($award['award'] ?? '') === 'MVP') {
                $mvp = $award;
                break;
            }
        }
        $headlinePlayer = $mvp ? $mvp['player_name'] : ($awards[0]['player_name'] ?? 'Top Players');

        $headline = $this->pickOne([
            "Season Awards: {$headlinePlayer} Named MVP",
            "{$headlinePlayer} Takes Home MVP Honors as Season Awards Announced",
            "Awards Night: {$headlinePlayer} Headlines a Star-Studded Class",
        ]);

        $paragraphs = [];

        // Paragraph 1: Intro
        $paragraphs[] = "The ballots are in, the votes have been counted, and the league's best have been recognized. From the Most Valuable Player to the top rookies, this season's award winners represent the very best of what football has to offer. Here is a look at every major award and the players who earned them.";

        // Group awards by type for columnist assignment
        $awardOrder = ['MVP', 'OPOY', 'DPOY', 'OROY', 'DROY', 'Coach of the Year'];
        $presentedAwards = [];
        foreach ($awardOrder as $awardName) {
            foreach ($awards as $award) {
                if (strtoupper($award['award'] ?? '') === strtoupper($awardName)) {
                    $presentedAwards[] = $award;
                    break;
                }
            }
        }
        // Add any remaining awards not in the standard order
        foreach ($awards as $award) {
            $found = false;
            foreach ($presentedAwards as $pa) {
                if (($pa['award'] ?? '') === ($award['award'] ?? '') && ($pa['player_id'] ?? 0) === ($award['player_id'] ?? 0)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) $presentedAwards[] = $award;
        }

        foreach ($presentedAwards as $idx => $award) {
            $awardName = $award['award'] ?? 'Award';
            $playerName = $award['player_name'] ?? 'Unknown';
            $teamId = $award['team_id'] ?? null;
            $stats = $award['stats'] ?? [];
            $team = $teamId ? $this->getTeam($teamId) : [];
            $teamName = !empty($team) ? ($team['city'] . ' ' . $team['name']) : 'his team';
            $teamShort = !empty($team) ? $team['name'] : 'his team';

            // Rotate columnists: MVP/OPOY = Marcus Bell, DPOY = Terry Hollis, Rookies = Dana Reeves, others rotate
            $awardUpper = strtoupper($awardName);
            if (in_array($awardUpper, ['MVP', 'OPOY'])) {
                $voice = 'marcus_bell';
            } elseif (in_array($awardUpper, ['DPOY', 'COACH OF THE YEAR'])) {
                $voice = 'terry_hollis';
            } else {
                $voice = 'dana_reeves';
            }

            $statLine = $this->formatAwardStats($stats);

            if ($awardUpper === 'MVP') {
                $paragraphs[] = $this->pickOne([
                    "**Most Valuable Player: {$playerName}, {$teamName}**\n\nThere was no debate. {$playerName} was the engine that drove the {$teamShort} all season long{$statLine}. When the games mattered most, {$playerName} elevated. When the pressure was at its peak, {$playerName} delivered. This is the kind of season that defines a career, the kind of year players dream about when they first pick up a football. {$playerName} did not just win the MVP — he earned it in a way that left no room for argument.",
                    "**Most Valuable Player: {$playerName}, {$teamName}**\n\n{$playerName} was the most dominant player in the league this season, and it was not particularly close{$statLine}. From Week 1 through the final snap, {$playerName} was the standard by which all other players were measured. The {$teamShort} were a different team with {$playerName} on the field, and the MVP award is a well-deserved recognition of a truly special season.",
                ]);
            } elseif ($awardUpper === 'OPOY') {
                $paragraphs[] = $this->pickOne([
                    "**Offensive Player of the Year: {$playerName}, {$teamName}**\n\n{$playerName} put together one of the most productive offensive seasons in recent memory{$statLine}. Week after week, {$playerName} was the most dangerous weapon on the field. Defenses game-planned around stopping him, and week after week, they failed.",
                    "**Offensive Player of the Year: {$playerName}, {$teamName}**\n\nThe numbers speak for themselves{$statLine}. {$playerName} was simply unstoppable this season, posting the kind of production that redefines what is possible at the position. The {$teamShort} built their offense around {$playerName}, and the results were extraordinary.",
                ]);
            } elseif ($awardUpper === 'DPOY') {
                $paragraphs[] = $this->pickOne([
                    "**Defensive Player of the Year: {$playerName}, {$teamName}**\n\n{$playerName} was a one-man wrecking crew{$statLine}. Opposing offenses built their game plans around avoiding {$playerName}, and even that was often not enough. This is old-school, imposing-your-will football, and nobody did it better this season.",
                    "**Defensive Player of the Year: {$playerName}, {$teamName}**\n\nFear. That is what {$playerName} instilled in opposing offenses{$statLine}. The {$teamShort} defense was anchored by {$playerName}'s relentless effort and game-changing ability. Quarterbacks saw him in their nightmares. Running backs looked for him before the snap. That is the ultimate compliment for a defender.",
                ]);
            } elseif ($awardUpper === 'OROY') {
                $paragraphs[] = $this->pickOne([
                    "**Offensive Rookie of the Year: {$playerName}, {$teamName}**\n\nThe transition from college to the pros can humble even the most talented prospects. Not {$playerName}{$statLine}. From his very first snap, {$playerName} played with a poise and confidence that belied his age. The {$teamShort} found a special one, and the best is almost certainly yet to come.",
                    "**Offensive Rookie of the Year: {$playerName}, {$teamName}**\n\n{$playerName} made the leap look easy{$statLine}. Rookie walls, learning curves, the speed of the game — none of it fazed him. The {$teamShort} have a building block for the next decade, and this award is just the beginning of what figures to be a remarkable career.",
                ]);
            } elseif ($awardUpper === 'DROY') {
                $paragraphs[] = $this->pickOne([
                    "**Defensive Rookie of the Year: {$playerName}, {$teamName}**\n\n{$playerName} played like a veteran from day one{$statLine}. The {$teamShort} asked him to contribute immediately, and he exceeded every expectation. For a rookie to make this kind of impact on the defensive side of the ball is rare. The future is bright.",
                    "**Defensive Rookie of the Year: {$playerName}, {$teamName}**\n\nRookies are not supposed to play like this{$statLine}. {$playerName} was a disruptive force from the moment he stepped on the field, earning the respect of veterans and opponents alike. The {$teamShort} struck gold on draft day.",
                ]);
            } elseif ($awardUpper === 'COACH OF THE YEAR') {
                $record = !empty($team) ? ($team['wins'] ?? 0) . '-' . ($team['losses'] ?? 0) : '';
                $paragraphs[] = $this->pickOne([
                    "**Coach of the Year: {$playerName}, {$teamName}**\n\nThe {$teamShort} had no business being as good as they were, and that is a testament to {$playerName}'s coaching. A {$record} record was earned through superior preparation, in-game adjustments, and the ability to get the most out of every player on the roster. This award belongs to the entire coaching staff, but it starts at the top.",
                    "**Coach of the Year: {$playerName}, {$teamName}**\n\n{$playerName} guided the {$teamShort} to a {$record} record through masterful game-planning and a locker room culture built on accountability. When the talent said one thing and the results said another, it was clear: coaching made the difference. This team overachieved, and {$playerName} is the reason why.",
                ]);
            } else {
                $paragraphs[] = "**{$awardName}: {$playerName}, {$teamName}**\n\n{$playerName} earned the {$awardName} through a season of consistent, high-level play{$statLine}. The {$teamShort} leaned on {$playerName} heavily this season, and time and again, the response was excellence.";
            }
        }

        // Closing paragraph
        $paragraphs[] = "Congratulations to all of this season's award winners. Their performances set the standard for the league and provided the kind of moments that make football the greatest game on earth.";

        $body = implode("\n\n", $paragraphs);

        $stmt = $this->db->prepare(
            "INSERT INTO articles (league_id, season_id, week, type, headline, body, author_name, author_persona, team_id, game_id, is_ai_generated, published_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)"
        );
        $stmt->execute([
            $leagueId, $seasonId, 0, 'awards',
            $headline, $body,
            'Dana Reeves', 'dana_reeves',
            null, null,
            $now,
        ]);

        // Ticker for MVP
        if ($mvp) {
            $mvpTeam = $mvp['team_id'] ? $this->getTeam($mvp['team_id']) : [];
            $abbr = $mvpTeam['abbreviation'] ?? '';
            $tickerText = "AWARDS: {$mvp['player_name']}" . ($abbr ? " ({$abbr})" : '') . " named league MVP";
            $stmt = $this->db->prepare(
                "INSERT INTO ticker_items (league_id, text, type, team_id, week, created_at) VALUES (?, ?, 'award', ?, ?, ?)"
            );
            $stmt->execute([$leagueId, $tickerText, $mvp['team_id'] ?? null, 0, $now]);
        }
    }

    /**
     * Format award stats into a readable string.
     */
    private function formatAwardStats(array $stats): string
    {
        if (empty($stats)) return '';

        $parts = [];
        if (isset($stats['pass_yards'])) $parts[] = number_format($stats['pass_yards']) . ' passing yards';
        if (isset($stats['pass_tds'])) $parts[] = $stats['pass_tds'] . ' touchdown passes';
        if (isset($stats['rush_yards'])) $parts[] = number_format($stats['rush_yards']) . ' rushing yards';
        if (isset($stats['rush_tds'])) $parts[] = $stats['rush_tds'] . ' rushing touchdowns';
        if (isset($stats['receptions'])) $parts[] = $stats['receptions'] . ' receptions';
        if (isset($stats['rec_yards'])) $parts[] = number_format($stats['rec_yards']) . ' receiving yards';
        if (isset($stats['rec_tds'])) $parts[] = $stats['rec_tds'] . ' receiving touchdowns';
        if (isset($stats['sacks'])) $parts[] = $stats['sacks'] . ' sacks';
        if (isset($stats['interceptions_def'])) $parts[] = $stats['interceptions_def'] . ' interceptions';
        if (isset($stats['tackles'])) $parts[] = $stats['tackles'] . ' tackles';
        if (isset($stats['forced_fumbles'])) $parts[] = $stats['forced_fumbles'] . ' forced fumbles';

        if (empty($parts)) return '';
        return ', posting ' . $this->joinList($parts);
    }

    // ─── Milestone Article ────────────────────────────────────────────

    /**
     * Generate an article for milestone events (clinch, elimination, streaks, career milestones).
     */
    public function generateMilestoneArticle(int $leagueId, int $seasonId, int $week, array $milestone): void
    {
        $now = date('Y-m-d H:i:s');
        $type = $milestone['type'] ?? '';
        $teamId = $milestone['team_id'] ?? null;
        $details = $milestone['details'] ?? '';
        $team = $teamId ? $this->getTeam($teamId) : [];
        $teamName = !empty($team) ? ($team['city'] . ' ' . $team['name']) : 'Unknown Team';
        $teamShort = !empty($team) ? $team['name'] : 'Unknown';
        $record = !empty($team) ? (($team['wins'] ?? 0) . '-' . ($team['losses'] ?? 0)) : '';

        $headline = '';
        $paragraphs = [];
        $columnist = self::COLUMNISTS['terry_hollis'];
        $columnistKey = 'terry_hollis';

        switch ($type) {
            case 'clinch_playoff':
                $columnist = self::COLUMNISTS['marcus_bell'];
                $columnistKey = 'marcus_bell';

                $headline = $this->pickOne([
                    "{$teamName} Clinch Playoff Berth",
                    "Postseason Bound: {$teamName} Punch Their Ticket",
                    "{$teamName} Lock Up Playoff Spot at {$record}",
                ]);

                $paragraphs[] = $this->pickOne([
                    "Pop the champagne. The {$teamName} have officially clinched a playoff berth, and the celebration in the locker room was well-earned. At {$record}, the {$teamShort} have punched their ticket to the postseason, validating a season of hard work and commitment to a winning culture.",
                    "The magic number hit zero, and the {$teamName} are playoff-bound. With a {$record} record, the {$teamShort} have secured their place in the postseason field, and now the real fun begins. This is a team that has been building toward this moment all season, and the satisfaction of hearing the playoff clinch announced was palpable.",
                ]);
                $paragraphs[] = $this->pickOne([
                    "For a franchise that has invested heavily in building a contender, this is validation. The {$teamShort} set out at the start of the season with postseason aspirations, and they have delivered. But make no mistake — clinching a spot is not the goal. It is just the beginning.",
                    "The {$teamShort} have not just clinched — they have earned it. Every win, every fourth-quarter stand, every gut-check game led to this moment. The players will savor tonight, but tomorrow the focus shifts to seeding, to home-field advantage, and to the ultimate prize.",
                ]);
                $paragraphs[] = "The question now is not whether the {$teamShort} will be in the playoffs, but how far they can go. " . ($details ?: "With the way this team has been playing, anything feels possible.");
                break;

            case 'eliminated':
                $columnist = self::COLUMNISTS['terry_hollis'];
                $columnistKey = 'terry_hollis';

                $headline = $this->pickOne([
                    "{$teamName}'s Season Effectively Over After Week {$week} Loss",
                    "Eliminated: {$teamName} Officially Out of Playoff Contention",
                    "Fade to Black: {$teamName}'s Postseason Hopes Dashed",
                ]);

                $paragraphs[] = $this->pickOne([
                    "The mathematical possibility may linger, but the reality is clear: the {$teamName}'s season is over. At {$record} through Week {$week}, the {$teamShort} have been eliminated from playoff contention, and an offseason of soul-searching begins now.",
                    "It is over for the {$teamName}. With a {$record} record and no path to the postseason, the {$teamShort} will be watching the playoffs from home. It is a bitter pill for a franchise that had higher expectations coming into the year.",
                ]);
                $paragraphs[] = $this->pickOne([
                    "There will be difficult conversations in the coming weeks. Roster evaluations, coaching assessments, draft positioning — the focus shifts from winning games to building for the future. The remaining games become about individual evaluation and developing young players.",
                    "The {$teamShort} now play for pride and for jobs. Every remaining snap is an audition, every game film a piece of evidence in the offseason evaluation. Some players are playing for their futures with this franchise. Others are playing for their next opportunity elsewhere.",
                ]);
                $paragraphs[] = "The fans deserve better, and the front office knows it. " . ($details ?: "Changes are coming. The only question is how sweeping those changes will be.");
                break;

            case 'win_streak':
                // Extract streak number from details
                preg_match('/(\d+)/', $details, $streakMatch);
                $streakNum = $streakMatch[1] ?? '5';

                if ((int)$streakNum < 5) return; // Only write about streaks of 5+

                $columnist = self::COLUMNISTS['marcus_bell'];
                $columnistKey = 'marcus_bell';

                $headline = $this->pickOne([
                    "{$teamName} Riding Red-Hot {$streakNum}-Game Win Streak",
                    "On Fire: {$teamName} Extend Win Streak to {$streakNum}",
                    "Nobody Can Stop the {$teamShort}: Win Streak Hits {$streakNum}",
                ]);

                $paragraphs[] = $this->pickOne([
                    "The {$teamName} cannot be stopped right now. Winners of {$streakNum} straight, the {$teamShort} have transformed from a good team into the hottest team in the league. This is the kind of run that changes the entire complexion of a season.",
                    "{$streakNum} in a row. Let that sink in. The {$teamName} have rattled off {$streakNum} consecutive victories, and each win seems more convincing than the last. This team has found something — a rhythm, a swagger, a belief — and opponents are running out of answers.",
                ]);
                $paragraphs[] = $this->pickOne([
                    "What makes this streak remarkable is not just the wins, but how the {$teamShort} are winning. Close games, blowouts, come-from-behind victories — they have done it all. This is a team that finds a way regardless of circumstance, and that adaptability is the hallmark of a genuine contender.",
                    "During this {$streakNum}-game tear, the {$teamShort} have shown the kind of resilience and depth that championship teams are made of. The offense is clicking, the defense is swarming, and the confidence is through the roof. When a team is playing like this, it is best to simply get out of the way.",
                ]);
                $paragraphs[] = "The rest of the league is on notice. The {$teamName} are coming, and right now, they look like the team to beat. " . ($details ?: '');
                break;

            case 'loss_streak':
                preg_match('/(\d+)/', $details, $streakMatch);
                $streakNum = $streakMatch[1] ?? '5';

                if ((int)$streakNum < 5) return;

                $columnist = self::COLUMNISTS['terry_hollis'];
                $columnistKey = 'terry_hollis';

                $headline = $this->pickOne([
                    "{$teamName} in Freefall: Losing Streak Hits {$streakNum}",
                    "Rock Bottom? {$teamName} Drop {$streakNum}th Straight",
                    "Crisis in {$team['city']}: {$teamShort} Lose {$streakNum} in a Row",
                ]);

                $paragraphs[] = $this->pickOne([
                    "{$streakNum} straight losses. There is no sugar-coating it, no silver lining to find, no moral victories to celebrate. The {$teamName} are in crisis, and the losing streak has exposed fundamental problems that go beyond any single game.",
                    "The {$teamName} have now lost {$streakNum} consecutive games, and the freefall shows no signs of stopping. What started as a rough patch has become a full-blown crisis, and the patience of everyone — from the front office to the fans — is being tested.",
                ]);
                $paragraphs[] = $this->pickOne([
                    "During this stretch, the {$teamShort} have been outscored, outcoached, and outcompeted. The issues are systemic. The offense cannot sustain drives. The defense cannot get off the field. Special teams have been a liability. When everything is broken, where do you even start fixing it?",
                    "The film from these {$streakNum} losses paints a damning picture. Missed assignments, mental errors, a lack of effort on critical plays — these are not the marks of a well-coached, disciplined football team. Something has to change, and it has to change immediately.",
                ]);
                $paragraphs[] = "Questions about the coaching staff, the roster construction, and the direction of this franchise are no longer premature. They are overdue. " . ($details ?: "The {$teamShort} need answers, and they need them fast.");
                break;

            case 'career_milestone':
                $columnist = self::COLUMNISTS['marcus_bell'];
                $columnistKey = 'marcus_bell';

                $headline = $details ?: "{$teamName} Player Reaches Career Milestone";

                $paragraphs[] = $this->pickOne([
                    "History was made. {$details} It is the kind of achievement that puts a career in perspective, a number so staggering that it demands we stop and appreciate what we have witnessed. Milestones like this do not happen by accident. They are the product of years of dedication, sacrifice, and an unwavering commitment to excellence.",
                    "{$details} In a league defined by turnover and short careers, reaching a milestone of this magnitude is a testament to sustained greatness. The kind of longevity and consistency required to achieve this number separates the good from the legendary.",
                ]);
                $paragraphs[] = $this->pickOne([
                    "Teammates and opponents alike tipped their caps. This is the kind of accomplishment that transcends team loyalty and rivalry. When a player reaches this level, the entire football community pauses to acknowledge greatness.",
                    "The celebration on the sideline told the story. Teammates mobbed the milestone achiever, coaches smiled ear to ear, and the crowd rose for a standing ovation. These are the moments that make sports special — the intersection of personal achievement and collective joy.",
                ]);
                $paragraphs[] = "When the career is over and the numbers are tallied, this milestone will stand as one of the defining moments. Football immortality is earned one play, one game, one season at a time, and today, another chapter was written.";
                break;

            default:
                return; // Unknown milestone type, skip
        }

        if (empty($headline) || empty($paragraphs)) return;

        $body = implode("\n\n", $paragraphs);

        $stmt = $this->db->prepare(
            "INSERT INTO articles (league_id, season_id, week, type, headline, body, author_name, author_persona, team_id, game_id, is_ai_generated, published_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)"
        );
        $stmt->execute([
            $leagueId, $seasonId, $week, 'milestone',
            $headline, $body,
            $columnist['name'], $columnistKey,
            $teamId, null,
            $now,
        ]);

        // Ticker item
        $tickerText = match ($type) {
            'clinch_playoff' => "CLINCHED: {$team['abbreviation']} secure playoff berth at {$record}",
            'eliminated' => "ELIMINATED: {$team['abbreviation']} out of playoff contention",
            'win_streak' => "STREAK: {$team['abbreviation']} have won {$streakNum} straight",
            'loss_streak' => "SKID: {$team['abbreviation']} have lost {$streakNum} straight",
            'career_milestone' => $details ?: "MILESTONE: {$team['abbreviation']} player reaches career milestone",
            default => '',
        };

        if ($tickerText) {
            $stmt = $this->db->prepare(
                "INSERT INTO ticker_items (league_id, text, type, team_id, week, created_at) VALUES (?, ?, 'milestone', ?, ?, ?)"
            );
            $stmt->execute([$leagueId, $tickerText, $teamId, $week, $now]);
        }
    }

    // ─── Ordinal Helper ───────────────────────────────────────────────

    /**
     * Convert a number to its ordinal string (1st, 2nd, 3rd, etc.)
     */
    private function ordinal(int $n): string
    {
        $s = ['th', 'st', 'nd', 'rd'];
        $v = $n % 100;
        return $n . ($s[($v - 20) % 10] ?? $s[$v] ?? $s[0]);
    }
}
