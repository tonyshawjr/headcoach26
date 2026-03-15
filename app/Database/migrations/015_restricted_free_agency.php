<?php
/**
 * Migration: Restricted Free Agency system
 *
 * Adds RFA columns to free_agents table and creates rfa_offer_sheets table.
 */
return function (string $driver): array {
    $autoInc = $driver === 'sqlite'
        ? 'INTEGER PRIMARY KEY AUTOINCREMENT'
        : 'INT PRIMARY KEY AUTO_INCREMENT';

    $boolean = $driver === 'sqlite' ? 'INTEGER' : 'TINYINT(1)';
    $bigint = $driver === 'sqlite' ? 'INTEGER' : 'BIGINT';

    return [
        // Add RFA columns to free_agents table
        "ALTER TABLE free_agents ADD COLUMN is_restricted {$boolean} NOT NULL DEFAULT 0",
        "ALTER TABLE free_agents ADD COLUMN tender_level VARCHAR(20) NULL",
        "ALTER TABLE free_agents ADD COLUMN tender_salary {$bigint} NOT NULL DEFAULT 0",
        "ALTER TABLE free_agents ADD COLUMN original_team_id INT NULL",
        "ALTER TABLE free_agents ADD COLUMN original_draft_round INT NULL",

        // Offer sheets from other teams on restricted free agents
        "CREATE TABLE IF NOT EXISTS rfa_offer_sheets (
            id {$autoInc},
            free_agent_id INT NOT NULL,
            offering_team_id INT NOT NULL,
            salary {$bigint} NOT NULL,
            years INT NOT NULL DEFAULT 1,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            matching_deadline DATETIME NULL,
            created_at DATETIME NOT NULL,
            resolved_at DATETIME NULL,
            FOREIGN KEY (free_agent_id) REFERENCES free_agents(id),
            FOREIGN KEY (offering_team_id) REFERENCES teams(id)
        )",

        // Index for quick lookups
        "CREATE INDEX IF NOT EXISTS idx_rfa_offer_sheets_fa ON rfa_offer_sheets(free_agent_id, status)",
        "CREATE INDEX IF NOT EXISTS idx_free_agents_restricted ON free_agents(league_id, is_restricted)",
    ];
};
