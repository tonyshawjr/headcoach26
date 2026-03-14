<?php
/**
 * Migration: Phase 3 — Depth Systems (Trades, FA, Draft, Coaching Staff, Dynasty)
 */
return function (string $driver): array {
    $autoInc = $driver === 'sqlite'
        ? 'INTEGER PRIMARY KEY AUTOINCREMENT'
        : 'INT PRIMARY KEY AUTO_INCREMENT';

    $boolean = $driver === 'sqlite' ? 'INTEGER' : 'TINYINT(1)';
    $bigint = $driver === 'sqlite' ? 'INTEGER' : 'BIGINT';

    return [
        // Trades
        "CREATE TABLE IF NOT EXISTS trades (
            id {$autoInc},
            league_id INT NOT NULL,
            proposing_team_id INT NOT NULL,
            receiving_team_id INT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'proposed',
            proposed_at DATETIME NOT NULL,
            resolved_at DATETIME,
            veto_reason TEXT,
            FOREIGN KEY (league_id) REFERENCES leagues(id),
            FOREIGN KEY (proposing_team_id) REFERENCES teams(id),
            FOREIGN KEY (receiving_team_id) REFERENCES teams(id)
        )",

        // Trade Items (players and/or draft picks in each direction)
        "CREATE TABLE IF NOT EXISTS trade_items (
            id {$autoInc},
            trade_id INT NOT NULL,
            direction VARCHAR(10) NOT NULL,
            item_type VARCHAR(20) NOT NULL,
            player_id INT,
            draft_pick_id INT,
            FOREIGN KEY (trade_id) REFERENCES trades(id),
            FOREIGN KEY (player_id) REFERENCES players(id)
        )",

        // Trade Block (players a team wants to trade)
        "CREATE TABLE IF NOT EXISTS trade_block (
            id {$autoInc},
            team_id INT NOT NULL,
            player_id INT NOT NULL,
            asking_price VARCHAR(20) NOT NULL DEFAULT 'fair',
            listed_at DATETIME NOT NULL,
            FOREIGN KEY (team_id) REFERENCES teams(id),
            FOREIGN KEY (player_id) REFERENCES players(id)
        )",

        // Free Agents
        "CREATE TABLE IF NOT EXISTS free_agents (
            id {$autoInc},
            league_id INT NOT NULL,
            player_id INT NOT NULL,
            asking_salary {$bigint} NOT NULL DEFAULT 0,
            market_value {$bigint} NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'available',
            released_at DATETIME NOT NULL,
            signed_at DATETIME,
            FOREIGN KEY (league_id) REFERENCES leagues(id),
            FOREIGN KEY (player_id) REFERENCES players(id)
        )",

        // Free Agent Bids
        "CREATE TABLE IF NOT EXISTS fa_bids (
            id {$autoInc},
            free_agent_id INT NOT NULL,
            team_id INT NOT NULL,
            coach_id INT NOT NULL,
            salary_offer {$bigint} NOT NULL,
            years_offer INT NOT NULL DEFAULT 1,
            is_winning {$boolean} NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            FOREIGN KEY (free_agent_id) REFERENCES free_agents(id),
            FOREIGN KEY (team_id) REFERENCES teams(id)
        )",

        // Draft Classes
        "CREATE TABLE IF NOT EXISTS draft_classes (
            id {$autoInc},
            league_id INT NOT NULL,
            year INT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'upcoming',
            created_at DATETIME NOT NULL,
            FOREIGN KEY (league_id) REFERENCES leagues(id)
        )",

        // Draft Picks
        "CREATE TABLE IF NOT EXISTS draft_picks (
            id {$autoInc},
            league_id INT NOT NULL,
            draft_class_id INT NOT NULL,
            round INT NOT NULL,
            pick_number INT NOT NULL,
            original_team_id INT NOT NULL,
            current_team_id INT NOT NULL,
            player_id INT,
            is_used {$boolean} NOT NULL DEFAULT 0,
            FOREIGN KEY (league_id) REFERENCES leagues(id),
            FOREIGN KEY (draft_class_id) REFERENCES draft_classes(id),
            FOREIGN KEY (original_team_id) REFERENCES teams(id),
            FOREIGN KEY (current_team_id) REFERENCES teams(id),
            FOREIGN KEY (player_id) REFERENCES players(id)
        )",

        // Draft Prospects (pre-draft scoutable players)
        "CREATE TABLE IF NOT EXISTS draft_prospects (
            id {$autoInc},
            draft_class_id INT NOT NULL,
            first_name VARCHAR(50) NOT NULL,
            last_name VARCHAR(50) NOT NULL,
            position VARCHAR(10) NOT NULL,
            college VARCHAR(80),
            age INT NOT NULL DEFAULT 21,
            projected_round INT NOT NULL DEFAULT 3,
            actual_overall INT NOT NULL,
            scouted_overall INT,
            scouted_floor INT,
            scouted_ceiling INT,
            potential VARCHAR(20) NOT NULL DEFAULT 'normal',
            combine_score INT,
            positional_ratings TEXT,
            is_drafted {$boolean} NOT NULL DEFAULT 0,
            FOREIGN KEY (draft_class_id) REFERENCES draft_classes(id)
        )",

        // Coaching Staff
        "CREATE TABLE IF NOT EXISTS coaching_staff (
            id {$autoInc},
            team_id INT NOT NULL,
            league_id INT NOT NULL,
            role VARCHAR(30) NOT NULL,
            name VARCHAR(100) NOT NULL,
            rating INT NOT NULL DEFAULT 50,
            specialty VARCHAR(30),
            salary {$bigint} NOT NULL DEFAULT 500000,
            contract_years INT NOT NULL DEFAULT 2,
            is_available {$boolean} NOT NULL DEFAULT 0,
            hired_at DATETIME,
            FOREIGN KEY (team_id) REFERENCES teams(id),
            FOREIGN KEY (league_id) REFERENCES leagues(id)
        )",

        // Coach History (career tracking)
        "CREATE TABLE IF NOT EXISTS coach_history (
            id {$autoInc},
            coach_id INT NOT NULL,
            team_id INT NOT NULL,
            league_id INT NOT NULL,
            season_year INT NOT NULL,
            wins INT NOT NULL DEFAULT 0,
            losses INT NOT NULL DEFAULT 0,
            made_playoffs {$boolean} NOT NULL DEFAULT 0,
            championship {$boolean} NOT NULL DEFAULT 0,
            final_influence INT NOT NULL DEFAULT 50,
            final_job_security INT NOT NULL DEFAULT 50,
            fired {$boolean} NOT NULL DEFAULT 0,
            FOREIGN KEY (coach_id) REFERENCES coaches(id),
            FOREIGN KEY (team_id) REFERENCES teams(id)
        )",

        // Season Awards
        "CREATE TABLE IF NOT EXISTS season_awards (
            id {$autoInc},
            league_id INT NOT NULL,
            season_year INT NOT NULL,
            award_type VARCHAR(50) NOT NULL,
            winner_type VARCHAR(20) NOT NULL,
            winner_id INT NOT NULL,
            stats TEXT,
            FOREIGN KEY (league_id) REFERENCES leagues(id)
        )",

        // Legacy Score (lifetime coach achievements)
        "CREATE TABLE IF NOT EXISTS legacy_scores (
            id {$autoInc},
            coach_id INT NOT NULL,
            total_score INT NOT NULL DEFAULT 0,
            total_wins INT NOT NULL DEFAULT 0,
            total_losses INT NOT NULL DEFAULT 0,
            championships INT NOT NULL DEFAULT 0,
            playoff_appearances INT NOT NULL DEFAULT 0,
            awards_won INT NOT NULL DEFAULT 0,
            teams_coached INT NOT NULL DEFAULT 1,
            seasons_completed INT NOT NULL DEFAULT 0,
            FOREIGN KEY (coach_id) REFERENCES coaches(id)
        )",

        // Indexes
        "CREATE INDEX IF NOT EXISTS idx_trades_league ON trades(league_id)",
        "CREATE INDEX IF NOT EXISTS idx_trades_proposing ON trades(proposing_team_id)",
        "CREATE INDEX IF NOT EXISTS idx_trades_receiving ON trades(receiving_team_id)",
        "CREATE INDEX IF NOT EXISTS idx_trade_items_trade ON trade_items(trade_id)",
        "CREATE INDEX IF NOT EXISTS idx_free_agents_league ON free_agents(league_id, status)",
        "CREATE INDEX IF NOT EXISTS idx_fa_bids_fa ON fa_bids(free_agent_id)",
        "CREATE INDEX IF NOT EXISTS idx_draft_picks_league ON draft_picks(league_id, draft_class_id)",
        "CREATE INDEX IF NOT EXISTS idx_draft_prospects_class ON draft_prospects(draft_class_id)",
        "CREATE INDEX IF NOT EXISTS idx_coaching_staff_team ON coaching_staff(team_id)",
        "CREATE INDEX IF NOT EXISTS idx_coach_history_coach ON coach_history(coach_id)",
    ];
};
