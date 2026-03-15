<?php
/**
 * Migration: Phase 4 — Multiplayer (Invites, Messages, Notifications, Commissioner)
 */
return function (string $driver): array {
    $autoInc = $driver === 'sqlite'
        ? 'INTEGER PRIMARY KEY AUTOINCREMENT'
        : 'INT PRIMARY KEY AUTO_INCREMENT';

    return [
        // League invitations
        "CREATE TABLE IF NOT EXISTS league_invites (
            id {$autoInc},
            league_id INTEGER NOT NULL,
            invite_code TEXT NOT NULL,
            team_id INTEGER DEFAULT NULL,
            invited_by INTEGER NOT NULL,
            claimed_by INTEGER DEFAULT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            expires_at TEXT DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT '',
            claimed_at TEXT DEFAULT NULL,
            FOREIGN KEY (league_id) REFERENCES leagues(id),
            FOREIGN KEY (team_id) REFERENCES teams(id),
            FOREIGN KEY (invited_by) REFERENCES users(id),
            FOREIGN KEY (claimed_by) REFERENCES users(id)
        )",

        // League messages / message board
        "CREATE TABLE IF NOT EXISTS league_messages (
            id {$autoInc},
            league_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            coach_id INTEGER DEFAULT NULL,
            channel TEXT NOT NULL DEFAULT 'general',
            body TEXT NOT NULL,
            is_pinned INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT '',
            FOREIGN KEY (league_id) REFERENCES leagues(id),
            FOREIGN KEY (user_id) REFERENCES users(id)
        )",

        // Notifications
        "CREATE TABLE IF NOT EXISTS notifications (
            id {$autoInc},
            user_id INTEGER NOT NULL,
            league_id INTEGER NOT NULL,
            type TEXT NOT NULL,
            title TEXT NOT NULL,
            body TEXT NOT NULL DEFAULT '',
            data TEXT NOT NULL DEFAULT '{}',
            is_read INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT '',
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (league_id) REFERENCES leagues(id)
        )",

        // Commissioner settings
        "CREATE TABLE IF NOT EXISTS commissioner_settings (
            id {$autoInc},
            league_id INTEGER NOT NULL UNIQUE,
            trade_review TEXT NOT NULL DEFAULT 'commissioner',
            trade_review_hours INTEGER NOT NULL DEFAULT 24,
            game_plan_deadline_hours INTEGER NOT NULL DEFAULT 24,
            auto_sim INTEGER NOT NULL DEFAULT 0,
            sim_interval_hours INTEGER NOT NULL DEFAULT 24,
            allow_ai_fill INTEGER NOT NULL DEFAULT 1,
            force_advance_enabled INTEGER NOT NULL DEFAULT 1,
            max_roster_size INTEGER NOT NULL DEFAULT 53,
            salary_cap INTEGER NOT NULL DEFAULT 225000000,
            trade_deadline_week INTEGER NOT NULL DEFAULT 12,
            salary_cap_enabled INTEGER NOT NULL DEFAULT 1,
            allow_ai_trades INTEGER NOT NULL DEFAULT 1,
            league_paused INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT DEFAULT NULL,
            FOREIGN KEY (league_id) REFERENCES leagues(id)
        )",

        // Game plan submissions (for async multiplayer)
        "CREATE TABLE IF NOT EXISTS game_plan_submissions (
            id {$autoInc},
            game_id INTEGER NOT NULL,
            team_id INTEGER NOT NULL,
            coach_id INTEGER NOT NULL,
            offensive_scheme TEXT NOT NULL DEFAULT 'balanced',
            defensive_scheme TEXT NOT NULL DEFAULT 'base_43',
            submitted_at TEXT DEFAULT NULL,
            deadline_at TEXT DEFAULT NULL,
            is_locked INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY (game_id) REFERENCES games(id),
            FOREIGN KEY (team_id) REFERENCES teams(id),
            FOREIGN KEY (coach_id) REFERENCES coaches(id)
        )",

        // Trade reviews (commissioner approval workflow)
        "CREATE TABLE IF NOT EXISTS trade_reviews (
            id {$autoInc},
            trade_id INTEGER NOT NULL,
            league_id INTEGER NOT NULL,
            reviewer_id INTEGER DEFAULT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            reason TEXT DEFAULT NULL,
            reviewed_at TEXT DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT '',
            FOREIGN KEY (trade_id) REFERENCES trades(id),
            FOREIGN KEY (league_id) REFERENCES leagues(id),
            FOREIGN KEY (reviewer_id) REFERENCES users(id)
        )",

        // Indexes
        "CREATE INDEX IF NOT EXISTS idx_invites_code ON league_invites(invite_code)",
        "CREATE INDEX IF NOT EXISTS idx_invites_league ON league_invites(league_id)",
        "CREATE INDEX IF NOT EXISTS idx_messages_league ON league_messages(league_id, channel)",
        "CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications(user_id, is_read)",
        "CREATE INDEX IF NOT EXISTS idx_gps_game ON game_plan_submissions(game_id, team_id)",
        "CREATE INDEX IF NOT EXISTS idx_trade_reviews_trade ON trade_reviews(trade_id)",

        // Add columns if they don't already exist (safe for re-runs on existing DBs)
        "ALTER TABLE commissioner_settings ADD COLUMN trade_deadline_week INTEGER NOT NULL DEFAULT 12",
        "ALTER TABLE commissioner_settings ADD COLUMN salary_cap_enabled INTEGER NOT NULL DEFAULT 1",
        "ALTER TABLE commissioner_settings ADD COLUMN allow_ai_trades INTEGER NOT NULL DEFAULT 1",
    ];
};
