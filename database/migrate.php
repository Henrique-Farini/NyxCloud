<?php

declare(strict_types=1);

$driver = $argv[1] ?? 'pgsql';
if (!in_array($driver, ['pgsql', 'mysql'], true)) {
    throw new InvalidArgumentException('Uso: php database/migrate.php [pgsql|mysql]');
}
$databaseDriver = $driver;
require_once dirname(__DIR__) . ($driver === 'mysql' ? '/back/conexao_mysql.php' : '/back/conexao.php');

$migrationsPath = __DIR__ . '/migrations' . ($driver === 'mysql' ? '/mysql' : '');
if (!is_dir($migrationsPath)) {
    throw new RuntimeException('Diretorio de migrations nao encontrado.');
}

if ($driver === 'mysql') {
    $lockName = 'nyxcloud_migrate_' . substr(hash('sha256', (string) $pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 40);
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 30)');
    $lock->execute([$lockName]);
    if ((int) $lock->fetchColumn() !== 1) {
        throw new RuntimeException('Nao foi possivel obter o lock das migrations MySQL.');
    }
} else {
    $pdo->exec('SELECT pg_advisory_lock(749021)');
}

try {
    $pdo->exec($driver === 'mysql'
        ? "CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(255) PRIMARY KEY,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        : "CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(255) PRIMARY KEY,
            applied_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )"
    );

    $files = glob($migrationsPath . DIRECTORY_SEPARATOR . '*.sql') ?: [];
    if ($driver === 'mysql') {
        $files[] = __DIR__ . '/migrations/016_create_alert_email_settings_mysql.sql';
    } else {
        $files = array_values(array_filter($files, static fn (string $file): bool => !str_ends_with(strtolower($file), '_mysql.sql')));
    }
    usort($files, static fn (string $left, string $right): int => strnatcasecmp(basename($left), basename($right)));

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

        // MySQL DDL faz commit implicito. Execute statements simples separadamente
        // para que uma migration possa criar/ajustar mais de um objeto.
        if ($driver === 'pgsql') {
            $pdo->beginTransaction();
        }
        try {
            $statements = $driver === 'mysql'
                ? array_values(array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [])))
                : [$sql];
            foreach ($statements as $statement) {
                $pdo->exec($statement);
            }
            $insert = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:version)');
            $insert->execute(['version' => $version]);
            if ($driver === 'pgsql') {
                $pdo->commit();
            }
            echo "applied {$version}" . PHP_EOL;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
} finally {
    if ($driver === 'mysql') {
        $unlock = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $unlock->execute([$lockName]);
    } else {
        $pdo->exec('SELECT pg_advisory_unlock(749021)');
    }
}
