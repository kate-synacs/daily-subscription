<?php

declare(strict_types=1);

namespace App\Repository;

use App\Config\Config;
use PDO;

final class PdoFactory
{
    public static function fromEnv(): PDO
    {
        Config::validateRequired('DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD');

        $host = Config::require('DB_HOST');
        $port = Config::get('DB_PORT') ?? '5432';
        $name = Config::require('DB_NAME');
        $user = Config::require('DB_USER');
        $password = Config::require('DB_PASSWORD');

        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $name);

        return new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}
