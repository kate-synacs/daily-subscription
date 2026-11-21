<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Repository\PdoFactory;

// Wait for the DB with a retry loop (no fixed startup sleep guess).
$pdo = null;
$maxAttempts = 120;
for ($i = 0; $i < $maxAttempts; $i++) {
    try {
        $candidate = PdoFactory::fromEnv();
        $candidate->query('SELECT 1');
        $pdo = $candidate;
        break;
    } catch (Throwable $e) {
        if ($i === $maxAttempts - 1) {
            fwrite(STDERR, "DB not ready after {$maxAttempts} attempts: {$e->getMessage()}\n");
            exit(1);
        }
        // Short backoff between connection attempts, part of the retry loop.
        usleep(500000);
    }
}

// Apply every migrations/*.sql file in lexical order. Each file is one atomic
// transaction; SQL uses IF NOT EXISTS so re-running on a migrated DB is a no-op.
$files = glob(__DIR__ . '/../migrations/*.sql') ?: [];
sort($files, SORT_STRING);

foreach ($files as $file) {
    $sql = file_get_contents($file);
    if ($sql === false) {
        fwrite(STDERR, "Failed to read migration: {$file}\n");
        exit(1);
    }

    $pdo->beginTransaction();
    try {
        if (trim($sql) !== '') {
            $pdo->exec($sql);
        }
        $pdo->commit();
        fwrite(STDOUT, 'Applied migration: ' . basename($file) . "\n");
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, "Migration failed {$file}: {$e->getMessage()}\n");
        // Fail the container: the entrypoint must not start php -S.
        exit(1);
    }
}

fwrite(STDOUT, "Migrations complete.\n");
