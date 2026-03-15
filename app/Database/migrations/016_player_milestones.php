<?php
/**
 * Migration 016: Player milestones
 *
 * Tracks individual player statistical milestones — game, season, and career.
 * Prevents duplicate awards via UNIQUE constraint on (player_id, league_id, milestone_type, milestone_value).
 */
return function (string $driver): array {
    $autoInc = $driver === 'sqlite'
        ? 'INTEGER PRIMARY KEY AUTOINCREMENT'
        : 'INT PRIMARY KEY AUTO_INCREMENT';

    return [
        "CREATE TABLE IF NOT EXISTS player_milestones (
            id {$autoInc},
            player_id INT NOT NULL,
            league_id INT NOT NULL,
            milestone_type VARCHAR(30) NOT NULL,
            milestone_value INT NOT NULL,
            milestone_label VARCHAR(200) NOT NULL,
            season_year INT NULL,
            week INT NULL,
            game_id INT NULL,
            achieved_at DATETIME NOT NULL DEFAULT (datetime('now')),
            FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
            UNIQUE(player_id, league_id, milestone_type, milestone_value)
        )",

        "CREATE INDEX IF NOT EXISTS idx_pm_league ON player_milestones(league_id)",
        "CREATE INDEX IF NOT EXISTS idx_pm_player ON player_milestones(player_id)",
        "CREATE INDEX IF NOT EXISTS idx_pm_season ON player_milestones(league_id, season_year)",
    ];
};
