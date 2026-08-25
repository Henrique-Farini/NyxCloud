<?php

declare(strict_types=1);

$pdo = new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=sistema_backup',
    'postgres',
    'postgres',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

$stmt = $pdo->prepare(
    'UPDATE usuario
     SET email = :email,
         atualizado_em = NOW()
     WHERE LOWER(nome) = LOWER(:nome)
     RETURNING id, nome, email, ativo'
);

$stmt->execute([
    'email' => 'henrique@wtsuporti.com.br',
    'nome' => 'henriquewt',
]);

echo json_encode($stmt->fetch(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
