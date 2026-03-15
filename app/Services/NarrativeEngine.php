<?php

namespace App\Services;

use App\Database\Connection;

class NarrativeEngine
{
    private \PDO $db;

    // Columnist profiles — dramatically different voices
    private const COLUMNISTS = [
        'terry_hollis' => [
            'name' => 'Terry Hollis',
            'style' => 'old_school',
            'game_types' => ['defensive_battle', 'blowout', 'solid_win', 'back_and_forth'],
            'phrases' => [
                'lede' => [
                    'This was football the way it was meant to be played.',
                    'You win in the trenches, and that was never more evident than today.',
                    'Some games are won on talent. This one was won on will.',
                    'That is football. Right there. That is what this game is supposed to look like.',
                    'They came to play. Simple as that.',
                    'You can talk about schemes and analytics all you want. This game was won by the tougher team.',
                    'Sixty minutes. That is all you get. And one team made every single one of them count.',
                    'No gimmicks. No tricks. Just hard-nosed, physical football.',
                    'There is something beautiful about a team that plays the game the right way. We saw it today.',
                    'I have watched a lot of football in my life. This is the kind of game that sticks with you.',
                    'Some teams talk. Some teams play. Today we saw a team that plays.',
                    'You cannot simulate toughness. You either have it or you do not.',
                    'The scoreboard does not lie. The better team won today.',
                    'When both teams line up and one simply whips the other, there is nothing to dissect. It is football.',
                    'Call me old-fashioned, but I still believe you win football games in the trenches.',
                    'I will say this: that was a grown man performance today.',
                    'Strip away the noise and you are left with one thing — execution. That is what decided this game.',
                ],
                'praise_defense' => [
                    'The defense set the tone from the opening snap.',
                    'Fundamentally sound from start to finish.',
                    'That is how you play championship-caliber defense.',
                    'When your defense plays like that, you are going to win a lot of football games.',
                    'Physical. Disciplined. Relentless. That is how you describe that defensive performance.',
                    'They flew to the ball. Every single play. That is coaching.',
                    'You do not see that kind of gang tackling very often anymore.',
                    'The defense played angry today. There is no other word for it.',
                    'Eleven hats to the ball. That is fundamental football.',
                ],
                'praise_run' => [
                    'They imposed their will on the ground.',
                    'Old-fashioned, smash-mouth football.',
                    'You could see the fight leave the defense in the fourth quarter.',
                    'They ran it down their throats. There is no sugarcoating it.',
                    'That offensive line was mauling people at the point of attack.',
                    'When you can run the ball like that, you control the game. Period.',
                    'Four yards and a cloud of dust. That is how you demoralize a defense.',
                    'The running game set the table, and the offense feasted.',
                ],
                'criticism' => [
                    'There is no excuse for that kind of performance.',
                    'Discipline was nonexistent.',
                    'They were outcoached, outworked, and outplayed.',
                    'You cannot win games turning the ball over like that. That is football 101.',
                    'That is not going to cut it. Not in this league.',
                    'Somebody has to look in the mirror after a performance like that.',
                    'Sloppy. Undisciplined. That falls on the coaching staff.',
                    'I have seen high school teams with better fundamentals.',
                    'You cannot come out flat like that and expect to compete.',
                ],
                'transition' => [
                    'Here is what I know:',
                    'Look, I will keep this simple.',
                    'Let me tell you something.',
                    'Bottom line:',
                    'I have said it before and I will say it again.',
                    'You want to know the difference in this game?',
                ],
            ],
        ],
        'dana_reeves' => [
            'name' => 'Dana Reeves',
            'style' => 'analytical',
            'game_types' => ['shootout', 'solid_win', 'back_and_forth', 'defensive_battle', 'blowout', 'comeback', 'thriller'],
            'phrases' => [
                'lede' => [
                    'The numbers tell the story.',
                    'From an efficiency standpoint, this was a clinic.',
                    'The metrics painted a clear picture before halftime.',
                    'Strip away the emotion and look at the data — this result was predictable.',
                    'When you break down the efficiency metrics, the outcome was never really in doubt.',
                    'The underlying numbers suggested this was coming.',
                    'In a sport increasingly driven by analytics, this game was a case study.',
                    'The advanced metrics aligned perfectly with the final score.',
                    'Process over results, they say. Today, the process delivered.',
                    'The efficiency gap in this game was staggering by modern standards.',
                    'Sometimes the box score tells one story and the advanced metrics tell another. Today they agreed.',
                    'Football is a game of margins, and the margins in this one were decisive.',
                    'The data does not have a rooting interest. It simply shows what happened.',
                    'By every measurable metric, this game was decided well before the final whistle.',
                    'Expected points added, success rate, completion percentage — every indicator pointed the same direction.',
                ],
                'analysis' => [
                    'The expected points added differential was stark.',
                    'When you look at the drive efficiency numbers, this result was inevitable.',
                    'A completion percentage that high against man coverage is remarkable.',
                    'The success rate on early downs told the whole story.',
                    'Yards per attempt is the single most predictive quarterback metric, and the gap here was enormous.',
                    'The third-down conversion disparity was the clearest indicator of offensive superiority.',
                    'From a situational football standpoint, the efficiency metrics were lopsided.',
                    'When you factor in scoring efficiency per drive, the margin becomes even more pronounced.',
                    'The defensive pressure rate directly correlated with the offensive struggles.',
                    'Explosive play rate — plays of 20-plus yards — was the differentiator.',
                    'The time of possession numbers only tell part of the story. Drive success rate tells the rest.',
                ],
                'stat_intro' => [
                    'Consider the numbers:',
                    'The stat sheet speaks volumes:',
                    'Dig into the efficiency metrics and the picture becomes clear:',
                    'The data points paint a vivid picture:',
                    'Here is what the numbers say:',
                    'Let the metrics illustrate the point:',
                    'A deeper look at the box score reveals:',
                    'The underlying data is instructive:',
                    'By the numbers:',
                ],
                'comparison' => [
                    'For context, league average is roughly',
                    'To put that in perspective,',
                    'That figure ranks among the best single-game performances this season.',
                    'Those are numbers you typically see from elite units.',
                    'Historically, teams posting those kinds of numbers win at a rate above 80 percent.',
                ],
            ],
        ],
        'marcus_bell' => [
            'name' => 'Marcus Bell',
            'style' => 'narrative',
            'game_types' => ['comeback', 'thriller', 'back_and_forth', 'shootout'],
            'phrases' => [
                'lede' => [
                    'You could feel it building.',
                    'Sometimes a game becomes something more.',
                    'This was the kind of game you tell your grandchildren about.',
                    'The stadium had a heartbeat today, and it was racing.',
                    'There are games you watch, and there are games that watch you. This was the latter.',
                    'Memory is a funny thing. Most games blur together over the years. Not this one. This one you will remember.',
                    'If you left early, you missed everything. If you stayed, you witnessed something extraordinary.',
                    'Some games are contests. Some games are events. And then there are games like this.',
                    'You could write a novel about what happened today and still not capture all of it.',
                    'The air felt different. Before the first snap, before the anthem, you knew this one was going to be different.',
                    'Football, at its best, is a story. And this game told one for the ages.',
                    'Years from now, people will argue about where they were when this game happened.',
                    'There is a moment in every great game when you realize you are watching something special. That moment came early.',
                    'The crowd knew. Even before the snap, you could feel the stadium holding its collective breath.',
                    'Some afternoons just have magic in them. This was one.',
                    'Every so often, a game comes along that reminds you why you fell in love with football.',
                ],
                'emotion' => [
                    'The sideline erupted.',
                    'You could see the belief drain from their eyes.',
                    'Every fan in the stadium knew what was coming.',
                    'The roar was deafening. Not just loud — something primal.',
                    'Grown men were hugging on the sideline. Strangers in the stands were hugging.',
                    'You could hear the silence from the visiting side. It was louder than any cheer.',
                    'The bench cleared. Not in anger — in joy, in disbelief, in something close to gratitude.',
                    'Time seemed to stop for just a moment. And then the stadium exploded.',
                    'The quarterback just stood there for a moment, helmet off, staring at the scoreboard. Taking it in.',
                    'There were tears. On the field, in the stands. Nobody was embarrassed.',
                ],
                'hero' => [
                    'This was his moment, and he seized it.',
                    'Heroes are made in moments like these.',
                    'He carried an entire franchise on his shoulders today.',
                    'Some players are built for this. He is one of them.',
                    'When the lights are brightest, the great ones find another gear. He found two.',
                    'Every career has a defining game. This might have been his.',
                    'He did not just make plays. He made history.',
                    'There will be a time, years from now, when they talk about the day he became a legend.',
                    'He willed this team to victory. There is no other explanation.',
                    'In the crucible, you find out what someone is made of. Today, we found out.',
                ],
                'tension' => [
                    'The clock was bleeding. The margin was razor-thin. And then —',
                    'Fourth quarter. Single-digit lead. The kind of football that ages you.',
                    'Every play felt like a held breath.',
                    'The tension was thick enough to taste.',
                    'Nobody sat down for the entire fourth quarter. Nobody could.',
                    'This was the kind of game where you check the clock, check the score, and forget to breathe.',
                ],
                'quote_intro' => [
                    'After the game,',
                    'In the locker room,',
                    'Standing at the podium,',
                    'In the tunnel afterward,',
                    'With his jersey still soaked in sweat,',
                    'Before he even reached the locker room,',
                ],
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

    // ─── Team Recap Article (5-8 paragraphs, 300-500 words) ─────────────

    private function generateTeamRecap(array $game, array $c, int $seasonId, string $perspective, array $columnist): void
    {
        $isWinner = $perspective === 'winner';
        $team = $isWinner ? $c['winner'] : $c['loser'];
        $opponent = $isWinner ? $c['loser'] : $c['winner'];
        $star = $isWinner ? $c['winner_star'] : $c['loser_star'];
        $gameType = $c['game_class']['type'] ?? 'solid_win';

        $teamName = $team['city'] . ' ' . $team['name'];
        $oppName = $opponent['city'] . ' ' . $opponent['name'];

        // Pre-load secondary performers for richer articles
        $teamStats = $isWinner
            ? ($c['winner_is_home'] ? $c['home_stats'] : $c['away_stats'])
            : ($c['winner_is_home'] ? $c['away_stats'] : $c['home_stats']);
        $starId = $star ? (int)($star['id'] ?? 0) : null;
        $secondaryPerformers = $this->findSecondaryPerformers($teamStats, $starId);

        // Build the article using game-type specific structure
        $paragraphs = $this->buildArticleByGameType(
            $c, $isWinner, $teamName, $oppName, $team, $opponent,
            $star, $secondaryPerformers, $gameType, $columnist
        );

        $body = implode("\n\n", array_filter($paragraphs));

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

    /**
     * Build article paragraphs with structure determined by game type.
     * Each game type has a different dramatic arc.
     */
    private function buildArticleByGameType(
        array $c, bool $isWinner, string $teamName, string $oppName,
        array $team, array $opponent, ?array $star, array $secondaryPerformers,
        string $gameType, array $columnist
    ): array {
        $weather = $c['weather'] ?? 'clear';
        $weatherSentence = $this->formatWeatherAtmosphere($weather, $teamName, $columnist);
        $turningPoint = $c['turning_point'] ?? '';

        // Common paragraph generators
        $lede = $this->generateLede($c, $isWinner, $teamName, $oppName, $gameType, $columnist);
        if ($weatherSentence && !str_contains($lede, 'rain') && !str_contains($lede, 'snow') && !str_contains($lede, 'wind')) {
            $lede .= ' ' . $weatherSentence;
        }

        // Divisional rivalry injection into lede
        if ($c['is_divisional']) {
            $divPhrases = [
                " In a rivalry game that carried extra weight, the stakes were as high as the emotion.",
                " Division games always carry a different energy, and this one was no exception.",
                " With division supremacy on the line, both teams knew what was at stake.",
                " The rivalry added an edge to every snap, every whistle, every sideline conversation.",
            ];
            $lede .= $this->pickOne($divPhrases);
        }

        $starPara = $this->generateStarParagraph($star, $teamName, $isWinner, $columnist);
        $keySequence = $this->generateKeySequenceParagraph($c, $isWinner, $teamName, $oppName);
        $gameFlow = $this->generateGameFlowParagraph($c, $teamName, $oppName, $columnist);
        $matchup = $this->generateMatchupParagraph($c, $isWinner, $teamName, $oppName);
        $injuryPara = $this->generateInjuryParagraph($c, $team, $opponent, $isWinner, $columnist);
        $contextPara = $this->generateContextParagraph($c, $team, $opponent, $isWinner);
        $secondaryPara = $this->generateSecondaryPerformersParagraph($secondaryPerformers, $teamName, $isWinner, $columnist);
        $turningPointPara = $this->generateTurningPointParagraph($turningPoint, $c, $isWinner, $columnist);

        // Quote: always for narrative, 60% chance otherwise
        $quote = null;
        if ($columnist['style'] === 'narrative' || mt_rand(1, 100) <= 60) {
            $quote = $this->generateQuoteParagraph($star, $team, $isWinner, $gameType, $columnist);
        }

        // Build structure based on game type for unique dramatic arcs
        return match ($gameType) {
            'blowout' => $isWinner
                ? [$lede, $starPara, $gameFlow ?: $keySequence, $matchup, $secondaryPara, $contextPara, $quote]
                : [$lede, $gameFlow ?: $keySequence, $matchup, $starPara, $injuryPara, $contextPara, $quote],

            'comeback' => $isWinner
                ? [$lede, $gameFlow, $turningPointPara ?: $keySequence, $starPara, $matchup, $secondaryPara, $contextPara, $quote]
                : [$lede, $gameFlow, $turningPointPara ?: $keySequence, $starPara, $matchup, $contextPara, $quote],

            'thriller' => [$lede, $gameFlow ?: $keySequence, $starPara, $turningPointPara, $matchup, $injuryPara, $contextPara, $quote],

            'shootout' => [$lede, $starPara, $gameFlow ?: $keySequence, $secondaryPara, $matchup, $contextPara, $quote],

            'defensive_battle' => [$lede, $matchup, $keySequence, $starPara, $gameFlow, $injuryPara, $contextPara, $quote],

            'back_and_forth' => [$lede, $gameFlow, $keySequence, $starPara, $turningPointPara, $matchup, $contextPara, $quote],

            default => [$lede, $starPara, $keySequence, $gameFlow, $matchup, $injuryPara, $secondaryPara, $contextPara, $quote],
        };
    }

    /**
     * Generate a paragraph about secondary performers (WR2, RB2, defensive standouts).
     */
    private function generateSecondaryPerformersParagraph(array $performers, string $teamName, bool $isWinner, array $columnist): ?string
    {
        if (count($performers) < 1) return null;

        $mentions = [];
        foreach ($performers as $p) {
            $s = $p['stats'];
            $pos = $p['position'];
            $name = $p['name'];

            if ($pos === 'QB' && ($s['pass_yards'] ?? 0) > 100) {
                $comp = $s['pass_completions'] ?? 0;
                $att = $s['pass_attempts'] ?? 0;
                $yds = $s['pass_yards'] ?? 0;
                $mentions[] = "{$name} went {$comp}-for-{$att} for {$yds} yards";
            } elseif ($pos === 'RB' && ($s['rush_yards'] ?? 0) > 30) {
                $mentions[] = "{$name} added {$s['rush_yards']} yards on the ground";
            } elseif (in_array($pos, ['WR', 'TE']) && ($s['receptions'] ?? 0) > 1) {
                $rec = $s['receptions'] ?? 0;
                $yds = $s['rec_yards'] ?? 0;
                $mentions[] = "{$name} chipped in {$rec} catches for {$yds} yards";
            } elseif (in_array($pos, ['DE', 'DT', 'LB']) && ($s['sacks'] ?? 0) > 0) {
                $mentions[] = "{$name} recorded " . ($s['sacks'] == 1 ? 'a sack' : "{$s['sacks']} sacks");
            } elseif (in_array($pos, ['CB', 'S']) && ($s['interceptions_def'] ?? 0) > 0) {
                $mentions[] = "{$name} came away with an interception";
            }
        }

        if (empty($mentions)) return null;

        $list = $this->joinList($mentions);

        $style = $columnist['style'] ?? 'old_school';
        return match ($style) {
            'old_school' => "It was not a one-man show. {$list}. That is the kind of complementary football that wins championships.",
            'analytical' => "The supporting cast contributed significantly: {$list}. The depth of production across the roster was notable.",
            'narrative' => "But this was more than a solo act. {$list}. When everyone contributes, when the whole is greater than the sum of its parts, that is when magic happens.",
            default => "Beyond the headline performers, {$list}.",
        };
    }

    /**
     * Generate a paragraph built around the turning_point narrative from SimEngine.
     */
    private function generateTurningPointParagraph(string $turningPoint, array $c, bool $isWinner, array $columnist): ?string
    {
        if (empty($turningPoint)) return null;

        $style = $columnist['style'] ?? 'old_school';

        return match ($style) {
            'old_school' => $this->pickOne([
                "The game turned on one sequence. {$turningPoint} That is how quickly momentum can shift in this league.",
                "If you want to know where this game was won and lost, look no further: {$turningPoint} That is the play. That is the moment.",
                "Football is a game of moments, and the decisive one came here: {$turningPoint}",
            ]),
            'analytical' => $this->pickOne([
                "The win probability model identified the inflection point clearly: {$turningPoint} From that moment, the expected outcome shifted dramatically.",
                "The swing play, by any metric, was straightforward to identify. {$turningPoint} The leverage of that moment was enormous.",
                "Isolate the highest-leverage play of the game and you find this: {$turningPoint}",
            ]),
            'narrative' => $this->pickOne([
                "And then came the moment that changed everything. {$turningPoint} The stadium felt it. The sideline felt it. Everyone watching at home felt it.",
                "There is always a hinge. A moment where the story could go one way or the other. This was it: {$turningPoint} After that, it was a different game.",
                "If this game were a novel, this would be the chapter break. {$turningPoint} Nothing was the same after that.",
                "You will replay this moment in your mind for weeks. {$turningPoint} That was the dagger. That was the turning point.",
            ]),
            default => "The turning point: {$turningPoint}",
        };
    }

    // ─── Paragraph Generators ──────────────────────────────────────────

    private function generateLede(array $c, bool $isWinner, string $teamName, string $oppName, string $gameType, array $columnist): string
    {
        $winScore = $c['win_score'];
        $loseScore = $c['lose_score'];
        $margin = $c['margin'];
        $week = $c['week'];
        $weekLabel = $this->weekLabel($week);
        $divisional = $c['is_divisional'] ? 'divisional ' : '';
        $combined = $winScore + $loseScore;
        $style = $columnist['style'] ?? 'old_school';

        // Voice-specific ledes: each columnist sees the same game differently
        if ($isWinner) {
            $ledes = match ($gameType) {
                'blowout' => match ($style) {
                    'old_school' => [
                        "It was never close. The {$teamName} dismantled the {$oppName} {$winScore}-{$loseScore} in a rout that was effectively over by halftime.",
                        "The {$teamName} left no doubt in a commanding {$winScore}-{$loseScore} victory. This was a thorough, systematic demolition.",
                        "Dominant does not begin to describe it. The {$teamName} put on a clinic in their {$winScore}-{$loseScore} destruction of the {$oppName}.",
                        "That is a statement win. The {$teamName} walked into this game and took it from the {$oppName}, {$winScore}-{$loseScore}. It was men against boys.",
                        "The {$teamName} played their most complete game of the season. A {$winScore}-{$loseScore} whipping of the {$oppName} that sends a message to the rest of the league.",
                        "I do not know what the {$oppName} expected to accomplish today, but I know what the {$teamName} did — they beat them up, {$winScore}-{$loseScore}.",
                        "Sometimes in this league, you just run into a buzzsaw. The {$oppName} ran into one today, falling {$winScore}-{$loseScore} to a {$teamName} team that was not interested in a close game.",
                        "From whistle to whistle, the {$teamName} were the better team. {$winScore}-{$loseScore}. Not even close.",
                        "The final score was {$winScore}-{$loseScore}, and that was flattering to the {$oppName}. The {$teamName} were dominant in every phase.",
                        "Physical. Relentless. Decisive. The {$teamName} steamrolled the {$oppName} {$winScore}-{$loseScore} in a performance that should terrify their next opponent.",
                        "The {$teamName} scored early, scored often, and never looked back. A {$margin}-point victory that was never in doubt.",
                        "An absolute mismatch. The {$teamName} dismantled the {$oppName} in a {$winScore}-{$loseScore} rout that had the starters resting by the fourth quarter.",
                        "This was a championship-caliber performance. The {$teamName} buried the {$oppName} {$winScore}-{$loseScore} with a display of precision and power.",
                        "The {$oppName} came in with a game plan. The {$teamName} tore it apart. {$winScore}-{$loseScore}.",
                        "Every once in a while, a game is so one-sided that the only question is how bad the final margin will be. This was one of those games. {$teamName} {$winScore}, {$oppName} {$loseScore}.",
                    ],
                    'analytical' => [
                        "The efficiency gap was enormous from the opening drive. The {$teamName}'s {$winScore}-{$loseScore} demolition of the {$oppName} was less a competitive game and more a statistical anomaly.",
                        "By every measurable metric, the {$teamName}'s {$winScore}-{$loseScore} victory over the {$oppName} was the most lopsided performance of the week. The expected points added differential tells the story.",
                        "A {$margin}-point margin of victory understates how dominant the {$teamName} were. The success rate, conversion efficiency, and yards-per-play numbers all pointed in one direction from the first quarter.",
                        "The {$teamName} produced a {$winScore}-{$loseScore} outcome that the models would have given roughly a 4% pre-game probability. Complete domination.",
                        "From a process standpoint, the {$teamName} executed at an elite level in all three phases. A {$winScore}-{$loseScore} result that was inevitable by halftime.",
                        "The {$teamName} posted a {$winScore}-{$loseScore} win that graded out as one of the most efficient single-game performances in the league this season.",
                        "Strip the emotion and look at the data — the {$teamName}'s {$margin}-point victory was a systematic dismantling, not a fluke. Every underlying metric confirms the dominance.",
                        "When one team outperforms the other by this magnitude — {$winScore}-{$loseScore} — the underlying process metrics are typically even more lopsided. That was the case here.",
                        "The {$teamName} controlled the win probability from the opening snap. Their {$winScore}-{$loseScore} rout of the {$oppName} never saw the win probability dip below 70% after the first drive.",
                        "A {$winScore}-{$loseScore} outcome. The advanced metrics corroborate the eye test: the {$teamName} were superior in every phase.",
                    ],
                    default => [
                        "It was never close. The {$teamName} dismantled the {$oppName} in a {$winScore}-{$loseScore} rout that was effectively over by halftime.",
                        "The {$teamName} left no doubt. {$winScore}-{$loseScore}. A thorough destruction that had the outcome settled before the fourth quarter began.",
                        "Somewhere in the first half, this stopped being a game and started being a statement. The {$teamName} buried the {$oppName} {$winScore}-{$loseScore}.",
                        "The scoreboard said {$winScore}-{$loseScore}. The field said it was worse than that. The {$teamName} overwhelmed the {$oppName} in every conceivable way.",
                        "From the moment the {$teamName} struck first, the {$oppName} never had a chance. {$winScore}-{$loseScore}. Dominance.",
                        "This was supposed to be a football game. Instead, it was a coronation. The {$teamName} dismantled the {$oppName}, {$winScore}-{$loseScore}.",
                        "The {$oppName} probably want to burn the tape. The {$teamName} rolled to a {$winScore}-{$loseScore} victory that got out of hand early and never looked back.",
                        "There was a game scheduled between the {$teamName} and the {$oppName}. What actually happened was a {$winScore}-{$loseScore} demolition.",
                        "The {$teamName} made the {$oppName} look like they did not belong on the same field. A commanding {$winScore}-{$loseScore} victory that leaves no room for debate.",
                        "In a game the {$teamName} controlled from start to finish, the {$oppName} were left searching for answers after a {$winScore}-{$loseScore} thrashing.",
                    ],
                },
                'comeback' => match ($style) {
                    'old_school' => [
                        "Left for dead in the fourth quarter, the {$teamName} authored one of the most dramatic comebacks of the season, rallying to defeat the {$oppName} {$winScore}-{$loseScore}.",
                        "They were down, but never out. The {$teamName} stormed back to stun the {$oppName} {$winScore}-{$loseScore} in a {$divisional}showdown that will be remembered for a long time.",
                        "Write the obituary, tear it up, and start over. The {$teamName} erased a late deficit and escaped with a {$winScore}-{$loseScore} comeback victory.",
                        "The {$teamName} showed toughness today. Real toughness. Down late and facing elimination from the game, they dug in and found a way. {$winScore}-{$loseScore}.",
                        "That is a character win. The {$teamName} were on the ropes, and they answered. You cannot teach that. {$winScore}-{$loseScore} over the {$oppName}.",
                        "I have seen a lot of comebacks. This one had something different. The {$teamName} looked dead, and they simply refused to stay down. {$winScore}-{$loseScore}.",
                        "You want to know what a team is made of? Watch what they do when they are down. The {$teamName} showed you today. {$winScore}-{$loseScore} comeback.",
                        "There was a point in the fourth quarter where you could have turned this game off. You would have missed everything. The {$teamName} rallied to beat the {$oppName} {$winScore}-{$loseScore}.",
                        "Resilience. That is the word. The {$teamName} were staring at a loss, and they chose to do something about it. {$winScore}-{$loseScore}.",
                        "Do not count this {$teamName} team out. They proved that today, storming back from a deficit to beat the {$oppName} {$winScore}-{$loseScore}.",
                    ],
                    'narrative' => [
                        "The stadium was emptying. The {$oppName} were celebrating on the sideline. And then the {$teamName} decided this story needed a different ending. {$winScore}-{$loseScore}.",
                        "Comebacks are not born in a single play. They are born in a look — a glance in the huddle that says 'not today.' The {$teamName} had that look. And they rode it to a {$winScore}-{$loseScore} victory that defied logic.",
                        "If you left early, you missed everything. If you stayed, you witnessed something extraordinary. The {$teamName} {$winScore}, the {$oppName} {$loseScore}, and a story they will be telling for years.",
                        "Down. Out. Done. And then — magic. The {$teamName} authored a {$winScore}-{$loseScore} comeback that belongs in a movie, not a box score.",
                        "This was the game that became a legend. Down late to the {$oppName}, the {$teamName} summoned something from deep within and walked off with a {$winScore}-{$loseScore} victory that will define their season.",
                        "The {$oppName} had this game in their hands. They could feel the victory. And then the {$teamName} ripped it away. {$winScore}-{$loseScore}. Stunning.",
                        "There is a moment in every great comeback where belief sparks. For the {$teamName}, it lit a fire that the {$oppName} could not extinguish. Final: {$winScore}-{$loseScore}.",
                        "Football can break your heart. It can also mend it. Today, the {$teamName} experienced both — the despair of falling behind and the euphoria of a {$winScore}-{$loseScore} comeback.",
                    ],
                    default => [
                        "The {$teamName} were trailing in the fourth quarter. The probability models had all but written them off. And then the data shifted — rapidly, improbably — in a {$winScore}-{$loseScore} comeback.",
                        "Win probability bottomed out below 15% in the fourth quarter for the {$teamName}. They won anyway. {$winScore}-{$loseScore} over the {$oppName}.",
                        "A fourth-quarter rally erased a multi-score deficit as the {$teamName} stunned the {$oppName} {$winScore}-{$loseScore}. The conversion and scoring efficiency in the final frame was remarkable.",
                        "By the numbers, the {$teamName}'s comeback was extraordinary. Trailing late, they executed at a level far above their season averages to pull out a {$winScore}-{$loseScore} victory.",
                        "The {$teamName} entered the fourth quarter with single-digit win probability. They exit with a {$winScore}-{$loseScore} victory. The efficiency swing was unprecedented.",
                    ],
                },
                'thriller' => [
                    "It came down to the final possession, and the {$teamName} made the plays that mattered most to edge the {$oppName} {$winScore}-{$loseScore}.",
                    "In a game that could have gone either way, the {$teamName} found just enough to hold off the {$oppName} {$winScore}-{$loseScore}.",
                    "Nail-biter. Heart-stopper. The {$teamName} survived a {$winScore}-{$loseScore} {$divisional}battle with the {$oppName} that was not decided until the final moments.",
                    "A {$margin}-point margin. That is all that separated the {$teamName} and the {$oppName} in a {$winScore}-{$loseScore} classic.",
                    "The {$teamName} needed every last second, every last yard, to escape with a {$winScore}-{$loseScore} victory over the {$oppName}.",
                    "One play. One moment. That is all that decided this {$winScore}-{$loseScore} battle between the {$teamName} and {$oppName}.",
                    "If you enjoy peaceful Sunday afternoons, this was not the game for you. The {$teamName} edged the {$oppName} {$winScore}-{$loseScore} in a white-knuckle affair.",
                    "The {$teamName} survived. That is the only word for it. A {$winScore}-{$loseScore} victory over the {$oppName} that was in doubt until the final whistle.",
                    "For sixty minutes, neither team blinked. The {$teamName} made the last play that mattered in a {$winScore}-{$loseScore} thriller.",
                    "Both teams left everything on the field. The {$teamName} just happened to leave with the victory, {$winScore}-{$loseScore}.",
                    "Close games reveal character. The {$teamName} showed theirs in a {$winScore}-{$loseScore} win over a game {$oppName} team.",
                    "You could not write a more dramatic ending. The {$teamName} escaped with a {$winScore}-{$loseScore} win that came down to the final drive.",
                    "Every play in the fourth quarter felt like the last play. The {$teamName} emerged from the chaos with a {$winScore}-{$loseScore} victory.",
                    "Football at its finest. The {$teamName} and {$oppName} staged a {$winScore}-{$loseScore} classic, with the {$teamName} making one more play.",
                    "The margin between victory and defeat: {$margin} points. The {$teamName} were on the right side of it, beating the {$oppName} {$winScore}-{$loseScore}.",
                ],
                'shootout' => [
                    "Defense was optional in a wild {$winScore}-{$loseScore} affair. The {$teamName} outscored the {$oppName} in a game that featured {$combined} combined points.",
                    "The {$teamName} outgunned the {$oppName} in a {$winScore}-{$loseScore} offensive showcase.",
                    "Points came in bunches, and the {$teamName} scored more of them. A {$winScore}-{$loseScore} shootout against the {$oppName}.",
                    "If you love offense, this was your game. The {$teamName} won a wild one, {$winScore}-{$loseScore}, in a game where defenses were afterthoughts.",
                    "The {$teamName} and {$oppName} combined for {$combined} points in a {$winScore}-{$loseScore} fireworks show that will give defensive coordinators nightmares.",
                    "Both offenses were unstoppable. Both defenses were invisible. The {$teamName} outpaced the {$oppName} in a {$winScore}-{$loseScore} track meet.",
                    "Touchdowns rained down in a {$winScore}-{$loseScore} shootout that the {$teamName} ultimately survived against the {$oppName}.",
                    "It was an old-fashioned scoring duel, and the {$teamName} had the last word — {$winScore}-{$loseScore} over the {$oppName}.",
                    "The {$combined} combined points tell you everything about this game. The {$teamName} won a shootout, {$winScore}-{$loseScore}.",
                    "Neither team could get a stop. The {$teamName} just scored one more time in a {$winScore}-{$loseScore} barn burner.",
                    "Defensive coordinators look away — this {$winScore}-{$loseScore} shootout between the {$teamName} and {$oppName} was all about the offenses.",
                    "An offensive fireworks show ended with the {$teamName} on top, {$winScore}-{$loseScore}, in a game where punts were an endangered species.",
                ],
                'defensive_battle' => [
                    "It was not pretty, but it was effective. The {$teamName} ground out a {$winScore}-{$loseScore} victory over the {$oppName}.",
                    "In a game defined by defense, the {$teamName} made just enough plays to escape with a {$winScore}-{$loseScore} win.",
                    "This was a rock fight, and the {$teamName} threw the last punch. {$winScore}-{$loseScore} over the {$oppName}.",
                    "Offense was a luxury neither team could afford. The {$teamName} prevailed {$winScore}-{$loseScore} in a defensive slugfest.",
                    "Every yard was earned. Every point was precious. The {$teamName} outlasted the {$oppName} {$winScore}-{$loseScore} in a game that belonged to the defenses.",
                    "If you appreciate the art of defense, this was a masterpiece. The {$teamName} won a {$winScore}-{$loseScore} chess match against the {$oppName}.",
                    "The final score read {$winScore}-{$loseScore}. Both defenses deserve credit. The {$teamName} get the win.",
                    "This was football in its purest form — hard hits, short fields, and a {$winScore}-{$loseScore} final that both defenses can be proud of.",
                    "A {$combined}-point game. In today's NFL, that is almost unheard of. The {$teamName} and {$oppName} played a throwback, and the {$teamName} came out on top.",
                    "Old-school football. The {$teamName} won a defensive struggle, {$winScore}-{$loseScore}, in a game where a field goal felt like a touchdown.",
                    "Smashmouth football. {$winScore}-{$loseScore}. The {$teamName} outlasted the {$oppName} in a bruising, low-scoring battle of wills.",
                ],
                'back_and_forth' => [
                    "The lead changed hands multiple times, but the {$teamName} had the final say — {$winScore}-{$loseScore} over the {$oppName}.",
                    "Neither team could pull away. The {$teamName} made the last run count, defeating the {$oppName} {$winScore}-{$loseScore}.",
                    "Back and forth, punch and counterpunch. The {$teamName} landed the last blow in a {$winScore}-{$loseScore} victory.",
                    "Every time the {$oppName} grabbed the lead, the {$teamName} grabbed it back. Final: {$winScore}-{$loseScore}.",
                    "If this game were a boxing match, it would go to the scorecards. The {$teamName} won the decision, {$winScore}-{$loseScore}.",
                    "Momentum swung like a pendulum in a {$winScore}-{$loseScore} {$divisional}battle. The {$teamName} made sure it swung their way last.",
                    "The {$teamName} and {$oppName} traded haymakers for sixty minutes. When the dust settled: {$winScore}-{$loseScore}, {$teamName}.",
                    "A seesaw affair that kept fans guessing until the end. The {$teamName} topped the {$oppName} {$winScore}-{$loseScore}.",
                    "Neither team wanted to lose this one. The {$teamName} simply wanted it more in the end, winning {$winScore}-{$loseScore}.",
                    "What a battle. The {$teamName} outlasted the {$oppName} {$winScore}-{$loseScore} in a game defined by momentum swings.",
                ],
                default => [
                    "The {$teamName} took care of business against the {$oppName}, earning a {$winScore}-{$loseScore} victory in {$weekLabel}.",
                    "A workmanlike {$winScore}-{$loseScore} win over the {$oppName} is exactly what the {$teamName} needed.",
                    "The {$teamName} handled the {$oppName} with a professional {$winScore}-{$loseScore} victory, controlling the game from start to finish.",
                    "Efficient. Controlled. Professional. The {$teamName} dispatched the {$oppName} {$winScore}-{$loseScore}.",
                    "No drama necessary. The {$teamName} took care of the {$oppName} {$winScore}-{$loseScore} in a game that went according to plan.",
                    "The {$teamName} did what good teams do — they handled their business. {$winScore}-{$loseScore} over the {$oppName}.",
                    "A solid {$winScore}-{$loseScore} victory for the {$teamName} over the {$oppName}. Nothing flashy, just a well-executed game plan.",
                    "The {$teamName} were the better team from start to finish in a {$winScore}-{$loseScore} win over the {$oppName}.",
                    "Business as usual for the {$teamName}. A crisp {$winScore}-{$loseScore} victory over the {$oppName} that never felt in doubt.",
                    "The {$teamName} played a clean, disciplined game and walked away with a {$winScore}-{$loseScore} win over the {$oppName}.",
                    "Sometimes a win is just a win. The {$teamName} beat the {$oppName} {$winScore}-{$loseScore} and are already looking ahead.",
                    "A comfortable {$winScore}-{$loseScore} victory for the {$teamName}, who controlled the tempo and the scoreboard against the {$oppName}.",
                    "The {$teamName} never trailed in a {$winScore}-{$loseScore} victory that was as straightforward as the final score suggests.",
                    "Nothing spectacular, nothing concerning. The {$teamName} posted a solid {$winScore}-{$loseScore} win over the {$oppName}.",
                    "The {$teamName} came in focused, executed their plan, and left with a {$winScore}-{$loseScore} victory. Another day at the office.",
                ],
            };
        } else {
            // Loser perspective ledes — also massively expanded
            $ledes = match ($gameType) {
                'blowout' => [
                    "There is no sugar-coating it. The {$teamName} were thoroughly outplayed in a {$winScore}-{$loseScore} loss to the {$oppName}.",
                    "It was an afternoon to forget for the {$teamName}, who were handed a {$winScore}-{$loseScore} defeat by the {$oppName}.",
                    "The {$teamName} had no answers in a {$winScore}-{$loseScore} loss to the {$oppName}. The film from this one will be difficult to watch.",
                    "From the opening snap, the {$teamName} were a step behind. The {$winScore}-{$loseScore} final was the ugly confirmation.",
                    "The {$teamName} were embarrassed on their own field. A {$winScore}-{$loseScore} shellacking at the hands of the {$oppName} that raises serious questions.",
                    "Rock bottom? It might be close. The {$teamName} absorbed a {$winScore}-{$loseScore} beating from the {$oppName} that was every bit as bad as the score suggests.",
                    "The {$teamName} never competed in this one. A {$winScore}-{$loseScore} loss to the {$oppName} that was over before halftime.",
                    "A {$margin}-point loss. There are no moral victories in a defeat this thorough. The {$teamName} were outclassed by the {$oppName}.",
                    "The {$teamName} came in with a game plan. The {$oppName} shredded it. {$winScore}-{$loseScore}.",
                    "The {$teamName} were non-competitive in a {$winScore}-{$loseScore} loss. Everything that could go wrong did go wrong.",
                    "If there is a silver lining in a {$winScore}-{$loseScore} loss, the {$teamName} have not found it yet.",
                    "A disaster from start to finish. The {$teamName} fell {$winScore}-{$loseScore} to the {$oppName} in a performance that demands answers.",
                    "The {$teamName} were outcoached, outplayed, and outclassed. {$winScore}-{$loseScore}. A loss that stings at every level.",
                    "Humbling does not begin to cover it. The {$teamName} were dismantled {$winScore}-{$loseScore} by a {$oppName} team that was simply better today.",
                ],
                'comeback' => [
                    "The {$teamName} let one slip away. Leading in the fourth quarter, they watched the {$oppName} mount a furious rally in a gut-wrenching {$winScore}-{$loseScore} defeat.",
                    "A game they should have won turned into a collapse. The {$teamName} could not hold their fourth-quarter lead and fell {$winScore}-{$loseScore}.",
                    "How do you lose a game you were winning with minutes to go? Ask the {$teamName}, who surrendered a lead and fell {$winScore}-{$loseScore} to the {$oppName}.",
                    "The {$teamName} were in control. And then they were not. A late collapse handed the {$oppName} a {$winScore}-{$loseScore} victory.",
                    "This loss is going to haunt the {$teamName}. They had a fourth-quarter lead, they had momentum, and they let the {$oppName} steal it. {$winScore}-{$loseScore}.",
                    "It slipped through their fingers. The {$teamName} led late and lost, falling {$winScore}-{$loseScore} to the {$oppName} in heartbreaking fashion.",
                    "The {$teamName} are left wondering what happened. A commanding lead evaporated in the fourth quarter as the {$oppName} rallied for a {$winScore}-{$loseScore} win.",
                    "Gut punch. The {$teamName} held a lead deep into the game and watched it dissolve. {$oppName} {$winScore}, {$teamName} {$loseScore}.",
                    "Fourth-quarter leads are supposed to be safe. Not this one. The {$teamName} surrendered theirs in a {$winScore}-{$loseScore} loss to the {$oppName}.",
                    "The {$teamName} did everything right for three quarters. Then the fourth quarter happened. A {$winScore}-{$loseScore} collapse.",
                ],
                'thriller' => [
                    "The {$teamName} came up just short in a {$winScore}-{$loseScore} loss to the {$oppName}. A {$margin}-point margin that will sting for days.",
                    "So close, yet so far. The {$teamName} gave everything but fell {$winScore}-{$loseScore} in a game decided by the smallest of margins.",
                    "A play here, a play there, and this is a different result. Instead, the {$teamName} take a {$winScore}-{$loseScore} loss.",
                    "The {$teamName} can hold their heads high after a {$winScore}-{$loseScore} loss, but moral victories do not show up in the standings.",
                    "One play short. The {$teamName} fell {$winScore}-{$loseScore} to the {$oppName} in a game that will replay in their minds all week.",
                    "The {$teamName} fought, scratched, and clawed, but a {$winScore}-{$loseScore} loss to the {$oppName} is still a loss.",
                    "Sometimes you do almost everything right and still lose. That is what happened to the {$teamName} today. {$winScore}-{$loseScore}.",
                    "Heartbreak for the {$teamName}. A {$winScore}-{$loseScore} defeat by the slimmest of margins.",
                    "The {$teamName} were right there. Right on the doorstep. But the {$oppName} slammed it shut, {$winScore}-{$loseScore}.",
                    "A {$margin}-point loss. In a game this tight, a single play decides everything. Today it decided against the {$teamName}.",
                ],
                'shootout' => [
                    "The {$teamName} put up a fight, but came out on the wrong end of a {$winScore}-{$loseScore} shootout.",
                    "Despite a prolific offensive performance, the {$teamName} could not keep pace in a {$winScore}-{$loseScore} defeat.",
                    "The offense did its part. The defense did not. The {$teamName} fell {$winScore}-{$loseScore} in a scoring bonanza.",
                    "When you score {$loseScore} points and still lose, the finger points squarely at the defense. The {$teamName} fell {$winScore}-{$loseScore}.",
                    "The {$teamName} traded touchdowns with the {$oppName} all afternoon but came up short in a {$winScore}-{$loseScore} shootout.",
                    "It was a wild ride that ended in disappointment for the {$teamName}. A {$winScore}-{$loseScore} shootout loss.",
                    "{$loseScore} points should be enough to win. It was not today. The {$teamName} fell in a {$winScore}-{$loseScore} offensive circus.",
                    "You can not give up {$winScore} points and expect to win. The {$teamName} learned that the hard way.",
                ],
                'defensive_battle' => [
                    "The {$teamName}'s defense kept them in it, but the offense could not generate enough in a {$winScore}-{$loseScore} loss.",
                    "The {$teamName} could not find the end zone often enough in a {$winScore}-{$loseScore} defensive grind.",
                    "The defense did its job. The offense did not hold up its end. The {$teamName} fell {$winScore}-{$loseScore}.",
                    "In a game this tight, the {$teamName} needed one more drive to go right. It never came. {$winScore}-{$loseScore}.",
                    "The {$teamName} played solid defense but could not solve the {$oppName}'s unit on the other side. A {$winScore}-{$loseScore} loss.",
                    "When points are this scarce, every possession matters. The {$teamName} wasted too many of theirs. {$winScore}-{$loseScore}.",
                    "A low-scoring affair went the wrong way for the {$teamName}, who fell {$winScore}-{$loseScore} to the {$oppName}.",
                ],
                default => [
                    "The {$teamName} fell to the {$oppName} {$winScore}-{$loseScore}, a loss that raises questions about the direction of this team.",
                    "It was a step backward for the {$teamName}, who dropped a {$winScore}-{$loseScore} decision to the {$oppName}.",
                    "The {$teamName} came out flat in a {$winScore}-{$loseScore} loss to the {$oppName}, never quite finding their rhythm.",
                    "Another loss for the {$teamName}. A {$winScore}-{$loseScore} defeat to the {$oppName} that felt all too familiar.",
                    "The {$teamName} could not get out of their own way in a {$winScore}-{$loseScore} loss to the {$oppName}.",
                    "A forgettable performance from the {$teamName}, who fell {$winScore}-{$loseScore} to the {$oppName}.",
                    "The {$teamName} were second-best in a {$winScore}-{$loseScore} loss. They know it. The {$oppName} know it. Everyone watching knew it.",
                    "Inconsistency continues to plague the {$teamName}, who dropped a {$winScore}-{$loseScore} contest to the {$oppName}.",
                    "Nothing went according to plan for the {$teamName} in a {$winScore}-{$loseScore} defeat.",
                    "The {$teamName} fell to {$winScore}-{$loseScore} against the {$oppName}, adding another chapter to a frustrating stretch.",
                    "The {$teamName} needed a strong performance. They got a {$winScore}-{$loseScore} loss instead.",
                    "A {$winScore}-{$loseScore} defeat. The {$teamName} will regroup, but the margin for error is shrinking.",
                ],
            };
        }

        return $this->pickOne($ledes);
    }

    private function generateStarParagraph(?array $star, string $teamName, bool $isWinner, array $columnist): string
    {
        if (!$star) {
            $noStarOptions = $isWinner
                ? [
                    "The {$teamName}'s victory was a true team effort, with contributions up and down the roster.",
                    "No single player dominated the stat sheet, but the {$teamName} won because everyone did their job.",
                    "This was a collective effort. The {$teamName} did not need a hero — they had 53 men pulling in the same direction.",
                    "You will not find one name that carried the {$teamName} today. You will find an entire roster that played winning football.",
                ]
                : [
                    "No one player could lift the {$teamName} out of this one, as the team struggled to find a rhythm.",
                    "The {$teamName} lacked a go-to playmaker when they needed one most. Nobody stepped up.",
                    "When the game was there to be won, the {$teamName} could not find a player to seize the moment.",
                ];
            return $this->pickOne($noStarOptions);
        }

        $name = $star['first_name'] . ' ' . $star['last_name'];
        $lastName = $star['last_name'];
        $stats = $star['game_stats'] ?? [];
        $pos = $star['position'];
        $statLine = $this->formatDetailedStatLine($star);
        $style = $columnist['style'] ?? 'old_school';

        // Massively expanded position-specific verb phrases
        $positionVerb = match ($pos) {
            'QB' => $this->pickOne([
                'was surgical from the pocket',
                'orchestrated the offense with precision',
                'put on a passing clinic',
                'was in complete command of the offense',
                'picked apart the defense with ruthless efficiency',
                'delivered strikes all over the field',
                'looked like the best quarterback in football today',
                'shredded the coverage at every level',
                'made it look easy, threading needles all afternoon',
                'operated the offense like a machine',
                'was dialed in from the first snap to the last',
                'carved up the defense with pinpoint accuracy',
                'turned every dropback into a work of art',
                'moved through his progressions and dissected the coverage',
                'was untouchable in the pocket',
            ]),
            'RB' => $this->pickOne([
                'was a force between the tackles',
                'ran with authority all day',
                'punished the defense on the ground',
                'could not be stopped on the ground',
                'ran through arm tackles and over linebackers',
                'found daylight on every carry',
                'left a trail of broken tackles behind him',
                'was the engine of the offense, rumbling for chunk plays all afternoon',
                'turned routine carries into explosive gains',
                'ran with a violence that demoralized the front seven',
                'made the defense pay for every missed tackle',
                'gashed the defense early and never let up',
                'was patient behind the line and explosive through the hole',
            ]),
            'WR' => $this->pickOne([
                'made himself uncoverable',
                'torched the secondary',
                'was the go-to target all afternoon',
                'ran routes that left defenders grasping at air',
                'could not be contained by any coverage the defense threw at him',
                'was electric after the catch, turning short throws into long gains',
                'owned every matchup he faced',
                'burned his man on deep routes and created separation underneath',
                'was the best player on the field, regardless of position',
                'won at every level of the route tree',
                'was a one-man wrecking crew for the passing game',
                'made contested catches look routine',
            ]),
            'TE' => $this->pickOne([
                'was a mismatch nightmare',
                'created problems all over the middle of the field',
                'was too big and too fast for the defense to handle',
                'exploited the seam like no one else in the league',
                'made linebackers look silly in coverage',
                'was the safety valve that became a weapon',
                'moved the chains time and again over the middle',
            ]),
            'DE', 'DT' => $this->pickOne([
                'wreaked havoc in the backfield',
                'was unblockable off the edge',
                'dominated the line of scrimmage',
                'terrorized the quarterback all day',
                'was a one-man wrecking crew up front',
                'blew up the pocket on nearly every passing down',
                'made life miserable for the offensive line',
                'came off the edge like his hair was on fire',
                'collapsed the pocket and disrupted everything',
                'was a force of nature on the defensive line',
                'was the most disruptive player on the field',
            ]),
            'LB' => $this->pickOne([
                'was everywhere',
                'flew sideline to sideline',
                'set the tone on defense',
                'made play after play',
                'was all over the field, finishing everything in sight',
                'was the quarterback of the defense and played like it',
                'sniffed out every play before it developed',
                'was physical in the run game and fluid in coverage',
                'played one of the most complete games you will see from a linebacker',
            ]),
            'CB', 'S' => $this->pickOne([
                'locked down his assignment',
                'was a ball hawk',
                'made the opposition pay for throwing his way',
                'shut down everything in his zone',
                'erased his side of the field completely',
                'jumped routes and made the quarterback pay',
                'played with the confidence of someone who knew he could not be beaten',
                'was in the right place at the right time all game',
                'blanketed receivers and took away half the field',
                'played a game that should be required viewing for every defensive back in the league',
            ]),
            default => 'delivered a standout performance',
        };

        if ($isWinner) {
            return match ($style) {
                'analytical' => $this->pickOne([
                    "{$name} {$positionVerb}, {$statLine}. Those are elite numbers by any measure, and they were the engine that drove the {$teamName}'s offense.",
                    "The star of this game, by the numbers: {$name}, {$statLine}. {$lastName}'s efficiency metrics were off the charts.",
                    "{$name} {$positionVerb}. The statistical output — {$statLine} — ranks among the top single-game performances at the position this season.",
                    "When you isolate {$name}'s contribution — {$statLine} — the impact on the {$teamName}'s expected scoring becomes clear. {$lastName} was the difference.",
                    "{$statLine}. That was {$name}'s afternoon, and those numbers were the foundation of everything the {$teamName} did offensively.",
                    "{$name} posted a performance that graded out at the highest level: {$statLine}. The efficiency was remarkable.",
                ]),
                'narrative' => $this->pickOne([
                    "{$name} {$positionVerb}. {$statLine}. When the moment called for a star, {$name} answered, carrying the {$teamName} on a performance that will not soon be forgotten.",
                    "There are games, and then there are performances. What {$name} did today — {$statLine} — transcended the ordinary. This was a player seizing the moment.",
                    "{$name}. Remember that name. {$statLine}. The kind of day that separates the good from the great.",
                    "When the {$teamName} needed someone to step into the spotlight, {$name} did not just step — he sprinted. {$statLine}. A star performance.",
                    "This was {$name}'s masterpiece. {$statLine}. Every catch, every yard, every play felt like it was building toward something special.",
                    "If you watched {$name} today — {$statLine} — you watched a player at the absolute peak of his powers.",
                ]),
                default => $this->pickOne([
                    "{$name} {$positionVerb}, {$statLine}. That is how you play this game. The {$teamName} go as {$name} goes, and today he was at his best.",
                    "{$name} {$positionVerb}. {$statLine}. Old-fashioned dominance at the position.",
                    "The {$teamName} leaned on {$name}, and he delivered: {$statLine}. When your best player is your best player, good things happen.",
                    "{$name} put the team on his back. {$statLine}. That is the kind of performance that makes everyone around you better.",
                    "Give {$name} the game ball. {$statLine}. He earned every bit of it.",
                    "{$name} was the best player on the field today. {$statLine}. It was not close.",
                ]),
            };
        } else {
            return $this->pickOne([
                "Despite the loss, {$name} {$positionVerb}, {$statLine}. But individual brilliance was not enough to overcome the {$teamName}'s collective struggles.",
                "{$name} did everything he could — {$statLine} — but it was not enough. The {$teamName} needed more help around their star.",
                "In a losing effort, {$name} {$positionVerb}, {$statLine}. He deserved a better outcome than the one the {$teamName} delivered.",
                "The one bright spot for the {$teamName}: {$name}, who {$positionVerb} with {$statLine}. Everything else needs work.",
                "{$name} kept the {$teamName} in it as long as he could. {$statLine}. But one player cannot do it alone.",
                "You cannot fault {$name}. {$statLine}. The rest of the {$teamName}, however, have some explaining to do.",
            ]);
        }
    }

    private function generateKeySequenceParagraph(array $c, bool $isWinner, string $teamName, string $oppName): string
    {
        $gameLog = $c['game_log'] ?? [];
        $turningPoint = $c['turning_point'] ?? '';

        if (empty($gameLog)) {
            if (!empty($turningPoint)) {
                return "The turning point: {$turningPoint}";
            }
            return $isWinner
                ? "The {$teamName} controlled the critical moments of the game."
                : "The {$teamName} could not capitalize when opportunities arose.";
        }

        // Find the top 2 key moments using the helper
        $moments = $this->extractKeyMoments($gameLog, 2);

        if (empty($moments)) {
            if (!empty($turningPoint)) return "The turning point: {$turningPoint}";
            return "The game was decided by the accumulation of small advantages rather than any single decisive play.";
        }

        // Describe the primary moment in detail
        $bestEntry = $moments[0];
        $primary = $this->describePlayMoment($bestEntry, $c);

        // If we have a second moment, add it for depth
        $secondary = '';
        if (count($moments) >= 2) {
            $secondEntry = $moments[1];
            $secondDesc = $this->describePlayMomentBrief($secondEntry, $c);
            if ($secondDesc) {
                $connectors = [
                    'Earlier in the game, ',
                    'That play came on the heels of another key moment: ',
                    'It was not the only pivotal sequence. ',
                    'The drama had been building all game. ',
                ];
                $secondary = ' ' . $this->pickOne($connectors) . $secondDesc;
            }
        }

        return $primary . $secondary;
    }

    /**
     * Describe a single play moment in rich, detailed prose.
     */
    private function describePlayMoment(array $entry, array $c): string
    {
        $quarter = match ($entry['quarter'] ?? 1) {
            1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth', 5 => 'overtime', default => 'fourth',
        };

        $clock = $entry['clock'] ?? 0;
        $timeStr = sprintf("%d:%02d", intdiv(max(0, $clock), 60), max(0, $clock) % 60);
        $note = $entry['note'] ?? '';
        $playerName = $entry['play']['player'] ?? 'the offense';
        $yards = abs($entry['play']['yards'] ?? 0);
        $playType = $entry['play']['type'] ?? '';
        $hs = $entry['home_score'] ?? 0;
        $as = $entry['away_score'] ?? 0;
        $possession = $entry['possession'] ?? 'home';
        $possTeam = $possession === 'home' ? ($c['home']['city'] . ' ' . $c['home']['name']) : ($c['away']['city'] . ' ' . $c['away']['name']);

        // Resolve player ID to name if available
        $playerId = $entry['play']['player_id'] ?? null;
        if ($playerId && is_numeric($playerId)) {
            $resolved = $this->getPlayerName((int)$playerId);
            if ($resolved !== 'Unknown') $playerName = $resolved;
        }

        if (str_contains($note, 'touchdown')) {
            $targetName = $entry['play']['target'] ?? null;
            $targetId = $entry['play']['target_id'] ?? null;
            if ($targetId && is_numeric($targetId)) {
                $resolved = $this->getPlayerName((int)$targetId);
                if ($resolved !== 'Unknown') $targetName = $resolved;
            }
            $depth = $entry['play']['depth'] ?? '';
            $depthDesc = $depth ? "{$depth} " : '';

            if ($playType === 'completion' && $targetName) {
                return $this->pickOne([
                    "With {$timeStr} left in the {$quarter} quarter, {$playerName} found {$targetName} on a {$yards}-yard {$depthDesc}strike for the score, making it {$hs}-{$as}. That connection shifted the momentum decisively.",
                    "The key sequence came with {$timeStr} remaining in the {$quarter}: {$playerName} dropped back, surveyed the field, and delivered a {$yards}-yard touchdown pass to {$targetName}. Just like that, it was {$hs}-{$as}.",
                    "The play that defined the game: {$timeStr} in the {$quarter} quarter, {$playerName} to {$targetName}, {$yards} yards, touchdown. {$hs}-{$as}. The sideline erupted.",
                    "{$playerName} connected with {$targetName} on a beautiful {$yards}-yard scoring strike with {$timeStr} left in the {$quarter}. The score moved to {$hs}-{$as}, and the entire complexion of the game changed.",
                    "With {$timeStr} on the {$quarter}-quarter clock, {$playerName} threaded the needle to {$targetName} for a {$yards}-yard touchdown. {$hs}-{$as}. That was the dagger.",
                ]);
            } else {
                return $this->pickOne([
                    "The decisive moment came with {$timeStr} left in the {$quarter} quarter when {$playerName} punched it in from {$yards} yards out, making it {$hs}-{$as}.",
                    "With {$timeStr} remaining in the {$quarter}, {$playerName} found the end zone on a {$yards}-yard run, pushing the score to {$hs}-{$as}. That was the play that broke the defense's spirit.",
                    "{$playerName} broke through for a {$yards}-yard touchdown with {$timeStr} left in the {$quarter}. At {$hs}-{$as}, the game had effectively been decided.",
                    "The play everyone will remember: {$playerName}, {$yards} yards, end zone, {$timeStr} remaining in the {$quarter}. {$hs}-{$as}.",
                ]);
            }
        } elseif (str_contains($note, 'Interception')) {
            $defenderName = $entry['play']['defender'] ?? 'the defense';
            $defenderId = $entry['play']['defender_id'] ?? null;
            if ($defenderId && is_numeric($defenderId)) {
                $resolved = $this->getPlayerName((int)$defenderId);
                if ($resolved !== 'Unknown') $defenderName = $resolved;
            }
            return $this->pickOne([
                "The game turned with {$timeStr} remaining in the {$quarter} when {$defenderName} jumped the route and came away with an interception. With the score {$hs}-{$as}, that turnover was devastating.",
                "With {$timeStr} left in the {$quarter} and the score at {$hs}-{$as}, {$defenderName} read the quarterback's eyes, broke on the ball, and picked it off. That interception changed everything.",
                "{$defenderName} made the play of the game — an interception with {$timeStr} remaining in the {$quarter} quarter. The score was {$hs}-{$as}, and {$possTeam} never recovered.",
                "The critical turnover came with {$timeStr} left in the {$quarter}: {$defenderName} jumped in front of the pass and came down with the interception. At {$hs}-{$as}, it was a back-breaker.",
            ]);
        } elseif (str_contains($note, 'Fumble')) {
            return $this->pickOne([
                "A fumble with {$timeStr} left in the {$quarter} quarter proved catastrophic. With the score {$hs}-{$as}, {$possTeam} coughed up the football and any chance at momentum with it.",
                "The ball hit the turf with {$timeStr} remaining in the {$quarter}. A fumble by {$possTeam} at the worst possible time, with the score {$hs}-{$as}.",
                "Turnover. {$possTeam} fumbled with {$timeStr} left in the {$quarter} and the score at {$hs}-{$as}. You cannot give the ball away in that situation.",
                "With the score {$hs}-{$as} and {$timeStr} left in the {$quarter}, {$possTeam} fumbled. The ball bounced free, and so did their hopes.",
            ]);
        } elseif (str_contains($note, 'Field goal good')) {
            $fgDist = $entry['play']['distance'] ?? $yards;
            return $this->pickOne([
                "A {$fgDist}-yard field goal with {$timeStr} remaining in the {$quarter} made it {$hs}-{$as} — a pivotal swing.",
                "The kicker drilled a {$fgDist}-yarder with {$timeStr} left in the {$quarter} to make it {$hs}-{$as}. In a game this tight, three points felt like seven.",
                "With {$timeStr} on the clock in the {$quarter} quarter, a {$fgDist}-yard field goal pushed the score to {$hs}-{$as}. Every point mattered.",
            ]);
        } elseif (str_contains($note, '4th down')) {
            return $this->pickOne([
                "With {$timeStr} left in the {$quarter} and the score at {$hs}-{$as}, {$possTeam} gambled on fourth down. That decision shaped the remainder of the contest.",
                "Fourth and short. {$timeStr} in the {$quarter}. Score: {$hs}-{$as}. {$possTeam} rolled the dice, and the result reverberated through the final minutes.",
                "The boldest call of the game came with {$timeStr} remaining in the {$quarter}: {$possTeam} went for it on fourth down with the score {$hs}-{$as}.",
            ]);
        }

        if (!empty($c['turning_point'])) {
            return "The turning point: {$c['turning_point']}";
        }

        return "The critical stretch of the game came in the {$quarter} quarter, when a sequence of plays with {$timeStr} remaining shifted the balance of the contest.";
    }

    /**
     * Brief description of a secondary play moment (1 sentence).
     */
    private function describePlayMomentBrief(array $entry, array $c): string
    {
        $quarter = match ($entry['quarter'] ?? 1) {
            1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth', 5 => 'overtime', default => 'fourth',
        };
        $note = $entry['note'] ?? '';
        $yards = abs($entry['play']['yards'] ?? 0);
        $playerName = $entry['play']['player'] ?? 'the offense';
        $playerId = $entry['play']['player_id'] ?? null;
        if ($playerId && is_numeric($playerId)) {
            $resolved = $this->getPlayerName((int)$playerId);
            if ($resolved !== 'Unknown') $playerName = $resolved;
        }

        if (str_contains($note, 'touchdown')) {
            $target = $entry['play']['target'] ?? null;
            $targetId = $entry['play']['target_id'] ?? null;
            if ($targetId && is_numeric($targetId)) {
                $resolved = $this->getPlayerName((int)$targetId);
                if ($resolved !== 'Unknown') $target = $resolved;
            }
            if ($target) {
                return "{$playerName} hit {$target} for a {$yards}-yard touchdown in the {$quarter} quarter.";
            }
            return "{$playerName} scored on a {$yards}-yard run in the {$quarter} quarter.";
        } elseif (str_contains($note, 'Interception')) {
            $defender = $entry['play']['defender'] ?? 'the defense';
            $defenderId = $entry['play']['defender_id'] ?? null;
            if ($defenderId && is_numeric($defenderId)) {
                $resolved = $this->getPlayerName((int)$defenderId);
                if ($resolved !== 'Unknown') $defender = $resolved;
            }
            return "{$defender} recorded an interception in the {$quarter} quarter.";
        } elseif (str_contains($note, 'Fumble')) {
            return "A {$quarter}-quarter fumble compounded the damage.";
        }

        return '';
    }

    private function generateMatchupParagraph(array $c, bool $isWinner, string $teamName, string $oppName): string
    {
        $homeUnits = $c['home_units'] ?? [];
        $awayUnits = $c['away_units'] ?? [];

        if (empty($homeUnits) || empty($awayUnits)) {
            $fallbackWin = [
                "The {$teamName} won the battle at the point of attack and that made all the difference.",
                "The {$teamName} controlled the line of scrimmage, and when you do that, you control the game.",
                "This game was won in the trenches. The {$teamName} were the more physical team, and it showed.",
            ];
            $fallbackLose = [
                "The {$teamName} were simply outmatched in the key areas of the game.",
                "The {$teamName} lost the battle up front, and everything else followed from there.",
                "When you cannot win at the point of attack, everything becomes harder. The {$teamName} found that out today.",
            ];
            return $this->pickOne($isWinner ? $fallbackWin : $fallbackLose);
        }

        // Find the biggest unit differential
        $winnerUnits = $c['winner_is_home'] ? $homeUnits : $awayUnits;
        $loserUnits = $c['winner_is_home'] ? $awayUnits : $homeUnits;

        $biggestDiff = 0;
        $biggestUnit = '';
        $unitComparisons = [
            'pass_offense' => ['winner' => $winnerUnits['pass_offense'] ?? 50, 'loser' => $loserUnits['pass_defense'] ?? 50],
            'run_offense' => ['winner' => $winnerUnits['run_offense'] ?? 50, 'loser' => $loserUnits['run_defense'] ?? 50],
            'pass_rush' => ['winner' => $winnerUnits['pass_rush'] ?? 50, 'loser' => $loserUnits['pass_block'] ?? 50],
            'pass_defense' => ['winner' => $winnerUnits['pass_defense'] ?? 50, 'loser' => $loserUnits['pass_offense'] ?? 50],
        ];

        foreach ($unitComparisons as $key => $comp) {
            $diff = abs($comp['winner'] - $comp['loser']);
            if ($diff > $biggestDiff) {
                $biggestDiff = $diff;
                $biggestUnit = $key;
            }
        }

        $wName = $isWinner ? $teamName : $oppName;
        $lName = $isWinner ? $oppName : $teamName;

        // Also pull some real stat data to enrich the matchup analysis
        $winStats = $c['winner_is_home'] ? $c['home_stats'] : $c['away_stats'];
        $loseStats = $c['winner_is_home'] ? $c['away_stats'] : $c['home_stats'];

        // Sum up team totals for context
        $winPassYds = 0; $winRushYds = 0; $losePassYds = 0; $loseRushYds = 0;
        $winSacks = 0; $loseSacks = 0;
        foreach ($winStats as $s) {
            $winPassYds += $s['pass_yards'] ?? 0;
            $winRushYds += $s['rush_yards'] ?? 0;
            $winSacks += $s['sacks'] ?? 0;
        }
        foreach ($loseStats as $s) {
            $losePassYds += $s['pass_yards'] ?? 0;
            $loseRushYds += $s['rush_yards'] ?? 0;
            $loseSacks += $s['sacks'] ?? 0;
        }
        $winTotalYds = $winPassYds + $winRushYds;
        $loseTotalYds = $losePassYds + $loseRushYds;

        if ($isWinner) {
            return match ($biggestUnit) {
                'pass_offense' => $this->pickOne([
                    "The {$wName}'s passing attack carved up the {$lName}'s secondary all day, racking up {$winPassYds} yards through the air. The {$lName} had no answer for the deep ball.",
                    "This game was decided through the air. The {$wName} piled up {$winPassYds} passing yards as receivers consistently found soft spots in the coverage.",
                    "The {$wName} aired it out for {$winPassYds} yards against a {$lName} secondary that had no answers. Route after route, throw after throw, the passing game was unstoppable.",
                    "With {$winPassYds} passing yards, the {$wName}'s aerial assault was the story of the game. The {$lName}'s defensive backs were chasing shadows.",
                    "The {$lName} game-planned for the run. They got the pass instead — {$winPassYds} yards of it — and could not adjust.",
                    "{$winPassYds} yards through the air. The {$wName}'s passing game dissected the {$lName}'s coverage at every level.",
                ]),
                'run_offense' => $this->pickOne([
                    "The {$wName} controlled the line of scrimmage and imposed their will on the ground, rushing for {$winRushYds} yards. The {$lName}'s front seven could not hold up.",
                    "It was a ground-and-pound afternoon for the {$wName}, who gashed the {$lName} for {$winRushYds} rushing yards. Holes opened, and the backs hit them hard.",
                    "The {$wName} ran for {$winRushYds} yards. That number tells you everything about who controlled this game at the line of scrimmage.",
                    "{$winRushYds} rushing yards. The {$wName}'s offensive line mauled the {$lName}'s front, creating lanes all game long.",
                    "The {$wName} ran it {$winRushYds} yards worth and did not stop until the clock hit zero. The {$lName} had no answer for the ground game.",
                    "When a team rushes for {$winRushYds} yards, the game plan is working. The {$wName}'s offensive line dominated.",
                ]),
                'pass_rush' => $this->pickOne([
                    "The {$wName}'s pass rush was relentless, recording {$winSacks} sacks and collapsing the pocket all game. The {$lName}'s offensive line was overmatched.",
                    "Pressure. That was the story. The {$wName} sacked the quarterback {$winSacks} times and disrupted everything the {$lName} tried to do through the air.",
                    "The {$wName} got home {$winSacks} times, and the constant pressure turned every dropback into an adventure for the {$lName}'s quarterback.",
                    "{$winSacks} sacks. Countless pressures. The {$wName}'s defensive front was in the backfield all afternoon, and the {$lName}'s offense never found its rhythm.",
                    "With {$winSacks} sacks and pressure on seemingly every passing down, the {$wName}'s front four was the difference in this game.",
                    "The {$wName} pinned their ears back and went after the quarterback. {$winSacks} sacks later, the {$lName} were a shell of themselves offensively.",
                ]),
                'pass_defense' => $this->pickOne([
                    "The {$wName}'s secondary locked down the {$lName}'s receiving corps, holding them to just {$losePassYds} passing yards.",
                    "Coverage was suffocating. The {$wName}'s defensive backs blanket-covered every route, limiting the {$lName} to {$losePassYds} yards through the air.",
                    "The {$lName} managed just {$losePassYds} passing yards against a {$wName} secondary that was in phase on nearly every snap.",
                    "{$losePassYds} passing yards. That is what the {$wName}'s coverage did to the {$lName}'s aerial attack. Receivers were blanketed all day.",
                    "The {$wName} took away the pass. {$losePassYds} yards allowed through the air. That kind of coverage wins football games.",
                ]),
                default => $this->pickOne([
                    "The {$wName} outgained the {$lName} {$winTotalYds} to {$loseTotalYds} in total yards. They won the critical matchup battles across the board.",
                    "A {$winTotalYds}-to-{$loseTotalYds} yardage advantage tells the story. The {$wName} were the better team in every phase.",
                    "The {$wName} dominated the stat sheet — {$winTotalYds} total yards to the {$lName}'s {$loseTotalYds} — and the scoreboard reflected it.",
                ]),
            };
        } else {
            return match ($biggestUnit) {
                'pass_offense' => $this->pickOne([
                    "The {$teamName}'s pass defense had no answer for what the {$oppName} brought. The {$oppName} threw for {$winPassYds} yards against a secondary that could not get a stop.",
                    "The {$teamName} allowed {$winPassYds} passing yards. That number is indefensible, and the secondary knows it.",
                    "The {$teamName}'s coverage was shredded to the tune of {$winPassYds} yards. When you cannot defend the pass, you cannot win.",
                ]),
                'run_offense' => $this->pickOne([
                    "The {$teamName}'s run defense was gashed for {$winRushYds} yards. The inability to set the edge and fill gaps let the {$oppName} control the clock and the game.",
                    "Give up {$winRushYds} rushing yards and you are going to lose. The {$teamName}'s front seven was pushed around all day.",
                    "The {$teamName} allowed {$winRushYds} rushing yards. The defensive front was overmatched and outwilled at the point of attack.",
                ]),
                'pass_rush' => $this->pickOne([
                    "The {$teamName}'s offensive line was overwhelmed. The {$oppName} recorded {$loseSacks} sacks, and the pressure was constant even when they did not get home.",
                    "Constant pressure disrupted the timing of the {$teamName}'s passing game. The {$oppName} sacked the quarterback {$loseSacks} times.",
                    "The {$teamName}'s quarterback was under siege all day — {$loseSacks} sacks and relentless pressure destroyed any offensive rhythm.",
                ]),
                'pass_defense' => $this->pickOne([
                    "The {$teamName}'s passing attack managed just {$losePassYds} yards against the {$oppName}'s suffocating coverage.",
                    "The {$teamName} could not move the ball through the air. {$losePassYds} passing yards is not going to cut it in this league.",
                    "{$losePassYds} passing yards. The {$teamName}'s receivers were blanketed, and the few windows that opened were slammed shut.",
                ]),
                default => $this->pickOne([
                    "The {$teamName} were outgained {$winTotalYds} to {$loseTotalYds} in total yards. They were outmatched in the key matchups.",
                    "A {$winTotalYds}-to-{$loseTotalYds} yardage deficit tells the story. The {$teamName} were outclassed.",
                ]),
            };
        }
    }

    private function generateContextParagraph(array $c, array $team, array $opponent, bool $isWinner): string
    {
        $winRecord = $c['winner']['wins'] . '-' . $c['winner']['losses'];
        $loseRecord = $c['loser']['wins'] . '-' . $c['loser']['losses'];
        $week = $c['week'];

        $teamRecord = $isWinner ? $winRecord : $loseRecord;

        $teamNameShort = $team['name'];
        $oppNameShort = $opponent['name'];
        $teamNameFull = $team['city'] . ' ' . $team['name'];

        // Varied base sentence for the record update
        $base = $this->pickOne([
            "With the result, the {$teamNameShort} move to {$teamRecord} on the season.",
            "The {$teamNameShort} are now {$teamRecord}.",
            "The win moves the {$teamNameShort} to {$teamRecord}." . ($isWinner ? '' : ''),
            "{$teamNameFull} sit at {$teamRecord} after {$week} weeks.",
        ]);
        if (!$isWinner) {
            $base = $this->pickOne([
                "The {$teamNameShort} fall to {$teamRecord} on the season.",
                "At {$teamRecord}, the {$teamNameShort} have work to do.",
                "The loss drops the {$teamNameShort} to {$teamRecord}.",
                "{$teamNameFull} sit at {$teamRecord} heading into Week " . ($week + 1) . ".",
            ]);
        }

        // Playoff implications — expanded
        $implications = '';
        if ($week >= 10) {
            $wins = (int)($team['wins'] ?? 0);
            $losses = (int)($team['losses'] ?? 0);

            if ($isWinner) {
                if ($wins >= 10) {
                    $implications = $this->pickOne([
                        " They are firmly in the playoff picture and look like a legitimate contender.",
                        " A postseason berth is all but secured, and this team has its sights set on something bigger.",
                        " At {$teamRecord}, the {$teamNameShort} are playing like a team with January ambitions.",
                    ]);
                } elseif ($wins >= 8) {
                    $implications = $this->pickOne([
                        " They are in strong position for a playoff berth.",
                        " The postseason picture is coming into focus, and the {$teamNameShort} like where they stand.",
                        " With {$wins} wins, the {$teamNameShort} control their own playoff destiny.",
                    ]);
                } elseif ($wins >= 6) {
                    $implications = $this->pickOne([
                        " They remain in the hunt for a postseason berth.",
                        " The playoff race is tight, but the {$teamNameShort} are still very much alive.",
                    ]);
                }
            } else {
                if ($losses >= 10) {
                    $implications = $this->pickOne([
                        " Their season is effectively over. The focus now shifts to next year.",
                        " Mathematically alive, perhaps, but realistically, the {$teamNameShort} are playing out the string.",
                    ]);
                } elseif ($losses >= 8) {
                    $implications = $this->pickOne([
                        " Their playoff hopes are all but extinguished.",
                        " The postseason is slipping away, and the {$teamNameShort} know it.",
                    ]);
                } elseif ($losses >= 6) {
                    $implications = $this->pickOne([
                        " Time is running out for a team that cannot afford many more losses.",
                        " The margin for error is gone. Every remaining game is a must-win.",
                        " The {$teamNameShort} are running out of runway to save their season.",
                    ]);
                }
            }
        }

        // Streak — expanded phrasing
        $streak = $team['streak'] ?? '';
        $streakNote = '';
        if (preg_match('/^W(\d+)$/', $streak, $m) && (int)$m[1] >= 3) {
            $n = (int)$m[1];
            $streakNote = $this->pickOne([
                " That is {$n} straight wins for a team playing with confidence.",
                " The {$teamNameShort} have now won {$n} in a row.",
                " Winners of {$n} consecutive games, the {$teamNameShort} are riding a hot streak.",
                " {$n} straight victories. This team is on a roll.",
            ]);
        } elseif (preg_match('/^L(\d+)$/', $streak, $m) && (int)$m[1] >= 3) {
            $n = (int)$m[1];
            $streakNote = $this->pickOne([
                " That is now {$n} consecutive losses, and the frustration is mounting.",
                " The losing streak extends to {$n} games. Something has to change.",
                " {$n} losses in a row. The skid continues for the {$teamNameShort}.",
                " That makes it {$n} straight defeats. The {$teamNameShort} are in freefall.",
            ]);
        }

        // Divisional note — expanded
        $divNote = '';
        if ($c['is_divisional']) {
            $divNote = $isWinner
                ? $this->pickOne([
                    " The division win could loom large come tiebreaker season.",
                    " A crucial divisional victory that strengthens their standing in the division race.",
                    " In a tight division, every head-to-head result matters. This one could be decisive.",
                ])
                : $this->pickOne([
                    " A division loss only makes the climb that much steeper.",
                    " Dropping a divisional game puts them at a significant disadvantage in the tiebreaker picture.",
                    " In the division standings, this loss could haunt the {$teamNameShort} come playoff time.",
                ]);
        }

        return $base . $implications . $streakNote . $divNote;
    }

    private function generateQuoteParagraph(?array $star, array $team, bool $isWinner, string $gameType, array $columnist): ?string
    {
        $teamName = $team['name'];
        $style = $columnist['style'] ?? 'old_school';

        // Determine who is being quoted — mix of star player and coach quotes
        $starName = $star ? ($star['first_name'] . ' ' . $star['last_name']) : null;
        $starLastName = $star ? $star['last_name'] : null;
        $isCoachQuote = !$starName || mt_rand(1, 100) <= 35;
        $name = $isCoachQuote ? 'head coach' : $starName;
        $attribution = $isCoachQuote ? 'the head coach said' : "{$starLastName} said";

        // Voice-specific attribution framing
        $intro = match ($style) {
            'narrative' => $this->pickOne([
                "In the locker room afterward, ",
                "Standing at the podium, still processing what happened, ",
                "Before the press conference even started, ",
                "With the confetti still in his hair, ",
                "In the hallway outside the locker room, ",
                "With teammates celebrating behind him, ",
            ]),
            'analytical' => $this->pickOne([
                "In the postgame press conference, ",
                "When asked about the performance, ",
                "Breaking down the game afterward, ",
            ]),
            default => $this->pickOne([
                "After the game, ",
                "In the press conference, ",
                "Postgame, ",
                "",
            ]),
        };

        if ($isWinner) {
            $quotes = match ($gameType) {
                'blowout' => [
                    "{$intro}\"We came out with the right mentality today. We executed the game plan and played our brand of football,\" {$attribution}.",
                    "{$intro}\"We prepared all week for this and it showed. Everyone did their job. That is what a complete performance looks like,\" {$attribution}.",
                    "{$intro}\"That is {$teamName} football right there. We came out and imposed our will from the first snap,\" {$attribution}.",
                    "{$intro}\"We talked all week about setting the tone early. I thought our guys did that. We were physical, we were disciplined, and it showed,\" {$attribution}.",
                    "{$intro}\"I do not want to get too high on this. We played well, but we have a lot of season left. We will enjoy this one and get back to work,\" {$attribution}.",
                    "{$intro}\"Our preparation was outstanding this week. The coaches had a great game plan, and the players executed it. You cannot ask for more than that,\" {$attribution}.",
                    "{$intro}\"I told the guys before the game: play with confidence, play with energy, and let the results take care of themselves. They did exactly that,\" {$attribution}.",
                    "{$intro}\"We are building something here. Games like today show what this team is capable of when everything clicks,\" {$attribution}.",
                    "{$intro}\"Our defense was incredible today. When they play like that, it makes everything easier on offense,\" {$attribution}.",
                    "{$intro}\"Every unit contributed. Special teams, offense, defense — that is a complete team victory,\" {$attribution}.",
                ],
                'comeback' => [
                    "{$intro}\"We never quit. That is the DNA of this team. We have been in those situations before and we trust each other,\" {$attribution}.",
                    "{$intro}\"I looked around the huddle and I saw belief. Nobody panicked. That is what championship teams do,\" {$attribution}.",
                    "{$intro}\"Down but never out. That is the motto around here. We proved it today,\" {$attribution}.",
                    "{$intro}\"I am not going to lie, there was a moment where it looked bad. But this group — they do not know how to quit. I am so proud of them,\" {$attribution}.",
                    "{$intro}\"We talked about resilience all offseason. Today we showed what that word actually means,\" {$attribution}.",
                    "{$intro}\"When we were down, I looked at the sideline and nobody had their head down. That tells you everything about this team,\" {$attribution}.",
                    "{$intro}\"Character wins. That is what we saw out there today. You cannot teach that. You either have it or you do not,\" {$attribution}.",
                    "{$intro}\"We knew we were going to get their best shot. We took it, we absorbed it, and we punched back. That is what we do,\" {$attribution}.",
                    "{$intro}\"My heart was pounding. I will not pretend it was not. But I trusted these guys to make the plays, and they did,\" {$attribution}.",
                ],
                'thriller' => [
                    "{$intro}\"That is why you play the game. Those are the moments you live for,\" {$attribution}.",
                    "{$intro}\"My heart was beating out of my chest on that last drive. But we made the plays when we needed them,\" {$attribution}.",
                    "{$intro}\"Close games come down to execution, and today we executed when it mattered most,\" {$attribution}.",
                    "{$intro}\"That was a great game between two good football teams. We just made one more play. That is the difference,\" {$attribution}.",
                    "{$intro}\"Games like that are why I love this sport. Two teams leaving everything on the field. I am glad we came out on top,\" {$attribution}.",
                    "{$intro}\"I will be honest, I do not know if I have a voice left. That was intense from start to finish,\" {$attribution}.",
                    "{$intro}\"A lot of respect for what they did today. They made it incredibly tough on us. We just found a way,\" {$attribution}.",
                    "{$intro}\"In the NFL, the margin between winning and losing is razor thin. Today we were on the right side of it. That is all you can ask for,\" {$attribution}.",
                ],
                'shootout' => [
                    "{$intro}\"We knew it was going to be a high-scoring game. We just had to score one more time than they did,\" {$attribution}.",
                    "{$intro}\"That was wild. I do not think anyone could have stopped either offense today. Fortunately, we made one more play,\" {$attribution}.",
                    "{$intro}\"Our offense was incredible today. I cannot say enough about the way we moved the ball,\" {$attribution}.",
                    "{$intro}\"When the defense gives up that many points, the offense has to respond. And they responded in a big way,\" {$attribution}.",
                ],
                'defensive_battle' => [
                    "{$intro}\"That is the way we want to play. Physical, tough, disciplined. Our defense was outstanding,\" {$attribution}.",
                    "{$intro}\"Ugly wins count the same as pretty ones. Our defense carried us today and I could not be more proud,\" {$attribution}.",
                    "{$intro}\"That was a slugfest, and our guys were ready for it. We won in the trenches today,\" {$attribution}.",
                    "{$intro}\"Games like that are won by the tougher team. I thought our guys were tougher today,\" {$attribution}.",
                ],
                default => [
                    "{$intro}\"Good team win. We still have things to clean up, but I am proud of how we competed today,\" {$attribution}.",
                    "{$intro}\"We are just focused on getting better every week. Today was a step in the right direction,\" {$attribution}.",
                    "{$intro}\"We did what we were supposed to do. Nothing more, nothing less. Now we move on to next week,\" {$attribution}.",
                    "{$intro}\"Solid performance by our guys. We controlled the game, and that is what you want to see,\" {$attribution}.",
                    "{$intro}\"I thought our guys played smart football today. We did not beat ourselves, and that is huge,\" {$attribution}.",
                    "{$intro}\"We talked about taking care of business today, and that is exactly what we did,\" {$attribution}.",
                    "{$intro}\"Every win in this league is hard. People do not realize that. I am proud of this group,\" {$attribution}.",
                    "{$intro}\"We are 1-0 this week. That is all that matters. On to the next one,\" {$attribution}.",
                ],
            };
        } else {
            $quotes = match ($gameType) {
                'blowout' => [
                    "{$intro}\"We have to look in the mirror. That is not who we are as a team. We will get back to work,\" {$attribution}.",
                    "{$intro}\"I am not going to sugarcoat it. That was unacceptable. We have to be better. I have to be better,\" {$attribution}.",
                    "{$intro}\"There is no excuse for a performance like that. We let our fans down, we let each other down. That is on all of us,\" {$attribution}.",
                    "{$intro}\"I take full responsibility. We were not prepared, and that starts with me. We will fix it,\" {$attribution}.",
                    "{$intro}\"That is embarrassing. There is no other word for it. We will get back in the building and correct this,\" {$attribution}.",
                    "{$intro}\"Credit to them — they played a great game. But we also made it easy for them. That cannot happen,\" {$attribution}.",
                    "{$intro}\"The film from this one is going to be ugly. But we will watch it, learn from it, and move forward,\" {$attribution}.",
                    "{$intro}\"I told the guys: this does not define us. How we respond to it will,\" {$attribution}.",
                ],
                'comeback' => [
                    "{$intro}\"We had it. We had the game in our hands and we let it go. That is tough to swallow,\" {$attribution}.",
                    "{$intro}\"You cannot take your foot off the gas in this league. We learned that the hard way today,\" {$attribution}.",
                    "{$intro}\"That one is going to hurt for a while. We were in control, and we let it slip away. That is inexcusable,\" {$attribution}.",
                    "{$intro}\"Finishing games is a skill. We obviously need to work on that skill,\" {$attribution}.",
                    "{$intro}\"When you have a team down, you have to put them away. We did not do that, and they made us pay,\" {$attribution}.",
                    "{$intro}\"I feel sick right now. We had that game won. There is no other way to put it — we gave it away,\" {$attribution}.",
                    "{$intro}\"We have to learn how to close. Good teams close games like that. We did not. That is on us,\" {$attribution}.",
                ],
                'thriller' => [
                    "{$intro}\"It stings because we were right there. A play here, a play there, and it is a different outcome,\" {$attribution}.",
                    "{$intro}\"You tip your cap sometimes. Both teams played hard. We just came up a play short,\" {$attribution}.",
                    "{$intro}\"I could not be more proud of how our guys competed. We just came up short. That is the nature of this game,\" {$attribution}.",
                    "{$intro}\"Tough loss. We had our chances and did not convert. That is going to bother me all week,\" {$attribution}.",
                    "{$intro}\"That was a coin-flip game, and the coin did not land our way. We will be fine. This team has fight,\" {$attribution}.",
                    "{$intro}\"When you lose a game by that margin, you know you were right there. We just need to make one more play,\" {$attribution}.",
                    "{$intro}\"I am not going to hang my head, and I do not want my guys to either. We competed. We just did not win,\" {$attribution}.",
                ],
                'shootout' => [
                    "{$intro}\"Our offense played well enough to win. We have to get stops. You cannot give up that many points and expect to come out on top,\" {$attribution}.",
                    "{$intro}\"We put up a lot of points. That should be enough. But we could not get off the field on defense, and that cost us,\" {$attribution}.",
                    "{$intro}\"Frustrating. The offense did its job. We need the defense to hold up its end,\" {$attribution}.",
                ],
                default => [
                    "{$intro}\"Back to the drawing board. We know what we need to fix, and we will get after it this week,\" {$attribution}.",
                    "{$intro}\"It is a long season. We have a lot of football left to play. We will respond,\" {$attribution}.",
                    "{$intro}\"Not the result we wanted. We have to regroup and come out better next week,\" {$attribution}.",
                    "{$intro}\"We are better than what we showed today. The guys know that. We will get it corrected,\" {$attribution}.",
                    "{$intro}\"You cannot play like that and expect to win in this league. We will clean it up,\" {$attribution}.",
                    "{$intro}\"I told the guys: flush it. Learn from it, but flush it. We have another game next week,\" {$attribution}.",
                    "{$intro}\"Disappointed but not discouraged. We will respond the way this team always responds — with work,\" {$attribution}.",
                    "{$intro}\"Every loss teaches you something. Today we learned we are not there yet. But we will be,\" {$attribution}.",
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
        $margin = $c['margin'];
        $week = $c['week'];

        if ($isWinner) {
            $options = match ($gameType) {
                'blowout' => array_filter([
                    "{$teamName} Dominate {$oppName} in {$ws}-{$ls} Rout",
                    "{$teamName} Roll Past {$oppName}, {$ws}-{$ls}",
                    $starName ? "{$starName} Leads {$teamName} Rout of {$oppName}" : null,
                    "{$teamName} Cruise Past {$oppName}, {$ws}-{$ls}",
                    "{$teamName} Demolish {$oppName} in Statement Win",
                    $starName ? "{$starName} Shines in {$teamName}'s {$ws}-{$ls} Blowout" : null,
                    "{$teamName} Put {$oppName} Away Early, Win {$ws}-{$ls}",
                    "No Contest: {$teamName} Rout {$oppName}, {$ws}-{$ls}",
                    $starName ? "{$starName}-Led {$teamName} Crush {$oppName}" : null,
                    "{$teamName} Send Message With {$margin}-Point Win",
                ]),
                'comeback' => array_filter([
                    "{$teamName} Storm Back to Stun {$oppName}, {$ws}-{$ls}",
                    "Comeback Complete: {$teamName} Rally Past {$oppName}",
                    $starName ? "{$starName} Sparks {$teamName} Comeback vs. {$oppName}" : null,
                    "{$teamName} Author Dramatic Comeback, Beat {$oppName}",
                    "Down But Not Out: {$teamName} Rally to Beat {$oppName}",
                    "{$teamName} Erase Deficit, Stun {$oppName} {$ws}-{$ls}",
                    $starName ? "{$starName} Fuels Furious {$teamName} Rally" : null,
                    "Incredible Comeback Lifts {$teamName} Over {$oppName}",
                    "{$teamName} Refuse to Quit, Rally for {$ws}-{$ls} Win",
                ]),
                'thriller' => array_filter([
                    "{$teamName} Edge {$oppName} in {$ws}-{$ls} Thriller",
                    $starName ? "{$starName} Delivers as {$teamName} Survive {$oppName}" : null,
                    "Down to the Wire: {$teamName} {$ws}, {$oppName} {$ls}",
                    "{$teamName} Hold Off {$oppName} in Nail-Biter",
                    "{$teamName} Survive {$oppName} in {$ws}-{$ls} Classic",
                    $starName ? "{$starName} Makes Clutch Play in {$teamName}'s {$ws}-{$ls} Win" : null,
                    "Thriller: {$teamName} Escape With {$ws}-{$ls} Victory",
                    "{$teamName} Win by {$margin} in Instant Classic",
                ]),
                'shootout' => array_filter([
                    "{$teamName} Outgun {$oppName} in {$ws}-{$ls} Shootout",
                    "Offensive Fireworks: {$teamName} Top {$oppName}, {$ws}-{$ls}",
                    $starName ? "{$starName} Leads Offensive Explosion in {$ws}-{$ls} Win" : null,
                    "{$teamName} Win Wild One, {$ws}-{$ls}",
                    "Track Meet: {$teamName} Outscore {$oppName}, {$ws}-{$ls}",
                    "{$teamName} Survive Shootout Against {$oppName}",
                    $starName ? "{$starName} Duels in {$teamName}'s {$ws}-{$ls} Shootout Win" : null,
                ]),
                'defensive_battle' => [
                    "{$teamName} Grind Out {$ws}-{$ls} Win Over {$oppName}",
                    "Defense Rules as {$teamName} Edge {$oppName}, {$ws}-{$ls}",
                    "{$teamName} Hold On for Gritty {$ws}-{$ls} Victory",
                    "Low-Scoring Affair Goes to {$teamName}, {$ws}-{$ls}",
                    "{$teamName} Win Defensive Slugfest, {$ws}-{$ls}",
                    "Defenses Dominate as {$teamName} Top {$oppName}",
                ],
                'back_and_forth' => array_filter([
                    "{$teamName} Prevail in {$ws}-{$ls} Battle With {$oppName}",
                    "{$teamName} Outlast {$oppName} in Back-and-Forth Affair",
                    $starName ? "{$starName} Has Last Word as {$teamName} Top {$oppName}" : null,
                    "Seesaw Battle Goes to {$teamName}, {$ws}-{$ls}",
                    "{$teamName} Make Final Run Count, Beat {$oppName} {$ws}-{$ls}",
                ]),
                default => array_filter([
                    "{$teamName} Top {$oppName}, {$ws}-{$ls}",
                    $starName ? "{$starName} Stars as {$teamName} Beat {$oppName}" : null,
                    "{$teamName} Handle {$oppName}, {$ws}-{$ls}",
                    "{$teamName} Take Down {$oppName} in Week {$week}",
                    "{$teamName} Dispatch {$oppName}, {$ws}-{$ls}",
                    $starName ? "{$starName} Leads {$teamName} Past {$oppName}" : null,
                    "{$teamName} Pick Up Week {$week} Win Over {$oppName}",
                    "{$teamName} Down {$oppName} {$ws}-{$ls}",
                ]),
            };
        } else {
            $options = match ($gameType) {
                'blowout' => [
                    "{$teamName} Blown Out by {$oppName}, {$ws}-{$ls}",
                    "{$teamName} Have No Answers in Lopsided Loss to {$oppName}",
                    "{$teamName} Routed by {$oppName} in {$ws}-{$ls} Debacle",
                    "Dismal Day for {$teamName} in {$ws}-{$ls} Loss",
                    "{$oppName} Steamroll {$teamName}, {$ws}-{$ls}",
                    "{$teamName} Embarrassed in {$margin}-Point Loss to {$oppName}",
                ],
                'comeback' => [
                    "{$teamName} Collapse in Fourth Quarter, Fall to {$oppName}",
                    "Lead Evaporates as {$teamName} Drop Heartbreaker to {$oppName}",
                    "{$teamName} Blow Lead, Fall to {$oppName} {$ws}-{$ls}",
                    "Heartbreak: {$teamName} Surrender Lead, Lose {$ws}-{$ls}",
                    "Fourth-Quarter Collapse Dooms {$teamName} Against {$oppName}",
                    "{$teamName} Let One Slip Away vs. {$oppName}",
                ],
                'thriller' => [
                    "{$teamName} Fall Short in {$ws}-{$ls} Loss to {$oppName}",
                    "{$teamName} Come Up Empty in Tight Loss to {$oppName}",
                    "Heartbreak: {$teamName} Fall {$ws}-{$ls} to {$oppName}",
                    "{$teamName} Lose Nail-Biter to {$oppName}, {$ws}-{$ls}",
                    "{$teamName} Drop {$ws}-{$ls} Thriller to {$oppName}",
                ],
                'shootout' => [
                    "{$teamName} Outscored in Wild {$ws}-{$ls} Loss",
                    "High-Scoring Affair Goes Against {$teamName}, {$ws}-{$ls}",
                    "Defense Fails {$teamName} in {$ws}-{$ls} Shootout Loss",
                    "{$teamName} Can't Keep Pace in {$ws}-{$ls} Defeat",
                ],
                'defensive_battle' => [
                    "{$teamName} Fall in Low-Scoring Affair, {$ws}-{$ls}",
                    "Offense Stalls as {$teamName} Drop {$ws}-{$ls} Decision",
                    "{$teamName} Come Up Short in Defensive Grind",
                ],
                default => [
                    "{$teamName} Fall to {$oppName}, {$ws}-{$ls}",
                    "{$teamName} Drop Week {$week} Contest to {$oppName}",
                    "{$teamName} Stumble Against {$oppName}, {$ws}-{$ls}",
                    "{$teamName} Lose to {$oppName} in Week {$week}",
                    "Rough Day for {$teamName} in {$ws}-{$ls} Loss",
                    "{$oppName} Hand {$teamName} {$ws}-{$ls} Defeat",
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

    private function getPlayerName(int $playerId): string
    {
        $stmt = $this->db->prepare("SELECT first_name, last_name FROM players WHERE id = ?");
        $stmt->execute([$playerId]);
        $row = $stmt->fetch();
        return $row ? trim($row['first_name'] . ' ' . $row['last_name']) : 'Unknown';
    }

    /**
     * Bulk-load player names for a set of IDs. Returns [id => ['name'=>..., 'last_name'=>..., 'position'=>...]].
     */
    private function getPlayerNames(array $playerIds): array
    {
        if (empty($playerIds)) return [];
        $playerIds = array_unique(array_filter($playerIds, 'is_numeric'));
        if (empty($playerIds)) return [];
        $placeholders = implode(',', array_fill(0, count($playerIds), '?'));
        $stmt = $this->db->prepare("SELECT id, first_name, last_name, position FROM players WHERE id IN ({$placeholders})");
        $stmt->execute(array_values($playerIds));
        $map = [];
        while ($row = $stmt->fetch()) {
            $map[(int)$row['id']] = [
                'name' => trim($row['first_name'] . ' ' . $row['last_name']),
                'last_name' => $row['last_name'],
                'first_name' => $row['first_name'],
                'position' => $row['position'],
            ];
        }
        return $map;
    }

    /**
     * Find secondary performers (2nd/3rd best) on a team, excluding the star.
     * Returns enriched arrays with player name, position, and stats.
     */
    private function findSecondaryPerformers(array $teamStats, ?int $excludeId = null): array
    {
        $performers = [];
        $playerIds = array_keys($teamStats);
        $names = $this->getPlayerNames($playerIds);

        foreach ($teamStats as $pid => $stat) {
            if ((int)$pid === $excludeId) continue;
            $info = $names[(int)$pid] ?? null;
            if (!$info) continue;

            $score = ($stat['pass_yards'] ?? 0) + ($stat['rush_yards'] ?? 0) * 2
                + ($stat['rec_yards'] ?? 0) * 1.5
                + ($stat['pass_tds'] ?? 0) * 20 + ($stat['rush_tds'] ?? 0) * 20
                + ($stat['rec_tds'] ?? 0) * 20
                + ($stat['sacks'] ?? 0) * 15 + ($stat['interceptions_def'] ?? 0) * 25;

            $performers[] = [
                'id' => (int)$pid,
                'name' => $info['name'],
                'last_name' => $info['last_name'],
                'position' => $info['position'],
                'stats' => $stat,
                'score' => $score,
            ];
        }

        usort($performers, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($performers, 0, 3);
    }

    /**
     * Extract key scoring plays and dramatic moments from the game log.
     */
    private function extractKeyMoments(array $gameLog, int $limit = 5): array
    {
        $moments = [];
        foreach ($gameLog as $entry) {
            $w = 0;
            $note = $entry['note'] ?? '';
            if (str_contains($note, 'touchdown')) $w += 20;
            if (str_contains($note, 'Interception')) $w += 18;
            if (str_contains($note, 'Fumble')) $w += 15;
            if (str_contains($note, 'Field goal good')) $w += 10;
            if (str_contains($note, '4th down')) $w += 12;
            if (str_contains($note, 'safety')) $w += 14;
            if (($entry['quarter'] ?? 0) >= 3) $w += 8;
            if (($entry['quarter'] ?? 0) >= 4) $w += 12;
            $yards = abs($entry['play']['yards'] ?? 0);
            if ($yards >= 40) $w += 10;
            elseif ($yards >= 25) $w += 6;
            $scoreDiff = abs(($entry['home_score'] ?? 0) - ($entry['away_score'] ?? 0));
            if ($scoreDiff <= 7) $w += 5;
            if ($scoreDiff === 0) $w += 3;
            if ($w > 0) {
                $entry['_weight'] = $w;
                $moments[] = $entry;
            }
        }
        usort($moments, fn($a, $b) => $b['_weight'] <=> $a['_weight']);
        return array_slice($moments, 0, $limit);
    }

    /**
     * Build a quarter-by-quarter scoring summary from the game log.
     */
    private function buildQuarterSummary(array $gameLog, array $c): array
    {
        $quarters = [1 => [], 2 => [], 3 => [], 4 => []];
        $homeName = $c['home']['city'] . ' ' . $c['home']['name'];
        $awayName = $c['away']['city'] . ' ' . $c['away']['name'];

        foreach ($gameLog as $entry) {
            $q = $entry['quarter'] ?? 1;
            if ($q < 1 || $q > 4) continue;
            $note = $entry['note'] ?? '';
            if (str_contains($note, 'touchdown') || str_contains($note, 'Field goal good') || str_contains($note, 'safety')) {
                $possession = $entry['possession'] ?? 'home';
                $teamScoring = $possession === 'home' ? $homeName : $awayName;
                $playerName = $entry['play']['player'] ?? 'the offense';
                $yards = abs($entry['play']['yards'] ?? 0);
                $playType = $entry['play']['type'] ?? '';
                $target = $entry['play']['target'] ?? null;
                $hs = $entry['home_score'] ?? 0;
                $as = $entry['away_score'] ?? 0;
                $clock = $entry['clock'] ?? 0;
                $timeStr = sprintf("%d:%02d", intdiv(max(0, $clock), 60), max(0, $clock) % 60);

                $quarters[$q][] = [
                    'team' => $teamScoring,
                    'player' => $playerName,
                    'target' => $target,
                    'yards' => $yards,
                    'play_type' => $playType,
                    'score_after' => "{$hs}-{$as}",
                    'clock' => $timeStr,
                    'is_td' => str_contains($note, 'touchdown'),
                    'is_fg' => str_contains($note, 'Field goal'),
                    'note' => $note,
                ];
            }
        }
        return $quarters;
    }

    /**
     * Generate a quarter-by-quarter flow paragraph.
     * Walks through the game chronologically — how scoring unfolded.
     */
    private function generateGameFlowParagraph(array $c, string $teamName, string $oppName, array $columnist): string
    {
        $gameLog = $c['game_log'] ?? [];
        if (empty($gameLog)) return '';

        $quarters = $this->buildQuarterSummary($gameLog, $c);
        $sentences = [];

        $quarterLabels = [1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth'];
        $scorelessOpeners = [
            'After a scoreless %s quarter,',
            'Neither team could find the end zone in the %s quarter.',
            'The %s quarter was a defensive stalemate.',
            'Both defenses held firm in the %s quarter.',
        ];
        $scoringVerbs = [
            'struck first with', 'opened the scoring with', 'got on the board with',
            'answered with', 'responded with', 'fired back with', 'countered with',
            'extended the lead with', 'added to their total with', 'tacked on',
            'pulled closer with', 'cut the deficit with', 'trimmed the margin with',
        ];

        $firstScoreFound = false;
        foreach ($quarterLabels as $q => $label) {
            $plays = $quarters[$q] ?? [];
            if (empty($plays)) {
                if ($q <= 2 && !$firstScoreFound) {
                    $sentences[] = sprintf($this->pickOne($scorelessOpeners), $label);
                }
                continue;
            }
            foreach ($plays as $play) {
                $firstScoreFound = true;
                $verb = $this->pickOne($scoringVerbs);
                if ($play['is_td']) {
                    if ($play['target'] && $play['play_type'] === 'completion') {
                        $sentences[] = "{$play['team']} {$verb} a {$play['yards']}-yard touchdown pass from {$play['player']} to {$play['target']}, making it {$play['score_after']}.";
                    } else {
                        $sentences[] = "{$play['team']} {$verb} a {$play['yards']}-yard touchdown run by {$play['player']} to make it {$play['score_after']}.";
                    }
                } elseif ($play['is_fg']) {
                    $sentences[] = "A field goal made it {$play['score_after']}.";
                }
                if (count($sentences) >= 5) break 2; // Cap at 5 scoring sentences to keep paragraph manageable
            }
        }

        if (empty($sentences)) return '';
        return implode(' ', $sentences);
    }

    /**
     * Format a weather atmosphere sentence for the lede or context.
     */
    private function formatWeatherAtmosphere(string $weather, string $teamName, array $columnist): string
    {
        if ($weather === 'clear' || $weather === 'dome' || $weather === '') return '';

        $style = $columnist['style'] ?? 'old_school';

        return match ($weather) {
            'rain' => match ($style) {
                'narrative' => $this->pickOne([
                    "Rain fell in sheets, turning the field into a quagmire and every snap into an adventure.",
                    "The rain never stopped. It soaked everything — the field, the jerseys, the football itself.",
                    "Under gray skies and driving rain, this game took on a different character entirely.",
                ]),
                'analytical' => $this->pickOne([
                    "Wet conditions impacted both passing attacks, reducing completion percentages across the board.",
                    "The rain was a variable that suppressed the aerial game and inflated fumble risk.",
                ]),
                default => $this->pickOne([
                    "Rain made every snap an adventure. That is when you find out what your team is made of.",
                    "Tough conditions. Rain. Wet ball. That favors the physical team, and it showed.",
                    "In the rain, you throw the playbook out and play football. That is what happened today.",
                ]),
            },
            'snow' => match ($style) {
                'narrative' => $this->pickOne([
                    "Snow blanketed the field like a postcard, but there was nothing picturesque about the football being played.",
                    "Fat snowflakes fell through the stadium lights, and the game turned into something primal.",
                    "The snow transformed the game into a winter war, every yard earned through sheer force.",
                ]),
                'analytical' => $this->pickOne([
                    "Snow conditions heavily favored the ground game, with passing efficiency dropping significantly for both sides.",
                    "The snowy conditions introduced chaos — fumble rates spike by 40% in these situations historically.",
                ]),
                default => $this->pickOne([
                    "Snow game. That is old-school football. Love it.",
                    "In the snow, you run the ball and play defense. Simple as that.",
                    "The snow leveled the playing field. No more finesse. Just football.",
                ]),
            },
            'wind' => match ($style) {
                'narrative' => $this->pickOne([
                    "The wind howled through the stadium, bending flags and turning deep balls into guessing games.",
                    "Gusts swirled through the open end of the stadium, making every throw a calculated risk.",
                ]),
                'analytical' => $this->pickOne([
                    "Wind speeds significantly affected deep passing attempts, particularly to the south end zone.",
                    "Gusty conditions reduced expected completion probability on passes over 15 air yards.",
                ]),
                default => $this->pickOne([
                    "Windy day. That deep ball was not there for either team.",
                    "The wind was a factor. Smart teams adjust. One team did.",
                ]),
            },
            default => '',
        };
    }

    private function pickOne(array $options): string
    {
        return $options[array_rand($options)];
    }

    /**
     * Convert week number to human-readable label.
     * Weeks 1-18: "Week 1" through "Week 18"
     * Week 19: "Wild Card Weekend"
     * Week 20: "Divisional Round"
     * Week 21: "Conference Championship"
     * Week 22: "The Big Game"
     */
    private function weekLabel(int $week): string
    {
        return match (true) {
            $week === 19 => 'Wild Card Weekend',
            $week === 20 => 'Divisional Round',
            $week === 21 => 'Conference Championship',
            $week === 22 => 'The Big Game',
            $week > 22   => 'Offseason',
            default       => "Week {$week}",
        };
    }

    private function weekLabelShort(int $week): string
    {
        return match (true) {
            $week === 19 => 'Wild Card',
            $week === 20 => 'Divisional',
            $week === 21 => 'Conf. Championship',
            $week === 22 => 'The Big Game',
            $week > 22   => 'Offseason',
            default       => "Week {$week}",
        };
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


    // ─── Weekly Column ───────────────────────────────────────────────

    /**
     * Generate a weekly opinion column from one of the three columnists.
     * Uses actual game results, player names, standings data, and deep columnist voice.
     */
    public function generateWeeklyColumn(int $leagueId, int $seasonId, int $week): void
    {
        $teams = $this->db->prepare("SELECT * FROM teams WHERE league_id = ? ORDER BY wins DESC");
        $teams->execute([$leagueId]);
        $teams = $teams->fetchAll();

        if (empty($teams)) return;

        // Fetch this week's game results
        $gamesStmt = $this->db->prepare(
            "SELECT g.*, g.home_score, g.away_score, g.box_score,
                    ht.city as home_city, ht.name as home_name, ht.abbreviation as home_abbr,
                    ht.wins as home_wins, ht.losses as home_losses, ht.conference as home_conf, ht.division as home_div,
                    at.city as away_city, at.name as away_name, at.abbreviation as away_abbr,
                    at.wins as away_wins, at.losses as away_losses, at.conference as away_conf, at.division as away_div
             FROM games g
             JOIN teams ht ON g.home_team_id = ht.id
             JOIN teams at ON g.away_team_id = at.id
             WHERE g.league_id = ? AND g.week = ? AND g.is_simulated = 1"
        );
        $gamesStmt->execute([$leagueId, $week]);
        $weekGames = $gamesStmt->fetchAll();

        $teamsById = [];
        foreach ($teams as $t) {
            $teamsById[(int) $t['id']] = $t;
        }

        $divisionStandings = [];
        foreach ($teams as $t) {
            $divKey = ($t['conference'] ?? '') . ' ' . ($t['division'] ?? '');
            $divisionStandings[$divKey][] = $t;
        }

        // Find week's top performer
        $weekTopPerformer = null;
        $weekTopGame = null;
        $weekTopScore = 0;
        foreach ($weekGames as $g) {
            $box = json_decode($g['box_score'] ?? '{}', true);
            foreach (['home', 'away'] as $side) {
                foreach (($box[$side]['stats'] ?? []) as $pid => $s) {
                    $score = ($s['pass_yards'] ?? 0) + ($s['rush_yards'] ?? 0) * 2
                        + ($s['rec_yards'] ?? 0) * 1.5
                        + ($s['pass_tds'] ?? 0) * 20 + ($s['rush_tds'] ?? 0) * 20
                        + ($s['rec_tds'] ?? 0) * 20
                        + ($s['sacks'] ?? 0) * 15 + ($s['interceptions_def'] ?? 0) * 25;
                    if ($score > $weekTopScore) {
                        $weekTopScore = $score;
                        $weekTopPerformer = ['id' => $pid, 'stats' => $s, 'side' => $side];
                        $weekTopGame = $g;
                    }
                }
            }
        }
        $topPerformerName = $weekTopPerformer ? $this->getPlayerName((int) $weekTopPerformer['id']) : null;

        // Rotate columnist
        $columnistKeys = ['terry_hollis', 'dana_reeves', 'marcus_bell'];
        $columnistKey = $columnistKeys[$week % 3];
        $columnist = self::COLUMNISTS[$columnistKey];
        $authorName = $columnist['name'];

        $topic = null;
        $focusTeam = null;
        $focusGame = null;
        $isPlayoffs = $week >= 19;

        if ($week === 22) { $topic = 'big_game_react'; }
        elseif ($week === 21) { $topic = 'conference_champ_react'; }
        elseif ($week === 20) { $topic = 'divisional_react'; }
        elseif ($week === 19) { $topic = 'wild_card_react'; }

        // Regular season topic selection with 20+ possibilities
        if (!$topic) {
            $candidates = [];

            foreach ($teams as $t) {
                if (str_starts_with($t['streak'] ?? '', 'W') && (int) substr($t['streak'], 1) >= 5) {
                    $candidates[] = ['topic' => 'contender', 'team' => $t, 'weight' => 10 + (int) substr($t['streak'], 1)];
                }
            }
            foreach (array_slice($teams, 0, 5) as $t) {
                if (str_starts_with($t['streak'] ?? '', 'L')) {
                    $candidates[] = ['topic' => 'overreaction', 'team' => $t, 'weight' => 12];
                }
            }
            foreach ($teams as $t) {
                if (str_starts_with($t['streak'] ?? '', 'L') && (int) substr($t['streak'], 1) >= 3 && (int) $t['wins'] >= 3) {
                    $candidates[] = ['topic' => 'coaching_hot_seat', 'team' => $t, 'weight' => 11];
                }
            }
            if ($weekTopPerformer) {
                $rc = $this->db->prepare("SELECT experience FROM players WHERE id = ?");
                $rc->execute([(int) $weekTopPerformer['id']]);
                $rr = $rc->fetch();
                if ($rr && (int) ($rr['experience'] ?? 1) === 0) {
                    $candidates[] = ['topic' => 'rookie_breakout', 'team' => null, 'weight' => 13];
                }
            }
            $bigM = 0; $bigG = null;
            foreach ($weekGames as $g) {
                $m = abs((int) $g['home_score'] - (int) $g['away_score']);
                if ($m > $bigM) { $bigM = $m; $bigG = $g; }
            }
            if ($bigM >= 21 && $bigG) {
                $wid = (int) $bigG['home_score'] > (int) $bigG['away_score'] ? (int) $bigG['home_team_id'] : (int) $bigG['away_team_id'];
                $candidates[] = ['topic' => 'statement_game', 'team' => $teamsById[$wid] ?? null, 'game' => $bigG, 'weight' => 9];
            }
            if ($week >= 10) {
                $topW = (int) $teams[0]['wins']; $closeT = 0;
                foreach ($teams as $t) { if ($topW - (int) $t['wins'] <= 2) $closeT++; }
                if ($closeT >= 4) { $candidates[] = ['topic' => 'playoff_race', 'team' => null, 'weight' => 10]; }
            }
            foreach ($divisionStandings as $dKey => $dTeams) {
                if (count($dTeams) >= 2 && abs((int) $dTeams[0]['wins'] - (int) $dTeams[1]['wins']) <= 1 && (int) $dTeams[0]['wins'] >= 3) {
                    $candidates[] = ['topic' => 'division_race', 'team' => $dTeams[0], 'weight' => 8];
                    break;
                }
            }
            foreach (array_slice($teams, -8) as $t) {
                if (str_starts_with($t['streak'] ?? '', 'W') && (int) substr($t['streak'], 1) >= 2) {
                    $candidates[] = ['topic' => $this->pickOne(['sleeper', 'nobody_talking_about']), 'team' => $t, 'weight' => 7];
                    break;
                }
            }
            foreach ($weekGames as $g) {
                $box = json_decode($g['box_score'] ?? '{}', true);
                $hRush = 0; $aRush = 0;
                foreach (($box['home']['stats'] ?? []) as $s) { $hRush += (int) ($s['rush_yards'] ?? 0); }
                foreach (($box['away']['stats'] ?? []) as $s) { $aRush += (int) ($s['rush_yards'] ?? 0); }
                if ($aRush >= 200 && isset($teamsById[(int) $g['home_team_id']])) {
                    $candidates[] = ['topic' => 'run_defense_problem', 'team' => $teamsById[(int) $g['home_team_id']], 'game' => $g, 'weight' => 8]; break;
                }
                if ($hRush >= 200 && isset($teamsById[(int) $g['away_team_id']])) {
                    $candidates[] = ['topic' => 'run_defense_problem', 'team' => $teamsById[(int) $g['away_team_id']], 'game' => $g, 'weight' => 8]; break;
                }
            }
            if ($week >= 8) {
                foreach (array_slice($teams, 0, 3) as $t) {
                    $tg = (int) $t['wins'] + (int) $t['losses'];
                    if ($tg > 0 && ((int) $t['wins'] / $tg) >= 0.7) {
                        $candidates[] = ['topic' => 'i_was_wrong', 'team' => $t, 'weight' => 6]; break;
                    }
                }
            }
            if ($week >= 4 && $week <= 14 && count($weekGames) < count($teams) / 2) {
                $candidates[] = ['topic' => 'bye_week_analysis', 'team' => null, 'weight' => 5];
            }
            foreach ($teams as $t) {
                if (str_starts_with($t['streak'] ?? '', 'L') && (int) substr($t['streak'], 1) >= 2) {
                    $candidates[] = ['topic' => 'injury_impact', 'team' => $t, 'weight' => 6]; break;
                }
            }
            if ($week >= 6) { $candidates[] = ['topic' => 'strength_of_schedule', 'team' => $teams[0], 'weight' => 4]; }
            $candidates[] = ['topic' => 'power_debate', 'team' => $teams[0], 'weight' => 3];
            $candidates[] = ['topic' => 'weekly_awards', 'team' => null, 'weight' => 4];

            usort($candidates, fn($a, $b) => $b['weight'] - $a['weight']);
            $topC = array_slice($candidates, 0, min(3, count($candidates)));
            $picked = $topC[array_rand($topC)];
            $topic = $picked['topic'];
            $focusTeam = $picked['team'] ?? null;
            $focusGame = $picked['game'] ?? null;
        }

        $paragraphs = [];
        $headline = '';
        $teamId = $focusTeam ? (int) $focusTeam['id'] : null;

        // Helper closures
        $gameResult = function (array $g): string {
            $hs = (int) $g['home_score']; $as = (int) $g['away_score'];
            $wn = $hs > $as ? $g['home_city'] . ' ' . $g['home_name'] : $g['away_city'] . ' ' . $g['away_name'];
            $ln = $hs > $as ? $g['away_city'] . ' ' . $g['away_name'] : $g['home_city'] . ' ' . $g['home_name'];
            return "the {$wn} beat the {$ln} " . max($hs, $as) . "-" . min($hs, $as);
        };
        $scoreboard = [];
        foreach ($weekGames as $g) { $scoreboard[] = $g['away_abbr'] . ' ' . (int) $g['away_score'] . ', ' . $g['home_abbr'] . ' ' . (int) $g['home_score']; }

        // Find focus team's game this week
        $findTeamGame = function (?array $ft) use ($weekGames): ?array {
            if (!$ft) return null;
            foreach ($weekGames as $g) {
                if ((int) $g['home_team_id'] === (int) $ft['id'] || (int) $g['away_team_id'] === (int) $ft['id']) return $g;
            }
            return null;
        };

        switch ($topic) {
            case 'contender':
                $name = $focusTeam['city'] . ' ' . $focusTeam['name'];
                $record = $focusTeam['wins'] . '-' . $focusTeam['losses'];
                $streakNum = (int) substr($focusTeam['streak'], 1);
                $pf = (int) $focusTeam['points_for']; $pa = (int) $focusTeam['points_against'];
                $diff = $pf - $pa; $diffStr = $diff >= 0 ? "+{$diff}" : (string) $diff;
                $tg = max(1, (int) $focusTeam['wins'] + (int) $focusTeam['losses']);
                $theirGame = $findTeamGame($focusTeam);

                if ($columnistKey === 'terry_hollis') {
                    $headline = "{$authorName}: The {$name} Are Built the Right Way — and Week {$week} Proved It";
                    $paragraphs[] = "I have been doing this for 30 years. I know what a championship team looks like. The {$name} are a championship team.";
                    if ($theirGame) { $paragraphs[] = "After watching " . $gameResult($theirGame) . " on Sunday, there should be no more debate. This team plays real football. They run the ball. They stop the run. They do not beat themselves with penalties and turnovers."; }
                    $paragraphs[] = "At {$record} with a {$diffStr} point differential, the {$name} have won {$streakNum} straight games. That does not happen by accident. That happens because a coaching staff has this team prepared every single week, and the players execute the fundamentals.";
                    $paragraphs[] = "I do not care about your analytics. I do not care about your expected points added. Watch the tape. Watch how they play in the trenches. Watch how they finish games. That is football the way it was meant to be played.";
                    $paragraphs[] = "The rest of the league is going to have to go through them. And right now, I am not sure anyone can.";
                    $paragraphs[] = "Will they face adversity? Of course. Every team does. But the great ones respond to adversity — they do not crumble. And this team has the backbone to handle whatever comes next.";
                    $paragraphs[] = "Mark it down. The {$name} are playing for a championship. I would bet my press pass on it.";
                } elseif ($columnistKey === 'dana_reeves') {
                    $headline = "{$authorName}: The Numbers Behind the {$name}'s {$streakNum}-Game Win Streak Are Staggering";
                    $paragraphs[] = "Let me walk you through the data, because the {$name}'s {$streakNum}-game win streak is not just impressive — it is historically significant.";
                    if ($theirGame) { $paragraphs[] = "This week, " . $gameResult($theirGame) . ". But the score only tells part of the story."; }
                    $paragraphs[] = "At {$record}, the {$name} have a point differential of {$diffStr}. They are scoring an average of " . round($pf / $tg, 1) . " points per game while allowing just " . round($pa / $tg, 1) . " per contest. That kind of efficiency on both sides of the ball is rare.";
                    $paragraphs[] = "During this {$streakNum}-game win streak, the margins have not been fluky. This is sustained, repeatable dominance — the kind that correlates strongly with deep playoff runs.";
                    $paragraphs[] = "The offensive rating sits at " . ($focusTeam['offense_rating'] ?? '??') . " and the defensive rating at " . ($focusTeam['defense_rating'] ?? '??') . ". When both numbers are that high simultaneously, history tells us you are looking at a legitimate championship contender.";
                    $paragraphs[] = "There are still games to be played, and regression is always possible. But the sample size is large enough now to say with confidence: the {$name} are for real.";
                    $paragraphs[] = "The question is not whether they make the playoffs. The question is whether anyone can match their two-way efficiency when January arrives.";
                } else {
                    $headline = "{$authorName}: Something Special Is Happening with the {$name}";
                    $paragraphs[] = "There is a moment in every great season when a team stops being good and starts being something else entirely. Something that makes you lean forward in your seat.";
                    if ($theirGame) { $paragraphs[] = "That moment arrived this Sunday, when " . $gameResult($theirGame) . ". It was not just a win. It was a declaration."; }
                    $paragraphs[] = "The {$name} are {$record}. They have won {$streakNum} straight. And there is a look in their eyes now — that quiet confidence that separates the contenders from the pretenders.";
                    $paragraphs[] = "Watch the sideline after a big play. Watch how the bench reacts. This is a team that believes — truly believes — that they cannot be beaten. And that belief is the most dangerous weapon in sports.";
                    $paragraphs[] = "Every great championship story needs a chapter where the team finds its identity. For the {$name}, that chapter is being written right now, in real time, one Sunday at a time.";
                    $paragraphs[] = "I do not know how this story ends. But I know this: I cannot look away. And neither should you.";
                    $paragraphs[] = "Something special is happening. Do not miss it.";
                }
                break;

            case 'overreaction':
                $name = $focusTeam['city'] . ' ' . $focusTeam['name'];
                $record = $focusTeam['wins'] . '-' . $focusTeam['losses'];
                $pa = (int) $focusTeam['points_against'];
                $tg = max(1, (int) $focusTeam['wins'] + (int) $focusTeam['losses']);
                $ppgA = round($pa / $tg, 1);
                $theirGame = $findTeamGame($focusTeam);

                if ($columnistKey === 'terry_hollis') {
                    $headline = "{$authorName}: The {$name} Have Lost Their Way, and Sunday Was the Proof";
                    $paragraphs[] = "I have seen enough. The {$name} are not the team we thought they were.";
                    if ($theirGame) { $paragraphs[] = "After " . $gameResult($theirGame) . ", I am done making excuses for this team. That was not a competitive football game. That was a team that has lost its identity."; }
                    $paragraphs[] = "At {$record}, the record still looks fine if you squint. But records lie. This team is allowing {$ppgA} points per game. The fundamentals have disappeared. The tackling is sloppy. The discipline that defined them early in the season? Gone.";
                    $paragraphs[] = "I have been doing this long enough to know the difference between a slump and a collapse. A slump is when a good team has a bad week. A collapse is when the effort disappears.";
                    $paragraphs[] = "Which one are the {$name}? Ask me again next week. But I did not like what I saw on Sunday. Not one bit.";
                    $paragraphs[] = "Something needs to change. The scheme. The intensity. The accountability. Something. Because what they are putting on the field right now is not good enough.";
                    $paragraphs[] = "The {$name} have the talent. The question is whether they have the will. And right now, I have serious doubts.";
                } elseif ($columnistKey === 'dana_reeves') {
                    $headline = "{$authorName}: The Data Says the {$name} Are Trending in the Wrong Direction";
                    $paragraphs[] = "I want to be measured about this, because overreaction is the enemy of good analysis. But the {$name}'s recent trajectory is concerning by any metric.";
                    if ($theirGame) { $paragraphs[] = "Sunday's result — " . $gameResult($theirGame) . " — was not an outlier. It was a continuation of a pattern."; }
                    $paragraphs[] = "At {$record}, the {$name} are allowing {$ppgA} points per game. Their defensive rating of " . ($focusTeam['defense_rating'] ?? '??') . " ranks in the bottom third of the league. The offensive rating of " . ($focusTeam['offense_rating'] ?? '??') . " has been declining week over week.";
                    $paragraphs[] = "When you see efficiency numbers trending downward for three or more weeks, that is not variance — that is a systemic issue.";
                    $d = (int) $focusTeam['points_for'] - $pa; $ds = $d >= 0 ? "+{$d}" : (string) $d;
                    $paragraphs[] = "The point differential tells the story: {$ds}. Historically, teams with their profile at this point in the season make the playoffs only about 40% of the time.";
                    $paragraphs[] = "There is still time to correct course. But the window is closing, and the data is flashing warning signs.";
                    $paragraphs[] = "The next three weeks will tell us whether this is a correction or a collapse. The numbers will not lie.";
                } else {
                    $headline = "{$authorName}: I Watched the {$name} on Sunday and Saw a Team Coming Apart";
                    $paragraphs[] = "There is a moment in every struggling season when the camera catches something you cannot unsee. A coach staring at the ground after a third-down failure. A quarterback sitting alone on the bench with a towel over his head.";
                    if ($theirGame) { $paragraphs[] = "I saw that moment on Sunday, when " . $gameResult($theirGame) . ". It was not the score that worried me. It was the body language."; }
                    $paragraphs[] = "The {$name} at {$record} are supposed to be contenders. But contenders do not look like that. Contenders fight. Contenders claw. The team I watched on Sunday was going through the motions.";
                    $paragraphs[] = "The talent is still there — you do not lose talent overnight. But belief? Belief is fragile. And once it cracks, it takes something extraordinary to put it back together.";
                    $paragraphs[] = "Every season has a crossroads moment. For the {$name}, this is it.";
                    $paragraphs[] = "I have seen it go both ways. The difference is always the same: leadership. Someone has to grab this team by the collar and refuse to let it sink.";
                    $paragraphs[] = "We are about to find out.";
                }
                break;

            case 'coaching_hot_seat':
                $name = $focusTeam['city'] . ' ' . $focusTeam['name'];
                $record = $focusTeam['wins'] . '-' . $focusTeam['losses'];
                $streakNum = (int) substr($focusTeam['streak'], 1);
                $theirGame = $findTeamGame($focusTeam);

                if ($columnistKey === 'terry_hollis') {
                    $headline = "{$authorName}: The {$name}'s Coach Has a {$streakNum}-Game Losing Streak and Zero Answers";
                    $paragraphs[] = "I am going to say what everyone in the building is thinking: the {$name}'s coaching staff is on borrowed time.";
                    if ($theirGame) { $paragraphs[] = "After " . $gameResult($theirGame) . ", that is now {$streakNum} straight losses. In a league where every game matters, that is an eternity."; }
                    $paragraphs[] = "At {$record}, the record tells one story. The losing streak tells another. And the losing streak is the one ownership is reading right now.";
                    $paragraphs[] = "I have seen this movie before. A team with talent that cannot get out of its own way. Questionable play-calling. No adjustments at halftime. Players freelancing because they have lost faith in the scheme.";
                    $paragraphs[] = "Is it fair to put all of that on the head coach? Maybe not. But that is the job. You get the credit when things go right, and you take the heat when they go wrong.";
                    $paragraphs[] = "The next two games will decide whether this coaching staff sees December or starts updating their resumes.";
                    $paragraphs[] = "The seat is not warm. It is on fire.";
                } elseif ($columnistKey === 'dana_reeves') {
                    $headline = "{$authorName}: Inside the Numbers Behind the {$name}'s {$streakNum}-Game Slide";
                    $paragraphs[] = "Losing streaks are rarely about one thing. They are the intersection of multiple failing systems. For the {$name} at {$record}, the data reveals a team breaking down on several fronts.";
                    if ($theirGame) { $paragraphs[] = "Sunday's loss — " . $gameResult($theirGame) . " — extended the skid to {$streakNum} straight."; }
                    $tg = max(1, (int) $focusTeam['wins'] + (int) $focusTeam['losses']);
                    $paragraphs[] = "The {$name} are allowing " . round((int) $focusTeam['points_against'] / $tg, 1) . " points per game. Their offensive rating of " . ($focusTeam['offense_rating'] ?? '??') . " suggests the play-calling is becoming predictable.";
                    $paragraphs[] = "The talent on the roster — rated " . ($focusTeam['overall_rating'] ?? '??') . " overall — suggests this is a scheme or preparation issue, not a personnel issue. That arrow points directly at the coaching staff.";
                    $paragraphs[] = "Whether a coaching change happens or not, something fundamental must shift in the approach.";
                    $paragraphs[] = "The numbers do not lie. And right now, they are telling an uncomfortable truth about the {$name}.";
                } else {
                    $headline = "{$authorName}: The Walls Are Closing In on the {$name}";
                    $paragraphs[] = "There is a loneliness to losing in the NFL that outsiders cannot understand. The bus rides get quieter. The film sessions get tenser. The hallways empty faster after practice.";
                    if ($theirGame) { $paragraphs[] = "After " . $gameResult($theirGame) . ", the {$name} have now lost {$streakNum} straight. And you can feel the walls closing in."; }
                    $paragraphs[] = "At {$record}, this team started the season with real expectations. Real hope. Now there is something different in the air — the unmistakable scent of a season slipping away.";
                    $paragraphs[] = "The coach faces the media every day. The answers are getting shorter. The questions are getting harder.";
                    $paragraphs[] = "In this league, you are always coaching for your job. But some weeks, you feel it more than others. For the {$name}'s staff, every Sunday now feels like a referendum.";
                    $paragraphs[] = "Can they turn it around? Stranger things have happened. But {$streakNum} straight losses creates a gravity that is almost impossible to escape.";
                    $paragraphs[] = "The next game is not just a football game. It is a lifeline. And lifelines do not come around twice.";
                }
                break;

            case 'rookie_breakout':
                $rpName = $topPerformerName ?? 'a young star';
                $rpStats = $weekTopPerformer['stats'] ?? [];
                $rpStatLine = '';
                if (($rpStats['pass_yards'] ?? 0) > 0) { $rpStatLine = ($rpStats['pass_yards'] ?? 0) . " yards passing, " . ($rpStats['pass_tds'] ?? 0) . " TDs"; }
                elseif (($rpStats['rush_yards'] ?? 0) > 0) { $rpStatLine = ($rpStats['rush_yards'] ?? 0) . " rushing yards, " . ($rpStats['rush_tds'] ?? 0) . " TDs"; }
                elseif (($rpStats['rec_yards'] ?? 0) > 0) { $rpStatLine = ($rpStats['receptions'] ?? 0) . " catches for " . ($rpStats['rec_yards'] ?? 0) . " yards and " . ($rpStats['rec_tds'] ?? 0) . " TDs"; }
                $rpAbbr = ($weekTopGame && $weekTopPerformer) ? ($weekTopPerformer['side'] === 'home' ? $weekTopGame['home_abbr'] : $weekTopGame['away_abbr']) : '';

                if ($columnistKey === 'terry_hollis') {
                    $headline = "{$authorName}: {$rpName} Just Announced Himself to the League";
                    $paragraphs[] = "I do not get excited about rookies. I have seen too many flash-in-the-pan performances. But what {$rpName} did on Sunday? That was different.";
                    if ($rpStatLine) { $paragraphs[] = "{$rpStatLine}. For a rookie. In Week {$week}. Against NFL competition. That is not a fluke — that is a player who belongs."; }
                    $paragraphs[] = "What impressed me most was not the numbers. It was the poise. Rookies are supposed to look lost. {$rpName} played like a 10-year veteran.";
                    $paragraphs[] = "The {$rpAbbr} front office has to be feeling pretty good about that draft pick right about now.";
                    $paragraphs[] = "Now, one game does not make a career. The question is consistency. Can {$rpName} do this again next Sunday? And the Sunday after that?";
                    $paragraphs[] = "If the answer is yes, this league has a new problem. And it is wearing a {$rpAbbr} uniform.";
                } elseif ($columnistKey === 'dana_reeves') {
                    $headline = "{$authorName}: Breaking Down {$rpName}'s Breakout Performance by the Numbers";
                    $paragraphs[] = "Rookie breakout performances require context. A big stat line against prevent defense in garbage time is meaningless. What {$rpName} did on Sunday was anything but meaningless.";
                    if ($rpStatLine && $weekTopGame) { $paragraphs[] = "The final line: {$rpStatLine} in the {$rpAbbr}'s " . max((int) $weekTopGame['home_score'], (int) $weekTopGame['away_score']) . "-" . min((int) $weekTopGame['home_score'], (int) $weekTopGame['away_score']) . " result."; }
                    $paragraphs[] = "What the raw numbers do not capture is the efficiency. This was high-quality, high-leverage production that directly influenced the outcome.";
                    $paragraphs[] = "For context, only a handful of rookies in league history have posted a performance this strong in Week {$week} of their first season. The historical comparisons are flattering.";
                    $paragraphs[] = "The sample size is still small. But the traits that drive production at this level — instincts, processing speed, physical tools — are not week-to-week variance. They are foundational.";
                    $paragraphs[] = "Keep an eye on {$rpName}. The data suggests this is the beginning, not the peak.";
                } else {
                    $headline = "{$authorName}: Remember the Name — {$rpName} Has Arrived";
                    $paragraphs[] = "Some debuts are quiet. A few nice plays, a modest stat line. And then there is what {$rpName} did on Sunday.";
                    if ($rpStatLine) { $paragraphs[] = "{$rpStatLine}. In a game that mattered. Against players who have been doing this for years. The kid walked into an NFL stadium and played like he owned the place."; }
                    $paragraphs[] = "There was a moment in the second half where you could see the opposing defense exchange a glance. That look. The one that says: this kid is for real, and we have no answer.";
                    $paragraphs[] = "Every generation of football has a moment like this. A young player who announces himself not with words, but with plays that make you stand up from your couch.";
                    $paragraphs[] = "The {$rpAbbr} knew they had something when they drafted him. But knowing and seeing are different things. On Sunday, everyone saw.";
                    $paragraphs[] = "Welcome to the league, {$rpName}. Something tells me you are going to be here for a long time.";
                }
                break;

            case 'statement_game':
                $g = $focusGame ?? $weekGames[0] ?? null;
                if (!$g) break;
                $hs = (int) $g['home_score']; $as = (int) $g['away_score']; $wIsH = $hs > $as;
                $name = $wIsH ? $g['home_city'] . ' ' . $g['home_name'] : $g['away_city'] . ' ' . $g['away_name'];
                $lName = $wIsH ? $g['away_city'] . ' ' . $g['away_name'] : $g['home_city'] . ' ' . $g['home_name'];
                $wS = max($hs, $as); $lS = min($hs, $as); $mar = $wS - $lS;
                $focusTeam = $focusTeam ?? $teamsById[$wIsH ? (int) $g['home_team_id'] : (int) $g['away_team_id']] ?? null;
                $teamId = $focusTeam ? (int) $focusTeam['id'] : null;
                $record = $focusTeam ? $focusTeam['wins'] . '-' . $focusTeam['losses'] : '';

                if ($columnistKey === 'terry_hollis') {
                    $headline = "{$authorName}: {$name} {$wS}, {$lName} {$lS} — That Was a Statement";
                    $paragraphs[] = "There are wins, and then there are statements. What the {$name} did to the {$lName} on Sunday was a statement.";
                    $paragraphs[] = "{$wS}-{$lS}. A {$mar}-point demolition. That is one team imposing its will on another from the opening snap to the final whistle.";
                    $paragraphs[] = "The {$name} came out and played bully ball. They ran the football. They hit on defense. They dominated the line of scrimmage. That is how you send a message.";
                    $paragraphs[] = "At {$record}, the {$name} just served notice. If you want to win this division, you are going to have to go through them.";
                    $paragraphs[] = "As for the {$lName}? They need to take a long look in the mirror. You cannot get beat like that — not at this level — and pretend everything is fine.";
                    $paragraphs[] = "Games like this separate the men from the boys. The {$name} looked like men on Sunday.";
                } elseif ($columnistKey === 'dana_reeves') {
                    $headline = "{$authorName}: Dissecting the {$name}'s {$wS}-{$lS} Demolition of {$lName}";
                    $paragraphs[] = "A {$mar}-point victory demands analysis. Was this a genuine mismatch, or a scheme-specific collapse? The data suggests the former.";
                    $paragraphs[] = "The {$name} at {$record} dominated both sides of the ball. The margin was not inflated by garbage time — the game was effectively over by the third quarter.";
                    $box = json_decode($g['box_score'] ?? '{}', true);
                    $wSide = $wIsH ? 'home' : 'away'; $tPY = 0; $tRY = 0;
                    foreach (($box[$wSide]['stats'] ?? []) as $s) { $tPY += (int) ($s['pass_yards'] ?? 0); $tRY += (int) ($s['rush_yards'] ?? 0); }
                    if ($tPY > 0 || $tRY > 0) { $paragraphs[] = "Offensively, the {$name} accumulated {$tPY} passing yards and {$tRY} rushing yards. That kind of balanced attack is nearly impossible to defend."; }
                    $paragraphs[] = "The concerning part for the {$lName} is how systematic the defeat was. This was a fundamental talent and preparation gap on full display.";
                    $paragraphs[] = "For the {$name}, this performance raises their ceiling. A team capable of this kind of output can beat anyone in the league.";
                    $paragraphs[] = "The question now is consistency. Can they replicate this next week? The metrics say yes.";
                } else {
                    $headline = "{$authorName}: The {$name} Left No Doubt — {$wS}-{$lS} Was a Reckoning";
                    $paragraphs[] = "Some games are about the final score. This game was about the silence. The silence on the {$lName}'s sideline. The silence of a team that had no answers.";
                    $paragraphs[] = "The {$name} won {$wS}-{$lS}, and it was not even that close. From the first drive, there was a relentlessness that made the outcome feel inevitable.";
                    $paragraphs[] = "You could see the moment the {$lName} broke. It was not one play. It was a sequence — a fourth-down stop, a long scoring drive, a defensive stand. The accumulation of excellence that turns a rout into a memory.";
                    $paragraphs[] = "At {$record}, the {$name} are not just winning. They are making opponents quit. And in this league, that is the most frightening thing a team can do.";
                    $paragraphs[] = "The {$lName} will regroup. But the image of Sunday's defeat will linger. It will be there in the film room. It will be there in their dreams.";
                    $paragraphs[] = "The {$name} did not just win a football game. They made a declaration.";
                }
                break;

            case 'playoff_race':
                $topNames = [];
                foreach (array_slice($teams, 0, 8) as $t) { $topNames[] = $t['city'] . ' ' . $t['name'] . ' (' . $t['wins'] . '-' . $t['losses'] . ')'; }
                $keyM = null;
                foreach ($weekGames as $g) { if ((int) $g['home_wins'] >= 4 && (int) $g['away_wins'] >= 4) { $keyM = $g; break; } }

                if ($columnistKey === 'terry_hollis') {
                    $headline = "{$authorName}: This Playoff Race Is Going to Break Hearts — and Sunday Was Just the Start";
                    $paragraphs[] = "Buckle up. This playoff race is going to be brutal. And I love it.";
                    if ($keyM) { $paragraphs[] = "Sunday gave us a taste: " . $gameResult($keyM) . ". That is a game with playoff implications on every single snap."; }
                    $paragraphs[] = "Look at the standings: " . implode(', ', array_slice($topNames, 0, 6)) . ". There is no separation. One bad loss and you are on the outside looking in.";
                    $paragraphs[] = "This is when the real football starts. Forget September. This is the stretch that separates the teams that want it from the teams that just think they do.";
                    $paragraphs[] = "Depth matters now. Toughness matters now. The teams that are fundamentally sound will survive. The teams that have been getting by on talent alone are about to get exposed.";
                    $paragraphs[] = "I have seen playoff races like this before. They are chaos. They are heartbreak. And they are the best thing about this sport.";
                    $paragraphs[] = "Every game from here on out is a playoff game. Act accordingly.";
                } elseif ($columnistKey === 'dana_reeves') {
                    $headline = "{$authorName}: Breaking Down the Playoff Probabilities — Why Every Game Now Is a Coin Flip";
                    $paragraphs[] = "The playoff race has reached the point where the math becomes fascinating and terrifying in equal measure.";
                    if ($keyM) { $paragraphs[] = "Case in point: " . $gameResult($keyM) . ". That single result shifted playoff probabilities across the entire conference."; }
                    $paragraphs[] = "The current standings: " . implode(', ', array_slice($topNames, 0, 6)) . ". The win differential between the top seed and the last wild card spot is razor thin.";
                    $wL = max(0, 18 - $week);
                    $paragraphs[] = "With {$wL} weeks remaining, there are dozens of tiebreaker scenarios in play. Conference record, division record, head-to-head, strength of victory — all could decide who plays in January.";
                    $paragraphs[] = "Strength of remaining schedule is critical. Some contenders face brutal closing stretches, while others have a path that opens up.";
                    $paragraphs[] = "My projection model gives at least eight teams a 20% or better chance at a playoff berth. That kind of parity is extraordinary.";
                    $paragraphs[] = "Watch the tiebreakers. They may end up mattering more than anyone thinks.";
                } else {
                    $headline = "{$authorName}: The Playoff Race Is a War of Attrition — and Nobody Is Backing Down";
                    $paragraphs[] = "There is something beautiful about a playoff race that has no clear favorite. A race where every game reshuffles the deck.";
                    if ($keyM) { $paragraphs[] = "Sunday delivered exactly that. " . ucfirst($gameResult($keyM)) . " in a game that had the intensity of a playoff atmosphere weeks early."; }
                    $paragraphs[] = "Look at these teams: " . implode(', ', array_slice($topNames, 0, 6)) . ". Every single one of them believes they are going to be the last team standing.";
                    $paragraphs[] = "That is what makes this time of the season so intoxicating. The stakes are real. One loss can be the difference between hosting a playoff game and watching one from your couch.";
                    $paragraphs[] = "And somewhere out there is a team that does not know it yet, but their season will end on a last-second field goal, or a controversial call, or a fumble in the red zone.";
                    $paragraphs[] = "But that is the deal we make as fans. The highs are higher because the lows are lower.";
                    $paragraphs[] = "Enjoy this race. It will not last forever. And when it is over, you will wish it had lasted one more week.";
                }
                break;

            case 'division_race':
                $name = $focusTeam['city'] . ' ' . $focusTeam['name'];
                $dKey = ($focusTeam['conference'] ?? '') . ' ' . ($focusTeam['division'] ?? '');
                $dTeams = $divisionStandings[$dKey] ?? [];
                $headline = "{$authorName}: The " . ($focusTeam['division'] ?? '') . " Division Race Is the Best Storyline in Football";
                $paragraphs[] = "Forget the overall standings. The best story in football right now is the " . ($focusTeam['conference'] ?? '') . " " . ($focusTeam['division'] ?? '') . " Division.";
                $dLines = [];
                foreach ($dTeams as $dt) { $dLines[] = $dt['city'] . ' ' . $dt['name'] . ' (' . $dt['wins'] . '-' . $dt['losses'] . ')'; }
                $paragraphs[] = "The standings: " . implode(', ', $dLines) . ". It does not get much tighter than that.";
                if ($columnistKey === 'terry_hollis') {
                    $paragraphs[] = "Division rivalries are what this sport was built on. These teams know each other. They hate each other. And they are going to beat the hell out of each other over the next few weeks.";
                    $paragraphs[] = "The team that wins this division will earn it the old-fashioned way — in the trenches, in the fourth quarter, in the games that everyone remembers.";
                } elseif ($columnistKey === 'dana_reeves') {
                    $paragraphs[] = "The head-to-head matchups remaining will likely decide the winner. Division record is the first tiebreaker, and with this level of parity, every divisional game is worth double.";
                    $paragraphs[] = "The metrics favor the team with the best point differential within the division, and right now that edge is razor thin.";
                } else {
                    $paragraphs[] = "There is something about a division race that brings out the best in everyone involved. The familiarity. The history. The knowledge that you will see these opponents again and again.";
                    $paragraphs[] = "This race will come down to one game. One moment. One play that everyone in this division will remember for years.";
                }
                $paragraphs[] = "Circle the remaining divisional matchups on your calendar. These are the games that will decide everything.";
                $paragraphs[] = "And if you are a fan of any of these teams? I hope your heart is strong. You are going to need it.";
                break;

            case 'sleeper':
            case 'nobody_talking_about':
                $name = $focusTeam['city'] . ' ' . $focusTeam['name'];
                $record = $focusTeam['wins'] . '-' . $focusTeam['losses'];
                $sN = (int) substr($focusTeam['streak'] ?? 'W0', 1);
                $pf = (int) $focusTeam['points_for']; $pa = (int) $focusTeam['points_against'];
                $tg = max(1, (int) $focusTeam['wins'] + (int) $focusTeam['losses']);
                $ppg = round($pf / $tg, 1);
                $theirGame = $findTeamGame($focusTeam);

                if ($columnistKey === 'terry_hollis') {
                    $headline = "{$authorName}: The Team Nobody Is Talking About? The {$name}. They Should Be.";
                    $paragraphs[] = "You want to know who is being slept on right now? The {$name}. And I am tired of it.";
                    if ($theirGame) { $paragraphs[] = "They just went out and " . $gameResult($theirGame) . ". That is {$sN} wins in a row. And nobody blinked."; }
                    $paragraphs[] = "At {$record}, the {$name} are not flashy. They are not sexy. They are just winning football games the old-fashioned way — with defense, discipline, and grit.";
                    $paragraphs[] = "This team reminds me of the teams I grew up watching. Lunch pail teams. Teams that show up, do their job, and let the scoreboard do the talking.";
                    $paragraphs[] = "The league is fixated on the big-name franchises. Fine. Let them look the other way. The {$name} do not need the attention. They just need the wins.";
                    $paragraphs[] = "And they are getting them. Quietly, relentlessly, and without apology.";
                    $paragraphs[] = "Do not say I did not warn you.";
                } elseif ($columnistKey === 'dana_reeves') {
                    $headline = "{$authorName}: The Data Loves the {$name} — Why Does Nobody Else?";
                    $paragraphs[] = "I want to highlight a disconnect between perception and reality. The {$name} are {$record}. They have won {$sN} straight. And they are virtually absent from the conversation.";
                    if ($theirGame) { $paragraphs[] = "This week: " . $gameResult($theirGame) . ". Another win. Another yawn from the national media."; }
                    $pd = $pf - $pa; $pds = $pd >= 0 ? "+{$pd}" : (string) $pd;
                    $paragraphs[] = "The {$name} are averaging {$ppg} points per game with a point differential of {$pds}. Their overall rating of " . ($focusTeam['overall_rating'] ?? '??') . " is competitive with teams getting far more attention.";
                    $paragraphs[] = "Market size bias is real in sports media. The {$name} are not dominant — but they are consistently good, and consistency is the most underrated quality in football.";
                    $paragraphs[] = "The model I run gives the {$name} a legitimate shot at a playoff berth. Not as a favorite, but as the kind of team nobody wants to face in January.";
                    $paragraphs[] = "Pay attention. The data has been screaming about this team for weeks. It is time the rest of us started listening.";
                } else {
                    $headline = "{$authorName}: The {$name} Are Writing the Best Underdog Story in the League";
                    $paragraphs[] = "Nobody picked the {$name}. And yet here they are, {$record}, winners of {$sN} straight, playing with a fire that comes from having absolutely nothing to lose.";
                    if ($theirGame) { $paragraphs[] = "Sunday's win — " . $gameResult($theirGame) . " — was the latest chapter in a season that is starting to feel like something special."; }
                    $paragraphs[] = "Every locker room has a vibe. Championship teams have an intensity. Rebuilding teams have resignation. But the {$name}? They have joy. Pure, uncut, we-are-not-supposed-to-be-here joy.";
                    $paragraphs[] = "That is the most dangerous emotion in sports. When a team plays loose, plays free, plays like every game is a bonus — that is when legends are born.";
                    $paragraphs[] = "I am not saying the {$name} are going to win the championship. I am saying they might not care. And a team that does not care about the outcome — only the moment — can beat anyone on any given Sunday.";
                    $paragraphs[] = "This is why we watch. Not for the dynasties. For the stories. And the {$name} are telling a great one.";
                }
                break;

            case 'run_defense_problem':
                $name = $focusTeam['city'] . ' ' . $focusTeam['name'];
                $record = $focusTeam['wins'] . '-' . $focusTeam['losses'];
                $g = $focusGame ?? $weekGames[0];
                $headline = "{$authorName}: The {$name} Have a Run Defense Problem, and Sunday Proved It";
                if ($columnistKey === 'terry_hollis') {
                    $paragraphs[] = "You want to know what keeps a defensive coordinator up at night? What I saw the {$name}'s defense do on Sunday.";
                    $paragraphs[] = "After " . $gameResult($g) . ", the film is going to be ugly. The {$name} got gashed on the ground. Repeatedly. Systematically.";
                    $paragraphs[] = "At {$record}, the {$name} have talent. But talent means nothing if you cannot stop the run. I have been saying this for 30 years: you win in the trenches. The {$name}'s trenches are getting pushed around.";
                    $paragraphs[] = "Every offensive coordinator in the league is watching that film right now, licking their chops.";
                    $paragraphs[] = "You want to fix it? Get bigger. Get tougher. Stop trying to be cute with your scheme and just beat the man in front of you.";
                    $paragraphs[] = "If they do not fix this fast, it does not matter what the offense does. You cannot outscore a problem this fundamental.";
                } elseif ($columnistKey === 'dana_reeves') {
                    $paragraphs[] = "The {$name}'s run defense has been a concern all season. Sunday, " . $gameResult($g) . ", and the rushing numbers told the story.";
                    $paragraphs[] = "At {$record}, the defensive rating of " . ($focusTeam['defense_rating'] ?? '??') . " masks a significant schematic vulnerability. The gap between their pass defense and run defense is growing every week.";
                    $paragraphs[] = "The issue appears structural. The front alignment is struggling to maintain gap integrity, and the linebackers are consistently arriving late to the point of attack.";
                    $paragraphs[] = "Until this is addressed, the {$name} have a ceiling. And that ceiling is lower than their talent suggests.";
                    $paragraphs[] = "The numbers do not lie. And right now, they are telling a story the {$name} do not want to hear.";
                } else {
                    $paragraphs[] = "There is a moment in every game where you can see a defense break. Not physically — mentally. The moment where the running back hits the hole and there is nothing but green grass.";
                    $paragraphs[] = "That moment happened on Sunday for the {$name}. " . ucfirst($gameResult($g)) . ", and the ground game was the story.";
                    $paragraphs[] = "At {$record}, the {$name} have playoff aspirations. But playoff teams do not get run out of the building on the ground.";
                    $paragraphs[] = "Run defense is about effort, angles, and trust. When that trust breaks down, running backs feast.";
                    $paragraphs[] = "The {$name} need to find that trust again. And they need to find it fast, because the schedule is not getting any easier.";
                }
                break;

            case 'i_was_wrong':
                $name = $focusTeam['city'] . ' ' . $focusTeam['name'];
                $record = $focusTeam['wins'] . '-' . $focusTeam['losses'];
                $d = (int) $focusTeam['points_for'] - (int) $focusTeam['points_against'];
                $ds = $d >= 0 ? "+{$d}" : (string) $d;
                $headline = "{$authorName}: I Was Wrong About the {$name} — And I Am Not Afraid to Admit It";
                if ($columnistKey === 'terry_hollis') {
                    $paragraphs[] = "I have been doing this job long enough to know when I need to eat crow. So here goes: I was wrong about the {$name}.";
                    $paragraphs[] = "Before the season, I did not give them a chance. At {$record}, they have not just been competitive — they have been dominant.";
                    $paragraphs[] = "A {$ds} point differential does not lie. This team is not winning on luck or schedule.";
                    $paragraphs[] = "The coaching staff deserves credit. They took a roster I underestimated and built an identity around it. They play tough, physical football. They do not beat themselves.";
                    $paragraphs[] = "I am not too proud to say it: I was wrong. And if the {$name} keep playing like this, I will be wrong all the way to the playoffs.";
                    $paragraphs[] = "That is fine with me. I would rather be wrong and honest than right and silent.";
                } elseif ($columnistKey === 'dana_reeves') {
                    $paragraphs[] = "Good analysis requires intellectual honesty. The {$name} have outperformed every model I ran.";
                    $paragraphs[] = "At {$record} with a {$ds} point differential, the preseason data suggested a middle-of-the-pack team. The actual performance has been significantly above that baseline.";
                    $paragraphs[] = "Where did the models go wrong? Chemistry, coaching adjustments, player development that exceeded projections. The variables hardest to quantify.";
                    $paragraphs[] = "Their offensive rating of " . ($focusTeam['offense_rating'] ?? '??') . " and defensive rating of " . ($focusTeam['defense_rating'] ?? '??') . " both exceed expectations. This is a genuinely good team.";
                    $paragraphs[] = "The lesson is humbling: projection models are probabilities, not certainties. The {$name} have made that point emphatically.";
                    $paragraphs[] = "I will update my models. But more importantly, I will update my expectations. The {$name} have earned that.";
                } else {
                    $paragraphs[] = "I owe the {$name} an apology. And I owe their fans one too.";
                    $paragraphs[] = "Before the season, I wrote them off. At {$record}, with a {$ds} point differential, the {$name} have been one of the best stories in the league. And I almost missed it.";
                    $paragraphs[] = "The best stories are never the ones you see coming. They are the ones that sneak up on you, that force you to reconsider everything you thought you knew.";
                    $paragraphs[] = "The {$name} forced me to reconsider. And I am grateful for it.";
                    $paragraphs[] = "I was wrong. They were right. And the story is still being written.";
                }
                break;

            case 'strength_of_schedule':
                $bt = $teams[0]; $name = $bt['city'] . ' ' . $bt['name']; $record = $bt['wins'] . '-' . $bt['losses'];
                $focusTeam = $bt; $teamId = (int) $bt['id'];
                $headline = "{$authorName}: Is {$name}'s {$record} Record for Real? The Schedule Says...";
                if ($columnistKey === 'terry_hollis') {
                    $paragraphs[] = "Every time a team jumps out to a great record, the same question comes up: who have they actually beaten?";
                    $paragraphs[] = "The {$name} are {$record}. That looks great. But have they beaten anyone that matters? In my experience, teams that feast on weak schedules are the first ones bounced from the playoffs.";
                    $paragraphs[] = "I want to see this team against the best the league has to offer. In a hostile environment, in a game that matters. Until then, I am reserving judgment.";
                    $paragraphs[] = "The second half of the schedule gets tougher. Records built on cupcakes crumble when the real games arrive.";
                    $paragraphs[] = "If they come out of the back half with that record intact, I will tip my cap. But not yet.";
                    $paragraphs[] = "Show me something. Then we will talk.";
                } elseif ($columnistKey === 'dana_reeves') {
                    $paragraphs[] = "Raw record is the most misleading statistic in sports. For the {$name} at {$record}, the context requires examination.";
                    $bd = (int) $bt['points_for'] - (int) $bt['points_against']; $bds = $bd >= 0 ? "+{$bd}" : (string) $bd;
                    $paragraphs[] = "Their point differential of {$bds} provides some clarity. Large positive differentials correlate with genuine quality regardless of schedule difficulty.";
                    $paragraphs[] = "The remaining schedule will serve as the ultimate test. If the {$name} maintain their current efficiency against tougher opponents, the debate is over.";
                    $paragraphs[] = "The data is suggestive but not conclusive. The next four weeks will resolve the question definitively.";
                } else {
                    $paragraphs[] = "At {$record}, the narrative is seductive. But is this the real thing, or a mirage built on a favorable schedule?";
                    $paragraphs[] = "The answer lies in the games ahead. The schedule gets harder. The opponents get better. The lights get brighter.";
                    $paragraphs[] = "I have seen both outcomes. Great records that held up and great records that evaporated like morning fog. The difference: the truly great teams do not care who they play.";
                    $paragraphs[] = "Does that describe the {$name}? We are about to find out. And the answer will define their season.";
                }
                break;

            case 'bye_week_analysis':
                $headline = "{$authorName}: Week {$week} Bye Week Report — Who Benefits, Who Does Not";
                $byeTeams = [];
                foreach ($teams as $t) {
                    $onBye = true;
                    foreach ($weekGames as $g) { if ((int) $g['home_team_id'] === (int) $t['id'] || (int) $g['away_team_id'] === (int) $t['id']) { $onBye = false; break; } }
                    if ($onBye) $byeTeams[] = $t;
                }
                if (!empty($byeTeams)) {
                    $bn = []; foreach ($byeTeams as $bt) { $bn[] = $bt['city'] . ' ' . $bt['name'] . ' (' . $bt['wins'] . '-' . $bt['losses'] . ')'; }
                    $paragraphs[] = "This week's bye teams: " . implode(', ', $bn) . ". A week off is either a blessing or a curse.";
                } else { $paragraphs[] = "A lighter schedule this week gives us a chance to evaluate the landscape."; }
                if ($columnistKey === 'terry_hollis') {
                    $paragraphs[] = "Bye weeks are overrated. Momentum is the most valuable thing in football, and a bye week kills momentum.";
                    $paragraphs[] = "The teams on a roll want to keep playing. The teams that are struggling benefit from a reset.";
                } elseif ($columnistKey === 'dana_reeves') {
                    $paragraphs[] = "Teams coming off a bye win at a slightly higher rate, primarily due to health benefits rather than preparation advantages.";
                    $paragraphs[] = "The real value is injury recovery. Teams with key players nursing ailments benefit disproportionately.";
                } else {
                    $paragraphs[] = "For the fans, a bye is agony. For the coaches, it is when the real work happens. Film study intensifies. Scheme adjustments are installed.";
                    $paragraphs[] = "The teams that emerge from the bye looking sharper are the well-coached teams. The teams that come out flat? That tells you something too.";
                }
                if (!empty($weekGames)) {
                    $gr = []; foreach (array_slice($weekGames, 0, 4) as $g) { $gr[] = $gameResult($g); }
                    $paragraphs[] = "Meanwhile, this week's action delivered: " . implode('; ', $gr) . ".";
                }
                $paragraphs[] = "Enjoy the lighter slate. The stretch run starts next week.";
                break;

            case 'injury_impact':
                $name = $focusTeam['city'] . ' ' . $focusTeam['name'];
                $record = $focusTeam['wins'] . '-' . $focusTeam['losses'];
                $sN = (int) substr($focusTeam['streak'] ?? 'L0', 1);
                $headline = "{$authorName}: Injuries Are Killing the {$name}'s Season — But Is That the Whole Story?";
                $paragraphs[] = "The {$name} are {$record}. They have lost {$sN} straight. And the injury report reads like a war casualty list.";
                if ($columnistKey === 'terry_hollis') {
                    $paragraphs[] = "Every team deals with injuries. The good teams find a way to survive them. The {$name} have not found that way yet.";
                    $paragraphs[] = "I am tired of the injury excuse. The teams that win championships have depth. They have next-man-up mentality.";
                    $paragraphs[] = "The {$name}'s problem is that the drop-off from starter to backup is a cliff, not a step. That is a roster construction failure.";
                    $paragraphs[] = "Build a deeper roster. Develop your backups. That is how you survive an NFL season.";
                } elseif ($columnistKey === 'dana_reeves') {
                    $paragraphs[] = "The {$name}'s performance metrics have declined in direct correlation with accumulated injuries.";
                    $paragraphs[] = "The offensive and defensive ratings — " . ($focusTeam['offense_rating'] ?? '??') . " and " . ($focusTeam['defense_rating'] ?? '??') . " — likely reflect a diminished version of this team.";
                    $paragraphs[] = "The compounding effect of injuries is the real killer. It is not just the missing player — it is the disrupted chemistry, the shuffled assignments.";
                } else {
                    $paragraphs[] = "There is a cruelty to sports injuries that statistics cannot capture. The months of preparation. The dreams. All undone by a collision that lasts half a second.";
                    $paragraphs[] = "The {$name} have been bitten hard. And you can see it — not just in the stats, but in the faces.";
                    $paragraphs[] = "Injuries are part of the game. The teams that plan for them — that build depth, that develop backups — are the teams that survive.";
                }
                $paragraphs[] = "The {$name} cannot control who gets hurt. They can only control how they respond. And right now, the response has not been good enough.";
                $paragraphs[] = "Whether that changes is the question that will define the rest of their season.";
                break;

            case 'weekly_awards':
                $headline = "{$authorName}: Week {$week} Awards — The Best, the Worst, and the Most Surprising";
                $wMVP = null; $wMVPS = 0; $wMVPG = null;
                foreach ($weekGames as $g) {
                    $box = json_decode($g['box_score'] ?? '{}', true);
                    foreach (['home', 'away'] as $side) {
                        foreach (($box[$side]['stats'] ?? []) as $pid => $s) {
                            $sc = ($s['pass_yards'] ?? 0) + ($s['rush_yards'] ?? 0) * 2 + ($s['rec_yards'] ?? 0) * 1.5
                                + ($s['pass_tds'] ?? 0) * 20 + ($s['rush_tds'] ?? 0) * 20 + ($s['rec_tds'] ?? 0) * 20;
                            if ($sc > $wMVPS) { $wMVPS = $sc; $wMVP = ['id' => $pid, 'stats' => $s, 'side' => $side]; $wMVPG = $g; }
                        }
                    }
                }
                if ($wMVP) {
                    $mn = $this->getPlayerName((int) $wMVP['id']);
                    $ma = $wMVP['side'] === 'home' ? $wMVPG['home_abbr'] : $wMVPG['away_abbr'];
                    $ms = $wMVP['stats']; $msl = '';
                    if (($ms['pass_yards'] ?? 0) > 0) { $msl = ($ms['pass_yards'] ?? 0) . " yards, " . ($ms['pass_tds'] ?? 0) . " TDs" . (($ms['interceptions'] ?? 0) > 0 ? ", " . $ms['interceptions'] . " INT" : ""); }
                    elseif (($ms['rush_yards'] ?? 0) > 50) { $msl = ($ms['rush_yards'] ?? 0) . " rush yards, " . ($ms['rush_tds'] ?? 0) . " TDs"; }
                    elseif (($ms['rec_yards'] ?? 0) > 50) { $msl = ($ms['receptions'] ?? 0) . " catches, " . ($ms['rec_yards'] ?? 0) . " yards, " . ($ms['rec_tds'] ?? 0) . " TDs"; }
                    $paragraphs[] = "PLAYER OF THE WEEK: {$mn}, {$ma}. {$msl}. " . $this->pickOne(["There was no one better on any field this week.", "A performance for the ages.", "That is how you put a team on your back.", "Dominant from start to finish."]);
                }
                $uG = null; $uD = 0;
                foreach ($weekGames as $g) {
                    $hs = (int) $g['home_score']; $as = (int) $g['away_score']; $hw = (int) $g['home_wins']; $aw = (int) $g['away_wins'];
                    if ($hs > $as && $hw < $aw && ($aw - $hw) > $uD) { $uD = $aw - $hw; $uG = $g; }
                    elseif ($as > $hs && $aw < $hw && ($hw - $aw) > $uD) { $uD = $hw - $aw; $uG = $g; }
                }
                if ($uG) { $paragraphs[] = "UPSET OF THE WEEK: " . ucfirst($gameResult($uG)) . ". " . $this->pickOne(["Nobody had this one circled, and that is exactly why it happened.", "The underdog proved that records do not play the game — players do.", "If you had this on your pick 'em card, you are a liar."]); }
                $bM = 0; $bG = null;
                foreach ($weekGames as $g) { $m = abs((int) $g['home_score'] - (int) $g['away_score']); if ($m > $bM) { $bM = $m; $bG = $g; } }
                if ($bG && $bM >= 14) { $paragraphs[] = "BLOWOUT OF THE WEEK: " . ucfirst($gameResult($bG)) . ". A {$bM}-point margin. " . $this->pickOne(["That one was over before the second half started.", "Mercy rule, please.", "Utter domination from start to finish."]); }
                if (!empty($scoreboard)) { $paragraphs[] = "FULL SCOREBOARD: " . implode(' / ', $scoreboard); }
                if ($columnistKey === 'terry_hollis') {
                    $paragraphs[] = "Another week in the books. The standings do not lie — and right now, they are telling us exactly who is for real and who is not.";
                    $paragraphs[] = "On to Week " . ($week + 1) . ". The games do not stop. And neither does my column.";
                } elseif ($columnistKey === 'dana_reeves') {
                    $paragraphs[] = "Week {$week} provided a significant data set. The trends are crystallizing, and the separation between tiers is becoming statistically significant.";
                    $paragraphs[] = "The models will be updated. And next week, we will have even more data. That is the beauty of a long season.";
                } else {
                    $paragraphs[] = "Every week in this league writes new stories and closes old ones. Week {$week} was dramatic, unpredictable, and absolutely unforgettable.";
                    $paragraphs[] = "What will Week " . ($week + 1) . " bring? I have no idea. And that is exactly why I cannot wait.";
                }
                break;

            case 'wild_card_react':
                $headline = "{$authorName}: Wild Card Weekend Delivered — and Then Some";
                $paragraphs[] = "Wild Card Weekend is always chaos, and this year did not disappoint.";
                foreach ($weekGames as $g) { $paragraphs[] = ucfirst($gameResult($g)) . "."; }
                $paragraphs[] = "Seasons ended. Dreams survived. The bracket is set for the Divisional Round.";
                $paragraphs[] = "The Divisional Round promises even more. The stakes get higher. The margin for error disappears completely.";
                $paragraphs[] = "Buckle up. The real tournament starts now.";
                break;

            case 'divisional_react':
                $headline = "{$authorName}: The Divisional Round Separated the Contenders from the Pretenders";
                $paragraphs[] = "Four games. Four teams eliminated. The Divisional Round is the great separator.";
                foreach ($weekGames as $g) { $paragraphs[] = ucfirst($gameResult($g)) . "."; }
                $paragraphs[] = "We now know our Conference Championship matchups. The four remaining teams have earned the right to play for a trip to The Big Game.";
                $paragraphs[] = "Defense travels. The best quarterbacks find a way. Two games left before The Big Game.";
                $paragraphs[] = "Win, or your season is over.";
                break;

            case 'conference_champ_react':
                $headline = "{$authorName}: Conference Championship Sunday — Two Teams Punch Their Ticket";
                $paragraphs[] = "This is the day when seasons become legacies. Conference Championship Sunday delivered.";
                foreach ($weekGames as $g) { $paragraphs[] = ucfirst($gameResult($g)) . "."; }
                $paragraphs[] = "Two teams celebrated. Two teams had their hearts ripped out.";
                $paragraphs[] = "The Big Game matchup is set. The anticipation is already building.";
                $paragraphs[] = "The Big Game is next. One game. One champion. Nothing else matters.";
                break;

            case 'big_game_react':
                $headline = "{$authorName}: The Big Game Is Over — A Champion Is Crowned";
                $paragraphs[] = "It is over. The confetti has fallen. A champion has been crowned.";
                foreach ($weekGames as $g) { $paragraphs[] = ucfirst($gameResult($g)) . ". That was The Big Game."; }
                $paragraphs[] = "For the winning team, this is the culmination of everything — every early morning, every film session, every fourth-quarter comeback. It all led to this moment.";
                $paragraphs[] = "For the losers, the sting will last all offseason. They will replay every mistake, every missed opportunity, every what-if.";
                $paragraphs[] = "But for now, let us celebrate the champions. They earned it. Every single snap of it.";
                $paragraphs[] = "See you next season.";
                break;

            default: // power_debate
                $bt = $teams[0]; $wt = end($teams);
                $focusTeam = $bt; $teamId = (int) $bt['id'];
                $bR = $bt['wins'] . '-' . $bt['losses']; $wR = $wt['wins'] . '-' . $wt['losses'];
                if ($columnistKey === 'terry_hollis') {
                    $headline = "{$authorName}: I Have Watched Every Team — Here Is Who Is Actually Good";
                    $paragraphs[] = "Every week, someone asks me who the best team is. Every week, I give a different answer. Because nobody has earned the right to be called the best. Not yet.";
                    $paragraphs[] = "The {$bt['city']} {$bt['name']} sit at {$bR}. Fine. But have they beaten anyone I respect?";
                    if (!empty($weekGames)) { $paragraphs[] = "This week, " . $gameResult($weekGames[0]) . ". " . (count($weekGames) > 1 ? "And " . $gameResult($weekGames[1]) . "." : "") . " Did any of that change my mind?"; }
                    $paragraphs[] = "The best team in this league can run the ball and stop the run. Period. Show me a team that can line up, smash the guy in front of them, and move the chains. That is football.";
                    $paragraphs[] = "Meanwhile, the {$wt['city']} {$wt['name']} at {$wR} are already looking ahead to the draft. Good. Because what they have put on the field this season is not NFL-caliber football.";
                    $paragraphs[] = "The cream will rise. It always does. But we are not there yet. Ask me again in three weeks.";
                    $paragraphs[] = "Until then, stop crowning people. Nobody has earned it.";
                } elseif ($columnistKey === 'dana_reeves') {
                    $headline = "{$authorName}: The Power Rankings Debate — What the Data Actually Says About Week {$week}";
                    $paragraphs[] = "Every week, I see power rankings driven more by narrative than data. So let me share what the numbers actually say after Week {$week}.";
                    $bd = (int) $bt['points_for'] - (int) $bt['points_against']; $bds = $bd >= 0 ? "+{$bd}" : (string) $bd;
                    $paragraphs[] = "The {$bt['city']} {$bt['name']} at {$bR} lead in wins. Their point differential of {$bds} is " . ($bd > 50 ? "elite" : "solid") . ".";
                    if (!empty($weekGames)) { $paragraphs[] = "This week's results: " . $gameResult($weekGames[0]) . (count($weekGames) > 1 ? "; " . $gameResult($weekGames[1]) : "") . "."; }
                    $paragraphs[] = "Offensive ratings across the top five: " . implode(', ', array_map(fn($t) => $t['abbreviation'] . ' (' . ($t['offense_rating'] ?? '??') . ')', array_slice($teams, 0, 5))) . ".";
                    $paragraphs[] = "The gap between the haves and have-nots is widening. The {$wt['city']} {$wt['name']} at {$wR} rank near the bottom in both offensive and defensive efficiency.";
                    $paragraphs[] = "The most interesting tier is the middle — teams between .400 and .600 winning percentage. That is where the playoff bubble lives.";
                    $paragraphs[] = "Data does not have a rooting interest. It simply describes reality. And right now, the reality is that this league has clear tiers.";
                } else {
                    $headline = "{$authorName}: Week {$week} Changed the Conversation — And Nobody Is Ready for What Comes Next";
                    $paragraphs[] = "Every week reshuffles the deck. And after Week {$week}, the conversation has shifted again.";
                    if (!empty($weekGames)) { $paragraphs[] = "Consider: " . $gameResult($weekGames[0]) . ". " . (count($weekGames) > 1 ? ucfirst($gameResult($weekGames[1])) . "." : "") . " These are not just scores. They are plot points in a season that refuses to follow the script."; }
                    $paragraphs[] = "The {$bt['city']} {$bt['name']} at {$bR} remain the team to beat. But that title comes with a target.";
                    $paragraphs[] = "At the other end, the {$wt['city']} {$wt['name']} at {$wR} are living a different story — one of patience, development, and hope.";
                    $paragraphs[] = "And in between? That is where the drama lives. The bubble teams. The overachievers. The teams one play away from changing their season.";
                    $paragraphs[] = "This is why football is the greatest sport in the world. Every week, it gives us something we did not expect.";
                    $paragraphs[] = "What will Week " . ($week + 1) . " bring? I have no idea. And I cannot wait to find out.";
                }
                break;
        }

        $body = implode("\n\n", $paragraphs);
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            "INSERT INTO articles (league_id, season_id, week, type, headline, body, author_name, author_persona, team_id, game_id, is_ai_generated, published_at)
             VALUES (?, ?, ?, 'column', ?, ?, ?, ?, ?, NULL, 0, ?)"
        );
        $stmt->execute([$leagueId, $seasonId, $week, $headline, $body, $authorName, $columnistKey, $teamId, $now]);
    }

    // ─── Morning Blitz ───────────────────────────────────────────────

    /**
     * Generate a "Morning Blitz" weekly roundup article with personality,
     * actual player names, hot takes, and detailed sections.
     */
    public function generateMorningBlitz(int $leagueId, int $seasonId, int $week): void
    {
        $games = $this->db->prepare(
            "SELECT g.*, g.home_score, g.away_score, g.box_score,
                    ht.city as home_city, ht.name as home_name, ht.abbreviation as home_abbr, ht.wins as home_wins, ht.losses as home_losses,
                    at.city as away_city, at.name as away_name, at.abbreviation as away_abbr, at.wins as away_wins, at.losses as away_losses
             FROM games g
             JOIN teams ht ON g.home_team_id = ht.id
             JOIN teams at ON g.away_team_id = at.id
             WHERE g.league_id = ? AND g.week = ? AND g.is_simulated = 1"
        );
        $games->execute([$leagueId, $week]);
        $games = $games->fetchAll();

        if (empty($games)) return;

        $sections = [];
        $biggestMargin = 0; $biggestGame = null;
        $smallestMargin = PHP_INT_MAX; $closestGame = null;
        $upsetGame = null; $upsetDiff = 0;
        $highestCombined = 0; $shootoutGame = null;

        // Collect all player stats across all games for top performer lookups
        $allPerformers = [];

        foreach ($games as $g) {
            $hs = (int) $g['home_score']; $as = (int) $g['away_score'];
            $margin = abs($hs - $as);
            $combined = $hs + $as;

            if ($margin > $biggestMargin) { $biggestMargin = $margin; $biggestGame = $g; }
            if ($margin < $smallestMargin) { $smallestMargin = $margin; $closestGame = $g; }
            if ($combined > $highestCombined) { $highestCombined = $combined; $shootoutGame = $g; }

            $homeRecord = (int) $g['home_wins']; $awayRecord = (int) $g['away_wins'];
            if ($hs > $as && $homeRecord < $awayRecord && ($awayRecord - $homeRecord) > $upsetDiff) {
                $upsetDiff = $awayRecord - $homeRecord; $upsetGame = $g;
            } elseif ($as > $hs && $awayRecord < $homeRecord && ($homeRecord - $awayRecord) > $upsetDiff) {
                $upsetDiff = $homeRecord - $awayRecord; $upsetGame = $g;
            }

            // Parse box score for player stats
            $box = json_decode($g['box_score'] ?? '{}', true);
            foreach (['home', 'away'] as $side) {
                $abbr = $side === 'home' ? $g['home_abbr'] : $g['away_abbr'];
                $teamName = $side === 'home' ? $g['home_city'] . ' ' . $g['home_name'] : $g['away_city'] . ' ' . $g['away_name'];
                foreach (($box[$side]['stats'] ?? []) as $pid => $s) {
                    $score = ($s['pass_yards'] ?? 0) + ($s['rush_yards'] ?? 0) * 2 + ($s['rec_yards'] ?? 0) * 1.5
                        + ($s['pass_tds'] ?? 0) * 20 + ($s['rush_tds'] ?? 0) * 20 + ($s['rec_tds'] ?? 0) * 20
                        + ($s['sacks'] ?? 0) * 15 + ($s['interceptions_def'] ?? 0) * 25;
                    $allPerformers[] = ['id' => (int) $pid, 'stats' => $s, 'score' => $score, 'abbr' => $abbr, 'team' => $teamName, 'game' => $g];
                }
            }
        }

        usort($allPerformers, fn($a, $b) => $b['score'] - $a['score']);

        // Helper
        $gameResult = function (array $g): string {
            $hs = (int) $g['home_score']; $as = (int) $g['away_score'];
            $wn = $hs > $as ? $g['home_city'] . ' ' . $g['home_name'] : $g['away_city'] . ' ' . $g['away_name'];
            $ln = $hs > $as ? $g['away_city'] . ' ' . $g['away_name'] : $g['home_city'] . ' ' . $g['home_name'];
            return "the {$wn} beat the {$ln} " . max($hs, $as) . "-" . min($hs, $as);
        };

        // === OPENING PARAGRAPH with personality ===
        $openings = [
            "Good morning, football fans. Pour yourself a coffee, because we have A LOT to talk about.",
            "Rise and shine. If you went to bed early last night, you missed some absolute mayhem.",
            "Morning, folks. Let me set the scene: " . count($games) . " games, " . count($games) . " stories, and zero shortage of drama.",
            "Welcome to the Morning Blitz. I hope you are sitting down, because Week {$week} did not disappoint.",
            "It is Monday morning, your team either won or lost yesterday, and either way, I have opinions. Let us get into it.",
        ];
        $sections[] = $this->pickOne($openings);

        // === BIGGEST WIN with player names ===
        if ($biggestGame) {
            $hs = (int) $biggestGame['home_score']; $as = (int) $biggestGame['away_score'];
            $winnerIsHome = $hs > $as;
            $winnerName = $winnerIsHome ? $biggestGame['home_city'] . ' ' . $biggestGame['home_name'] : $biggestGame['away_city'] . ' ' . $biggestGame['away_name'];
            $loserName = $winnerIsHome ? $biggestGame['away_city'] . ' ' . $biggestGame['away_name'] : $biggestGame['home_city'] . ' ' . $biggestGame['home_name'];
            $wScore = max($hs, $as); $lScore = min($hs, $as);

            // Find the star of that game
            $starLine = '';
            $box = json_decode($biggestGame['box_score'] ?? '{}', true);
            $wSide = $winnerIsHome ? 'home' : 'away';
            $bestPid = null; $bestSc = 0;
            foreach (($box[$wSide]['stats'] ?? []) as $pid => $s) {
                $sc = ($s['pass_yards'] ?? 0) + ($s['rush_yards'] ?? 0) * 2 + ($s['rec_yards'] ?? 0) * 1.5 + ($s['pass_tds'] ?? 0) * 20 + ($s['rush_tds'] ?? 0) * 20 + ($s['rec_tds'] ?? 0) * 20;
                if ($sc > $bestSc) { $bestSc = $sc; $bestPid = $pid; }
            }
            if ($bestPid) {
                $pName = $this->getPlayerName((int) $bestPid);
                $pStats = $box[$wSide]['stats'][$bestPid] ?? [];
                if (($pStats['pass_yards'] ?? 0) > 0) { $starLine = "{$pName} led the way with " . $pStats['pass_yards'] . " passing yards and " . ($pStats['pass_tds'] ?? 0) . " touchdowns."; }
                elseif (($pStats['rush_yards'] ?? 0) > 50) { $starLine = "{$pName} dominated on the ground with " . $pStats['rush_yards'] . " rushing yards and " . ($pStats['rush_tds'] ?? 0) . " touchdowns."; }
                elseif (($pStats['rec_yards'] ?? 0) > 50) { $starLine = "{$pName} was unstoppable with " . ($pStats['receptions'] ?? 0) . " catches for " . $pStats['rec_yards'] . " yards."; }
            }
            $sections[] = "BIGGEST WIN: The {$winnerName} demolished the {$loserName} {$wScore}-{$lScore}. A {$biggestMargin}-point margin that was never in doubt. {$starLine}";
        }

        // === UPSET OF THE WEEK ===
        if ($upsetGame) {
            $hs = (int) $upsetGame['home_score']; $as = (int) $upsetGame['away_score'];
            $uWinner = $hs > $as ? $upsetGame['home_city'] . ' ' . $upsetGame['home_name'] : $upsetGame['away_city'] . ' ' . $upsetGame['away_name'];
            $uWinRecord = $hs > $as ? $upsetGame['home_wins'] . '-' . $upsetGame['home_losses'] : $upsetGame['away_wins'] . '-' . $upsetGame['away_losses'];
            $uLoser = $hs > $as ? $upsetGame['away_city'] . ' ' . $upsetGame['away_name'] : $upsetGame['home_city'] . ' ' . $upsetGame['home_name'];
            $uLoserRecord = $hs > $as ? $upsetGame['away_wins'] . '-' . $upsetGame['away_losses'] : $upsetGame['home_wins'] . '-' . $upsetGame['home_losses'];
            $sections[] = "UPSET OF THE WEEK: The {$uWinner} ({$uWinRecord}) knocked off the {$uLoser} ({$uLoserRecord}), " . max($hs, $as) . "-" . min($hs, $as) . ". " . $this->pickOne([
                "Somebody did not get the memo about who was supposed to win this game.",
                "The {$uLoser} locker room is going to be a dark place this morning.",
                "This is why they play the games, folks. Records mean nothing between the white lines.",
                "I double-checked the score. It is real. The {$uWinner} really did this.",
            ]);
        }

        // === GAME OF THE WEEK ===
        if ($closestGame && $closestGame !== $biggestGame) {
            $hs = (int) $closestGame['home_score']; $as = (int) $closestGame['away_score'];
            $sections[] = "GAME OF THE WEEK: {$closestGame['home_city']} {$closestGame['home_name']} vs {$closestGame['away_city']} {$closestGame['away_name']} — a {$hs}-{$as} " . ($smallestMargin <= 3 ? "thriller" : "nail-biter") . " that came down to the final possession. If you only watch one game replay this week, make it this one.";
        }

        // === OVERREACTION OF THE WEEK ===
        $overreactions = [];
        if ($upsetGame) {
            $hs = (int) $upsetGame['home_score']; $as = (int) $upsetGame['away_score'];
            $uLoser = $hs > $as ? $upsetGame['away_city'] . ' ' . $upsetGame['away_name'] : $upsetGame['home_city'] . ' ' . $upsetGame['home_name'];
            $overreactions[] = "The {$uLoser} are done. Season over. Blow it up. Fire everyone. (OK, maybe not. But it feels that way this morning.)";
        }
        if ($biggestGame) {
            $hs = (int) $biggestGame['home_score']; $as = (int) $biggestGame['away_score'];
            $wN = $hs > $as ? $biggestGame['home_city'] . ' ' . $biggestGame['home_name'] : $biggestGame['away_city'] . ' ' . $biggestGame['away_name'];
            $overreactions[] = "The {$wN} are winning the championship. Book it. Print the t-shirts. It is over. (It is Week {$week}. Calm down. But also... maybe?)";
        }
        if (!empty($overreactions)) {
            $sections[] = "OVERREACTION OF THE WEEK: " . $this->pickOne($overreactions);
        }

        // === STAT THAT WILL BLOW YOUR MIND ===
        $mindBlowers = [];
        foreach ($allPerformers as $p) {
            $s = $p['stats'];
            if (($s['pass_yards'] ?? 0) >= 400) { $mindBlowers[] = $this->getPlayerName($p['id']) . " of the " . $p['abbr'] . " threw for " . $s['pass_yards'] . " yards. FOUR HUNDRED. That is not a typo."; }
            if (($s['rush_yards'] ?? 0) >= 150) { $mindBlowers[] = $this->getPlayerName($p['id']) . " of the " . $p['abbr'] . " rushed for " . $s['rush_yards'] . " yards. In one game. On his own two legs. Incredible."; }
            if (($s['rec_yards'] ?? 0) >= 150) { $mindBlowers[] = $this->getPlayerName($p['id']) . " of the " . $p['abbr'] . " had " . $s['rec_yards'] . " receiving yards. Corners across the league are having nightmares about this guy."; }
            if (($s['pass_tds'] ?? 0) >= 4) { $mindBlowers[] = $this->getPlayerName($p['id']) . " of the " . $p['abbr'] . " threw " . $s['pass_tds'] . " touchdown passes. That is an entire fantasy roster in one player."; }
            if (($s['interceptions_def'] ?? 0) >= 2) { $mindBlowers[] = $this->getPlayerName($p['id']) . " of the " . $p['abbr'] . " had " . $s['interceptions_def'] . " interceptions. Ball-hawk alert."; }
        }
        if (!empty($mindBlowers)) {
            $sections[] = "STAT THAT WILL BLOW YOUR MIND: " . $this->pickOne(array_slice($mindBlowers, 0, 5));
        }

        // === PLAY OF THE WEEK (from game_log if available) ===
        $playOfWeek = null;
        foreach ($games as $g) {
            $box = json_decode($g['box_score'] ?? '{}', true);
            $gameLog = $box['game_log'] ?? [];
            foreach ($gameLog as $entry) {
                $desc = $entry['description'] ?? '';
                if (stripos($desc, 'touchdown') !== false && ($entry['quarter'] ?? 0) >= 4) {
                    $playOfWeek = ['desc' => $desc, 'game' => $g, 'quarter' => $entry['quarter'] ?? 4];
                    break 2;
                }
            }
        }
        if (!$playOfWeek) {
            // Fallback: look for any big play
            foreach ($games as $g) {
                $box = json_decode($g['box_score'] ?? '{}', true);
                $gameLog = $box['game_log'] ?? [];
                foreach ($gameLog as $entry) {
                    $desc = $entry['description'] ?? '';
                    if (stripos($desc, 'touchdown') !== false || stripos($desc, 'interception') !== false) {
                        $playOfWeek = ['desc' => $desc, 'game' => $g, 'quarter' => $entry['quarter'] ?? 0];
                        break 2;
                    }
                }
            }
        }
        if ($playOfWeek) {
            $pg = $playOfWeek['game'];
            $sections[] = "PLAY OF THE WEEK: In the {$pg['home_city']} {$pg['home_name']} vs {$pg['away_city']} {$pg['away_name']} game — " . $playOfWeek['desc'] . " (Q" . $playOfWeek['quarter'] . "). If you have not seen it, find the replay. Trust me.";
        }

        // === BY THE NUMBERS with actual player names ===
        $statLines = [];
        foreach (array_slice($allPerformers, 0, 10) as $p) {
            $s = $p['stats'];
            $pName = $this->getPlayerName($p['id']);
            if (($s['pass_yards'] ?? 0) >= 250) {
                $cmp = ($s['pass_completions'] ?? 0); $att = ($s['pass_attempts'] ?? 0);
                $pct = $att > 0 ? round($cmp / $att * 100) : 0;
                $statLines[] = "{$p['abbr']} QB {$pName}: {$cmp}/{$att} ({$pct}%), " . $s['pass_yards'] . " yards, " . ($s['pass_tds'] ?? 0) . " TDs" . (($s['interceptions'] ?? 0) > 0 ? ", " . $s['interceptions'] . " INT" : "");
            }
            if (($s['rush_yards'] ?? 0) >= 80) {
                $statLines[] = "{$p['abbr']} RB {$pName}: " . ($s['rush_attempts'] ?? 0) . " carries, " . $s['rush_yards'] . " yards, " . ($s['rush_tds'] ?? 0) . " TDs";
            }
            if (($s['rec_yards'] ?? 0) >= 80) {
                $statLines[] = "{$p['abbr']} WR/TE {$pName}: " . ($s['receptions'] ?? 0) . " catches, " . $s['rec_yards'] . " yards, " . ($s['rec_tds'] ?? 0) . " TDs";
            }
            if (($s['sacks'] ?? 0) >= 2) {
                $statLines[] = "{$p['abbr']} {$pName}: " . $s['sacks'] . " sacks. Wrecking ball.";
            }
        }
        if (!empty($statLines)) {
            $sections[] = "BY THE NUMBERS:\n- " . implode("\n- ", array_unique(array_slice($statLines, 0, 6)));
        }

        // === FULL SCOREBOARD ===
        $scoreLines = [];
        foreach ($games as $g) {
            $hs = (int) $g['home_score']; $as = (int) $g['away_score'];
            $marker = $hs > $as ? $g['home_abbr'] : $g['away_abbr'];
            $scoreLines[] = $g['away_abbr'] . ' ' . $as . ', ' . $g['home_abbr'] . ' ' . $hs;
        }
        $sections[] = "SCOREBOARD: " . implode(' | ', $scoreLines);

        // === LOOKING AHEAD — playoff-aware ===
        $isPlayoffs = $week >= 19;
        if ($week === 22) {
            $sections[] = "WHAT A SEASON: That is a wrap. The Big Game is over. What a ride. See you next season.";
        } elseif ($week === 21) {
            $sections[] = "NEXT UP: The Big Game. Two teams remain. One will be crowned champion. Everything else is noise.";
        } elseif ($week === 20) {
            $sections[] = "NEXT UP: The Conference Championships. Four teams left standing. Two will advance. Two will go home devastated.";
        } elseif ($week === 19) {
            $sections[] = "NEXT UP: The Divisional Round. The field is narrowing. Every game is win-or-go-home from here.";
        } elseif ($week === 18) {
            $sections[] = "NEXT UP: The playoffs begin. Wild Card Weekend is here. Six games, six chances to keep your season alive. Who survives?";
        } else {
            // Query next week's schedule for specific matchup teases
            $nextWeek = $week + 1;
            $nextStmt = $this->db->prepare(
                "SELECT g.*, ht.city as home_city, ht.name as home_name, ht.wins as home_wins, ht.losses as home_losses,
                        at.city as away_city, at.name as away_name, at.wins as away_wins, at.losses as away_losses
                 FROM games g
                 JOIN teams ht ON g.home_team_id = ht.id
                 JOIN teams at ON g.away_team_id = at.id
                 WHERE g.league_id = ? AND g.week = ? AND g.is_simulated = 0
                 ORDER BY (ht.wins + at.wins) DESC
                 LIMIT 3"
            );
            $nextStmt->execute([$leagueId, $nextWeek]);
            $nextGames = $nextStmt->fetchAll();

            if (!empty($nextGames)) {
                $previews = [];
                foreach ($nextGames as $ng) {
                    $previews[] = $ng['away_city'] . ' ' . $ng['away_name'] . ' (' . $ng['away_wins'] . '-' . $ng['away_losses'] . ') at ' . $ng['home_city'] . ' ' . $ng['home_name'] . ' (' . $ng['home_wins'] . '-' . $ng['home_losses'] . ')';
                }
                $sections[] = "LOOKING AHEAD TO WEEK {$nextWeek}:\n- " . implode("\n- ", $previews) . "\n\nCircle those matchups. This is where the season gets real.";
            } else {
                $sections[] = "LOOKING AHEAD: Week {$nextWeek} is on deck. The race continues. Stay tuned.";
            }
        }

        // Build headline with variety
        $weekName = $this->weekLabel($week);
        if ($isPlayoffs) {
            $playoffSubs = ['Win or Go Home', 'Elimination Day', 'The Madness Continues', 'Survive and Advance', 'Playoff Drama'];
            if ($week === 22) { $catchySub = 'A Champion Is Crowned'; }
            elseif ($week === 21) { $catchySub = 'Conference Championship Recap'; }
            else { $catchySub = $this->pickOne($playoffSubs); }
        } else {
            $catchySubs = [
                'Upsets, Blowouts, and Drama',
                'The Good, the Bad, and the Ugly',
                'Winners, Losers, and Everything In Between',
                'Shakeups Across the League',
                'Statement Wins and Stunning Upsets',
                'The Week That Changed Everything',
                'Nobody Saw This Coming',
                'Records Shattered, Dreams Crushed',
                'Chaos Reigns Supreme',
                'Heroes and Heartbreak',
            ];
            $catchySub = $this->pickOne($catchySubs);
        }
        $headline = "Morning Blitz: {$weekName} — {$catchySub}";

        $body = implode("\n\n", $sections);
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            "INSERT INTO articles (league_id, season_id, week, type, headline, body, author_name, author_persona, team_id, game_id, is_ai_generated, published_at)
             VALUES (?, ?, ?, 'morning_blitz', ?, ?, 'HC26 Staff', 'staff', NULL, NULL, 0, ?)"
        );
        $stmt->execute([$leagueId, $seasonId, $week, $headline, $body, $now]);
    }

    // ─── Feature Story ───────────────────────────────────────────────

    /**
     * Generate a feature article at key moments in the season.
     * 600-800 words, 8-10 paragraphs, with real data throughout.
     */
    public function generateFeatureStory(int $leagueId, int $seasonId, int $week, string $topic, array $context): void
    {
        $teams = $this->db->prepare("SELECT * FROM teams WHERE league_id = ? ORDER BY wins DESC");
        $teams->execute([$leagueId]);
        $teams = $teams->fetchAll();

        if (empty($teams)) return;

        // Build division standings
        $divisionStandings = [];
        foreach ($teams as $t) {
            $divKey = ($t['conference'] ?? '') . ' ' . ($t['division'] ?? '');
            $divisionStandings[$divKey][] = $t;
        }

        $paragraphs = [];
        $headline = '';
        $authorName = '';
        $authorPersona = '';
        $teamId = $context['team_id'] ?? null;

        switch ($topic) {
            case 'midseason_report':
                $authorName = 'Dana Reeves';
                $authorPersona = 'dana_reeves';
                $headline = "Midseason Report Card: Grading Every Division at the Halfway Mark";

                $paragraphs[] = "We have reached the midpoint of the season, and it is time to take stock. Not team by team — that would take all day — but division by division. Because in this league, divisions tell the real story. Who has exceeded expectations? Who has collapsed? And which division race is going to give us all ulcers by December?";

                $paragraphs[] = "Let us grade every division in the league.";

                foreach ($divisionStandings as $divKey => $divTeams) {
                    $divWins = 0; $divLosses = 0; $divPF = 0; $divPA = 0;
                    $teamLines = [];
                    foreach ($divTeams as $dt) {
                        $divWins += (int) $dt['wins']; $divLosses += (int) $dt['losses'];
                        $divPF += (int) $dt['points_for']; $divPA += (int) $dt['points_against'];
                        $teamLines[] = $dt['city'] . ' ' . $dt['name'] . ' (' . $dt['wins'] . '-' . $dt['losses'] . ')';
                    }
                    $divTotal = $divWins + $divLosses;
                    $divPct = $divTotal > 0 ? $divWins / $divTotal : 0;
                    $divDiff = $divPF - $divPA;

                    // Grade the division
                    if ($divPct >= 0.65) { $grade = 'A'; $desc = 'elite'; }
                    elseif ($divPct >= 0.55) { $grade = 'B+'; $desc = 'strong'; }
                    elseif ($divPct >= 0.50) { $grade = 'B'; $desc = 'competitive'; }
                    elseif ($divPct >= 0.45) { $grade = 'C+'; $desc = 'mediocre'; }
                    elseif ($divPct >= 0.35) { $grade = 'C'; $desc = 'struggling'; }
                    else { $grade = 'D'; $desc = 'rebuilding'; }

                    $topTeam = $divTeams[0]; $bottomTeam = end($divTeams);
                    $spread = (int) $topTeam['wins'] - (int) $bottomTeam['wins'];
                    $raceDesc = $spread <= 1 ? "This is a dogfight — no clear favorite." : ($spread >= 4 ? "One team is running away with it." : "There is a clear frontrunner, but the rest are not out of it yet.");

                    $paragraphs[] = "**{$divKey} — Grade: {$grade}** ({$desc})\n" . implode(', ', $teamLines) . ". Combined record: {$divWins}-{$divLosses} (point differential: " . ($divDiff >= 0 ? "+{$divDiff}" : $divDiff) . "). {$raceDesc}";
                }

                $paragraphs[] = "The second half of the season is where legacies are made. Division races tighten, schedules get harder, and the pretenders get separated from the contenders. What we have seen so far is just the preview.";

                $paragraphs[] = "Expect movement. Expect surprises. And expect at least one division race to come down to the final week. It always does.";
                break;

            case 'playoff_race':
                $authorName = 'Marcus Bell';
                $authorPersona = 'marcus_bell';
                $headline = "The Playoff Picture: Who Controls Their Destiny and Who Needs a Miracle";

                $paragraphs[] = "We are deep enough into the season now that the math matters. Not just wins and losses — the math. Tiebreakers. Conference records. Strength of victory. The invisible threads that will ultimately decide who plays in January and who watches from home.";

                $totalTeams = count($teams);
                $playoffSpots = max(4, (int) ceil($totalTeams * 0.4));

                $inTeams = array_slice($teams, 0, $playoffSpots);
                $bubbleTeams = array_slice($teams, $playoffSpots, 4);
                $outTeams = array_slice($teams, $playoffSpots + 4);

                // Locks
                $lockLines = [];
                foreach (array_slice($inTeams, 0, 3) as $t) {
                    $lockLines[] = $t['city'] . ' ' . $t['name'] . ' (' . $t['wins'] . '-' . $t['losses'] . ')';
                }
                $paragraphs[] = "THE LOCKS: " . implode(', ', $lockLines) . ". These teams control their own destiny. Win and they are in. Barring a catastrophic collapse, these teams are playing in January.";

                // Contending
                $contLines = [];
                foreach (array_slice($inTeams, 3) as $t) {
                    $contLines[] = $t['city'] . ' ' . $t['name'] . ' (' . $t['wins'] . '-' . $t['losses'] . ')';
                }
                if (!empty($contLines)) {
                    $paragraphs[] = "IN THE HUNT: " . implode(', ', $contLines) . ". These teams are in a playoff spot right now, but their margin is thin. One two-game losing streak and suddenly they are on the outside looking in.";
                }

                // Bubble
                if (!empty($bubbleTeams)) {
                    $bubLines = [];
                    foreach ($bubbleTeams as $t) {
                        $weeksLeft = max(0, 18 - $week);
                        $winsNeeded = max(0, (int) $inTeams[count($inTeams) - 1]['wins'] - (int) $t['wins'] + 1);
                        $bubLines[] = $t['city'] . ' ' . $t['name'] . ' (' . $t['wins'] . '-' . $t['losses'] . ') — needs approximately ' . $winsNeeded . ' of remaining ' . $weeksLeft . ' games';
                    }
                    $paragraphs[] = "ON THE BUBBLE:\n- " . implode("\n- ", $bubLines) . "\n\nThis is where it gets agonizing. Every snap matters. Every game is a referendum on their season.";
                }

                // Eliminated/fading
                if (!empty($outTeams)) {
                    $outNames = [];
                    foreach (array_slice($outTeams, 0, 5) as $t) { $outNames[] = $t['abbreviation'] . ' (' . $t['wins'] . '-' . $t['losses'] . ')'; }
                    $paragraphs[] = "FADING FAST: " . implode(', ', $outNames) . ". The math is getting ugly. They would need to win out and get significant help. It is not impossible, but it would require a run for the ages.";
                }

                // Scenarios
                $topTeam = $teams[0];
                $weeksLeft = max(0, 18 - $week);
                $paragraphs[] = "THE CLINCH SCENARIO: If the {$topTeam['city']} {$topTeam['name']} ({$topTeam['wins']}-{$topTeam['losses']}) win " . min($weeksLeft, 3) . " of their next {$weeksLeft} games, they clinch the top seed regardless of what happens elsewhere. But one more loss opens the door for the rest of the conference.";

                $paragraphs[] = "This is December football. This is when it all means something. The margin between a first-round bye and missing the playoffs entirely is often a single game. A single play. A single moment that reverberates through the rest of the season.";

                $paragraphs[] = "Buckle up. The finish line is in sight. And the sprint to get there is going to be spectacular.";
                break;

            case 'trade_deadline':
                $authorName = 'Dana Reeves';
                $authorPersona = 'dana_reeves';
                $headline = "Trade Deadline Report: The Deals, the Rumors, and the Teams That Stood Pat";

                $paragraphs[] = "The trade deadline has come and gone, and as always, it left a trail of winners, losers, and teams wondering what might have been. Let us break down the landscape.";

                // Query actual trades
                $tradeStmt = $this->db->prepare(
                    "SELECT tr.*, pt.city as prop_city, pt.name as prop_name, pt.abbreviation as prop_abbr,
                            rt.city as recv_city, rt.name as recv_name, rt.abbreviation as recv_abbr
                     FROM trades tr
                     JOIN teams pt ON tr.proposing_team_id = pt.id
                     JOIN teams rt ON tr.receiving_team_id = rt.id
                     WHERE tr.league_id = ? AND tr.status = 'accepted'
                     ORDER BY tr.resolved_at DESC
                     LIMIT 10"
                );
                $tradeStmt->execute([$leagueId]);
                $recentTrades = $tradeStmt->fetchAll();

                if (!empty($recentTrades)) {
                    $paragraphs[] = "THE DEALS THAT HAPPENED:";
                    foreach (array_slice($recentTrades, 0, 5) as $tr) {
                        // Get trade items
                        $itemStmt = $this->db->prepare(
                            "SELECT ti.*, p.first_name, p.last_name, p.position, p.overall_rating
                             FROM trade_items ti
                             LEFT JOIN players p ON ti.player_id = p.id
                             WHERE ti.trade_id = ?"
                        );
                        $itemStmt->execute([$tr['id']]);
                        $items = $itemStmt->fetchAll();

                        $toProposer = []; $toReceiver = [];
                        foreach ($items as $item) {
                            $desc = $item['first_name'] ? $item['first_name'] . ' ' . $item['last_name'] . ' (' . $item['position'] . ', ' . $item['overall_rating'] . ' OVR)' : 'Draft pick';
                            if ($item['direction'] === 'to_proposer') { $toProposer[] = $desc; }
                            else { $toReceiver[] = $desc; }
                        }

                        if (!empty($toProposer) || !empty($toReceiver)) {
                            $paragraphs[] = "{$tr['prop_city']} {$tr['prop_name']} traded " . (empty($toReceiver) ? 'assets' : implode(', ', $toReceiver)) . " to {$tr['recv_city']} {$tr['recv_name']} for " . (empty($toProposer) ? 'assets' : implode(', ', $toProposer)) . ". " . $this->pickOne([
                                "A deal that could reshape both franchises.",
                                "The kind of trade where both sides walk away feeling like they won.",
                                "Bold move. We will see how it plays out.",
                                "This trade will be judged by what happens in January.",
                            ]);
                        }
                    }
                } else {
                    $paragraphs[] = "THE DEALS: It was a quiet deadline — quieter than many expected. No blockbuster trades materialized, though that does not mean the phones were not ringing. Sometimes the best trade is the one you do not make.";
                }

                $buyers = array_slice($teams, 0, (int) ceil(count($teams) * 0.3));
                $sellers = array_slice($teams, -(int) ceil(count($teams) * 0.3));

                $buyerNames = []; foreach ($buyers as $t) { $buyerNames[] = $t['city'] . ' ' . $t['name'] . ' (' . $t['wins'] . '-' . $t['losses'] . ')'; }
                $paragraphs[] = "THE BUYERS: " . implode(', ', $buyerNames) . ". These teams are in win-now mode. Every move they make is about maximizing their championship window. The cost of a rental is steep, but so is the cost of a missed opportunity.";

                $sellerNames = []; foreach ($sellers as $t) { $sellerNames[] = $t['city'] . ' ' . $t['name'] . ' (' . $t['wins'] . '-' . $t['losses'] . ')'; }
                $paragraphs[] = "THE SELLERS: " . implode(', ', $sellerNames) . ". The smart play is to stockpile assets for the future. Draft picks and young players are the currency of rebuilding, and these teams should be open for business.";

                $paragraphs[] = "The best trades are the ones that help both sides. A contender gets the missing piece; a rebuilder gets the draft capital to accelerate their timeline. That is how franchises are built.";
                $paragraphs[] = "The deadline has passed, but the impact of these decisions will echo through the rest of the season — and beyond.";
                break;

            case 'offseason_preview':
                $authorName = 'Dana Reeves';
                $authorPersona = 'dana_reeves';
                $headline = "Offseason Preview: What Every Contender and Rebuilder Needs This Offseason";

                $paragraphs[] = "The season is over, and the real work begins. For some teams, this offseason is about fine-tuning a contender. For others, it is about tearing it down and starting fresh. And for the teams in the middle? They face the hardest decisions of all.";

                foreach (array_slice($teams, 0, 5) as $t) {
                    $name = $t['city'] . ' ' . $t['name'];
                    $record = $t['wins'] . '-' . $t['losses'];
                    $pf = (int) $t['points_for']; $pa = (int) $t['points_against'];
                    $diff = $pf - $pa; $ds = $diff >= 0 ? "+{$diff}" : (string) $diff;
                    $offR = $t['offense_rating'] ?? '??'; $defR = $t['defense_rating'] ?? '??';
                    $paragraphs[] = "{$name} ({$record}, {$ds} point differential): Offense rated {$offR}, defense rated {$defR}. " . $this->pickOne([
                        "A contender that needs to address depth. The starters are good enough — the question is what happens when someone goes down.",
                        "The core is in place. The priority this offseason should be adding complementary pieces that elevate the entire roster.",
                        "Close to a championship-caliber team. One or two targeted additions could push them over the top.",
                    ]);
                }

                foreach (array_slice($teams, -3) as $t) {
                    $name = $t['city'] . ' ' . $t['name'];
                    $record = $t['wins'] . '-' . $t['losses'];
                    $pf = (int) $t['points_for']; $pa = (int) $t['points_against'];
                    $diff = $pf - $pa; $ds = $diff >= 0 ? "+{$diff}" : (string) $diff;
                    $paragraphs[] = "{$name} ({$record}, {$ds} point differential): A long offseason ahead. The draft will be critical, and cap space needs to be spent wisely. Patience is the name of the game — resist the urge to overpay free agents just to create headlines.";
                }

                $paragraphs[] = "The offseason is where championships are won. The teams that make the smartest moves now will be the ones celebrating next February. The teams that panic, overspend, or lose discipline? They will be right back where they started.";
                $paragraphs[] = "The clock is ticking. Free agency opens soon. The draft is around the corner. And every front office in the league is about to be tested.";
                break;

            case 'rookie_watch':
                $authorName = 'Marcus Bell';
                $authorPersona = 'marcus_bell';

                // Fetch rookies with season stats
                $rookieStmt = $this->db->prepare(
                    "SELECT p.id, p.first_name, p.last_name, p.position, p.overall_rating, p.team_id, t.abbreviation, t.city, t.name as team_name,
                            SUM(gs.pass_yards) as total_pass_yards, SUM(gs.pass_tds) as total_pass_tds, SUM(gs.interceptions) as total_ints_thrown,
                            SUM(gs.rush_yards) as total_rush_yards, SUM(gs.rush_tds) as total_rush_tds, SUM(gs.rush_attempts) as total_rush_att,
                            SUM(gs.receptions) as total_receptions, SUM(gs.rec_yards) as total_rec_yards, SUM(gs.rec_tds) as total_rec_tds,
                            SUM(gs.tackles) as total_tackles, SUM(gs.sacks) as total_sacks, SUM(gs.interceptions_def) as total_def_ints,
                            COUNT(gs.id) as games_played
                     FROM players p
                     JOIN teams t ON p.team_id = t.id
                     LEFT JOIN game_stats gs ON gs.player_id = p.id
                     WHERE p.team_id IN (SELECT id FROM teams WHERE league_id = ?)
                       AND p.experience = 0
                     GROUP BY p.id
                     HAVING games_played > 0
                     ORDER BY (COALESCE(SUM(gs.pass_yards), 0) + COALESCE(SUM(gs.rush_yards), 0) * 2 + COALESCE(SUM(gs.rec_yards), 0) * 1.5 + COALESCE(SUM(gs.pass_tds), 0) * 20 + COALESCE(SUM(gs.rush_tds), 0) * 20 + COALESCE(SUM(gs.rec_tds), 0) * 20 + COALESCE(SUM(gs.sacks), 0) * 15 + COALESCE(SUM(gs.interceptions_def), 0) * 25) DESC
                     LIMIT 10"
                );
                $rookieStmt->execute([$leagueId]);
                $rookies = $rookieStmt->fetchAll();

                $headline = "Rookie Watch: The Stars, the Surprises, and the Struggles Through Week {$week}";

                $paragraphs[] = "Every draft class writes its own story. Through {$week} weeks, this year's class is penning one that is equal parts thrilling and puzzling. Some first-year players have arrived with a vengeance. Others are still searching for their footing. Here is where things stand.";

                if (!empty($rookies)) {
                    // ROY frontrunner
                    $r1 = $rookies[0];
                    $r1Name = $r1['first_name'] . ' ' . $r1['last_name'];
                    $r1Stat = '';
                    $gp = max(1, (int) $r1['games_played']);
                    if (in_array($r1['position'], ['QB'])) {
                        $r1Stat = (int) $r1['total_pass_yards'] . " passing yards (" . round((int) $r1['total_pass_yards'] / $gp, 1) . " per game), " . (int) $r1['total_pass_tds'] . " touchdowns, " . (int) $r1['total_ints_thrown'] . " interceptions";
                    } elseif (in_array($r1['position'], ['RB'])) {
                        $ypc = (int) $r1['total_rush_att'] > 0 ? round((int) $r1['total_rush_yards'] / (int) $r1['total_rush_att'], 1) : 0;
                        $r1Stat = (int) $r1['total_rush_yards'] . " rushing yards ({$ypc} per carry), " . (int) $r1['total_rush_tds'] . " touchdowns";
                    } elseif (in_array($r1['position'], ['WR', 'TE'])) {
                        $r1Stat = (int) $r1['total_receptions'] . " catches for " . (int) $r1['total_rec_yards'] . " yards (" . round((int) $r1['total_rec_yards'] / $gp, 1) . " per game) and " . (int) $r1['total_rec_tds'] . " touchdowns";
                    } elseif (in_array($r1['position'], ['DE', 'DT', 'LB'])) {
                        $r1Stat = (int) $r1['total_tackles'] . " tackles, " . number_format((float) $r1['total_sacks'], 1) . " sacks";
                    } elseif (in_array($r1['position'], ['CB', 'S'])) {
                        $r1Stat = (int) $r1['total_tackles'] . " tackles, " . (int) $r1['total_def_ints'] . " interceptions";
                    }
                    $paragraphs[] = "THE FRONTRUNNER: {$r1Name}, {$r1['position']}, {$r1['city']} {$r1['team_name']} ({$r1['overall_rating']} OVR). Through {$week} weeks: {$r1Stat}. The early favorite for Rookie of the Year, and it is not particularly close. This kid is the real deal — the kind of player who does not just contribute but transforms the identity of his team.";

                    // Next 2-3 rookies
                    foreach (array_slice($rookies, 1, 3) as $r) {
                        $rName = $r['first_name'] . ' ' . $r['last_name'];
                        $rStat = '';
                        $rgp = max(1, (int) $r['games_played']);
                        if (in_array($r['position'], ['QB'])) { $rStat = (int) $r['total_pass_yards'] . " pass yards, " . (int) $r['total_pass_tds'] . " TDs in {$rgp} games"; }
                        elseif (in_array($r['position'], ['RB'])) { $rStat = (int) $r['total_rush_yards'] . " rush yards, " . (int) $r['total_rush_tds'] . " TDs in {$rgp} games"; }
                        elseif (in_array($r['position'], ['WR', 'TE'])) { $rStat = (int) $r['total_receptions'] . " catches, " . (int) $r['total_rec_yards'] . " rec yards, " . (int) $r['total_rec_tds'] . " TDs in {$rgp} games"; }
                        elseif (in_array($r['position'], ['DE', 'DT', 'LB'])) { $rStat = (int) $r['total_tackles'] . " tackles, " . number_format((float) $r['total_sacks'], 1) . " sacks in {$rgp} games"; }
                        elseif (in_array($r['position'], ['CB', 'S'])) { $rStat = (int) $r['total_tackles'] . " tackles, " . (int) $r['total_def_ints'] . " INTs in {$rgp} games"; }
                        $paragraphs[] = "{$rName}, {$r['position']}, {$r['abbreviation']} ({$r['overall_rating']} OVR): {$rStat}. " . $this->pickOne([
                            "Making an impact from day one. Exactly what you want to see from a young player with this kind of pedigree.",
                            "The production has been steady and the trajectory is pointing up. The best may be yet to come.",
                            "A pleasant surprise for a team that needed young contributors to step up immediately.",
                            "Playing with a maturity that belies his experience level. This franchise found a keeper.",
                        ]);
                    }

                    // Pace projection for the top rookie
                    if (in_array($r1['position'], ['WR', 'TE']) && $week > 0) {
                        $projectedYards = (int) round((int) $r1['total_rec_yards'] / $week * 18);
                        $projectedTDs = (int) round((int) $r1['total_rec_tds'] / $week * 18);
                        $paragraphs[] = "If {$r1Name} maintains his current pace, he would finish the season with approximately {$projectedYards} receiving yards and {$projectedTDs} touchdowns — numbers that would put him in elite company among rookie receivers in league history.";
                    } elseif (in_array($r1['position'], ['QB']) && $week > 0) {
                        $projectedYards = (int) round((int) $r1['total_pass_yards'] / $week * 18);
                        $paragraphs[] = "At his current pace, {$r1Name} would finish the season with approximately {$projectedYards} passing yards — a remarkable number for a first-year quarterback.";
                    } elseif (in_array($r1['position'], ['RB']) && $week > 0) {
                        $projectedYards = (int) round((int) $r1['total_rush_yards'] / $week * 18);
                        $paragraphs[] = "At his current pace, {$r1Name} is on track for approximately {$projectedYards} rushing yards on the season. That kind of production from a rookie running back does not come around often.";
                    }
                } else {
                    $paragraphs[] = "It has been a quiet start for this year's rookie class, but there is still plenty of season left. The second half is where rookies often find their footing, and the late-season breakouts are sometimes the most memorable.";
                }

                $paragraphs[] = "The beauty of the rookie class is that the story is never fully written until the season ends. Players develop. Opportunities emerge. And sometimes the biggest rookie impact comes from a player nobody was watching.";

                $paragraphs[] = "The best is yet to come for this class. Keep watching.";
                break;

            default:
                $authorName = 'Dana Reeves';
                $authorPersona = 'dana_reeves';
                $headline = "Week {$week} League Analysis: Trends, Takeaways, and What to Watch";

                $paragraphs[] = "Through {$week} weeks of play, the league landscape is taking shape. The contenders are separating from the pretenders, and the data is starting to tell a clear story.";

                $bestTeam = $teams[0]; $worstTeam = end($teams);
                $bDiff = (int) $bestTeam['points_for'] - (int) $bestTeam['points_against']; $bDs = $bDiff >= 0 ? "+{$bDiff}" : (string) $bDiff;
                $wDiff = (int) $worstTeam['points_for'] - (int) $worstTeam['points_against']; $wDs = $wDiff >= 0 ? "+{$wDiff}" : (string) $wDiff;

                $paragraphs[] = "At the top, the {$bestTeam['city']} {$bestTeam['name']} ({$bestTeam['wins']}-{$bestTeam['losses']}, {$bDs} point differential) continue to set the pace with an offensive rating of " . ($bestTeam['offense_rating'] ?? '??') . " and a defensive rating of " . ($bestTeam['defense_rating'] ?? '??') . ".";

                $paragraphs[] = "At the bottom, the {$worstTeam['city']} {$worstTeam['name']} ({$worstTeam['wins']}-{$worstTeam['losses']}, {$wDs} point differential) are looking ahead to next year. Their overall rating of " . ($worstTeam['overall_rating'] ?? '??') . " tells the story of a roster in need of significant upgrades.";

                // Middle tier analysis
                $midTeams = array_slice($teams, (int) (count($teams) * 0.3), (int) (count($teams) * 0.4));
                if (!empty($midTeams)) {
                    $midNames = [];
                    foreach (array_slice($midTeams, 0, 4) as $mt) { $midNames[] = $mt['abbreviation'] . ' (' . $mt['wins'] . '-' . $mt['losses'] . ')'; }
                    $paragraphs[] = "The most fascinating tier is the middle: " . implode(', ', $midNames) . ". These teams are not elite, but they are not rebuilding either. They are one hot streak — or one key injury — away from completely changing their trajectory.";
                }

                $paragraphs[] = "The efficiency metrics are crystallizing. The teams that rank in the top quartile in both offensive and defensive efficiency have historically made the playoffs at an 85% rate. The teams in the bottom quartile in both? Less than 5%.";

                $paragraphs[] = "The next few weeks will be critical for every team. Expect movement in the standings, some surprises, and at least one team that makes everyone reconsider their preseason predictions.";
                break;
        }

        $body = implode("\n\n", $paragraphs);
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            "INSERT INTO articles (league_id, season_id, week, type, headline, body, author_name, author_persona, team_id, game_id, is_ai_generated, published_at)
             VALUES (?, ?, ?, 'feature', ?, ?, ?, ?, ?, NULL, 0, ?)"
        );
        $stmt->execute([$leagueId, $seasonId, $week, $headline, $body, $authorName, $authorPersona, $teamId, $now]);
    }
}
