<?php

declare(strict_types=1);

namespace App\Repositories;

final class AlertRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function countUnseen(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM alerts WHERE seen = 0')->fetchColumn();
    }

    /** Latest alerts, newest first, with their rule names. */
    public function recent(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.rule_id, a.triggered_on, a.window_total_cents, a.transaction_count, a.summary, a.seen,
                    r.name AS rule_name
             FROM alerts a JOIN rules r ON r.id = a.rule_id
             ORDER BY a.triggered_on DESC, a.id DESC LIMIT ?'
        );
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Insert an alert unless one already exists for this rule + day.
     * The UNIQUE (rule_id, triggered_on) constraint is the real guard —
     * evaluating twice can't spam the inbox.
     */
    public function insertIgnore(int $ruleId, string $triggeredOn, int $totalCents, int $count, string $summary): bool
    {
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO alerts (rule_id, triggered_on, window_total_cents, transaction_count, summary)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$ruleId, $triggeredOn, $totalCents, $count, $summary]);
        return $stmt->rowCount() === 1;
    }

    public function markSeen(int $id): void
    {
        $this->pdo->prepare('UPDATE alerts SET seen = 1 WHERE id = ?')->execute([$id]);
    }

    public function markAllSeen(): void
    {
        $this->pdo->exec('UPDATE alerts SET seen = 1 WHERE seen = 0');
    }
}
