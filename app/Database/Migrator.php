<?php

namespace App\Database;

class Migrator
{
    private \PDO $pdo;
    private string $driver;

    public function __construct(\PDO $pdo, string $driver = 'sqlite')
    {
        $this->pdo = $pdo;
        $this->driver = $driver;
    }

    /**
     * Run all migrations in order.
     */
    public function migrate(): array
    {
        $this->createMigrationsTable();
        $ran = $this->getRanMigrations();
        $files = $this->getMigrationFiles();
        $results = [];

        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (in_array($name, $ran)) {
                continue;
            }

            $sql = require $file;

            // Handle anonymous class migrations with up() method
            if (is_object($sql) && method_exists($sql, 'up')) {
                $sql->up();
                $this->pdo->prepare("INSERT INTO migrations (name, ran_at) VALUES (?, ?)")
                    ->execute([$name, date('Y-m-d H:i:s')]);
                $results[] = $name;
                continue;
            }

            if (is_callable($sql)) {
                $sql = $sql($this->driver);
            }

            if (is_array($sql)) {
                foreach ($sql as $statement) {
                    $this->pdo->exec($statement);
                }
            } else {
                $this->pdo->exec($sql);
            }

            $this->pdo->prepare("INSERT INTO migrations (name, ran_at) VALUES (?, ?)")
                ->execute([$name, date('Y-m-d H:i:s')]);

            $results[] = $name;
        }

        return $results;
    }

    private function createMigrationsTable(): void
    {
        if ($this->driver === 'sqlite') {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(255) NOT NULL,
                ran_at DATETIME NOT NULL
            )");
        } else {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS migrations (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(255) NOT NULL,
                ran_at DATETIME NOT NULL
            )");
        }
    }

    private function getRanMigrations(): array
    {
        $stmt = $this->pdo->query("SELECT name FROM migrations ORDER BY id");
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function getMigrationFiles(): array
    {
        $dir = __DIR__ . '/migrations';
        $files = glob($dir . '/*.php');
        sort($files);
        return $files;
    }
}
