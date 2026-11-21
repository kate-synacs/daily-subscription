<?php

declare(strict_types=1);

namespace App\Tests\Http;

final class SubscriberStatusTest extends HttpTestCase
{
    public function test_status_returns_active_after_opt_in(): void
    {
        $this->post('/subscribers/opt-in', ['subscriberId' => 'tel:1000000001']);

        [$status, $body] = $this->get('/subscribers/tel:1000000001');

        self::assertSame(200, $status);
        self::assertTrue($body['ok'] ?? false);
        self::assertSame('tel:1000000001', $body['subscriberId'] ?? null);
        self::assertSame('ACTIVE', $body['status'] ?? null);
    }

    public function test_status_returns_404_for_unknown(): void
    {
        [$status, $body] = $this->get('/subscribers/tel:9999999999');

        self::assertSame(404, $status);
        self::assertSame('NOT_FOUND', $body['error']['code'] ?? null);
    }

    public function test_status_includes_last_charge_null_before_charging(): void
    {
        $this->post('/subscribers/opt-in', ['subscriberId' => 'tel:1000000001']);

        [, $body] = $this->get('/subscribers/tel:1000000001');

        self::assertArrayHasKey('lastCharge', $body);
        self::assertNull($body['lastCharge']);
        self::assertArrayHasKey('lastChargeError', $body);
        self::assertNull($body['lastChargeError']);
    }
}
