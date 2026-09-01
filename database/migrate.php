<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/back/conexao.php';

$migrationsPath = __DIR__ . '/migrations';
if (!is_dir($migrationsPath)) {
    throw new RuntimeException('Diretorio de migrations nao encontrado.');
}

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS schema_migrations (
        version VARCHAR(255) PRIMARY KEY,
        applied_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )"
);

$pdo->exec('SELECT pg_advisory_lock(749021)');

try {
    $files = glob($migrationsPath . DIRECTORY_SEPARATOR . '*.sql') ?: [];
    sort($files, SORT_NATURAL);

    foreach ($files as $file) {
        $version = basename($file);
        $check = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
        $check->execute(['version' => $version]);
        if ($check->fetchColumn()) {
            echo "skip {$version}" . PHP_EOL;
            continue;
        }

        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException("Nao foi possivel ler {$version}.");
        }

        $pdo->beginTransaction();
        try {
            $pdo->exec($sql);
            $insert = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:version)');
            $insert->execute(['version' => $version]);
            $pdo->commit();
            echo "applied {$version}" . PHP_EOL;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
} finally {
    $pdo->exec('SELECT pg_advisory_unlock(749021)');
}
