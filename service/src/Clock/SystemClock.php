<?php

declare(strict_types=1);

namespace App\Clock;

use DateTimeImmutable;
use DateTimeZone;

final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function today(): string
    {
        return $this->now()->format('Y-m-d');
    }
}
