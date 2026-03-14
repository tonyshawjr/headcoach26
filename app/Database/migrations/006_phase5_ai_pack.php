<?php
/**
 * Migration: Phase 5 — AI Pack (AI generation log, Roster imports)
 */
return function (string $driver): array {
    $autoInc = $driver === 'sqlite'
        ? 'INTEGER PRIMARY KEY AUTOINCREMENT'
        : 'INT PRIMARY KEY AUTO_INCREMENT';

    return [
        // AI generation log
        "CREATE TABLE IF NOT EXISTS ai_generations (
            id {$autoInc},
            league_id INTEGER NOT NULL,
            type TEXT NOT NULL,
            prompt_tokens INTEGER NOT NULL DEFAULT 0,
            completion_tokens INTEGER NOT NULL DEFAULT 0,
            model TEXT NOT NULL DEFAULT '',
            context_summary TEXT DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT '',
            FOREIGN KEY (league_id) REFERENCES leagues(id)
        )",

        // Roster imports
        "CREATE TABLE IF NOT EXISTS roster_imports (
            id {$autoInc},
            league_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            filename TEXT NOT NULL,
            format TEXT NOT NULL DEFAULT 'csv',
            total_rows INTEGER NOT NULL DEFAULT 0,
            imported INTEGER NOT NULL DEFAULT 0,
            skipped INTEGER NOT NULL DEFAULT 0,
            errors TEXT NOT NULL DEFAULT '[]',
            status TEXT NOT NULL DEFAULT 'pending',
            created_at TEXT NOT NULL DEFAULT '',
            completed_at TEXT DEFAULT NULL,
            FOREIGN KEY (league_id) REFERENCES leagues(id),
            FOREIGN KEY (user_id) REFERENCES users(id)
        )",

        // Indexes
        "CREATE INDEX IF NOT EXISTS idx_ai_gen_league ON ai_generations(league_id, type)",
        "CREATE INDEX IF NOT EXISTS idx_roster_imports_league ON roster_imports(league_id)",
    ];
};
