<?php

declare(strict_types=1);

namespace App\Exception;

final class PlatformException extends DomainException
{
    public function __construct(
        public readonly string $statusCode,
        string $detail = '',
    ) {
        parent::__construct($detail !== '' ? $detail : 'Platform error: ' . $statusCode);
    }
}
