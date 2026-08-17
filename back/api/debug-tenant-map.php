<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use NyxCloud\Lib\Acronis\AcronisFactory;

apiMethod('GET');
exigirAutenticacao($pdo);

try {
    $config = AcronisFactory::config();
    $api = AcronisFactory::api($config);

    $clientId = (string) ($config['client_id'] ?? '');
    $clientEndpoint = str_replace('{client_id}', rawurlencode($clientId), (string) $config['endpoints']['client']);
    $client = $clientId !== '' ? $api->get($clientEndpoint) : null;

    $subtreeRootId = '';
    if (is_array($client)) {
        $subtreeRootId =
            (string) ($client['tenant_id'] ?? '') ?:
            (string) ($client['tenantId'] ?? '') ?:
            (string) ($client['tenant']['id'] ?? '') ?:
            (string) ($client['tenant']['uuid'] ?? '') ?:
            (string) ($client['data']['tenant_id'] ?? '') ?:
            (string) ($client['data']['tenant']['id'] ?? '') ?:
            (string) ($client['data']['tenant']['uuid'] ?? '');
    }

    $tenantFilters = $subtreeRootId !== '' ? ['subtree_root_id' => $subtreeRootId] : [];

    $tenants = $api->get((string) $config['endpoints']['tenants'], $tenantFilters);
    $workloads = $api->get((string) $config['endpoints']['workloads'], [
        'include_status' => 'true',
        'include_all_attributes' => 'true',
        'limit' => 25,
    ]);
    $tasks = $api->get((string) $config['endpoints']['tasks'], ['limit' => 25]);

    apiResponse(true, [
        'resolved_subtree_root_id' => $subtreeRootId,
        'client' => $client,
        'tenants_sample' => array_slice(is_array($tenants['items'] ?? null) ? $tenants['items'] : (is_array($tenants) ? $tenants : []), 0, 3),
        'workloads_sample' => array_slice(is_array($workloads['items'] ?? null) ? $workloads['items'] : (is_array($workloads) ? $workloads : []), 0, 5),
        'tasks_sample' => array_slice(is_array($tasks['items'] ?? null) ? $tasks['items'] : (is_array($tasks) ? $tasks : []), 0, 5),
    ], [], '');
} catch (Throwable $e) {
    apiHandle($e);
}
