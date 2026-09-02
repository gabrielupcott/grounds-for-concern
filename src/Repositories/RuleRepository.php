<?php

declare(strict_types=1);

namespace App\Repositories;

final class RuleRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /** @return array all rules with definitions decoded from JSON */
    public function all(): array
    {
        $rows = $this->pdo->query('SELECT id, name, definition, is_active, created_at FROM rules ORDER BY id')->fetchAll();
        return array_map([$this, 'hydrate'], $rows);
    }

    public function active(): array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, definition, is_active, created_at FROM rules WHERE is_active = 1 ORDER BY id');
        $stmt->execute();
        return array_map([$this, 'hydrate'], $stmt->fetchAll());
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, definition, is_active, created_at FROM rules WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? $this->hydrate($row) : null;
    }

    public function insert(string $name, array $definition): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO rules (name, definition) VALUES (?, ?)');
        $stmt->execute([$name, json_encode($definition, JSON_THROW_ON_ERROR)]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $name, array $definition): void
    {
        $stmt = $this->pdo->prepare('UPDATE rules SET name = ?, definition = ? WHERE id = ?');
        $stmt->execute([$name, json_encode($definition, JSON_THROW_ON_ERROR), $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM rules WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function setActive(int $id, bool $active): void
    {
        $stmt = $this->pdo->prepare('UPDATE rules SET is_active = ? WHERE id = ?');
        $stmt->execute([(int) $active, $id]);
    }

    private function hydrate(array $row): array
    {
        $row['definition'] = json_decode($row['definition'], true, 512, JSON_THROW_ON_ERROR);
        $row['is_active'] = (bool) $row['is_active'];
        return $row;
    }
}
