<?php

declare(strict_types=1);

namespace App\Clock;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;

    /** @return string YYYY-MM-DD (UTC billing day) */
    public function today(): string;
}
