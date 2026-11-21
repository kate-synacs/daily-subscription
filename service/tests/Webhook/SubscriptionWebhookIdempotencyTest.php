<?php

declare(strict_types=1);

namespace App\Tests\Webhook;

use App\Tests\Http\HttpTestCase;
use PDO;

final class SubscriptionWebhookIdempotencyTest extends HttpTestCase
{
    public function test_registered_creates_active_subscriber(): void
    {
        [$status, $body] = $this->webhook('tel:1000000009', 'REGISTERED', '2026-11-21T10:00:00Z');

        self::assertSame(200, $status);
        self::assertSame('S1000', $body['statusCode'] ?? null);
        self::assertSame('Success', $body['statusDetail'] ?? null);

        $stmt = $this->pdo->prepare(
            'SELECT status, platform_registered_at FROM subscribers WHERE id = ?'
        );
        $stmt->execute(['tel:1000000009']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertNotFalse($row);
        self::assertSame('ACTIVE', $row['status']);
        self::assertNotNull($row['platform_registered_at']);
    }

    public function test_duplicate_registered_is_idempotent(): void
    {
        $payload = $this->webhook('tel:1000000009', 'REGISTERED', '2026-11-21T10:00:00Z');
        self::assertSame(200, $payload[0]);

        $this->webhook('tel:1000000009', 'REGISTERED', '2026-11-21T10:00:00Z');

        self::assertSame(1, (int) $this->pdo->query(
            'SELECT count(*) FROM processed_webhook_events'
        )->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query(
            "SELECT count(*) FROM subscribers WHERE id = 'tel:1000000009'"
        )->fetchColumn());
        self::assertCount(0, $this->mockLog('/_log/debits'));
        self::assertSame('ACTIVE', $this->subscriberStatus('tel:1000000009'));
    }

    public function test_unregistered_sets_inactive(): void
    {
        $this->post('/subscribers/opt-in', ['subscriberId' => 'tel:1000000001']);
        self::assertSame('ACTIVE', $this->subscriberStatus('tel:1000000001'));

        [$status, $body] = $this->webhook('tel:1000000001', 'UNREGISTERED', '2026-11-21T10:05:00Z');

        self::assertSame(200, $status);
        self::assertSame('S1000', $body['statusCode'] ?? null);
        self::assertSame('INACTIVE', $this->subscriberStatus('tel:1000000001'));
        self::assertCount(0, $this->mockLog('/_log/debits'));
    }

    public function test_unregistered_for_unknown_subscriber_is_noop(): void
    {
        [$status, $body] = $this->webhook('tel:9999999998', 'UNREGISTERED', '2026-11-21T10:06:00Z');

        self::assertSame(200, $status);
        self::assertSame('S1000', $body['statusCode'] ?? null);
        self::assertSame(0, (int) $this->pdo->query(
            "SELECT count(*) FROM subscribers WHERE id = 'tel:9999999998'"
        )->fetchColumn());
    }

    public function test_webhook_never_calls_debit(): void
    {
        foreach (['REGISTERED', 'PENDING', 'CHARGE', 'UNREGISTERED'] as $index => $event) {
            $this->webhook(
                'tel:1000000009',
                $event,
                sprintf('2026-11-21T10:%02d:00Z', 10 + $index),
            );
        }

        self::assertCount(0, $this->mockLog('/_log/debits'));
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function webhook(string $subscriberId, string $status, string $timeStamp): array
    {
        return $this->post('/webhooks/subscription', [
            'applicationId' => 'APP_000123',
            'subscriberId' => $subscriberId,
            'frequency' => 'daily',
            'status' => $status,
            'version' => '1.0',
            'timeStamp' => $timeStamp,
        ]);
    }

    private function subscriberStatus(string $subscriberId): string
    {
        $stmt = $this->pdo->prepare('SELECT status FROM subscribers WHERE id = ?');
        $stmt->execute([$subscriberId]);

        return (string) $stmt->fetchColumn();
    }
}
