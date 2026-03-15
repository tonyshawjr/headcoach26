<?php
/**
 * Migration 018: Add offense/defense ratings to teams.
 */
return function (string $driver): array {
    return [
        "ALTER TABLE teams ADD COLUMN offense_rating INT NOT NULL DEFAULT 75",
        "ALTER TABLE teams ADD COLUMN defense_rating INT NOT NULL DEFAULT 75",
    ];
};
