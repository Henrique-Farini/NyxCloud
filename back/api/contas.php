<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$usuario = exigirPerfilAdministrador($pdo);
$administradorGeral = usuarioEhAdministradorGeral($pdo, $usuario);

try {
    $hasPerfil = tabelaUsuarioTemPerfil($pdo);
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $contaColumns = 'id, nome, email, ' . ($hasPerfil ? 'perfil' : "'admin' AS perfil")
        . ', ativo, ultimo_login_em, criado_em, atualizado_em';
    $returning = $mysql ? '' : ' RETURNING ' . $contaColumns;

    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $perfilSelect = $hasPerfil ? 'perfil' : "'admin' AS perfil";
        $scope = $administradorGeral
            ? ''
            : ' AND (u.id = :viewer_id OR EXISTS (
                    SELECT 1 FROM usuario_empresa ue_viewer
                    INNER JOIN usuario_empresa ue_target ON ue_target.empresa_id = ue_viewer.empresa_id
                    WHERE ue_viewer.usuario_id = :viewer_id_scope AND ue_target.usuario_id = u.id
                ))';
        $stmt = $pdo->prepare(
            "SELECT u.id, u.nome, u.email, {$perfilSelect}, u.ativo, u.ultimo_login_em, u.criado_em, u.atualizado_em
             FROM usuario u
             WHERE 1 = 1 {$scope}
             ORDER BY u.ativo DESC, u.nome ASC, u.email ASC"
        );
        $stmt->execute($administradorGeral ? [] : [
            'viewer_id' => (int) $usuario['id'],
            'viewer_id_scope' => (int) $usuario['id'],
        ]);
        $contas = array_map(static function (array $item): array {
            $item['id'] = (int) $item['id'];
            $item['perfil'] = normalizarPerfil((string) ($item['perfil'] ?? ''));
            $item['perfil_nome'] = nomePerfil($item['perfil']);
            $item['ativo'] = filter_var($item['ativo'], FILTER_VALIDATE_BOOLEAN);
            return $item;
        }, $stmt->fetchAll() ?: []);

        $empresaStmt = $pdo->query(
            'SELECT ue.usuario_id, e.id, e.nome
             FROM usuario_empresa ue
             INNER JOIN empresa e ON e.id = ue.empresa_id
             ORDER BY e.nome ASC'
        );
        $empresasPorUsuario = [];
        foreach ($empresaStmt->fetchAll() ?: [] as $empresa) {
            $empresasPorUsuario[(int) $empresa['usuario_id']][] = [
                'id' => (int) $empresa['id'],
                'nome' => $empresa['nome'],
            ];
        }
        foreach ($contas as &$conta) {
            $conta['empresas'] = $empresasPorUsuario[(int) $conta['id']] ?? [];
            $conta['empresa_ids'] = array_column($conta['empresas'], 'id');
        }
        unset($conta);

        apiResponse(true, [
            'items' => $contas,
            'perfis' => perfisDisponiveis(),
            'empresas' => empresasAcessiveis($pdo, $usuario),
            'administrador_geral' => $administradorGeral,
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
        $temEmpresas = array_key_exists('empresa_ids', $payload);
        $empresaIds = $temEmpresas ? normalizarEmpresaIds($payload['empresa_ids']) : [];

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
        if ($temEmpresas) {
            validarEmpresasAcessiveis($pdo, $usuario, $empresaIds);
            if ($empresaIds === [] && $self) {
                apiResponse(false, new stdClass(), [], 'Nao remova todas as empresas do seu proprio usuario.', 422);
            }
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
        if ($temEmpresas) {
            salvarEmpresasDoUsuario($pdo, $id, $empresaIds);
        }
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
    $empresaIds = normalizarEmpresaIds($payload['empresa_ids'] ?? []);
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
    validarEmpresasAcessiveis($pdo, $usuario, $empresaIds);

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
    salvarEmpresasDoUsuario($pdo, (int) ($conta['id'] ?? 0), $empresaIds);
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

function normalizarEmpresaIds(mixed $ids): array
{
    if (!is_array($ids)) {
        return [];
    }
    $ids = array_map(static fn (mixed $id): int => (int) $id, $ids);
    $ids = array_filter($ids, static fn (int $id): bool => $id > 0);
    return array_values(array_unique($ids));
}

function validarEmpresasAcessiveis(PDO $pdo, array $usuario, array $empresaIds): void
{
    if ($empresaIds === []) {
        return;
    }
    $permitidas = array_column(empresasAcessiveis($pdo, $usuario), 'id');
    if (count(array_diff($empresaIds, array_map('intval', $permitidas))) !== 0) {
        apiResponse(false, new stdClass(), [], 'Uma ou mais empresas nao estao disponiveis para este administrador.', 403);
    }
}

function salvarEmpresasDoUsuario(PDO $pdo, int $usuarioId, array $empresaIds): void
{
    if ($usuarioId <= 0) {
        return;
    }
    $pdo->beginTransaction();
    try {
        $delete = $pdo->prepare('DELETE FROM usuario_empresa WHERE usuario_id = :usuario_id');
        $delete->execute(['usuario_id' => $usuarioId]);
        if ($empresaIds !== []) {
            $insert = $pdo->prepare(
                'INSERT INTO usuario_empresa (usuario_id, empresa_id) VALUES (:usuario_id, :empresa_id)'
            );
            foreach ($empresaIds as $empresaId) {
                $insert->execute(['usuario_id' => $usuarioId, 'empresa_id' => $empresaId]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
