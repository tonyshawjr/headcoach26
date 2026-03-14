<?php

/**
 * Migration 008: Coach Career History table
 * Tracks a coach's history across teams for team switching / career mode.
 */

return function (string $driver): array {
    $autoInc = $driver === 'sqlite'
        ? 'INTEGER PRIMARY KEY AUTOINCREMENT'
        : 'INT PRIMARY KEY AUTO_INCREMENT';

    return [
        "CREATE TABLE IF NOT EXISTS coach_career_history (
            id {$autoInc},
            coach_id INT NOT NULL,
            team_id INT NOT NULL,
            league_id INT NOT NULL,
            start_season INT NOT NULL,
            end_season INT NULL,
            wins INT NOT NULL DEFAULT 0,
            losses INT NOT NULL DEFAULT 0,
            ties INT NOT NULL DEFAULT 0,
            playoff_appearances INT NOT NULL DEFAULT 0,
            championships INT NOT NULL DEFAULT 0,
            departure_reason VARCHAR(30) NULL,
            created_at DATETIME NOT NULL,
            FOREIGN KEY (coach_id) REFERENCES coaches(id) ON DELETE CASCADE,
            FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
            FOREIGN KEY (league_id) REFERENCES leagues(id) ON DELETE CASCADE
        )",
    ];
};
