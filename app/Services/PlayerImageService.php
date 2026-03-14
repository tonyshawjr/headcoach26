<?php

namespace App\Services;

/**
 * Handles player headshot images.
 *
 * For real NFL players: fetches from ESPN CDN via their public roster API.
 * For fictional players: generates DiceBear avatar URLs.
 */
class PlayerImageService
{
    private const ESPN_ROSTER_URL = 'https://site.api.espn.com/apis/site/v2/sports/football/nfl/teams/{team_id}/roster';
    private const ESPN_HEADSHOT_URL = 'https://a.espncdn.com/combiner/i?img=/i/headshots/nfl/players/full/{id}.png&w=350&h=254';
    private const DICEBEAR_URL = 'https://api.dicebear.com/9.x/initials/svg?seed={seed}&backgroundColor=1a1a2e&textColor=ffffff&fontSize=36';

    /**
     * ESPN team IDs mapped to NFL team names.
     */
    private const ESPN_TEAMS = [
        'Arizona Cardinals' => 22,
        'Atlanta Falcons' => 1,
        'Baltimore Ravens' => 33,
        'Buffalo Bills' => 2,
        'Carolina Panthers' => 29,
        'Chicago Bears' => 3,
        'Cincinnati Bengals' => 4,
        'Cleveland Browns' => 5,
        'Dallas Cowboys' => 6,
        'Denver Broncos' => 7,
        'Detroit Lions' => 8,
        'Green Bay Packers' => 9,
        'Houston Texans' => 34,
        'Indianapolis Colts' => 11,
        'Jacksonville Jaguars' => 30,
        'Kansas City Chiefs' => 12,
        'Las Vegas Raiders' => 13,
        'Los Angeles Chargers' => 24,
        'Los Angeles Rams' => 14,
        'Miami Dolphins' => 15,
        'Minnesota Vikings' => 16,
        'New England Patriots' => 17,
        'New Orleans Saints' => 18,
        'NY Giants' => 19,
        'NY Jets' => 20,
        'Philadelphia Eagles' => 21,
        'Pittsburgh Steelers' => 23,
        'San Francisco 49ers' => 25,
        'Seattle Seahawks' => 26,
        'Tampa Bay Buccaneers' => 27,
        'Tennessee Titans' => 10,
        'Washington Commanders' => 28,
    ];

    /**
     * Build a lookup table: "FirstName LastName" => ESPN headshot URL
     * by fetching rosters from ESPN's public API.
     *
     * @return array<string, string> name => headshot URL
     */
    public function fetchEspnHeadshots(): array
    {
        $lookup = [];

        foreach (self::ESPN_TEAMS as $teamName => $espnTeamId) {
            $url = str_replace('{team_id}', (string) $espnTeamId, self::ESPN_ROSTER_URL);

            $context = stream_context_create([
                'http' => [
                    'timeout' => 10,
                    'header' => "User-Agent: HeadCoach26/1.0\r\n",
                ],
            ]);

            $json = @file_get_contents($url, false, $context);
            if ($json === false) {
                continue;
            }

            $data = json_decode($json, true);
            if (!$data) {
                continue;
            }

            // ESPN roster API returns athletes nested in categories
            $athletes = $data['athletes'] ?? [];
            foreach ($athletes as $group) {
                $items = $group['items'] ?? [];
                foreach ($items as $athlete) {
                    $fullName = $athlete['fullName'] ?? '';
                    $headshot = $athlete['headshot']['href'] ?? null;
                    $espnId = $athlete['id'] ?? null;

                    if ($fullName && $headshot) {
                        $lookup[$fullName] = $headshot;
                    } elseif ($fullName && $espnId) {
                        $lookup[$fullName] = str_replace('{id}', $espnId, self::ESPN_HEADSHOT_URL);
                    }
                }
            }

            // Be nice to ESPN — small delay between requests
            usleep(100000); // 100ms
        }

        return $lookup;
    }

    /**
     * Generate a DiceBear avatar URL for a fictional player.
     */
    public static function generateAvatar(string $firstName, string $lastName): string
    {
        $seed = urlencode($firstName . ' ' . $lastName);
        return str_replace('{seed}', $seed, self::DICEBEAR_URL);
    }

    /**
     * Assign images to all players in a league.
     * Real players get ESPN headshots, fictional get DiceBear avatars.
     *
     * @return array{updated: int, espn_matched: int, avatars: int}
     */
    public function assignImages(\PDO $pdo, int $leagueId): array
    {
        $espnMatched = 0;
        $avatars = 0;

        // Get all players without images
        $stmt = $pdo->prepare(
            "SELECT id, first_name, last_name, is_fictional FROM players
             WHERE league_id = ? AND (image_url IS NULL OR image_url = '')"
        );
        $stmt->execute([$leagueId]);
        $players = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($players)) {
            return ['updated' => 0, 'espn_matched' => 0, 'avatars' => 0];
        }

        // Check if any are real (non-fictional) players
        $hasReal = false;
        foreach ($players as $p) {
            if (empty($p['is_fictional'])) {
                $hasReal = true;
                break;
            }
        }

        // Fetch ESPN headshots if we have real players
        $espnLookup = [];
        if ($hasReal) {
            $espnLookup = $this->fetchEspnHeadshots();
        }

        $updateStmt = $pdo->prepare("UPDATE players SET image_url = ? WHERE id = ?");

        foreach ($players as $p) {
            $fullName = $p['first_name'] . ' ' . $p['last_name'];
            $imageUrl = null;

            if (empty($p['is_fictional']) && isset($espnLookup[$fullName])) {
                $imageUrl = $espnLookup[$fullName];
                $espnMatched++;
            } else {
                $imageUrl = self::generateAvatar($p['first_name'], $p['last_name']);
                $avatars++;
            }

            $updateStmt->execute([$imageUrl, $p['id']]);
        }

        return [
            'updated' => $espnMatched + $avatars,
            'espn_matched' => $espnMatched,
            'avatars' => $avatars,
        ];
    }
}
