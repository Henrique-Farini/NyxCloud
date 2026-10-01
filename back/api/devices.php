<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use NyxCloud\Services\DeviceService;

apiMethod('GET');
$usuario = exigirAutenticacao($pdo);

try {
    /** @var DeviceService $service */
    $service = apiService(DeviceService::class);
    aplicarEscopoAcronis($service, $pdo, $usuario);
    $filters = apiFilters(['limit', 'offset', 'tenant_id', 'type', 'status', 'name', 'include_status', 'include_all_attributes']);
    $data = $service->listDevices($filters);

    apiResponse(true, $data, [
        'count' => count($data),
        'filters' => $filters,
    ], '');
} catch (Throwable $e) {
    apiHandle($e);
}
