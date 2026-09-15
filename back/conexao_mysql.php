<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

// Conexao independente: DB_* continua reservado ao PostgreSQL.
$mysqlHost = env('MYSQL_HOST', env('DB_HOST', '127.0.0.1'));
$mysqlPort = env('MYSQL_PORT', env('DB_PORT', '3306'));
$mysqlDatabase = env('MYSQL_DATABASE', env('DB_NAME', env('DB_DATABASE')));
$mysqlUser = env('MYSQL_USER');
$mysqlPassword = env('MYSQL_PASSWORD');

if (!$mysqlDatabase || !$mysqlUser || $mysqlPassword === null) {
    throw new RuntimeException('Defina MYSQL_DATABASE, MYSQL_USER e MYSQL_PASSWORD no .env.');
}
if (!extension_loaded('pdo_mysql')) {
    throw new RuntimeException('A extensao pdo_mysql do PHP nao esta habilitada.');
}

try {
    $pdo = new PDO(
        "mysql:host={$mysqlHost};port={$mysqlPort};dbname={$mysqlDatabase};charset=utf8mb4",
        $mysqlUser,
        $mysqlPassword,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
        ]
    );
    $pdo->exec("SET time_zone = '+00:00'");
} catch (PDOException $e) {
    throw new RuntimeException('Nao foi possivel conectar ao MySQL. Verifique MYSQL_* no .env. Codigo: ' . ($e->errorInfo[1] ?? $e->getCode()));
}
