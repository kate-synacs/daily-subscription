<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class SubscriberRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, status, last_charge_error, created_at, updated_at FROM subscribers WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function insertOrIgnoreActive(string $id): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO subscribers (id, status) VALUES (?, 'ACTIVE') ON CONFLICT (id) DO NOTHING"
        );
        $stmt->execute([$id]);
    }

    /**
     * Provisional row used during opt-in: the subscriber is not ACTIVE until the
     * Platform has confirmed registration.
     */
    public function insertOrIgnoreInactive(string $id): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO subscribers (id, status) VALUES (?, 'INACTIVE') ON CONFLICT (id) DO NOTHING"
        );
        $stmt->execute([$id]);
    }

    /** Webhook REGISTERED path: upsert handles events arriving before or after opt-in. */
    public function upsertRegistered(string $id): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO subscribers (id, status, platform_registered_at)
             VALUES (?, 'ACTIVE', now())
             ON CONFLICT (id) DO UPDATE
               SET status = 'ACTIVE', platform_registered_at = now(), updated_at = now()"
        );
        $stmt->execute([$id]);
    }

    public function setStatus(string $id, string $status): void
    {
        // updated_at has no DB trigger; always set it explicitly.
        $stmt = $this->pdo->prepare(
            'UPDATE subscribers SET status = ?, updated_at = now() WHERE id = ?'
        );
        $stmt->execute([$status, $id]);
    }

    public function insertSmsLog(
        string $subscriberId,
        string $direction,
        string $message,
        ?string $platformStatusCode = null,
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sms_log (direction, subscriber_id, message, platform_status_code)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$direction, $subscriberId, $message, $platformStatusCode]);
    }
}
