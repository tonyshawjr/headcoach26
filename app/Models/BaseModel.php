<?php

namespace App\Models;

use App\Database\Connection;

abstract class BaseModel
{
    protected string $table;
    protected \PDO $db;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getPdo();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function all(array $where = [], string $orderBy = 'id ASC', int $limit = 0, int $offset = 0): array
    {
        $sql = "SELECT * FROM {$this->table}";
        $params = [];

        if (!empty($where)) {
            $clauses = [];
            foreach ($where as $col => $val) {
                if ($val === null) {
                    $clauses[] = "{$col} IS NULL";
                } else {
                    $clauses[] = "{$col} = ?";
                    $params[] = $val;
                }
            }
            $sql .= ' WHERE ' . implode(' AND ', $clauses);
        }

        $sql .= " ORDER BY {$orderBy}";
        if ($limit > 0) {
            $sql .= " LIMIT {$limit}";
            if ($offset > 0) {
                $sql .= " OFFSET {$offset}";
            }
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function count(array $where = []): int
    {
        $sql = "SELECT COUNT(*) as cnt FROM {$this->table}";
        $params = [];

        if (!empty($where)) {
            $clauses = [];
            foreach ($where as $col => $val) {
                if ($val === null) {
                    $clauses[] = "{$col} IS NULL";
                } else {
                    $clauses[] = "{$col} = ?";
                    $params[] = $val;
                }
            }
            $sql .= ' WHERE ' . implode(' AND ', $clauses);
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetch()['cnt'];
    }

    public function create(array $data): int
    {
        $cols = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $stmt = $this->db->prepare(
            "INSERT INTO {$this->table} ({$cols}) VALUES ({$placeholders})"
        );
        $stmt->execute(array_values($data));
        return (int) $this->db->lastInsertId();
    }

    public function createMany(array $rows): int
    {
        if (empty($rows)) return 0;

        $cols = array_keys($rows[0]);
        $colStr = implode(', ', $cols);
        $placeholders = '(' . implode(', ', array_fill(0, count($cols), '?')) . ')';
        $allPlaceholders = implode(', ', array_fill(0, count($rows), $placeholders));

        $values = [];
        foreach ($rows as $row) {
            foreach ($cols as $col) {
                $values[] = $row[$col] ?? null;
            }
        }

        $stmt = $this->db->prepare(
            "INSERT INTO {$this->table} ({$colStr}) VALUES {$allPlaceholders}"
        );
        $stmt->execute($values);
        return count($rows);
    }

    public function update(int $id, array $data): bool
    {
        $sets = implode(', ', array_map(fn($col) => "{$col} = ?", array_keys($data)));
        $stmt = $this->db->prepare(
            "UPDATE {$this->table} SET {$sets} WHERE id = ?"
        );
        return $stmt->execute([...array_values($data), $id]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM {$this->table} WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function deleteWhere(array $where): int
    {
        $clauses = [];
        $params = [];
        foreach ($where as $col => $val) {
            $clauses[] = "{$col} = ?";
            $params[] = $val;
        }
        $sql = "DELETE FROM {$this->table} WHERE " . implode(' AND ', $clauses);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function query(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function exec(string $sql, array $params = []): int
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }
}
