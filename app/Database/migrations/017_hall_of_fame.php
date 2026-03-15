<?php
/**
 * Migration 017: Hall of Fame
 *
 * Stores inducted Hall of Fame players with their career summary,
 * awards breakdown, and career stats snapshot.
 */
return function (string $driver): array {
    $autoInc = $driver === 'sqlite'
        ? 'INTEGER PRIMARY KEY AUTOINCREMENT'
        : 'INT PRIMARY KEY AUTO_INCREMENT';

    return [
        "CREATE TABLE IF NOT EXISTS hall_of_fame (
            id {$autoInc},
            player_id INT NOT NULL,
            league_id INT NOT NULL,
            first_name VARCHAR(50) NOT NULL,
            last_name VARCHAR(50) NOT NULL,
            position VARCHAR(5) NOT NULL,
            inducted_year INT NOT NULL,
            career_years INT NOT NULL DEFAULT 0,
            career_stats TEXT NULL,
            peak_overall INT NOT NULL DEFAULT 0,
            all_pro_first_count INT NOT NULL DEFAULT 0,
            all_pro_second_count INT NOT NULL DEFAULT 0,
            pro_bowl_count INT NOT NULL DEFAULT 0,
            championships INT NOT NULL DEFAULT 0,
            mvp_count INT NOT NULL DEFAULT 0,
            inducted_at DATETIME NOT NULL,
            FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
            FOREIGN KEY (league_id) REFERENCES leagues(id) ON DELETE CASCADE
        )",

        "CREATE INDEX IF NOT EXISTS idx_hall_of_fame_league ON hall_of_fame(league_id)",
    ];
};
