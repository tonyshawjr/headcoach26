<?php
/**
 * Migration: Dead cap charges tracking table.
 * Stores dead money hits from released players, including post-June-1 designations
 * that split dead cap over two cap years.
 */
return function (string $driver): array {
    $autoInc = $driver === 'sqlite'
        ? 'INTEGER PRIMARY KEY AUTOINCREMENT'
        : 'INT PRIMARY KEY AUTO_INCREMENT';

    $bigint = $driver === 'sqlite' ? 'INTEGER' : 'BIGINT';
    $boolean = $driver === 'sqlite' ? 'INTEGER' : 'TINYINT(1)';

    return [
        "CREATE TABLE IF NOT EXISTS dead_cap_charges (
            id {$autoInc},
            team_id INT NOT NULL,
            player_id INT NOT NULL,
            contract_id INT NOT NULL,
            league_id INT NOT NULL,
            season_year INT NOT NULL,
            cap_charge {$bigint} NOT NULL DEFAULT 0,
            charge_type VARCHAR(20) NOT NULL DEFAULT 'standard',
            is_post_june1 {$boolean} NOT NULL DEFAULT 0,
            description TEXT NULL,
            created_at DATETIME NOT NULL,
            FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
            FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
        )",

        "CREATE INDEX IF NOT EXISTS idx_dead_cap_team_year ON dead_cap_charges(team_id, season_year)",
        "CREATE INDEX IF NOT EXISTS idx_dead_cap_player ON dead_cap_charges(player_id)",
    ];
};
