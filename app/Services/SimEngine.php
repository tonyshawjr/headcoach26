<?php

namespace App\Services;

use App\Database\Connection;

class SimEngine
{
    private \PDO $db;

    // Positional importance weights for team strength calculation
    private const POSITION_WEIGHTS = [
        'QB' => 3.0, 'WR' => 1.2, 'RB' => 1.0, 'TE' => 0.8,
        'OT' => 1.0, 'OG' => 0.8, 'C' => 0.7,
        'DE' => 1.2, 'DT' => 0.9, 'LB' => 1.0, 'CB' => 1.1, 'S' => 0.9,
        'K' => 0.3, 'P' => 0.2, 'LS' => 0.1,
    ];

    // Game plan matchup matrix: [offense][defense] => modifier
    private const MATCHUP_MATRIX = [
        'run_heavy'    => ['base_43' => 0, '34' => 2, 'blitz' => 3, 'prevent' => -1, 'zone' => -2],
        'balanced'     => ['base_43' => 0, '34' => 0, 'blitz' => 1, 'prevent' => 1, 'zone' => 0],
        'pass_heavy'   => ['base_43' => 1, '34' => 0, 'blitz' => -3, 'prevent' => 2, 'zone' => -1],
        'no_huddle'    => ['base_43' => 2, '34' => 1, 'blitz' => -2, 'prevent' => 3, 'zone' => 1],
        'ball_control' => ['base_43' => 1, '34' => 1, 'blitz' => 2, 'prevent' => -2, 'zone' => -1],
    ];

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
    }

    /**
     * Simulate a single game. Returns full result data.
     */
    public function simulateGame(array $game): array
    {
        $homeTeam = $this->getTeamData($game['home_team_id']);
        $awayTeam = $this->getTeamData($game['away_team_id']);

        $homePlan = json_decode($game['home_game_plan'] ?? '{}', true) ?: $this->defaultGamePlan();
        $awayPlan = json_decode($game['away_game_plan'] ?? '{}', true) ?: $this->defaultGamePlan();

        // Calculate team strengths
        $homeStr = $this->calculateTeamStrength($homeTeam['starters']);
        $awayStr = $this->calculateTeamStrength($awayTeam['starters']);

        // Apply modifiers
        $homeStr += $this->gamePlanModifier($homePlan, $awayPlan);
        $awayStr += $this->gamePlanModifier($awayPlan, $homePlan);
        $homeStr += $homeTeam['team']['home_field_advantage'];
        $homeStr += $this->weatherModifier($game['weather'] ?? 'clear', $homePlan);
        $awayStr += $this->weatherModifier($game['weather'] ?? 'clear', $awayPlan);
        $homeStr += $this->moraleModifier($homeTeam['team']['morale']);
        $awayStr += $this->moraleModifier($awayTeam['team']['morale']);

        // Generate scores
        $scores = $this->generateScore($homeStr, $awayStr);

        // Generate player stats
        $homeStats = $this->distributeStats($homeTeam, $scores['home'], $homePlan);
        $awayStats = $this->distributeStats($awayTeam, $scores['away'], $awayPlan);

        // Generate injuries
        $injuries = $this->generateInjuries($homeTeam, $awayTeam);

        // Grade performances
        $grades = $this->gradePerformances($homeStats, $awayStats, $scores);

        // Generate turning point narrative
        $turningPoint = $this->generateTurningPoint(
            $homeTeam, $awayTeam, $scores, $homeStats, $awayStats
        );

        return [
            'home_score' => $scores['home'],
            'away_score' => $scores['away'],
            'home_stats' => $homeStats,
            'away_stats' => $awayStats,
            'injuries' => $injuries,
            'grades' => $grades,
            'turning_point' => $turningPoint,
            'box_score' => $this->buildBoxScore($homeTeam, $awayTeam, $homeStats, $awayStats, $scores),
        ];
    }

    private function getTeamData(int $teamId): array
    {
        $team = $this->db->prepare("SELECT * FROM teams WHERE id = ?");
        $team->execute([$teamId]);
        $teamData = $team->fetch();

        // Get all active players for this team
        $players = $this->db->prepare(
            "SELECT * FROM players WHERE team_id = ? AND status = 'active' ORDER BY overall_rating DESC"
        );
        $players->execute([$teamId]);
        $allPlayers = $players->fetchAll();

        // Get depth chart starters
        $dc = $this->db->prepare("SELECT * FROM depth_chart WHERE team_id = ? AND slot = 1");
        $dc->execute([$teamId]);
        $starterSlots = $dc->fetchAll();

        $starterIds = array_column($starterSlots, 'player_id');

        // Get active injuries
        $injStmt = $this->db->prepare(
            "SELECT player_id FROM injuries WHERE team_id = ? AND weeks_remaining > 0"
        );
        $injStmt->execute([$teamId]);
        $injuredIds = $injStmt->fetchAll(\PDO::FETCH_COLUMN);

        // Build starters list (exclude injured)
        $starters = [];
        $positionFilled = [];

        // First: depth chart starters
        foreach ($allPlayers as $player) {
            if (in_array($player['id'], $starterIds) && !in_array($player['id'], $injuredIds)) {
                $starters[] = $player;
                $positionFilled[$player['position']] = ($positionFilled[$player['position']] ?? 0) + 1;
            }
        }

        // Fill missing positions with best available
        $positionNeeds = [
            'QB' => 1, 'RB' => 1, 'WR' => 3, 'TE' => 1,
            'OT' => 2, 'OG' => 2, 'C' => 1,
            'DE' => 2, 'DT' => 2, 'LB' => 3, 'CB' => 2, 'S' => 2,
            'K' => 1, 'P' => 1,
        ];

        foreach ($positionNeeds as $pos => $needed) {
            $filled = $positionFilled[$pos] ?? 0;
            if ($filled < $needed) {
                foreach ($allPlayers as $player) {
                    if ($player['position'] === $pos &&
                        !in_array($player['id'], $injuredIds) &&
                        !in_array($player, $starters)) {
                        $starters[] = $player;
                        $filled++;
                        if ($filled >= $needed) break;
                    }
                }
            }
        }

        return [
            'team' => $teamData,
            'starters' => $starters,
            'all_players' => $allPlayers,
        ];
    }

    private function calculateTeamStrength(array $starters): float
    {
        $totalWeighted = 0;
        $totalWeight = 0;

        foreach ($starters as $player) {
            $w = self::POSITION_WEIGHTS[$player['position']] ?? 1.0;
            $totalWeighted += $player['overall_rating'] * $w;
            $totalWeight += $w;
        }

        return $totalWeight > 0 ? $totalWeighted / $totalWeight : 65;
    }

    private function gamePlanModifier(array $ours, array $theirs): float
    {
        $offense = $ours['offense'] ?? 'balanced';
        $defense = $theirs['defense'] ?? 'base_43';

        return self::MATCHUP_MATRIX[$offense][$defense] ?? 0;
    }

    private function weatherModifier(string $weather, array $plan): float
    {
        $offense = $plan['offense'] ?? 'balanced';

        return match ($weather) {
            'rain' => match ($offense) {
                'pass_heavy', 'no_huddle' => -3,
                'run_heavy', 'ball_control' => 1,
                default => -1,
            },
            'wind' => match ($offense) {
                'pass_heavy' => -4,
                'run_heavy' => 2,
                default => -1,
            },
            'snow' => match ($offense) {
                'pass_heavy', 'no_huddle' => -3,
                'run_heavy' => 1,
                default => -2,
            },
            default => 0,
        };
    }

    private function moraleModifier(int $morale): float
    {
        return ($morale - 50) * 0.05; // -2.5 to +2.5
    }

    private function generateScore(float $homeStr, float $awayStr): array
    {
        // Base expected points: ~14-31 range (NFL average ~22 per team)
        $homeExpected = ($homeStr - 50) * 0.4 + 21;
        $awayExpected = ($awayStr - 50) * 0.4 + 21;

        // Strength differential (reduced to prevent blowouts)
        $diff = $homeStr - $awayStr;
        $homeExpected += $diff * 0.10;
        $awayExpected -= $diff * 0.10;

        // Add variance (reduced from 7 to 5)
        $homeScore = max(0, (int) round($homeExpected + $this->gaussianRandom(0, 5)));
        $awayScore = max(0, (int) round($awayExpected + $this->gaussianRandom(0, 5)));

        // Make scores feel like football
        $homeScore = $this->footballizeScore($homeScore);
        $awayScore = $this->footballizeScore($awayScore);

        // Avoid ties (rare in football, resolve with OT)
        if ($homeScore === $awayScore) {
            if (mt_rand(0, 1)) {
                $homeScore += mt_rand(0, 1) ? 7 : 3;
            } else {
                $awayScore += mt_rand(0, 1) ? 7 : 3;
            }
        }

        return ['home' => $homeScore, 'away' => $awayScore];
    }

    private function footballizeScore(int $raw): int
    {
        $touchdowns = intdiv($raw, 7);
        $remainder = $raw % 7;
        $fieldGoals = intdiv($remainder, 3);
        $leftover = $remainder % 3;

        // Small chance of safety
        if ($leftover === 2 && mt_rand(1, 10) <= 2) {
            return $touchdowns * 7 + $fieldGoals * 3 + 2;
        }

        return $touchdowns * 7 + $fieldGoals * 3;
    }

    /**
     * Distribute individual player stats based on total score and game plan.
     */
    private function distributeStats(array $teamData, int $totalScore, array $plan): array
    {
        $starters = $teamData['starters'];
        $stats = [];

        // Find key players by position
        $qb = $this->findByPosition($starters, 'QB');
        $rbs = $this->findAllByPosition($starters, 'RB');
        $wrs = $this->findAllByPosition($starters, 'WR');
        $tes = $this->findAllByPosition($starters, 'TE');
        $defenders = array_merge(
            $this->findAllByPosition($starters, 'DE'),
            $this->findAllByPosition($starters, 'DT'),
            $this->findAllByPosition($starters, 'LB'),
            $this->findAllByPosition($starters, 'CB'),
            $this->findAllByPosition($starters, 'S')
        );
        $kicker = $this->findByPosition($starters, 'K');

        // Total yards: base range + moderate score bonus (NFL avg ~330 yards/team)
        $baseYards = mt_rand(260, 340);
        $scoreBonus = (int)(($totalScore - 17) * mt_rand(3, 5));
        $totalYards = max(180, $baseYards + $scoreBonus);
        $passRatio = match ($plan['offense'] ?? 'balanced') {
            'run_heavy' => 0.40,
            'balanced' => 0.55,
            'pass_heavy' => 0.70,
            'no_huddle' => 0.65,
            'ball_control' => 0.45,
            default => 0.55,
        };

        $passYards = (int) ($totalYards * $passRatio);
        $rushYards = $totalYards - $passYards;

        // QB stats
        if ($qb) {
            $passYards = min($passYards, 400); // Cap pass yards per team
            $attempts = min(max(20, (int) ($passYards / mt_rand(6, 9))), 45); // Cap 20-45 attempts
            $compRate = 0.50 + ($qb['overall_rating'] - 60) * 0.008 + $this->gaussianRandom(0, 0.05);
            $compRate = max(0.40, min(0.80, $compRate));
            $completions = (int) ($attempts * $compRate);
            $passTds = max(0, intdiv($totalScore, 7) - mt_rand(0, 1));
            $ints = max(0, mt_rand(0, 3) - intdiv($qb['awareness'] - 50, 15));

            $stats[$qb['id']] = [
                'player_id' => $qb['id'],
                'team_id' => $teamData['team']['id'],
                'pass_attempts' => $attempts,
                'pass_completions' => $completions,
                'pass_yards' => $passYards,
                'pass_tds' => $passTds,
                'interceptions' => $ints,
                'rush_attempts' => mt_rand(1, 5),
                'rush_yards' => mt_rand(-5, 30),
                'rush_tds' => mt_rand(0, 100) < 10 ? 1 : 0,
            ];
        }

        // RB stats
        $qbId = $qb ? $qb['id'] : 0;
        $rbYardsRemaining = $rushYards - ($stats[$qbId]['rush_yards'] ?? 0);
        foreach ($rbs as $i => $rb) {
            $share = $i === 0 ? mt_rand(60, 75) / 100 : mt_rand(20, 35) / 100;
            $rbYards = min((int) ($rbYardsRemaining * $share), 180); // Cap individual RB yards
            $rbAttempts = max(1, (int) ($rbYards / mt_rand(3, 5)));
            $rbTds = 0;
            $tdsRemaining = max(0, intdiv($totalScore, 7) - ($stats[$qbId]['pass_tds'] ?? 0) - ($stats[$qbId]['rush_tds'] ?? 0));
            if ($tdsRemaining > 0 && mt_rand(1, 100) <= 60) {
                $rbTds = min($tdsRemaining, mt_rand(1, 2));
                $tdsRemaining -= $rbTds;
            }

            $stats[$rb['id']] = [
                'player_id' => $rb['id'],
                'team_id' => $teamData['team']['id'],
                'rush_attempts' => $rbAttempts,
                'rush_yards' => $rbYards,
                'rush_tds' => $rbTds,
                'targets' => mt_rand(1, 5),
                'receptions' => mt_rand(0, 4),
                'rec_yards' => mt_rand(0, 40),
                'rec_tds' => 0,
            ];
        }

        // WR stats — distribute receiving yards by rating weight
        $receivers = array_merge($wrs, $tes);
        $totalRecWeight = 0;
        foreach ($receivers as $rec) {
            $totalRecWeight += $rec['overall_rating'];
        }

        $recYardsRemaining = $passYards;
        foreach ($receivers as $rec) {
            $share = $totalRecWeight > 0 ? $rec['overall_rating'] / $totalRecWeight : 0.25;
            $recYards = (int) ($recYardsRemaining * $share) + mt_rand(-15, 15);
            $recYards = min(max(0, $recYards), 200); // Cap individual receiver yards
            $targets = max(1, (int) ($recYards / mt_rand(8, 14)));
            $catches = (int) ($targets * mt_rand(55, 75) / 100);
            $recTds = 0;

            $passTdsLeft = ($stats[$qbId]['pass_tds'] ?? 0);
            if ($passTdsLeft > 0 && mt_rand(1, count($receivers)) <= 2) {
                $recTds = 1;
            }

            $stats[$rec['id']] = [
                'player_id' => $rec['id'],
                'team_id' => $teamData['team']['id'],
                'targets' => $targets,
                'receptions' => $catches,
                'rec_yards' => $recYards,
                'rec_tds' => $recTds,
            ];
        }

        // Defensive stats
        $totalTackles = mt_rand(45, 70);
        $totalSacks = mt_rand(0, 6);
        $totalDefInts = mt_rand(0, 3);

        foreach ($defenders as $def) {
            $tackleShare = max(1, (int) ($totalTackles / count($defenders)) + mt_rand(-3, 3));
            $sack = 0;
            if ($totalSacks > 0 && in_array($def['position'], ['DE', 'DT', 'LB']) && mt_rand(1, 100) <= 30) {
                $sack = mt_rand(0, 1) ? 1.0 : 0.5;
                $totalSacks--;
            }
            $defInt = 0;
            if ($totalDefInts > 0 && in_array($def['position'], ['CB', 'S', 'LB']) && mt_rand(1, 100) <= 25) {
                $defInt = 1;
                $totalDefInts--;
            }

            $stats[$def['id']] = [
                'player_id' => $def['id'],
                'team_id' => $teamData['team']['id'],
                'tackles' => $tackleShare,
                'sacks' => $sack,
                'interceptions_def' => $defInt,
                'forced_fumbles' => mt_rand(0, 100) < 8 ? 1 : 0,
            ];
        }

        // Kicker stats
        if ($kicker) {
            $fgAttempts = intdiv($totalScore % 7, 3) + mt_rand(0, 1);
            $fgMade = max(0, $fgAttempts - (mt_rand(0, 100) < 15 ? 1 : 0));

            $stats[$kicker['id']] = [
                'player_id' => $kicker['id'],
                'team_id' => $teamData['team']['id'],
                'fg_attempts' => $fgAttempts,
                'fg_made' => $fgMade,
            ];
        }

        return $stats;
    }

    private function generateInjuries(array $home, array $away): array
    {
        $injuries = [];
        $allPlayers = array_merge($home['starters'], $away['starters']);

        foreach ($allPlayers as $player) {
            $chance = 0.03 + ($player['injury_prone'] / 1000);
            if (mt_rand(1, 10000) / 10000 < $chance) {
                $types = match ($player['position']) {
                    'QB' => ['shoulder', 'knee', 'ankle', 'hand', 'ribs'],
                    'RB' => ['knee', 'ankle', 'hamstring', 'shoulder', 'ribs'],
                    'WR', 'TE' => ['hamstring', 'knee', 'ankle', 'shoulder', 'hand'],
                    'OT', 'OG', 'C' => ['knee', 'ankle', 'shoulder', 'back', 'elbow'],
                    default => ['knee', 'hamstring', 'ankle', 'shoulder', 'concussion'],
                };

                $severity = $this->weightedRandom([
                    'day_to_day' => 40,
                    'short_term' => 35,
                    'long_term' => 20,
                    'season_ending' => 5,
                ]);

                $weeks = match ($severity) {
                    'day_to_day' => 1,
                    'short_term' => mt_rand(2, 4),
                    'long_term' => mt_rand(4, 8),
                    'season_ending' => 99,
                    default => 1,
                };

                $injuries[] = [
                    'player_id' => $player['id'],
                    'team_id' => $player['team_id'],
                    'type' => $types[array_rand($types)],
                    'severity' => $severity,
                    'weeks_remaining' => $weeks,
                    'occurred_at' => date('Y-m-d H:i:s'),
                ];
            }
        }

        return $injuries;
    }

    private function gradePerformances(array $homeStats, array $awayStats, array $scores): array
    {
        $grades = [];
        $allStats = array_merge($homeStats, $awayStats);

        foreach ($allStats as $playerId => $stat) {
            $score = 50; // Base C grade

            // Passing performance
            if (($stat['pass_attempts'] ?? 0) > 0) {
                $compPct = ($stat['pass_completions'] ?? 0) / $stat['pass_attempts'];
                $score += ($compPct - 0.60) * 100;
                $score += ($stat['pass_tds'] ?? 0) * 10;
                $score -= ($stat['interceptions'] ?? 0) * 15;
                $score += (($stat['pass_yards'] ?? 0) - 200) * 0.05;
            }

            // Rushing
            if (($stat['rush_attempts'] ?? 0) > 3) {
                $ypc = ($stat['rush_yards'] ?? 0) / $stat['rush_attempts'];
                $score += ($ypc - 4.0) * 8;
                $score += ($stat['rush_tds'] ?? 0) * 12;
            }

            // Receiving
            if (($stat['targets'] ?? 0) > 0) {
                $catchRate = ($stat['receptions'] ?? 0) / $stat['targets'];
                $score += ($catchRate - 0.60) * 30;
                $score += ($stat['rec_tds'] ?? 0) * 12;
                $score += (($stat['rec_yards'] ?? 0) - 50) * 0.1;
            }

            // Defense
            $score += ($stat['tackles'] ?? 0) * 2;
            $score += ($stat['sacks'] ?? 0) * 10;
            $score += ($stat['interceptions_def'] ?? 0) * 15;
            $score += ($stat['forced_fumbles'] ?? 0) * 8;

            // Kicking
            if (($stat['fg_attempts'] ?? 0) > 0) {
                $fgPct = ($stat['fg_made'] ?? 0) / $stat['fg_attempts'];
                $score += ($fgPct - 0.75) * 50;
            }

            $grade = match (true) {
                $score >= 85 => 'A+',
                $score >= 75 => 'A',
                $score >= 65 => 'B+',
                $score >= 55 => 'B',
                $score >= 45 => 'C+',
                $score >= 35 => 'C',
                $score >= 25 => 'D',
                default => 'F',
            };

            $grades[$playerId] = $grade;
        }

        return $grades;
    }

    private function generateTurningPoint(array $home, array $away, array $scores, array $homeStats, array $awayStats): string
    {
        $margin = abs($scores['home'] - $scores['away']);
        $winner = $scores['home'] > $scores['away'] ? $home : $away;
        $loser = $scores['home'] > $scores['away'] ? $away : $home;
        $winnerStats = $scores['home'] > $scores['away'] ? $homeStats : $awayStats;

        $winnerName = $winner['team']['city'] . ' ' . $winner['team']['name'];
        $loserName = $loser['team']['city'] . ' ' . $loser['team']['name'];

        // Find top performer on winning team
        $topPlayer = null;
        $topScore = 0;
        foreach ($winnerStats as $playerId => $stat) {
            $s = ($stat['pass_yards'] ?? 0) + ($stat['rush_yards'] ?? 0) * 2 + ($stat['rec_yards'] ?? 0) * 1.5
                + ($stat['pass_tds'] ?? 0) * 20 + ($stat['rush_tds'] ?? 0) * 20 + ($stat['rec_tds'] ?? 0) * 20;
            if ($s > $topScore) {
                $topScore = $s;
                $topPlayer = $playerId;
            }
        }

        $playerName = 'the offense';
        if ($topPlayer) {
            $stmt = $this->db->prepare("SELECT first_name, last_name, position FROM players WHERE id = ?");
            $stmt->execute([$topPlayer]);
            $p = $stmt->fetch();
            if ($p) {
                $playerName = $p['first_name'] . ' ' . $p['last_name'];
            }
        }

        $templates = [
            "in the third quarter when {$playerName} broke free for a crucial score, shifting the momentum firmly in {$winnerName}'s favor",
            "late in the fourth quarter when {$winnerName}'s defense forced a critical turnover to seal the victory",
            "midway through the second half when {$playerName} delivered a highlight-reel play that energized the {$winnerName} sideline",
            "when {$winnerName} strung together a 12-play drive in the second half, eating clock and putting points on the board",
            "early in the game when {$winnerName} jumped out to a quick lead, forcing {$loserName} to abandon their game plan",
        ];

        if ($margin <= 7) {
            $templates = array_merge($templates, [
                "in the final two minutes when {$playerName} made a clutch play to put {$winnerName} ahead for good",
                "on the last defensive stand, when {$winnerName} stopped {$loserName} on fourth down to preserve the lead",
            ]);
        }

        return $templates[array_rand($templates)];
    }

    private function buildBoxScore(array $home, array $away, array $homeStats, array $awayStats, array $scores): array
    {
        return [
            'home' => [
                'team_id' => $home['team']['id'],
                'team_name' => $home['team']['city'] . ' ' . $home['team']['name'],
                'abbreviation' => $home['team']['abbreviation'],
                'score' => $scores['home'],
                'stats' => $homeStats,
            ],
            'away' => [
                'team_id' => $away['team']['id'],
                'team_name' => $away['team']['city'] . ' ' . $away['team']['name'],
                'abbreviation' => $away['team']['abbreviation'],
                'score' => $scores['away'],
                'stats' => $awayStats,
            ],
        ];
    }

    private function defaultGamePlan(): array
    {
        $offenses = ['run_heavy', 'balanced', 'pass_heavy', 'no_huddle', 'ball_control'];
        $defenses = ['base_43', '34', 'blitz', 'prevent', 'zone'];
        return [
            'offense' => $offenses[array_rand($offenses)],
            'defense' => $defenses[array_rand($defenses)],
        ];
    }

    private function findByPosition(array $players, string $pos): ?array
    {
        foreach ($players as $p) {
            if ($p['position'] === $pos) return $p;
        }
        return null;
    }

    private function findAllByPosition(array $players, string $pos): array
    {
        return array_values(array_filter($players, fn($p) => $p['position'] === $pos));
    }

    private function gaussianRandom(float $mean, float $stddev): float
    {
        $u1 = mt_rand(1, PHP_INT_MAX) / PHP_INT_MAX;
        $u2 = mt_rand(1, PHP_INT_MAX) / PHP_INT_MAX;
        $z = sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
        return $mean + $z * $stddev;
    }

    private function weightedRandom(array $weights): string
    {
        $total = array_sum($weights);
        $rand = mt_rand(1, $total);
        $cum = 0;
        foreach ($weights as $value => $weight) {
            $cum += $weight;
            if ($rand <= $cum) return (string) $value;
        }
        return array_key_first($weights);
    }
}
