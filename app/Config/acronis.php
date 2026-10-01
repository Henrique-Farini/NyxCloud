<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'back' . DIRECTORY_SEPARATOR . 'config.php';

$activeAccount = [];
$accounts = [];
$activeIds = [];
$storePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'acronis_accounts.json';
$databaseConfig = [];
global $pdo;
if ($pdo instanceof PDO && class_exists(\NyxCloud\Services\AcronisCredentialStore::class)) {
    $databaseConfig = (new \NyxCloud\Services\AcronisCredentialStore())->config();
}

if ($databaseConfig !== []) {
    $activeIds = (array) ($databaseConfig['active_ids'] ?? []);
    foreach ((array) ($databaseConfig['accounts'] ?? []) as $account) {
        if (!is_array($account)) {
            continue;
        }
        $accounts[] = $account;
        if ($activeAccount === [] && in_array((string) ($account['id'] ?? ''), $activeIds, true)) {
            $activeAccount = $account;
        }
    }
    if ($activeAccount === [] && $accounts !== []) {
        $activeAccount = $accounts[0];
        $activeIds = [(string) ($activeAccount['id'] ?? '')];
    }
} elseif (is_file($storePath)) {
    $data = json_decode((string) file_get_contents($storePath), true);
    $rawActiveIds = is_array($data) ? ($data['active_ids'] ?? ($data['active_id'] ?? [])) : [];
    if (is_string($rawActiveIds)) {
        $activeIds = trim($rawActiveIds) !== '' ? [trim($rawActiveIds)] : [];
    } elseif (is_array($rawActiveIds)) {
        $activeIds = array_values(array_unique(array_filter(array_map('trim', $rawActiveIds), static fn (string $value): bool => $value !== '')));
    }

    foreach ((array) ($data['accounts'] ?? []) as $account) {
        if (!is_array($account)) {
            continue;
        }

        $secretValue = (string) ($account['client_secret'] ?? '');
        $plainSecret = '';
        if (str_starts_with($secretValue, 'v1:')) {
            $raw = base64_decode(substr($secretValue, 3), true);
            if ($raw !== false && strlen($raw) >= 29) {
                $key = hash('sha256', (string) env('JWT_SECRET', ''), true);
                $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
                $plainSecret = is_string($plain) ? $plain : '';
            }
        }

        $normalized = [
            'id' => (string) ($account['id'] ?? ''),
            'name' => (string) ($account['name'] ?? ''),
            'region' => (string) ($account['region'] ?? 'BR'),
            'base_url' => rtrim((string) ($account['base_url'] ?? ''), '/'),
            'client_id' => (string) ($account['client_id'] ?? ''),
            'client_secret' => $plainSecret,
            'updated_at' => (string) ($account['updated_at'] ?? ''),
        ];

        $accounts[] = $normalized;

        if ($activeIds === [] && $normalized['id'] === (string) ($data['active_id'] ?? '')) {
            $activeIds = [$normalized['id']];
        }

        if ($activeAccount === [] && in_array($normalized['id'], $activeIds, true)) {
            $activeAccount = $normalized;
        }
    }

    if ($activeAccount === [] && $accounts !== []) {
        $activeAccount = $accounts[0];
        $activeIds = [$activeAccount['id'] ?? ''];
    }
}

$singleActiveAccount = $activeAccount !== [] ? $activeAccount : ($accounts[0] ?? []);

return [
    'active_id' => $activeIds[0] ?? '',
    'active_ids' => $activeIds,
    'accounts' => $accounts,
    'base_url' => $singleActiveAccount['base_url'] ?? rtrim((string) env('ACRONIS_BASE_URL', ''), '/'),
    'client_id' => $singleActiveAccount['client_id'] ?? (string) env('ACRONIS_CLIENT_ID', ''),
    'client_secret' => $singleActiveAccount['client_secret'] ?? (string) env('ACRONIS_CLIENT_SECRET', ''),
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
        'policies' => '/api/policy_management/v4/policies',
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
