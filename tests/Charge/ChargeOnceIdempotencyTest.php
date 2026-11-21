<?php

declare(strict_types=1);

namespace App\Tests\Charge;

use App\Tests\Support\IsolatedTestCase;

final class ChargeOnceIdempotencyTest extends IsolatedTestCase
{
    public function test_run_twice_charges_once(): void
    {
        $msisdn = 'tel:1000000501';
        $this->json('POST', '/subscribers/opt-in', ['subscriberId' => $msisdn]);

        $first  = $this->json('POST', '/jobs/daily-charge');
        $second = $this->json('POST', '/jobs/daily-charge');

        self::assertSame(1, (int) $first['charged']);
        self::assertSame(0, (int) $second['charged']);

        // считаем только своего абонента, а не весь _log/debits
        self::assertCount(1, $this->debitsFor($msisdn));

        $attempt = $this->attemptFor($msisdn);
        self::assertNotNull($attempt);
        self::assertSame('charged', $attempt['status']);
        self::assertSame(1, (int) $attempt['attempt_count']);
    }
}