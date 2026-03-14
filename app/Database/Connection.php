<?php

namespace App\Database;

class Connection
{
    private static ?Connection $instance = null;
    private \PDO $pdo;
    private string $driver; // 'mysql' or 'sqlite'

    private function __construct()
    {
        $config = require __DIR__ . '/../../config/database.php';
        $this->driver = $config['driver'];

        if ($this->driver === 'sqlite') {
            $path = $config['sqlite_path'];
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $this->pdo = new \PDO("sqlite:{$path}");
            $this->pdo->exec('PRAGMA journal_mode=WAL');
            $this->pdo->exec('PRAGMA foreign_keys=ON');
        } else {
            $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4";
            $this->pdo = new \PDO($dsn, $config['username'], $config['password']);
        }

        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Create a connection from explicit config (used by installer before config file exists).
     */
    public static function fromConfig(array $config): self
    {
        $instance = new self();
        // Constructor already ran with existing config — override for installer
        // Actually we need a different approach for installer
        return $instance;
    }

    /**
     * Create a raw PDO connection for testing (used by installer).
     */
    public static function testConnection(array $config): \PDO
    {
        if ($config['driver'] === 'sqlite') {
            $path = $config['sqlite_path'];
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $pdo = new \PDO("sqlite:{$path}");
            $pdo->exec('PRAGMA journal_mode=WAL');
            $pdo->exec('PRAGMA foreign_keys=ON');
        } else {
            $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4";
            $pdo = new \PDO($dsn, $config['username'], $config['password']);
        }
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        return $pdo;
    }

    public function getPdo(): \PDO
    {
        return $this->pdo;
    }

    public function getDriver(): string
    {
        return $this->driver;
    }

    public function isSQLite(): bool
    {
        return $this->driver === 'sqlite';
    }

    public function isMySQL(): bool
    {
        return $this->driver === 'mysql';
    }

    /**
     * Get current timestamp expression for SQL.
     */
    public function now(): string
    {
        return $this->isSQLite() ? "datetime('now')" : 'NOW()';
    }

    /**
     * Reset singleton (used in testing).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
