<?php
/**
 * Migration: Core tables for Head Coach 26
 */
return function (string $driver): array {
    $autoInc = $driver === 'sqlite'
        ? 'INTEGER PRIMARY KEY AUTOINCREMENT'
        : 'INT PRIMARY KEY AUTO_INCREMENT';

    $boolean = $driver === 'sqlite' ? 'INTEGER' : 'TINYINT(1)';
    $bigint = $driver === 'sqlite' ? 'INTEGER' : 'BIGINT';

    return [
        // Users
        "CREATE TABLE IF NOT EXISTS users (
            id {$autoInc},
            username VARCHAR(50) NOT NULL UNIQUE,
            email VARCHAR(120) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            is_admin {$boolean} NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL
        )",

        // Leagues
        "CREATE TABLE IF NOT EXISTS leagues (
            id {$autoInc},
            name VARCHAR(100) NOT NULL,
            slug VARCHAR(100) NOT NULL UNIQUE,
            season_year INT NOT NULL DEFAULT 2026,
            current_week INT NOT NULL DEFAULT 0,
            phase VARCHAR(20) NOT NULL DEFAULT 'preseason',
            commissioner_id INT NULL,
            settings TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        )",

        // Teams
        "CREATE TABLE IF NOT EXISTS teams (
            id {$autoInc},
            league_id INT NOT NULL,
            city VARCHAR(60) NOT NULL,
            name VARCHAR(60) NOT NULL,
            abbreviation VARCHAR(4) NOT NULL,
            conference VARCHAR(10) NOT NULL,
            division VARCHAR(10) NOT NULL,
            primary_color VARCHAR(7) NOT NULL,
            secondary_color VARCHAR(7) NOT NULL,
            logo_emoji VARCHAR(10) NOT NULL DEFAULT '',
            overall_rating INT NOT NULL DEFAULT 75,
            offense_rating INT NOT NULL DEFAULT 75,
            defense_rating INT NOT NULL DEFAULT 75,
            salary_cap {$bigint} NOT NULL DEFAULT 225000000,
            cap_used {$bigint} NOT NULL DEFAULT 0,
            wins INT NOT NULL DEFAULT 0,
            losses INT NOT NULL DEFAULT 0,
            ties INT NOT NULL DEFAULT 0,
            points_for INT NOT NULL DEFAULT 0,
            points_against INT NOT NULL DEFAULT 0,
            streak VARCHAR(10) NOT NULL DEFAULT '',
            home_field_advantage INT NOT NULL DEFAULT 3,
            morale INT NOT NULL DEFAULT 70,
            FOREIGN KEY (league_id) REFERENCES leagues(id) ON DELETE CASCADE
        )",

        // Coaches
        "CREATE TABLE IF NOT EXISTS coaches (
            id {$autoInc},
            league_id INT NOT NULL,
            team_id INT NULL,
            user_id INT NULL,
            name VARCHAR(100) NOT NULL,
            is_human {$boolean} NOT NULL DEFAULT 0,
            archetype VARCHAR(30) NULL,
            influence INT NOT NULL DEFAULT 50,
            job_security INT NOT NULL DEFAULT 70,
            media_rating INT NOT NULL DEFAULT 50,
            contract_years INT NOT NULL DEFAULT 3,
            contract_salary INT NOT NULL DEFAULT 5000000,
            personality TEXT NULL,
            owner_expectations TEXT NULL,
            created_at DATETIME NOT NULL,
            FOREIGN KEY (league_id) REFERENCES leagues(id) ON DELETE CASCADE,
            FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        )",

        // Seasons
        "CREATE TABLE IF NOT EXISTS seasons (
            id {$autoInc},
            league_id INT NOT NULL,
            year INT NOT NULL,
            is_current {$boolean} NOT NULL DEFAULT 1,
            champion_team_id INT NULL,
            mvp_player_id INT NULL,
            created_at DATETIME NOT NULL,
            FOREIGN KEY (league_id) REFERENCES leagues(id) ON DELETE CASCADE
        )",

        // Players
        "CREATE TABLE IF NOT EXISTS players (
            id {$autoInc},
            league_id INT NOT NULL,
            team_id INT NULL,
            first_name VARCHAR(50) NOT NULL,
            last_name VARCHAR(50) NOT NULL,
            position VARCHAR(5) NOT NULL,
            age INT NOT NULL,
            overall_rating INT NOT NULL,
            speed INT NOT NULL DEFAULT 50,
            strength INT NOT NULL DEFAULT 50,
            awareness INT NOT NULL DEFAULT 50,
            stamina INT NOT NULL DEFAULT 50,
            injury_prone INT NOT NULL DEFAULT 20,
            positional_ratings TEXT NULL,
            potential VARCHAR(15) NOT NULL DEFAULT 'normal',
            personality VARCHAR(20) NOT NULL DEFAULT 'team_player',
            morale VARCHAR(15) NOT NULL DEFAULT 'content',
            experience INT NOT NULL DEFAULT 0,
            college VARCHAR(80) NULL,
            jersey_number INT NULL,
            is_rookie {$boolean} NOT NULL DEFAULT 0,
            is_fictional {$boolean} NOT NULL DEFAULT 1,
            status VARCHAR(15) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            FOREIGN KEY (league_id) REFERENCES leagues(id) ON DELETE CASCADE,
            FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL
        )",

        // Depth Chart
        "CREATE TABLE IF NOT EXISTS depth_chart (
            id {$autoInc},
            team_id INT NOT NULL,
            position_group VARCHAR(5) NOT NULL,
            slot INT NOT NULL DEFAULT 1,
            player_id INT NOT NULL,
            FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
            FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
            UNIQUE(team_id, position_group, slot)
        )",

        // Games
        "CREATE TABLE IF NOT EXISTS games (
            id {$autoInc},
            league_id INT NOT NULL,
            season_id INT NOT NULL,
            week INT NOT NULL,
            game_type VARCHAR(20) NOT NULL DEFAULT 'regular',
            home_team_id INT NOT NULL,
            away_team_id INT NOT NULL,
            home_score INT NULL,
            away_score INT NULL,
            is_simulated {$boolean} NOT NULL DEFAULT 0,
            weather VARCHAR(20) NULL,
            home_game_plan TEXT NULL,
            away_game_plan TEXT NULL,
            box_score TEXT NULL,
            turning_point TEXT NULL,
            player_grades TEXT NULL,
            simulated_at DATETIME NULL,
            FOREIGN KEY (league_id) REFERENCES leagues(id) ON DELETE CASCADE,
            FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE CASCADE,
            FOREIGN KEY (home_team_id) REFERENCES teams(id),
            FOREIGN KEY (away_team_id) REFERENCES teams(id)
        )",

        // Game Stats
        "CREATE TABLE IF NOT EXISTS game_stats (
            id {$autoInc},
            game_id INT NOT NULL,
            player_id INT NOT NULL,
            team_id INT NOT NULL,
            pass_attempts INT NOT NULL DEFAULT 0,
            pass_completions INT NOT NULL DEFAULT 0,
            pass_yards INT NOT NULL DEFAULT 0,
            pass_tds INT NOT NULL DEFAULT 0,
            interceptions INT NOT NULL DEFAULT 0,
            rush_attempts INT NOT NULL DEFAULT 0,
            rush_yards INT NOT NULL DEFAULT 0,
            rush_tds INT NOT NULL DEFAULT 0,
            targets INT NOT NULL DEFAULT 0,
            receptions INT NOT NULL DEFAULT 0,
            rec_yards INT NOT NULL DEFAULT 0,
            rec_tds INT NOT NULL DEFAULT 0,
            tackles INT NOT NULL DEFAULT 0,
            sacks REAL NOT NULL DEFAULT 0,
            interceptions_def INT NOT NULL DEFAULT 0,
            forced_fumbles INT NOT NULL DEFAULT 0,
            fg_attempts INT NOT NULL DEFAULT 0,
            fg_made INT NOT NULL DEFAULT 0,
            punt_yards INT NOT NULL DEFAULT 0,
            grade VARCHAR(2) NULL,
            FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
            FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
            FOREIGN KEY (team_id) REFERENCES teams(id)
        )",

        // Contracts
        "CREATE TABLE IF NOT EXISTS contracts (
            id {$autoInc},
            player_id INT NOT NULL,
            team_id INT NOT NULL,
            years_total INT NOT NULL,
            years_remaining INT NOT NULL,
            salary_annual INT NOT NULL,
            cap_hit INT NOT NULL,
            guaranteed INT NOT NULL DEFAULT 0,
            dead_cap INT NOT NULL DEFAULT 0,
            signed_at DATETIME NOT NULL,
            FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
            FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE
        )",

        // Injuries
        "CREATE TABLE IF NOT EXISTS injuries (
            id {$autoInc},
            player_id INT NOT NULL,
            team_id INT NOT NULL,
            type VARCHAR(50) NOT NULL,
            severity VARCHAR(20) NOT NULL,
            weeks_remaining INT NOT NULL,
            game_id INT NULL,
            occurred_at DATETIME NOT NULL,
            FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
            FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE
        )",

        // Indexes
        "CREATE INDEX IF NOT EXISTS idx_players_team ON players(team_id)",
        "CREATE INDEX IF NOT EXISTS idx_players_league ON players(league_id)",
        "CREATE INDEX IF NOT EXISTS idx_players_position ON players(position)",
        "CREATE INDEX IF NOT EXISTS idx_games_league_week ON games(league_id, week)",
        "CREATE INDEX IF NOT EXISTS idx_games_teams ON games(home_team_id, away_team_id)",
        "CREATE INDEX IF NOT EXISTS idx_game_stats_game ON game_stats(game_id)",
        "CREATE INDEX IF NOT EXISTS idx_game_stats_player ON game_stats(player_id)",
        "CREATE INDEX IF NOT EXISTS idx_depth_chart_team ON depth_chart(team_id)",
        "CREATE INDEX IF NOT EXISTS idx_injuries_player ON injuries(player_id)",
        "CREATE INDEX IF NOT EXISTS idx_contracts_player ON contracts(player_id)",
    ];
};
