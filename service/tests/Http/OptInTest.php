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
        $msisdn = 'tel:1000000099';
        $this->forceSubscriptionMode('E1308');

        [$status] = $this->post('/subscribers/opt-in', ['subscriberId' => $msisdn]);

        self::assertSame(502, $status);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM subscribers WHERE id = ?');
        $stmt->execute([$msisdn]);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'subscriber must not be created when the platform call fails');
    }

    public function test_opt_in_transport_error_maps_to_502(): void
    {
        $this->forceSubscriptionMode('transport');

        [$status] = $this->post('/subscribers/opt-in', ['subscriberId' => 'tel:1000000098']);

        self::assertSame(502, $status);
    }

    private function forceSubscriptionMode(string $mode): void
    {
        $client = new \GuzzleHttp\Client([
            'base_uri'    => getenv('MOCK_URL') ?: 'http://platform-mock:8080',
            'http_errors' => false,
            'timeout'     => 10,
        ]);

        $client->post('/_control/subscription-status', ['json' => ['mode' => $mode]]);
    }
}
