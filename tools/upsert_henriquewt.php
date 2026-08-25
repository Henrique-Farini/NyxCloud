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

$hasPerfil = (bool) $pdo->query(
    "SELECT 1
     FROM information_schema.columns
     WHERE table_name = 'usuario'
       AND column_name = 'perfil'
     LIMIT 1"
)->fetchColumn();

if ($hasPerfil) {
    $stmt = $pdo->prepare(
        "INSERT INTO usuario (nome, email, senha_hash, perfil, ativo, criado_em, atualizado_em)
         VALUES (:nome, :email, :senha_hash, 'admin', TRUE, NOW(), NOW())
         ON CONFLICT ((LOWER(email))) DO UPDATE
         SET nome = EXCLUDED.nome,
             senha_hash = EXCLUDED.senha_hash,
             perfil = 'admin',
             ativo = TRUE,
             atualizado_em = NOW()
         RETURNING id, nome, email, perfil, ativo"
    );
} else {
    $stmt = $pdo->prepare(
        "INSERT INTO usuario (nome, email, senha_hash, ativo, criado_em, atualizado_em)
         VALUES (:nome, :email, :senha_hash, TRUE, NOW(), NOW())
         ON CONFLICT ((LOWER(email))) DO UPDATE
         SET nome = EXCLUDED.nome,
             senha_hash = EXCLUDED.senha_hash,
             ativo = TRUE,
             atualizado_em = NOW()
         RETURNING id, nome, email, 'admin' AS perfil, ativo"
    );
}

$stmt->execute([
    'nome' => 'henriquewt',
    'email' => 'henriquewt@nyxcloud.local',
    'senha_hash' => '$2y$10$Sb4h0wZJ1/9a6ru4V2mgEeBXuxFK6NhvXwn07Qax3rZhZwXwQtosa',
]);

echo json_encode($stmt->fetch(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
