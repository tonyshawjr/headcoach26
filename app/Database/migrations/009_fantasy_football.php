<?php
/**
 * Migration: Fantasy Football system
 *
 * Adds tables for fantasy leagues, managers (human + AI), rosters,
 * weekly scoring, matchups, transactions, and trade proposals.
 */
return function (string $driver): array {
    $autoInc = $driver === 'sqlite'
        ? 'INTEGER PRIMARY KEY AUTOINCREMENT'
        : 'INT PRIMARY KEY AUTO_INCREMENT';

    $boolean = $driver === 'sqlite' ? 'INTEGER' : 'TINYINT(1)';

    return [
        // ── Fantasy Leagues ────────────────────────────────────────────
        "CREATE TABLE IF NOT EXISTS fantasy_leagues (
            id {$autoInc},
            league_id INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            commissioner_coach_id INT NOT NULL,
            num_teams INT NOT NULL DEFAULT 10,
            scoring_type VARCHAR(20) NOT NULL DEFAULT 'ppr',
            scoring_rules TEXT NULL,
            roster_slots TEXT NULL,
            num_playoff_teams INT NOT NULL DEFAULT 4,
            playoff_start_week INT NOT NULL DEFAULT 14,
            championship_week INT NOT NULL DEFAULT 16,
            regular_season_end_week INT NOT NULL DEFAULT 13,
            waiver_type VARCHAR(20) NOT NULL DEFAULT 'priority',
            faab_budget INT NOT NULL DEFAULT 100,
            trade_review_hours INT NOT NULL DEFAULT 24,
            draft_type VARCHAR(20) NOT NULL DEFAULT 'snake',
            draft_rounds INT NOT NULL DEFAULT 15,
            draft_status VARCHAR(20) NOT NULL DEFAULT 'pending',
            status VARCHAR(20) NOT NULL DEFAULT 'setup',
            invite_code VARCHAR(20) NULL,
            max_human_players INT NOT NULL DEFAULT 1,
            created_week INT NOT NULL DEFAULT 0,
            season_year INT NOT NULL DEFAULT 2026,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            FOREIGN KEY (league_id) REFERENCES leagues(id) ON DELETE CASCADE
        )",

        // ── Fantasy Managers (human or AI) ─────────────────────────────
        "CREATE TABLE IF NOT EXISTS fantasy_managers (
            id {$autoInc},
            fantasy_league_id INT NOT NULL,
            coach_id INT NULL,
            user_id INT NULL,
            team_name VARCHAR(100) NOT NULL,
            owner_name VARCHAR(60) NOT NULL,
            avatar_color VARCHAR(7) NOT NULL DEFAULT '#3B82F6',
            is_ai {$boolean} NOT NULL DEFAULT 0,
            personality VARCHAR(30) NULL,
            favorite_nfl_teams TEXT NULL,
            draft_position INT NULL,
            faab_remaining INT NOT NULL DEFAULT 100,
            waiver_priority INT NOT NULL DEFAULT 1,
            wins INT NOT NULL DEFAULT 0,
            losses INT NOT NULL DEFAULT 0,
            ties INT NOT NULL DEFAULT 0,
            points_for REAL NOT NULL DEFAULT 0,
            points_against REAL NOT NULL DEFAULT 0,
            streak VARCHAR(10) NOT NULL DEFAULT '',
            playoff_seed INT NULL,
            is_eliminated {$boolean} NOT NULL DEFAULT 0,
            is_champion {$boolean} NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            FOREIGN KEY (fantasy_league_id) REFERENCES fantasy_leagues(id) ON DELETE CASCADE
        )",

        // ── Fantasy Rosters (who owns which player) ────────────────────
        "CREATE TABLE IF NOT EXISTS fantasy_rosters (
            id {$autoInc},
            fantasy_league_id INT NOT NULL,
            fantasy_manager_id INT NOT NULL,
            player_id INT NOT NULL,
            roster_slot VARCHAR(10) NOT NULL DEFAULT 'BN',
            is_starter {$boolean} NOT NULL DEFAULT 0,
            acquired_via VARCHAR(20) NOT NULL DEFAULT 'draft',
            acquired_week INT NOT NULL DEFAULT 0,
            FOREIGN KEY (fantasy_league_id) REFERENCES fantasy_leagues(id) ON DELETE CASCADE,
            FOREIGN KEY (fantasy_manager_id) REFERENCES fantasy_managers(id) ON DELETE CASCADE,
            FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
            UNIQUE(fantasy_league_id, player_id)
        )",

        // ── Fantasy Scores (weekly points per player) ──────────────────
        "CREATE TABLE IF NOT EXISTS fantasy_scores (
            id {$autoInc},
            fantasy_league_id INT NOT NULL,
            player_id INT NOT NULL,
            week INT NOT NULL,
            points REAL NOT NULL DEFAULT 0,
            breakdown TEXT NULL,
            FOREIGN KEY (fantasy_league_id) REFERENCES fantasy_leagues(id) ON DELETE CASCADE,
            FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
            UNIQUE(fantasy_league_id, player_id, week)
        )",

        // ── Fantasy Matchups (weekly head-to-head) ─────────────────────
        "CREATE TABLE IF NOT EXISTS fantasy_matchups (
            id {$autoInc},
            fantasy_league_id INT NOT NULL,
            week INT NOT NULL,
            manager1_id INT NOT NULL,
            manager2_id INT NOT NULL,
            manager1_score REAL NULL,
            manager2_score REAL NULL,
            winner_id INT NULL,
            is_playoff {$boolean} NOT NULL DEFAULT 0,
            is_championship {$boolean} NOT NULL DEFAULT 0,
            is_consolation {$boolean} NOT NULL DEFAULT 0,
            FOREIGN KEY (fantasy_league_id) REFERENCES fantasy_leagues(id) ON DELETE CASCADE,
            FOREIGN KEY (manager1_id) REFERENCES fantasy_managers(id) ON DELETE CASCADE,
            FOREIGN KEY (manager2_id) REFERENCES fantasy_managers(id) ON DELETE CASCADE
        )",

        // ── Fantasy Transactions (audit log) ───────────────────────────
        "CREATE TABLE IF NOT EXISTS fantasy_transactions (
            id {$autoInc},
            fantasy_league_id INT NOT NULL,
            fantasy_manager_id INT NOT NULL,
            type VARCHAR(20) NOT NULL,
            player_id INT NULL,
            player2_id INT NULL,
            details TEXT NULL,
            week INT NOT NULL,
            created_at DATETIME NOT NULL,
            FOREIGN KEY (fantasy_league_id) REFERENCES fantasy_leagues(id) ON DELETE CASCADE,
            FOREIGN KEY (fantasy_manager_id) REFERENCES fantasy_managers(id) ON DELETE CASCADE
        )",

        // ── Fantasy Trade Proposals ────────────────────────────────────
        "CREATE TABLE IF NOT EXISTS fantasy_trade_proposals (
            id {$autoInc},
            fantasy_league_id INT NOT NULL,
            proposer_id INT NOT NULL,
            recipient_id INT NOT NULL,
            players_offered TEXT NOT NULL,
            players_requested TEXT NOT NULL,
            message TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            responded_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            FOREIGN KEY (fantasy_league_id) REFERENCES fantasy_leagues(id) ON DELETE CASCADE,
            FOREIGN KEY (proposer_id) REFERENCES fantasy_managers(id) ON DELETE CASCADE,
            FOREIGN KEY (recipient_id) REFERENCES fantasy_managers(id) ON DELETE CASCADE
        )",

        // ── Indexes ────────────────────────────────────────────────────
        "CREATE INDEX IF NOT EXISTS idx_fantasy_leagues_league ON fantasy_leagues(league_id)",
        "CREATE INDEX IF NOT EXISTS idx_fantasy_managers_league ON fantasy_managers(fantasy_league_id)",
        "CREATE INDEX IF NOT EXISTS idx_fantasy_managers_coach ON fantasy_managers(coach_id)",
        "CREATE INDEX IF NOT EXISTS idx_fantasy_rosters_manager ON fantasy_rosters(fantasy_manager_id)",
        "CREATE INDEX IF NOT EXISTS idx_fantasy_rosters_player ON fantasy_rosters(player_id)",
        "CREATE INDEX IF NOT EXISTS idx_fantasy_scores_week ON fantasy_scores(fantasy_league_id, week)",
        "CREATE INDEX IF NOT EXISTS idx_fantasy_matchups_week ON fantasy_matchups(fantasy_league_id, week)",
        "CREATE INDEX IF NOT EXISTS idx_fantasy_transactions_manager ON fantasy_transactions(fantasy_manager_id)",
        "CREATE INDEX IF NOT EXISTS idx_fantasy_trades_status ON fantasy_trade_proposals(status)",
    ];
};
