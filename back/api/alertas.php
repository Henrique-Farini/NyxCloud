<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use NyxCloud\Services\AlertService;

apiMethod('GET');
exigirAutenticacao($pdo);

try {
    /** @var AlertService $service */
    $service = apiService(AlertService::class);
    $filters = apiFilters(['limit', 'offset', 'order', 'severity', 'status', 'type']);
    $data = $service->listAlerts($filters);

    apiResponse(true, $data, [
        'count' => count($data),
        'filters' => $filters,
    ], '');
} catch (Throwable $e) {
    apiHandle($e);
}
