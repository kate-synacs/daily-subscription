<?php

declare(strict_types=1);

namespace App\Tests\PlatformMock;

final class ContractTest extends PlatformMockTestCase
{
    public function test_subscription_send_action_1_returns_registered(): void
    {
        $response = $this->postJson('/subscription/send', [
            'applicationId' => 'APP_000123',
            'password' => 'test-only',
            'version' => '1.0',
            'action' => '1',
            'subscriberId' => 'tel:1000000001',
        ]);

        self::assertSame('S1000', $response['statusCode'] ?? null);
        self::assertSame('SUCCESS', $response['statusDetail'] ?? null);
        self::assertSame('REGISTERED', $response['subscriptionStatus'] ?? null);
    }

    public function test_subscription_send_action_0_returns_unregistered(): void
    {
        $response = $this->postJson('/subscription/send', [
            'applicationId' => 'APP_000123',
            'password' => 'test-only',
            'version' => '1.0',
            'action' => '0',
            'subscriberId' => 'tel:1000000001',
        ]);

        self::assertSame('S1000', $response['statusCode'] ?? null);
        self::assertSame('UNREGISTERED', $response['subscriptionStatus'] ?? null);
    }

    public function test_sms_send_appends_to_sms_log(): void
    {
        $response = $this->postJson('/sms/send', [
            'applicationId' => 'APP_000123',
            'password' => 'test-only',
            'message' => 'Welcome to Daily Pass',
            'destinationAddresses' => ['tel:1000000001'],
            'version' => '1.0',
            'sourceAddress' => 'tel:1234',
            'encoding' => '0',
        ]);
        self::assertSame('S1000', $response['statusCode'] ?? null);

        $log = $this->getJson('/_log/sms');
        self::assertCount(1, $log);
        self::assertSame('MT', $log[0]['direction'] ?? null);
        self::assertSame('tel:1000000001', $log[0]['to'] ?? null);
        self::assertSame('Welcome to Daily Pass', $log[0]['message'] ?? null);
    }

    public function test_debit_status_is_configurable(): void
    {
        $this->postJson('/_control/debit-status', ['mode' => 'E1326']);
        self::assertSame('E1326', $this->debit('ext-1')['statusCode'] ?? null);

        $this->postJson('/_control/debit-status', ['mode' => 'E1308']);
        self::assertSame('E1308', $this->debit('ext-1')['statusCode'] ?? null);

        $this->postJson('/_control/debit-status', ['mode' => 'auto-dup']);
        $first = $this->debit('ext-dup');
        self::assertSame('S1000', $first['statusCode'] ?? null);
        self::assertIsString($first['referenceId'] ?? null);

        $second = $this->debit('ext-dup');
        self::assertSame('E1337', $second['statusCode'] ?? null);
    }

    public function test_push_callback_delivers_to_service(): void
    {
        // Iteration 5 will assert upstream S1000; for now only delivery is required.
        $response = $this->postJson('/_control/push-callback', [
            'subscriberId' => 'tel:1000000001',
            'status' => 'REGISTERED',
        ]);

        self::assertTrue($response['delivered'] ?? false);
        self::assertIsInt($response['upstreamStatus'] ?? null);
    }
}
