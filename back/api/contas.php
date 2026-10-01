<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$usuario = exigirAutenticacao($pdo);
$perfilViewer = normalizarPerfil((string) ($usuario['perfil'] ?? ''));
if (!usuarioPodeAcessarContas($usuario)) {
    apiResponse(false, new stdClass(), [], 'Permissao insuficiente para gerenciar contas.', 403);
}
$administradorGeral = $perfilViewer === 'admin' && usuarioEhAdministradorGeral($pdo, $usuario);

try {
    sincronizarEmpresasAcronis($pdo);
    $hasPerfil = tabelaUsuarioTemPerfil($pdo);
    $hasAdminFlag = tabelaUsuarioTemAdministradorGeral($pdo);
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $contaColumns = 'id, nome, email, ' . ($hasPerfil ? 'perfil' : "'admin' AS perfil")
        . ', ' . ($hasAdminFlag ? 'administrador_geral' : 'FALSE AS administrador_geral') . ', ativo, ultimo_login_em, criado_em, atualizado_em';
    $returning = $mysql ? '' : ' RETURNING ' . $contaColumns;

    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $perfilSelect = $hasPerfil ? 'perfil' : "'admin' AS perfil";
        if ($administradorGeral) {
            $scope = '';
        } elseif ($perfilViewer === 'operador') {
            $scope = ' AND u.perfil = \'leitura\' AND EXISTS (
                    SELECT 1 FROM usuario_empresa ue_viewer
                    INNER JOIN usuario_empresa ue_target ON ue_target.empresa_id = ue_viewer.empresa_id
                    WHERE ue_viewer.usuario_id = :viewer_id_scope AND ue_target.usuario_id = u.id
                )';
        } else {
            $scope = ' AND (u.id = :viewer_id OR (u.perfil IN (\'operador\', \'leitura\') AND EXISTS (
                    SELECT 1 FROM usuario_empresa ue_viewer
                    INNER JOIN usuario_empresa ue_target ON ue_target.empresa_id = ue_viewer.empresa_id
                    WHERE ue_viewer.usuario_id = :viewer_id_scope AND ue_target.usuario_id = u.id
                )))';
        }
        $stmt = $pdo->prepare(
            "SELECT u.id, u.nome, u.email, {$perfilSelect}, " . ($hasAdminFlag ? 'u.administrador_geral' : 'FALSE') . " AS administrador_geral, u.ativo, u.ultimo_login_em, u.criado_em, u.atualizado_em
             FROM usuario u
             WHERE 1 = 1 {$scope}
             ORDER BY u.ativo DESC, u.nome ASC, u.email ASC"
        );
        $scopeParams = $administradorGeral ? [] : ['viewer_id_scope' => (int) $usuario['id']];
        if (!$administradorGeral && $perfilViewer !== 'operador') {
            $scopeParams['viewer_id'] = (int) $usuario['id'];
        }
        $stmt->execute($scopeParams);
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
        $empresasPermitidas = array_fill_keys(
            array_map('intval', array_column(empresasAcessiveis($pdo, $usuario), 'id')),
            true
        );
        $empresasPorUsuario = [];
        foreach ($empresaStmt->fetchAll() ?: [] as $empresa) {
            if (!$administradorGeral && !isset($empresasPermitidas[(int) $empresa['id']])) {
                continue;
            }
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
    if (!in_array($method, ['POST', 'PATCH', 'DELETE'], true)) {
        apiMethod('POST, PATCH ou DELETE');
    }
    apiMutationGuard();

    $payload = json_decode(file_get_contents('php://input'), true);
    $payload = is_array($payload) ? $payload : $_POST;

    if ($method === 'DELETE') {
        $id = (int) ($payload['id'] ?? 0);
        $senhaConfirmacao = (string) ($payload['senha_confirmacao'] ?? '');
        if ($id <= 0) {
            apiResponse(false, new stdClass(), [], 'Conta invalida.', 422);
        }
        if ((int) $usuario['id'] === $id) {
            apiResponse(false, new stdClass(), [], 'A propria conta nao pode ser excluida.', 422);
        }

        $stmt = $pdo->prepare('SELECT id, nome, email, perfil, ' . ($hasAdminFlag ? 'administrador_geral' : 'FALSE AS administrador_geral') . ' FROM usuario WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $contaExcluir = $stmt->fetch();
        if (!$contaExcluir) {
            apiResponse(false, new stdClass(), [], 'Conta nao encontrada.', 404);
        }
        if (!$administradorGeral) {
            if ($perfilViewer !== 'admin' || !in_array(normalizarPerfil((string) $contaExcluir['perfil']), ['operador', 'leitura'], true)) {
                apiResponse(false, new stdClass(), [], 'Seu nivel de acesso nao permite excluir esta conta.', 403);
            }
            if (!contaEstaNoEscopo($pdo, $usuario, $contaExcluir, false)) {
                apiResponse(false, new stdClass(), [], 'Esta conta nao esta disponivel no seu nivel de acesso.', 403);
            }
        }
        if ($senhaConfirmacao === '') {
            apiResponse(false, new stdClass(), [], 'Digite sua senha para confirmar a exclusao.', 422);
        }
        $senhaStmt = $pdo->prepare('SELECT senha_hash FROM usuario WHERE id = :id LIMIT 1');
        $senhaStmt->execute(['id' => (int) ($usuario['id'] ?? 0)]);
        if (!password_verify($senhaConfirmacao, (string) $senhaStmt->fetchColumn())) {
            apiResponse(false, new stdClass(), [], 'Senha de confirmacao incorreta.', 422);
        }

        registrarAuditoriaConta($pdo, (int) $usuario['id'], $id, 'conta_excluida', [
            'perfil' => $contaExcluir['perfil'] ?? null,
            'email' => $contaExcluir['email'] ?? null,
        ]);
        $delete = $pdo->prepare('DELETE FROM usuario WHERE id = :id');
        $delete->execute(['id' => $id]);
        apiResponse(true, ['id' => $id], [], 'Conta excluida com sucesso.');
    }

    if ($method === 'PATCH') {
        if (!$hasPerfil) {
            apiResponse(false, new stdClass(), [], 'Atualize o schema antes de editar contas.', 409);
        }

        $id = (int) ($payload['id'] ?? 0);
        $nome = trim((string) ($payload['nome'] ?? ''));
        $perfil = normalizarPerfil((string) ($payload['perfil'] ?? 'leitura'));
        $adminGeralInformado = array_key_exists('administrador_geral', $payload);
        $adminGeral = filter_var($payload['administrador_geral'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $senhaConfirmacao = (string) ($payload['senha_confirmacao'] ?? '');
        $ativo = array_key_exists('ativo', $payload)
            ? filter_var($payload['ativo'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;
        $senha = (string) ($payload['senha'] ?? '');
        $temEmpresas = array_key_exists('empresa_ids', $payload);
        $empresaIds = $temEmpresas ? normalizarEmpresaIds($payload['empresa_ids']) : [];
        $self = (int) $usuario['id'] === $id;

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
            if ($empresaIds === []) {
                apiResponse(false, new stdClass(), [], 'Selecione pelo menos uma empresa para este usuario.', 422);
            }
        }

        $stmt = $pdo->prepare('SELECT id, nome, email, perfil, ' . ($hasAdminFlag ? 'administrador_geral' : 'FALSE AS administrador_geral') . ', ativo FROM usuario WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $contaAtual = $stmt->fetch();
        if (!$contaAtual) {
            apiResponse(false, new stdClass(), [], 'Conta nao encontrada.', 404);
        }

        if (!$administradorGeral && !contaEstaNoEscopo($pdo, $usuario, $contaAtual, $self)) {
            apiResponse(false, new stdClass(), [], 'Esta conta nao esta disponivel no seu nivel de acesso.', 403);
        }

        if ($perfilViewer === 'operador') {
            if (($contaAtual['perfil'] ?? 'leitura') !== 'leitura' || $perfil !== 'leitura' || $self) {
                apiResponse(false, new stdClass(), [], 'Operadores podem alterar somente usuarios de leitura da sua empresa.', 403);
            }
            if (!$temEmpresas) {
                $empresaIds = idsDasEmpresasDoUsuario($pdo, $id);
                $temEmpresas = true;
            }
        }

        if (!$adminGeralInformado) {
            $adminGeral = filter_var($contaAtual['administrador_geral'] ?? false, FILTER_VALIDATE_BOOLEAN);
        }
        $adminGeralAtual = filter_var($contaAtual['administrador_geral'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $ativoAtual = filter_var($contaAtual['ativo'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $alteracaoCritica = $perfil !== normalizarPerfil((string) ($contaAtual['perfil'] ?? ''))
            || ($ativo !== null && $ativo !== $ativoAtual)
            || $temEmpresas
            || ($adminGeralInformado && $adminGeral !== $adminGeralAtual)
            || $senha !== '';
        if ($alteracaoCritica) {
            validarSenhaConfirmacao($pdo, $usuario, $senhaConfirmacao);
        }
        if ($adminGeral && !$administradorGeral) {
            apiResponse(false, new stdClass(), [], 'Somente um administrador geral pode conceder acesso global.', 403);
        }
        if ($perfil === 'admin' && !$administradorGeral && ($contaAtual['perfil'] ?? '') !== 'admin') {
            apiResponse(false, new stdClass(), [], 'Somente um administrador geral pode criar administradores de empresa.', 403);
        }
        if ($perfil !== 'admin') {
            $adminGeral = false;
        }
        if ($adminGeral) {
            $empresaIds = [];
            $temEmpresas = true;
        }
        if (!$adminGeral && $temEmpresas && $empresaIds === []) {
            apiResponse(false, new stdClass(), [], 'Associe pelo menos uma empresa ou marque Administrador geral.', 422);
        }
        if (!$adminGeral && $adminGeralInformado && filter_var($contaAtual['administrador_geral'] ?? false, FILTER_VALIDATE_BOOLEAN) && !$temEmpresas) {
            apiResponse(false, new stdClass(), [], 'Associe pelo menos uma empresa ou marque Administrador geral.', 422);
        }

        if ($self && ($perfil !== 'admin' || $ativo === false)) {
            apiResponse(false, new stdClass(), [], 'Voce nao pode remover seu proprio acesso administrativo.', 422);
        }

        $sets = ['perfil = :perfil', 'atualizado_em = NOW()'];
        $params = ['id' => $id, 'perfil' => $perfil];
        if ($hasAdminFlag) {
            $sets[] = 'administrador_geral = :administrador_geral';
            $params['administrador_geral'] = $adminGeral ? '1' : '0';
        }
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
    $adminGeral = filter_var($payload['administrador_geral'] ?? false, FILTER_VALIDATE_BOOLEAN);
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
    if ($perfilViewer === 'operador') {
        $perfil = 'leitura';
        $adminGeral = false;
    }
    if ($perfil !== 'admin') {
        $adminGeral = false;
    }
    if ($perfil === 'admin' && !$administradorGeral) {
        apiResponse(false, new stdClass(), [], 'Somente um administrador geral pode criar administradores de empresa.', 403);
    }
    if ($adminGeral && !$administradorGeral) {
        apiResponse(false, new stdClass(), [], 'Somente um administrador geral pode criar outro administrador geral.', 403);
    }
    validarEmpresasAcessiveis($pdo, $usuario, $empresaIds);
    if ($adminGeral) {
        $empresaIds = [];
    } elseif ($empresaIds === []) {
        apiResponse(false, new stdClass(), [], 'Selecione pelo menos uma empresa para este usuario.', 422);
    }
    validarSenhaConfirmacao($pdo, $usuario, (string) ($payload['senha_confirmacao'] ?? ''));

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
            'INSERT INTO usuario (nome, email, senha_hash, perfil, administrador_geral, ativo, criado_em, atualizado_em)
             VALUES (:nome, :email, :senha_hash, :perfil, :administrador_geral, :ativo, NOW(), NOW())' . $returning
        );
        $insert->execute([
            'nome' => $nome,
            'email' => $email,
            'senha_hash' => $hash,
            'perfil' => $perfil,
            'administrador_geral' => $adminGeral ? '1' : '0',
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
    $conta['administrador_geral'] = filter_var($conta['administrador_geral'] ?? false, FILTER_VALIDATE_BOOLEAN);

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

function idsDasEmpresasDoUsuario(PDO $pdo, int $usuarioId): array
{
    $stmt = $pdo->prepare('SELECT empresa_id FROM usuario_empresa WHERE usuario_id = :usuario_id');
    $stmt->execute(['usuario_id' => $usuarioId]);
    return array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));
}

function validarSenhaConfirmacao(PDO $pdo, array $usuario, string $senha): void
{
    if ($senha === '') {
        apiResponse(false, new stdClass(), [], 'Digite sua senha para confirmar esta alteracao.', 422);
    }
    $stmt = $pdo->prepare('SELECT senha_hash FROM usuario WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => (int) ($usuario['id'] ?? 0)]);
    if (!password_verify($senha, (string) $stmt->fetchColumn())) {
        apiResponse(false, new stdClass(), [], 'Senha de confirmacao incorreta.', 422);
    }
}

function contaEstaNoEscopo(PDO $pdo, array $usuario, array $conta, bool $self): bool
{
    if ($self) {
        return true;
    }

    $viewerPerfil = normalizarPerfil((string) ($usuario['perfil'] ?? ''));
    $targetPerfil = normalizarPerfil((string) ($conta['perfil'] ?? ''));
    if ($viewerPerfil === 'operador' && $targetPerfil !== 'leitura') {
        return false;
    }
    if ($viewerPerfil === 'admin' && !in_array($targetPerfil, ['operador', 'leitura'], true)) {
        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT 1
         FROM usuario_empresa viewer_empresa
         INNER JOIN usuario_empresa target_empresa ON target_empresa.empresa_id = viewer_empresa.empresa_id
         WHERE viewer_empresa.usuario_id = :viewer_id AND target_empresa.usuario_id = :target_id
         LIMIT 1'
    );
    $stmt->execute([
        'viewer_id' => (int) ($usuario['id'] ?? 0),
        'target_id' => (int) ($conta['id'] ?? 0),
    ]);
    return (bool) $stmt->fetchColumn();
}
