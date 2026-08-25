<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$usuario = exigirPerfilAdministrador($pdo);

try {
    $hasPerfil = tabelaUsuarioTemPerfil($pdo);

    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $perfilSelect = $hasPerfil ? 'perfil' : "'admin' AS perfil";
        $stmt = $pdo->query(
            "SELECT id, nome, email, {$perfilSelect}, ativo, ultimo_login_em, criado_em, atualizado_em
             FROM usuario
             ORDER BY ativo DESC, nome ASC, email ASC"
        );
        $contas = array_map(static function (array $item): array {
            $item['id'] = (int) $item['id'];
            $item['perfil'] = normalizarPerfil((string) ($item['perfil'] ?? ''));
            $item['perfil_nome'] = nomePerfil($item['perfil']);
            $item['ativo'] = filter_var($item['ativo'], FILTER_VALIDATE_BOOLEAN);
            return $item;
        }, $stmt->fetchAll() ?: []);

        apiResponse(true, [
            'items' => $contas,
            'perfis' => perfisDisponiveis(),
            'viewer' => [
                'id' => (int) $usuario['id'],
                'perfil' => normalizarPerfil((string) ($usuario['perfil'] ?? '')),
            ],
        ]);
    }

    apiMethod('POST');
    $payload = json_decode(file_get_contents('php://input'), true);
    $payload = is_array($payload) ? $payload : $_POST;

    $nome = trim((string) ($payload['nome'] ?? ''));
    $email = strtolower(trim((string) ($payload['email'] ?? '')));
    $senha = (string) ($payload['senha'] ?? '');
    $perfil = normalizarPerfil((string) ($payload['perfil'] ?? 'leitura'));
    $ativo = array_key_exists('ativo', $payload)
        ? filter_var($payload['ativo'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
        : true;

    if ($nome === '' || mb_strlen($nome) < 3) {
        apiResponse(false, new stdClass(), [], 'Informe um nome com pelo menos 3 caracteres.', 422);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        apiResponse(false, new stdClass(), [], 'Informe um e-mail valido.', 422);
    }
    if (strlen($senha) < 6) {
        apiResponse(false, new stdClass(), [], 'A senha deve ter pelo menos 6 caracteres.', 422);
    }
    if (!array_key_exists($perfil, perfisDisponiveis())) {
        apiResponse(false, new stdClass(), [], 'Perfil invalido.', 422);
    }

    $stmt = $pdo->prepare('SELECT id FROM usuario WHERE LOWER(email) = LOWER(:email) LIMIT 1');
    $stmt->execute(['email' => $email]);
    if ($stmt->fetch()) {
        apiResponse(false, new stdClass(), [], 'Ja existe uma conta com este e-mail.', 409);
    }

    $hash = password_hash($senha, PASSWORD_DEFAULT);
    if ($hash === false) {
        apiResponse(false, new stdClass(), [], 'Nao foi possivel proteger a senha informada.', 500);
    }

    if ($hasPerfil) {
        $insert = $pdo->prepare(
            'INSERT INTO usuario (nome, email, senha_hash, perfil, ativo, criado_em, atualizado_em)
             VALUES (:nome, :email, :senha_hash, :perfil, :ativo, NOW(), NOW())
             RETURNING id, nome, email, perfil, ativo, ultimo_login_em, criado_em, atualizado_em'
        );
        $insert->execute([
            'nome' => $nome,
            'email' => $email,
            'senha_hash' => $hash,
            'perfil' => $perfil,
            'ativo' => $ativo !== false,
        ]);
    } else {
        $insert = $pdo->prepare(
            "INSERT INTO usuario (nome, email, senha_hash, ativo, criado_em, atualizado_em)
             VALUES (:nome, :email, :senha_hash, :ativo, NOW(), NOW())
             RETURNING id, nome, email, 'admin' AS perfil, ativo, ultimo_login_em, criado_em, atualizado_em"
        );
        $insert->execute([
            'nome' => $nome,
            'email' => $email,
            'senha_hash' => $hash,
            'ativo' => $ativo !== false,
        ]);
    }

    $conta = $insert->fetch() ?: [];
    $conta['id'] = (int) ($conta['id'] ?? 0);
    $conta['perfil'] = normalizarPerfil((string) ($conta['perfil'] ?? ''));
    $conta['perfil_nome'] = nomePerfil($conta['perfil']);
    $conta['ativo'] = filter_var($conta['ativo'] ?? true, FILTER_VALIDATE_BOOLEAN);

    apiResponse(true, $conta, [], 'Conta criada com sucesso.', 201);
} catch (Throwable $e) {
    apiHandle($e);
}
