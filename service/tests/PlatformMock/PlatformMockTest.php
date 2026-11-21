<?php

declare(strict_types=1);

namespace App\Tests\PlatformMock;

final class PlatformMockTest extends PlatformMockTestCase
{
    public function test_subscription_send_appends_to_log(): void
    {
        $this->postJson('/subscription/send', [
            'applicationId' => 'APP_000123',
            'password' => 'test-only',
            'version' => '1.0',
            'action' => '1',
            'subscriberId' => 'tel:1000000001',
        ]);

        $log = $this->getJson('/_log/subscriptions');
        self::assertCount(1, $log);
        self::assertSame('1', $log[0]['action'] ?? null);
        self::assertSame('tel:1000000001', $log[0]['subscriberId'] ?? null);
        self::assertSame('S1000', $log[0]['statusCode'] ?? null);

        // Opt-out is logged too.
        $this->postJson('/subscription/send', [
            'applicationId' => 'APP_000123',
            'password' => 'test-only',
            'version' => '1.0',
            'action' => '0',
            'subscriberId' => 'tel:1000000001',
        ]);
        self::assertCount(2, $this->getJson('/_log/subscriptions'));

        $this->postJson('/_control/reset', []);
        self::assertCount(0, $this->getJson('/_log/subscriptions'));
    }
}
