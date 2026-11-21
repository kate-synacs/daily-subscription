<?php

declare(strict_types=1);

namespace App\Config;

use App\Exception\ConfigurationException;

/**
 * Environment-backed configuration. Deliberately holds no properties: secrets
 * are read on demand and can never leak through var_dump/__toString/serialization.
 */
final class Config
{
    /** @var string[] */
    private const REQUIRED = [
        'DB_HOST',
        'DB_NAME',
        'DB_USER',
        'DB_PASSWORD',
        'PLATFORM_APP_ID',
        'PLATFORM_PASSWORD',
    ];

    public function __construct()
    {
        self::validateRequired(...self::REQUIRED);
    }

    public function getString(string $key): string
    {
        return self::require($key);
    }

    public function getInt(string $key): int
    {
        $value = self::require($key);
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new ConfigurationException('Invalid integer configuration: ' . $key);
        }

        return (int) $value;
    }

    /** Money amounts are always 2-dp strings; never floats. */
    public function getDecimal(string $key): string
    {
        $value = self::require($key);
        if (preg_match('/^\d+\.\d{2}$/', $value) !== 1) {
            throw new ConfigurationException('Invalid decimal configuration: ' . $key);
        }

        return $value;
    }

    /** Platform credentials have no defaults: they must come from the environment. */
    public static function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        if ($value === false) {
            return $default;
        }

        return $value;
    }

    /** Fail fast: the key must be present in the environment (empty value is allowed). */
    public static function require(string $key): string
    {
        $value = getenv($key);
        if ($value === false) {
            throw new ConfigurationException('Missing required configuration: ' . $key);
        }

        return $value;
    }

    /** @param string[] $keys */
    public static function validateRequired(string ...$keys): void
    {
        $missing = [];
        foreach ($keys as $key) {
            if (getenv($key) === false) {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            throw new ConfigurationException(
                'Missing required configuration: ' . implode(', ', $missing)
            );
        }
    }
}
