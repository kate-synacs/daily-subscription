<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Clock\Clock;
use DateTimeImmutable;
use DateTimeZone;

/** Test clock with a fixed instant; lives under tests/Support (App\Tests\Support). */
final class FrozenClock implements Clock
{
    private readonly DateTimeImmutable $frozen;

    public function __construct(string $instant = '2026-11-21T12:00:00+00:00')
    {
        $this->frozen = new DateTimeImmutable($instant, new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->frozen;
    }

    public function today(): string
    {
        return $this->frozen->format('Y-m-d');
    }
}
