<?php

namespace App\Services;

use App\Database\Connection;

/**
 * DraftScoutEngine — Dedicated draft prospect coverage with two scout writers
 * who follow prospects with progressive storylines throughout the offseason.
 *
 * Writers:
 *   Jake Morrison (Senior Draft Analyst) — film study, measurables, pro comparisons
 *   Nina Charles  (Draft Insider)        — player stories, character, intangibles, drama
 */
class DraftScoutEngine
{
    private \PDO $db;

    // Two dedicated draft writers
    private const SCOUTS = [
        'jake_morrison' => [
            'name' => 'Jake Morrison',
            'title' => 'Senior Draft Analyst',
            'style' => 'analytical',
            // Focuses on film study, measurables, pro comparisons
        ],
        'nina_charles' => [
            'name' => 'Nina Charles',
            'title' => 'Draft Insider',
            'style' => 'narrative',
            // Focuses on player stories, character, intangibles, drama
        ],
    ];

    // Weekly topic rotation for draft updates
    private const WEEKLY_TOPICS = [
        'mock_draft',
        'prospect_spotlight',
        'combine_report',
        'stock_watch',
        'character_concerns',
    ];

    // One-line scouting reports by position
    private const POSITION_SCOUTING_LINES = [
        'QB'  => [
            'elite' => [
                'Franchise-altering arm talent with elite processing speed.',
                'The most complete passer to enter the draft in years.',
                'Pro-ready pocket presence with a cannon for an arm.',
                'Sees the field like a ten-year veteran and delivers the ball with anticipation that borders on clairvoyant.',
                'Everything you want in a franchise quarterback — the arm, the brain, the poise under fire.',
            ],
            'high'  => [
                'Impressive arm talent with room to grow as a decision-maker.',
                'Dynamic dual-threat with a live arm and dangerous legs.',
                'High-ceiling passer who can make every throw on the field.',
                'Flashes brilliance on off-schedule plays. The arm talent is obvious, the consistency will come.',
                'Can drive the ball to every level with velocity. Needs to learn when to take the checkdown.',
            ],
            'avg'   => [
                'Solid accuracy and decent mobility, but needs refinement under pressure.',
                'Competent game manager with above-average athleticism.',
                'Functional arm strength with good intangibles and work ethic.',
                'Won\'t wow you with arm talent, but his pre-snap processing and command of the offense are advanced.',
                'A rhythm passer who thrives in a structured system. Will need a creative offensive coordinator.',
            ],
        ],
        'RB'  => [
            'elite' => [
                'Explosive three-down back with elite vision and contact balance.',
                'A generational running talent — breaks tackles like a machine.',
                'Complete back: runs between the tackles, catches out of the backfield, and pass-protects.',
                'His jump-cut ability and one-cut burst through the hole are the best I\'ve seen in this evaluation cycle.',
                'Makes the first defender miss every time. The kind of runner who turns 3-yard gains into 12.',
            ],
            'high'  => [
                'Violent runner with impressive burst through the hole.',
                'Shifty playmaker who can change the game on any carry.',
                'Powerful runner with good hands and solid pass protection.',
                'Patient behind the line of scrimmage with the explosive second gear to hit the gap and go.',
                'Runs angry. Defenders feel him in their chest. And he catches the ball cleanly out of the backfield.',
            ],
            'avg'   => [
                'Reliable runner with a patient style behind the line.',
                'Decent burst with room to improve in pass-catching.',
                'Physical runner who needs development as a receiver.',
                'Zone-scheme fit who reads his blocks well but lacks the home-run speed to break long runs.',
                'Competent early-down runner who will need to prove he can stay on the field on third down.',
            ],
        ],
        'WR'  => [
            'elite' => [
                'Elite route runner with hands like glue and game-breaking speed.',
                'A true alpha receiver who dominates at every level of the field.',
                'Generational catch radius with the speed to take the top off defenses.',
                'His route tree is complete. Short, intermediate, deep — he wins at every level with technique and suddenness.',
                'Tracks the deep ball like a centerfielder and has the body control to make circus catches look routine.',
            ],
            'high'  => [
                'Smooth route runner who creates separation at all three levels.',
                'Explosive deep threat with reliable hands and YAC ability.',
                'Physical wideout with strong contested-catch skills.',
                'His release off the line is advanced for a college receiver. Presses can\'t get their hands on him.',
                'Dangerous after the catch — turns short passes into explosive plays with his vision and acceleration.',
            ],
            'avg'   => [
                'Solid possession receiver with good hands underneath.',
                'Reliable route runner who needs to add speed.',
                'Good size and catch radius, needs to refine routes.',
                'Dependable chain-mover who will need to find a niche — likely a slot role at the next level.',
                'Can win on back-shoulder throws and in traffic, but won\'t consistently create separation against press.',
            ],
        ],
        'TE'  => [
            'elite' => [
                'A mismatch nightmare — blocks like a tackle, catches like a receiver.',
                'The best tight end prospect in years, a true three-down player.',
                'Lines up in-line, in the slot, and out wide. Defenses cannot match his versatility.',
                'Moves like a big receiver but finishes blocks like a sixth offensive lineman. Truly unique.',
            ],
            'high'  => [
                'Athletic move tight end with reliable hands and red-zone presence.',
                'Versatile weapon who can line up anywhere.',
                'Seam-stretcher with plus athleticism. Will be a coverage headache for linebackers from day one.',
                'His ability to find soft spots in zone coverage is instinctive. A quarterback\'s best friend.',
            ],
            'avg'   => [
                'Solid blocker who flashes receiving potential.',
                'Dependable inline tight end with serviceable hands.',
                'A Y tight end who does the dirty work. Reliable in short-yardage and goal-line packages.',
                'Won\'t be a featured target in the passing game but will earn his spot with effort in the run game.',
            ],
        ],
        'OT'  => [
            'elite' => [
                'Premier blindside protector with NFL-ready technique and length.',
                'The best pass-blocking prospect in this class, period.',
                'His kick-slide is fluid, his anchor is immovable, and his hands are active and precise.',
                'Day-one franchise left tackle. The kind of prospect you build an offensive line around.',
            ],
            'high'  => [
                'Powerful anchor with impressive footwork in pass sets.',
                'Long, athletic tackle with a high ceiling.',
                'Plays with proper pad level and recovers quickly when beaten initially. Rare balance for his size.',
                'Projects as a quality starter at either tackle spot. His pass sets are technically sound.',
            ],
            'avg'   => [
                'Solid run blocker who needs work in pass protection.',
                'Physical tackle with good size and adequate technique.',
                'Will need time to adjust to the speed of professional pass rushers, but the tools are there.',
                'Capable run blocker who gets displacement at the point of attack. Pass pro is a developmental area.',
            ],
        ],
        'OG'  => [
            'elite' => [
                'A road-grader in the run game with surprising pass-pro ability.',
                'Dominant interior lineman who creates running lanes through sheer violence at the point of attack.',
                'The best guard prospect in this class. Pulls, combos, and pass-protects at a premium level.',
            ],
            'high'  => [
                'Nasty disposition with plus strength at the point of attack.',
                'Moves defenders off the ball consistently. Strong anchor in pass protection for an interior player.',
                'Reliable in both gap and zone schemes. Versatile guard who can start immediately.',
            ],
            'avg'   => [
                'Functional starter with adequate strength and technique.',
                'Solid in the run game between the tackles but gets exposed against interior pass-rush moves.',
                'Will compete for a starting role. His effort and toughness grade out well even if the athleticism is average.',
            ],
        ],
        'C'   => [
            'elite' => [
                'Quarterback of the offensive line with elite mental processing.',
                'The best center prospect in the class — makes all the calls, handles every stunt, and anchors in pass-pro.',
                'A true alpha in the middle. His communication and identification of pressures are already NFL-caliber.',
            ],
            'high'  => [
                'Smart, tough, and technically refined interior lineman.',
                'Makes the right calls at the line. Handles blitz pickups and combo blocks with ease.',
                'Technically polished center who rarely gets beat in one-on-one reps.',
            ],
            'avg'   => [
                'Reliable snapper with adequate blocking ability.',
                'Functional center who can hold up in the run game. Needs to improve his ability to handle A-gap pressure.',
                'Steady presence in the middle. Won\'t lose you games but won\'t win them either.',
            ],
        ],
        'DE'  => [
            'elite' => [
                'An unblockable force off the edge — generational first step and bend.',
                'The most disruptive pass rusher to enter the draft in a decade.',
                'His first step is violent, his bend around the arc is elite, and his hand usage is already pro-level.',
                'Offensive tackles have nightmares about this player. He is a one-man pressure generator.',
            ],
            'high'  => [
                'Explosive edge rusher with a diverse pass-rush repertoire.',
                'Long, bendy edge with elite closing speed.',
                'Gets off the ball with a burst that tackles can\'t handle. His speed-to-power conversion is devastating.',
                'Has a go-to move and a reliable counter. That two-move combination will translate immediately.',
            ],
            'avg'   => [
                'Active motor with flashes of pass-rush ability.',
                'Solid run defender who needs to develop a counter move.',
                'Plays hard on every snap but relies too much on effort and not enough on technique as a rusher.',
                'Can set the edge in the run game. The pass-rush plan needs refinement before he can be a full-time starter.',
            ],
        ],
        'DT'  => [
            'elite' => [
                'Interior disruptor who collapses the pocket on every snap.',
                'Dominates with a combination of power and quickness that offensive guards simply cannot match.',
                'The best interior defender in this class. Commands double-teams and still makes plays.',
            ],
            'high'  => [
                'Powerful nose tackle with impressive quickness for his size.',
                'Penetrating three-technique who can disrupt the run game and generate interior pressure.',
                'His get-off at the snap is special for an interior player. Creates negative plays consistently.',
            ],
            'avg'   => [
                'Run-stuffing tackle with limited pass-rush upside.',
                'Holds ground at the point of attack and eats blocks. A nose tackle who does the dirty work.',
                'Adequate interior defender who can fill a role in a rotation. Needs to add a pass-rush dimension.',
            ],
        ],
        'LB'  => [
            'elite' => [
                'Sideline-to-sideline eraser with elite instincts in coverage and run support.',
                'The total package at linebacker — he runs, he hits, he covers, he blitzes. You cannot take him off the field.',
                'Processes run fits at an elite level and has the range to match running backs in space.',
            ],
            'high'  => [
                'Rangy linebacker with playmaking ability at all three levels.',
                'Covers tight ends, fills the A-gap, and makes plays on the ball. A three-down linebacker.',
                'Reads the quarterback\'s eyes and breaks on routes with the instincts of a defensive back.',
            ],
            'avg'   => [
                'Solid tackler with decent range, limited in coverage.',
                'Can fill gaps and make tackles near the line of scrimmage but gets exposed when asked to carry routes.',
                'A two-down linebacker who will need to improve in coverage to stay on the field in passing situations.',
            ],
        ],
        'CB'  => [
            'elite' => [
                'Lockdown corner with elite ball skills and track-star speed.',
                'A true shutdown corner who erases half the field.',
                'His combination of length, speed, and ball production is the best I\'ve graded at corner in five years.',
                'Mirrors routes, stays in the hip pocket, and makes plays on the ball. The prototype.',
            ],
            'high'  => [
                'Sticky in man coverage with the speed to recover and the instincts to jump routes.',
                'Physical at the line of scrimmage with the foot speed to carry receivers vertically.',
                'Fluid hips and quick transitions. Can play press or off and handle both with confidence.',
            ],
            'avg'   => [
                'Competitive corner with adequate speed and physicality at the catch point.',
                'Can match up in zone coverage but will struggle against elite speed in man assignments.',
                'Physical defender who plays bigger than his size. Needs to improve his ball skills and deep-third coverage.',
            ],
        ],
        'S'   => [
            'elite' => [
                'Versatile safety who can play the deep half, cover slot receivers, and fill the box.',
                'The most complete safety in this class — he does everything and does it at a high level.',
                'Has the range to play single-high, the physicality to play in the box, and the coverage skills to handle tight ends.',
            ],
            'high'  => [
                'Rangy ball-hawk with plus instincts in zone coverage.',
                'Reads the quarterback\'s eyes and drives on throws with the closing speed to make plays.',
                'A safety who can match up with tight ends in man coverage. That skill set is rare and valuable.',
            ],
            'avg'   => [
                'Solid tackler with good size, limited range in deep coverage.',
                'A box safety who will contribute in run support but may be a liability in deep-third responsibilities.',
                'Functional safety who plays with effort and toughness but lacks the range to be a true center-fielder.',
            ],
        ],
    ];

    // Position-specific detailed analysis templates (for expanded scouting reports)
    private const POSITION_ANALYSIS = [
        'QB' => [
            'strengths' => [
                'His arm talent is the headline. He can drive the ball with velocity to every level of the field and fit throws into tight windows that most college quarterbacks can\'t even attempt.',
                'What stands out on film is his pocket presence. He navigates pressure with subtle movements, keeps his eyes downfield, and delivers strikes while absorbing contact.',
                'The pre-snap processing is advanced. He reads defenses, identifies the mike, and adjusts protections with the command of a fifth-year starter.',
                'His ability to manipulate safeties with his eyes before pulling the trigger is something you rarely see at the college level.',
                'His throw-on-the-run ability is special. He can attack all three levels while moving to his left or right without losing velocity or accuracy.',
            ],
            'weaknesses' => [
                'The concern is his decision-making under duress. When the pocket collapses, he tends to force throws into coverage rather than living to see another down.',
                'He needs to learn the value of the checkdown. Too often he bypasses the easy completion in search of the home-run ball.',
                'His footwork in the pocket can get sloppy. When his base narrows, his accuracy suffers — particularly on intermediate throws.',
                'There are games where he tries to do too much. The hero ball tendency is exciting in college but will get you benched in the pros.',
                'His deep ball accuracy is inconsistent. He can uncork a beautiful 50-yard bomb and then sail the next one five yards over his receiver\'s head.',
            ],
            'comparisons' => ['Patrick Mahomes', 'Justin Herbert', 'Josh Allen', 'Jalen Hurts', 'Joe Burrow', 'Lamar Jackson', 'Trevor Lawrence', 'C.J. Stroud', 'Dak Prescott', 'Kirk Cousins'],
        ],
        'RB' => [
            'strengths' => [
                'His vision between the tackles is exceptional. He sets up blocks with patience, then explodes through the hole with a burst that catches linebackers flat-footed.',
                'Contact balance is elite. Defenders hit him at the line of scrimmage and he bounces off, keeping his feet churning for an extra three or four yards.',
                'He is a legitimate weapon in the passing game. His route-running from the backfield is polished, and he catches the ball naturally away from his frame.',
                'His ability to make the first man miss in the open field is game-changing. The jump-cut and the spin move are both devastating.',
                'Pass protection is an underrated part of his game. He identifies blitzers, engages with proper technique, and gives his quarterback time.',
            ],
            'weaknesses' => [
                'Ball security is a concern. He had multiple fumbles in high-leverage situations, and that will make coaches hesitant to trust him in the red zone.',
                'He dances too much behind the line. At the next level, those hesitation runs will result in losses rather than big gains.',
                'His top-end speed is just adequate. He won\'t outrun defensive backs in the open field, which limits his big-play ceiling.',
                'He needs to improve his blitz pickup ability. There were too many reps where he whiffed on blitzing linebackers.',
                'His durability is a question mark after a heavy college workload. Can his body hold up to 250 carries a season?',
            ],
            'comparisons' => ['Saquon Barkley', 'Derrick Henry', 'Nick Chubb', 'Christian McCaffrey', 'Alvin Kamara', 'Breece Hall', 'Josh Jacobs', 'Jonathan Taylor', 'Bijan Robinson', 'Jahmyr Gibbs'],
        ],
        'WR' => [
            'strengths' => [
                'His route-running is the best in this class. He sells every stem, creates separation with sharp breaks, and finds the soft spot in every zone.',
                'After the catch, he becomes a different player. His vision and acceleration turn five-yard completions into 25-yard gains.',
                'He has natural hands. The ball sticks. Contested catches, back-shoulder throws, balls away from his frame — he catches everything.',
                'His release at the line of scrimmage is already pro-level. Press corners cannot get their hands on him.',
                'He wins at every level. Short crossers, intermediate digs, deep posts — his route tree is complete and he runs each one with precision.',
            ],
            'weaknesses' => [
                'He struggles against physical press coverage. When corners get into his frame at the line, he has trouble recovering his route.',
                'His concentration drops are concerning. There are too many plays where he takes his eyes off the ball before securing the catch.',
                'He needs to add strength to his frame. The physicality of professional defensive backs will be an adjustment.',
                'His speed is adequate but not elite. He won\'t run past corners at the next level — he\'ll need to win with technique.',
                'He disappears against top competition. His biggest games came against weaker secondaries.',
            ],
            'comparisons' => ['Ja\'Marr Chase', 'CeeDee Lamb', 'Tyreek Hill', 'Davante Adams', 'Justin Jefferson', 'A.J. Brown', 'Chris Olave', 'Garrett Wilson', 'Amon-Ra St. Brown', 'Stefon Diggs'],
        ],
        'TE' => [
            'strengths' => [
                'He is a true dual-threat tight end. His inline blocking is physical and effective, and his ability to release into routes creates mismatches that defenses cannot solve.',
                'His catch radius is enormous. He plucks the ball out of the air at its highest point and shields defenders with his frame.',
                'He finds the soft spots in zone coverage instinctively — sitting down in windows and presenting a target for his quarterback.',
            ],
            'weaknesses' => [
                'His blocking technique needs refinement. He has the size and willingness but gets off balance at the point of attack.',
                'He is not a consistent separator against man coverage. Linebackers who can mirror his movements will limit his impact.',
                'His route tree is limited. He runs seams and crossers effectively but needs to develop more nuance in his route running.',
            ],
            'comparisons' => ['Travis Kelce', 'George Kittle', 'Mark Andrews', 'T.J. Hockenson', 'Kyle Pitts', 'Sam LaPorta', 'Dallas Goedert', 'Dalton Kincaid'],
        ],
        'OT' => [
            'strengths' => [
                'His pass sets are technically refined. The kick-slide is smooth, his hands strike on time, and his anchor is rock-solid against power rushers.',
                'He plays with the kind of length and leverage that offensive line coaches dream about. He keeps defenders at arm\'s length and controls the rep.',
                'His ability to handle speed-to-power conversions is advanced. Edge rushers who try to bull him find a brick wall.',
            ],
            'weaknesses' => [
                'His lateral agility is a concern against the fastest edge rushers in the league. He will need to refine his foot speed.',
                'He gets grabby when he\'s beat. That tendency will draw holding penalties at the professional level.',
                'His recovery ability when beaten on the initial move is limited. He must win the first exchange or the rep is lost.',
            ],
            'comparisons' => ['Penei Sewell', 'Tristan Wirfs', 'Rashawn Slater', 'Paris Johnson Jr.', 'Laremy Tunsil', 'Jedrick Wills', 'Andrew Thomas', 'Joe Alt'],
        ],
        'DE' => [
            'strengths' => [
                'His first step off the edge is explosive. He crosses the face of the tackle before they can get set, bending the arc with elite flexibility.',
                'His pass-rush plan is advanced. He has a primary move, a counter, and the awareness to adjust based on the tackle\'s set. That three-move combination is rare for a college rusher.',
                'He sets the edge against the run with violence and discipline. He is not just a pass-rush specialist — he is a complete defensive end.',
                'His closing speed to the quarterback is devastating. Once he turns the corner, there is no recovery for the tackle.',
                'His hand usage is already pro-level. Rip, swim, chop — he wins with technique, not just athleticism.',
            ],
            'weaknesses' => [
                'He needs to develop a more consistent counter move. When tackles take away his primary rush, he runs himself out of the play.',
                'His power element is underdeveloped. He wins with speed and bend but gets stalled against bigger tackles who anchor effectively.',
                'He tends to get too far upfield in the run game. Disciplined offensive tackles can wash him past the play.',
                'His pad level rises through contact. He needs to play lower and with more leverage to convert speed to power.',
                'He is inconsistent with effort on run plays. Some snaps he dominates, others he takes off. That will not fly at the next level.',
            ],
            'comparisons' => ['Myles Garrett', 'Micah Parsons', 'Nick Bosa', 'Chase Young', 'Aidan Hutchinson', 'Will Anderson Jr.', 'Kayvon Thibodeaux', 'Jaelan Phillips', 'Travon Walker', 'Maxx Crosby'],
        ],
        'DT' => [
            'strengths' => [
                'His ability to penetrate the A-gap and disrupt the run game at the point of attack is elite. He commands double-teams on every snap.',
                'His first-step quickness for an interior player is special. He is in the backfield before guards can get their hands on him.',
                'He collapses the pocket from the inside, which is the most valuable thing a defensive tackle can do in today\'s passing league.',
            ],
            'weaknesses' => [
                'His pass-rush repertoire is limited. He relies on power and effort but needs to develop finesse moves to become a complete rusher.',
                'His conditioning is a concern. He fades in the fourth quarter and his motor runs inconsistently through a full game.',
                'He gets washed out of his gap against double-teams. Needs to improve his anchor and pad level at the point of attack.',
            ],
            'comparisons' => ['Aaron Donald', 'Chris Jones', 'Quinnen Williams', 'Dexter Lawrence', 'Jalen Carter', 'Ed Oliver', 'Javon Hargrave', 'Christian Wilkins'],
        ],
        'LB' => [
            'strengths' => [
                'He has sideline-to-sideline range that is rare for a player his size. He runs down ball carriers from behind and covers ground in zone like a safety.',
                'His instincts against the run are exceptional. He reads his keys, fills the gap, and finishes with authority.',
                'He reads the RPO like a veteran. His ability to diagnose run-pass options and react accordingly is already at a professional level.',
            ],
            'weaknesses' => [
                'His coverage ability against tight ends and running backs is a concern. He gets outmatched in man assignments against athletic pass-catchers.',
                'He takes false steps against play-action, which puts him out of position in zone coverage.',
                'His blitz timing and pass-rush technique need work. He has the athleticism to be an effective rusher but the execution is inconsistent.',
            ],
            'comparisons' => ['Fred Warner', 'Roquan Smith', 'Devin White', 'Patrick Queen', 'Tremaine Edmunds', 'Devin Lloyd', 'Foye Oluokun', 'Jordyn Brooks'],
        ],
        'CB' => [
            'strengths' => [
                'He has a natural feel for zone coverage, reading the quarterback\'s eyes and breaking on throws with elite timing.',
                'His man coverage technique is polished. He mirrors routes, stays in phase, and makes plays at the catch point without drawing flags.',
                'His ball production is outstanding. He does not just break up passes — he intercepts them. His ball-hawking instincts create turnovers.',
                'His press technique at the line is disruptive. He gets his hands on receivers, reroutes them, and takes away the timing of the route.',
            ],
            'weaknesses' => [
                'He gets too handsy downfield. The penalties will come at the professional level where officials enforce illegal contact strictly.',
                'His deep speed is a concern. He can cover in the short and intermediate areas but gets exposed on double-moves and go routes.',
                'His tackling in run support is inconsistent. He avoids contact at times, which is a problem on the perimeter.',
                'He panics when he loses vision of the ball. His recovery technique when beaten needs significant improvement.',
            ],
            'comparisons' => ['Sauce Gardner', 'Derek Stingley Jr.', 'Jaire Alexander', 'Trevon Diggs', 'Devon Witherspoon', 'Pat Surtain II', 'Darius Slay', 'Denzel Ward'],
        ],
        'S' => [
            'strengths' => [
                'He has true center-field range. He covers ground in the deep third with the speed and instincts to be a single-high safety from day one.',
                'His versatility is his calling card. He can play deep, cover the slot, blitz from the secondary, and fill against the run.',
                'His ability to diagnose plays pre-snap and communicate adjustments to the secondary makes him the quarterback of the back end.',
            ],
            'weaknesses' => [
                'His man coverage skills against slot receivers need development. He can get turned around by quick-twitch route runners.',
                'He is aggressive downhill, which sometimes leaves him out of position when the play goes vertical behind him.',
                'His tackling angles are inconsistent. He misses more than you would like from a safety, especially in the open field.',
            ],
            'comparisons' => ['Kyle Hamilton', 'Jessie Bates III', 'Derwin James', 'Antoine Winfield Jr.', 'Jevon Holland', 'Xavier McKinney', 'Jimmie Ward', 'Minkah Fitzpatrick'],
        ],
        'OG' => [
            'strengths' => [
                'He is a mauler in the run game. His ability to generate movement at the point of attack and create running lanes is elite.',
                'His pass protection is sneaky good for a guard. He mirrors interior rushers and anchors against power with impressive consistency.',
                'He pulls with athleticism and finds his target in space. His ability to lead on outside runs makes him scheme-versatile.',
            ],
            'weaknesses' => [
                'He struggles against interior quickness. Defensive tackles who win with finesse can get past him before he can get his hands engaged.',
                'His conditioning needs work. He fades in the second half and his technique deteriorates when he is tired.',
                'He is limited in pass protection against stunts and twists. He needs to improve his ability to pass off interior pressure.',
            ],
            'comparisons' => ['Quenton Nelson', 'Zack Martin', 'Joel Bitonio', 'Chris Lindstrom', 'Tyler Smith', 'Landon Dickerson', 'Andrew Norwell', 'Kevin Zeitler'],
        ],
        'C' => [
            'strengths' => [
                'He is the brain of the offensive line. His ability to identify fronts, call protections, and handle blitz pickups is advanced beyond his years.',
                'His snapping is flawless — he never puts his quarterback in a bad position with an errant snap, even in loud environments.',
                'He anchors well against nose tackles and handles interior pressure with poise and proper hand placement.',
            ],
            'weaknesses' => [
                'He lacks the athleticism to reach block effectively in a zone-running scheme. His limited mobility could restrict scheme fit.',
                'He gets overwhelmed by power nose tackles who can walk him back into the quarterback\'s lap.',
                'His ability to combo block and climb to the second level is inconsistent. He sometimes loses his assignment in traffic.',
            ],
            'comparisons' => ['Jason Kelce', 'Creed Humphrey', 'Frank Ragnow', 'Tyler Linderbaum', 'Corey Linsley', 'Ryan Jensen', 'Erik McCoy', 'Lloyd Cushenberry III'],
        ],
    ];

    // Jake Morrison analytical openers (varied to prevent repetition)
    private const JAKE_OPENERS = [
        'big_board' => [
            "After reviewing film on every prospect in this class — and I mean every snap, every rep, every game — my board is finalized.",
            "I've spent the last four months buried in tape. Pro days, combine testing, all-star game reps — all of it factors in. Here's where I've landed.",
            "The evaluation is never done, but at some point you have to commit your board to paper. After 200+ hours of film study, this is my definitive ranking.",
            "Every year I tell myself I won't get attached to prospects. Every year I fail. But the board doesn't lie — and neither does the tape.",
            "Film. Measurables. Production. Character. I weigh all of it, but the tape always gets the final word. Here's what the tape told me this year.",
        ],
        'combine' => [
            "The combine is a tool, not a verdict. But it's a valuable tool — and this year, it separated some prospects from the pack.",
            "Numbers don't tell the whole story, but they tell part of it. And this combine produced some numbers that demand attention.",
            "I walked into Indianapolis with my board set. I walked out with three significant changes. Here's what I saw.",
            "Every year, the combine creates winners and losers. The smart evaluators use it as confirmation, not revelation. Here's my breakdown.",
            "Athletic testing is one piece of the puzzle. But when a prospect tests in the 95th percentile at his position, you sit up and take notice.",
        ],
    ];

    // Nina Charles narrative openers (varied to prevent repetition)
    private const NINA_OPENERS = [
        'player_to_watch' => [
            "Every draft class has that one name — the player everyone is talking about, the one whose name echoes through war rooms and scout meetings and late-night film sessions.",
            "I first heard his name in a hallway at the Senior Bowl, whispered between two scouts who didn't know I was listening.",
            "There's a player in this draft that has divided every front office in the league. Half think he's a top-three lock. The other half aren't sure he's a first-rounder.",
            "Some prospects announce themselves with a single play. A throw that defies physics. A run that embarrasses an entire defense. A hit that makes the stadium gasp.",
            "The phone won't stop ringing. Every GM, every scout, every coach I talk to brings up the same name.",
        ],
        'spotlight' => [
            "What the numbers won't tell you about {name} is what happens after practice.",
            "I spent three days in {college}'s facility last week, and everywhere I went, {name}'s name came up.",
            "There's a story behind every prospect. {name}'s story is one that deserves to be told.",
            "Before we talk about measurables and combine grades, let me tell you who {name} really is.",
            "I've profiled hundreds of prospects over the years. {name} is different — and not just because of the talent.",
        ],
        'draft_day' => [
            "The phone calls are over. The war rooms are empty. The board is clear. Draft night is done — and what a night it was.",
            "Somewhere between the first pick and the final handshake, draft night became something more than an event. It became a story.",
            "They always say draft night is about hope. But it's also about heartbreak, surprise, and the moments that make you grab the person next to you.",
            "I've covered ten drafts. This one will stay with me.",
            "The green room lights dimmed, the commissioner stepped to the podium, and a new chapter began for thirty-two franchises.",
        ],
    ];

    // Anonymous scout quotes (for variety)
    private const SCOUT_QUOTES = [
        'elite_praise' => [
            "He's the best prospect I've evaluated in five years. And I don't say that lightly.",
            "I told my GM — if we don't take this kid, I'm turning in my resignation.",
            "There's a tier above 'first-round grade' and this kid is in it. We're talking generational.",
            "I've been doing this for twenty years. This kid is special. You don't pass on this talent.",
            "Every team in the top five has him circled. Everyone knows. The only question is who gets there first.",
        ],
        'high_praise' => [
            "He's going to be a starter from day one. Book it.",
            "I have a first-round grade on him. I think he's the safest pick in the class.",
            "The ceiling is through the roof. If he puts it all together, we're talking Pro Bowl.",
            "Our coaching staff is already scheming for him. That tells you everything.",
            "He's the kind of player you build a draft around. Take him and don't look back.",
        ],
        'concern' => [
            "We took him off our board entirely. The risk isn't worth it for us.",
            "The talent is too good to pass up, but you better have a strong locker room.",
            "I've never seen a prospect this talented fall this far in our internal rankings.",
            "We love the player. We're worried about the person. And in this league, that matters.",
            "If you draft him, you better be ready for the phone calls. It's going to be a story.",
        ],
        'slide' => [
            "Somebody is going to look back on this in three years and wonder how they let him slide.",
            "He fell because of the narrative, not the tape. That's a mistake.",
            "I guarantee you — five teams' war rooms were screaming when he was still on the board.",
            "This is the steal of the draft. You're getting a top-ten talent outside the first round.",
            "The league got this one wrong. This kid is going to prove a lot of people wrong.",
        ],
        'riser' => [
            "League sources say at least three teams in the top 10 have him on their shortlist.",
            "His stock has gone through the roof in the last two weeks. Private workouts have been electric.",
            "I've heard he's moved into the first-round conversation for multiple teams.",
            "One GM told me this week: 'If he's there at our pick, we're sprinting to the podium.'",
            "Every scout I've talked to in the last week has bumped him up. The momentum is real.",
        ],
        'faller' => [
            "Teams are quietly moving him down their boards. The buzz has cooled significantly.",
            "I know of at least two teams that have removed him from their first-round consideration entirely.",
            "The red flags are piling up. What looked like a sure thing in September feels uncertain now.",
            "His stock has taken a real hit. The question is whether he can recover before draft night.",
            "Sources say his interview process has not gone well. Teams are concerned about his football IQ.",
        ],
    ];

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
    }

    // ================================================================
    //  Public API
    // ================================================================

    /**
     * Identify blue chip and drama prospects from a draft class.
     *
     * - potential = 'elite' → generational
     * - combine_score >= 85 AND actual_overall >= 72 → blue chip
     * - character_flag IS NOT NULL AND actual_overall >= 70 → bust risk / drama prospect
     */
    public function identifyBluechipProspects(int $draftClassId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM draft_prospects
             WHERE draft_class_id = ?
               AND (
                   potential = 'elite'
                   OR (combine_score >= 85 AND actual_overall >= 72)
                   OR (character_flag IS NOT NULL AND character_flag != '' AND actual_overall >= 70)
               )
             ORDER BY actual_overall DESC"
        );
        $stmt->execute([$draftClassId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Generate initial pre-draft coverage (3 articles) when the draft class
     * is first generated at the start of offseason.
     */
    public function generatePreDraftCoverage(int $leagueId, int $seasonId, int $draftClassId): void
    {
        $now = date('Y-m-d H:i:s');

        // Get season year for headlines
        $year = $this->getSeasonYear($leagueId);

        // Fetch top prospects
        $stmt = $this->db->prepare(
            "SELECT * FROM draft_prospects WHERE draft_class_id = ? ORDER BY actual_overall DESC LIMIT 15"
        );
        $stmt->execute([$draftClassId]);
        $topProspects = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($topProspects)) {
            return;
        }

        $blueChips = $this->identifyBluechipProspects($draftClassId);
        $generational = array_filter($blueChips, fn($p) => ($p['potential'] ?? '') === 'elite');

        // ── Article 1: Big Board (Jake Morrison) ────────────────────────
        $this->generateBigBoardArticle($leagueId, $seasonId, $year, $topProspects, $generational, $now);

        // ── Article 2: Player to Watch (Nina Charles) ───────────────────
        $this->generatePlayerToWatchArticle($leagueId, $seasonId, $year, $topProspects, $blueChips, $now);

        // ── Article 3: Draft Needs (Jake Morrison) ──────────────────────
        $this->generateDraftNeedsArticle($leagueId, $seasonId, $year, $now);
    }

    /**
     * Generate a weekly draft update with progressive storylines.
     * Called each week during offseason.
     */
    public function generateWeeklyDraftUpdate(int $leagueId, int $seasonId, int $week, int $draftClassId): void
    {
        $now = date('Y-m-d H:i:s');
        $year = $this->getSeasonYear($leagueId);
        $blueChips = $this->identifyBluechipProspects($draftClassId);

        if (empty($blueChips)) {
            return;
        }

        // Rotate between Jake and Nina each week
        $scoutKey = ($week % 2 === 0) ? 'jake_morrison' : 'nina_charles';
        $scout = $this->pickScout($scoutKey);

        // Cycle through topics
        $topicIndex = ($week - 1) % count(self::WEEKLY_TOPICS);
        $topic = self::WEEKLY_TOPICS[$topicIndex];

        // If Nina gets a Jake topic or vice-versa, remap
        if ($scoutKey === 'jake_morrison' && in_array($topic, ['prospect_spotlight', 'character_concerns'])) {
            $topic = 'mock_draft'; // Jake does mock drafts
        } elseif ($scoutKey === 'nina_charles' && in_array($topic, ['mock_draft', 'combine_report'])) {
            $topic = 'stock_watch'; // Nina does stock watch
        }

        match ($topic) {
            'mock_draft'          => $this->generateMockDraft($leagueId, $seasonId, $week, $year, $draftClassId, $scout, $now),
            'prospect_spotlight'  => $this->generateProspectSpotlight($leagueId, $seasonId, $week, $year, $blueChips, $scout, $now),
            'combine_report'      => $this->generateCombineReport($leagueId, $seasonId, $week, $year, $draftClassId, $scout, $now),
            'stock_watch'         => $this->generateStockWatch($leagueId, $seasonId, $week, $year, $draftClassId, $scout, $now),
            'character_concerns'  => $this->generateCharacterConcerns($leagueId, $seasonId, $week, $year, $draftClassId, $scout, $now),
        };
    }

    /**
     * Generate draft-day narrative coverage after the draft completes.
     * Both writers react to the picks.
     */
    public function generateDraftDayNarrative(int $leagueId, int $seasonId, array $picks): void
    {
        $now = date('Y-m-d H:i:s');
        $year = $this->getSeasonYear($leagueId);

        if (empty($picks)) {
            return;
        }

        // ── Nina Charles: Draft Day Drama ───────────────────────────────
        $this->generateDraftDayDramaArticle($leagueId, $seasonId, $year, $picks, $now);

        // ── Jake Morrison: Draft Grades ─────────────────────────────────
        $this->generateDraftGradesArticle($leagueId, $seasonId, $year, $picks, $now);
    }

    // ================================================================
    //  Pre-Draft Coverage Articles
    // ================================================================

    private function generateBigBoardArticle(int $leagueId, int $seasonId, int $year, array $topProspects, array $generational, string $now): void
    {
        $scout = $this->pickScout('jake_morrison');
        $top15 = array_slice($topProspects, 0, 15);

        $hasGenerational = !empty($generational);

        // Varied opener
        if ($hasGenerational) {
            $body = "This class has a generational talent — and everyone in the league knows it. ";
            $body .= self::JAKE_OPENERS['big_board'][array_rand(self::JAKE_OPENERS['big_board'])] . "\n\n";
        } else {
            $body = self::JAKE_OPENERS['big_board'][array_rand(self::JAKE_OPENERS['big_board'])] . " ";
            $body .= "This class has some legitimate difference-makers, but there are also some prospects whose stock doesn't match the tape. Let me walk you through it.\n\n";
        }

        // Tier system
        $tiers = [
            ['label' => 'TIER 1: FRANCHISE CHANGERS', 'start' => 0, 'end' => 3],
            ['label' => 'TIER 2: DAY 1 STARTERS', 'start' => 3, 'end' => 8],
            ['label' => 'TIER 3: HIGH-UPSIDE PICKS', 'start' => 8, 'end' => 15],
        ];

        foreach ($tiers as $tier) {
            $tierProspects = array_slice($top15, $tier['start'], $tier['end'] - $tier['start']);
            if (empty($tierProspects)) continue;

            $body .= "--- {$tier['label']} ---\n\n";

            foreach ($tierProspects as $idx => $p) {
                $rank = $tier['start'] + $idx + 1;
                $name = $p['first_name'] . ' ' . $p['last_name'];
                $pos = $p['position'];
                $college = $p['college'] ?? 'Unknown';
                $potential = $p['potential'] ?? 'average';
                $combineGrade = $p['combine_grade'] ?? $this->deriveCombineGrade((int) ($p['combine_score'] ?? 50));
                $combineScore = (int) ($p['combine_score'] ?? 50);

                $body .= "{$rank}. {$name}, {$pos} — {$college}\n";
                $body .= "   Combine Grade: {$combineGrade} | Combine Score: {$combineScore}\n";

                // Sentence 1: Primary scouting line (strength)
                $scoutLine = $this->getScoutingLine($pos, $potential);
                $body .= "   {$scoutLine} ";

                // Sentence 2: Position-specific strength detail
                $posAnalysis = self::POSITION_ANALYSIS[$pos] ?? null;
                if ($posAnalysis) {
                    $strengths = $posAnalysis['strengths'] ?? [];
                    if (!empty($strengths)) {
                        $body .= $strengths[array_rand($strengths)] . " ";
                    }
                }

                // Sentence 3: Weakness or concern + NFL comparison
                if ($posAnalysis) {
                    $weaknesses = $posAnalysis['weaknesses'] ?? [];
                    $comparisons = $posAnalysis['comparisons'] ?? [];

                    if ($potential === 'elite' && !empty($comparisons)) {
                        $comp = $comparisons[array_rand($comparisons)];
                        $body .= "NFL comparison: {$comp}. ";
                        if (!empty($weaknesses) && $rank > 1) {
                            $body .= "The only nitpick? " . $weaknesses[array_rand($weaknesses)];
                        }
                    } elseif ($potential === 'high') {
                        if (!empty($comparisons)) {
                            $comp = $comparisons[array_rand($comparisons)];
                            $body .= "Think a developmental version of {$comp}. ";
                        }
                        if (!empty($weaknesses)) {
                            $body .= $weaknesses[array_rand($weaknesses)];
                        }
                    } else {
                        if (!empty($weaknesses)) {
                            $body .= $weaknesses[array_rand($weaknesses)] . " ";
                        }
                        if (!empty($comparisons)) {
                            $comp = $comparisons[array_rand($comparisons)];
                            $body .= "Ceiling comp: {$comp} lite.";
                        }
                    }
                }

                // Character flag note
                if (!empty($p['character_flag'])) {
                    $flagText = ucwords(str_replace('_', ' ', $p['character_flag']));
                    $body .= " (Note: {$flagText} concerns could impact draft position.)";
                }

                $body .= "\n\n";
            }
        }

        // Closing section
        if ($hasGenerational) {
            $gen = reset($generational);
            $genName = $gen['first_name'] . ' ' . $gen['last_name'];
            $body .= "THE BOTTOM LINE\n\n";
            $body .= "The headliner is obvious: {$genName} from {$gen['college']} is the best prospect I've evaluated since I started this job. ";
            $body .= "Whoever picks first is getting a franchise cornerstone — the kind of player you build around for the next decade. ";
            $body .= "After him, the drop-off to Tier 2 is real, but there's genuine starting-caliber talent throughout the top eight. ";
            $body .= "The value picks in Tier 3 are where smart franchises will separate themselves. ";
            $quote = self::SCOUT_QUOTES['elite_praise'][array_rand(self::SCOUT_QUOTES['elite_praise'])];
            $body .= "As one veteran scout told me: \"{$quote}\"\n\n";
        } else {
            $body .= "THE BOTTOM LINE\n\n";
            $body .= "There's no consensus number-one in this class, which makes the draft unpredictable — and unpredictable drafts produce the best stories. ";
            $body .= "The Tier 1 prospects are legitimate franchise-building blocks, and there's enough depth in Tier 2 to make the middle of the first round fascinating. ";
            $body .= "Tier 3 is where the real scouting happens — the players who will separate the good front offices from the great ones.\n\n";
        }

        $body .= "My board will continue to evolve as we get new information from pro days and private workouts. But as of today, this is where I stand — and I'm putting my name on it. Stay locked in.";

        $this->insertArticle(
            $leagueId, $seasonId, null, 'draft_coverage',
            "Morrison's Big Board: Top 15 Prospects in the {$year} Draft Class",
            $body, $scout['name'], $scout['style'], null, null, $now
        );
    }

    private function generatePlayerToWatchArticle(int $leagueId, int $seasonId, int $year, array $topProspects, array $blueChips, string $now): void
    {
        $scout = $this->pickScout('nina_charles');

        // Pick the most interesting prospect: generational first, then highest with character flag
        $featured = null;
        foreach ($blueChips as $p) {
            if (($p['potential'] ?? '') === 'elite') {
                $featured = $p;
                break;
            }
        }
        if (!$featured) {
            foreach ($blueChips as $p) {
                if (!empty($p['character_flag'])) {
                    $featured = $p;
                    break;
                }
            }
        }
        if (!$featured && !empty($topProspects)) {
            $featured = $topProspects[0];
        }

        if (!$featured) {
            return;
        }

        $name = $featured['first_name'] . ' ' . $featured['last_name'];
        $pos = $featured['position'];
        $college = $featured['college'] ?? 'Unknown';
        $potential = $featured['potential'] ?? 'average';
        $hasFlag = !empty($featured['character_flag']);
        $flagText = $hasFlag ? ucwords(str_replace('_', ' ', $featured['character_flag'])) : '';
        $combineGrade = $featured['combine_grade'] ?? $this->deriveCombineGrade((int) ($featured['combine_score'] ?? 50));
        $combineScore = (int) ($featured['combine_score'] ?? 50);
        $posAnalysis = self::POSITION_ANALYSIS[$pos] ?? null;

        // Paragraph 1: The hook (varied opener)
        $openers = self::NINA_OPENERS['player_to_watch'];
        $body = $openers[array_rand($openers)] . " ";
        $body .= "This year, that name is {$name}.\n\n";

        // Paragraph 2: Origin story
        $conferences = ['SEC', 'Big Ten', 'Big 12', 'ACC', 'Pac-12', 'AAC', 'Mountain West', 'Sun Belt', 'Conference USA', 'MAC'];
        $conference = $conferences[array_rand($conferences)];
        if ($potential === 'elite') {
            $body .= "From {$college}, where he dominated the {$conference} for three seasons, {$name} arrived on the national radar as a true freshman — ";
            $body .= "the kind of player who makes you put down your coffee and rewind the tape. By his sophomore year, scouts were making the trip to campus specifically to watch him. ";
            $body .= "By the time he declared for the draft, the conversation had shifted from 'Will he go in the first round?' to 'Is he the best prospect to enter the draft in a decade?'\n\n";
        } else {
            $body .= "From {$college}, where he emerged as one of the {$conference}'s most productive players over the last two seasons, {$name}'s path to the draft wasn't paved in gold. ";
            $body .= "He wasn't the five-star recruit who arrived on campus with a spotlight already shining. He earned it — snap by snap, game by game, season by season. ";
            $body .= "And now, the football world is paying attention.\n\n";
        }

        // Paragraph 3: Physical profile
        $body .= "The physical profile speaks for itself. ";
        if ($combineScore >= 85) {
            $body .= "With a combine grade of {$combineGrade} and an athletic testing score of {$combineScore}, {$name} checked every box that front offices want to see. ";
            $body .= "The measurables are elite. The kind of numbers that make personnel directors call their GMs at midnight.\n\n";
        } elseif ($combineScore >= 70) {
            $body .= "With a combine grade of {$combineGrade} and an athletic testing score of {$combineScore}, {$name} showed the physical traits to compete at the highest level. ";
            $body .= "Not a freak athlete, but a well-rounded one — and sometimes that translates better than raw numbers.\n\n";
        } else {
            $body .= "His combine grade of {$combineGrade} (score: {$combineScore}) won't blow anyone away, and {$name} knows it. ";
            $body .= "But ask anyone who's watched him play, and they'll tell you the same thing: the tape is better than the testing. ";
            $body .= "Some players are gamers, not testers — and {$name} is the definition.\n\n";
        }

        // Paragraph 4: Strengths in detail (position-specific)
        $scoutLine = $this->getScoutingLine($pos, $potential);
        $body .= "On the field, the strengths are impossible to miss. {$scoutLine} ";
        if ($posAnalysis && !empty($posAnalysis['strengths'])) {
            $strengthPool = $posAnalysis['strengths'];
            shuffle($strengthPool);
            $body .= $strengthPool[0] . " ";
            if (count($strengthPool) > 1) {
                $body .= $strengthPool[1];
            }
        }
        $body .= "\n\n";

        // Paragraph 5: Weaknesses / concerns
        $body .= "No prospect is perfect, and {$name} is no exception. ";
        if ($posAnalysis && !empty($posAnalysis['weaknesses'])) {
            $weaknessPool = $posAnalysis['weaknesses'];
            shuffle($weaknessPool);
            $body .= $weaknessPool[0] . " ";
            if (count($weaknessPool) > 1) {
                $body .= "Additionally, " . lcfirst($weaknessPool[1]);
            }
        } else {
            $body .= "Some scouts want to see more consistency against top competition, and there are always questions about how college production translates to the pros.";
        }
        $body .= " But the floor here is high, and the ceiling is even higher.\n\n";

        // Paragraph 6: Character flag (full paragraph if exists)
        if ($hasFlag) {
            $body .= "And then there's the part nobody in the league wants to talk about publicly — but everyone is talking about privately. ";
            $body .= "{$name}'s {$flagText} concerns have been a constant undercurrent throughout the evaluation process. ";
            $body .= "I've spoken to scouts from six different organizations this month, and the word 'complicated' comes up every single time. ";
            $body .= "One front office executive told me, 'The talent is undeniable. But we've been burned before, and the organization has to weigh what it means for the locker room.' ";
            $body .= "Another was more blunt: 'We're not taking him. Period.' ";
            $body .= "The truth, as always, lies somewhere in between. History is full of prospects who were flagged for character and became model citizens in the pros. ";
            $body .= "It's also full of cautionary tales. Every franchise will have to make its own call.\n\n";
        }

        // Paragraph 7: NFL fit and scout quote
        $body .= "Teams picking in the top five should be circling this name. ";
        if ($potential === 'elite') {
            $quote = self::SCOUT_QUOTES['elite_praise'][array_rand(self::SCOUT_QUOTES['elite_praise'])];
            $body .= "One scout who spoke on condition of anonymity put it simply: \"{$quote}\" ";
            $body .= "Barring a stunning trade, this feels like a lock for the first overall pick. ";
            $body .= "The only question is which franchise gets to build around him — and whether they're ready for the weight of that responsibility.\n\n";
        } else {
            $quote = self::SCOUT_QUOTES['high_praise'][array_rand(self::SCOUT_QUOTES['high_praise'])];
            $body .= "A scout who spoke on condition of anonymity told me: \"{$quote}\" ";
            $body .= "Mock drafts have him anywhere from the top five to the mid-first round, depending on who you talk to and what day of the week it is. ";
            $body .= "Wherever he goes, that team is getting a player who can make an immediate impact.\n\n";
        }

        // Paragraph 8: Closing — Nina's narrative warmth
        if ($posAnalysis && !empty($posAnalysis['comparisons'])) {
            $comp = $posAnalysis['comparisons'][array_rand($posAnalysis['comparisons'])];
            $body .= "The comparisons to {$comp} are inevitable, and while no prospect perfectly mirrors a pro, the archetype fits. ";
        }
        $body .= "This is just the beginning of {$name}'s story. Over the coming weeks, I'll be tracking his journey — the workouts, the interviews, the war-room debates. ";
        $body .= "By draft night, we'll know where {$name} lands. But whoever picks him should know this: they're not just getting a football player. ";
        $body .= "They're getting a story that's still being written. And I, for one, can't wait to see the next chapter.";

        $headlines = [
            "{$name}: The Most Intriguing Prospect in This Draft",
            "The {$name} File: Inside the Most Watched Prospect of {$year}",
            "All Eyes on {$name}: Why the {$year} Draft Starts With Him",
            "{$name} and the Weight of Expectations",
            "Draft Preview: The {$name} Question Every Team Must Answer",
        ];

        $this->insertArticle(
            $leagueId, $seasonId, null, 'feature',
            $headlines[array_rand($headlines)],
            $body, $scout['name'], $scout['style'], null, (int) $featured['id'], $now
        );
    }

    private function generateDraftNeedsArticle(int $leagueId, int $seasonId, int $year, string $now): void
    {
        $scout = $this->pickScout('jake_morrison');

        // Get teams with the worst records (most likely to pick high)
        $stmt = $this->db->prepare(
            "SELECT id, city, name, abbreviation, wins, losses FROM teams
             WHERE league_id = ?
             ORDER BY wins ASC, (points_for - points_against) ASC
             LIMIT 10"
        );
        $stmt->execute([$leagueId]);
        $teams = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($teams)) {
            return;
        }

        $body = "Draft season means need season. Every front office is staring at its roster, searching for the holes that cost them wins. ";
        $body .= "Here's my breakdown of the teams most likely to be picking at the top of the board — and what they should be targeting.\n\n";

        foreach ($teams as $team) {
            $needs = $this->getTeamNeeds((int) $team['id']);
            $topNeed = !empty($needs) ? $needs[0] : 'BPA';
            $secondNeed = count($needs) > 1 ? $needs[1] : null;

            $record = ($team['wins'] ?? 0) . '-' . ($team['losses'] ?? 0);
            $body .= "**{$team['city']} {$team['name']}** ({$record})\n";
            $body .= "Primary need: {$topNeed}";
            if ($secondNeed) {
                $body .= " | Also watching: {$secondNeed}";
            }
            $body .= "\n";

            // Brief analysis
            $body .= match ($topNeed) {
                'QB'  => "Until you find your quarterback, nothing else matters. This franchise needs to swing for the fences.\n",
                'DE', 'DT' => "The pass rush was nonexistent last season. Getting pressure with four would transform this defense.\n",
                'OT', 'OG', 'C' => "You can't develop a young quarterback if he's running for his life every snap. The line needs help.\n",
                'CB', 'S' => "The secondary was torched all year. A lockdown corner could be the missing piece.\n",
                'WR', 'TE' => "The passing game needs weapons. Time to give the quarterback someone to throw to.\n",
                'LB' => "The second level of the defense was a liability. A sideline-to-sideline linebacker changes everything.\n",
                'RB' => "The run game was anemic. A dynamic back could open up the entire offense.\n",
                default => "Best player available is the smart play here. Build through talent.\n",
            };
            $body .= "\n";
        }

        $body .= "Draft night is about matching need with talent. The teams that thread that needle will be the ones celebrating in the fall.";

        $this->insertArticle(
            $leagueId, $seasonId, null, 'draft_coverage',
            "Draft Day Guide: What Every Team Needs",
            $body, $scout['name'], $scout['style'], null, null, $now
        );
    }

    // ================================================================
    //  Weekly Draft Update Topics
    // ================================================================

    private function generateMockDraft(int $leagueId, int $seasonId, int $week, int $year, int $draftClassId, array $scout, string $now): void
    {
        // Determine which version of the mock
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM articles WHERE league_id = ? AND author_name = ? AND headline LIKE '%Mock Draft%'"
        );
        $stmt->execute([$leagueId, $scout['name']]);
        $mockVersion = ((int) $stmt->fetchColumn()) + 1;
        $versionLabel = $mockVersion === 1 ? '1.0' : (string) number_format($mockVersion * 1.0, 1);

        // Get ALL teams by draft order (worst record first, point diff as tiebreaker)
        $stmt = $this->db->prepare(
            "SELECT id, city, name, abbreviation, wins, losses, overall_rating
             FROM teams WHERE league_id = ?
             ORDER BY wins ASC, (points_for - points_against) ASC"
        );
        $stmt->execute([$leagueId]);
        $allTeams = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Get ALL available prospects ranked by overall/stock
        $stmt = $this->db->prepare(
            "SELECT * FROM draft_prospects
             WHERE draft_class_id = ? AND is_drafted = 0
             ORDER BY actual_overall DESC, COALESCE(combine_score, 50) DESC"
        );
        $stmt->execute([$draftClassId]);
        $allProspects = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($allTeams) || empty($allProspects)) return;

        // Build comprehensive team needs with context
        $teamNeeds = [];
        $teamBestByPos = [];
        foreach ($allTeams as $t) {
            $tid = (int) $t['id'];
            $teamNeeds[$tid] = $this->getTeamNeeds($tid);

            // Also get the best player at each position (to avoid drafting what they already have)
            $stmt2 = $this->db->prepare(
                "SELECT position, MAX(overall_rating) as best_ovr
                 FROM players WHERE team_id = ? AND status = 'active'
                 GROUP BY position"
            );
            $stmt2->execute([$tid]);
            $teamBestByPos[$tid] = [];
            foreach ($stmt2->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $teamBestByPos[$tid][$r['position']] = (int) $r['best_ovr'];
            }
        }

        // Mock the full first round (32 picks)
        $usedProspects = [];
        $picks = [];
        $pickCount = min(32, count($allTeams), count($allProspects));

        for ($i = 0; $i < $pickCount; $i++) {
            $team = $allTeams[$i];
            $tid = (int) $team['id'];
            $needs = $teamNeeds[$tid];
            $bestAtPos = $teamBestByPos[$tid] ?? [];

            // Score each available prospect for this team
            $bestMatch = null;
            $bestScore = -999;

            foreach ($allProspects as $p) {
                if (in_array((int) $p['id'], $usedProspects)) continue;

                $score = (int) $p['actual_overall'];
                $pos = $p['position'];
                $potential = $p['potential'] ?? 'average';

                // Bonus for filling a need (top 3 needs get bigger bonus)
                $needIdx = array_search($pos, $needs);
                if ($needIdx !== false) {
                    $score += (5 - min($needIdx, 4)) * 4; // +20 for #1 need, +16 for #2, etc.
                }

                // Bonus for elite/high potential
                if ($potential === 'elite') $score += 12;
                elseif ($potential === 'high') $score += 6;

                // Penalty if team already has a star at this position (80+ OVR)
                $existingBest = $bestAtPos[$pos] ?? 0;
                if ($existingBest >= 85) $score -= 20; // Already have a stud, don't draft this
                elseif ($existingBest >= 80) $score -= 10;

                // Combine score bonus
                $combineScore = (int) ($p['combine_score'] ?? 50);
                if ($combineScore >= 90) $score += 5;
                elseif ($combineScore >= 80) $score += 3;

                // Character flag penalty (slight — teams still draft talent)
                if (!empty($p['character_flag'])) $score -= 3;

                // Generational talent override — always top pick material
                if ($potential === 'elite' && $combineScore >= 88) {
                    $score += 15; // Near-impossible to pass on
                }

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestMatch = $p;
                }
            }

            if (!$bestMatch) continue;
            $usedProspects[] = (int) $bestMatch['id'];
            $picks[] = ['pick' => $i + 1, 'team' => $team, 'prospect' => $bestMatch, 'needs' => $needs];
        }

        // Build the article
        $body = $mockVersion === 1
            ? "It's that time of year. The combine is in the books, pro days are wrapping up, and front offices are burning midnight oil. After analyzing every roster, every need, and every prospect in this class, here's my projection for the full first round.\n\n"
            : "The board continues to shift. Since Mock Draft " . number_format(($mockVersion - 1) * 1.0, 1) . ", I've revisited every team's roster, adjusted for new intel, and updated my projections. Here's where things stand.\n\n";

        foreach ($picks as $p) {
            $pick = $p['pick'];
            $team = $p['team'];
            $prospect = $p['prospect'];
            $needs = $p['needs'];
            $pName = $prospect['first_name'] . ' ' . $prospect['last_name'];
            $pos = $prospect['position'];
            $college = $prospect['college'] ?? 'Unknown';
            $potential = $prospect['potential'] ?? 'average';
            $combineGrade = $prospect['combine_grade'] ?? $this->deriveCombineGrade((int) ($prospect['combine_score'] ?? 50));
            $isNeedPick = in_array($pos, array_slice($needs, 0, 3));
            $ovr = (int) $prospect['actual_overall'];

            // Build context-aware analysis for each pick
            $analysis = '';
            if ($potential === 'elite') {
                $analysis = "Generational talent. You don't overthink this — {$pName} is the best player in this class and it's not close.";
            } elseif ($isNeedPick && $ovr >= 72) {
                $analysis = "Fills their biggest need at {$pos} with a {$combineGrade}-grade prospect. This is a no-brainer.";
            } elseif ($isNeedPick) {
                $analysis = "{$pos} is a clear need, and {$pName} has the upside to be a starter by Year 2.";
            } elseif ($ovr >= 74) {
                $analysis = "Best player available. You can't pass on this kind of talent even if {$pos} isn't their top need.";
            } else {
                $analysis = "Solid value here. {$pName} grades out as a {$combineGrade} with {$potential} potential.";
            }

            if (!empty($prospect['character_flag'])) {
                $flag = ucwords(str_replace('_', ' ', $prospect['character_flag']));
                $analysis .= " Note: {$flag} concerns could cause a slide on draft day.";
            }

            $body .= "**{$pick}. {$team['city']} {$team['name']}** ({$team['wins']}-{$team['losses']}) — **{$pName}**, {$pos}, {$college}\n";
            $body .= "   {$analysis}\n";
            $body .= "   Top needs: " . implode(', ', array_slice($needs, 0, 3)) . "\n\n";
        }

        if ($mockVersion === 1) {
            $body .= "This is just the beginning. Boards will shift, trades will reshape the order, and draft day always has surprises. But this is where I see it right now — and I'm putting my name on it.";
        } else {
            $body .= "The draft is getting closer, and I'm more confident in this board than the last one. But as always, draft day has a mind of its own. Stay tuned.";
        }

        $this->insertArticle(
            $leagueId, $seasonId, $week, 'draft_coverage',
            "Morrison Mock Draft {$versionLabel}",
            $body, $scout['name'], $scout['style'], null, null, $now
        );
    }

    private function generateProspectSpotlight(int $leagueId, int $seasonId, int $week, int $year, array $blueChips, array $scout, string $now): void
    {
        if (empty($blueChips)) {
            return;
        }

        // Pick a prospect we haven't written about recently
        $featured = null;
        foreach ($blueChips as $p) {
            $stmt = $this->db->prepare(
                "SELECT id FROM articles WHERE league_id = ? AND author_name = ? AND player_id = ? AND type = 'feature'
                 ORDER BY published_at DESC LIMIT 1"
            );
            $stmt->execute([$leagueId, $scout['name'], (int) $p['id']]);
            if (!$stmt->fetch()) {
                $featured = $p;
                break;
            }
        }

        // If we've covered all of them, revisit the top one
        if (!$featured) {
            $featured = $blueChips[0];
        }

        $name = $featured['first_name'] . ' ' . $featured['last_name'];
        $pos = $featured['position'];
        $college = $featured['college'] ?? 'Unknown';
        $potential = $featured['potential'] ?? 'average';
        $combineScore = (int) ($featured['combine_score'] ?? 50);
        $combineGrade = $featured['combine_grade'] ?? $this->deriveCombineGrade($combineScore);
        $stockRating = (int) ($featured['stock_rating'] ?? 50);
        $stockTrend = $featured['stock_trend'] ?? 'steady';
        $posAnalysis = self::POSITION_ANALYSIS[$pos] ?? null;

        // Check for previous coverage — query headline AND body for progressive storylines
        $stmt = $this->db->prepare(
            "SELECT headline, body FROM articles WHERE author_name = ? AND player_id = ? ORDER BY published_at DESC LIMIT 1"
        );
        $stmt->execute([$scout['name'], (int) $featured['id']]);
        $previousArticle = $stmt->fetch(\PDO::FETCH_ASSOC);

        // Count how many articles have been written about this prospect by this author
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM articles WHERE author_name = ? AND player_id = ?"
        );
        $stmt->execute([$scout['name'], (int) $featured['id']]);
        $articleCount = (int) $stmt->fetchColumn();

        // Determine the phase: 0 = introduction, 1 = film breakdown, 2 = combine review, 3+ = final assessment
        $phase = min($articleCount, 3);

        // Build the article based on phase
        if ($phase === 0) {
            // INTRODUCTION — First time profiling this prospect
            $openerTemplates = self::NINA_OPENERS['spotlight'];
            $opener = str_replace(['{name}', '{college}'], [$name, $college], $openerTemplates[array_rand($openerTemplates)]);
            $body = $opener . "\n\n";

            $body .= "The {$college} {$pos} has been climbing draft boards all offseason, and after spending the last week immersed in his film, talking to people who know him, and studying every available piece of data, I understand why. ";
            $body .= "{$name} is the kind of prospect who divides rooms — not because the talent is in question, but because the upside is so tantalizing that it forces you to ask: how good can he be?\n\n";

            // Physical profile
            $body .= "Let's start with what you can measure. ";
            if ($combineScore >= 85) {
                $body .= "A combine score of {$combineScore} and a grade of {$combineGrade} — those are elite numbers. The kind that make scouts text their GMs at two in the morning. ";
                $body .= "The athleticism is obvious every time he steps on the field, but the testing confirmed what the eyes already knew.\n\n";
            } elseif ($combineScore >= 70) {
                $body .= "A combine score of {$combineScore} (grade: {$combineGrade}) puts him in solid territory — not a freak athlete, but well above average. ";
                $body .= "The beauty of {$name}'s game is that it translates beyond numbers. He plays faster than he tests.\n\n";
            } else {
                $body .= "His combine score of {$combineScore} (grade: {$combineGrade}) won't win any awards, and {$name} is the first to acknowledge it. ";
                $body .= "'I don't play the combine — I play football,' he reportedly told one evaluator during the process. And the tape backs him up.\n\n";
            }

            // Strengths
            $scoutLine = $this->getScoutingLine($pos, $potential);
            $body .= "On film, the story writes itself. {$scoutLine} ";
            if ($posAnalysis && !empty($posAnalysis['strengths'])) {
                $body .= $posAnalysis['strengths'][array_rand($posAnalysis['strengths'])];
            }
            $body .= "\n\n";

            // Closing tease
            $body .= "This is just the first chapter of the {$name} story. In the coming weeks, I'll be breaking down the film in detail, tracking his combine performance, and talking to the people closest to him. ";
            $body .= "Keep his name in your mind. You're going to be hearing it a lot on draft night.";

        } elseif ($phase === 1) {
            // FILM BREAKDOWN — Second article
            $body = "When I first profiled {$name} three weeks ago, I was struck by his physical tools and his potential. ";
            $body .= "This week, I went deeper. I sat down with the film — all of it — and charted every meaningful snap from his final college season. ";
            $body .= "Here's what the tape told me.\n\n";

            // Position-specific film language
            $body .= "THE TAPE\n\n";
            if ($posAnalysis && !empty($posAnalysis['strengths'])) {
                $strengthPool = $posAnalysis['strengths'];
                shuffle($strengthPool);
                $body .= $strengthPool[0] . " ";
                if (count($strengthPool) > 1) {
                    $body .= $strengthPool[1] . " ";
                }
                $body .= "When you watch the film on a loop, these traits jump off the screen. It's not one or two plays — it's a pattern of dominance.\n\n";
            }

            // Position-specific football language
            $posSpecific = $this->getPositionSpecificFilmLanguage($pos, $name);
            $body .= $posSpecific . "\n\n";

            // Weaknesses from film
            $body .= "THE CONCERNS\n\n";
            if ($posAnalysis && !empty($posAnalysis['weaknesses'])) {
                $weaknessPool = $posAnalysis['weaknesses'];
                shuffle($weaknessPool);
                $body .= "No prospect is flawless on film, and {$name} has areas to develop. {$weaknessPool[0]} ";
                if (count($weaknessPool) > 1) {
                    $body .= $weaknessPool[1] . " ";
                }
                $body .= "These are correctable issues, but they're on tape, and NFL coaching staffs will see them.\n\n";
            }

            // Stock trend
            $body .= match ($stockTrend) {
                'rising' => "His stock continues to rise. Multiple league sources tell me he's moved up boards across the league, and the film study confirms it.\n\n",
                'falling' => "His stock has cooled recently, and honestly, the film helps explain why. The inconsistency shows up in the second half of the season. But the talent is still there.\n\n",
                default => "His stock has been steady throughout the process, and the film validates that position. Teams know what they're getting — and most of them like it.\n\n",
            };

            $body .= "The film doesn't lie. And {$name}'s film tells the story of a player who belongs in the first round. The question isn't if — it's when.";

        } elseif ($phase === 2) {
            // COMBINE REVIEW — Third article
            $body = "We've been tracking {$name}'s journey since the beginning of the draft process. First, we profiled who he is. Then, we broke down the film. ";
            $body .= "This week, we got the combine results — and they added another chapter to the story.\n\n";

            if ($combineScore >= 85) {
                $body .= "THE COMBINE: CONFIRMATION\n\n";
                $body .= "A combine score of {$combineScore}. A grade of {$combineGrade}. These numbers confirmed what every scout in attendance already suspected: ";
                $body .= "{$name} is the real deal. The athleticism that shows up on film translated to the biggest stage in the evaluation process.\n\n";
                $quote = self::SCOUT_QUOTES['elite_praise'][array_rand(self::SCOUT_QUOTES['elite_praise'])];
                $body .= "One scout I spoke with afterward summed it up: \"{$quote}\"\n\n";
            } elseif ($combineScore >= 70) {
                $body .= "THE COMBINE: SOLID\n\n";
                $body .= "A combine score of {$combineScore} (grade: {$combineGrade}) won't send anyone running to the podium, but it won't scare anyone away either. ";
                $body .= "{$name} tested as expected — athletic enough to play at the highest level, with the kind of movement skills that translate to Sunday.\n\n";
                $body .= "What stood out wasn't a single number — it was the way he carried himself. Confident. Focused. Ready. ";
                $body .= "The interviews, I'm told, went even better than the testing.\n\n";
            } else {
                $body .= "THE COMBINE: COMPLICATED\n\n";
                $body .= "Let's address the number first: a combine score of {$combineScore} (grade: {$combineGrade}). That's below where most teams wanted to see him. ";
                $body .= "And yes, there were whispers in Indianapolis. But here's what I keep coming back to: the tape is still the tape. ";
                $body .= "Some of the best players in league history were average combine testers. The question is whether front offices have the courage to trust the film over the stopwatch.\n\n";
            }

            // Character flag in context of combine
            if (!empty($featured['character_flag'])) {
                $flagText = ucwords(str_replace('_', ' ', $featured['character_flag']));
                $body .= "THE ELEPHANT IN THE ROOM\n\n";
                $body .= "The {$flagText} situation came up in every formal interview {$name} had at the combine. Every single one. ";
                $body .= "By all accounts, he handled the questions with maturity and directness. Whether that's enough to ease teams' concerns remains to be seen. ";
                $quote = self::SCOUT_QUOTES['concern'][array_rand(self::SCOUT_QUOTES['concern'])];
                $body .= "As one executive told me: \"{$quote}\"\n\n";
            }

            $body .= "We're in the final stretch now. Pro days are next, then private workouts, then the draft itself. ";
            $body .= "{$name}'s story has one more chapter to write — and it starts when the commissioner steps to the podium.";

        } else {
            // FINAL ASSESSMENT — Fourth+ article
            $body = "This is the last time I'll profile {$name} before the draft. We've been on this journey together — from the initial introduction, through the film study, through the combine. ";
            $body .= "Now it's time for the final verdict.\n\n";

            $body .= "FINAL GRADE: {$combineGrade}\n\n";

            // Complete picture
            $scoutLine = $this->getScoutingLine($pos, $potential);
            $body .= "{$scoutLine} ";
            if ($posAnalysis && !empty($posAnalysis['strengths'])) {
                $body .= $posAnalysis['strengths'][array_rand($posAnalysis['strengths'])] . " ";
            }
            $body .= "The strengths haven't changed since I first saw the film — if anything, they've become more pronounced with each evaluation.\n\n";

            if ($posAnalysis && !empty($posAnalysis['weaknesses'])) {
                $body .= "The areas for growth remain: " . $posAnalysis['weaknesses'][array_rand($posAnalysis['weaknesses'])] . " ";
                $body .= "But at some point, you have to bet on the talent. And the talent here is considerable.\n\n";
            }

            // NFL comparison
            if ($posAnalysis && !empty($posAnalysis['comparisons'])) {
                $comp = $posAnalysis['comparisons'][array_rand($posAnalysis['comparisons'])];
                $body .= "THE COMPARISON\n\n";
                $body .= "I've been asked all offseason who {$name} reminds me of. The answer, imperfect as all comparisons are, is {$comp}. ";
                $body .= "Not a clone — no two players are identical — but the archetype, the skill set, the way he impacts the game. ";
                $body .= "If {$name} reaches his ceiling, that's the neighborhood we're talking about.\n\n";
            }

            // Where he'll land
            if ($potential === 'elite') {
                $body .= "PREDICTION: Top 3 pick. Possibly first overall. ";
                $body .= "This is the kind of prospect you don't overthink. You take him, hand him the keys, and build around him.\n\n";
            } elseif ($potential === 'high') {
                $body .= "PREDICTION: First-round selection. The range is somewhere between picks 5 and 15, depending on team needs and pre-draft trades. ";
                $body .= "Wherever he lands, that franchise is getting a player who will start from day one.\n\n";
            } else {
                $body .= "PREDICTION: Day 1 or early Day 2 selection. He could sneak into the late first round if a team falls in love, ";
                $body .= "or he could be the first pick on Day 2 — which might actually be the best outcome for everyone.\n\n";
            }

            $body .= "This has been one of the most compelling prospect stories I've covered. Whatever happens on draft night, {$name}'s journey has been worth following. ";
            $body .= "Thank you for coming along for the ride. Now let's see where the story ends — or rather, where the next one begins.";
        }

        // Varied headline
        $headlineOptions = [
            "Prospect Spotlight: {$name}, {$pos}, {$college}",
            "Inside the Tape: A Deep Dive on {$name}",
            "Draft Diary: {$name}'s Path to the Podium",
            "The {$name} Report: Week {$week} Update",
            "Tracking {$name}: What I've Learned This Week",
        ];

        $this->insertArticle(
            $leagueId, $seasonId, $week, 'feature',
            $headlineOptions[min($phase, count($headlineOptions) - 1)],
            $body, $scout['name'], $scout['style'], null, (int) $featured['id'], $now
        );
    }

    private function generateCombineReport(int $leagueId, int $seasonId, int $week, int $year, int $draftClassId, array $scout, string $now): void
    {
        // Get ALL combine performers grouped by position
        $stmt = $this->db->prepare(
            "SELECT * FROM draft_prospects
             WHERE draft_class_id = ? AND is_drafted = 0 AND combine_score IS NOT NULL
             ORDER BY combine_score DESC"
        );
        $stmt->execute([$draftClassId]);
        $allProspects = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($allProspects)) {
            return;
        }

        // Group by position category
        $positionGroups = [
            'QUARTERBACKS' => ['QB'],
            'PASS RUSHERS' => ['DE'],
            'DEFENSIVE TACKLES' => ['DT'],
            'LINEBACKERS' => ['LB'],
            'CORNERBACKS' => ['CB'],
            'SAFETIES' => ['S'],
            'WIDE RECEIVERS' => ['WR'],
            'TIGHT ENDS' => ['TE'],
            'RUNNING BACKS' => ['RB'],
            'OFFENSIVE TACKLES' => ['OT'],
            'INTERIOR OFFENSIVE LINE' => ['OG', 'C'],
        ];

        $grouped = [];
        foreach ($allProspects as $p) {
            foreach ($positionGroups as $groupName => $positions) {
                if (in_array($p['position'], $positions)) {
                    $grouped[$groupName][] = $p;
                    break;
                }
            }
        }

        // Opener
        $opener = self::JAKE_OPENERS['combine'][array_rand(self::JAKE_OPENERS['combine'])];
        $body = $opener . "\n\n";
        $body .= "Below is my position-by-position breakdown: the winners, the disappointments, and what it all means for draft night.\n\n";

        // Measurables language by position
        $measurablesLanguage = [
            'QB' => ['arm velocity on the throwing drills', 'footwork in the pocket simulation', 'accuracy on the deep ball'],
            'DE' => ['explosive first step off the line', 'bend and flexibility through the bag drills', 'closing speed in the pass-rush simulation'],
            'DT' => ['short-area quickness for a man his size', 'power at the point of attack', 'lateral agility that belies his frame'],
            'LB' => ['forty-time that is elite for a linebacker his size', 'change-of-direction ability in the three-cone drill', 'coverage skills in the position drills'],
            'CB' => ['forty-time that put the stadium on notice', 'fluidity in his hip transitions during drills', 'ball skills during the interception drill'],
            'S' => ['range demonstrated in the deep-ball drill', 'versatility in positional workouts', 'tackling technique in the position drills'],
            'WR' => ['forty-time that turned heads across the stadium', 'route-running precision in positional drills', 'hands and catch radius during the gauntlet'],
            'TE' => ['combination of size and speed that is rare at the position', 'blocking technique and receiving skills in dual drills', 'forty-time that is elite for a tight end'],
            'RB' => ['burst through the hole in the shuttle drill', 'vision and patience demonstrated in the position workout', 'pass-catching ability during the receiving drills'],
            'OT' => ['footwork and kick-slide in the pass-protection drill', 'length and anchor demonstrated at the bench press', 'movement skills that are exceptional for a man his size'],
            'OG' => ['power at the bench press', 'lateral agility in the position workout', 'short-area quickness and hand placement'],
            'C' => ['intelligence and communication during the position workout', 'athleticism for an interior lineman', 'snap-and-move quickness'],
        ];

        foreach ($positionGroups as $groupName => $positions) {
            if (empty($grouped[$groupName])) continue;

            $groupProspects = $grouped[$groupName];
            $body .= "--- {$groupName} ---\n\n";

            // Winner (highest combine score in group)
            $winner = $groupProspects[0];
            $winnerName = $winner['first_name'] . ' ' . $winner['last_name'];
            $winnerScore = (int) $winner['combine_score'];
            $winnerGrade = $winner['combine_grade'] ?? $this->deriveCombineGrade($winnerScore);
            $winnerPos = $winner['position'];

            $body .= "WINNER: {$winnerName}, {$winnerPos} ({$winner['college']}) — Grade: {$winnerGrade} (Score: {$winnerScore})\n";

            // Position-specific measurables language
            $measurables = $measurablesLanguage[$winnerPos] ?? $measurablesLanguage[$positions[0]] ?? ['athletic testing across the board'];
            $measurable = $measurables[array_rand($measurables)];

            if ($winnerScore >= 90) {
                $body .= "His {$measurable} is elite — we're talking top-percentile numbers for the position. ";
                $body .= "This is the kind of athletic testing that moves a player into the top ten conversation. ";
                $quote = self::SCOUT_QUOTES['riser'][array_rand(self::SCOUT_QUOTES['riser'])];
                $body .= "{$quote}\n\n";
            } elseif ($winnerScore >= 80) {
                $body .= "His {$measurable} confirmed what the film already showed. ";
                $body .= "No surprises here — just a well-rounded athlete who tested exactly where evaluators expected, which is to say, very well. ";
                $body .= "He solidified his first-round grade.\n\n";
            } else {
                $body .= "He won the group, though it wasn't a dominant performance. His {$measurable} was the highlight. ";
                $body .= "Sometimes winning a position group at the combine is about consistency, not fireworks. That's what we got here.\n\n";
            }

            // Disappointment (lowest combine score in group, if meaningfully low)
            $disappointment = end($groupProspects);
            if (count($groupProspects) > 1 && (int) $disappointment['combine_score'] < $winnerScore - 10) {
                $dName = $disappointment['first_name'] . ' ' . $disappointment['last_name'];
                $dScore = (int) $disappointment['combine_score'];
                $dGrade = $disappointment['combine_grade'] ?? $this->deriveCombineGrade($dScore);
                $dPos = $disappointment['position'];

                $body .= "DISAPPOINTMENT: {$dName}, {$dPos} ({$disappointment['college']}) — Grade: {$dGrade} (Score: {$dScore})\n";

                if ($dScore < 50) {
                    $body .= "This was a rough week for {$dName}. The testing numbers fell well below expectations, and you could see the concern on scouts' faces in the stands. ";
                    $body .= "The tape tells a better story than the stopwatch, but front offices notice when a prospect underperforms on the biggest stage of the evaluation process. ";
                    $body .= "His stock has taken a real hit.\n\n";
                } else {
                    $body .= "Not a disaster, but not what anyone wanted to see. {$dName} tested below his projection, and in a combine where every tenth of a second matters, ";
                    $body .= "that gap between expectation and reality can cost a player millions. The film will be his saving grace — if teams trust it.\n\n";
                }
            }

            // If there's a notable second prospect in the group
            if (count($groupProspects) >= 3) {
                $sleeper = $groupProspects[1];
                $sleeperName = $sleeper['first_name'] . ' ' . $sleeper['last_name'];
                $sleeperScore = (int) $sleeper['combine_score'];
                if ($sleeperScore >= 75) {
                    $body .= "Also worth noting: {$sleeperName} ({$sleeper['college']}) posted a {$sleeperScore} combine score and is quietly moving up boards.\n\n";
                }
            }
        }

        // Closing
        $body .= "--- THE BOTTOM LINE ---\n\n";
        $body .= "The combine is one data point in a months-long evaluation process. It can confirm what you've seen on tape, and it can raise new questions. ";
        $body .= "But it should never be the only thing you hang your draft pick on. The best franchises use the combine as a piece of the puzzle, not the whole picture. ";
        $body .= "That said — when a prospect tests in the 95th percentile at his position, you sit up and take notice. And this combine produced several of those moments.\n\n";
        $body .= "Boards are shifting. My next mock draft will reflect these results. Stay tuned.";

        $headlines = [
            "Combine Report: Winners, Losers, and Everything in Between",
            "Indianapolis Breakdown: A Position-by-Position Combine Analysis",
            "The Combine Verdict: Who Helped and Hurt Their Draft Stock",
            "Combine Takeaways: The Testing That Will Reshape the First Round",
        ];

        $this->insertArticle(
            $leagueId, $seasonId, $week, 'draft_coverage',
            $headlines[array_rand($headlines)],
            $body, $scout['name'], $scout['style'], null, null, $now
        );
    }

    private function generateStockWatch(int $leagueId, int $seasonId, int $week, int $year, int $draftClassId, array $scout, string $now): void
    {
        // Risers — top 3
        $stmt = $this->db->prepare(
            "SELECT * FROM draft_prospects
             WHERE draft_class_id = ? AND is_drafted = 0 AND stock_trend = 'rising'
             ORDER BY COALESCE(stock_rating, 50) DESC
             LIMIT 3"
        );
        $stmt->execute([$draftClassId]);
        $risers = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Fallers — top 3
        $stmt = $this->db->prepare(
            "SELECT * FROM draft_prospects
             WHERE draft_class_id = ? AND is_drafted = 0 AND stock_trend = 'falling'
             ORDER BY COALESCE(stock_rating, 50) ASC
             LIMIT 3"
        );
        $stmt->execute([$draftClassId]);
        $fallers = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($risers) && empty($fallers)) {
            return;
        }

        // Check for previous stock watch to reference
        $stmt = $this->db->prepare(
            "SELECT body FROM articles WHERE league_id = ? AND author_name = ? AND headline LIKE '%Stock Watch%'
             ORDER BY published_at DESC LIMIT 1"
        );
        $stmt->execute([$leagueId, $scout['name']]);
        $prevRow = $stmt->fetch();
        $hasPrevious = (bool) $prevRow;

        // Varied openers
        $freshOpeners = [
            "The board is alive. Every week brings new information — pro days, private workouts, interviews — and the rankings shift accordingly.",
            "Draft boards are living documents. They change with every workout, every phone call, every piece of new information.",
            "This is my favorite part of the draft process — watching the movement. Nothing stays the same in this business.",
            "If your draft board looks the same as it did two weeks ago, you're not doing your job. Here's what's changed.",
        ];
        $returnOpeners = [
            "The draft board never stops moving. Since my last stock watch, we've seen some significant shifts.",
            "Welcome back to the stock watch. The board has moved again — and some of these shifts are dramatic.",
            "Another week, another round of movement. The pre-draft process is relentless, and so is the information flow.",
            "Since I last checked in on the movers and shakers, the landscape has shifted in some surprising ways.",
        ];

        $body = $hasPrevious
            ? $returnOpeners[array_rand($returnOpeners)] . " Here's who's trending.\n\n"
            : $freshOpeners[array_rand($freshOpeners)] . " Here's this week's movers.\n\n";

        // RISERS — each gets a full paragraph
        if (!empty($risers)) {
            $body .= "--- RISERS ---\n\n";
            foreach ($risers as $i => $p) {
                $name = $p['first_name'] . ' ' . $p['last_name'];
                $pos = $p['position'];
                $college = $p['college'] ?? 'Unknown';
                $combineScore = (int) ($p['combine_score'] ?? 50);
                $stockRating = (int) ($p['stock_rating'] ?? 50);
                $potential = $p['potential'] ?? 'average';
                $combineGrade = $p['combine_grade'] ?? $this->deriveCombineGrade($combineScore);
                $posAnalysis = self::POSITION_ANALYSIS[$pos] ?? null;

                $body .= "UP {$name}, {$pos} ({$college}) — Stock Rating: {$stockRating}\n\n";

                // Why they're rising — build a full paragraph with specific reasons
                $riserReasons = [];
                if ($combineScore >= 80) {
                    $riserReasons[] = "His combine testing (score: {$combineScore}, grade: {$combineGrade}) opened eyes across the league and validated the athletic profile teams were hoping to see.";
                } elseif ($combineScore >= 65) {
                    $riserReasons[] = "His pro day workout answered lingering questions about his athleticism, and the private workouts since have only reinforced the positive impression.";
                }

                if ($potential === 'elite' || $potential === 'high') {
                    $riserReasons[] = "The upside is tantalizing. Evaluators see a player who hasn't come close to reaching his ceiling, and in this league, you draft potential as much as production.";
                }

                if ($posAnalysis && !empty($posAnalysis['strengths'])) {
                    $riserReasons[] = "On film, the improvement from his junior to senior season is dramatic. " . $posAnalysis['strengths'][array_rand($posAnalysis['strengths'])];
                }

                // Add position scarcity angle
                $riserReasons[] = "Position scarcity is a factor. There aren't many {$pos} prospects in this class with his combination of traits, and teams that need the position are taking notice.";

                // Use 2-3 reasons
                shuffle($riserReasons);
                $body .= implode(' ', array_slice($riserReasons, 0, min(3, count($riserReasons))));

                // Scout quote
                $quote = self::SCOUT_QUOTES['riser'][array_rand(self::SCOUT_QUOTES['riser'])];
                $body .= " {$quote}\n\n";
            }
        }

        // FALLERS — each gets a full paragraph
        if (!empty($fallers)) {
            $body .= "--- FALLERS ---\n\n";
            foreach ($fallers as $i => $p) {
                $name = $p['first_name'] . ' ' . $p['last_name'];
                $pos = $p['position'];
                $college = $p['college'] ?? 'Unknown';
                $combineScore = (int) ($p['combine_score'] ?? 50);
                $stockRating = (int) ($p['stock_rating'] ?? 50);
                $potential = $p['potential'] ?? 'average';
                $hasFlag = !empty($p['character_flag']);
                $posAnalysis = self::POSITION_ANALYSIS[$pos] ?? null;

                $body .= "DOWN {$name}, {$pos} ({$college}) — Stock Rating: {$stockRating}\n\n";

                // Why they're falling — build a full paragraph with specific reasons
                if ($hasFlag) {
                    $flagText = ucwords(str_replace('_', ' ', $p['character_flag']));
                    $body .= "The {$flagText} concerns have cast a long shadow over what was once a promising evaluation. ";
                    $body .= "Multiple teams have told me they've either moved him down their board or removed him entirely. ";
                    $quote = self::SCOUT_QUOTES['concern'][array_rand(self::SCOUT_QUOTES['concern'])];
                    $body .= "One front office source was blunt: \"{$quote}\" ";
                } else {
                    $fallerReasons = [
                        "The combine didn't help. A score of {$combineScore} raised questions about his athleticism that the film alone couldn't answer.",
                        "The interview process, I'm told, did not go well. Multiple teams have expressed concern about his football IQ and preparation.",
                        "His senior tape wasn't as dominant as his junior film, and evaluators are asking why. Regression — even perceived regression — is a red flag in this process.",
                        "Private workouts have been underwhelming. When teams bring you in for a one-on-one evaluation and you don't perform, word travels fast.",
                        "The early-season buzz has evaporated. What looked like a surefire first-rounder in October now feels like a Day 2 pick, and the fall might not be over.",
                    ];
                    $body .= $fallerReasons[array_rand($fallerReasons)] . " ";
                }

                if ($posAnalysis && !empty($posAnalysis['weaknesses'])) {
                    $body .= $posAnalysis['weaknesses'][array_rand($posAnalysis['weaknesses'])] . " ";
                }

                $quote = self::SCOUT_QUOTES['faller'][array_rand(self::SCOUT_QUOTES['faller'])];
                $body .= "{$quote}\n\n";
            }
        }

        // Closing
        $closingOptions = [
            "Draft season is a marathon, not a sprint. These stocks will keep moving, and the final picture won't be clear until the commissioner steps to the podium. But right now, the trends are unmistakable.",
            "The board never stops moving, and neither do I. I'll be tracking these shifts all the way to draft night. The players who handle this process well will hear their names called early. The ones who don't will learn a hard lesson about the NFL evaluation machine.",
            "Every riser and faller on this list has a story. Some of those stories will have happy endings. Others won't. That's the brutal honesty of the draft process — and it's what makes it the most fascinating event in professional sports.",
        ];
        $body .= $closingOptions[array_rand($closingOptions)];

        $headlines = [
            "Stock Watch: Risers and Fallers in the {$year} Draft",
            "Draft Stock Report: Who's Up, Who's Down, and Why",
            "The Stock Watch: This Week's Biggest Draft Board Movers",
            "Rising and Falling: The {$year} Draft Stock Report",
        ];

        $this->insertArticle(
            $leagueId, $seasonId, $week, 'draft_coverage',
            $headlines[array_rand($headlines)],
            $body, $scout['name'], $scout['style'], null, null, $now
        );
    }

    private function generateCharacterConcerns(int $leagueId, int $seasonId, int $week, int $year, int $draftClassId, array $scout, string $now): void
    {
        // Find ALL prospects with character flags — no limit
        $stmt = $this->db->prepare(
            "SELECT * FROM draft_prospects
             WHERE draft_class_id = ? AND is_drafted = 0
               AND character_flag IS NOT NULL AND character_flag != ''
             ORDER BY actual_overall DESC"
        );
        $stmt->execute([$draftClassId]);
        $flagged = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($flagged)) {
            // No character concerns — generate a "clean class" article
            $body = "I've done my homework. Talked to coaches. Talked to teammates. Talked to high school mentors, college professors, apartment neighbors — ";
            $body .= "anyone who would answer the phone and give me an honest assessment. And here's the verdict: this is a remarkably clean class.\n\n";
            $body .= "That doesn't mean every prospect in the {$year} draft is a choir boy. There are always maturity questions, coaching fit concerns, ";
            $body .= "and the inevitable 'Does he love football?' debates that happen in every war room. But this year, there are no bombshells. ";
            $body .= "No prospects with serious off-field incidents that teams need to navigate. No one who is going to fall three rounds because of a phone call from an agent.\n\n";
            $body .= "As one veteran scout told me: 'This is a class where you can draft with your eyes on the tape and not worry about what's in the file.' ";
            $body .= "That's a refreshing change. And it should give every franchise the confidence to draft the best player available without hesitation.\n\n";
            $body .= "There are always concerns in the evaluation process — scheme dependency, contact history, how a player handles adversity — ";
            $body .= "but nothing that would make me take a player off my board entirely. Draft with confidence. This class earned it.";

            $this->insertArticle(
                $leagueId, $seasonId, $week, 'draft_coverage',
                "Character Check: A Clean {$year} Draft Class",
                $body, $scout['name'], $scout['style'], null, null, $now
            );
            return;
        }

        // Group by severity: elite/high potential flagged players are bigger stories
        $highSeverity = [];
        $moderateSeverity = [];
        foreach ($flagged as $p) {
            $potential = $p['potential'] ?? 'average';
            if ($potential === 'elite' || $potential === 'high' || ((int) ($p['actual_overall'] ?? 0)) >= 74) {
                $highSeverity[] = $p;
            } else {
                $moderateSeverity[] = $p;
            }
        }

        // Opening
        $openers = [
            "We have to talk about the things that make front offices uncomfortable. This is the part of the draft coverage that nobody wants to write — and everybody wants to read.",
            "There's a folder on every GM's desk that never gets discussed publicly. It's the character file. The background checks, the interviews, the phone calls at midnight. This is what's in it.",
            "Talent evaluation isn't just about the 40-yard dash and the bench press. It's about who a player is when the cameras are off, when the stadium lights go dark, and when nobody is watching.",
            "Every draft class has its complications. This year is no different. The talent is real — but so are the questions.",
        ];
        $body = $openers[array_rand($openers)] . "\n\n";

        // High-severity cases — full analysis
        if (!empty($highSeverity)) {
            $body .= "--- HIGH-PROFILE CONCERNS ---\n\n";
            foreach ($highSeverity as $p) {
                $name = $p['first_name'] . ' ' . $p['last_name'];
                $pos = $p['position'];
                $college = $p['college'] ?? 'Unknown';
                $potential = $p['potential'] ?? 'average';
                $flagText = ucwords(str_replace('_', ' ', $p['character_flag']));
                $combineGrade = $p['combine_grade'] ?? $this->deriveCombineGrade((int) ($p['combine_score'] ?? 50));

                // Check for previous coverage
                $prevArticle = $this->getPreviousArticleAboutProspect($leagueId, $scout['name'], (int) $p['id']);

                if ($prevArticle) {
                    $body .= "We first reported on {$name}'s situation weeks ago. Since then, the story has only gotten more complicated. ";
                    $body .= "New information has surfaced, teams have adjusted their boards, and the conversation around the {$college} {$pos} has taken another turn.\n\n";
                } else {
                    $body .= "{$name}, {$pos}, {$college}\n\n";
                    $body .= "On pure talent, {$name} is one of the best players in this draft class. ";
                    $body .= "A {$combineGrade} combine grade, " . ($potential === 'elite' ? "generational" : "first-round") . " ability, ";
                    $body .= "and the kind of film that makes scouts lose sleep over the possibility of missing on him.\n\n";
                }

                $body .= "The concern: {$flagText}.\n\n";

                // Detailed analysis of how teams are reacting
                $talentLevel = $potential === 'elite' ? 'generational' : ($potential === 'high' ? 'first-round caliber' : 'legitimate NFL');
                $body .= "Here's what I know from talking to front offices across the league: teams are genuinely split. ";
                $body .= "We're talking about a player with {$talentLevel} ability, and the {$flagText} concerns are creating a fascinating case study in risk management. ";

                // Two opposing scout quotes
                $concernQuote = self::SCOUT_QUOTES['concern'][array_rand(self::SCOUT_QUOTES['concern'])];
                $body .= "One personnel director told me, on condition of anonymity: \"{$concernQuote}\" ";

                // Will it cause a slide?
                if ($potential === 'elite') {
                    $body .= "But here's the reality: talent this rare almost never slides past the top five. ";
                    $body .= "History tells us that when a generational prospect has character questions, someone in the top ten talks themselves into it. ";
                    $body .= "The talent is simply too good to pass up — or at least, that's what the franchise that drafts him will tell themselves.\n\n";
                } elseif ($potential === 'high') {
                    $body .= "The real question is whether the {$flagText} concerns push him out of the first round entirely. ";
                    $body .= "I've heard from multiple sources that at least three teams have removed him from their first-round consideration. ";
                    $body .= "If he slides to the second round, the team that picks him will be getting first-round talent at a discount — ";
                    $body .= "but they'll also be accepting the risk that comes with it.\n\n";
                } else {
                    $body .= "For a player who was already looking at a Day 2 selection, these concerns could push him into Day 3 territory. ";
                    $body .= "At that point, the risk-reward calculation changes dramatically. A fourth-round pick on a talented but complicated player is a very different bet than a first-rounder.\n\n";
                }
            }
        }

        // Moderate-severity cases — shorter but still substantive
        if (!empty($moderateSeverity)) {
            $body .= "--- ALSO MONITORING ---\n\n";
            foreach ($moderateSeverity as $p) {
                $name = $p['first_name'] . ' ' . $p['last_name'];
                $pos = $p['position'];
                $college = $p['college'] ?? 'Unknown';
                $flagText = ucwords(str_replace('_', ' ', $p['character_flag']));

                $body .= "{$name}, {$pos}, {$college} — {$flagText}\n";
                $body .= "The situation is less dramatic here, but it's still on teams' radar. ";

                $moderateReactions = [
                    "Multiple teams have told me it's not a deal-breaker, but it might cost him a round. In this business, a round is millions of dollars.",
                    "This is the kind of flag that drops you ten spots, not ten rounds. But for a player in his draft range, ten spots is the difference between being a starter and being a project.",
                    "Sources say the concern is real but manageable. The right organization with the right culture can handle it. The wrong one can't.",
                    "It's not a red flag so much as a yellow one. Teams are proceeding with caution, but they haven't crossed him off their boards.",
                ];
                $body .= $moderateReactions[array_rand($moderateReactions)] . "\n\n";
            }
        }

        // Closing
        $body .= "--- THE BIGGER PICTURE ---\n\n";
        $body .= "History shows us that character concerns sometimes fade, and sometimes they define a career. ";
        $body .= "For every player who was flagged for character and became a Pro Bowler, there's another who flamed out in two years. ";
        $body .= "The franchises that do the best job vetting these situations — not just the incidents themselves, but the context, the support systems, the individual — ";
        $body .= "are the ones that win in April and in January.\n\n";
        $body .= "My job isn't to judge these young men. It's to report what I'm hearing, provide context, and let the franchises make their own calls. ";
        $body .= "The draft is about calculated risk. And these are the calculations that keep general managers up at night.";

        $headlines = [
            "Behind the Curtain: Character Questions in the {$year} Draft",
            "The Character File: What Teams Aren't Saying Publicly About This Draft Class",
            "Red Flags and Tough Calls: The {$year} Draft's Complicated Prospects",
            "Character Watch: The Prospects Keeping GMs Up at Night",
        ];

        $this->insertArticle(
            $leagueId, $seasonId, $week, 'draft_coverage',
            $headlines[array_rand($headlines)],
            $body, $scout['name'], $scout['style'], null, null, $now
        );
    }

    // ================================================================
    //  Draft Day Coverage
    // ================================================================

    private function generateDraftDayDramaArticle(int $leagueId, int $seasonId, int $year, array $picks, string $now): void
    {
        $scout = $this->pickScout('nina_charles');

        // Identify the drama: surprises, slides, reaches
        $surprises = [];
        $slides = [];

        foreach ($picks as $pick) {
            $round = (int) ($pick['round'] ?? 1);
            $overall = (int) ($pick['overall_rating'] ?? 70);
            $pos = $pick['position'] ?? '';
            $name = trim(($pick['first_name'] ?? '') . ' ' . ($pick['last_name'] ?? ''));

            if ($round === 1 && $overall < 65) {
                $surprises[] = ['name' => $name, 'pos' => $pos, 'team' => ($pick['team_city'] ?? '') . ' ' . ($pick['team_name'] ?? ''), 'type' => 'reach', 'round' => $round, 'overall' => $overall];
            }
            if ($round >= 2 && $overall >= 75) {
                $slides[] = ['name' => $name, 'pos' => $pos, 'team' => ($pick['team_city'] ?? '') . ' ' . ($pick['team_name'] ?? ''), 'type' => 'slide', 'round' => $round, 'overall' => $overall];
            }
        }

        // Find ALL prospects Nina was following (all features she wrote)
        $followedStories = [];
        $stmt = $this->db->prepare(
            "SELECT DISTINCT a.player_id, a.headline FROM articles a
             WHERE a.league_id = ? AND a.author_name = ? AND a.player_id IS NOT NULL AND a.type = 'feature'
             ORDER BY a.published_at DESC"
        );
        $stmt->execute([$leagueId, $scout['name']]);
        $followedRows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($followedRows as $followedRow) {
            foreach ($picks as $pick) {
                if (isset($pick['player_id']) && (int) $pick['player_id'] === (int) $followedRow['player_id']) {
                    $followedStories[] = ['pick' => $pick, 'headline' => $followedRow['headline']];
                    break;
                }
            }
        }

        $firstPick = $picks[0] ?? null;
        $totalPicks = count($picks);

        // Paragraph 1: The scene — varied opener
        $openers = self::NINA_OPENERS['draft_day'];
        $body = $openers[array_rand($openers)] . "\n\n";

        // Paragraph 2: First overall pick — the moment
        if ($firstPick) {
            $fpName = trim(($firstPick['first_name'] ?? '') . ' ' . ($firstPick['last_name'] ?? ''));
            $fpTeam = ($firstPick['team_city'] ?? '') . ' ' . ($firstPick['team_name'] ?? '');
            $fpPos = $firstPick['position'] ?? '';
            $fpOverall = (int) ($firstPick['overall_rating'] ?? 70);

            $body .= "The night began the way everyone expected — and yet somehow, it still felt electric. With the first overall pick, the {$fpTeam} selected {$fpName}, {$fpPos}. ";

            if ($fpOverall >= 75) {
                $body .= "The room erupted. The green room cameras found {$fpName}'s face — the tears, the embrace with his family, the phone call that changed everything. ";
                $body .= "A franchise found its cornerstone. A young man's dream became reality. This is why we love the draft.\n\n";
            } elseif ($fpOverall >= 68) {
                $body .= "It was the expected pick, but that doesn't make the moment any less significant. {$fpName} hugged his mother, pulled on the team cap, and walked to the podium as the newest face of the {$fpTeam}. ";
                $body .= "The franchise has its guy. Now the work begins.\n\n";
            } else {
                $body .= "It was a pick that surprised some — {$fpName} wasn't on everyone's radar as the top selection. But the {$fpTeam} saw something. ";
                $body .= "Whether this pick looks brilliant or baffling in three years, tonight it belongs to {$fpName} and his family. And no one can take that moment away.\n\n";
            }
        }

        // Paragraph 3-4: Followed storyline payoffs — Nina references her own coverage
        if (!empty($followedStories)) {
            $primary = $followedStories[0];
            $fsName = trim(($primary['pick']['first_name'] ?? '') . ' ' . ($primary['pick']['last_name'] ?? ''));
            $fsTeam = ($primary['pick']['team_city'] ?? '') . ' ' . ($primary['pick']['team_name'] ?? '');
            $fsRound = (int) ($primary['pick']['round'] ?? 1);
            $fsPos = $primary['pick']['position'] ?? '';

            $body .= "The story we've been tracking since January finally has its ending. {$fsName} — the {$fsPos} I first profiled months ago, ";
            $body .= "whose journey we followed through film study and combine testing and the endless cycle of rumor and speculation — ";
            $body .= "heard his name called by the {$fsTeam}";
            if ($fsRound === 1) {
                $body .= " in the first round";
            } elseif ($fsRound === 2) {
                $body .= " early on Day 2";
            } else {
                $body .= " on Day {$fsRound}";
            }
            $body .= ". For a young man who has been under the brightest microscope in sports for the better part of a year, the wait is finally, mercifully, over.\n\n";

            // If there's a character flag, reference the drama
            if (!empty($primary['pick']['character_flag'])) {
                $flagText = ucwords(str_replace('_', ' ', $primary['pick']['character_flag']));
                $body .= "The {$flagText} questions that hung over {$fsName}'s evaluation all offseason? The {$fsTeam} clearly did their homework and decided the talent outweighs the risk. ";
                $body .= "Only time will tell if they were right. But tonight, the story is about a second chance — or maybe a first real chance — and the franchise willing to bet on it.\n\n";
            }

            // Reference additional followed prospects if any
            if (count($followedStories) > 1) {
                $secondary = $followedStories[1];
                $ssName = trim(($secondary['pick']['first_name'] ?? '') . ' ' . ($secondary['pick']['last_name'] ?? ''));
                $ssTeam = ($secondary['pick']['team_city'] ?? '') . ' ' . ($secondary['pick']['team_name'] ?? '');
                $body .= "Another name we've been watching: {$ssName}, who landed with the {$ssTeam}. ";
                $body .= "We first wrote about his potential early in the process, and seeing him find a home feels like the end of a chapter I've been writing in my head for months.\n\n";
            }
        }

        // Paragraph 5: Surprises — with drama and weight
        if (!empty($surprises)) {
            $body .= "And then there were the moments nobody saw coming. ";
            $s = $surprises[0];
            $body .= "The {$s['team']} raised eyebrows — and raised voices in press rows — by selecting {$s['name']} ({$s['pos']}) in round {$s['round']}. ";
            $body .= "Most draft boards had him going significantly later. The war room was clearly operating on different information than the rest of us. ";
            $body .= "That's either a franchise-defining move or a franchise-altering mistake. In three years, we'll know which. Tonight, it's just a conversation piece — ";
            $body .= "and it was the pick that had every scout in the building texting under the table.\n\n";

            if (count($surprises) > 1) {
                $s2 = $surprises[1];
                $body .= "The surprises didn't stop there. The {$s2['team']} reaching for {$s2['name']} ({$s2['pos']}) also turned heads. ";
                $body .= "When the draft deviates from the consensus, it either means someone is smarter than everyone else — or they aren't.\n\n";
            }
        }

        // Paragraph 6: Slides — the heartbreak angle
        if (!empty($slides)) {
            $sl = $slides[0];
            $body .= "But the story that will linger longest from this draft — the one I'll be thinking about on the drive home — is the slide of {$sl['name']}. ";
            $body .= "The {$sl['pos']} out of college fell all the way to round {$sl['round']} before the {$sl['team']} finally called his name. ";
            $body .= "I watched the green room cameras as pick after pick went by. The forced smile. The supportive family trying to stay positive. The agent on the phone, working every connection. ";
            $body .= "When it finally happened — when {$sl['name']} finally heard his name — the relief was palpable. But so was the chip on his shoulder. ";
            $quote = self::SCOUT_QUOTES['slide'][array_rand(self::SCOUT_QUOTES['slide'])];
            $body .= "As one scout told me afterward: \"{$quote}\"\n\n";

            if (count($slides) > 1) {
                $sl2 = $slides[1];
                $body .= "{$sl2['name']} ({$sl2['pos']}) also fell further than expected, landing with the {$sl2['team']} in round {$sl2['round']}. ";
                $body .= "Thirty-one other teams will have to answer for that on film night.\n\n";
            }
        }

        // Paragraph 7: Quick-hit notable picks (second and third round picks worth mentioning)
        $roundTwoPicks = array_filter($picks, fn($p) => ((int) ($p['round'] ?? 0)) === 2);
        if (count($roundTwoPicks) >= 3) {
            $body .= "The second round produced its own drama. ";
            $r2picks = array_slice(array_values($roundTwoPicks), 0, 3);
            foreach ($r2picks as $r2) {
                $r2Name = trim(($r2['first_name'] ?? '') . ' ' . ($r2['last_name'] ?? ''));
                $r2Team = ($r2['team_city'] ?? '') . ' ' . ($r2['team_name'] ?? '');
                $body .= "The {$r2Team} added {$r2Name} ({$r2['position']}). ";
            }
            $body .= "All quality additions. All stories waiting to be told.\n\n";
        }

        // Paragraph 8: Closing — Nina's emotional resonance
        $closings = [
            "Draft night is about hope. Every franchise believes they got better today. Every family celebrated a dream realized. And somewhere, a young man who wasn't drafted is staring at his phone, wondering what comes next. The draft giveth, and the draft taketh away. But for the {$totalPicks} names called tonight, the journey continues — and the real story won't be written until they take the field.",
            "The podium is empty now. The confetti has been swept. The green room chairs are folded and stacked. But the stories that began tonight — the triumphs, the slides, the surprises — will echo through locker rooms and living rooms for years to come. This is what makes the draft the most human event in professional sports. It's not about picks and prospects. It's about people.",
            "I've covered a lot of drafts. And every year, I'm reminded of the same truth: behind every pick is a person. A family. A story that stretches back long before anyone knew their name, and forward into a future that nobody can predict. Tonight, {$totalPicks} stories got their next chapter. I can't wait to see how they end.",
            "As the lights go down and the analysts pack up their draft boards, the real work begins. For {$totalPicks} young men and their families, tonight was the culmination of a lifetime of preparation. For thirty-two franchises, it was the beginning of something new. And for those of us lucky enough to tell these stories, it was another reminder of why we love this game. See you at training camp.",
        ];
        $body .= $closings[array_rand($closings)];

        $headlines = [
            "Draft Day Drama: The Picks That Defined the {$year} Class",
            "The Night Everything Changed: Inside the {$year} Draft",
            "Dreams, Drama, and Draft Night: The {$year} Class Has Arrived",
            "From the Green Room to the Podium: A {$year} Draft Night Story",
        ];

        $this->insertArticle(
            $leagueId, $seasonId, null, 'draft_coverage',
            $headlines[array_rand($headlines)],
            $body, $scout['name'], $scout['style'], null, null, $now
        );
    }

    private function generateDraftGradesArticle(int $leagueId, int $seasonId, int $year, array $picks, string $now): void
    {
        $scout = $this->pickScout('jake_morrison');

        // Group picks by team
        $teamPicks = [];
        foreach ($picks as $pick) {
            $teamId = (int) ($pick['team_id'] ?? 0);
            if (!$teamId) continue;
            $teamPicks[$teamId][] = $pick;
        }

        if (empty($teamPicks)) {
            return;
        }

        $gradeOpeners = [
            "The draft is in the books. Now comes my favorite part — grading the work. This isn't about hindsight. This is about process, value, and vision.",
            "Every pick is a statement. Every pass is a decision. Now that the dust has settled, it's time to evaluate who made smart moves and who left value on the table.",
            "I've been grading drafts for over a decade, and I approach every one the same way: Did you address your needs? Did you get fair value? Did you have a plan? Let's find out.",
            "The confetti is swept. The draft cards are filed. And now, the evaluation of the evaluators begins. Here are my grades for every team in the {$year} draft.",
        ];

        $body = $gradeOpeners[array_rand($gradeOpeners)] . "\n\n";
        $body .= "My grading criteria: need fulfillment (did they address roster holes?), value (did they get talent appropriate to their draft slot?), and upside (did they add ceiling to their roster?). Let's get to it.\n\n";

        // Sort teams by grade for presentation (best to worst)
        $gradeResults = [];

        foreach ($teamPicks as $teamId => $tPicks) {
            $teamCity = $tPicks[0]['team_city'] ?? '';
            $teamName = $tPicks[0]['team_name'] ?? '';
            $teamAbbr = $tPicks[0]['team_abbreviation'] ?? '';
            $needs = $this->getTeamNeeds($teamId);

            $totalValue = 0;
            $needsHit = 0;
            $pickDetails = [];

            foreach ($tPicks as $tp) {
                $round = (int) ($tp['round'] ?? 4);
                $overall = (int) ($tp['overall_rating'] ?? 60);
                $pos = $tp['position'] ?? '';
                $name = trim(($tp['first_name'] ?? '') . ' ' . ($tp['last_name'] ?? ''));
                $potential = $tp['potential'] ?? 'average';

                $expectedOvr = match ($round) {
                    1 => 72, 2 => 68, 3 => 64, 4 => 60, 5 => 57, 6 => 54, default => 52,
                };
                $value = $overall - $expectedOvr;
                $totalValue += $value;

                $isNeed = in_array($pos, $needs);
                if ($isNeed) {
                    $needsHit++;
                }

                $pickDetails[] = [
                    'round' => $round, 'name' => $name, 'pos' => $pos,
                    'overall' => $overall, 'value' => $value, 'isNeed' => $isNeed,
                    'potential' => $potential,
                    'character_flag' => $tp['character_flag'] ?? null,
                ];
            }

            $pickCount = count($tPicks);
            $avgValue = $pickCount > 0 ? $totalValue / $pickCount : 0;
            $needsRate = $pickCount > 0 ? $needsHit / $pickCount : 0;

            $gradeScore = $avgValue * 2 + ($needsRate * 10);
            $grade = match (true) {
                $gradeScore >= 15 => 'A+',
                $gradeScore >= 10 => 'A',
                $gradeScore >= 6  => 'A-',
                $gradeScore >= 3  => 'B+',
                $gradeScore >= 0  => 'B',
                $gradeScore >= -3 => 'B-',
                $gradeScore >= -6 => 'C+',
                $gradeScore >= -10 => 'C',
                $gradeScore >= -15 => 'D',
                default           => 'F',
            };

            $gradeResults[] = [
                'teamCity' => $teamCity, 'teamName' => $teamName, 'teamAbbr' => $teamAbbr,
                'grade' => $grade, 'gradeScore' => $gradeScore, 'needs' => $needs,
                'pickDetails' => $pickDetails, 'needsHit' => $needsHit, 'pickCount' => $pickCount,
            ];
        }

        // Sort by grade score descending (best first)
        usort($gradeResults, fn($a, $b) => $b['gradeScore'] <=> $a['gradeScore']);

        foreach ($gradeResults as $result) {
            $body .= "**{$result['teamCity']} {$result['teamName']}** ({$result['teamAbbr']}) — Grade: **{$result['grade']}**\n\n";

            // List each pick with specific analysis
            foreach ($result['pickDetails'] as $pd) {
                $body .= "  Rd {$pd['round']}: {$pd['name']}, {$pd['pos']}\n";

                // Build specific grade explanation for each pick
                if ($pd['value'] >= 8 && $pd['isNeed']) {
                    $explanations = [
                        "  Outstanding value. They needed a {$pd['pos']} and got the best one available at pick {$pd['round']}. This player could start from day one.",
                        "  Home run. {$pd['pos']} was a primary need and {$pd['name']} was the highest-graded player at the position on my board. Perfect match.",
                        "  This is the kind of pick that wins drafts. A top-tier {$pd['pos']} who fills their biggest hole? That's an A+ selection.",
                    ];
                } elseif ($pd['value'] >= 8) {
                    $explanations = [
                        "  Best player available, and it's hard to argue with the talent. {$pd['name']} would have been a steal a round earlier.",
                        "  Pure value play. {$pd['pos']} wasn't their top need, but when this level of talent falls to you, you take it and figure out the roster later.",
                        "  The BPA approach works when the talent is this obvious. {$pd['name']} is a better player than his draft slot suggests.",
                    ];
                } elseif ($pd['value'] >= 3 && $pd['isNeed']) {
                    $explanations = [
                        "  Solid pick. They addressed the {$pd['pos']} need with a player who grades out at the right level for this draft slot.",
                        "  Smart, methodical selection. {$pd['name']} fills a need at {$pd['pos']} and the value is appropriate for round {$pd['round']}.",
                        "  Good process here. They identified {$pd['pos']} as a need and found a quality player to fill it. No complaints.",
                    ];
                } elseif ($pd['value'] >= 0) {
                    $explanations = [
                        "  Fair value. Not the kind of pick that wins you a draft, but not the kind that loses one either. {$pd['name']} is a competent selection.",
                        "  Acceptable. The grade matches the slot, and {$pd['name']} should compete for a role. Nothing more, nothing less.",
                        "  Fine. I wouldn't have made this pick, but I understand the logic. {$pd['name']} is a reasonable selection in round {$pd['round']}.",
                    ];
                } elseif ($pd['value'] >= -5) {
                    $explanations = [
                        "  A reach. {$pd['name']} could have been available a round later, and the value doesn't match the draft capital spent.",
                        "  Questionable. I had {$pd['name']} graded lower than where they took him. This feels like a need-driven pick that sacrificed value.",
                        "  I'm skeptical. {$pd['name']} is a Day " . ($pd['round'] + 1) . " talent taken on Day " . (min($pd['round'], 3)) . ". The roster need doesn't justify the reach.",
                    ];
                } else {
                    $explanations = [
                        "  I can't defend this one. {$pd['name']} in round {$pd['round']} is a significant reach. There were better players available at multiple positions.",
                        "  This is the pick that drags the grade down. {$pd['name']} is a developmental prospect taken in a premium slot. I don't see the justification.",
                        "  Head-scratcher. The film on {$pd['name']} says late-round prospect. The draft card says round {$pd['round']}. Something doesn't add up.",
                    ];
                }
                $body .= $explanations[array_rand($explanations)] . "\n";

                // Add potential and character flag notes
                if ($pd['potential'] === 'elite') {
                    $body .= "  Upside note: Generational talent. The ceiling here is Pro Bowl.\n";
                } elseif ($pd['potential'] === 'high') {
                    $body .= "  Upside note: High-ceiling prospect. Could outperform his draft slot significantly.\n";
                }

                if (!empty($pd['character_flag'])) {
                    $flagText = ucwords(str_replace('_', ' ', $pd['character_flag']));
                    $body .= "  Risk factor: {$flagText} concerns are real. This pick carries more volatility than average.\n";
                }

                $body .= "\n";
            }

            // Overall team draft summary
            $needsList = implode(', ', array_slice($result['needs'], 0, 3));
            $body .= "  Top needs entering the draft: {$needsList}\n";
            $body .= "  Needs addressed: {$result['needsHit']} of {$result['pickCount']} picks\n";

            // Grade explanation
            $gradeExplanation = match (true) {
                $result['gradeScore'] >= 15 => "This is the draft class that other front offices will study. They addressed needs, found value, and added upside. An elite haul by any measure.",
                $result['gradeScore'] >= 10 => "An excellent draft. They were disciplined, took the best player available when they could, and filled needs when they needed to. This roster got significantly better.",
                $result['gradeScore'] >= 6 => "A strong draft. Not perfect — no draft ever is — but the process was sound, the value was there, and several of these picks will contribute immediately.",
                $result['gradeScore'] >= 3 => "A good draft. They hit on more picks than they missed, addressed at least one major need, and didn't make any moves that will haunt them.",
                $result['gradeScore'] >= 0 => "An average draft. They didn't hurt themselves, but they didn't separate from the pack either. Serviceable picks across the board.",
                $result['gradeScore'] >= -3 => "A below-average draft. Some questionable decisions, some missed value, and not enough needs addressed. This class will need time to develop.",
                $result['gradeScore'] >= -6 => "A disappointing draft. The reaches outweigh the value picks, and the biggest needs on the roster are still the biggest needs. Back to the drawing board.",
                $result['gradeScore'] >= -10 => "A poor draft. Multiple reaches, significant needs unaddressed, and a strategy that's hard to understand from the outside looking in.",
                default => "A disastrous draft. I struggle to find a single pick that represents good value. This is the kind of draft that gets people fired.",
            };
            $body .= "  {$gradeExplanation}\n\n";
        }

        // Closing
        $body .= "--- FINAL WORD ---\n\n";
        $body .= "Remember: draft grades are written in pencil. I've given A+ grades to drafts that produced zero starters, and C grades to classes that produced franchise quarterbacks. ";
        $body .= "The real evaluation happens on the field, in the meeting rooms, and over the course of careers that haven't started yet. ";
        $body .= "But based on process, value, and need — the only things we can evaluate today — these are my grades. And I'm putting my name on every one of them.";

        $headlines = [
            "Morrison's {$year} Draft Grades: Every Team, Every Pick, Every Grade",
            "{$year} Draft Report Card: Winners, Losers, and Head-Scratchers",
            "Grading the {$year} Draft: A Comprehensive Team-by-Team Breakdown",
            "The Final Verdict: Morrison's Complete {$year} Draft Grades",
        ];

        $this->insertArticle(
            $leagueId, $seasonId, null, 'draft_coverage',
            $headlines[array_rand($headlines)],
            $body, $scout['name'], $scout['style'], null, null, $now
        );
    }

    // ================================================================
    //  Helper Methods
    // ================================================================

    /**
     * Fetch a single prospect by ID.
     */
    private function getProspect(int $id): array
    {
        $stmt = $this->db->prepare("SELECT * FROM draft_prospects WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: [];
    }

    /**
     * Analyze a team's roster for weakest positions (needs).
     * Returns an array of position strings sorted by need.
     */
    private function getTeamNeeds(int $teamId): array
    {
        $positionGroups = ['QB', 'RB', 'WR', 'TE', 'OT', 'OG', 'C', 'DE', 'DT', 'LB', 'CB', 'S'];
        $needs = [];

        foreach ($positionGroups as $pos) {
            $stmt = $this->db->prepare(
                "SELECT AVG(overall_rating) as avg_ovr, COUNT(*) as cnt
                 FROM players
                 WHERE team_id = ? AND position = ? AND status = 'active'"
            );
            $stmt->execute([$teamId, $pos]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            $avgOvr = (float) ($row['avg_ovr'] ?? 0);
            $count = (int) ($row['cnt'] ?? 0);

            // Minimum roster counts by position
            $minCount = match ($pos) {
                'QB' => 2, 'RB' => 2, 'WR' => 4, 'TE' => 2,
                'OT' => 2, 'OG' => 2, 'C' => 1,
                'DE' => 3, 'DT' => 2, 'LB' => 3, 'CB' => 3, 'S' => 2,
                default => 1,
            };

            // Need score: lower is worse (higher priority need)
            $needScore = $avgOvr;
            if ($count < $minCount) {
                $needScore -= (($minCount - $count) * 15); // Heavy penalty for understaffed positions
            }
            if ($count === 0) {
                $needScore = 0; // Critical need
            }

            $needs[$pos] = $needScore;
        }

        // Sort by need (lowest score = biggest need)
        asort($needs);

        return array_keys(array_slice($needs, 0, 5, true));
    }

    /**
     * Return a scout profile from self::SCOUTS.
     */
    private function pickScout(string $key): array
    {
        return self::SCOUTS[$key] ?? self::SCOUTS['jake_morrison'];
    }

    /**
     * Insert an article into the articles table.
     */
    private function insertArticle(
        int $leagueId,
        int $seasonId,
        ?int $week,
        string $type,
        string $headline,
        string $body,
        string $authorName,
        string $authorPersona,
        ?int $teamId,
        ?int $playerId,
        string $publishedAt
    ): void {
        $stmt = $this->db->prepare(
            "INSERT INTO articles (league_id, season_id, week, type, headline, body, author_name, author_persona, team_id, player_id, game_id, is_ai_generated, published_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)"
        );
        $stmt->execute([
            $leagueId, $seasonId, $week, $type, $headline, $body,
            $authorName, $authorPersona, $teamId, $playerId, null, $publishedAt,
        ]);
    }

    /**
     * Get season year from the league.
     */
    private function getSeasonYear(int $leagueId): int
    {
        $stmt = $this->db->prepare("SELECT season_year FROM leagues WHERE id = ?");
        $stmt->execute([$leagueId]);
        return (int) ($stmt->fetchColumn() ?: 2026);
    }

    /**
     * Get the most recent article by a specific author about a specific prospect.
     */
    private function getPreviousArticleAboutProspect(int $leagueId, string $authorName, int $playerId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM articles
             WHERE league_id = ? AND author_name = ? AND player_id = ?
             ORDER BY published_at DESC LIMIT 1"
        );
        $stmt->execute([$leagueId, $authorName, $playerId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Get a one-line scouting report for a position/potential tier.
     */
    private function getScoutingLine(string $position, string $potential): string
    {
        $tier = match ($potential) {
            'elite' => 'elite',
            'high'  => 'high',
            default => 'avg',
        };

        $lines = self::POSITION_SCOUTING_LINES[$position][$tier]
            ?? self::POSITION_SCOUTING_LINES[$position]['avg']
            ?? ['A talented prospect with upside.'];

        return $lines[array_rand($lines)];
    }

    /**
     * Generate position-specific film language for prospect spotlight articles.
     * Uses specific football terminology to sound like a real analyst.
     */
    private function getPositionSpecificFilmLanguage(string $position, string $name): string
    {
        $language = match ($position) {
            'QB' => [
                "What I see on film is a quarterback who processes the field pre-snap, identifies the coverage, and delivers the ball with anticipation. {$name} reads the RPO like a veteran — he holds the safety with his eyes, works through his progressions, and pulls the trigger before the window closes.",
                "His pocket presence is what separates him. {$name} feels pressure without seeing it, slides naturally, keeps his eyes downfield, and delivers accurate throws from multiple platforms. The subtle movements — the half-step up in the pocket, the slight lean to avoid the rush — those are instinctive, not coached.",
                "On tape, his best trait is ball placement. {$name} throws receivers open. He puts the ball on the outside shoulder against man coverage, leads his receivers away from linebackers in zone, and places back-shoulder fades with precision that is rare at this level.",
                "The anticipation throws are what stand out. {$name} releases the ball before the break, trusting his receivers to be where they're supposed to be. That level of confidence in the system — and in his arm — is the marker of a franchise quarterback.",
            ],
            'WR' => [
                "His route-running is the story. {$name} creates separation with footwork, not just speed. Watch the way he sells the vertical stem before snapping off a sharp comeback — defensive backs are left grabbing air. His ability to set up routes with subtle body language is advanced beyond his years.",
                "After the catch, {$name} transforms into a running back. He has a natural feel for space, makes defenders miss with his hips, and accelerates through contact. The YAC ability turns five-yard slants into twenty-yard gains — that's where the value multiplies.",
                "The contested-catch reel is absurd. {$name} attacks the ball at its highest point, uses his frame to shield defenders, and finishes through contact. He wins the fifty-fifty balls at a rate that suggests they're really seventy-thirty in his favor.",
                "His release at the line of scrimmage is already NFL-caliber. Press corners cannot get their hands on him — he uses a combination of hand swipes, foot fakes, and body lean to get free in the first two steps. Once he's clean off the line, the route is already won.",
            ],
            'RB' => [
                "His vision is the trait that jumps off the screen. {$name} reads his blocks, sets up his cuts with patience, and then explodes through the hole with a burst that linebackers can't match. He runs the zone scheme like he was born in it — one cut, and he's through the second level.",
                "The contact balance is elite. {$name} absorbs hits at the line of scrimmage, keeps his feet churning, and falls forward for an extra two or three yards on every carry. His ability to run through arm tackles makes him a nightmare in short-yardage situations.",
                "What stands out on film is his pass-catching ability. {$name} runs routes out of the backfield with the technique of a slot receiver. He creates separation against linebackers and is a genuine third-down weapon, not just a check-down option.",
                "His jump-cut ability is special. {$name} can plant, redirect, and explode in a different direction without losing speed. Defenders who overcommit to one gap find themselves grasping at air as he shoots through the opposite hole.",
            ],
            'TE' => [
                "The film shows a tight end who can do everything. {$name} blocks in-line with physicality and technique, releases into routes seamlessly, and creates mismatches that no defensive coordinator has solved. He is a true dual-threat at the position.",
                "What makes {$name} special is his ability to find soft spots in zone coverage. He sits down in the windows between linebackers and safeties, presents a massive target, and catches everything thrown his direction. He is a quarterback's safety blanket.",
                "His blocking technique is more advanced than most college tight ends. {$name} gets his hands inside, establishes leverage, and sustains his blocks through the whistle. He can line up as a sixth offensive lineman and hold up against defensive ends.",
            ],
            'DE' => [
                "His first step off the edge is explosive — the kind of burst that puts offensive tackles on their heels immediately. {$name} bends the arc with elite flexibility, dips his inside shoulder, and converts speed to power at the contact point. His get-off is the best in this class.",
                "The hand usage is where {$name} separates himself from the other edge rushers in this draft. He has a go-to rip move, a devastating swim, and a long-arm that keeps tackles at bay. When tackles recover from the initial rush, he counters with a spin move that generates sacks in the pocket.",
                "Watch the way {$name} sets the edge against the run. He is not just a pass-rush specialist — he plays the run with discipline, keeps outside leverage, and forces ball carriers back inside where the linebackers are waiting. This is a complete defensive end.",
                "His closing speed to the quarterback is elite. Once {$name} turns the corner, the play is over. He flattens to the quarterback with the kind of burst that makes you rewind the tape and watch it again.",
            ],
            'DT' => [
                "Interior disruption is the name of the game, and {$name} does it better than anyone in this class. He penetrates the A-gap with a first step that guards cannot handle, collapses the pocket from the inside, and creates negative plays at an elite rate.",
                "His ability to two-gap is what makes him scheme-versatile. {$name} can hold the point of attack against double-teams, read his keys, and shed blocks to make tackles. He does the dirty work that wins games, even when the stat sheet doesn't reflect it.",
                "What sets {$name} apart is his pass-rush ability from the interior. He has quick hands, a powerful bull rush, and the agility to win with finesse when power doesn't work. Interior pressure is the most valuable thing a defensive tackle can provide, and he provides it consistently.",
            ],
            'CB' => [
                "His man coverage technique is a thing of beauty. {$name} mirrors routes with fluid hips, stays in the receiver's hip pocket through breaks, and makes plays at the catch point without drawing flags. He has a natural feel for zone coverage, reading the quarterback's eyes and breaking on throws with elite timing.",
                "The ball production tells the story. {$name} doesn't just break up passes — he catches them. His ball skills are the best among cornerbacks in this class, and his ability to high-point interceptions and turn broken-up passes into turnovers is a game-changing trait.",
                "Watch his press technique at the line. {$name} gets his hands on receivers, disrupts the timing of the route, and reroutes them out of their stems. Once he's gotten his jam, the receiver is playing catch-up for the rest of the route.",
                "In zone coverage, his instincts are exceptional. {$name} reads the quarterback's progression, anticipates the throw, and drives on the ball with closing speed that creates turnovers. He plays the position with a cornerback's technique and a safety's range.",
            ],
            'S' => [
                "He has true center-field range. {$name} covers ground in the deep third with the speed and instincts to play single-high from day one. His ability to read the quarterback's eyes, break on throws, and close the distance is the defining trait of his game.",
                "The versatility is what makes {$name} so valuable. On any given snap, he can play deep, cover the slot, blitz from the secondary, or fill against the run. Defensive coordinators will love the chess pieces he provides — he is the ultimate movable piece in a modern defense.",
                "His tackling and physicality separate him from the other safety prospects. {$name} arrives with force, wraps up ball carriers, and plays with a fearlessness in run support that makes running backs think twice about running to his side of the field.",
            ],
            'LB' => [
                "His sideline-to-sideline range is rare for a linebacker his size. {$name} runs down ball carriers from behind, covers ground in zone like a safety, and fills his gaps with speed and violence. He reads the RPO like a veteran — diagnosing run-pass options and reacting before the offensive line can block him.",
                "The instincts against the run are what jump off the film. {$name} reads his keys, triggers downhill, and meets ball carriers in the hole with textbook technique. He doesn't wait for blocks to come to him — he attacks them.",
                "His coverage ability is what makes him a three-down linebacker. {$name} can match up with tight ends in man coverage, has the range to patrol the seam, and breaks on routes with the kind of click-and-close speed that defensive coordinators dream about.",
            ],
            'OT' => [
                "His pass sets are technically refined and battle-tested. {$name}'s kick-slide is smooth, his hands strike on time, and his anchor is rock-solid against power rushers. He mirrors speed with his feet and absorbs power with his core — the two things a franchise tackle must do.",
                "In the run game, {$name} generates movement at the point of attack. He plays with a nastiness that offensive line coaches love, driving defenders off the ball and creating running lanes. The combination of pass-protection polish and run-blocking physicality is rare.",
                "What stands out is his recovery ability. Even when {$name} gets beaten on the initial move — which is rare — he has the athleticism and awareness to recover, reset his hands, and salvage the rep. That ability to adjust mid-play is what separates good tackles from great ones.",
            ],
            default => [
                "The film reveals a player with traits that will translate to the next level. {$name} plays with effort, technique, and a competitive fire that evaluators value highly.",
                "After charting every meaningful snap, the strengths are clear and the weaknesses are correctable. {$name} projects as a player who will contribute early in his career.",
            ],
        };

        return $language[array_rand($language)];
    }

    /**
     * Derive a combine grade from a numeric score (fallback when combine_grade column is null).
     */
    private function deriveCombineGrade(int $combineScore): string
    {
        return match (true) {
            $combineScore >= 90 => 'A+',
            $combineScore >= 82 => 'A',
            $combineScore >= 74 => 'B+',
            $combineScore >= 66 => 'B',
            $combineScore >= 58 => 'C+',
            $combineScore >= 50 => 'C',
            default             => 'D',
        };
    }
}
