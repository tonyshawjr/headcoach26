<?php
/**
 * Migration 013: Franchise tag system.
 *
 * Adds franchise_tag_type to contracts table and franchise_tags_used to teams table.
 * Each team can use ONE franchise tag per offseason year.
 */
return function (string $driver): array {
    return [
        // Add franchise tag type to contracts (nullable — only set for tagged contracts)
        "ALTER TABLE contracts ADD COLUMN franchise_tag_type VARCHAR(20) NULL",

        // Track how many franchise tags each team has used (resets each offseason)
        "ALTER TABLE teams ADD COLUMN franchise_tags_used INT NOT NULL DEFAULT 0",

        // Index for quick lookups of tagged players
        "CREATE INDEX IF NOT EXISTS idx_contracts_franchise_tag ON contracts(franchise_tag_type)",
    ];
};
