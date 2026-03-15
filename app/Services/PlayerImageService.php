<?php

namespace App\Services;

/**
 * PlayerImageService — Downloads, resizes, and converts player headshots.
 *
 * For real NFL players: fetches from ESPN CDN, resizes to 250px wide,
 * converts to WebP (high quality), and saves locally.
 *
 * For fictional players: generates a simple SVG silhouette saved locally.
 *
 * All images stored in storage/players/ and served via /uploads/players/.
 * On franchise restart, deleteAllImages() wipes the folder clean.
 */
class PlayerImageService
{
    private const ESPN_ROSTER_URL = 'https://site.api.espn.com/apis/site/v2/sports/football/nfl/teams/{team_id}/roster';
    private const ESPN_HEADSHOT_URL = 'https://a.espncdn.com/combiner/i?img=/i/headshots/nfl/players/full/{id}.png&w=350&h=254';

    private const IMAGE_WIDTH = 300;   // px — enough for display + retina
    private const WEBP_QUALITY = 95;   // 0-100, 95 keeps faces sharp
    private const STORAGE_DIR = 'storage/players';
    private const URL_PREFIX = '/uploads/players';

    private const ESPN_TEAMS = [
        'Arizona Cardinals' => 22, 'Atlanta Falcons' => 1, 'Baltimore Ravens' => 33,
        'Buffalo Bills' => 2, 'Carolina Panthers' => 29, 'Chicago Bears' => 3,
        'Cincinnati Bengals' => 4, 'Cleveland Browns' => 5, 'Dallas Cowboys' => 6,
        'Denver Broncos' => 7, 'Detroit Lions' => 8, 'Green Bay Packers' => 9,
        'Houston Texans' => 34, 'Indianapolis Colts' => 11, 'Jacksonville Jaguars' => 30,
        'Kansas City Chiefs' => 12, 'Las Vegas Raiders' => 13, 'Los Angeles Chargers' => 24,
        'Los Angeles Rams' => 14, 'Miami Dolphins' => 15, 'Minnesota Vikings' => 16,
        'New England Patriots' => 17, 'New Orleans Saints' => 18, 'NY Giants' => 19,
        'NY Jets' => 20, 'Philadelphia Eagles' => 21, 'Pittsburgh Steelers' => 23,
        'San Francisco 49ers' => 25, 'Seattle Seahawks' => 26, 'Tampa Bay Buccaneers' => 27,
        'Tennessee Titans' => 10, 'Washington Commanders' => 28,
    ];

    /**
     * Fetch ESPN headshots for all teams.
     * @return array<string, string> "First Last" => remote URL
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
            if ($json === false) continue;

            $data = json_decode($json, true);
            if (!$data) continue;

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

            usleep(100000); // 100ms between requests
        }

        return $lookup;
    }

    /**
     * Download a remote image, resize to 250px wide, convert to WebP, save locally.
     * Returns the local URL path or null on failure.
     */
    public function downloadAndConvert(string $remoteUrl, int $playerId): ?string
    {
        $storageDir = $this->getStorageDir();

        // Download the image
        $context = stream_context_create([
            'http' => [
                'timeout' => 15,
                'header' => "User-Agent: HeadCoach26/1.0\r\n",
            ],
        ]);

        $imageData = @file_get_contents($remoteUrl, false, $context);
        if ($imageData === false || strlen($imageData) < 100) {
            return null;
        }

        // Create GD image from the downloaded data
        $src = @imagecreatefromstring($imageData);
        if ($src === false) {
            return null;
        }

        // Get original dimensions
        $origW = imagesx($src);
        $origH = imagesy($src);

        if ($origW <= 0 || $origH <= 0) {
            imagedestroy($src);
            return null;
        }

        // Calculate new dimensions (maintain aspect ratio)
        $newW = self::IMAGE_WIDTH;
        $newH = (int) round($origH * ($newW / $origW));

        // Resize
        $dst = imagecreatetruecolor($newW, $newH);
        if ($dst === false) {
            imagedestroy($src);
            return null;
        }

        // Preserve transparency for PNG sources
        imagealphablending($dst, false);
        imagesavealpha($dst, true);

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
        imagedestroy($src);

        // Save as WebP
        $filename = "player_{$playerId}.webp";
        $filepath = $storageDir . '/' . $filename;

        $success = imagewebp($dst, $filepath, self::WEBP_QUALITY);
        imagedestroy($dst);

        if (!$success) {
            return null;
        }

        return self::URL_PREFIX . '/' . $filename;
    }

    /**
     * Generate a simple generic silhouette SVG for fictional players.
     * Saves locally as an SVG file (tiny, ~500 bytes).
     */
    public function generateSilhouette(int $playerId, string $firstName, string $lastName): string
    {
        $storageDir = $this->getStorageDir();
        $initials = strtoupper(substr($firstName, 0, 1) . substr($lastName, 0, 1));

        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="250" height="250" viewBox="0 0 250 250">
  <rect width="250" height="250" fill="#1c2333"/>
  <circle cx="125" cy="90" r="42" fill="#2a3040"/>
  <ellipse cx="125" cy="220" rx="70" ry="55" fill="#2a3040"/>
  <text x="125" y="140" text-anchor="middle" fill="#4a5568" font-family="sans-serif" font-size="48" font-weight="bold">{$initials}</text>
</svg>
SVG;

        $filename = "player_{$playerId}.svg";
        $filepath = $storageDir . '/' . $filename;
        file_put_contents($filepath, $svg);

        return self::URL_PREFIX . '/' . $filename;
    }

    /**
     * Assign images to all players in a league.
     * Downloads ESPN headshots → resize → WebP for real players.
     * Generates SVG silhouettes for fictional players.
     *
     * @return array{updated: int, espn_matched: int, avatars: int, failed: int}
     */
    /**
     * Fast assignment from name cache only — no ESPN downloads.
     * Used on franchise restart to instantly restore images.
     */
    public function assignFromCache(\PDO $pdo, int $leagueId): array
    {
        $stmt = $pdo->prepare(
            "SELECT id, first_name, last_name FROM players
             WHERE league_id = ? AND (image_url IS NULL OR image_url = '')"
        );
        $stmt->execute([$leagueId]);
        $players = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $assigned = 0;
        $updateStmt = $pdo->prepare("UPDATE players SET image_url = ? WHERE id = ?");

        foreach ($players as $p) {
            $cachedUrl = self::tryNameCache((int) $p['id'], $p['first_name'], $p['last_name']);
            if ($cachedUrl) {
                $updateStmt->execute([$cachedUrl, $p['id']]);
                $assigned++;
            }
        }

        return ['assigned' => $assigned, 'total' => count($players)];
    }

    public function assignImages(\PDO $pdo, int $leagueId): array
    {
        $espnMatched = 0;
        $avatars = 0;
        $failed = 0;

        // Get all players without images
        $stmt = $pdo->prepare(
            "SELECT id, first_name, last_name, is_fictional FROM players
             WHERE league_id = ? AND (image_url IS NULL OR image_url = '')"
        );
        $stmt->execute([$leagueId]);
        $players = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($players)) {
            return ['updated' => 0, 'espn_matched' => 0, 'avatars' => 0, 'failed' => 0];
        }

        // Check if any are real players
        $hasReal = false;
        foreach ($players as $p) {
            if (empty($p['is_fictional'])) {
                $hasReal = true;
                break;
            }
        }

        // Fetch ESPN headshot URLs if we have real players
        $espnLookup = [];
        if ($hasReal) {
            $espnLookup = $this->fetchEspnHeadshots();
        }

        $updateStmt = $pdo->prepare("UPDATE players SET image_url = ? WHERE id = ?");

        foreach ($players as $p) {
            $fullName = $p['first_name'] . ' ' . $p['last_name'];
            $imageUrl = null;

            // Check name cache first — no download needed if we already have it
            $cachedUrl = self::tryNameCache((int) $p['id'], $p['first_name'], $p['last_name']);
            if ($cachedUrl) {
                $updateStmt->execute([$cachedUrl, $p['id']]);
                $espnMatched++;
                continue;
            }

            if (empty($p['is_fictional']) && isset($espnLookup[$fullName])) {
                // Real player with ESPN match — download + convert + cache by name
                $imageUrl = $this->downloadAndConvert($espnLookup[$fullName], (int) $p['id']);
                if ($imageUrl) {
                    // Save to name cache so we don't re-download on franchise restart
                    $cacheFile = self::getNameCacheFile($p['first_name'], $p['last_name']);
                    $playerFile = dirname(__DIR__, 2) . '/' . self::STORAGE_DIR . '/player_' . $p['id'] . '.webp';
                    if (file_exists($playerFile)) @copy($playerFile, $cacheFile);
                    $espnMatched++;
                } else {
                    // Download failed — generate silhouette instead
                    $imageUrl = $this->generateSilhouette((int) $p['id'], $p['first_name'], $p['last_name']);
                    $avatars++;
                    $failed++;
                }
            } else {
                // Fictional or no ESPN match — silhouette
                $imageUrl = $this->generateSilhouette((int) $p['id'], $p['first_name'], $p['last_name']);
                $avatars++;
            }

            $updateStmt->execute([$imageUrl, $p['id']]);
        }

        return [
            'updated' => $espnMatched + $avatars,
            'espn_matched' => $espnMatched,
            'avatars' => $avatars,
            'failed' => $failed,
        ];
    }

    /**
     * Delete all player images from storage.
     * Called on franchise restart to clean up.
     */
    public static function deleteAllImages(): void
    {
        $dir = dirname(__DIR__, 2) . '/' . self::STORAGE_DIR;
        if (!is_dir($dir)) return;

        // Only delete player_ID files, keep the name-based cache
        $files = glob($dir . '/player_*');
        if ($files === false) return;

        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * Get the name-based cache filename for a player.
     * This persists across franchise restarts since it's based on the real name, not DB id.
     */
    public static function getNameCacheFile(string $firstName, string $lastName): string
    {
        $dir = dirname(__DIR__, 2) . '/' . self::STORAGE_DIR;
        $safeName = strtolower(preg_replace('/[^a-z0-9]/i', '_', $firstName . '_' . $lastName));
        return $dir . '/cache_' . $safeName . '.webp';
    }

    /**
     * Try to use a cached image by player name. If found, copy to the player_ID file.
     * Returns the URL path if cached, null if not.
     */
    public static function tryNameCache(int $playerId, string $firstName, string $lastName): ?string
    {
        $cacheFile = self::getNameCacheFile($firstName, $lastName);
        if (file_exists($cacheFile) && filesize($cacheFile) > 500) {
            $destFile = dirname(__DIR__, 2) . '/' . self::STORAGE_DIR . '/player_' . $playerId . '.webp';
            copy($cacheFile, $destFile);
            return self::URL_PREFIX . '/player_' . $playerId . '.webp';
        }
        return null;
    }

    /**
     * Save an image to both the player_ID file AND the name cache.
     */
    public static function saveWithCache(string $imageData, int $playerId, string $firstName, string $lastName): ?string
    {
        $src = @imagecreatefromstring($imageData);
        if (!$src) return null;

        $dir = dirname(__DIR__, 2) . '/' . self::STORAGE_DIR;
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $playerFile = $dir . '/player_' . $playerId . '.webp';
        $cacheFile = self::getNameCacheFile($firstName, $lastName);

        imagewebp($src, $playerFile, self::WEBP_QUALITY);
        copy($playerFile, $cacheFile); // save to name cache too

        return self::URL_PREFIX . '/player_' . $playerId . '.webp';
    }

    /**
     * Ensure the storage directory exists and return its path.
     */
    private function getStorageDir(): string
    {
        $dir = dirname(__DIR__, 2) . '/' . self::STORAGE_DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }
}
