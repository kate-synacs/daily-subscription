<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\Config;

final class ChargeCalculator
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * Deterministic by construction: same subscriber + UTC billing day always
     * yields the same logical charge key, across retries and restarts.
     */
    public static function externalTrxId(string $subscriberId, string $billingDay): string
    {
        return hash('sha256', $subscriberId . ':' . $billingDay);
    }

    public function amount(): string
    {
        return $this->config->getDecimal('DAILY_FEE');
    }

    public function currency(): string
    {
        return $this->config->getString('CURRENCY');
    }
}
