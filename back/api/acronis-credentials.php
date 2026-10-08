<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use NyxCloud\Services\AcronisCredentialStore;

try {
    $usuario = exigirAutenticacao($pdo);
    exigirPermissaoAcao($pdo, $usuario, 'integrations.manage');
    $store = new AcronisCredentialStore();
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        apiResponse(true, $store->listSafe());
    }

    apiMutationGuard();
    $input = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($input)) {
        apiResponse(false, new stdClass(), [], 'JSON invalido.', 422);
    }

    if ($method === 'POST') {
        $result = $store->save($input);
        registrarAuditoriaAcronis($pdo, (int) $usuario['id'], 'integracao_salva', $input);
        apiResponse(true, $result, [], 'Integracao salva.');
    }

    if ($method === 'PATCH') {
        $active = filter_var($input['active'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $result = $store->activate((string) ($input['id'] ?? ''), $active);
        registrarAuditoriaAcronis($pdo, (int) $usuario['id'], $active ? 'integracao_ativada' : 'integracao_desativada', $input);
        apiResponse(true, $result, [], $active ? 'Integracao ativada.' : 'Integracao desativada.');
    }

    if ($method === 'DELETE') {
        $result = $store->delete((string) ($input['id'] ?? ''));
        registrarAuditoriaAcronis($pdo, (int) $usuario['id'], 'integracao_removida', $input);
        apiResponse(true, $result, [], 'Integracao removida.');
    }

    apiResponse(false, new stdClass(), [], 'Metodo nao permitido.', 405);
} catch (InvalidArgumentException $e) {
    apiResponse(false, new stdClass(), [], $e->getMessage(), 422);
} catch (Throwable $e) {
    apiHandle($e);
}

function registrarAuditoriaAcronis(PDO $pdo, int $atorId, string $acao, array $input): void
{
    try {
        $detalhes = [
            'integracao_id' => (string) ($input['id'] ?? ''),
            'nome' => (string) ($input['name'] ?? ''),
            'regiao' => (string) ($input['region'] ?? ''),
            'ativo' => array_key_exists('active', $input) ? filter_var($input['active'], FILTER_VALIDATE_BOOLEAN) : null,
        ];
        $json = json_encode($detalhes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $value = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ':detalhes' : 'CAST(:detalhes AS jsonb)';
        $stmt = $pdo->prepare("INSERT INTO usuario_auditoria (ator_id, acao, detalhes, ip, user_agent, criado_em) VALUES (:ator_id, :acao, {$value}, :ip, :user_agent, CURRENT_TIMESTAMP)");
        $stmt->execute(['ator_id' => $atorId, 'acao' => $acao, 'detalhes' => $json, 'ip' => $_SERVER['REMOTE_ADDR'] ?? null, 'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500)]);
    } catch (Throwable $e) {
        error_log('Auditoria Acronis indisponivel: ' . $e->getMessage());
    }
}
