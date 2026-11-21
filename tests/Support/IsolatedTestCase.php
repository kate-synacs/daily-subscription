<?php

declare(strict_types=1);

namespace App\Tests\Support;

use GuzzleHttp\Client;
use PDO;
use PHPUnit\Framework\TestCase;

abstract class IsolatedTestCase extends TestCase
{
    protected Client $api;
    protected Client $mock;
    protected PDO $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->api = new Client([
            'base_uri'    => getenv('SERVICE_URL') ?: 'http://127.0.0.1:8080',
            'http_errors' => false,
            'timeout'     => 20,
        ]);

        $this->mock = new Client([
            'base_uri'    => getenv('MOCK_URL') ?: 'http://platform-mock:8080',
            'http_errors' => false,
            'timeout'     => 20,
        ]);

        $this->db = new PDO(
            sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                getenv('DB_HOST') ?: 'db',
                getenv('DB_PORT') ?: '5432',
                getenv('DB_NAME') ?: 'dailypass'
            ),
            getenv('DB_USER') ?: 'dailypass',
            getenv('DB_PASSWORD') ?: 'dailypass',
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );

        $this->db->exec(
            'TRUNCATE charge_attempts, sms_log, processed_webhook_events, subscribers RESTART IDENTITY CASCADE'
        );

        $this->mock->post('/_control/reset');
        $this->mock->post('/_control/debit-status', ['json' => ['mode' => 'S1000']]);
    }

    protected function json(string $method, string $path, array $body = []): array
    {
        $response = $this->api->request($method, $path, ['json' => $body]);

        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<array<string, mixed>> */
    protected function debitsFor(string $msisdn): array
    {
        $response = $this->mock->get('/_log/debits');
        $debits = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return array_values(array_filter(
            $debits,
            static fn (array $row): bool => ($row['subscriberId'] ?? null) === $msisdn
        ));
    }

    protected function attemptFor(string $msisdn): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM charge_attempts WHERE subscriber_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$msisdn]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }
}