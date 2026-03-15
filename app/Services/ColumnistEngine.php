<?php

namespace App\Services;

use App\Database\Connection;

class ColumnistEngine
{
    private \PDO $db;

    /**
     * Columnist personas with distinct writing styles.
     */
    private array $columnists = [
        'terry_hollis' => [
            'name' => 'Terry Hollis',
            'title' => 'Senior NFL Columnist',
            'style' => 'old_school', // traditional, stats-heavy, opinionated
            'bias' => 'defensive',   // favors defensive teams
        ],
        'dana_reeves' => [
            'name' => 'Dana Reeves',
            'title' => 'Lead Sports Analyst',
            'style' => 'analytical', // data-driven, balanced
            'bias' => 'neutral',
        ],
        'marcus_bell' => [
            'name' => 'Marcus Bell',
            'title' => 'Sports Culture Writer',
            'style' => 'narrative',  // story-driven, emotional, player-focused
            'bias' => 'offensive',   // favors offensive fireworks
        ],
    ];

    /**
     * Morning Blitz analyst pair.
     */
    private array $blitzAnalysts = [
        'mike_diaz' => [
            'name' => 'Mike Diaz',
            'role' => 'Host',
            'personality' => 'hot_take', // loud, provocative, drives debate
        ],
        'sarah_chen' => [
            'name' => 'Sarah Chen',
            'role' => 'Analyst',
            'personality' => 'measured', // pushes back on hot takes with facts
        ],
    ];

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
    }

    // ────────────────────────────────────────────
    // Feature Article
    // ────────────────────────────────────────────

    /**
     * Generate a feature article about a team, player, or trend.
     *
     * @param string $topic One of: team_preview, breakout_player, rivalry, coaching, trend
     * @param array  $context Contextual data (team, player, stats, etc.)
     */
    public function generateFeatureArticle(int $leagueId, int $seasonId, int $week, string $topic, array $context): void
    {
        // Rotate columnists so content feels diverse
        $personaKey = $this->pickColumnistForTopic($topic);
        $persona = $this->columnists[$personaKey];

        $headline = '';
        $body = '';

        switch ($topic) {
            case 'team_preview':
                [$headline, $body] = $this->featureTeamPreview($persona, $context);
                break;
            case 'breakout_player':
                [$headline, $body] = $this->featureBreakoutPlayer($persona, $context);
                break;
            case 'rivalry':
                [$headline, $body] = $this->featureRivalry($persona, $context);
                break;
            case 'coaching':
                [$headline, $body] = $this->featureCoaching($persona, $context);
                break;
            case 'trend':
                [$headline, $body] = $this->featureTrend($persona, $context);
                break;
            default:
                [$headline, $body] = $this->featureGeneric($persona, $context);
                break;
        }

        $this->insertArticle($leagueId, $seasonId, $week, 'feature', $headline, $body, $persona, $context, $personaKey);
    }

    // ────────────────────────────────────────────
    // Opinion Column
    // ────────────────────────────────────────────

    /**
     * Generate a column (opinion piece) from a specific columnist.
     */
    public function generateColumn(string $personaKey, int $leagueId, int $seasonId, int $week, array $context): void
    {
        if (!isset($this->columnists[$personaKey])) {
            return;
        }
        $persona = $this->columnists[$personaKey];

        [$headline, $body] = match ($personaKey) {
            'terry_hollis' => $this->terryColumn($context, $week),
            'dana_reeves'  => $this->danaColumn($context, $week),
            'marcus_bell'  => $this->marcusColumn($context, $week),
            default        => ['Column', ''],
        };

        $this->insertArticle($leagueId, $seasonId, $week, 'column', $headline, $body, $persona, $context, $personaKey);
    }

    // ────────────────────────────────────────────
    // Morning Blitz (dialogue format)
    // ────────────────────────────────────────────

    /**
     * Generate Morning Blitz dialogue for the week.
     * Two analysts discussing the biggest stories in a First Take / GMFB style.
     */
    public function generateMorningBlitz(int $leagueId, int $seasonId, int $week): void
    {
        $stories = $this->gatherWeekStories($leagueId, $week);
        if (empty($stories)) {
            return;
        }

        $segments = [];
        $segmentCount = min(3, count($stories));

        for ($i = 0; $i < $segmentCount; $i++) {
            $story = $stories[$i];
            $segments[] = $this->buildBlitzSegment($story, $i + 1);
        }

        $headline = "Morning Blitz: Week {$week} Breakdown";
        $body = "# MORNING BLITZ -- Week {$week}\n";
        $body .= "*Hosted by Mike Diaz with analyst Sarah Chen*\n\n";
        $body .= "---\n\n";
        $body .= implode("\n\n---\n\n", $segments);

        $persona = [
            'name' => 'Morning Blitz Staff',
            'title' => 'Morning Blitz',
        ];

        $stmt = $this->db->prepare(
            "INSERT INTO articles (league_id, season_id, week, type, headline, body, author_name, author_persona, column_persona, team_id, is_ai_generated, published_at)
             VALUES (?, ?, ?, 'morning_blitz', ?, ?, ?, ?, ?, NULL, 0, ?)"
        );
        $stmt->execute([
            $leagueId, $seasonId, $week,
            $headline, $body,
            $persona['name'], 'morning_blitz', 'morning_blitz',
            date('Y-m-d H:i:s'),
        ]);
    }

    // ────────────────────────────────────────────
    // Weekly GridironX (social posts)
    // ────────────────────────────────────────────

    /**
     * Generate a diverse batch of GridironX social posts for the week.
     * Covers: hot takes, memes, stat drops, reporter scoops, beat writers, fans.
     */
    public function generateWeeklyGridironX(int $leagueId, int $seasonId, int $week): void
    {
        $teams = $this->getTeamsByLeague($leagueId);
        if (empty($teams)) {
            return;
        }

        $posts = [];

        // 1. Hot take artist
        $hotTeam = $this->pickTeamByExtreme($teams, 'wins', 'desc');
        $coldTeam = $this->pickTeamByExtreme($teams, 'losses', 'desc');

        if ($hotTeam) {
            $hotTakes = [
                "I don't care what anyone says, the {$hotTeam['city']} {$hotTeam['name']} are the best team in football right now. It's not even close. Fight me.",
                "If you aren't watching the {$hotTeam['name']} right now, you're missing out on greatness. This team is BUILT DIFFERENT.",
                "Everyone laughed when I said the {$hotTeam['name']} were contenders. Nobody's laughing now. {$hotTeam['wins']}-{$hotTeam['losses']}.",
                "The {$hotTeam['name']} are must-watch TV every single week. That offense is cooking and I'm here for it.",
            ];
            $posts[] = $this->buildSocialPost(
                $leagueId, $seasonId, $week,
                '@GridironHotTakes', 'Hot Take Hank', 'personality',
                $hotTeam['id'], null,
                $hotTakes[array_rand($hotTakes)],
                mt_rand(2000, 25000), mt_rand(300, 5000)
            );
        }

        // 2. Stat drop from analytics account
        $statTeam = $teams[array_rand($teams)];
        $statDrops = [
            "The {$statTeam['city']} {$statTeam['name']} are averaging " . round(($statTeam['points_for'] / max(1, $statTeam['wins'] + $statTeam['losses'])), 1) . " points per game through Week {$week}. That puts them " . ($statTeam['points_for'] > $statTeam['points_against'] ? 'above' : 'below') . " league average.",
            "Point differential for the {$statTeam['name']}: " . ($statTeam['points_for'] - $statTeam['points_against']) . ". " . ($statTeam['points_for'] > $statTeam['points_against'] ? "They're outscoring opponents and the record reflects it." : "Negative differential usually catches up to you."),
            "Through {$week} weeks, the {$statTeam['name']} have scored {$statTeam['points_for']} total points while allowing {$statTeam['points_against']}. Point differential: " . ($statTeam['points_for'] - $statTeam['points_against']) . ".",
        ];
        $posts[] = $this->buildSocialPost(
            $leagueId, $seasonId, $week,
            '@GridironAnalytics', 'Gridiron Analytics', 'analyst',
            $statTeam['id'], null,
            $statDrops[array_rand($statDrops)],
            mt_rand(500, 8000), mt_rand(100, 1500)
        );

        // 3. Beat writer scoop
        $beatTeam = $teams[array_rand($teams)];
        $scoops = [
            "Source tells me the {$beatTeam['city']} {$beatTeam['name']} are feeling very good about the direction of this team. 'The energy in the building is different this year,' per a team source.",
            "Been told {$beatTeam['name']} coaching staff spent the week specifically game-planning for red zone efficiency. Look for that emphasis this Sunday.",
            "Hearing the {$beatTeam['name']} locker room is " . ($beatTeam['morale'] >= 60 ? 'extremely tight right now. Players are bought in.' : 'going through some growing pains. Not unusual for a team in this situation but worth monitoring.'),
            "I'm told the {$beatTeam['name']} front office is " . ($beatTeam['wins'] > $beatTeam['losses'] ? 'pleased with the early returns. No panic moves expected.' : 'evaluating all options. They believe in their coach but patience is not unlimited.'),
        ];
        $posts[] = $this->buildSocialPost(
            $leagueId, $seasonId, $week,
            '@' . str_replace(' ', '', $beatTeam['name']) . 'Beat', $beatTeam['city'] . ' Beat Writer', 'reporter',
            $beatTeam['id'], null,
            $scoops[array_rand($scoops)],
            mt_rand(800, 12000), mt_rand(200, 3000)
        );

        // 4. Meme / comedy account
        if ($coldTeam) {
            $memes = [
                "Me watching the {$coldTeam['name']} every Sunday knowing full well they're going to hurt me again.",
                "{$coldTeam['name']} fans during the first quarter: 'This might be the week!'\n\n{$coldTeam['name']} fans by the third quarter: 'There's always next year.'",
                "The {$coldTeam['name']} defense out here making every QB look like an MVP candidate.",
                "POV: You're a {$coldTeam['name']} fan and someone asks 'How's the season going?'",
                "Every week I tell myself I won't get my hopes up about the {$coldTeam['name']}. Every week I lie to myself.",
            ];
            $posts[] = $this->buildSocialPost(
                $leagueId, $seasonId, $week,
                '@GridironMemes', 'Gridiron Memes', 'fan',
                $coldTeam['id'], null,
                $memes[array_rand($memes)],
                mt_rand(5000, 40000), mt_rand(1000, 8000)
            );
        }

        // 5. Fantasy football account
        $hotName = $hotTeam ? $hotTeam['name'] : 'the top team';
        $coldName = $coldTeam ? $coldTeam['name'] : 'the bottom team';
        $fantasyPosts = [
            "Week {$week} waiver wire priority list coming soon. If you're not rostering {$hotName} pass-catchers, you're leaving points on the table.",
            "Start/Sit for Week " . ($week + 1) . " is live. Hot take: every skill player on the {$hotName} is a must-start right now.",
            "Your league-mate who drafted all {$coldName} players is currently 0-" . min($week, 6) . " in your fantasy league. We've all been there.",
        ];
        $posts[] = $this->buildSocialPost(
            $leagueId, $seasonId, $week,
            '@FantasyGridiron', 'Fantasy Gridiron', 'personality',
            null, null,
            $fantasyPosts[array_rand($fantasyPosts)],
            mt_rand(1000, 15000), mt_rand(200, 3000)
        );

        // 6. Random fan reaction (passionate)
        $fanTeam = $teams[array_rand($teams)];
        $record = "{$fanTeam['wins']}-{$fanTeam['losses']}";
        if ($fanTeam['wins'] > $fanTeam['losses']) {
            $fanPosts = [
                "I've been a {$fanTeam['name']} fan since I was 5 years old and I'm telling you, this is THE YEAR. {$record} and rolling!",
                "Nobody believed us in the preseason. {$fanTeam['name']} at {$record}. Keep doubting, we'll keep winning.",
                "The {$fanTeam['name']} are {$record} and I've never been more confident in this roster. Big Game or bust!",
            ];
        } else {
            $fanPosts = [
                "Being a {$fanTeam['name']} fan is a full-contact sport. {$record} is rough but I'm not jumping ship.",
                "My therapist asked me what's causing my stress. I just showed her the {$fanTeam['name']} schedule. {$record}.",
                "I will not abandon the {$fanTeam['name']} at {$record}. This is a test of character and I WILL pass it. Probably.",
            ];
        }
        $posts[] = $this->buildSocialPost(
            $leagueId, $seasonId, $week,
            '@DiehardFan' . mt_rand(1, 999), 'Gridiron Fan', 'fan',
            $fanTeam['id'], null,
            $fanPosts[array_rand($fanPosts)],
            mt_rand(200, 8000), mt_rand(50, 1500)
        );

        // 7. League official / PR account
        $officialPosts = [
            "Week {$week} is in the books! What a slate of games. Catch all the highlights at GridironX.com.",
            "Through {$week} weeks, the league has seen " . array_sum(array_column($teams, 'points_for')) . " total points scored. Offense is alive and well.",
        ];
        $posts[] = $this->buildSocialPost(
            $leagueId, $seasonId, $week,
            '@OfficialGridiron', 'Gridiron League', 'official',
            null, null,
            $officialPosts[array_rand($officialPosts)],
            mt_rand(3000, 20000), mt_rand(500, 4000)
        );

        // Persist all posts
        foreach ($posts as $post) {
            $cols = implode(', ', array_keys($post));
            $placeholders = implode(', ', array_fill(0, count($post), '?'));
            $stmt = $this->db->prepare("INSERT INTO social_posts ({$cols}) VALUES ({$placeholders})");
            $stmt->execute(array_values($post));
        }
    }

    // ────────────────────────────────────────────
    // Private: Terry Hollis column templates
    // ────────────────────────────────────────────

    /**
     * Terry Hollis: blunt, old-school, loves defense, distrusts gimmick offenses.
     */
    private function terryColumn(array $c, int $week): array
    {
        $teams = $c['teams'] ?? [];
        $bestDefense = $this->pickTeamByExtreme($teams, 'points_against', 'asc');
        $worstDefense = $this->pickTeamByExtreme($teams, 'points_against', 'desc');

        $headline = '';
        $paragraphs = [];

        if ($bestDefense && $worstDefense) {
            $headlines = [
                "The Hollis Report: Defense Still Wins Championships, and Don't Let Anyone Tell You Different",
                "Hollis: I've Seen Enough -- It's Time We Talk About Who Can Actually Play Defense",
                "Hollis: Stop Obsessing Over Passing Yards. Here's What Actually Matters in Week {$week}",
            ];
            $headline = $headlines[array_rand($headlines)];

            $bestName = $bestDefense['city'] . ' ' . $bestDefense['name'];
            $worstName = $worstDefense['city'] . ' ' . $worstDefense['name'];
            $bestPA = $bestDefense['points_against'];
            $worstPA = $worstDefense['points_against'];

            $paragraphs[] = "I've been covering football for a long time. Long enough to know that the truth hasn't changed: you win in the trenches, you win with defense, and you win by not turning the football over. Everything else is noise.";

            $paragraphs[] = "Through {$week} weeks, the {$bestName} have allowed just {$bestPA} points. That's football. That's how you build something sustainable. I don't care how many touchdowns you score if you can't stop anyone on the other side.";

            $paragraphs[] = "On the other end of the spectrum, the {$worstName} have given up {$worstPA} points. You simply cannot win football games playing defense like that. I don't care who your quarterback is. I don't care about your offensive coordinator's fancy schemes. If you can't get off the field on third down, you're going home in January.";

            $paragraphs[] = "Every year I watch the league get more enamored with offense. Spread formations, RPOs, jet sweeps. And every year, come playoff time, it's the team that can rush the passer and stop the run that hoists the trophy. Some things never change.";

            $paragraphs[] = "Mark my words: when the dust settles, the team with the best defense will be the last one standing. That's not an opinion. That's football.";
        } else {
            $headline = "The Hollis Report: Week {$week} Observations From the Film Room";
            $paragraphs[] = "Another week in the books, and another week where the fundamentals separated the contenders from the pretenders. Let me tell you what the tape shows.";
            $paragraphs[] = "You want to know what I'm watching for this time of year? Physicality. The teams that are winning the line of scrimmage on both sides of the ball are the teams that are going to be playing meaningful football in December and January.";
            $paragraphs[] = "Turnovers, tackling, and toughness. That's the formula. Always has been, always will be. I don't need a computer to tell me that.";
        }

        return [$headline, implode("\n\n", $paragraphs)];
    }

    // ────────────────────────────────────────────
    // Private: Dana Reeves column templates
    // ────────────────────────────────────────────

    /**
     * Dana Reeves: measured, analytical, data-driven, balanced perspective.
     */
    private function danaColumn(array $c, int $week): array
    {
        $teams = $c['teams'] ?? [];
        $totalPF = array_sum(array_column($teams, 'points_for'));
        $totalPA = array_sum(array_column($teams, 'points_against'));
        $gamesPlayed = array_sum(array_column($teams, 'wins')) + array_sum(array_column($teams, 'losses'));
        $avgPPG = $gamesPlayed > 0 ? round($totalPF / $gamesPlayed, 1) : 0;

        $bestRecord = $this->pickTeamByExtreme($teams, 'wins', 'desc');
        $worstRecord = $this->pickTeamByExtreme($teams, 'wins', 'asc');

        $headlines = [
            "Reeves' Analysis: What the Numbers Are Telling Us Through Week {$week}",
            "By the Numbers: Week {$week} Statistical Breakdown and What It Means Going Forward",
            "The Data Doesn't Lie: Separating Signal From Noise at the Quarter Mark",
        ];
        $headline = $headlines[array_rand($headlines)];

        $paragraphs = [];

        $paragraphs[] = "Through {$week} weeks, the league is averaging {$avgPPG} points per game across all matchups. That number tells a story about where the competitive balance stands this season, and there are some interesting trends worth examining.";

        if ($bestRecord) {
            $bName = $bestRecord['city'] . ' ' . $bestRecord['name'];
            $bDiff = $bestRecord['points_for'] - $bestRecord['points_against'];
            $paragraphs[] = "The {$bName} lead the league at {$bestRecord['wins']}-{$bestRecord['losses']} with a point differential of " . ($bDiff >= 0 ? "+{$bDiff}" : "{$bDiff}") . ". Point differential remains one of the strongest predictors of future performance, and their number suggests " . ($bDiff >= 20 ? "a team that is genuinely elite on both sides of the ball." : ($bDiff >= 0 ? "a solid team, though the margin is thin enough that a few close games could have gone either way." : "a team that has been winning close games, which historically tends to regress."));
        }

        if ($worstRecord) {
            $wName = $worstRecord['city'] . ' ' . $worstRecord['name'];
            $wDiff = $worstRecord['points_for'] - $worstRecord['points_against'];
            $paragraphs[] = "At the other end, the {$wName} sit at {$worstRecord['wins']}-{$worstRecord['losses']}. Their point differential of " . ($wDiff >= 0 ? "+{$wDiff}" : "{$wDiff}") . ($wDiff >= -10 ? " suggests they are closer to competitive than the record indicates. A few bounces the other way and we're telling a very different story about this team." : " points to systemic issues that go beyond bad luck. This is a team that needs to find answers quickly.");
        }

        $paragraphs[] = "The biggest takeaway from the data through Week {$week}: parity is " . ($avgPPG > 22 ? "alive and well, with most games decided by less than a touchdown" : "an illusion, as the top tier has clearly separated from the bottom half") . ". As we move deeper into the season, expect the cream to rise -- the numbers always sort themselves out eventually.";

        $paragraphs[] = "One metric I'll be watching closely going forward is scoring defense. The teams that have limited opponents to under 20 points per game are overwhelmingly the teams with winning records. It's not glamorous, but the correlation between defensive efficiency and wins is undeniable in the data.";

        return [$headline, implode("\n\n", $paragraphs)];
    }

    // ────────────────────────────────────────────
    // Private: Marcus Bell column templates
    // ────────────────────────────────────────────

    /**
     * Marcus Bell: narrative-driven, emotional, player-focused, loves high-scoring games.
     */
    private function marcusColumn(array $c, int $week): array
    {
        $teams = $c['teams'] ?? [];
        $hotTeam = $this->pickTeamByExtreme($teams, 'points_for', 'desc');
        $streakTeam = $this->findTeamWithStreak($teams);

        $headline = '';
        $paragraphs = [];

        if ($hotTeam) {
            $hName = $hotTeam['city'] . ' ' . $hotTeam['name'];
            $headlines = [
                "Bell: The {$hName} Are Writing a Story We Won't Forget",
                "Bell: There's Something Special Brewing With the {$hotTeam['name']}",
                "The Beautiful Game: Why the {$hotTeam['name']} Remind Me Why I Fell in Love With Football",
            ];
            $headline = $headlines[array_rand($headlines)];

            $paragraphs[] = "I sat in the press box last Sunday and watched something beautiful. The kind of football that makes you remember why you fell in love with this sport in the first place. The {$hName} are playing with a joy and a freedom that is impossible to fake.";

            $paragraphs[] = "You can see it in the way they celebrate. Not just the touchdowns, but the small things -- a perfectly executed block, a third-down conversion, a teammate picking another up off the turf. This is a team that genuinely likes each other, and in my experience, that's when special things happen.";

            if ($streakTeam) {
                $sName = $streakTeam['city'] . ' ' . $streakTeam['name'];
                $paragraphs[] = "And then there's the {$sName}, who are on a {$streakTeam['streak']} streak. Every great season has a defining stretch, a run of games where a team transforms from good to great. I think we might be watching that transformation happen right now.";
            }

            $paragraphs[] = "Week {$week} gave us everything we could ask for. Drama, heartbreak, joy, redemption. That's not just football -- that's life, compressed into sixty minutes on a Sunday afternoon. And we get to do it all again next week.";

            $paragraphs[] = "In a world full of analytics and efficiency metrics, sometimes you just have to watch the game and feel it. The numbers don't capture the roar of a crowd after a game-winning drive. They don't measure the look in a quarterback's eyes when he knows he's got the defense beat. Those moments are why we watch. Those moments are why football matters.";
        } else {
            $headline = "Bell: Week {$week} Was a Reminder That Football Is the Greatest Sport on Earth";
            $paragraphs[] = "Another week, another reminder. Football is storytelling at its finest. Every game is a narrative, every player is a character, and every Sunday is a new chapter.";
            $paragraphs[] = "We watch because we care. We care about the teams we grew up loving, the players who inspire us, and the moments that take our breath away. Week {$week} delivered all of that and more.";
            $paragraphs[] = "No algorithm can predict what will happen on any given Sunday. And honestly? That's the best part.";
        }

        return [$headline, implode("\n\n", $paragraphs)];
    }

    // ────────────────────────────────────────────
    // Private: Feature article builders
    // ────────────────────────────────────────────

    private function featureTeamPreview(array $persona, array $c): array
    {
        $team = $c['team'] ?? null;
        if (!$team) {
            return ['Feature: Team to Watch', 'Check back soon for the full feature.'];
        }
        $name = $team['city'] . ' ' . $team['name'];
        $record = $team['wins'] . '-' . $team['losses'];

        $headline = "Feature: The {$name} at {$record} -- Where Do They Go From Here?";

        $body = [];
        $body[] = "The {$name} sit at {$record} through the early stretch of the season, and questions are forming about what kind of team they truly are.";

        if ($team['wins'] > $team['losses']) {
            $body[] = "The record is encouraging, but records can be deceiving. A deeper look at the {$team['name']} reveals a team that " . ($team['points_for'] > $team['points_against'] ? "is winning convincingly and passing the eye test." : "has been finding ways to win close games, which raises questions about sustainability.");
            $body[] = "If they can maintain this pace, playoff football is a real possibility. The roster has the talent, and the coaching staff has shown an ability to adjust week to week.";
        } else {
            $body[] = "It has not been the start anyone in the {$team['name']} organization was hoping for. The losses have exposed some weaknesses that will need to be addressed, either through scheme adjustments or personnel changes.";
            $body[] = "But it's important to remember: the season is long. Teams that start slow can turn it around, and the {$team['name']} have enough talent on the roster to make a push if things start clicking.";
        }

        $body[] = "The next few weeks will tell us a lot about this football team. Stay tuned.";

        return [$headline, implode("\n\n", $body)];
    }

    private function featureBreakoutPlayer(array $persona, array $c): array
    {
        $player = $c['player'] ?? null;
        $team = $c['team'] ?? null;
        if (!$player || !$team) {
            return ['Breakout Star Emerging', 'A player is making waves across the league.'];
        }

        $pName = $player['first_name'] . ' ' . $player['last_name'];
        $tName = $team['city'] . ' ' . $team['name'];

        $headline = "{$pName} Is Making the {$tName} Impossible to Ignore";

        $body = [];
        $body[] = "When the {$tName} drafted {$pName}, the expectations were measured. A solid contributor, maybe a starter by year two. Nobody predicted this.";
        $body[] = "{$pName} has been one of the most exciting players in football through the early weeks of the season, consistently outperforming his overall rating and giving the {$team['name']} a weapon that opposing coordinators are struggling to contain.";
        $body[] = "\"He's doing things in games that you normally only see in practice,\" one league scout said. \"His confidence is sky-high and when a player is feeling it like that, he's dangerous.\"";
        $body[] = "The advanced metrics back up the eye test. {$pName} has graded out as one of the top players at his position through the first stretch of games, a remarkable achievement for a player many considered a developmental project.";
        $body[] = "For the {$tName}, the emergence of {$pName} changes the calculus for the rest of the season. A genuine playmaker at the {$player['position']} position opens up the entire offense and makes everyone around him better.";

        return [$headline, implode("\n\n", $body)];
    }

    private function featureRivalry(array $persona, array $c): array
    {
        $team1 = $c['team1'] ?? null;
        $team2 = $c['team2'] ?? null;
        if (!$team1 || !$team2) {
            return ['Division Rivalry Heating Up', 'Two teams. One division. The stakes have never been higher.'];
        }

        $name1 = $team1['city'] . ' ' . $team1['name'];
        $name2 = $team2['city'] . ' ' . $team2['name'];

        $headline = "{$name1} vs. {$name2}: A Rivalry Renewed";

        $body = [];
        $body[] = "Some rivalries are manufactured. Talking heads on TV trying to drum up interest in a meaningless matchup. This is not one of those rivalries.";
        $body[] = "The {$team1['name']} and the {$team2['name']} genuinely do not like each other. You can see it in the way they play when they line up across from one another. The hits are harder. The celebrations are louder. The stakes feel higher, even when the standings say otherwise.";
        $body[] = "This season, the rivalry has taken on new dimensions. The {$name1} enter at {$team1['wins']}-{$team1['losses']}, while the {$name2} sit at {$team2['wins']}-{$team2['losses']}. Every divisional matchup carries playoff implications, and these two teams know it.";
        $body[] = "When these two teams meet, throw out the records. Anything can happen, and it usually does.";

        return [$headline, implode("\n\n", $body)];
    }

    private function featureCoaching(array $persona, array $c): array
    {
        $coach = $c['coach'] ?? null;
        $team = $c['team'] ?? null;
        if (!$coach || !$team) {
            return ['Coaching Under the Microscope', 'The spotlight is on the sideline.'];
        }

        $tName = $team['city'] . ' ' . $team['name'];

        $headline = "Under Pressure: {$coach['name']} and the Future of the {$tName}";

        $body = [];
        $body[] = "In the NFL, patience is a luxury few coaches can afford. For {$coach['name']}, the clock is ticking.";
        $body[] = "The {$tName} are {$team['wins']}-{$team['losses']}, and the owner's office is watching closely. Sources indicate that {$coach['name']}'s job security sits at {$coach['job_security']} out of 100, a number that will fluctuate dramatically based on the next few weeks of results.";
        $body[] = "\"Every coach in the league understands the deal,\" one veteran assistant said. \"You win, you stay. You lose, you go. It's not personal, it's the business.\"";
        $body[] = "{$coach['name']}'s influence rating -- a measure of how much sway the coach has over roster decisions, game planning, and organizational direction -- currently stands at {$coach['influence']}. That number matters more than most fans realize. A coach with high influence can weather a storm. A coach without it is one bad loss away from a pink slip.";
        $body[] = "The next chapter of this story is unwritten. But make no mistake: the pressure is real, and the entire football world is watching.";

        return [$headline, implode("\n\n", $body)];
    }

    private function featureTrend(array $persona, array $c): array
    {
        $trend = $c['trend'] ?? 'scoring';
        $teams = $c['teams'] ?? [];

        $headline = "League Trend Report: What the Data Reveals About the State of Football";

        $body = [];
        $body[] = "Every season tells a story through its statistics. This season is no different, and the numbers are painting an interesting picture as we move through the schedule.";

        $totalPF = array_sum(array_column($teams, 'points_for'));
        $totalGames = array_sum(array_column($teams, 'wins')) + array_sum(array_column($teams, 'losses'));
        $avgPPG = $totalGames > 0 ? round($totalPF / $totalGames, 1) : 0;

        $body[] = "The league-wide scoring average of {$avgPPG} points per game tells one story, but the distribution is where the real insights live. The gap between the top-scoring offenses and the bottom tier has been one of the defining features of the season so far.";

        $winningTeams = array_filter($teams, fn($t) => $t['wins'] > $t['losses']);
        $losingTeams = array_filter($teams, fn($t) => $t['losses'] > $t['wins']);
        $body[] = "Currently, " . count($winningTeams) . " teams hold winning records while " . count($losingTeams) . " sit below .500. " . (count($winningTeams) > count($losingTeams) ? "Parity is thriving." : "The league is increasingly divided into haves and have-nots.");

        $body[] = "As we move deeper into the season, watch for these trends to crystallize. The teams that can sustain their early-season success while the teams that can't will face some difficult decisions about the direction of their organizations.";

        return [$headline, implode("\n\n", $body)];
    }

    private function featureGeneric(array $persona, array $c): array
    {
        $headline = "{$persona['name']}: What I'm Watching This Week";
        $body = "Every week in football brings new questions and new stories. Here's what has my attention as we move forward in the season.\n\n";
        $body .= "The competitive balance across the league continues to provide compelling matchups, and I'm eager to see how the standings shake out over the next few weeks.";

        return [$headline, $body];
    }

    // ────────────────────────────────────────────
    // Private: Morning Blitz segment builder
    // ────────────────────────────────────────────

    private function buildBlitzSegment(array $story, int $segNum): string
    {
        $mike = $this->blitzAnalysts['mike_diaz']['name'];
        $sarah = $this->blitzAnalysts['sarah_chen']['name'];

        $type = $story['type'] ?? 'game_result';
        $team = $story['team'] ?? [];
        $tName = isset($team['city']) ? $team['city'] . ' ' . $team['name'] : 'this team';
        $record = isset($team['wins']) ? "{$team['wins']}-{$team['losses']}" : '0-0';

        $segment = "## Segment {$segNum}";

        switch ($type) {
            case 'big_win':
                $segment .= ": {$tName} Domination\n\n";
                $segment .= "**{$mike}:** All right, let's talk about the {$tName}. {$record} and looking DANGEROUS. Sarah, is this team for real or are we overreacting?\n\n";
                $segment .= "**{$sarah}:** I think there are legitimate reasons to be excited, Mike. The point differential is strong, and they're winning in different ways each week. That's a hallmark of a well-coached team.\n\n";
                $segment .= "**{$mike}:** Well-coached? WELL-COACHED? They're not just well-coached, they're the best team in football right now! I don't want to hear any more 'let's wait and see.' The {$team['name']} are LEGIT!\n\n";
                $segment .= "**{$sarah}:** Mike, we're still early in the season. The schedule gets harder, and we haven't seen how they handle adversity. But yes, the early returns are very encouraging.\n\n";
                $segment .= "**{$mike}:** Encouraging? Try ELITE. Book it!";
                break;

            case 'struggling':
                $segment .= ": What's Wrong With the {$tName}?\n\n";
                $segment .= "**{$mike}:** Can we please talk about the {$tName}? {$record}! This is UNACCEPTABLE. Something has to change, and it has to change NOW.\n\n";
                $segment .= "**{$sarah}:** Look, the record is concerning, but when you look at the underlying metrics, some of the losses have been competitive. The issue isn't talent, it's execution in key moments.\n\n";
                $segment .= "**{$mike}:** Execution? I'll tell you what the problem is -- this team doesn't have the fight. They don't have the dog in them. When the game is on the line, they fold. Every. Single. Time.\n\n";
                $segment .= "**{$sarah}:** That's a dramatic oversimplification, Mike. Coaching adjustments, health, and schedule difficulty all factor in. I think this team is closer than the record suggests.\n\n";
                $segment .= "**{$mike}:** Closer to what? Because right now they're closer to a top-five pick than a playoff spot!";
                break;

            case 'streak':
                $streak = $story['streak'] ?? 'W3';
                $isWin = str_starts_with($streak, 'W');
                $count = (int) substr($streak, 1);
                $segment .= ": {$tName} on a Roll\n\n";
                if ($isWin) {
                    $segment .= "**{$mike}:** The {$tName} have won {$count} straight. I don't know about you, Sarah, but I'm buying stock in this team RIGHT NOW.\n\n";
                    $segment .= "**{$sarah}:** Winning streaks are encouraging, but the quality of wins matters. Let's look at who they've beaten --\n\n";
                    $segment .= "**{$mike}:** I don't CARE who they've beaten! Winning is winning! When you're rolling like this, the confidence carries over. This team believes they can beat anyone.\n\n";
                    $segment .= "**{$sarah}:** Fair point about momentum. The analytics do show that teams on winning streaks of three or more games tend to sustain that level of play for several more weeks. So the data supports your enthusiasm, at least partially.";
                } else {
                    $segment .= "**{$mike}:** {$count} straight losses for the {$tName}. At what point do we start talking about coaching changes?\n\n";
                    $segment .= "**{$sarah}:** It's early for that conversation, Mike. But I will say, losing streaks are corrosive. They erode confidence, they create tension in the locker room, and they make every close game feel like an inevitable loss.\n\n";
                    $segment .= "**{$mike}:** EXACTLY. This team has lost its identity and someone needs to be held accountable!";
                }
                break;

            default:
                $segment .= ": Around the League\n\n";
                $segment .= "**{$mike}:** Let's do a lightning round. {$tName} at {$record} -- contender or pretender?\n\n";
                $segment .= "**{$sarah}:** Based on what we've seen? " . ($team['wins'] > $team['losses'] ? "Leaning contender, but I need to see more." : "Too many question marks right now to call them a contender.") . "\n\n";
                $segment .= "**{$mike}:** " . ($team['wins'] > $team['losses'] ? "CONTENDER! No doubt!" : "Pretender city, population: the {$team['name']}!") . " Moving on!";
                break;
        }

        return $segment;
    }

    // ────────────────────────────────────────────
    // Private helpers
    // ────────────────────────────────────────────

    /**
     * Gather the week's biggest stories for Morning Blitz.
     */
    private function gatherWeekStories(int $leagueId, int $week): array
    {
        $teams = $this->getTeamsByLeague($leagueId);
        if (empty($teams)) {
            return [];
        }

        $stories = [];

        // Look for big wins
        $bestRecord = $this->pickTeamByExtreme($teams, 'wins', 'desc');
        if ($bestRecord && $bestRecord['wins'] > 0) {
            $stories[] = ['type' => 'big_win', 'team' => $bestRecord, 'priority' => $bestRecord['wins']];
        }

        // Look for struggling teams
        $worstRecord = $this->pickTeamByExtreme($teams, 'losses', 'desc');
        if ($worstRecord && $worstRecord['losses'] > 1) {
            $stories[] = ['type' => 'struggling', 'team' => $worstRecord, 'priority' => $worstRecord['losses']];
        }

        // Look for streaks
        foreach ($teams as $team) {
            $streak = $team['streak'] ?? '';
            if (preg_match('/^[WL](\d+)$/', $streak, $m) && (int) $m[1] >= 3) {
                $stories[] = ['type' => 'streak', 'team' => $team, 'streak' => $streak, 'priority' => (int) $m[1]];
            }
        }

        // Fallback: just pick random teams for discussion
        if (count($stories) < 2) {
            $randomTeam = $teams[array_rand($teams)];
            $stories[] = ['type' => 'general', 'team' => $randomTeam, 'priority' => 0];
        }

        // Sort by priority descending
        usort($stories, fn($a, $b) => $b['priority'] <=> $a['priority']);

        return $stories;
    }

    /**
     * Choose the best columnist for a given topic type.
     */
    private function pickColumnistForTopic(string $topic): string
    {
        return match ($topic) {
            'coaching', 'trend' => 'terry_hollis',
            'team_preview'     => 'dana_reeves',
            'breakout_player', 'rivalry' => 'marcus_bell',
            default => array_rand($this->columnists),
        };
    }

    /**
     * Insert an article into the database.
     */
    private function insertArticle(int $leagueId, int $seasonId, int $week, string $type, string $headline, string $body, array $persona, array $context, string $personaKey): void
    {
        $teamId = $context['team']['id'] ?? $context['team_id'] ?? null;
        $playerId = $context['player']['id'] ?? $context['player_id'] ?? null;
        $arcId = $context['narrative_arc_id'] ?? null;

        $stmt = $this->db->prepare(
            "INSERT INTO articles (league_id, season_id, week, type, headline, body, author_name, author_persona, column_persona, narrative_arc_id, team_id, player_id, is_ai_generated, published_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)"
        );
        $stmt->execute([
            $leagueId, $seasonId, $week,
            $type, $headline, $body,
            $persona['name'], $personaKey, $personaKey,
            $arcId, $teamId, $playerId,
            date('Y-m-d H:i:s'),
        ]);
    }

    private function buildSocialPost(int $leagueId, int $seasonId, int $week, string $handle, string $displayName, string $avatarType, ?int $teamId, ?int $playerId, string $body, int $likes, int $reposts): array
    {
        return [
            'league_id' => $leagueId,
            'season_id' => $seasonId,
            'week' => $week,
            'handle' => $handle,
            'display_name' => $displayName,
            'avatar_type' => $avatarType,
            'team_id' => $teamId,
            'player_id' => $playerId,
            'body' => $body,
            'likes' => $likes,
            'reposts' => $reposts,
            'is_ai_generated' => 0,
            'posted_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function getTeamsByLeague(int $leagueId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM teams WHERE league_id = ?");
        $stmt->execute([$leagueId]);
        return $stmt->fetchAll();
    }

    private function pickTeamByExtreme(array $teams, string $column, string $direction): ?array
    {
        if (empty($teams)) return null;

        $best = null;
        foreach ($teams as $team) {
            if ($best === null) {
                $best = $team;
                continue;
            }
            if ($direction === 'desc' && $team[$column] > $best[$column]) {
                $best = $team;
            }
            if ($direction === 'asc' && $team[$column] < $best[$column]) {
                $best = $team;
            }
        }
        return $best;
    }

    private function findTeamWithStreak(array $teams): ?array
    {
        foreach ($teams as $team) {
            $streak = $team['streak'] ?? '';
            if (preg_match('/^W(\d+)$/', $streak, $m) && (int) $m[1] >= 3) {
                return $team;
            }
        }
        return null;
    }
}
