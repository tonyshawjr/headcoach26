<?php
/**
 * Migration 012: Ready-check system for multiplayer advancement.
 *
 * Tracks which coaches and fantasy managers have marked themselves
 * ready to advance. Also stores league advance settings (auto-advance
 * schedule, cron interval, etc.)
 */
return function (string $driver): array {
    $autoInc = $driver === 'sqlite'
        ? 'INTEGER PRIMARY KEY AUTOINCREMENT'
        : 'INT PRIMARY KEY AUTO_INCREMENT';

    $boolean = $driver === 'sqlite' ? 'INTEGER' : 'TINYINT(1)';

    return [
        // Ready checks per week
        "CREATE TABLE IF NOT EXISTS ready_checks (
            id {$autoInc},
            league_id INT NOT NULL,
            week INT NOT NULL,
            coach_id INT NULL,
            fantasy_manager_id INT NULL,
            type VARCHAR(20) NOT NULL DEFAULT 'coach',
            is_ready {$boolean} NOT NULL DEFAULT 0,
            ready_at DATETIME NULL,
            FOREIGN KEY (league_id) REFERENCES leagues(id) ON DELETE CASCADE,
            UNIQUE(league_id, week, coach_id, type)
        )",

        // League advance settings
        "CREATE TABLE IF NOT EXISTS league_advance_settings (
            id {$autoInc},
            league_id INT NOT NULL UNIQUE,
            advance_mode VARCHAR(20) NOT NULL DEFAULT 'manual',
            auto_advance_hours INT NOT NULL DEFAULT 24,
            last_advance_at DATETIME NULL,
            next_advance_at DATETIME NULL,
            require_all_coaches {$boolean} NOT NULL DEFAULT 1,
            require_all_fantasy {$boolean} NOT NULL DEFAULT 1,
            commissioner_can_force {$boolean} NOT NULL DEFAULT 1,
            FOREIGN KEY (league_id) REFERENCES leagues(id) ON DELETE CASCADE
        )",

        "CREATE INDEX IF NOT EXISTS idx_ready_checks_league_week ON ready_checks(league_id, week)",
    ];
};
