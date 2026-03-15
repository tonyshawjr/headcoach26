<?php
/**
 * Migration: Add void years and incentive clauses to contracts.
 *
 * Void years spread signing-bonus proration over extra years, lowering
 * the annual cap hit now at the cost of accelerated dead cap later.
 *
 * Incentive clauses (roster bonus, performance, playing time) are NLTBE
 * — they only count against the cap once triggered.
 */
return function (string $driver): array {
    return [
        // Void year count (e.g. 2 void years on a 3+2 deal)
        "ALTER TABLE contracts ADD COLUMN void_years INT DEFAULT 0",

        // Incentive flag
        "ALTER TABLE contracts ADD COLUMN has_incentives TINYINT DEFAULT 0",

        // Type: roster_bonus | performance | playing_time
        "ALTER TABLE contracts ADD COLUMN incentive_type VARCHAR(30) NULL",

        // Dollar amount of the incentive
        "ALTER TABLE contracts ADD COLUMN incentive_value INT DEFAULT 0",

        // JSON trigger condition, e.g. {"stat":"rush_yards","threshold":1000}
        "ALTER TABLE contracts ADD COLUMN incentive_threshold TEXT NULL",
    ];
};
