<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class ProcessedWebhookEventRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * DB-enforced idempotency: the event_key PRIMARY KEY decides first vs.
     * duplicate delivery. No PHP-side pre-check.
     *
     * @param array<string, mixed> $payload
     */
    public function tryInsert(string $eventKey, array $payload): bool
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO processed_webhook_events (event_key, payload)
             VALUES (?, ?::jsonb)
             ON CONFLICT (event_key) DO NOTHING
             RETURNING event_key'
        );
        $stmt->execute([$eventKey, json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}']);

        return $stmt->fetchColumn() !== false;
    }
}
