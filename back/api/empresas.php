<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$usuario = exigirPerfilAdministrador($pdo);

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if ($method === 'GET') {
        apiResponse(true, [
            'items' => empresasAcessiveis($pdo, $usuario),
            'administrador_geral' => usuarioEhAdministradorGeral($pdo, $usuario),
        ]);
    }

    if (!usuarioEhAdministradorGeral($pdo, $usuario)) {
        apiResponse(false, new stdClass(), [], 'Somente o administrador geral pode gerenciar empresas.', 403);
    }

    if (!in_array($method, ['POST', 'PATCH'], true)) {
        apiMethod('POST');
    }
    apiMutationGuard();

    $payload = json_decode(file_get_contents('php://input'), true);
    $payload = is_array($payload) ? $payload : $_POST;
    $nome = trim((string) ($payload['nome'] ?? ''));

    if ($nome === '' || mb_strlen($nome) < 2) {
        apiResponse(false, new stdClass(), [], 'Informe um nome valido para a empresa.', 422);
    }

    if ($method === 'POST') {
        $stmt = $pdo->prepare('INSERT INTO empresa (nome, ativo) VALUES (:nome, TRUE)');
        $stmt->execute(['nome' => $nome]);
        $id = (int) $pdo->lastInsertId();
        registrarAuditoriaEmpresa($pdo, (int) $usuario['id'], 'empresa_criada', ['empresa_id' => $id, 'nome' => $nome]);
        apiResponse(true, ['id' => $id, 'nome' => $nome, 'ativo' => true], [], 'Empresa criada com sucesso.', 201);
    }

    $id = (int) ($payload['id'] ?? 0);
    if ($id <= 0) {
        apiResponse(false, new stdClass(), [], 'Empresa invalida.', 422);
    }
    $ativo = array_key_exists('ativo', $payload)
        ? filter_var($payload['ativo'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
        : true;
    $stmt = $pdo->prepare('UPDATE empresa SET nome = :nome, ativo = :ativo WHERE id = :id');
    $stmt->execute(['nome' => $nome, 'ativo' => $ativo === false ? '0' : '1', 'id' => $id]);
    if ($stmt->rowCount() === 0) {
        apiResponse(false, new stdClass(), [], 'Empresa nao encontrada.', 404);
    }
    registrarAuditoriaEmpresa($pdo, (int) $usuario['id'], 'empresa_atualizada', ['empresa_id' => $id, 'nome' => $nome, 'ativo' => $ativo !== false]);
    apiResponse(true, ['id' => $id, 'nome' => $nome, 'ativo' => $ativo !== false], [], 'Empresa atualizada com sucesso.');
} catch (Throwable $e) {
    apiHandle($e);
}

function registrarAuditoriaEmpresa(PDO $pdo, int $atorId, string $acao, array $detalhes): void
{
    try {
        $jsonValue = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? ':detalhes' : 'CAST(:detalhes AS jsonb)';
        $stmt = $pdo->prepare(
            "INSERT INTO usuario_auditoria (ator_id, acao, detalhes, ip, user_agent, criado_em)
             VALUES (:ator_id, :acao, {$jsonValue}, :ip, :user_agent, NOW())"
        );
        $stmt->execute([
            'ator_id' => $atorId,
            'acao' => $acao,
            'detalhes' => json_encode($detalhes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        ]);
    } catch (Throwable $e) {
        error_log('Auditoria indisponivel: ' . $e->getMessage());
    }
}
