<?php

declare(strict_types=1);

namespace App\Tests\PlatformMock;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;

abstract class PlatformMockTestCase extends TestCase
{
    protected Client $client;

    protected function setUp(): void
    {
        $this->client = new Client([
            'base_uri' => 'http://platform-mock:8080',
            'timeout' => 5,
            'http_errors' => false,
        ]);
        $this->client->post('/_control/reset');
    }

    /** @param array<string, mixed> $json @return array<string, mixed> */
    protected function postJson(string $path, array $json): array
    {
        $response = $this->client->post($path, ['json' => $json]);
        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<int|string, mixed> */
    protected function getJson(string $path): array
    {
        $response = $this->client->get($path);
        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<string, mixed> */
    protected function debit(string $externalTrxId): array
    {
        return $this->postJson('/caas/direct/debit', [
            'applicationId' => 'APP_000123',
            'password' => 'test-only',
            'externalTrxId' => $externalTrxId,
            'subscriberId' => 'tel:1000000001',
            'paymentInstrumentName' => 'Mobile Account',
            'amount' => '5.00',
            'currency' => 'XTS',
        ]);
    }
}
