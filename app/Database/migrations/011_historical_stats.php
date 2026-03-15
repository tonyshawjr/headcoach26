<?php
/**
 * Migration 011: Historical season stats
 *
 * Stores per-season career stats for players — both real (scraped)
 * and generated (synthetic). Allows the Stats tab to show full
 * career history from before the franchise started.
 */
return function (string $driver): array {
    $autoInc = $driver === 'sqlite'
        ? 'INTEGER PRIMARY KEY AUTOINCREMENT'
        : 'INT PRIMARY KEY AUTO_INCREMENT';

    return [
        "CREATE TABLE IF NOT EXISTS historical_stats (
            id {$autoInc},
            player_id INT NOT NULL,
            league_id INT NOT NULL,
            season_year INT NOT NULL,
            team_abbr VARCHAR(5) NULL,
            games_played INT NOT NULL DEFAULT 0,
            pass_attempts INT NOT NULL DEFAULT 0,
            pass_completions INT NOT NULL DEFAULT 0,
            pass_yards INT NOT NULL DEFAULT 0,
            pass_tds INT NOT NULL DEFAULT 0,
            interceptions INT NOT NULL DEFAULT 0,
            rush_attempts INT NOT NULL DEFAULT 0,
            rush_yards INT NOT NULL DEFAULT 0,
            rush_tds INT NOT NULL DEFAULT 0,
            targets INT NOT NULL DEFAULT 0,
            receptions INT NOT NULL DEFAULT 0,
            rec_yards INT NOT NULL DEFAULT 0,
            rec_tds INT NOT NULL DEFAULT 0,
            tackles INT NOT NULL DEFAULT 0,
            sacks REAL NOT NULL DEFAULT 0,
            interceptions_def INT NOT NULL DEFAULT 0,
            forced_fumbles INT NOT NULL DEFAULT 0,
            fg_attempts INT NOT NULL DEFAULT 0,
            fg_made INT NOT NULL DEFAULT 0,
            is_synthetic INT NOT NULL DEFAULT 1,
            FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
            UNIQUE(player_id, season_year)
        )",

        "CREATE INDEX IF NOT EXISTS idx_historical_player ON historical_stats(player_id)",
        "CREATE INDEX IF NOT EXISTS idx_historical_year ON historical_stats(season_year)",
    ];
};
