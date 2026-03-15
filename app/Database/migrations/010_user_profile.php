<?php
/**
 * Migration 010: User profile fields
 *
 * Adds avatar, display name, bio, and updated_at to users table.
 * Adds avatar and coaching philosophy to coaches table.
 */
return function (string $driver): array {
    return [
        // User profile fields
        "ALTER TABLE users ADD COLUMN display_name VARCHAR(100) NULL",
        "ALTER TABLE users ADD COLUMN avatar_url VARCHAR(500) NULL",
        "ALTER TABLE users ADD COLUMN bio TEXT NULL",
        "ALTER TABLE users ADD COLUMN updated_at DATETIME NULL",

        // Coach profile fields
        "ALTER TABLE coaches ADD COLUMN avatar_url VARCHAR(500) NULL",
        "ALTER TABLE coaches ADD COLUMN coaching_philosophy TEXT NULL",
    ];
};
