<?php
/**
 * Migration: Phase 2 Narrative Layer additions
 *
 * Adds columns needed for the columnist system, coach personality,
 * and narrative arc tracking.
 *
 * NOTE: SQLite only supports adding ONE column per ALTER TABLE statement.
 */
return function (string $driver): array {
    return [
        // articles: add column_persona for multi-columnist system
        "ALTER TABLE articles ADD COLUMN column_persona TEXT NULL",

        // articles: link articles to the narrative arc they cover
        "ALTER TABLE articles ADD COLUMN narrative_arc_id INTEGER NULL",

        // coaches: JSON string for AI coach personality traits
        "ALTER TABLE coaches ADD COLUMN personality_traits TEXT NULL",

        // narrative_arcs: add player_id for single-player arcs (breakout_player, etc.)
        "ALTER TABLE narrative_arcs ADD COLUMN player_id INTEGER NULL",

        // narrative_arcs: add team_id for single-team arcs (winning_streak, etc.)
        "ALTER TABLE narrative_arcs ADD COLUMN team_id INTEGER NULL",

        // narrative_arcs: JSON payload for arc-specific data (streak counts, thresholds, etc.)
        "ALTER TABLE narrative_arcs ADD COLUMN data TEXT NULL",

        // Index for fast narrative arc lookups by status
        "CREATE INDEX IF NOT EXISTS idx_narrative_arcs_status ON narrative_arcs(league_id, status)",

        // Index for narrative arcs by team
        "CREATE INDEX IF NOT EXISTS idx_narrative_arcs_team ON narrative_arcs(team_id)",
    ];
};
