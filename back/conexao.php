<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Conexão PostgreSQL da aplicação.
 *
 * Em desenvolvimento, defina as variáveis em um arquivo .env na raiz do
 * projeto (consulte .env.example). Em produção, prefira variáveis do sistema.
 */

$host = env('DB_HOST', '127.0.0.1');
$porta = env('DB_PORT', '5432');
// DB_NAME/DB_USER continuam aceitos para compatibilidade com a aplicação.
$banco = env('DB_NAME', env('DB_DATABASE'));
$usuario = env('DB_USER', env('DB_USERNAME'));
$senha = env('DB_PASSWORD');

if ($banco === null || $banco === '' || $usuario === null || $usuario === '' || $senha === null) {
    throw new RuntimeException(
        'Configuração PostgreSQL incompleta. Defina DB_NAME (ou DB_DATABASE), DB_USER (ou DB_USERNAME) e DB_PASSWORD.'
    );
}

if (!extension_loaded('pdo_pgsql')) {
    throw new RuntimeException('A extensão pdo_pgsql do PHP não está habilitada.');
}

try {
    $pdo = new PDO(
        "pgsql:host={$host};port={$porta};dbname={$banco}",
        $usuario,
        $senha,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    $pdo->exec("SET TIME ZONE 'UTC'");
} catch (PDOException $e) {
    error_log('Erro na conexão com o PostgreSQL: ' . $e->getMessage());
    if (PHP_SAPI === 'cli') {
        throw new RuntimeException('Não foi possível conectar ao PostgreSQL. Verifique a configuração.', 0, $e);
    }

    http_response_code(500);
    die('Não foi possível conectar ao banco de dados. Verifique a configuração.');
}
