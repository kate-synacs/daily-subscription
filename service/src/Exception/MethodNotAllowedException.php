<?php

declare(strict_types=1);

namespace App\Exception;

final class MethodNotAllowedException extends DomainException
{
    /** @param string[] $allowed */
    public function __construct(private readonly array $allowed = [])
    {
        parent::__construct('Method not allowed');
    }

    /** @return string[] */
    public function allowedMethods(): array
    {
        return $this->allowed;
    }
}
