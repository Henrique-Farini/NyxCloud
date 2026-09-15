<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$usuario = exigirPerfilAdministrador($pdo);

try {
    $hasPerfil = tabelaUsuarioTemPerfil($pdo);
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $contaColumns = 'id, nome, email, ' . ($hasPerfil ? 'perfil' : "'admin' AS perfil")
        . ', ativo, ultimo_login_em, criado_em, atualizado_em';
    $returning = $mysql ? '' : ' RETURNING ' . $contaColumns;

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

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (!in_array($method, ['POST', 'PATCH'], true)) {
        apiMethod('POST');
    }
    apiMutationGuard();

    $payload = json_decode(file_get_contents('php://input'), true);
    $payload = is_array($payload) ? $payload : $_POST;

    if ($method === 'PATCH') {
        if (!$hasPerfil) {
            apiResponse(false, new stdClass(), [], 'Atualize o schema antes de editar contas.', 409);
        }

        $id = (int) ($payload['id'] ?? 0);
        $nome = trim((string) ($payload['nome'] ?? ''));
        $perfil = normalizarPerfil((string) ($payload['perfil'] ?? 'leitura'));
        $ativo = array_key_exists('ativo', $payload)
            ? filter_var($payload['ativo'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;
        $senha = (string) ($payload['senha'] ?? '');

        if ($id <= 0) {
            apiResponse(false, new stdClass(), [], 'Conta invalida.', 422);
        }
        if ($nome !== '' && mb_strlen($nome) < 3) {
            apiResponse(false, new stdClass(), [], 'Informe um nome com pelo menos 3 caracteres.', 422);
        }
        if (!array_key_exists($perfil, perfisDisponiveis())) {
            apiResponse(false, new stdClass(), [], 'Perfil invalido.', 422);
        }
        if ($senha !== '' && strlen($senha) < 8) {
            apiResponse(false, new stdClass(), [], 'A nova senha deve ter pelo menos 8 caracteres.', 422);
        }

        $stmt = $pdo->prepare('SELECT id, nome, email, perfil, ativo FROM usuario WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $contaAtual = $stmt->fetch();
        if (!$contaAtual) {
            apiResponse(false, new stdClass(), [], 'Conta nao encontrada.', 404);
        }

        $self = (int) $usuario['id'] === $id;
        if ($self && ($perfil !== 'admin' || $ativo === false)) {
            apiResponse(false, new stdClass(), [], 'Voce nao pode remover seu proprio acesso administrativo.', 422);
        }

        $sets = ['perfil = :perfil', 'atualizado_em = NOW()'];
        $params = ['id' => $id, 'perfil' => $perfil];
        if ($nome !== '') {
            $sets[] = 'nome = :nome';
            $params['nome'] = $nome;
        }
        if ($ativo !== null) {
            $sets[] = 'ativo = :ativo';
            $params['ativo'] = $ativo ? '1' : '0';
        }
        if ($senha !== '') {
            $hash = password_hash($senha, PASSWORD_DEFAULT);
            if ($hash === false) {
                apiResponse(false, new stdClass(), [], 'Nao foi possivel proteger a senha informada.', 500);
            }
            $sets[] = 'senha_hash = :senha_hash';
            $params['senha_hash'] = $hash;
        }

        $update = $pdo->prepare(
            'UPDATE usuario SET ' . implode(', ', $sets) . '
             WHERE id = :id' . $returning
        );
        $update->execute($params);
        $conta = $mysql ? buscarContaSalva($pdo, $id, $contaColumns) : ($update->fetch() ?: []);
        registrarAuditoriaConta($pdo, (int) $usuario['id'], $id, 'conta_atualizada', [
            'perfil_anterior' => $contaAtual['perfil'] ?? null,
            'perfil_novo' => $perfil,
            'ativo_anterior' => $contaAtual['ativo'] ?? null,
            'ativo_novo' => $ativo,
            'senha_alterada' => $senha !== '',
        ]);

        apiResponse(true, formatarConta($conta), [], 'Conta atualizada com sucesso.');
    }

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
    if (strlen($senha) < 8) {
        apiResponse(false, new stdClass(), [], 'A senha deve ter pelo menos 8 caracteres.', 422);
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
             VALUES (:nome, :email, :senha_hash, :perfil, :ativo, NOW(), NOW())' . $returning
        );
        $insert->execute([
            'nome' => $nome,
            'email' => $email,
            'senha_hash' => $hash,
            'perfil' => $perfil,
            'ativo' => $ativo !== false ? '1' : '0',
        ]);
    } else {
        $insert = $pdo->prepare(
            "INSERT INTO usuario (nome, email, senha_hash, ativo, criado_em, atualizado_em)
             VALUES (:nome, :email, :senha_hash, :ativo, NOW(), NOW())" . $returning
        );
        $insert->execute([
            'nome' => $nome,
            'email' => $email,
            'senha_hash' => $hash,
            'ativo' => $ativo !== false ? '1' : '0',
        ]);
    }

    $conta = $mysql
        ? buscarContaSalva($pdo, (int) $pdo->lastInsertId(), $contaColumns)
        : ($insert->fetch() ?: []);
    registrarAuditoriaConta($pdo, (int) $usuario['id'], (int) ($conta['id'] ?? 0), 'conta_criada', [
        'perfil' => $perfil,
        'ativo' => $ativo !== false,
    ]);

    apiResponse(true, formatarConta($conta), [], 'Conta criada com sucesso.', 201);
} catch (Throwable $e) {
    apiHandle($e);
}

function buscarContaSalva(PDO $pdo, int $id, string $columns): array
{
    $stmt = $pdo->prepare('SELECT ' . $columns . ' FROM usuario WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $conta = $stmt->fetch();
    if (!$conta) {
        throw new RuntimeException('Nao foi possivel consultar a conta salva.');
    }
    return $conta;
}

function formatarConta(array $conta): array
{
    $conta['id'] = (int) ($conta['id'] ?? 0);
    $conta['perfil'] = normalizarPerfil((string) ($conta['perfil'] ?? ''));
    $conta['perfil_nome'] = nomePerfil($conta['perfil']);
    $conta['ativo'] = filter_var($conta['ativo'] ?? true, FILTER_VALIDATE_BOOLEAN);

    return $conta;
}

function registrarAuditoriaConta(PDO $pdo, int $atorId, int $alvoId, string $acao, array $detalhes = []): void
{
    try {
        $jsonValue = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? ':detalhes' : 'CAST(:detalhes AS jsonb)';
        $stmt = $pdo->prepare(
            "INSERT INTO usuario_auditoria (ator_id, alvo_usuario_id, acao, detalhes, ip, user_agent, criado_em)
             VALUES (:ator_id, :alvo_usuario_id, :acao, {$jsonValue}, :ip, :user_agent, NOW())"
        );
        $stmt->execute([
            'ator_id' => $atorId,
            'alvo_usuario_id' => $alvoId > 0 ? $alvoId : null,
            'acao' => $acao,
            'detalhes' => json_encode($detalhes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        ]);
    } catch (Throwable $e) {
        error_log('Auditoria indisponivel: ' . $e->getMessage());
    }
}
