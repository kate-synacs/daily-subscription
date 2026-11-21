<?php

declare(strict_types=1);

namespace App\Tests\Http;

use PDO;

final class OptInTest extends HttpTestCase
{
    public function test_opt_in_creates_active_subscriber(): void
    {
        [$status, $body] = $this->post('/subscribers/opt-in', [
            'subscriberId' => 'tel:1000000001',
        ]);

        self::assertSame(200, $status);
        self::assertSame('active', $body['status'] ?? null);

        $stmt = $this->pdo->prepare(
            'SELECT status, platform_registered_at FROM subscribers WHERE id = ?'
        );
        $stmt->execute(['tel:1000000001']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertNotFalse($row);
        self::assertSame('ACTIVE', $row['status']);
        self::assertNotNull($row['platform_registered_at']);

        $subscriptions = $this->mockLog('/_log/subscriptions');
        self::assertCount(1, $subscriptions);
        self::assertSame('1', $subscriptions[0]['action'] ?? null);
        self::assertCount(1, $this->mockLog('/_log/sms'));
    }

    public function test_opt_in_twice_returns_already_active(): void
    {
        $this->post('/subscribers/opt-in', ['subscriberId' => 'tel:1000000001']);
        [$status, $body] = $this->post('/subscribers/opt-in', [
            'subscriberId' => 'tel:1000000001',
        ]);

        self::assertSame(200, $status);
        self::assertSame('already_active', $body['status'] ?? null);
        self::assertCount(1, $this->mockLog('/_log/subscriptions'), 'Platform called exactly once');
        self::assertCount(1, $this->mockLog('/_log/sms'), 'One welcome SMS');
        self::assertCount(0, $this->mockLog('/_log/debits'), 'Opt-in must never charge');
    }

    public function test_opt_in_recovers_half_done_state(): void
    {
        // Simulate a crash after the Platform accepted the opt-in but before
        // the service recorded platform_registered_at.
        $insert = $this->pdo->prepare(
            "INSERT INTO subscribers (id, status) VALUES (?, 'ACTIVE')"
        );
        $insert->execute(['tel:1000000001']);

        [, $body] = $this->post('/subscribers/opt-in', [
            'subscriberId' => 'tel:1000000001',
        ]);

        self::assertSame('active', $body['status'] ?? null);
        self::assertCount(1, $this->mockLog('/_log/subscriptions'));
        self::assertCount(1, $this->mockLog('/_log/sms'));

        $stmt = $this->pdo->prepare(
            'SELECT platform_registered_at FROM subscribers WHERE id = ?'
        );
        $stmt->execute(['tel:1000000001']);
        self::assertNotNull($stmt->fetchColumn());
    }

    public function test_opt_in_invalid_msisdn_returns_400(): void
    {
        [$status, $body] = $this->post('/subscribers/opt-in', ['subscriberId' => 'junk']);

        self::assertSame(400, $status);
        self::assertSame('BAD_REQUEST', $body['error']['code'] ?? null);
    }

    public function test_opt_in_platform_error_maps_to_502(): void
    {
        // The mock has no forced-error control for /subscription/send (only for
        // /caas/direct/debit), so this mapping cannot be exercised end-to-end yet.
        self::markTestSkipped('mock cannot force /subscription/send to fail');
    }
}
