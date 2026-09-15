<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../back/config.php';

try {
    // PG_* permite usar uma origem separada quando DB_* aponta para outro banco.
    $useDb = env('DB_PORT', '5432') === '5432';
    $host = env('PG_HOST', $useDb ? env('DB_HOST', '127.0.0.1') : '127.0.0.1');
    $port = env('PG_PORT', '5432');
    $database = env('PG_DATABASE', env('DB_NAME', env('DB_DATABASE')));
    $user = env('PG_USER', $useDb ? env('DB_USER', env('DB_USERNAME')) : null);
    $password = env('PG_PASSWORD', $useDb ? env('DB_PASSWORD') : null);
    if (!$database || !$user || $password === null) {
        throw new RuntimeException('Configure PG_DATABASE, PG_USER e PG_PASSWORD para o PostgreSQL de origem.');
    }
    try {
        $source = new PDO("pgsql:host={$host};port={$port};dbname={$database};connect_timeout=5", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (PDOException $e) {
        throw new RuntimeException('PostgreSQL de origem indisponivel. Verifique o servidor e PG_*.');
    }
    $source->exec("SET TIME ZONE 'UTC'");
    $rows = $source->query('SELECT id, nome, email, senha_hash, perfil, ativo, ultimo_login_em, criado_em, atualizado_em FROM usuario ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    require __DIR__ . '/../back/conexao_mysql.php';
    $pdo->beginTransaction();
    $check = $pdo->prepare('SELECT id, email, senha_hash FROM usuario WHERE id = ? OR email = ? FOR UPDATE');
    $insert = $pdo->prepare('INSERT INTO usuario (id, nome, email, senha_hash, perfil, ativo, ultimo_login_em, criado_em, atualizado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $verify = $pdo->prepare('SELECT senha_hash FROM usuario WHERE id = ?');
    $imported = $skipped = 0;
    foreach ($rows as $row) {
        $check->execute([$row['id'], $row['email']]);
        $existing = $check->fetchAll(PDO::FETCH_ASSOC);
        if ($existing) {
            if (count($existing) === 1 && (string) $existing[0]['id'] === (string) $row['id'] && $existing[0]['email'] === $row['email'] && hash_equals($existing[0]['senha_hash'], $row['senha_hash'])) {
                $skipped++;
                continue;
            }
            throw new RuntimeException('Conflito de ID ou email no MySQL para o ID ' . $row['id'] . '. Nenhum usuario sera sobrescrito.');
        }
        $values = [$row['id'], $row['nome'], $row['email'], $row['senha_hash'], $row['perfil'], in_array($row['ativo'], [true, 1, '1', 't', 'true'], true) ? 1 : 0];
        foreach (['ultimo_login_em', 'criado_em', 'atualizado_em'] as $field) {
            $values[] = $row[$field] === null ? null : (new DateTimeImmutable($row[$field]))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        $insert->execute($values);
        $verify->execute([$row['id']]);
        if (!hash_equals($row['senha_hash'], (string) $verify->fetchColumn())) {
            throw new RuntimeException('Falha ao verificar a preservacao da senha.');
        }
        $imported++;
    }
    $pdo->commit();
    echo "Usuarios copiados: {$imported}; ja presentes com a mesma senha: {$skipped}. PostgreSQL preservado." . PHP_EOL;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Erros PDO podem incluir dados pessoais; nao imprimir detalhes de consultas.
    fwrite(STDERR, ($e instanceof PDOException ? 'Falha SQL: importacao revertida. Codigo ' . $e->getCode() : $e->getMessage()) . PHP_EOL);
    exit(1);
}
