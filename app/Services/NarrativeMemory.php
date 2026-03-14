<?php

namespace App\Services;

use App\Database\Connection;

/**
 * Manages narrative memory as markdown files per league/season.
 * Used as context for Claude API calls.
 */
class NarrativeMemory
{
    private \PDO $db;
    private string $storagePath;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
        $this->storagePath = dirname(__DIR__, 2) . '/storage/leagues';
    }

    /**
     * Get the season context markdown for Claude prompts.
     */
    public function getSeasonContext(int $leagueId): string
    {
        $filePath = $this->getSeasonFilePath($leagueId);
        if (!file_exists($filePath)) {
            return '';
        }

        $content = file_get_contents($filePath);
        // Truncate to ~4000 chars to stay within prompt budgets
        if (strlen($content) > 4000) {
            $content = substr($content, -4000);
            $content = "...\n" . substr($content, strpos($content, "\n") + 1);
        }

        return $content;
    }

    /**
     * Record a weekly summary to the season narrative file.
     */
    public function recordWeek(int $leagueId, int $week, array $results): void
    {
        $filePath = $this->getSeasonFilePath($leagueId);
        $this->ensureDirectory(dirname($filePath));

        $entry = "\n## Week {$week}\n\n";

        // Game results
        $entry .= "### Results\n";
        foreach ($results as $r) {
            $home = $r['home'] ?? [];
            $away = $r['away'] ?? [];
            $winner = ($home['score'] ?? 0) > ($away['score'] ?? 0) ? 'home' : 'away';
            $entry .= sprintf(
                "- %s %d, %s %d%s\n",
                $home['name'] ?? 'Home',
                $home['score'] ?? 0,
                $away['name'] ?? 'Away',
                $away['score'] ?? 0,
                isset($r['turning_point']) ? " (Key: {$r['turning_point']})" : ''
            );
        }

        // Standings snapshot
        $entry .= "\n### Standings Snapshot\n";
        $standings = $this->getStandingsSnapshot($leagueId);
        foreach ($standings as $team) {
            $entry .= sprintf(
                "- %s %s: %d-%d (Rating: %d)\n",
                $team['city'],
                $team['name'],
                $team['wins'],
                $team['losses'],
                $team['overall_rating']
            );
        }

        // Narrative arcs
        $arcs = $this->getActiveArcs($leagueId);
        if (!empty($arcs)) {
            $entry .= "\n### Active Storylines\n";
            foreach ($arcs as $arc) {
                $entry .= "- [{$arc['type']}] {$arc['description']}\n";
            }
        }

        // Injuries
        $injuries = $this->getActiveInjuries($leagueId);
        if (!empty($injuries)) {
            $entry .= "\n### Key Injuries\n";
            foreach (array_slice($injuries, 0, 5) as $inj) {
                $entry .= "- {$inj['first_name']} {$inj['last_name']} ({$inj['position']}, {$inj['abbreviation']}): {$inj['type']}, {$inj['weeks_remaining']}w\n";
            }
        }

        $entry .= "\n---\n";

        file_put_contents($filePath, $entry, FILE_APPEND);
    }

    /**
     * Record a press conference to memory.
     */
    public function recordPressConference(int $leagueId, int $week, string $coachName, array $answers): void
    {
        $filePath = $this->getSeasonFilePath($leagueId);
        $this->ensureDirectory(dirname($filePath));

        $entry = "\n### Press Conference (Week {$week}) — Coach {$coachName}\n";
        foreach ($answers as $a) {
            $entry .= "- Q: \"{$a['question']}\" → A ({$a['tone']}): \"{$a['answer']}\"\n";
        }
        $entry .= "\n";

        file_put_contents($filePath, $entry, FILE_APPEND);
    }

    /**
     * Initialize a new season file.
     */
    public function initSeason(int $leagueId, int $year): void
    {
        $filePath = $this->getSeasonFilePath($leagueId, $year);
        $this->ensureDirectory(dirname($filePath));

        $league = $this->getLeague($leagueId);
        $leagueName = $league['name'] ?? 'League';

        $header = "# {$leagueName} — Season {$year}\n\n";
        $header .= "League ID: {$leagueId}\n";
        $header .= "Season: {$year}\n\n";
        $header .= "---\n";

        file_put_contents($filePath, $header);
    }

    /**
     * Get a summary of the season so far for shorter contexts.
     */
    public function getSeasonSummary(int $leagueId): string
    {
        $league = $this->getLeague($leagueId);
        if (!$league) return '';

        $summary = "League: {$league['name']}, Year: {$league['season_year']}, Week: {$league['current_week']}\n\n";

        // Top teams
        $stmt = $this->db->prepare(
            "SELECT city, name, abbreviation, wins, losses, overall_rating
             FROM teams WHERE league_id = ? ORDER BY wins DESC LIMIT 8"
        );
        $stmt->execute([$leagueId]);
        $topTeams = $stmt->fetchAll();

        $summary .= "Top Teams:\n";
        foreach ($topTeams as $t) {
            $summary .= "- {$t['city']} {$t['name']} ({$t['abbreviation']}): {$t['wins']}-{$t['losses']}, {$t['overall_rating']} OVR\n";
        }

        // Stat leaders
        $stmt = $this->db->prepare(
            "SELECT p.first_name, p.last_name, p.position, t.abbreviation,
                    SUM(gs.pass_yards) as py, SUM(gs.rush_yards) as ry, SUM(gs.rec_yards) as recy,
                    SUM(gs.pass_tds) + SUM(gs.rush_tds) + SUM(gs.rec_tds) as tds
             FROM game_stats gs
             JOIN players p ON gs.player_id = p.id
             JOIN teams t ON p.team_id = t.id
             JOIN games g ON gs.game_id = g.id
             WHERE g.league_id = ?
             GROUP BY p.id ORDER BY tds DESC LIMIT 5"
        );
        $stmt->execute([$leagueId]);
        $leaders = $stmt->fetchAll();

        if (!empty($leaders)) {
            $summary .= "\nTD Leaders:\n";
            foreach ($leaders as $l) {
                $summary .= "- {$l['first_name']} {$l['last_name']} ({$l['position']}, {$l['abbreviation']}): {$l['tds']} TDs\n";
            }
        }

        return $summary;
    }

    private function getSeasonFilePath(int $leagueId, ?int $year = null): string
    {
        if (!$year) {
            $league = $this->getLeague($leagueId);
            $year = $league['season_year'] ?? date('Y');
        }
        return "{$this->storagePath}/league_{$leagueId}/season_{$year}.md";
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    private function getLeague(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM leagues WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    private function getStandingsSnapshot(int $leagueId): array
    {
        $stmt = $this->db->prepare(
            "SELECT city, name, wins, losses, overall_rating
             FROM teams WHERE league_id = ? ORDER BY wins DESC, (points_for - points_against) DESC LIMIT 10"
        );
        $stmt->execute([$leagueId]);
        return $stmt->fetchAll();
    }

    private function getActiveArcs(int $leagueId): array
    {
        $stmt = $this->db->prepare(
            "SELECT type, description FROM narrative_arcs WHERE league_id = ? AND status = 'active' LIMIT 5"
        );
        $stmt->execute([$leagueId]);
        return $stmt->fetchAll();
    }

    private function getActiveInjuries(int $leagueId): array
    {
        $stmt = $this->db->prepare(
            "SELECT p.first_name, p.last_name, p.position, t.abbreviation, i.type, i.weeks_remaining
             FROM injuries i
             JOIN players p ON i.player_id = p.id
             JOIN teams t ON p.team_id = t.id
             WHERE i.league_id = ? AND i.weeks_remaining > 0
             ORDER BY p.overall_rating DESC LIMIT 5"
        );
        $stmt->execute([$leagueId]);
        return $stmt->fetchAll();
    }
}
