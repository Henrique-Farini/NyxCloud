<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use NyxCloud\Services\CustomerService;

apiMethod('GET');
$usuario = exigirAutenticacao($pdo);

try {
    /** @var CustomerService $service */
    $service = apiService(CustomerService::class);
    aplicarEscopoAcronis($service, $pdo, $usuario);
    $filters = apiFilters(['limit', 'offset', 'parent_id', 'kind', 'edition', 'id']);
    $data = $service->listCustomers($filters);

    apiResponse(true, $data, [
        'count' => count($data),
        'filters' => $filters,
    ], '');
} catch (Throwable $e) {
    apiHandle($e);
}
