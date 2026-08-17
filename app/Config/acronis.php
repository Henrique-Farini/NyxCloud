<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'back' . DIRECTORY_SEPARATOR . 'config.php';

return [
    'base_url' => rtrim((string) env('ACRONIS_BASE_URL', ''), '/'),
    'client_id' => (string) env('ACRONIS_CLIENT_ID', ''),
    'client_secret' => (string) env('ACRONIS_CLIENT_SECRET', ''),
    'timeout' => max(1, (int) env('ACRONIS_TIMEOUT', '30')),
    'ssl_verify' => filter_var(env('ACRONIS_SSL_VERIFY', 'true'), FILTER_VALIDATE_BOOLEAN),
    'token_cache_ttl' => max(60, (int) env('ACRONIS_TOKEN_CACHE_TTL', '6600')),
    'cache_path' => (string) env(
        'ACRONIS_CACHE_PATH',
        dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'acronis'
    ),
    'endpoints' => [
        'oauth_token' => '/api/2/idp/token',
        'client' => '/api/2/clients/{client_id}',
        'tenants' => '/api/2/tenants',
        'tenant_usages' => '/api/2/tenants/{tenant_id}/usages',
        'workloads' => '/api/workload_management/v5/workloads',
        'alerts' => '/api/alert_manager/v1/alerts',
        'tasks' => '/api/task_manager/v2/tasks',
        'resource_statuses' => '/api/resource_management/v4/resource_statuses',
    ],
    'cache_ttl' => [
        'dashboard' => 300,
        'storage' => 1800,
        'customers' => 600,
        'devices' => 300,
        'alerts' => 60,
    ],
];
