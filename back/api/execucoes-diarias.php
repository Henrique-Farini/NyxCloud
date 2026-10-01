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
    $data = $service->dailyExecutions();
    apiResponse(true, $data, [
        'hoje' => count($data['hoje'] ?? []),
        'ontem' => count($data['ontem'] ?? []),
    ], '');
} catch (Throwable $e) {
    apiHandle($e);
}
