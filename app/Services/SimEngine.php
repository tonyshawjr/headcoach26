<?php

namespace App\Services;

use App\Database\Connection;

class SimEngine
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
    }

    // ─── Unit Rating Methods ───────────────────────────────────────────

    /**
     * QB accuracy + arm + awareness (55%) + WR/TE catching + routes + speed + release (45%)
     */
    private function passOffenseRating(array $starters): float
    {
        $qb = $this->findByPosition($starters, 'QB');
        $qbRating = 50;
        if ($qb) {
            $qbRating = (
                $qb['throw_accuracy_short'] * 0.15 +
                $qb['throw_accuracy_mid'] * 0.15 +
                $qb['throw_accuracy_deep'] * 0.10 +
                $qb['throw_power'] * 0.05 +
                $qb['awareness'] * 0.05 +
                $qb['throw_under_pressure'] * 0.05
            ) / 0.55;
        }

        $receivers = array_merge(
            $this->findAllByPosition($starters, 'WR'),
            $this->findAllByPosition($starters, 'TE')
        );
        $recRating = 50;
        if (!empty($receivers)) {
            $sum = 0;
            foreach ($receivers as $r) {
                $sum += $r['catching'] * 0.25 +
                    (($r['short_route_running'] + $r['medium_route_running'] + $r['deep_route_running']) / 3) * 0.30 +
                    $r['speed'] * 0.25 +
                    $r['release'] * 0.20;
            }
            $recRating = $sum / count($receivers);
        }

        return $qbRating * 0.55 + $recRating * 0.45;
    }

    /**
     * RB speed + vision + break_tackle + trucking + carrying (50%) + OL run blocking (50%)
     */
    private function runOffenseRating(array $starters): float
    {
        $rbs = $this->findAllByPosition($starters, 'RB');
        $rbRating = 50;
        if (!empty($rbs)) {
            $sum = 0;
            foreach ($rbs as $rb) {
                $sum += $rb['speed'] * 0.20 +
                    $rb['bc_vision'] * 0.25 +
                    $rb['break_tackle'] * 0.20 +
                    $rb['trucking'] * 0.15 +
                    $rb['carrying'] * 0.20;
            }
            $rbRating = $sum / count($rbs);
        }

        $ol = array_merge(
            $this->findAllByPosition($starters, 'OT'),
            $this->findAllByPosition($starters, 'OG'),
            $this->findAllByPosition($starters, 'C')
        );
        $olRating = 50;
        if (!empty($ol)) {
            $sum = 0;
            foreach ($ol as $o) {
                $sum += $o['run_block'] * 0.40 +
                    $o['run_block_power'] * 0.30 +
                    $o['run_block_finesse'] * 0.30;
            }
            $olRating = $sum / count($ol);
        }

        return $rbRating * 0.50 + $olRating * 0.50;
    }

    /**
     * OL pass_block + pass_block_finesse + pass_block_power + awareness
     */
    private function passBlockRating(array $starters): float
    {
        $ol = array_merge(
            $this->findAllByPosition($starters, 'OT'),
            $this->findAllByPosition($starters, 'OG'),
            $this->findAllByPosition($starters, 'C')
        );
        if (empty($ol)) return 50;

        $sum = 0;
        foreach ($ol as $o) {
            $sum += $o['pass_block'] * 0.35 +
                $o['pass_block_finesse'] * 0.25 +
                $o['pass_block_power'] * 0.25 +
                $o['awareness'] * 0.15;
        }
        return $sum / count($ol);
    }

    /**
     * CB/S man/zone coverage + press + speed + play_recognition + LB zone/man coverage
     */
    private function passDefenseRating(array $starters): float
    {
        $dbs = array_merge(
            $this->findAllByPosition($starters, 'CB'),
            $this->findAllByPosition($starters, 'S')
        );
        $dbRating = 50;
        if (!empty($dbs)) {
            $sum = 0;
            foreach ($dbs as $d) {
                $sum += $d['man_coverage'] * 0.25 +
                    $d['zone_coverage'] * 0.25 +
                    $d['press'] * 0.10 +
                    $d['speed'] * 0.15 +
                    $d['play_recognition'] * 0.25;
            }
            $dbRating = $sum / count($dbs);
        }

        $lbs = $this->findAllByPosition($starters, 'LB');
        $lbCovRating = 50;
        if (!empty($lbs)) {
            $sum = 0;
            foreach ($lbs as $lb) {
                $sum += $lb['zone_coverage'] * 0.50 + $lb['man_coverage'] * 0.50;
            }
            $lbCovRating = $sum / count($lbs);
        }

        return $dbRating * 0.75 + $lbCovRating * 0.25;
    }

    /**
     * DL block_shedding + tackle + pursuit + LB tackle + pursuit + play_recognition
     */
    private function runDefenseRating(array $starters): float
    {
        $dl = array_merge(
            $this->findAllByPosition($starters, 'DE'),
            $this->findAllByPosition($starters, 'DT')
        );
        $dlRating = 50;
        if (!empty($dl)) {
            $sum = 0;
            foreach ($dl as $d) {
                $sum += $d['block_shedding'] * 0.40 +
                    $d['tackle'] * 0.30 +
                    $d['pursuit'] * 0.30;
            }
            $dlRating = $sum / count($dl);
        }

        $lbs = $this->findAllByPosition($starters, 'LB');
        $lbRating = 50;
        if (!empty($lbs)) {
            $sum = 0;
            foreach ($lbs as $lb) {
                $sum += $lb['tackle'] * 0.35 +
                    $lb['pursuit'] * 0.35 +
                    $lb['play_recognition'] * 0.30;
            }
            $lbRating = $sum / count($lbs);
        }

        return $dlRating * 0.50 + $lbRating * 0.50;
    }

    /**
     * DL finesse_moves + power_moves + speed + pursuit
     */
    private function passRushRating(array $starters): float
    {
        $dl = array_merge(
            $this->findAllByPosition($starters, 'DE'),
            $this->findAllByPosition($starters, 'DT')
        );
        if (empty($dl)) return 50;

        $sum = 0;
        foreach ($dl as $d) {
            $sum += $d['finesse_moves'] * 0.30 +
                $d['power_moves'] * 0.30 +
                $d['speed'] * 0.20 +
                $d['pursuit'] * 0.20;
        }
        return $sum / count($dl);
    }

    // ─── Game State ────────────────────────────────────────────────────

    private function initGameState(): array
    {
        return [
            'quarter' => 1,
            'clock' => 900,       // seconds remaining in quarter
            'home_score' => 0,
            'away_score' => 0,
            'possession' => 'home', // 'home' or 'away'
            'yard_line' => 25,     // 0=own endzone, 100=opponent endzone
            'down' => 1,
            'distance' => 10,
            'plays_run' => 0,
            'home_timeouts' => 3,
            'away_timeouts' => 3,
            'in_game_injuries' => [],
            'injured_player_ids' => [],
            'questionable_returns' => [], // player_id => drives_missed
        ];
    }

    private function isGameOver(array $state): bool
    {
        return $state['quarter'] > 4;
    }

    private function advanceClock(array &$state, string $playType, bool $hurryUp = false): void
    {
        // NFL average: ~40 seconds per play (play clock). Include huddle, snap, play action.
        // Run plays burn more clock. Incomplete passes stop the clock.
        $elapsed = match ($playType) {
            'run' => mt_rand(35, 45),
            'pass_complete' => mt_rand(30, 42),
            'pass_incomplete', 'sack' => mt_rand(6, 12),
            'penalty' => mt_rand(20, 35),
            'field_goal', 'punt' => mt_rand(8, 15),
            'kickoff' => mt_rand(5, 10),
            'touchdown' => mt_rand(5, 12),
            default => mt_rand(30, 40),
        };

        // Hurry-up offense: no-huddle, quick snap reduces clock usage by 40%
        if ($hurryUp) {
            $elapsed = (int)round($elapsed * 0.6);
        }

        $state['clock'] -= $elapsed;

        if ($state['clock'] <= 0) {
            $state['quarter']++;
            if ($state['quarter'] <= 4) {
                $state['clock'] = 900;
            }
            // Half-time: reset timeouts
            if ($state['quarter'] === 3) {
                $state['home_timeouts'] = 3;
                $state['away_timeouts'] = 3;
            }
        }
    }

    private function kickoff(array &$state, array $kickingTeam = [], array $receivingTeam = [], array &$allStats = [], array &$gameLog = []): array
    {
        $result = ['type' => 'kickoff', 'return_td' => false, 'onside' => false, 'touchback' => false, 'return_yards' => 0];

        // Find kicker on kicking team
        $kicker = !empty($kickingTeam) ? $this->findByPosition($kickingTeam['starters'] ?? [], 'K') : null;
        $kickPower = $kicker ? ($kicker['kick_power'] ?? 65) : 65;

        // Onside kick check: trailing by 1-8 in Q4 with < 5:00 left
        if (!empty($kickingTeam) && $state['quarter'] === 4 && $state['clock'] < 300) {
            $kickingSide = $state['possession']; // possession is set to receiving team before kickoff
            // The kicking team is the opposite of current possession
            $kickerScore = $kickingSide === 'home' ? $state['away_score'] : $state['home_score'];
            $receiverScore = $kickingSide === 'home' ? $state['home_score'] : $state['away_score'];
            $deficit = $receiverScore - $kickerScore;

            if ($deficit >= 1 && $deficit <= 8) {
                // 15% chance of onside attempt
                if (mt_rand(1, 100) <= 15) {
                    $result['onside'] = true;
                    // 10% recovery rate
                    if (mt_rand(1, 100) <= 10) {
                        // Kicking team recovers — switch possession back
                        $this->switchPossession($state);
                        $state['yard_line'] = 45; // midfield-ish
                        $state['down'] = 1;
                        $state['distance'] = 10;
                        $result['recovered'] = true;
                        if (!empty($gameLog)) {
                            $gameLog[] = [
                                'quarter' => $state['quarter'], 'clock' => $state['clock'],
                                'possession' => $state['possession'], 'yard_line' => $state['yard_line'],
                                'down' => $state['down'], 'distance' => $state['distance'],
                                'home_score' => $state['home_score'], 'away_score' => $state['away_score'],
                                'play' => ['type' => 'onside_kick', 'yards' => 0, 'made' => true,
                                    'distance' => null, 'player' => null, 'target' => null, 'defender' => null, 'depth' => null],
                                'note' => 'Onside kick recovered!',
                            ];
                        }
                        return $result;
                    } else {
                        // Failed onside — receiving team gets great field position
                        $state['yard_line'] = 55 + mt_rand(0, 10);
                        $state['down'] = 1;
                        $state['distance'] = 10;
                        $result['recovered'] = false;
                        if (!empty($gameLog)) {
                            $gameLog[] = [
                                'quarter' => $state['quarter'], 'clock' => $state['clock'],
                                'possession' => $state['possession'], 'yard_line' => $state['yard_line'],
                                'down' => $state['down'], 'distance' => $state['distance'],
                                'home_score' => $state['home_score'], 'away_score' => $state['away_score'],
                                'play' => ['type' => 'onside_kick', 'yards' => 0, 'made' => false,
                                    'distance' => null, 'player' => null, 'target' => null, 'defender' => null, 'depth' => null],
                                'note' => 'Onside kick failed',
                            ];
                        }
                        return $result;
                    }
                }
            }
        }

        // Touchback probability based on kicker's kick_power
        $touchbackChance = 40 + ($kickPower - 65) * 1.5;
        $touchbackChance = max(10, min(90, $touchbackChance));

        if (mt_rand(1, 100) <= $touchbackChance) {
            $state['yard_line'] = 25;
            $state['down'] = 1;
            $state['distance'] = 10;
            $result['touchback'] = true;
            return $result;
        }

        // Not a touchback — kick return
        $startYard = 15 + mt_rand(0, 10);

        // Find fastest WR or RB on receiving team as returner
        $returner = null;
        if (!empty($receivingTeam)) {
            $candidates = array_merge(
                $this->findAllByPosition($receivingTeam['starters'] ?? [], 'WR'),
                $this->findAllByPosition($receivingTeam['starters'] ?? [], 'RB')
            );
            if (!empty($candidates)) {
                usort($candidates, fn($a, $b) => ($b['speed'] ?? 0) - ($a['speed'] ?? 0));
                $returner = $candidates[0];
            }
        }

        $returnerSpeed = $returner ? ($returner['speed'] ?? 70) : 70;
        $returnerJuke = $returner ? ($returner['juke_move'] ?? 50) : 50;

        $baseReturn = mt_rand(15, 25);
        $speedBonus = ($returnerSpeed - 70) * 0.3;
        $returnYards = (int)round($baseReturn + $speedBonus);

        // Return TD chance
        $returnTdChance = $returnerSpeed > 90 ? 1.0 : 0.3;
        if (mt_rand(1, 1000) <= ($returnTdChance * 10)) {
            // Return touchdown!
            $result['return_td'] = true;
            $result['return_yards'] = 100 - $startYard;

            // Score the TD for the receiving team
            $receivingSide = $state['possession'];
            $tdPoints = $this->scoreTouchdown($state, $receivingSide);

            // Track returner stats
            if ($returner && !empty($allStats)) {
                $recTeamId = $returner['team_id'];
                $this->ensurePlayerStats($allStats, $returner['id'], $recTeamId);
                $allStats[$returner['id']]['kick_returns']++;
                $allStats[$returner['id']]['return_yards'] += (100 - $startYard);
                $allStats[$returner['id']]['return_tds']++;
            }

            if (!empty($gameLog)) {
                $returnerName = $returner ? (($returner['first_name'] ?? '') . ' ' . ($returner['last_name'] ?? '')) : 'Returner';
                $gameLog[] = [
                    'quarter' => $state['quarter'], 'clock' => $state['clock'],
                    'possession' => $state['possession'], 'yard_line' => 100,
                    'down' => 1, 'distance' => 10,
                    'home_score' => $state['home_score'], 'away_score' => $state['away_score'],
                    'play' => ['type' => 'kick_return_td', 'yards' => (100 - $startYard),
                        'made' => null, 'distance' => null, 'player' => $returnerName,
                        'target' => null, 'defender' => null, 'depth' => null],
                    'note' => 'Kick return touchdown!',
                ];
            }

            // After return TD, the scoring team kicks off — switch possession for the next kickoff
            $this->switchPossession($state);
            $state['yard_line'] = 25;
            $state['down'] = 1;
            $state['distance'] = 10;
            return $result;
        }

        $finalYard = min(99, $startYard + $returnYards);
        $result['return_yards'] = $returnYards;

        // Track returner stats
        if ($returner && !empty($allStats)) {
            $recTeamId = $returner['team_id'];
            $this->ensurePlayerStats($allStats, $returner['id'], $recTeamId);
            $allStats[$returner['id']]['kick_returns']++;
            $allStats[$returner['id']]['return_yards'] += $returnYards;
        }

        // Log notable returns (20+ yards)
        if ($returnYards >= 20 && !empty($gameLog)) {
            $returnerName = $returner ? (($returner['first_name'] ?? '') . ' ' . ($returner['last_name'] ?? '')) : 'Returner';
            $gameLog[] = [
                'quarter' => $state['quarter'], 'clock' => $state['clock'],
                'possession' => $state['possession'], 'yard_line' => $finalYard,
                'down' => 1, 'distance' => 10,
                'home_score' => $state['home_score'], 'away_score' => $state['away_score'],
                'play' => ['type' => 'kick_return', 'yards' => $returnYards,
                    'made' => null, 'distance' => null, 'player' => $returnerName,
                    'target' => null, 'defender' => null, 'depth' => null],
                'note' => "Big kick return ({$returnYards} yards)",
            ];
        }

        $state['yard_line'] = $finalYard;
        $state['down'] = 1;
        $state['distance'] = 10;
        return $result;
    }

    private function switchPossession(array &$state): void
    {
        $state['possession'] = $state['possession'] === 'home' ? 'away' : 'home';
    }

    private function punt(array &$state, array $puntingTeam = [], array $receivingTeam = [], array &$allStats = [], array &$gameLog = []): array
    {
        $result = ['type' => 'punt', 'muffed' => false, 'return_td' => false, 'touchback' => false, 'return_yards' => 0, 'punt_yards' => 0];

        // Find punter
        $punter = !empty($puntingTeam) ? $this->findByPosition($puntingTeam['starters'] ?? [], 'P') : null;
        $puntPower = $punter ? ($punter['kick_power'] ?? 65) : 65;

        // Punt distance based on punter's kick_power
        $puntYards = (int)round(35 + ($puntPower - 65) * 0.4 + mt_rand(0, 10));
        $result['punt_yards'] = $puntYards;

        // Calculate where punt lands (from punting team's perspective)
        $landingSpot = $state['yard_line'] + $puntYards;

        // Touchback if punt lands in end zone
        if ($landingSpot >= 100) {
            $this->switchPossession($state);
            $state['yard_line'] = 20;
            $state['down'] = 1;
            $state['distance'] = 10;
            $result['touchback'] = true;
            return $result;
        }

        // Find returner (fastest WR or RB on receiving team)
        $returner = null;
        if (!empty($receivingTeam)) {
            $candidates = array_merge(
                $this->findAllByPosition($receivingTeam['starters'] ?? [], 'WR'),
                $this->findAllByPosition($receivingTeam['starters'] ?? [], 'RB')
            );
            if (!empty($candidates)) {
                usort($candidates, fn($a, $b) => ($b['speed'] ?? 0) - ($a['speed'] ?? 0));
                $returner = $candidates[0];
            }
        }

        $returnerSpeed = $returner ? ($returner['speed'] ?? 70) : 70;

        // Muffed punt chance: 1.5% — fumble, kicking team recovers
        if (mt_rand(1, 1000) <= 15) {
            $result['muffed'] = true;
            // Kicking team recovers at the landing spot
            // Don't switch possession — kicking team gets the ball
            $state['yard_line'] = max(1, min(99, $landingSpot));
            $state['down'] = 1;
            $state['distance'] = 10;

            if (!empty($gameLog)) {
                $gameLog[] = [
                    'quarter' => $state['quarter'], 'clock' => $state['clock'],
                    'possession' => $state['possession'], 'yard_line' => $state['yard_line'],
                    'down' => $state['down'], 'distance' => $state['distance'],
                    'home_score' => $state['home_score'], 'away_score' => $state['away_score'],
                    'play' => ['type' => 'muffed_punt', 'yards' => 0, 'made' => null,
                        'distance' => null, 'player' => null, 'target' => null, 'defender' => null, 'depth' => null],
                    'note' => 'Muffed punt! Kicking team recovers',
                ];
            }
            return $result;
        }

        // Fair catch: 40% of the time
        if (mt_rand(1, 100) <= 40) {
            $this->switchPossession($state);
            $state['yard_line'] = max(1, min(99, 100 - $landingSpot));
            $state['down'] = 1;
            $state['distance'] = 10;
            $result['return_yards'] = 0;
            return $result;
        }

        // Punt return
        $baseReturn = mt_rand(0, 12);
        $speedBonus = ($returnerSpeed - 70) * 0.3;
        $returnYards = max(0, (int)round($baseReturn + $speedBonus));

        // Punt return TD chance: 0.5% if returner speed > 88
        if ($returnerSpeed > 88 && mt_rand(1, 1000) <= 5) {
            $result['return_td'] = true;

            $this->switchPossession($state);
            $receivingSide = $state['possession'];

            $totalReturnYards = 100 - (100 - $landingSpot); // = landingSpot yards from their endzone perspective
            $result['return_yards'] = $totalReturnYards;

            $tdPoints = $this->scoreTouchdown($state, $receivingSide);

            if ($returner && !empty($allStats)) {
                $recTeamId = $returner['team_id'];
                $this->ensurePlayerStats($allStats, $returner['id'], $recTeamId);
                $allStats[$returner['id']]['punt_returns']++;
                $allStats[$returner['id']]['return_yards'] += $totalReturnYards;
                $allStats[$returner['id']]['return_tds']++;
            }

            if (!empty($gameLog)) {
                $returnerName = $returner ? (($returner['first_name'] ?? '') . ' ' . ($returner['last_name'] ?? '')) : 'Returner';
                $gameLog[] = [
                    'quarter' => $state['quarter'], 'clock' => $state['clock'],
                    'possession' => $receivingSide, 'yard_line' => 100,
                    'down' => 1, 'distance' => 10,
                    'home_score' => $state['home_score'], 'away_score' => $state['away_score'],
                    'play' => ['type' => 'punt_return_td', 'yards' => $totalReturnYards,
                        'made' => null, 'distance' => null, 'player' => $returnerName,
                        'target' => null, 'defender' => null, 'depth' => null],
                    'note' => 'Punt return touchdown!',
                ];
            }

            // After return TD, scoring team kicks off
            $this->switchPossession($state);
            $state['yard_line'] = 25;
            $state['down'] = 1;
            $state['distance'] = 10;
            return $result;
        }

        $result['return_yards'] = $returnYards;

        // Track returner stats
        if ($returner && !empty($allStats) && $returnYards > 0) {
            $recTeamId = $returner['team_id'];
            $this->ensurePlayerStats($allStats, $returner['id'], $recTeamId);
            $allStats[$returner['id']]['punt_returns']++;
            $allStats[$returner['id']]['return_yards'] += $returnYards;
        }

        $this->switchPossession($state);
        // yard_line is still from the punting team's perspective after switchPossession
        // Receiving team's yard_line: 100 - landingSpot + returnYards
        $receiverYard = 100 - $landingSpot + $returnYards;
        $state['yard_line'] = max(1, min(99, $receiverYard));
        $state['down'] = 1;
        $state['distance'] = 10;

        // Log notable returns (10+ yards for punts)
        if ($returnYards >= 10 && !empty($gameLog)) {
            $returnerName = $returner ? (($returner['first_name'] ?? '') . ' ' . ($returner['last_name'] ?? '')) : 'Returner';
            $gameLog[] = [
                'quarter' => $state['quarter'], 'clock' => $state['clock'],
                'possession' => $state['possession'], 'yard_line' => $state['yard_line'],
                'down' => $state['down'], 'distance' => $state['distance'],
                'home_score' => $state['home_score'], 'away_score' => $state['away_score'],
                'play' => ['type' => 'punt_return', 'yards' => $returnYards,
                    'made' => null, 'distance' => null, 'player' => $returnerName,
                    'target' => null, 'defender' => null, 'depth' => null],
                'note' => "Punt return ({$returnYards} yards)",
            ];
        }

        return $result;
    }

    // ─── Defense Scheme Modifiers ──────────────────────────────────────

    private function getDefenseModifiers(string $scheme): array
    {
        return match ($scheme) {
            'blitz' => ['pressure' => 15, 'man' => 5, 'zone' => -10, 'run_def' => -5],
            'zone' => ['pressure' => -5, 'man' => -10, 'zone' => 15, 'run_def' => 5],
            '34' => ['pressure' => 5, 'man' => 0, 'zone' => 0, 'run_def' => 10],
            'prevent' => ['pressure' => -15, 'man' => 0, 'zone' => 10, 'run_def' => -15],
            'base_43' => ['pressure' => 0, 'man' => 0, 'zone' => 0, 'run_def' => 0],
            default => ['pressure' => 0, 'man' => 0, 'zone' => 0, 'run_def' => 0],
        };
    }

    // ─── Weather Effects ───────────────────────────────────────────────

    private function getWeatherEffects(string $weather): array
    {
        return match ($weather) {
            'rain' => [
                'pass_accuracy' => -8,
                'deep_accuracy' => -15,
                'fumble_mod' => 1.5,
                'kick_accuracy' => -10,
                'run_boost' => 3,
            ],
            'snow' => [
                'pass_accuracy' => -10,
                'deep_accuracy' => -18,
                'fumble_mod' => 1.8,
                'kick_accuracy' => -15,
                'run_boost' => 2,
            ],
            'wind' => [
                'pass_accuracy' => -5,
                'deep_accuracy' => -20,
                'fumble_mod' => 1.0,
                'kick_accuracy' => -12,
                'run_boost' => 4,
            ],
            default => [
                'pass_accuracy' => 0,
                'deep_accuracy' => 0,
                'fumble_mod' => 1.0,
                'kick_accuracy' => 0,
                'run_boost' => 0,
            ],
        };
    }

    // ─── Play Calling ──────────────────────────────────────────────────

    /**
     * Decide run or pass based on game plan + situation.
     */
    private function selectPlayType(array $offPlan, array $state, string $possession): string
    {
        $offense = $offPlan['offense'] ?? 'balanced';

        // Base pass probability by scheme
        $passPct = match ($offense) {
            'run_heavy' => 35,
            'ball_control' => 40,
            'balanced' => 52,
            'no_huddle' => 62,
            'pass_heavy' => 68,
            default => 52,
        };

        // Situational adjustments
        if ($state['down'] === 3 && $state['distance'] >= 7) {
            $passPct += 25; // 3rd and long
        } elseif ($state['down'] === 3 && $state['distance'] <= 2) {
            $passPct -= 15; // 3rd and short, lean run
        }

        if ($state['down'] === 1 && $state['distance'] === 10) {
            $passPct -= 3; // First down, slightly favor run
        }

        // Goal line (inside 5)
        if ($state['yard_line'] >= 95) {
            $passPct -= 10;
        }

        // Leading late: run more
        $myScore = $possession === 'home' ? $state['home_score'] : $state['away_score'];
        $oppScore = $possession === 'home' ? $state['away_score'] : $state['home_score'];
        if ($state['quarter'] >= 4 && $myScore > $oppScore && ($myScore - $oppScore) >= 7) {
            $passPct -= 25;
        }

        // Trailing late: pass more
        if ($state['quarter'] >= 4 && $oppScore > $myScore && ($oppScore - $myScore) >= 7) {
            $passPct += 20;
        }

        // 2-minute drill
        if ($state['clock'] <= 120 && ($state['quarter'] === 2 || $state['quarter'] === 4)) {
            $passPct += 15;
        }

        $passPct = max(15, min(85, $passPct));

        return mt_rand(1, 100) <= $passPct ? 'pass' : 'run';
    }

    /**
     * Select pass depth based on down/distance.
     */
    private function selectPassDepth(array $state): string
    {
        $distance = $state['distance'];
        $down = $state['down'];

        // NFL pass depth distribution: ~55% short, ~30% mid, ~15% deep
        if ($distance <= 3) {
            return $this->weightedRandom(['short' => 65, 'mid' => 28, 'deep' => 7]);
        } elseif ($distance <= 8) {
            return $this->weightedRandom(['short' => 45, 'mid' => 40, 'deep' => 15]);
        } elseif ($distance <= 15) {
            return $this->weightedRandom(['short' => 25, 'mid' => 50, 'deep' => 25]);
        } else {
            return $this->weightedRandom(['short' => 15, 'mid' => 45, 'deep' => 40]);
        }
    }

    /**
     * Select a target receiver (WR or TE).
     */
    private function selectTarget(array $receivers, string $depth): ?array
    {
        if (empty($receivers)) return null;

        $weights = [];
        foreach ($receivers as $i => $r) {
            $routeAttr = match ($depth) {
                'short' => $r['short_route_running'],
                'mid' => $r['medium_route_running'],
                'deep' => $r['deep_route_running'],
                default => $r['medium_route_running'],
            };
            // Higher route running + catching = more likely target
            $weights[$i] = (int)(($routeAttr + $r['catching'] + $r['overall_rating']) / 3);
        }

        $total = array_sum($weights);
        if ($total <= 0) return $receivers[array_rand($receivers)];

        $roll = mt_rand(1, $total);
        $cum = 0;
        foreach ($weights as $i => $w) {
            $cum += $w;
            if ($roll <= $cum) return $receivers[$i];
        }
        return $receivers[0];
    }

    /**
     * Find the best covering defender for a receiver.
     */
    private function findCoveringDefender(array $defStarters, string $receiverPos): ?array
    {
        // CBs cover WRs, Safeties cover TEs primarily
        if ($receiverPos === 'TE') {
            $defenders = array_merge(
                $this->findAllByPosition($defStarters, 'S'),
                $this->findAllByPosition($defStarters, 'LB')
            );
        } else {
            $defenders = array_merge(
                $this->findAllByPosition($defStarters, 'CB'),
                $this->findAllByPosition($defStarters, 'S')
            );
        }

        if (empty($defenders)) return null;

        // Return a random defender from the pool (simulates matchup variety)
        return $defenders[array_rand($defenders)];
    }

    // ─── Play Resolution ───────────────────────────────────────────────

    /**
     * Resolve a pass play through 5 steps:
     * 1. OL pass_block vs DL pass_rush → pressure
     * 2. If pressured: sack check
     * 3. Select target + depth
     * 4. WR vs CB matchup
     * 5. Completion/incompletion/INT
     */
    private function resolvePassPlay(
        array $offStarters,
        array $defStarters,
        array $state,
        array $defMods,
        array $weatherFx
    ): array {
        $qb = $this->findByPosition($offStarters, 'QB');
        if (!$qb) {
            return ['type' => 'incomplete', 'yards' => 0, 'details' => []];
        }

        $receivers = array_merge(
            $this->findAllByPosition($offStarters, 'WR'),
            $this->findAllByPosition($offStarters, 'TE')
        );

        // Step 1: Pressure check
        $passBlockAvg = $this->passBlockRating($offStarters);
        $passRushAvg = $this->passRushRating($defStarters);

        $pressureDiff = ($passRushAvg + $defMods['pressure']) - $passBlockAvg;
        // Map to 10-50% pressure chance
        $pressureChance = 25 + ($pressureDiff * 0.8);
        $pressureChance = max(10, min(50, $pressureChance));

        $isPressured = mt_rand(1, 100) <= $pressureChance;
        $sacked = false;
        $sackDefender = null;

        // Step 2: Sack check if pressured
        if ($isPressured) {
            // QB break_sack determines sack avoidance
            $sackRate = 45 - ($qb['break_sack'] - 50) * 0.6;
            $sackRate = max(15, min(55, $sackRate));

            if (mt_rand(1, 100) <= $sackRate) {
                $sacked = true;
                $sackYards = mt_rand(-10, -3);

                // Credit sack to a DL/LB
                $rushers = array_merge(
                    $this->findAllByPosition($defStarters, 'DE'),
                    $this->findAllByPosition($defStarters, 'DT'),
                    $this->findAllByPosition($defStarters, 'LB')
                );
                if (!empty($rushers)) {
                    // Prefer DEs for sacks
                    $des = $this->findAllByPosition($defStarters, 'DE');
                    $sackDefender = !empty($des) && mt_rand(1, 100) <= 60
                        ? $des[array_rand($des)]
                        : $rushers[array_rand($rushers)];
                }

                return [
                    'type' => 'sack',
                    'yards' => $sackYards,
                    'details' => [
                        'qb' => $qb,
                        'defender' => $sackDefender,
                        'pressured' => true,
                    ],
                ];
            }
        }

        // Step 3: Select target and depth
        $depth = $this->selectPassDepth($state);
        $target = $this->selectTarget($receivers, $depth);
        if (!$target) {
            return ['type' => 'incomplete', 'yards' => 0, 'details' => ['qb' => $qb, 'pressured' => $isPressured]];
        }

        // Step 4: WR vs CB matchup
        $defender = $this->findCoveringDefender($defStarters, $target['position']);

        $routeAttr = match ($depth) {
            'short' => $target['short_route_running'],
            'mid' => $target['medium_route_running'],
            'deep' => $target['deep_route_running'],
            default => $target['medium_route_running'],
        };

        $wrAdvantage = ($routeAttr + $target['speed'] + $target['release']) / 3;
        $dbAdvantage = 50;
        if ($defender) {
            $covType = ($defMods['man'] >= $defMods['zone']) ? 'man' : 'zone';
            $covRating = $covType === 'man'
                ? $defender['man_coverage'] + $defMods['man']
                : $defender['zone_coverage'] + $defMods['zone'];
            $dbAdvantage = ($covRating + $defender['speed'] + ($defender['press'] ?? 50)) / 3;
        }

        $matchupDelta = $wrAdvantage - $dbAdvantage;

        // Step 5: Completion check
        $accuracyAttr = match ($depth) {
            'short' => $qb['throw_accuracy_short'],
            'mid' => $qb['throw_accuracy_mid'],
            'deep' => $qb['throw_accuracy_deep'],
            default => $qb['throw_accuracy_mid'],
        };

        $deepAccMod = $depth === 'deep' ? $weatherFx['deep_accuracy'] : $weatherFx['pass_accuracy'];

        // Base completion chance by depth (NFL averages: ~65% overall)
        $baseComp = match ($depth) {
            'short' => 68,
            'mid' => 48,
            'deep' => 30,
            default => 50,
        };

        $compChance = $baseComp
            + ($accuracyAttr - 65) * 0.5   // QB accuracy bonus
            + $matchupDelta * 0.3            // matchup advantage
            + $deepAccMod;                    // weather

        if ($isPressured) {
            $compChance -= 15;
            $compChance += ($qb['throw_under_pressure'] - 50) * 0.3;
        }

        $compChance = max(15, min(78, $compChance));

        if (mt_rand(1, 100) <= $compChance) {
            // COMPLETION
            $baseYards = match ($depth) {
                'short' => mt_rand(1, 7),
                'mid' => mt_rand(7, 16),
                'deep' => mt_rand(18, 40),
                default => mt_rand(4, 12),
            };

            // YAC based on WR speed + break_tackle (NFL avg YAC: ~4-5 yards)
            $yacChance = ($target['speed'] - 50) * 0.3 + ($target['break_tackle'] - 50) * 0.2;
            $yac = max(0, (int)($yacChance * mt_rand(0, 60) / 200));

            $totalYards = $baseYards + $yac;

            // Catch in traffic for short/mid
            if ($depth !== 'deep' && $defender && mt_rand(1, 100) > $target['catch_in_traffic']) {
                // Dropped in traffic — 10% chance
                if (mt_rand(1, 100) <= 10) {
                    return [
                        'type' => 'incomplete',
                        'yards' => 0,
                        'details' => [
                            'qb' => $qb,
                            'target' => $target,
                            'defender' => $defender,
                            'depth' => $depth,
                            'pressured' => $isPressured,
                            'dropped' => true,
                        ],
                    ];
                }
            }

            return [
                'type' => 'completion',
                'yards' => $totalYards,
                'details' => [
                    'qb' => $qb,
                    'target' => $target,
                    'defender' => $defender,
                    'depth' => $depth,
                    'pressured' => $isPressured,
                    'yac' => $yac,
                ],
            ];
        }

        // INCOMPLETE — check for INT
        $intChance = 4 + ($defender ? ($defender['play_recognition'] - 50) * 0.15 : 0)
            - ($qb['awareness'] - 50) * 0.15;
        if ($isPressured) $intChance += 4;
        if ($depth === 'deep') $intChance += 3;
        $intChance = max(2, min(20, $intChance));

        if (mt_rand(1, 100) <= $intChance) {
            return [
                'type' => 'interception',
                'yards' => 0,
                'details' => [
                    'qb' => $qb,
                    'target' => $target,
                    'defender' => $defender,
                    'depth' => $depth,
                    'pressured' => $isPressured,
                    'int_return' => mt_rand(0, 30),
                ],
            ];
        }

        return [
            'type' => 'incomplete',
            'yards' => 0,
            'details' => [
                'qb' => $qb,
                'target' => $target,
                'defender' => $defender,
                'depth' => $depth,
                'pressured' => $isPressured,
            ],
        ];
    }

    /**
     * Resolve a run play:
     * 1. OL run_block vs DL block_shedding + LB pursuit → line advantage
     * 2. Base YPC + line advantage + RB vision bonus
     * 3. Big play chance from RB break_tackle + juke
     * 4. Fumble check
     */
    private function resolveRunPlay(
        array $offStarters,
        array $defStarters,
        array $defMods,
        array $weatherFx
    ): array {
        $rbs = $this->findAllByPosition($offStarters, 'RB');
        $rb = !empty($rbs) ? $rbs[0] : null;
        if (!$rb) {
            // QB scramble
            $qb = $this->findByPosition($offStarters, 'QB');
            return [
                'type' => 'run',
                'yards' => mt_rand(-2, 8),
                'details' => ['runner' => $qb, 'fumble' => false],
            ];
        }

        // Step 1: Line advantage
        $olRunBlock = 0;
        $ol = array_merge(
            $this->findAllByPosition($offStarters, 'OT'),
            $this->findAllByPosition($offStarters, 'OG'),
            $this->findAllByPosition($offStarters, 'C')
        );
        if (!empty($ol)) {
            foreach ($ol as $o) {
                $olRunBlock += ($o['run_block'] + $o['run_block_power']) / 2;
            }
            $olRunBlock /= count($ol);
        } else {
            $olRunBlock = 50;
        }

        $dlShed = 50;
        $dl = array_merge(
            $this->findAllByPosition($defStarters, 'DE'),
            $this->findAllByPosition($defStarters, 'DT')
        );
        if (!empty($dl)) {
            $sum = 0;
            foreach ($dl as $d) {
                $sum += $d['block_shedding'];
            }
            $dlShed = $sum / count($dl);
        }

        $lbPursuit = 50;
        $lbs = $this->findAllByPosition($defStarters, 'LB');
        if (!empty($lbs)) {
            $sum = 0;
            foreach ($lbs as $lb) {
                $sum += ($lb['pursuit'] + $lb['tackle']) / 2;
            }
            $lbPursuit = $sum / count($lbs);
        }

        $defRunStrength = ($dlShed * 0.5 + $lbPursuit * 0.5) + $defMods['run_def'];
        $lineAdvantage = ($olRunBlock - $defRunStrength) * 0.08;

        // Step 2: Base yards (NFL avg rush: 4.3 YPC, includes negative plays)
        $baseYards = 3.5 + $lineAdvantage + ($rb['bc_vision'] - 50) * 0.03 + $weatherFx['run_boost'] * 0.1;
        $yards = (int) round($baseYards + $this->gaussianRandom(0, 3.0));

        // Tackle for loss chance (~12% of runs in NFL)
        if ($yards <= 0 && mt_rand(1, 100) <= 15) {
            $yards = mt_rand(-3, -1);
        }

        // Step 3: Big play chance (~3% of runs go 10+ yards in NFL)
        $bigPlayChance = 1 + ($rb['break_tackle'] - 50) * 0.05 + ($rb['juke_move'] - 50) * 0.03 + ($rb['speed'] - 50) * 0.03;
        $bigPlayChance = max(1, min(6, $bigPlayChance));

        if (mt_rand(1, 100) <= $bigPlayChance) {
            $yards += mt_rand(6, 20);
        }

        // Prevent extreme negative runs
        $yards = max(-5, $yards);

        // Step 4: Fumble check
        $fumbleChance = 1.5 - ($rb['carrying'] - 50) * 0.04;
        $fumbleChance *= $weatherFx['fumble_mod'];
        $fumbleChance = max(0.3, min(3.5, $fumbleChance));

        $fumbled = mt_rand(1, 1000) <= ($fumbleChance * 10);

        // Find tackler
        $tacklers = array_merge($lbs, $dl,
            $this->findAllByPosition($defStarters, 'CB'),
            $this->findAllByPosition($defStarters, 'S')
        );
        $tackler = !empty($tacklers) ? $tacklers[array_rand($tacklers)] : null;

        return [
            'type' => 'run',
            'yards' => $yards,
            'details' => [
                'runner' => $rb,
                'fumble' => $fumbled,
                'tackler' => $tackler,
                'big_play' => $yards >= 15,
            ],
        ];
    }

    /**
     * Resolve a field goal attempt.
     */
    private function resolveFieldGoal(array $offStarters, int $yardLine, array $weatherFx, array $defStarters = []): array
    {
        $kicker = $this->findByPosition($offStarters, 'K');
        $distance = 100 - $yardLine + 17; // snap + hold distance

        // Block chance: 2% base + (defTeam pass_rush rating - 60) * 0.1%. Max 5%.
        if (!empty($defStarters)) {
            $passRush = $this->passRushRating($defStarters);
            $blockChance = 2 + ($passRush - 60) * 0.1;
            $blockChance = max(0.5, min(5, $blockChance));

            if (mt_rand(1, 1000) <= ($blockChance * 10)) {
                return [
                    'type' => 'field_goal',
                    'made' => false,
                    'blocked' => true,
                    'distance' => $distance,
                    'details' => ['kicker' => $kicker],
                ];
            }
        }

        $baseChance = 95;
        if ($distance > 55) $baseChance = 25;
        elseif ($distance > 50) $baseChance = 45;
        elseif ($distance > 45) $baseChance = 65;
        elseif ($distance > 40) $baseChance = 78;
        elseif ($distance > 35) $baseChance = 85;

        if ($kicker) {
            $baseChance += ($kicker['kick_accuracy'] - 65) * 0.5;
            $baseChance += ($kicker['kick_power'] - 65) * 0.2;
        }

        $baseChance += $weatherFx['kick_accuracy'];
        $baseChance = max(10, min(98, $baseChance));

        $made = mt_rand(1, 100) <= $baseChance;

        return [
            'type' => 'field_goal',
            'made' => $made,
            'blocked' => false,
            'distance' => $distance,
            'details' => ['kicker' => $kicker],
        ];
    }

    // ─── Stat Accumulation ─────────────────────────────────────────────

    private function initPlayerStats(): array
    {
        return [
            'pass_attempts' => 0,
            'pass_completions' => 0,
            'pass_yards' => 0,
            'pass_tds' => 0,
            'interceptions' => 0,
            'rush_attempts' => 0,
            'rush_yards' => 0,
            'rush_tds' => 0,
            'targets' => 0,
            'receptions' => 0,
            'rec_yards' => 0,
            'rec_tds' => 0,
            'tackles' => 0,
            'sacks' => 0,
            'interceptions_def' => 0,
            'forced_fumbles' => 0,
            'fg_attempts' => 0,
            'fg_made' => 0,
            'punt_returns' => 0,
            'kick_returns' => 0,
            'return_yards' => 0,
            'return_tds' => 0,
            'penalties' => 0,
            'penalty_yards' => 0,
        ];
    }

    private function ensurePlayerStats(array &$stats, int $playerId, int $teamId): void
    {
        if (!isset($stats[$playerId])) {
            $stats[$playerId] = $this->initPlayerStats();
            $stats[$playerId]['player_id'] = $playerId;
            $stats[$playerId]['team_id'] = $teamId;
        }
    }

    private function accumulatePassPlay(array &$stats, array $play, int $teamId): void
    {
        $details = $play['details'];
        $qb = $details['qb'] ?? null;

        if (!$qb) return;

        $this->ensurePlayerStats($stats, $qb['id'], $teamId);

        if ($play['type'] === 'sack') {
            // Sacks don't count as pass attempts in NFL stats
            $stats[$qb['id']]['rush_yards'] += $play['yards']; // sack yards go against rush

            if (!empty($details['defender'])) {
                $def = $details['defender'];
                $this->ensurePlayerStats($stats, $def['id'], $def['team_id']);
                $stats[$def['id']]['sacks'] += 1.0;
                $stats[$def['id']]['tackles'] += 1;
            }
            return;
        }

        $stats[$qb['id']]['pass_attempts']++;

        if ($play['type'] === 'completion') {
            $stats[$qb['id']]['pass_completions']++;
            $stats[$qb['id']]['pass_yards'] += $play['yards'];

            $target = $details['target'] ?? null;
            if ($target) {
                $this->ensurePlayerStats($stats, $target['id'], $teamId);
                $stats[$target['id']]['targets']++;
                $stats[$target['id']]['receptions']++;
                $stats[$target['id']]['rec_yards'] += $play['yards'];
            }

            // Tackler credit
            $def = $details['defender'] ?? null;
            if ($def) {
                $this->ensurePlayerStats($stats, $def['id'], $def['team_id']);
                $stats[$def['id']]['tackles']++;
            }
        } elseif ($play['type'] === 'incomplete') {
            $target = $details['target'] ?? null;
            if ($target) {
                $this->ensurePlayerStats($stats, $target['id'], $teamId);
                $stats[$target['id']]['targets']++;
            }
        } elseif ($play['type'] === 'interception') {
            $stats[$qb['id']]['interceptions']++;

            $target = $details['target'] ?? null;
            if ($target) {
                $this->ensurePlayerStats($stats, $target['id'], $teamId);
                $stats[$target['id']]['targets']++;
            }

            $def = $details['defender'] ?? null;
            if ($def) {
                $this->ensurePlayerStats($stats, $def['id'], $def['team_id']);
                $stats[$def['id']]['interceptions_def']++;
            }
        }
    }

    private function accumulateRunPlay(array &$stats, array $play, int $teamId): void
    {
        $runner = $play['details']['runner'] ?? null;
        if (!$runner) return;

        $this->ensurePlayerStats($stats, $runner['id'], $teamId);
        $stats[$runner['id']]['rush_attempts']++;
        $stats[$runner['id']]['rush_yards'] += $play['yards'];

        if ($play['details']['fumble']) {
            // Credit forced fumble to tackler
            $tackler = $play['details']['tackler'] ?? null;
            if ($tackler) {
                $this->ensurePlayerStats($stats, $tackler['id'], $tackler['team_id']);
                $stats[$tackler['id']]['forced_fumbles']++;
                $stats[$tackler['id']]['tackles']++;
            }
        } else {
            // Tackle credit
            $tackler = $play['details']['tackler'] ?? null;
            if ($tackler) {
                $this->ensurePlayerStats($stats, $tackler['id'], $tackler['team_id']);
                $stats[$tackler['id']]['tackles']++;
            }
        }
    }

    private function accumulateFieldGoal(array &$stats, array $play, int $teamId): void
    {
        $kicker = $play['details']['kicker'] ?? null;
        if (!$kicker) return;

        $this->ensurePlayerStats($stats, $kicker['id'], $teamId);
        $stats[$kicker['id']]['fg_attempts']++;
        if ($play['made']) {
            $stats[$kicker['id']]['fg_made']++;
        }
    }

    // ─── Game Log ──────────────────────────────────────────────────────

    /**
     * Determine if a play is a "key play" — highlight-worthy in the game log.
     */
    private function isKeyPlay(array $play, int $yards, array $state, bool $isScoringPlay): bool
    {
        if ($isScoringPlay) return true;
        if ($play['type'] === 'sack') return true;
        if ($play['type'] === 'interception') return true;
        if ($play['details']['fumble'] ?? false) return true;
        if (abs($yards) >= 20) return true;
        if ($state['down'] === 4) return true;
        if ($state['quarter'] >= 4 && $state['clock'] <= 120) return true;
        // 3rd down conversions / failures
        if ($state['down'] === 3) return true;
        return false;
    }

    private function buildLogEntry(array $play, array $state, string $possession, ?string $note = null, bool $keyPlay = false): array
    {
        return [
            'quarter' => $state['quarter'],
            'clock' => $state['clock'],
            'possession' => $possession,
            'yard_line' => $state['yard_line'],
            'down' => $state['down'],
            'distance' => $state['distance'],
            'home_score' => $state['home_score'],
            'away_score' => $state['away_score'],
            'play' => [
                'type' => $play['type'],
                'yards' => $play['yards'] ?? 0,
                'made' => $play['made'] ?? null,
                'distance' => $play['distance'] ?? null,
                'player' => $this->logPlayerName($play['details']['qb'] ?? $play['details']['runner'] ?? $play['details']['kicker'] ?? null),
                'target' => $this->logPlayerName($play['details']['target'] ?? null),
                'defender' => $this->logPlayerName($play['details']['defender'] ?? $play['details']['tackler'] ?? null),
                'depth' => $play['details']['depth'] ?? null,
            ],
            'note' => $note,
            'key_play' => $keyPlay,
        ];
    }

    private function logPlayerName(?array $player): ?string
    {
        if (!$player) return null;
        return ($player['first_name'] ?? '') . ' ' . ($player['last_name'] ?? '');
    }

    // ─── Touchdown / Scoring ───────────────────────────────────────────

    private function scoreTouchdown(array &$state, string $possession): int
    {
        $points = 6;

        // PAT: 94% XP, 4% 2pt attempt (48% success), 2% miss
        $patRoll = mt_rand(1, 100);
        if ($patRoll <= 94) {
            $points += 1;
        } elseif ($patRoll <= 98) {
            if (mt_rand(1, 100) <= 48) {
                $points += 2;
            }
        }

        if ($possession === 'home') {
            $state['home_score'] += $points;
        } else {
            $state['away_score'] += $points;
        }

        return $points;
    }

    private function scoreFieldGoal(array &$state, string $possession): void
    {
        if ($possession === 'home') {
            $state['home_score'] += 3;
        } else {
            $state['away_score'] += 3;
        }
    }

    private function scoreSafety(array &$state, string $possession): void
    {
        // Safety scored by the defense (opponent gets 2 points)
        if ($possession === 'home') {
            $state['away_score'] += 2;
        } else {
            $state['home_score'] += 2;
        }
    }

    // ─── Halftime Adjustments ─────────────────────────────────────────

    /**
     * At halftime, each team's AI adjusts defensive scheme based on 1st half performance.
     */
    private function halftimeAdjustment(
        array $state,
        array $homeStats,
        array $awayStats,
        string &$homeDefScheme,
        string &$awayDefScheme
    ): array {
        $adjustments = ['home_adjustment' => null, 'away_adjustment' => null];

        // Calculate 1st-half passing/rushing yards for each side
        $homePassYards = 0;
        $homeRushYards = 0;
        foreach ($homeStats as $stat) {
            $homePassYards += $stat['pass_yards'] ?? 0;
            $homeRushYards += $stat['rush_yards'] ?? 0;
        }

        $awayPassYards = 0;
        $awayRushYards = 0;
        foreach ($awayStats as $stat) {
            $awayPassYards += $stat['pass_yards'] ?? 0;
            $awayRushYards += $stat['rush_yards'] ?? 0;
        }

        $scoreDiff = $state['home_score'] - $state['away_score'];

        // Home team adjusts defense based on away team's 1st half offense
        if (mt_rand(1, 100) <= 60) {
            $newScheme = null;
            if ($scoreDiff <= -14) {
                // Home losing badly — go aggressive
                if ($homeDefScheme !== 'blitz') {
                    $newScheme = 'blitz';
                    $adjustments['home_adjustment'] = 'Switched to blitz defense (trailing big)';
                }
            } elseif ($scoreDiff >= 14) {
                // Home winning big — protect lead
                if ($homeDefScheme !== 'prevent') {
                    $newScheme = 'prevent';
                    $adjustments['home_adjustment'] = 'Switched to prevent defense (protecting lead)';
                }
            } elseif ($awayPassYards >= 200) {
                if ($homeDefScheme !== 'zone' && $homeDefScheme !== 'prevent') {
                    $newScheme = 'zone';
                    $adjustments['home_adjustment'] = 'Switched to zone defense (opponent passing effectively)';
                }
            } elseif ($awayRushYards >= 100) {
                if ($homeDefScheme !== '34' && $homeDefScheme !== 'blitz') {
                    $newScheme = '34';
                    $adjustments['home_adjustment'] = 'Switched to 3-4 defense (opponent running effectively)';
                }
            }
            if ($newScheme) {
                $homeDefScheme = $newScheme;
            }
        }

        // Away team adjusts defense based on home team's 1st half offense
        if (mt_rand(1, 100) <= 60) {
            $newScheme = null;
            if ($scoreDiff >= 14) {
                // Away losing badly — go aggressive
                if ($awayDefScheme !== 'blitz') {
                    $newScheme = 'blitz';
                    $adjustments['away_adjustment'] = 'Switched to blitz defense (trailing big)';
                }
            } elseif ($scoreDiff <= -14) {
                // Away winning big — protect lead
                if ($awayDefScheme !== 'prevent') {
                    $newScheme = 'prevent';
                    $adjustments['away_adjustment'] = 'Switched to prevent defense (protecting lead)';
                }
            } elseif ($homePassYards >= 200) {
                if ($awayDefScheme !== 'zone' && $awayDefScheme !== 'prevent') {
                    $newScheme = 'zone';
                    $adjustments['away_adjustment'] = 'Switched to zone defense (opponent passing effectively)';
                }
            } elseif ($homeRushYards >= 100) {
                if ($awayDefScheme !== '34' && $awayDefScheme !== 'blitz') {
                    $newScheme = '34';
                    $adjustments['away_adjustment'] = 'Switched to 3-4 defense (opponent running effectively)';
                }
            }
            if ($newScheme) {
                $awayDefScheme = $newScheme;
            }
        }

        return $adjustments;
    }

    // ─── Penalty System ─────────────────────────────────────────────────

    /**
     * Check for a penalty after a play is resolved.
     * Returns null if no penalty, or a penalty array with type/yards/details.
     * ~12% of plays draw a flag (NFL average 11-13%).
     */
    private function resolvePenalty(
        array $state,
        string $possession,
        array $offStarters,
        array $defStarters,
        array $play
    ): ?array {
        if (mt_rand(1, 1000) > 120) {
            return null;
        }

        $playType = $play['type'] ?? 'run';
        $isPassPlay = in_array($playType, ['completion', 'incomplete', 'sack', 'interception']);
        $isPressured = $play['details']['pressured'] ?? false;
        $isSack = $playType === 'sack';

        $penalties = [];

        // ── Offensive penalties ──

        // False start: 5 yards, replay down. More likely with low-awareness OL.
        $olAwareness = 50;
        $ol = array_merge(
            $this->findAllByPosition($offStarters, 'OT'),
            $this->findAllByPosition($offStarters, 'OG'),
            $this->findAllByPosition($offStarters, 'C')
        );
        if (!empty($ol)) {
            $sum = 0;
            foreach ($ol as $o) {
                $sum += $o['awareness'] ?? 50;
            }
            $olAwareness = $sum / count($ol);
        }
        $falseStartWeight = (int)(13 + (50 - $olAwareness) * 0.2);
        $penalties['false_start'] = max(4, min(22, $falseStartWeight));

        // Holding (offense): 10 yards, replay down. Worse OL vs DL = more holding.
        $passBlockAvg = $this->passBlockRating($offStarters);
        $passRushAvg = $this->passRushRating($defStarters);
        $holdingWeight = (int)(14 + ($passRushAvg - $passBlockAvg) * 0.3);
        $penalties['holding_offense'] = max(6, min(25, $holdingWeight));

        // Illegal formation: 5 yards, replay down. Flat chance.
        $penalties['illegal_formation'] = 7;

        // Intentional grounding: loss of down. Only on pass plays under pressure.
        if ($isPassPlay && $isPressured) {
            $penalties['intentional_grounding'] = 6;
        }

        // Offensive pass interference: 10 yards, replay down. Only on pass plays.
        if ($isPassPlay) {
            $penalties['offensive_pass_interference'] = 4;
        }

        // ── Defensive penalties ──

        // DPI: spot foul, auto first down. Only on pass plays (not sacks).
        if ($isPassPlay && !$isSack) {
            $cbCoverage = 50;
            $cbs = $this->findAllByPosition($defStarters, 'CB');
            if (!empty($cbs)) {
                $sum = 0;
                foreach ($cbs as $cb) {
                    $sum += ($cb['man_coverage'] + $cb['zone_coverage']) / 2;
                }
                $cbCoverage = $sum / count($cbs);
            }
            $wrRoutes = 50;
            $wrs = array_merge(
                $this->findAllByPosition($offStarters, 'WR'),
                $this->findAllByPosition($offStarters, 'TE')
            );
            if (!empty($wrs)) {
                $sum = 0;
                foreach ($wrs as $wr) {
                    $sum += ($wr['short_route_running'] + $wr['medium_route_running'] + $wr['deep_route_running']) / 3;
                }
                $wrRoutes = $sum / count($wrs);
            }
            $dpiWeight = (int)(12 + ($wrRoutes - $cbCoverage) * 0.25);
            $penalties['defensive_pass_interference'] = max(5, min(22, $dpiWeight));
        }

        // Roughing the passer: 15 yards, auto first down. Only on sacks (~8% of sacks).
        if ($isSack) {
            $penalties['roughing_the_passer'] = 18;
        }

        // Defensive holding: 5 yards, auto first down. On pass plays.
        if ($isPassPlay) {
            $penalties['defensive_holding'] = 10;
        }

        // Encroachment: 5 yards, replay down. Flat chance.
        $penalties['encroachment'] = 7;

        // Unnecessary roughness: 15 yards, auto first down. Rare.
        $penalties['unnecessary_roughness'] = 3;

        $penaltyType = $this->weightedRandom($penalties);

        return $this->buildPenaltyResult($penaltyType, $state, $possession, $offStarters, $defStarters, $play);
    }

    /**
     * Build penalty result with yardage, description, and game state effects.
     */
    private function buildPenaltyResult(
        string $penaltyType,
        array $state,
        string $possession,
        array $offStarters,
        array $defStarters,
        array $play
    ): array {
        $isOffensivePenalty = in_array($penaltyType, [
            'false_start', 'holding_offense', 'illegal_formation',
            'intentional_grounding', 'offensive_pass_interference',
        ]);

        $player = null;
        $yards = 0;
        $replayDown = false;
        $lossOfDown = false;
        $autoFirstDown = false;
        $spotFoul = false;
        $spotYardLine = null;

        switch ($penaltyType) {
            case 'false_start':
                $yards = 5;
                $replayDown = true;
                $ol = array_merge(
                    $this->findAllByPosition($offStarters, 'OT'),
                    $this->findAllByPosition($offStarters, 'OG'),
                    $this->findAllByPosition($offStarters, 'C')
                );
                $player = !empty($ol) ? $ol[array_rand($ol)] : null;
                break;

            case 'holding_offense':
                $yards = 10;
                $replayDown = true;
                $ol = array_merge(
                    $this->findAllByPosition($offStarters, 'OT'),
                    $this->findAllByPosition($offStarters, 'OG'),
                    $this->findAllByPosition($offStarters, 'C')
                );
                $player = !empty($ol) ? $ol[array_rand($ol)] : null;
                break;

            case 'illegal_formation':
                $yards = 5;
                $replayDown = true;
                break;

            case 'intentional_grounding':
                $yards = 8;
                $lossOfDown = true;
                $player = $this->findByPosition($offStarters, 'QB');
                break;

            case 'offensive_pass_interference':
                $yards = 10;
                $replayDown = true;
                $wrs = array_merge(
                    $this->findAllByPosition($offStarters, 'WR'),
                    $this->findAllByPosition($offStarters, 'TE')
                );
                $player = !empty($wrs) ? $wrs[array_rand($wrs)] : null;
                break;

            case 'defensive_pass_interference':
                $autoFirstDown = true;
                $spotFoul = true;
                $depth = $play['details']['depth'] ?? 'mid';
                $spotGain = match ($depth) {
                    'short' => mt_rand(4, 10),
                    'mid' => mt_rand(10, 20),
                    'deep' => mt_rand(20, 45),
                    default => mt_rand(8, 18),
                };
                $spotYardLine = min(99, $state['yard_line'] + $spotGain);
                $yards = $spotYardLine - $state['yard_line'];
                $dbs = array_merge(
                    $this->findAllByPosition($defStarters, 'CB'),
                    $this->findAllByPosition($defStarters, 'S')
                );
                $player = !empty($dbs) ? $dbs[array_rand($dbs)] : null;
                break;

            case 'roughing_the_passer':
                $yards = 15;
                $autoFirstDown = true;
                $rushers = array_merge(
                    $this->findAllByPosition($defStarters, 'DE'),
                    $this->findAllByPosition($defStarters, 'DT'),
                    $this->findAllByPosition($defStarters, 'LB')
                );
                $player = !empty($rushers) ? $rushers[array_rand($rushers)] : null;
                break;

            case 'defensive_holding':
                $yards = 5;
                $autoFirstDown = true;
                $dbs = array_merge(
                    $this->findAllByPosition($defStarters, 'CB'),
                    $this->findAllByPosition($defStarters, 'S')
                );
                $player = !empty($dbs) ? $dbs[array_rand($dbs)] : null;
                break;

            case 'encroachment':
                $yards = 5;
                $replayDown = true;
                $dl = array_merge(
                    $this->findAllByPosition($defStarters, 'DE'),
                    $this->findAllByPosition($defStarters, 'DT')
                );
                $player = !empty($dl) ? $dl[array_rand($dl)] : null;
                break;

            case 'unnecessary_roughness':
                $yards = 15;
                $autoFirstDown = true;
                $allDef = array_merge(
                    $this->findAllByPosition($defStarters, 'DE'),
                    $this->findAllByPosition($defStarters, 'DT'),
                    $this->findAllByPosition($defStarters, 'LB'),
                    $this->findAllByPosition($defStarters, 'CB'),
                    $this->findAllByPosition($defStarters, 'S')
                );
                $player = !empty($allDef) ? $allDef[array_rand($allDef)] : null;
                break;
        }

        $penaltyName = ucwords(str_replace('_', ' ', $penaltyType));
        $playerDesc = '';
        if ($player) {
            $number = $player['jersey_number'] ?? '?';
            $firstName = $player['first_name'] ?? '';
            $lastName = $player['last_name'] ?? '';
            $pos = $player['position'] ?? '';
            $playerDesc = " on #{$number} {$firstName} {$lastName} ({$pos})";
        }
        $description = "{$penaltyName}{$playerDesc} — {$yards} yards";

        return [
            'penalty_type' => $penaltyType,
            'is_offensive' => $isOffensivePenalty,
            'yards' => $yards,
            'replay_down' => $replayDown,
            'loss_of_down' => $lossOfDown,
            'auto_first_down' => $autoFirstDown,
            'spot_foul' => $spotFoul,
            'spot_yard_line' => $spotYardLine,
            'player' => $player,
            'description' => $description,
        ];
    }

    /**
     * Apply penalty yardage with half-the-distance-to-the-goal rule.
     */
    private function applyPenaltyYardage(array &$state, array $penalty): void
    {
        if ($penalty['is_offensive']) {
            $penaltyYards = $penalty['yards'];
            if ($penaltyYards >= $state['yard_line']) {
                $penaltyYards = max(1, (int)floor($state['yard_line'] / 2));
            }
            $state['yard_line'] -= $penaltyYards;
            $state['yard_line'] = max(1, $state['yard_line']);
        } else {
            if ($penalty['spot_foul'] && $penalty['spot_yard_line'] !== null) {
                $state['yard_line'] = min(99, $penalty['spot_yard_line']);
            } else {
                $penaltyYards = $penalty['yards'];
                $remaining = 100 - $state['yard_line'];
                if ($penaltyYards >= $remaining) {
                    $penaltyYards = max(1, (int)floor($remaining / 2));
                }
                $state['yard_line'] += $penaltyYards;
                $state['yard_line'] = min(99, $state['yard_line']);
            }
        }

        if ($penalty['auto_first_down']) {
            $state['down'] = 1;
            $state['distance'] = min(10, 100 - $state['yard_line']);
        } elseif ($penalty['replay_down']) {
            if ($penalty['is_offensive']) {
                $state['distance'] += $penalty['yards'];
            } else {
                $newDist = $state['distance'] - $penalty['yards'];
                if ($newDist <= 0) {
                    $state['down'] = 1;
                    $state['distance'] = min(10, 100 - $state['yard_line']);
                } else {
                    $state['distance'] = $newDist;
                }
            }
        } elseif ($penalty['loss_of_down']) {
            $state['down']++;
            if ($penalty['is_offensive']) {
                $state['distance'] += $penalty['yards'];
            }
        }
    }


    // ─── Fatigue / Stamina ──────────────────────────────────────────────

    /**
     * Returns a modifier (0.90-1.00) that reduces player effectiveness as the game wears on.
     */
    private function getFatigueModifier(int $quarter, int $playsRun, string $offenseScheme): float
    {
        $baseFatigue = match (true) {
            $quarter <= 1 => 1.00,
            $quarter === 2 => 0.985,
            $quarter === 3 => 0.97,
            $quarter === 4 => 0.95,
            default        => 0.925,
        };

        $schemePenalty = 0.0;
        if ($offenseScheme === 'no_huddle') {
            $schemePenalty = 0.005 * min($quarter, 5);
        }

        $playFatigue = min(0.02, $playsRun / 10000.0);
        $modifier = $baseFatigue - $schemePenalty - $playFatigue;

        return max(0.90, min(1.00, $modifier));
    }

    /**
     * Apply fatigue modifier to a set of unit ratings.
     */
    private function applyFatigue(array $units, float $fatigueMod): array
    {
        $fatigued = [];
        foreach ($units as $key => $value) {
            $fatigued[$key] = $value * $fatigueMod;
        }
        return $fatigued;
    }

    // ─── In-Game Injuries ─────────────────────────────────────────────

    /**
     * After each play, check whether any involved player suffered an injury.
     */
    private function checkInGameInjury(array $play, array &$state, string $possession): ?array
    {
        $involved = [];
        $details = $play['details'] ?? [];

        if (!empty($details['qb']))       $involved[] = $details['qb'];
        if (!empty($details['runner']))    $involved[] = $details['runner'];
        if (!empty($details['target']))    $involved[] = $details['target'];
        if (!empty($details['defender']))  $involved[] = $details['defender'];
        if (!empty($details['tackler']))   $involved[] = $details['tackler'];

        $seen = [];
        $unique = [];
        foreach ($involved as $p) {
            $pid = $p['id'] ?? null;
            if ($pid && !isset($seen[$pid])) {
                $seen[$pid] = true;
                $unique[] = $p;
            }
        }

        $riskMod = 1.0;
        if ($play['type'] === 'sack') {
            $riskMod = 1.8;
        } elseif ($play['type'] === 'run' && ($play['details']['big_play'] ?? false)) {
            $riskMod = 1.4;
        } elseif ($play['type'] === 'completion' && ($details['depth'] ?? '') === 'deep') {
            $riskMod = 1.3;
        }

        foreach ($unique as $player) {
            $pid = $player['id'];

            if (in_array($pid, $state['injured_player_ids'])) {
                continue;
            }

            $injuryProne = $player['injury_prone'] ?? 50;
            $chance = (0.003 + ($injuryProne / 5000)) * $riskMod;

            if (mt_rand(1, 100000) / 100000.0 < $chance) {
                $types = match ($player['position'] ?? 'LB') {
                    'QB' => ['shoulder', 'knee', 'ankle', 'hand', 'ribs'],
                    'RB' => ['knee', 'ankle', 'hamstring', 'shoulder', 'ribs'],
                    'WR', 'TE' => ['hamstring', 'knee', 'ankle', 'shoulder', 'hand'],
                    'OT', 'OG', 'C' => ['knee', 'ankle', 'shoulder', 'back', 'elbow'],
                    default => ['knee', 'hamstring', 'ankle', 'shoulder', 'concussion'],
                };

                $injuryType = $types[array_rand($types)];

                $sevRoll = mt_rand(1, 100);
                if ($sevRoll <= 60) {
                    $severity = 'questionable';
                    $weeksRemaining = 0;
                } elseif ($sevRoll <= 90) {
                    $severity = 'out';
                    $weeksRemaining = 1;
                } else {
                    $severity = 'serious';
                    $weeksRemaining = mt_rand(2, 6);
                }

                $playerName = trim(($player['first_name'] ?? '') . ' ' . ($player['last_name'] ?? ''));
                $position = $player['position'] ?? '??';

                $severityLabel = match ($severity) {
                    'questionable' => 'Questionable to return',
                    'out' => 'Out for the game',
                    'serious' => 'Serious — carted off the field',
                    default => ucfirst($severity),
                };

                $ovr = (int) ($player['overall_rating'] ?? 50);
                $isStar = $ovr >= 82;

                $logMsg = "INJURY: {$position} {$playerName} ({$injuryType}) — {$severityLabel}";

                $injury = [
                    'player_id' => $pid,
                    'team_id' => $player['team_id'],
                    'type' => $injuryType,
                    'severity' => $severity,
                    'weeks_remaining' => $weeksRemaining,
                    'occurred_at' => date('Y-m-d H:i:s'),
                    'in_game' => true,
                    'quarter' => $state['quarter'],
                    'is_star' => $isStar,
                    'overall_rating' => $ovr,
                    'log_message' => $logMsg,
                ];

                $state['in_game_injuries'][] = $injury;
                $state['injured_player_ids'][] = $pid;

                if ($severity === 'questionable') {
                    $state['questionable_returns'][$pid] = 0;
                }

                return $injury;
            }
        }

        return null;
    }

    /**
     * Promote a backup when a starter goes down mid-game.
     */
    private function promoteBackup(array &$teamData, int $injuredPlayerId, array $injuredPlayerIds): ?array
    {
        $injuredPlayer = null;
        $injuredIndex = null;

        foreach ($teamData['starters'] as $i => $player) {
            if ($player['id'] === $injuredPlayerId) {
                $injuredPlayer = $player;
                $injuredIndex = $i;
                break;
            }
        }

        if ($injuredPlayer === null) {
            return null;
        }

        $position = $injuredPlayer['position'];
        $starterIds = array_column($teamData['starters'], 'id');

        $backup = null;
        foreach ($teamData['all_players'] as $player) {
            if ($player['position'] === $position
                && !in_array($player['id'], $starterIds)
                && !in_array($player['id'], $injuredPlayerIds)
                && $player['id'] !== $injuredPlayerId
            ) {
                $backup = $player;
                break;
            }
        }

        if ($backup) {
            $teamData['starters'][$injuredIndex] = $backup;
            return $backup;
        }

        array_splice($teamData['starters'], $injuredIndex, 1);
        return null;
    }

    /**
     * Check if any questionable players can return (50% chance after 2 drives).
     */
    private function checkQuestionableReturns(array &$state, array &$homeTeam, array &$awayTeam): void
    {
        foreach ($state['questionable_returns'] as $pid => $drivesMissed) {
            $state['questionable_returns'][$pid] = $drivesMissed + 1;

            if ($drivesMissed + 1 >= 2 && mt_rand(1, 100) <= 50) {
                $restored = false;
                foreach ([$homeTeam, $awayTeam] as &$team) {
                    foreach ($team['all_players'] as $player) {
                        if ($player['id'] === $pid) {
                            $team['starters'][] = $player;
                            $state['injured_player_ids'] = array_values(
                                array_filter($state['injured_player_ids'], fn($id) => $id !== $pid)
                            );
                            unset($state['questionable_returns'][$pid]);
                            $restored = true;
                            break;
                        }
                    }
                    if ($restored) break;
                }
                unset($team);
            }
        }
    }

    // ─── Main Simulation Loop ──────────────────────────────────────────

    /**
     * Simulate a single game. Returns full result data.
     */
    public function simulateGame(array $game): array
    {
        $homeTeam = $this->getTeamData($game['home_team_id']);
        $awayTeam = $this->getTeamData($game['away_team_id']);

        $homePlan = json_decode($game['home_game_plan'] ?? '{}', true) ?: $this->defaultGamePlan();
        $awayPlan = json_decode($game['away_game_plan'] ?? '{}', true) ?: $this->defaultGamePlan();

        $weather = $game['weather'] ?? 'clear';
        $weatherFx = $this->getWeatherEffects($weather);

        // Compute unit ratings
        $homeUnits = [
            'pass_offense' => $this->passOffenseRating($homeTeam['starters']),
            'run_offense' => $this->runOffenseRating($homeTeam['starters']),
            'pass_block' => $this->passBlockRating($homeTeam['starters']),
            'pass_defense' => $this->passDefenseRating($homeTeam['starters']),
            'run_defense' => $this->runDefenseRating($homeTeam['starters']),
            'pass_rush' => $this->passRushRating($homeTeam['starters']),
        ];
        $awayUnits = [
            'pass_offense' => $this->passOffenseRating($awayTeam['starters']),
            'run_offense' => $this->runOffenseRating($awayTeam['starters']),
            'pass_block' => $this->passBlockRating($awayTeam['starters']),
            'pass_defense' => $this->passDefenseRating($awayTeam['starters']),
            'run_defense' => $this->runDefenseRating($awayTeam['starters']),
            'pass_rush' => $this->passRushRating($awayTeam['starters']),
        ];

        // Defense scheme modifiers — track scheme names for halftime adjustments
        $homeDefScheme = $homePlan['defense'] ?? 'base_43';
        $awayDefScheme = $awayPlan['defense'] ?? 'base_43';
        $homeDefMods = $this->getDefenseModifiers($homeDefScheme);
        $awayDefMods = $this->getDefenseModifiers($awayDefScheme);

        // Home field advantage: small boost to home pass_offense/run_offense
        $hfa = $homeTeam['team']['home_field_advantage'] ?? 3;

        // Morale modifier
        $homeMorale = $this->moraleModifier($homeTeam['team']['morale'] ?? 50);
        $awayMorale = $this->moraleModifier($awayTeam['team']['morale'] ?? 50);

        // Initialize game state
        $state = $this->initGameState();
        $gameLog = [];
        // Single unified stats array — split into home/away at the end by team_id
        $allStats = [];

        // Coin toss: away team receives first (home defers)
        $state['possession'] = 'away';
        $this->kickoff($state, $homeTeam, $awayTeam, $allStats, $gameLog);

        $prevHomeScore = 0;
        $prevAwayScore = 0;
        $leadChanges = 0;
        $halftimeProcessed = false;

        // Play-by-play loop
        $maxPlays = 200; // safety valve
        while (!$this->isGameOver($state) && $state['plays_run'] < $maxPlays) {
            $possession = $state['possession'];
            $isHome = $possession === 'home';

            $offTeam = $isHome ? $homeTeam : $awayTeam;
            $defTeam = $isHome ? $awayTeam : $homeTeam;
            $offPlan = $isHome ? $homePlan : $awayPlan;
            $defMods = $isHome ? $awayDefMods : $homeDefMods;
            $teamId = $offTeam['team']['id'];
            $defTeamId = $defTeam['team']['id'];

            // Detect hurry-up mode
            $offScheme = $offPlan['offense'] ?? 'balanced';
            $isTwoMinute = $state['clock'] <= 120 && ($state['quarter'] === 2 || $state['quarter'] === 4);
            $hurryUp = $isTwoMinute || ($offScheme === 'no_huddle' && $state['clock'] <= 180);

            // Apply fatigue modifier based on quarter and plays run
            $offFatigue = $this->getFatigueModifier($state['quarter'], $state['plays_run'], $offScheme);
            $defSchemeForFatigue = ($isHome ? $awayPlan : $homePlan)['offense'] ?? 'balanced';
            $defFatigue = $this->getFatigueModifier($state['quarter'], $state['plays_run'], $defSchemeForFatigue);

            // Recalculate unit ratings with fatigue (starters may have changed from injuries)
            $offUnits = $this->applyFatigue([
                'pass_offense' => $this->passOffenseRating($offTeam['starters']),
                'run_offense' => $this->runOffenseRating($offTeam['starters']),
                'pass_block' => $this->passBlockRating($offTeam['starters']),
            ], $offFatigue);
            $defUnits = $this->applyFatigue([
                'pass_defense' => $this->passDefenseRating($defTeam['starters']),
                'run_defense' => $this->runDefenseRating($defTeam['starters']),
                'pass_rush' => $this->passRushRating($defTeam['starters']),
            ], $defFatigue);

            // Check if any questionable players can return at possession changes
            if ($state['plays_run'] > 0 && !empty($state['questionable_returns'])) {
                $this->checkQuestionableReturns($state, $homeTeam, $awayTeam);
            }

            // Select play type
            $playCall = $this->selectPlayType($offPlan, $state, $possession);

            $play = null;
            $yards = 0;

            // Apply fatigue: tired offense means defense gets a boost, and vice versa
            // Fatigue increases effective pressure (tired OL) and reduces pass accuracy (tired QB)
            $fatiguedDefMods = $defMods;
            $fatiguedWeatherFx = $weatherFx;
            if ($offFatigue < 1.0) {
                // Offense fatigue: defense gets pressure bonus, pass accuracy penalty
                $offTiredness = (1.0 - $offFatigue) * 100; // 0-10 scale
                $fatiguedDefMods['pressure'] += (int)round($offTiredness * 1.5);
                $fatiguedWeatherFx['pass_accuracy'] -= (int)round($offTiredness * 0.8);
                $fatiguedWeatherFx['run_boost'] -= $offTiredness * 0.3;
            }
            if ($defFatigue < 1.0) {
                // Defense fatigue: reduces their effectiveness
                $defTiredness = (1.0 - $defFatigue) * 100;
                $fatiguedDefMods['pressure'] -= (int)round($defTiredness * 1.0);
                $fatiguedDefMods['man'] -= (int)round($defTiredness * 0.8);
                $fatiguedDefMods['zone'] -= (int)round($defTiredness * 0.8);
                $fatiguedDefMods['run_def'] -= (int)round($defTiredness * 1.0);
            }

            if ($playCall === 'pass') {
                $play = $this->resolvePassPlay(
                    $offTeam['starters'],
                    $defTeam['starters'],
                    $state,
                    $fatiguedDefMods,
                    $fatiguedWeatherFx
                );
                $yards = $play['yards'];
                $this->accumulatePassPlay($allStats, $play, $teamId);
            } else {
                $play = $this->resolveRunPlay(
                    $offTeam['starters'],
                    $defTeam['starters'],
                    $fatiguedDefMods,
                    $fatiguedWeatherFx
                );
                $yards = $play['yards'];
                $this->accumulateRunPlay($allStats, $play, $teamId);
            }

            $state['plays_run']++;

            // Check for in-game injuries after each play
            $inGameInjury = $this->checkInGameInjury($play, $state, $possession);
            if ($inGameInjury) {
                $injuredTeamRef = ($inGameInjury['team_id'] == $homeTeam['team']['id']) ? 'home' : 'away';

                // Log the injury
                $gameLog[] = [
                    'quarter' => $state['quarter'], 'clock' => $state['clock'],
                    'possession' => $possession, 'yard_line' => $state['yard_line'],
                    'down' => $state['down'], 'distance' => $state['distance'],
                    'home_score' => $state['home_score'], 'away_score' => $state['away_score'],
                    'play' => ['type' => 'injury', 'yards' => 0, 'made' => null, 'distance' => null,
                        'player' => null, 'target' => null, 'defender' => null, 'depth' => null],
                    'note' => $inGameInjury['log_message'],
                ];

                // If out or serious, promote backup and recalculate starters
                if ($inGameInjury['severity'] !== 'questionable') {
                    if ($injuredTeamRef === 'home') {
                        $backup = $this->promoteBackup($homeTeam, $inGameInjury['player_id'], $state['injured_player_ids']);
                    } else {
                        $backup = $this->promoteBackup($awayTeam, $inGameInjury['player_id'], $state['injured_player_ids']);
                    }
                    if ($backup) {
                        $backupName = trim(($backup['first_name'] ?? '') . ' ' . ($backup['last_name'] ?? ''));
                        $gameLog[] = [
                            'quarter' => $state['quarter'], 'clock' => $state['clock'],
                            'possession' => $possession, 'yard_line' => $state['yard_line'],
                            'down' => $state['down'], 'distance' => $state['distance'],
                            'home_score' => $state['home_score'], 'away_score' => $state['away_score'],
                            'play' => ['type' => 'substitution', 'yards' => 0, 'made' => null, 'distance' => null,
                                'player' => $backupName, 'target' => null, 'defender' => null, 'depth' => null],
                            'note' => "{$backup['position']} {$backupName} enters the game",
                        ];
                    }
                }
            }

            // ── Penalty check ──
            $prePlayYardLine = $state['yard_line']; // LOS — yard_line has not been modified yet
            $penalty = $this->resolvePenalty(
                $state, $possession,
                $offTeam['starters'], $defTeam['starters'],
                $play
            );

            if ($penalty !== null) {
                // Track penalty stats for the penalized player
                if ($penalty['player']) {
                    $penaltyPlayerId = $penalty['player']['id'];
                    $penaltyTeamId = $penalty['player']['team_id'];
                    $this->ensurePlayerStats($allStats, $penaltyPlayerId, $penaltyTeamId);
                    $allStats[$penaltyPlayerId]['penalties']++;
                    $allStats[$penaltyPlayerId]['penalty_yards'] += $penalty['yards'];
                }

                if ($penalty['is_offensive']) {
                    // Offensive penalty: negate the play result, apply penalty from LOS
                    $state['yard_line'] = max(1, $prePlayYardLine); // revert to LOS
                    $this->applyPenaltyYardage($state, $penalty);
                    $this->advanceClock($state, 'penalty');

                    // Build a dummy play for the log entry
                    $penaltyPlay = ['type' => 'penalty', 'yards' => 0, 'details' => $play['details']];
                    $gameLog[] = $this->buildLogEntry($penaltyPlay, $state, $possession, 'PENALTY: ' . $penalty['description'], true);

                    // Handle turnover on downs after loss-of-down penalty
                    if ($state['down'] > 4) {
                        $this->switchPossession($state);
                        $state['yard_line'] = max(1, min(99, 100 - $state['yard_line']));
                        $state['down'] = 1;
                        $state['distance'] = 10;
                    }
                    continue;
                } else {
                    // Defensive penalty: offense can decline if the play gained more
                    $penaltyYardGain = $penalty['yards'];
                    if ($penalty['spot_foul'] && $penalty['spot_yard_line'] !== null) {
                        $penaltyYardGain = $penalty['spot_yard_line'] - $prePlayYardLine;
                    }

                    // Auto-decline if the play result is better than the penalty
                    // (and the play is not a turnover)
                    $isTurnover = ($play['type'] === 'interception' || ($play['details']['fumble'] ?? false));
                    $playGainedMore = ($yards > $penaltyYardGain) && !$isTurnover;

                    if (!$playGainedMore) {
                        // Accept the penalty: revert to LOS, apply penalty yardage
                        $state['yard_line'] = max(1, $prePlayYardLine);
                        $this->applyPenaltyYardage($state, $penalty);
                        $this->advanceClock($state, 'penalty');

                        $penaltyPlay = ['type' => 'penalty', 'yards' => 0, 'details' => $play['details']];
                        $gameLog[] = $this->buildLogEntry($penaltyPlay, $state, $possession, 'PENALTY: ' . $penalty['description'], true);
                        continue;
                    }
                    // Otherwise: penalty declined, play stands — fall through to normal processing
                }
            }
            $isScoringPlay = false;
            $logNote = null;

            // Handle turnovers
            if ($play['type'] === 'interception') {
                $this->advanceClock($state, 'pass_incomplete', $hurryUp);

                // Halftime adjustment check after clock advance
                if ($state['quarter'] === 3 && !$halftimeProcessed) {
                    $halfHomeStats = [];
                    $halfAwayStats = [];
                    $homeTeamId_tmp = (int)$homeTeam['team']['id'];
                    foreach ($allStats as $pid => $st) {
                        if ((int)($st['team_id'] ?? 0) === $homeTeamId_tmp) {
                            $halfHomeStats[$pid] = $st;
                        } else {
                            $halfAwayStats[$pid] = $st;
                        }
                    }
                    $htAdj = $this->halftimeAdjustment($state, $halfHomeStats, $halfAwayStats, $homeDefScheme, $awayDefScheme);
                    $homeDefMods = $this->getDefenseModifiers($homeDefScheme);
                    $awayDefMods = $this->getDefenseModifiers($awayDefScheme);
                    if ($htAdj['home_adjustment']) {
                        $gameLog[] = [
                            'quarter' => 3, 'clock' => 900, 'possession' => 'home',
                            'yard_line' => $state['yard_line'], 'down' => $state['down'], 'distance' => $state['distance'],
                            'home_score' => $state['home_score'], 'away_score' => $state['away_score'],
                            'play' => ['type' => 'halftime_adjustment', 'yards' => 0, 'made' => null, 'distance' => null,
                                'player' => null, 'target' => null, 'defender' => null, 'depth' => null],
                            'note' => 'Home: ' . $htAdj['home_adjustment'],
                        ];
                    }
                    if ($htAdj['away_adjustment']) {
                        $gameLog[] = [
                            'quarter' => 3, 'clock' => 900, 'possession' => 'away',
                            'yard_line' => $state['yard_line'], 'down' => $state['down'], 'distance' => $state['distance'],
                            'home_score' => $state['home_score'], 'away_score' => $state['away_score'],
                            'play' => ['type' => 'halftime_adjustment', 'yards' => 0, 'made' => null, 'distance' => null,
                                'player' => null, 'target' => null, 'defender' => null, 'depth' => null],
                            'note' => 'Away: ' . $htAdj['away_adjustment'],
                        ];
                    }
                    $halftimeProcessed = true;
                }

                $intReturn = $play['details']['int_return'] ?? 0;
                // Log before turnover
                $gameLog[] = $this->buildLogEntry($play, $state, $possession, 'Interception', true);
                $this->switchPossession($state);
                $state['yard_line'] = max(1, min(99, 100 - $state['yard_line'] + $intReturn));
                $state['down'] = 1;
                $state['distance'] = 10;
                continue;
            }

            if ($play['details']['fumble'] ?? false) {
                $this->advanceClock($state, 'run', $hurryUp);
                $gameLog[] = $this->buildLogEntry($play, $state, $possession, 'Fumble lost', true);
                // Fumble recovery: defense recovers ~50% of the time
                if (mt_rand(1, 100) <= 50) {
                    $state['yard_line'] += $yards;
                    $this->switchPossession($state);
                    $state['yard_line'] = max(1, min(99, 100 - $state['yard_line']));
                    $state['down'] = 1;
                    $state['distance'] = 10;
                    continue;
                }
                // Offense recovers, still loses yards but keeps ball
                $state['yard_line'] = max(1, $state['yard_line'] + max(0, $yards - 3));
            } else {
                // Normal play — advance field position
                $state['yard_line'] += $yards;
            }

            // Safety check
            if ($state['yard_line'] <= 0) {
                $this->scoreSafety($state, $possession);
                $isScoringPlay = true;
                $logNote = 'Safety';
                $gameLog[] = $this->buildLogEntry($play, $state, $possession, $logNote, true);
                $this->advanceClock($state, 'run', $hurryUp);
                // After safety: scoring team kicks off (free kick)
                $this->switchPossession($state);
                $kickingTeamSafety = $state['possession'] === 'home' ? $awayTeam : $homeTeam;
                $receivingTeamSafety = $state['possession'] === 'home' ? $homeTeam : $awayTeam;
                $this->kickoff($state, $kickingTeamSafety, $receivingTeamSafety, $allStats, $gameLog);
                $this->switchPossession($state); // back to team that got safetied, who now kicks
                // Actually after safety the team that scored the safety receives a free kick
                // The team that was safetied punts from their 20
                $state['yard_line'] = 25;
                $state['down'] = 1;
                $state['distance'] = 10;
                continue;
            }

            // Touchdown check
            if ($state['yard_line'] >= 100) {
                $isScoringPlay = true;
                $tdPoints = $this->scoreTouchdown($state, $possession);

                // Credit the TD
                if ($playCall === 'pass' && $play['type'] === 'completion') {
                    $qb = $play['details']['qb'] ?? null;
                    $target = $play['details']['target'] ?? null;
                    if ($qb) {
                        $this->ensurePlayerStats($allStats, $qb['id'], $teamId);
                        $allStats[$qb['id']]['pass_tds']++;
                    }
                    if ($target) {
                        $this->ensurePlayerStats($allStats, $target['id'], $teamId);
                        $allStats[$target['id']]['rec_tds']++;
                    }
                    $logNote = 'Passing touchdown';
                } else {
                    $runner = $play['details']['runner'] ?? null;
                    if ($runner) {
                        $this->ensurePlayerStats($allStats, $runner['id'], $teamId);
                        $allStats[$runner['id']]['rush_tds']++;
                    }
                    $logNote = 'Rushing touchdown';
                }

                $gameLog[] = $this->buildLogEntry($play, $state, $possession, $logNote, true);
                $this->advanceClock($state, 'touchdown', $hurryUp);

                // Halftime adjustment check after clock advance
                if ($state['quarter'] === 3 && !$halftimeProcessed) {
                    $halfHomeStats = [];
                    $halfAwayStats = [];
                    $homeTeamId_tmp = (int)$homeTeam['team']['id'];
                    foreach ($allStats as $pid => $st) {
                        if ((int)($st['team_id'] ?? 0) === $homeTeamId_tmp) {
                            $halfHomeStats[$pid] = $st;
                        } else {
                            $halfAwayStats[$pid] = $st;
                        }
                    }
                    $htAdj = $this->halftimeAdjustment($state, $halfHomeStats, $halfAwayStats, $homeDefScheme, $awayDefScheme);
                    $homeDefMods = $this->getDefenseModifiers($homeDefScheme);
                    $awayDefMods = $this->getDefenseModifiers($awayDefScheme);
                    if ($htAdj['home_adjustment']) {
                        $gameLog[] = [
                            'quarter' => 3, 'clock' => 900, 'possession' => 'home',
                            'yard_line' => $state['yard_line'], 'down' => $state['down'], 'distance' => $state['distance'],
                            'home_score' => $state['home_score'], 'away_score' => $state['away_score'],
                            'play' => ['type' => 'halftime_adjustment', 'yards' => 0, 'made' => null, 'distance' => null,
                                'player' => null, 'target' => null, 'defender' => null, 'depth' => null],
                            'note' => 'Home: ' . $htAdj['home_adjustment'],
                        ];
                    }
                    if ($htAdj['away_adjustment']) {
                        $gameLog[] = [
                            'quarter' => 3, 'clock' => 900, 'possession' => 'away',
                            'yard_line' => $state['yard_line'], 'down' => $state['down'], 'distance' => $state['distance'],
                            'home_score' => $state['home_score'], 'away_score' => $state['away_score'],
                            'play' => ['type' => 'halftime_adjustment', 'yards' => 0, 'made' => null, 'distance' => null,
                                'player' => null, 'target' => null, 'defender' => null, 'depth' => null],
                            'note' => 'Away: ' . $htAdj['away_adjustment'],
                        ];
                    }
                    $halftimeProcessed = true;
                }

                // Kickoff
                $this->switchPossession($state);
                $kickingTeamTd = $possession === 'home' ? $homeTeam : $awayTeam;
                $receivingTeamTd = $possession === 'home' ? $awayTeam : $homeTeam;
                $koResult = $this->kickoff($state, $kickingTeamTd, $receivingTeamTd, $allStats, $gameLog);

                // If kickoff resulted in a return TD, the kickoff method handled scoring and possession
                if ($koResult['return_td'] ?? false) {
                    if (($state['home_score'] > $state['away_score']) !== ($prevHomeScore > $prevAwayScore)
                        && $prevHomeScore !== $prevAwayScore) {
                        $leadChanges++;
                    }
                    $prevHomeScore = $state['home_score'];
                    $prevAwayScore = $state['away_score'];
                }

                // Track lead changes
                if (($state['home_score'] > $state['away_score']) !== ($prevHomeScore > $prevAwayScore)
                    && $prevHomeScore !== $prevAwayScore) {
                    $leadChanges++;
                }
                $prevHomeScore = $state['home_score'];
                $prevAwayScore = $state['away_score'];
                continue;
            }

            // Advance clock
            $clockType = match ($play['type']) {
                'completion' => 'pass_complete',
                'incomplete' => 'pass_incomplete',
                'sack' => 'sack',
                'run' => 'run',
                default => 'run',
            };

            // Timeout usage: in 2-minute drill, trailing team may use timeout after clock-running plays
            $timeoutUsed = false;
            if ($isTwoMinute && ($clockType === 'run' || $clockType === 'pass_complete')) {
                $myScore = $isHome ? $state['home_score'] : $state['away_score'];
                $oppScore = $isHome ? $state['away_score'] : $state['home_score'];
                $timeoutsKey = $isHome ? 'home_timeouts' : 'away_timeouts';
                if ($oppScore > $myScore && $state[$timeoutsKey] > 0 && mt_rand(1, 100) <= 50) {
                    $state[$timeoutsKey]--;
                    $timeoutUsed = true;
                    $gameLog[] = [
                        'quarter' => $state['quarter'], 'clock' => $state['clock'],
                        'possession' => $possession, 'yard_line' => $state['yard_line'],
                        'down' => $state['down'], 'distance' => $state['distance'],
                        'home_score' => $state['home_score'], 'away_score' => $state['away_score'],
                        'play' => ['type' => 'timeout', 'yards' => 0, 'made' => null, 'distance' => null,
                            'player' => null, 'target' => null, 'defender' => null, 'depth' => null],
                        'note' => ($isHome ? 'Home' : 'Away') . " timeout ({$state[$timeoutsKey]} remaining)",
                    ];
                }
            }

            if (!$timeoutUsed) {
                $this->advanceClock($state, $clockType, $hurryUp);
            }
            // If timeout was used, clock stops — don't advance

            // Halftime adjustment check after clock advance
            if ($state['quarter'] === 3 && !$halftimeProcessed) {
                $halfHomeStats = [];
                $halfAwayStats = [];
                $homeTeamId_tmp = (int)$homeTeam['team']['id'];
                foreach ($allStats as $pid => $st) {
                    if ((int)($st['team_id'] ?? 0) === $homeTeamId_tmp) {
                        $halfHomeStats[$pid] = $st;
                    } else {
                        $halfAwayStats[$pid] = $st;
                    }
                }
                $htAdj = $this->halftimeAdjustment($state, $halfHomeStats, $halfAwayStats, $homeDefScheme, $awayDefScheme);
                $homeDefMods = $this->getDefenseModifiers($homeDefScheme);
                $awayDefMods = $this->getDefenseModifiers($awayDefScheme);
                if ($htAdj['home_adjustment']) {
                    $gameLog[] = [
                        'quarter' => 3, 'clock' => 900, 'possession' => 'home',
                        'yard_line' => $state['yard_line'], 'down' => $state['down'], 'distance' => $state['distance'],
                        'home_score' => $state['home_score'], 'away_score' => $state['away_score'],
                        'play' => ['type' => 'halftime_adjustment', 'yards' => 0, 'made' => null, 'distance' => null,
                            'player' => null, 'target' => null, 'defender' => null, 'depth' => null],
                        'note' => 'Home: ' . $htAdj['home_adjustment'],
                    ];
                }
                if ($htAdj['away_adjustment']) {
                    $gameLog[] = [
                        'quarter' => 3, 'clock' => 900, 'possession' => 'away',
                        'yard_line' => $state['yard_line'], 'down' => $state['down'], 'distance' => $state['distance'],
                        'home_score' => $state['home_score'], 'away_score' => $state['away_score'],
                        'play' => ['type' => 'halftime_adjustment', 'yards' => 0, 'made' => null, 'distance' => null,
                            'player' => null, 'target' => null, 'defender' => null, 'depth' => null],
                        'note' => 'Away: ' . $htAdj['away_adjustment'],
                    ];
                }
                $halftimeProcessed = true;
            }

            // Log every play (key plays get flagged for highlighting)
            if (!$isScoringPlay) {
                $note = null;
                $isKey = $this->isKeyPlay($play, $yards, $state, false);
                if (abs($yards) >= 20) $note = 'Big play';
                if ($play['type'] === 'sack') $note = 'Sack';
                $gameLog[] = $this->buildLogEntry($play, $state, $possession, $note, $isKey);
            }

            // Down and distance management
            if ($yards >= $state['distance']) {
                // First down
                $state['down'] = 1;
                $state['distance'] = min(10, 100 - $state['yard_line']);
            } else {
                $state['down']++;
                $state['distance'] -= max(0, $yards);

                if ($state['down'] > 4) {
                    // Turnover on downs
                    if ($state['down'] === 5) {
                        $gameLog[] = $this->buildLogEntry($play, $state, $possession, 'Turnover on downs', true);
                    }
                    $this->switchPossession($state);
                    $state['yard_line'] = max(1, min(99, 100 - $state['yard_line']));
                    $state['down'] = 1;
                    $state['distance'] = 10;
                    continue;
                }

                // 4th down decisions
                if ($state['down'] === 4) {
                    $myScore = $isHome ? $state['home_score'] : $state['away_score'];
                    $oppScore = $isHome ? $state['away_score'] : $state['home_score'];
                    $trailing = $oppScore > $myScore;
                    $fgRange = $state['yard_line'] >= 45; // ~52 yard FG attempt max
                    $shortYardage = $state['distance'] <= 2;
                    $deepInTerritory = $state['yard_line'] >= 65;
                    $lateAndTrailing = ($state['quarter'] >= 4 && $trailing);

                    if ($fgRange && !($lateAndTrailing && $deepInTerritory) && $state['distance'] > 2) {
                        // Field goal attempt
                        $fgPlay = $this->resolveFieldGoal($offTeam['starters'], $state['yard_line'], $weatherFx, $defTeam['starters']);
                        $this->accumulateFieldGoal($allStats, $fgPlay, $teamId);
                        $this->advanceClock($state, 'field_goal', $hurryUp);

                        if ($fgPlay['blocked'] ?? false) {
                            // Blocked FG — defense gets ball at the spot
                            $gameLog[] = $this->buildLogEntry($fgPlay, $state, $possession, "Field goal BLOCKED ({$fgPlay['distance']} yards)", true);
                            $this->switchPossession($state);
                            $state['yard_line'] = max(1, min(99, 100 - $state['yard_line']));
                            $state['down'] = 1;
                            $state['distance'] = 10;
                            continue;
                        } elseif ($fgPlay['made']) {
                            $this->scoreFieldGoal($state, $possession);
                            $gameLog[] = $this->buildLogEntry($fgPlay, $state, $possession, "Field goal good ({$fgPlay['distance']} yards)", true);

                            if (($state['home_score'] > $state['away_score']) !== ($prevHomeScore > $prevAwayScore)
                                && $prevHomeScore !== $prevAwayScore) {
                                $leadChanges++;
                            }
                            $prevHomeScore = $state['home_score'];
                            $prevAwayScore = $state['away_score'];
                        } else {
                            $gameLog[] = $this->buildLogEntry($fgPlay, $state, $possession, "Field goal missed ({$fgPlay['distance']} yards)", true);
                        }

                        // After FG attempt, other team gets ball
                        $this->switchPossession($state);
                        if ($fgPlay['made']) {
                            $kickingTeamFg = $isHome ? $homeTeam : $awayTeam;
                            $receivingTeamFg = $isHome ? $awayTeam : $homeTeam;
                            $this->kickoff($state, $kickingTeamFg, $receivingTeamFg, $allStats, $gameLog);
                        } else {
                            $state['yard_line'] = max(1, min(99, 100 - $state['yard_line']));
                            $state['down'] = 1;
                            $state['distance'] = 10;
                        }
                        continue;
                    } elseif (($shortYardage && $deepInTerritory) || ($lateAndTrailing && $state['quarter'] >= 4 && $state['clock'] <= 300)) {
                        // Go for it on 4th down — let the play loop handle it
                        $gameLog[] = $this->buildLogEntry($play, $state, $possession, 'Going for it on 4th down', true);
                    } else {
                        // Punt
                        $gameLog[] = $this->buildLogEntry($play, $state, $possession, 'Punt', true);
                        $this->advanceClock($state, 'punt', $hurryUp);
                        $puntResult = $this->punt($state, $offTeam, $defTeam, $allStats, $gameLog);
                        // If punt resulted in a return TD, punt() handled scoring and possession
                        if ($puntResult['return_td'] ?? false) {
                            if (($state['home_score'] > $state['away_score']) !== ($prevHomeScore > $prevAwayScore)
                                && $prevHomeScore !== $prevAwayScore) {
                                $leadChanges++;
                            }
                            $prevHomeScore = $state['home_score'];
                            $prevAwayScore = $state['away_score'];
                        }
                        continue;
                    }
                }
            }
        }

        // Handle overtime if tied
        if ($state['home_score'] === $state['away_score']) {
            $otResult = $this->resolveOvertime(
                $homeTeam, $awayTeam, $homePlan, $awayPlan,
                $homeDefMods, $awayDefMods, $weatherFx,
                $allStats
            );
            $state['home_score'] += $otResult['home_points'];
            $state['away_score'] += $otResult['away_points'];
            $gameLog = array_merge($gameLog, $otResult['log']);
        }

        // Split allStats into home/away by team_id
        $homeTeamId = (int)$homeTeam['team']['id'];
        $awayTeamId = (int)$awayTeam['team']['id'];
        $homeStats = [];
        $awayStats = [];
        foreach ($allStats as $playerId => $stat) {
            if ((int)($stat['team_id'] ?? 0) === $homeTeamId) {
                $homeStats[$playerId] = $stat;
            } else {
                $awayStats[$playerId] = $stat;
            }
        }

        // Generate post-game injuries (reduced rate since injuries now happen in-game)
        $injuries = $this->generateInjuries($homeTeam, $awayTeam);

        // Merge in-game injuries into the injuries array
        if (!empty($state['in_game_injuries'])) {
            foreach ($state['in_game_injuries'] as $igInjury) {
                if ($igInjury['weeks_remaining'] > 0) {
                    $injuries[] = $igInjury;
                } elseif ($igInjury['severity'] === 'questionable'
                    && in_array($igInjury['player_id'], $state['injured_player_ids'])) {
                    $igInjury['severity'] = 'day_to_day';
                    $igInjury['weeks_remaining'] = 1;
                    $injuries[] = $igInjury;
                }
            }
        }

        // Grade performances
        $grades = $this->gradePerformances($homeStats, $awayStats, [
            'home' => $state['home_score'],
            'away' => $state['away_score'],
        ]);

        // Build game classification
        $gameClass = $this->classifyGame($state, $gameLog, $leadChanges);

        // Build box score
        $boxScore = [
            'home' => [
                'team_id' => $homeTeamId,
                'team_name' => $homeTeam['team']['city'] . ' ' . $homeTeam['team']['name'],
                'abbreviation' => $homeTeam['team']['abbreviation'],
                'score' => $state['home_score'],
                'stats' => $homeStats,
                'units' => $homeUnits,
            ],
            'away' => [
                'team_id' => $awayTeamId,
                'team_name' => $awayTeam['team']['city'] . ' ' . $awayTeam['team']['name'],
                'abbreviation' => $awayTeam['team']['abbreviation'],
                'score' => $state['away_score'],
                'stats' => $awayStats,
                'units' => $awayUnits,
            ],
            'game_log' => $gameLog,
            'game_class' => $gameClass,
        ];

        // Generate turning point from game log
        $turningPoint = $this->generateTurningPointFromLog($gameLog, $homeTeam, $awayTeam, $state);

        return [
            'home_score' => $state['home_score'],
            'away_score' => $state['away_score'],
            'home_stats' => $homeStats,
            'away_stats' => $awayStats,
            'injuries' => $injuries,
            'grades' => $grades,
            'turning_point' => $turningPoint,
            'box_score' => $boxScore,
            'game_log' => $gameLog,
            'game_class' => $gameClass,
        ];
    }

    // ─── Overtime ──────────────────────────────────────────────────────

    private function resolveOvertime(
        array $homeTeam, array $awayTeam,
        array $homePlan, array $awayPlan,
        array $homeDefMods, array $awayDefMods,
        array $weatherFx,
        array &$allStats
    ): array {
        $homePoints = 0;
        $awayPoints = 0;
        $log = [];

        $firstPossession = mt_rand(0, 1) === 0 ? 'home' : 'away';
        $otState = $this->initGameState();
        $otState['quarter'] = 5;
        $otState['possession'] = $firstPossession;
        $otKickingTeam = $firstPossession === 'home' ? $awayTeam : $homeTeam;
        $otReceivingTeam = $firstPossession === 'home' ? $homeTeam : $awayTeam;
        $this->kickoff($otState, $otKickingTeam, $otReceivingTeam, $allStats, $log);

        for ($drive = 0; $drive < 2; $drive++) {
            $isHome = $otState['possession'] === 'home';
            $offTeam = $isHome ? $homeTeam : $awayTeam;
            $defTeam = $isHome ? $awayTeam : $homeTeam;
            $offPlan = $isHome ? $homePlan : $awayPlan;
            $defMods = $isHome ? $awayDefMods : $homeDefMods;
            $teamId = $offTeam['team']['id'];

            for ($p = 0; $p < 12; $p++) {
                $playCall = $this->selectPlayType($offPlan, $otState, $otState['possession']);

                if ($playCall === 'pass') {
                    $play = $this->resolvePassPlay($offTeam['starters'], $defTeam['starters'], $otState, $defMods, $weatherFx);
                    $this->accumulatePassPlay($allStats, $play, $teamId);
                } else {
                    $play = $this->resolveRunPlay($offTeam['starters'], $defTeam['starters'], $defMods, $weatherFx);
                    $this->accumulateRunPlay($allStats, $play, $teamId);
                }

                if ($play['type'] === 'interception' || ($play['details']['fumble'] ?? false)) {
                    $log[] = $this->buildLogEntry($play, $otState, $otState['possession'], 'OT turnover', true);
                    break;
                }

                $otState['yard_line'] += $play['yards'];

                if ($otState['yard_line'] >= 100) {
                    $pts = mt_rand(1, 100) <= 94 ? 7 : 6;
                    if ($isHome) $homePoints += $pts; else $awayPoints += $pts;
                    $log[] = $this->buildLogEntry($play, $otState, $otState['possession'], 'OT touchdown', true);
                    if ($drive === 0) {
                        $this->switchPossession($otState);
                        $otKickTeam2 = $otState['possession'] === 'home' ? $awayTeam : $homeTeam;
                        $otRecTeam2 = $otState['possession'] === 'home' ? $homeTeam : $awayTeam;
                        $this->kickoff($otState, $otKickTeam2, $otRecTeam2, $allStats, $log);
                    }
                    break;
                }

                if ($otState['yard_line'] <= 0) break;

                if ($play['yards'] >= $otState['distance']) {
                    $otState['down'] = 1;
                    $otState['distance'] = min(10, 100 - $otState['yard_line']);
                } else {
                    $otState['down']++;
                    $otState['distance'] -= max(0, $play['yards']);

                    if ($otState['down'] === 4) {
                        if ($otState['yard_line'] >= 40) {
                            $fgPlay = $this->resolveFieldGoal($offTeam['starters'], $otState['yard_line'], $weatherFx, $defTeam['starters']);
                            $this->accumulateFieldGoal($allStats, $fgPlay, $teamId);
                            if ($fgPlay['made']) {
                                if ($isHome) $homePoints += 3; else $awayPoints += 3;
                                $log[] = $this->buildLogEntry($fgPlay, $otState, $otState['possession'], 'OT field goal', true);
                            }
                            break;
                        }
                        break;
                    }
                }
            }

            if ($drive === 0) {
                $this->switchPossession($otState);
                $otKickTeam3 = $otState['possession'] === 'home' ? $awayTeam : $homeTeam;
                $otRecTeam3 = $otState['possession'] === 'home' ? $homeTeam : $awayTeam;
                $this->kickoff($otState, $otKickTeam3, $otRecTeam3, $allStats, $log);
            }
        }

        if ($homePoints === $awayPoints) {
            if (mt_rand(0, 1) === 0) {
                $homePoints += 3;
            } else {
                $awayPoints += 3;
            }
        }

        return ['home_points' => $homePoints, 'away_points' => $awayPoints, 'log' => $log];
    }

    // ─── Game Classification ───────────────────────────────────────────

    private function classifyGame(array $state, array $gameLog, int $leadChanges): array
    {
        $margin = abs($state['home_score'] - $state['away_score']);
        $combinedScore = $state['home_score'] + $state['away_score'];
        $loserScore = min($state['home_score'], $state['away_score']);

        // Detect comeback: was the eventual winner trailing in Q4?
        $isComeback = false;
        $winnerSide = $state['home_score'] > $state['away_score'] ? 'home' : 'away';
        foreach ($gameLog as $entry) {
            if (($entry['quarter'] ?? 0) >= 4) {
                $winnerScore = $winnerSide === 'home' ? ($entry['home_score'] ?? 0) : ($entry['away_score'] ?? 0);
                $loserScoreQ4 = $winnerSide === 'home' ? ($entry['away_score'] ?? 0) : ($entry['home_score'] ?? 0);
                if ($loserScoreQ4 > $winnerScore) {
                    $isComeback = true;
                    break;
                }
            }
        }

        $type = 'solid_win';
        if ($margin >= 21) {
            $type = 'blowout';
        } elseif ($isComeback) {
            $type = 'comeback';
        } elseif ($margin <= 3) {
            $type = 'thriller';
        } elseif ($combinedScore >= 55) {
            $type = 'shootout';
        } elseif ($state['home_score'] <= 17 && $state['away_score'] <= 17 && $loserScore <= 10) {
            $type = 'defensive_battle';
        } elseif ($margin <= 7 && $leadChanges >= 3) {
            $type = 'back_and_forth';
        }

        $tags = [];
        if ($leadChanges >= 3) $tags[] = 'lead_changes';
        if ($loserScore <= 10) $tags[] = 'defensive_score';

        return [
            'type' => $type,
            'tags' => $tags,
            'lead_changes' => $leadChanges,
            'margin' => $margin,
            'combined_score' => $combinedScore,
        ];
    }

    // ─── Turning Point from Game Log ───────────────────────────────────

    private function generateTurningPointFromLog(array $gameLog, array $home, array $away, array $state): string
    {
        if (empty($gameLog)) {
            $winner = $state['home_score'] >= $state['away_score'] ? $home : $away;
            $wName = $winner['team']['city'] . ' ' . $winner['team']['name'];
            return "when {$wName} put together a strong drive to take control of the game";
        }

        // Find the most impactful play: prefer scoring plays in key moments
        $bestEntry = null;
        $bestScore = 0;
        foreach ($gameLog as $entry) {
            $score = 0;
            $note = $entry['note'] ?? '';
            if (str_contains($note, 'touchdown')) $score += 20;
            if (str_contains($note, 'Field goal')) $score += 10;
            if (str_contains($note, 'Interception')) $score += 15;
            if (str_contains($note, 'Fumble')) $score += 12;
            if (($entry['quarter'] ?? 0) >= 3) $score += 10;
            if (($entry['quarter'] ?? 0) >= 4) $score += 15;
            if (abs(($entry['play']['yards'] ?? 0)) >= 30) $score += 8;

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestEntry = $entry;
            }
        }

        if (!$bestEntry) {
            $bestEntry = $gameLog[array_rand($gameLog)];
        }

        $quarter = match ($bestEntry['quarter'] ?? 1) {
            1 => 'first',
            2 => 'second',
            3 => 'third',
            4 => 'fourth',
            default => 'fourth',
        };

        $clock = $bestEntry['clock'] ?? 0;
        $minutes = intdiv($clock, 60);
        $seconds = $clock % 60;
        $timeStr = sprintf("%d:%02d", $minutes, $seconds);

        $playerName = $bestEntry['play']['player'] ?? 'the offense';
        $note = $bestEntry['note'] ?? 'a key play';
        $yards = abs($bestEntry['play']['yards'] ?? 0);

        $templates = [
            "in the {$quarter} quarter ({$timeStr}) when {$playerName} delivered {$note}" . ($yards > 0 ? " for {$yards} yards" : ""),
            "with {$timeStr} left in the {$quarter} quarter when {$note} changed the complexion of the game",
            "midway through the {$quarter} when {$playerName} made a pivotal play — {$note}",
        ];

        return $templates[array_rand($templates)];
    }

    // ─── Preserved Methods ─────────────────────────────────────────────

    public function getTeamData(int $teamId): array
    {
        $team = $this->db->prepare("SELECT * FROM teams WHERE id = ?");
        $team->execute([$teamId]);
        $teamData = $team->fetch();

        $players = $this->db->prepare(
            "SELECT * FROM players WHERE team_id = ? AND status IN ('active') ORDER BY overall_rating DESC"
        );
        // Note: 'holdout' status players are excluded — they don't play
        $players->execute([$teamId]);
        $allPlayersRaw = $players->fetchAll();

        $dc = $this->db->prepare("SELECT * FROM depth_chart WHERE team_id = ? AND slot = 1");
        $dc->execute([$teamId]);
        $starterSlots = $dc->fetchAll();
        $starterIds = array_column($starterSlots, 'player_id');

        $injStmt = $this->db->prepare(
            "SELECT player_id FROM injuries WHERE team_id = ? AND weeks_remaining > 0"
        );
        $injStmt->execute([$teamId]);
        $injuredIds = $injStmt->fetchAll(\PDO::FETCH_COLUMN);

        // Exclude injured players from all_players so backups can't promote injured players mid-game
        $allPlayers = array_filter($allPlayersRaw, function ($p) use ($injuredIds) {
            return !in_array($p['id'], $injuredIds);
        });
        $allPlayers = array_values($allPlayers);

        // Apply individual morale modifiers to player attributes
        // angry = -5 to key stats, frustrated = -2, content = 0, happy = +1, ecstatic = +2
        $moraleMods = [
            'angry' => -5,
            'frustrated' => -2,
            'content' => 0,
            'happy' => 1,
            'ecstatic' => 2,
        ];

        $statKeys = [
            'throw_accuracy_short', 'throw_accuracy_mid', 'throw_accuracy_deep',
            'throw_under_pressure', 'awareness', 'bc_vision', 'catching',
            'short_route_running', 'medium_route_running', 'deep_route_running',
            'pass_block', 'run_block', 'man_coverage', 'zone_coverage',
            'play_recognition', 'block_shedding', 'tackle',
        ];

        foreach ($allPlayers as &$p) {
            $mod = $moraleMods[$p['morale'] ?? 'content'] ?? 0;
            if ($mod !== 0) {
                foreach ($statKeys as $key) {
                    if (isset($p[$key])) {
                        $p[$key] = max(1, min(99, (int) $p[$key] + $mod));
                    }
                }
            }
        }
        unset($p);

        $starters = [];
        $positionFilled = [];

        foreach ($allPlayers as $player) {
            if (in_array($player['id'], $starterIds) && !in_array($player['id'], $injuredIds)) {
                $starters[] = $player;
                $positionFilled[$player['position']] = ($positionFilled[$player['position']] ?? 0) + 1;
            }
        }

        $positionNeeds = [
            'QB' => 1, 'RB' => 1, 'WR' => 3, 'TE' => 1,
            'OT' => 2, 'OG' => 2, 'C' => 1,
            'DE' => 2, 'DT' => 2, 'LB' => 3, 'CB' => 2, 'S' => 2,
            'K' => 1, 'P' => 1,
        ];

        $starterPlayerIds = array_column($starters, 'id');

        foreach ($positionNeeds as $pos => $needed) {
            $filled = $positionFilled[$pos] ?? 0;
            if ($filled < $needed) {
                // First pass: try healthy players not already starting
                foreach ($allPlayers as $player) {
                    if ($player['position'] === $pos &&
                        !in_array($player['id'], $injuredIds) &&
                        !in_array($player['id'], $starterPlayerIds)) {
                        $starters[] = $player;
                        $starterPlayerIds[] = $player['id'];
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

    private function generateInjuries(array $home, array $away): array
    {
        $injuries = [];
        $allPlayers = array_merge($home['starters'], $away['starters']);

        foreach ($allPlayers as $player) {
            // Reduced rate: injuries now also happen during plays
            $chance = 0.015 + ($player['injury_prone'] / 2000);
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
                    'long_term' => mt_rand(5, 10),
                    'season_ending' => 18, // rest of season (max regular season weeks)
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
            $score = 50;

            if (($stat['pass_attempts'] ?? 0) > 0) {
                $compPct = ($stat['pass_completions'] ?? 0) / $stat['pass_attempts'];
                $score += ($compPct - 0.60) * 100;
                $score += ($stat['pass_tds'] ?? 0) * 10;
                $score -= ($stat['interceptions'] ?? 0) * 15;
                $score += (($stat['pass_yards'] ?? 0) - 200) * 0.05;
            }

            if (($stat['rush_attempts'] ?? 0) > 3) {
                $ypc = ($stat['rush_yards'] ?? 0) / $stat['rush_attempts'];
                $score += ($ypc - 4.0) * 8;
                $score += ($stat['rush_tds'] ?? 0) * 12;
            }

            if (($stat['targets'] ?? 0) > 0) {
                $catchRate = ($stat['receptions'] ?? 0) / $stat['targets'];
                $score += ($catchRate - 0.60) * 30;
                $score += ($stat['rec_tds'] ?? 0) * 12;
                $score += (($stat['rec_yards'] ?? 0) - 50) * 0.1;
            }

            $score += ($stat['tackles'] ?? 0) * 2;
            $score += ($stat['sacks'] ?? 0) * 10;
            $score += ($stat['interceptions_def'] ?? 0) * 15;
            $score += ($stat['forced_fumbles'] ?? 0) * 8;

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

    private function moraleModifier(int $morale): float
    {
        return ($morale - 50) * 0.05;
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
