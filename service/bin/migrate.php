<?php

declare(strict_types=1);

$host = getenv('DB_HOST') ?: 'db';
$port = getenv('DB_PORT') ?: '5432';
$name = getenv('DB_NAME') ?: 'dailypass';
$user = getenv('DB_USER') ?: 'dailypass';
$password = getenv('DB_PASSWORD') ?: '';
$dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $name);

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

$pdo = null;
$attempts = 60;
for ($i = 0; $i < $attempts; $i++) {
    try {
        $pdo = new PDO($dsn, $user, $password, $options);
        break;
    } catch (Throwable $e) {
        if ($i === $attempts - 1) {
            fwrite(STDERR, "DB not ready after {$attempts} attempts: {$e->getMessage()}\n");
            exit(1);
        }
        usleep(500000);
    }
}

$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (filename VARCHAR(255) PRIMARY KEY, applied_at TIMESTAMPTZ NOT NULL DEFAULT NOW())');

$migrationsDir = __DIR__ . '/../migrations';
$files = glob($migrationsDir . '/*.sql') ?: [];
sort($files, SORT_STRING);

foreach ($files as $file) {
    $filename = basename($file);
    $stmt = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE filename = ?');
    $stmt->execute([$filename]);
    if ($stmt->fetchColumn()) {
        continue;
    }

    $sql = file_get_contents($file);
    if ($sql === false) {
        fwrite(STDERR, "Failed to read migration: {$filename}\n");
        exit(1);
    }

    $pdo->beginTransaction();
    try {
        if (trim($sql) !== '') {
            $pdo->exec($sql);
        }
        $insert = $pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (?)');
        $insert->execute([$filename]);
        $pdo->commit();
        fwrite(STDOUT, "Applied migration: {$filename}\n");
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "Migration failed {$filename}: {$e->getMessage()}\n");
        exit(1);
    }
}

fwrite(STDOUT, "Migrations complete.\n");
