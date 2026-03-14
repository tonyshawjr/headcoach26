<?php
/**
 * Migration: Add Madden-style player attribute columns to players table.
 *
 * Adds 52 new columns: bio/metadata fields, physical stats, ball-carrier,
 * receiving, blocking, defense, quarterback, kicking, and running style.
 * Existing columns (speed, strength, stamina, awareness) are NOT re-added.
 */
return function (string $driver): array {
    return [
        // ── Bio / Metadata ──────────────────────────────────────────────
        "ALTER TABLE players ADD COLUMN height INT NULL",
        "ALTER TABLE players ADD COLUMN weight INT NULL",
        "ALTER TABLE players ADD COLUMN handedness INT NULL DEFAULT 1",
        "ALTER TABLE players ADD COLUMN birthdate VARCHAR(20) NULL",
        "ALTER TABLE players ADD COLUMN years_pro INT NOT NULL DEFAULT 0",
        "ALTER TABLE players ADD COLUMN archetype VARCHAR(60) NULL",
        "ALTER TABLE players ADD COLUMN position_type VARCHAR(20) NULL",
        "ALTER TABLE players ADD COLUMN x_factor VARCHAR(80) NULL",
        "ALTER TABLE players ADD COLUMN superstar_abilities TEXT NULL",

        // ── Physical (speed, strength, stamina already exist) ───────────
        "ALTER TABLE players ADD COLUMN acceleration INT NOT NULL DEFAULT 50",
        "ALTER TABLE players ADD COLUMN agility INT NOT NULL DEFAULT 50",
        "ALTER TABLE players ADD COLUMN jumping INT NOT NULL DEFAULT 50",
        "ALTER TABLE players ADD COLUMN toughness INT NOT NULL DEFAULT 50",

        // ── Ball Carrier ────────────────────────────────────────────────
        "ALTER TABLE players ADD COLUMN bc_vision INT NOT NULL DEFAULT 50",
        "ALTER TABLE players ADD COLUMN break_tackle INT NOT NULL DEFAULT 50",
        "ALTER TABLE players ADD COLUMN carrying INT NOT NULL DEFAULT 50",
        "ALTER TABLE players ADD COLUMN change_of_direction INT NOT NULL DEFAULT 50",
        "ALTER TABLE players ADD COLUMN juke_move INT NOT NULL DEFAULT 50",
        "ALTER TABLE players ADD COLUMN spin_move INT NOT NULL DEFAULT 50",
        "ALTER TABLE players ADD COLUMN stiff_arm INT NOT NULL DEFAULT 50",
        "ALTER TABLE players ADD COLUMN trucking INT NOT NULL DEFAULT 50",

        // ── Receiving ───────────────────────────────────────────────────
        "ALTER TABLE players ADD COLUMN catch_in_traffic INT NOT NULL DEFAULT 50",
        "ALTER TABLE players ADD COLUMN catching INT NOT NULL DEFAULT 50",
        "ALTER TABLE players ADD COLUMN deep_route_running INT NOT NULL DEFAULT 50",
        "ALTER TABLE players ADD COLUMN medium_route_running INT NOT NULL DEFAULT 50",
        "ALTER TABLE players ADD COLUMN short_route_running INT NOT NULL DEFAULT 50",
        "ALTER TABLE players ADD COLUMN spectacular_catch INT NOT NULL DEFAULT 50",
        "ALTER TABLE players ADD COLUMN release INT NOT NULL DEFAULT 50",

        // ── Blocking ────────────────────────────────────────────────────
        "ALTER TABLE players ADD COLUMN impact_blocking INT NOT NULL DEFAULT 50",
        "ALTER TABLE players ADD COLUMN lead_block INT NOT NULL DEFAULT 50",
        "ALTER TABLE players ADD COLUMN pass_block INT NOT NULL DEFAULT 50",
        "ALTER TABLE players ADD COLUMN pass_block_finesse INT NOT NULL DEFAULT 50",
        "ALTER TABLE players ADD COLUMN pass_block_power INT NOT NULL DEFAULT 50",
        "ALTER TABLE players ADD COLUMN run_block INT NOT NULL DEFAULT 50",
        "ALTER TABLE players ADD COLUMN run_block_finesse INT NOT NULL DEFAULT 50",
        "ALTER TABLE players ADD COLUMN run_block_power INT NOT NULL DEFAULT 50",

        // ── Defense ─────────────────────────────────────────────────────
        "ALTER TABLE players ADD COLUMN block_shedding INT NOT NULL DEFAULT 50",
        "ALTER TABLE players ADD COLUMN finesse_moves INT NOT NULL DEFAULT 50",
        "ALTER TABLE players ADD COLUMN hit_power INT NOT NULL DEFAULT 50",
        "ALTER TABLE players ADD COLUMN man_coverage INT NOT NULL DEFAULT 50",
        "ALTER TABLE players ADD COLUMN play_recognition INT NOT NULL DEFAULT 50",
        "ALTER TABLE players ADD COLUMN power_moves INT NOT NULL DEFAULT 50",
        "ALTER TABLE players ADD COLUMN press INT NOT NULL DEFAULT 50",
        "ALTER TABLE players ADD COLUMN pursuit INT NOT NULL DEFAULT 50",
        "ALTER TABLE players ADD COLUMN tackle INT NOT NULL DEFAULT 50",
        "ALTER TABLE players ADD COLUMN zone_coverage INT NOT NULL DEFAULT 50",

        // ── Quarterback ─────────────────────────────────────────────────
        "ALTER TABLE players ADD COLUMN break_sack INT NOT NULL DEFAULT 50",
        "ALTER TABLE players ADD COLUMN play_action INT NOT NULL DEFAULT 50",
        "ALTER TABLE players ADD COLUMN throw_accuracy_deep INT NOT NULL DEFAULT 50",
        "ALTER TABLE players ADD COLUMN throw_accuracy_mid INT NOT NULL DEFAULT 50",
        "ALTER TABLE players ADD COLUMN throw_accuracy_short INT NOT NULL DEFAULT 50",
        "ALTER TABLE players ADD COLUMN throw_on_the_run INT NOT NULL DEFAULT 50",
        "ALTER TABLE players ADD COLUMN throw_power INT NOT NULL DEFAULT 50",
        "ALTER TABLE players ADD COLUMN throw_under_pressure INT NOT NULL DEFAULT 50",

        // ── Kicking ─────────────────────────────────────────────────────
        "ALTER TABLE players ADD COLUMN kick_accuracy INT NOT NULL DEFAULT 50",
        "ALTER TABLE players ADD COLUMN kick_power INT NOT NULL DEFAULT 50",
        "ALTER TABLE players ADD COLUMN kick_return INT NOT NULL DEFAULT 50",

        // ── Other ───────────────────────────────────────────────────────
        "ALTER TABLE players ADD COLUMN running_style VARCHAR(60) NULL",

        // ── Image ───────────────────────────────────────────────────────
        "ALTER TABLE players ADD COLUMN image_url TEXT NULL",
    ];
};
