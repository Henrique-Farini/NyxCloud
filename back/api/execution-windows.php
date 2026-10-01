<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use NyxCloud\Services\ExecutionWindowService;

apiMethod('GET');
$usuario = exigirAutenticacao($pdo);

try {
    /** @var ExecutionWindowService $service */
    $service = apiService(ExecutionWindowService::class);
    aplicarEscopoAcronis($service, $pdo, $usuario);
    $filters = apiFilters(['date', 'stale']);
    $data = $service->listWindows($filters);

    apiResponse(true, $data, ['filters' => $filters], '');
} catch (Throwable $e) {
    apiHandle($e);
}
