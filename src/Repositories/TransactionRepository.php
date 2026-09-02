<?php

declare(strict_types=1);

namespace App\Repositories;

final class TransactionRepository
{
    /** Must match the ENUM in schema.sql. */
    public const CATEGORIES = ['coffee', 'food', 'groceries', 'transport', 'entertainment', 'other'];

    public function __construct(private \PDO $pdo)
    {
    }

    /** Latest transactions, newest first. */
    public function recent(int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, occurred_on, merchant, category, amount_cents
             FROM transactions ORDER BY occurred_on DESC, id DESC LIMIT ?'
        );
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Total + count for one category since N days ago (inclusive).
     * Dates are computed in PHP and passed in explicitly — one clock.
     */
    public function categoryStatsSince(string $category, int $days): array
    {
        $since = (new \DateTimeImmutable("-{$days} days"))->format('Y-m-d');
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(amount_cents), 0) AS total_cents, COUNT(*) AS n
             FROM transactions WHERE category = ? AND occurred_on >= ?'
        );
        $stmt->execute([$category, $since]);
        $row = $stmt->fetch();
        return ['total_cents' => (int) $row['total_cents'], 'count' => (int) $row['n']];
    }

    /** Total spend (all categories) since N days ago, in cents. */
    public function totalSince(int $days): int
    {
        $since = (new \DateTimeImmutable("-{$days} days"))->format('Y-m-d');
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(amount_cents), 0) FROM transactions WHERE occurred_on >= ?'
        );
        $stmt->execute([$since]);
        return (int) $stmt->fetchColumn();
    }

    /** All transactions since a date, shaped for the Go engine. */
    public function sinceForEngine(string $since): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT occurred_on, merchant, category, amount_cents
             FROM transactions WHERE occurred_on >= ? ORDER BY occurred_on, id'
        );
        $stmt->execute([$since]);
        return array_map(
            fn (array $r) => [
                'occurred_on' => $r['occurred_on'],
                'merchant' => $r['merchant'],
                'category' => $r['category'],
                'amount_cents' => (int) $r['amount_cents'],
            ],
            $stmt->fetchAll()
        );
    }

    /** Distinct merchant names, for the builder's datalist suggestions. */
    public function distinctMerchants(): array
    {
        return $this->pdo->query('SELECT DISTINCT merchant FROM transactions ORDER BY merchant')->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function add(string $date, string $merchant, string $category, int $amountCents): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO transactions (occurred_on, merchant, category, amount_cents) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$date, $merchant, $category, $amountCents]);
        return (int) $this->pdo->lastInsertId();
    }
}
