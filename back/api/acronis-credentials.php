<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use NyxCloud\Services\AcronisCredentialStore;

try {
    $usuario = exigirPerfilAdministrador($pdo);
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
        apiResponse(true, $store->save($input), [], 'Integracao salva.');
    }

    if ($method === 'PATCH') {
        $active = filter_var($input['active'] ?? true, FILTER_VALIDATE_BOOLEAN);
        apiResponse(true, $store->activate((string) ($input['id'] ?? ''), $active), [], $active ? 'Integracao ativada.' : 'Integracao desativada.');
    }

    if ($method === 'DELETE') {
        apiResponse(true, $store->delete((string) ($input['id'] ?? '')), [], 'Integracao removida.');
    }

    apiResponse(false, new stdClass(), [], 'Metodo nao permitido.', 405);
} catch (InvalidArgumentException $e) {
    apiResponse(false, new stdClass(), [], $e->getMessage(), 422);
} catch (Throwable $e) {
    apiHandle($e);
}
