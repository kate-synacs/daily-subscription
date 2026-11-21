<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use PHPUnit\Framework\TestCase;

final class HealthTest extends TestCase
{
    public function testServiceHealthEndpointReturnsOk(): void
    {
        $body = @file_get_contents('http://localhost:8080/health');
        self::assertNotFalse($body, 'Expected /health to be reachable on localhost:8080');

        $decoded = json_decode((string) $body, true);
        self::assertIsArray($decoded);
        self::assertTrue($decoded['ok'] ?? false);
        self::assertSame('service', $decoded['service'] ?? null);
    }
}
