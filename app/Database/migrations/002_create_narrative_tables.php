<?php
/**
 * Migration: Narrative and media tables
 */
return function (string $driver): array {
    $autoInc = $driver === 'sqlite'
        ? 'INTEGER PRIMARY KEY AUTOINCREMENT'
        : 'INT PRIMARY KEY AUTO_INCREMENT';

    $boolean = $driver === 'sqlite' ? 'INTEGER' : 'TINYINT(1)';

    return [
        // Articles
        "CREATE TABLE IF NOT EXISTS articles (
            id {$autoInc},
            league_id INT NOT NULL,
            season_id INT NOT NULL,
            week INT NULL,
            type VARCHAR(30) NOT NULL,
            headline VARCHAR(255) NOT NULL,
            body TEXT NOT NULL,
            author_name VARCHAR(60) NOT NULL,
            author_persona VARCHAR(30) NULL,
            team_id INT NULL,
            player_id INT NULL,
            game_id INT NULL,
            is_ai_generated {$boolean} NOT NULL DEFAULT 0,
            published_at DATETIME NOT NULL,
            FOREIGN KEY (league_id) REFERENCES leagues(id) ON DELETE CASCADE
        )",

        // Social Posts (GridironX)
        "CREATE TABLE IF NOT EXISTS social_posts (
            id {$autoInc},
            league_id INT NOT NULL,
            season_id INT NOT NULL,
            week INT NULL,
            handle VARCHAR(50) NOT NULL,
            display_name VARCHAR(80) NOT NULL,
            avatar_type VARCHAR(20) NOT NULL DEFAULT 'player',
            team_id INT NULL,
            player_id INT NULL,
            body TEXT NOT NULL,
            likes INT NOT NULL DEFAULT 0,
            reposts INT NOT NULL DEFAULT 0,
            is_ai_generated {$boolean} NOT NULL DEFAULT 0,
            posted_at DATETIME NOT NULL,
            FOREIGN KEY (league_id) REFERENCES leagues(id) ON DELETE CASCADE
        )",

        // Press Conferences
        "CREATE TABLE IF NOT EXISTS press_conferences (
            id {$autoInc},
            league_id INT NOT NULL,
            coach_id INT NOT NULL,
            game_id INT NULL,
            week INT NOT NULL,
            type VARCHAR(15) NOT NULL,
            questions TEXT NOT NULL,
            answers TEXT NULL,
            consequences TEXT NULL,
            media_rating_change INT NOT NULL DEFAULT 0,
            completed_at DATETIME NULL,
            FOREIGN KEY (league_id) REFERENCES leagues(id) ON DELETE CASCADE,
            FOREIGN KEY (coach_id) REFERENCES coaches(id) ON DELETE CASCADE
        )",

        // Narrative Arcs
        "CREATE TABLE IF NOT EXISTS narrative_arcs (
            id {$autoInc},
            league_id INT NOT NULL,
            season_id INT NOT NULL,
            type VARCHAR(40) NOT NULL,
            title VARCHAR(200) NOT NULL,
            description TEXT NOT NULL,
            status VARCHAR(15) NOT NULL DEFAULT 'active',
            team_ids TEXT NULL,
            player_ids TEXT NULL,
            started_week INT NOT NULL,
            resolved_week INT NULL,
            metadata TEXT NULL,
            FOREIGN KEY (league_id) REFERENCES leagues(id) ON DELETE CASCADE
        )",

        // Media Ratings
        "CREATE TABLE IF NOT EXISTS media_ratings (
            id {$autoInc},
            coach_id INT NOT NULL,
            league_id INT NOT NULL,
            week INT NOT NULL,
            rating INT NOT NULL,
            change_amount INT NOT NULL DEFAULT 0,
            reason VARCHAR(255) NULL,
            FOREIGN KEY (coach_id) REFERENCES coaches(id) ON DELETE CASCADE
        )",

        // Ticker Items (breaking news)
        "CREATE TABLE IF NOT EXISTS ticker_items (
            id {$autoInc},
            league_id INT NOT NULL,
            text VARCHAR(255) NOT NULL,
            type VARCHAR(20) NOT NULL DEFAULT 'news',
            team_id INT NULL,
            week INT NULL,
            created_at DATETIME NOT NULL,
            FOREIGN KEY (league_id) REFERENCES leagues(id) ON DELETE CASCADE
        )",

        // Indexes
        "CREATE INDEX IF NOT EXISTS idx_articles_league_week ON articles(league_id, week)",
        "CREATE INDEX IF NOT EXISTS idx_articles_type ON articles(type)",
        "CREATE INDEX IF NOT EXISTS idx_articles_team ON articles(team_id)",
        "CREATE INDEX IF NOT EXISTS idx_social_posts_league ON social_posts(league_id, week)",
        "CREATE INDEX IF NOT EXISTS idx_press_conf_coach ON press_conferences(coach_id, week)",
        "CREATE INDEX IF NOT EXISTS idx_ticker_league ON ticker_items(league_id)",
    ];
};
