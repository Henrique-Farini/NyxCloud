<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use NyxCloud\Services\DashboardService;

apiMethod('GET');
$usuario = exigirAutenticacao($pdo);

try {
    /** @var DashboardService $service */
    $service = apiService(DashboardService::class);
    aplicarEscopoAcronis($service, $pdo, $usuario);
    $filters = apiFilters();
    $data = $service->summary($filters);

    apiResponse(true, $data, ['filters' => $filters], '');
} catch (Throwable $e) {
    apiHandle($e);
}
