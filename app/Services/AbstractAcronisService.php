<?php

declare(strict_types=1);

namespace NyxCloud\Services;

use NyxCloud\Lib\Acronis\AcronisApi;
use NyxCloud\Lib\Cache\CacheInterface;

abstract class AbstractAcronisService
{
    private ?array $tenantScopeCache = null;
    private ?array $allowedTenantIds = null;
    private ?array $allowedTenantNames = null;

    public function __construct(
        protected readonly AcronisApi $api,
        protected readonly CacheInterface $cache,
        protected readonly array $config
    ) {
    }

    protected function remember(string $key, int $ttl, callable $callback): mixed
    {
        $cached = $this->cache->get($key);
        if ($cached !== null) {
            return $cached;
        }

        $lockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nyxcloud-cache-' . hash('sha256', $key) . '.lock';
        $lock = @fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

            $value = $callback();
            $this->cache->set($key, $value, $ttl);
            return $value;
        }

        try {
            // Outra requisicao pode ter preenchido o cache enquanto esta aguardava o lock.
            $cached = $this->cache->get($key);
            if ($cached !== null) {
                return $cached;
            }

            $value = $callback();
            $this->cache->set($key, $value, $ttl);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $value;
    }

    protected function endpoint(string $name, array $replace = []): string
    {
        $endpoint = (string) ($this->config['endpoints'][$name] ?? '');
        foreach ($replace as $key => $value) {
            $endpoint = str_replace('{' . $key . '}', rawurlencode((string) $value), $endpoint);
        }

        return $endpoint;
    }

    protected function items(mixed $payload): array
    {
        if (!is_array($payload)) {
            return [];
        }

        if (isset($payload['items']) && is_array($payload['items'])) {
            return $payload['items'];
        }

        if (isset($payload['data']) && is_array($payload['data'])) {
            return $payload['data'];
        }

        return array_is_list($payload) ? $payload : [];
    }

    protected function tenantScopeFilters(array $filters = []): array
    {
        foreach (['uuids', 'parent_id', 'subtree_root_id', 'after'] as $requiredKey) {
            if (!empty($filters[$requiredKey])) {
                return $filters;
            }
        }

        if ($this->tenantScopeCache !== null) {
            return array_merge($this->tenantScopeCache, $filters);
        }

        $clientId = (string) ($this->config['client_id'] ?? '');
        if ($clientId === '') {
            return $filters;
        }

        $client = $this->api->get($this->endpoint('client', ['client_id' => $clientId]));
        $tenantId = '';

        if (is_array($client)) {
            $tenantId = $this->firstString($client, [
                'tenant_id',
                'tenantId',
                'tenant.id',
                'tenant.uuid',
                'data.tenant_id',
                'data.tenant.id',
                'data.tenant.uuid',
            ]);
        }

        $this->tenantScopeCache = $tenantId !== '' ? ['subtree_root_id' => $tenantId] : [];

        return array_merge($this->tenantScopeCache, $filters);
    }

    protected function firstString(array $source, array $paths, string $default = ''): string
    {
        foreach ($paths as $path) {
            $value = $this->value($source, $path);
            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return $default;
    }

    protected function value(array $source, string $path): mixed
    {
        $current = $source;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    protected function taskItems(int $days = 30): array
    {
        $days = max(1, $days);

        return $this->remember('acronis.tasks.daily.v4.' . $days . '.' . $this->escopoCacheKey(), 300, function () use ($days): array {
            $items = [];
            $since = gmdate('Y-m-d\TH:i:s\Z', time() - ($days * 86400));
            $scopeIds = $this->tenantScopeIds();
            $taskTenantIds = [];

            if ($scopeIds !== null) {
                foreach ($this->workloadItems() as $workload) {
                    if (!is_array($workload)) {
                        continue;
                    }

                    $numericTenantId = $this->firstString($workload, ['tenant_id']);
                    if ($numericTenantId !== '') {
                        $taskTenantIds[$numericTenantId] = true;
                    }
                }
            }

            $tenantQueries = $scopeIds === null
                ? [null]
                : array_keys($taskTenantIds);

            foreach ($tenantQueries as $tenantId) {
                $after = '';
                for ($page = 0; $page < 100; $page++) {
                    $query = $after === ''
                        ? ['limit' => 1000, 'completedAt' => 'gt(' . $since . ')']
                        : ['limit' => 1000, 'after' => $after];

                    if ($tenantId !== null) {
                        // Task Manager expects the internal numeric tenant id.
                        $query['tenant'] = $tenantId;
                    }

                    $payload = $this->api->get($this->endpoint('tasks'), $query);
                    $pageItems = $this->items($payload);
                    foreach ($pageItems as $item) {
                        if (is_array($item)) {
                            $items[] = $item;
                        }
                    }

                    $next = is_array($payload) ? (string) ($payload['paging']['cursors']['after'] ?? '') : '';
                    if ($pageItems === [] || $next === '' || $next === $after) {
                        break;
                    }

                    $after = $next;
                }
            }

            if ($scopeIds === null || $taskTenantIds !== []) {
                return $items;
            }

            return $this->filterTenantScopedItems($items);
        });
    }

    public function definirEscopoTenants(?array $tenantIds, ?array $tenantNames = null): void
    {
        $this->allowedTenantIds = $tenantIds === null
            ? null
            : array_values(array_unique(array_filter(array_map('strval', $tenantIds), static fn (string $id): bool => $id !== '')));
        $this->allowedTenantNames = $tenantNames === null
            ? null
            : array_values(array_unique(array_filter(array_map(
                static fn (string $name): string => mb_strtolower(trim($name)),
                $tenantNames
            ), static fn (string $name): bool => $name !== '')));
        $this->tenantScopeCache = null;
    }

    protected function escopoCacheKey(): string
    {
        return $this->allowedTenantIds === null
            ? 'all'
            : hash('sha256', json_encode([$this->allowedTenantIds, $this->allowedTenantNames]));
    }

    protected function tenantScopeNames(): array
    {
        return $this->allowedTenantNames ?? [];
    }

    protected function tenantScopeIds(): ?array
    {
        return $this->allowedTenantIds;
    }

    protected function itemPertenceAoEscopo(array $item): bool
    {
        if ($this->allowedTenantIds === null) {
            return true;
        }
        if ($this->allowedTenantIds === [] && $this->allowedTenantNames === []) {
            return false;
        }

        foreach (['tenant_id', 'tenantID', 'tenant.id', 'tenant.uuid', 'context.tenant_id', 'context.tenant.id', 'context.tenant.uuid', 'id', 'uuid'] as $path) {
            $value = trim($this->firstString($item, [$path]));
            if ($value !== '' && in_array($value, $this->allowedTenantIds ?? [], true)) {
                return true;
            }
        }

        if ($this->allowedTenantNames !== null) {
            foreach (['tenant.name', 'tenant_name', 'cliente', 'empresa', 'name', 'customer_name'] as $path) {
                $name = mb_strtolower(trim($this->firstString($item, [$path])));
                if ($name !== '') {
                    return in_array($name, $this->allowedTenantNames, true);
                }
            }
        }

        return false;
    }

    protected function filterTenantScopedItems(array $items): array
    {
        if ($this->allowedTenantIds === null) {
            return $items;
        }
        return array_values(array_filter($items, fn (mixed $item): bool => is_array($item) && $this->itemPertenceAoEscopo($item)));
    }

    protected function policyItems(): array
    {
        return $this->remember('acronis.policies.v1.' . $this->escopoCacheKey(), 300, function (): array {
            $items = [];
            $after = '';

            for ($page = 0; $page < 100; $page++) {
                $query = $after === '' ? ['limit' => 1000] : ['limit' => 1000, 'after' => $after];
                $payload = $this->api->get($this->endpoint('policies'), $query);
                $pageItems = $this->items($payload);
                foreach ($pageItems as $item) {
                    if (is_array($item)) {
                        $items[] = $item;
                    }
                }

                $next = is_array($payload) ? (string) ($payload['paging']['cursors']['after'] ?? '') : '';
                if ($pageItems === [] || $next === '' || $next === $after) {
                    break;
                }
                $after = $next;
            }

            return $this->filterTenantScopedItems($items);
        });
    }

    protected function workloadItems(array $query = []): array
    {
        $baseQuery = array_merge([
            'include_status' => 'true',
            'include_all_attributes' => 'true',
            'limit' => 500,
        ], $query);
        $cacheKey = 'acronis.workloads.v3.' . $this->escopoCacheKey() . '.' . md5(json_encode($baseQuery));

        return $this->remember($cacheKey, (int) ($this->config['cache_ttl']['devices'] ?? 300), function () use ($baseQuery): array {
            $items = [];
            $scopeIds = $this->tenantScopeIds();
            $tenantQueries = $scopeIds === null ? [null] : $scopeIds;

            foreach ($tenantQueries as $tenantId) {
                $after = '';
                for ($page = 0; $page < 100; $page++) {
                    $query = $baseQuery;
                    if ($tenantId !== null) {
                        // The workload API returns an internal numeric tenant_id
                        // in items, so filtering by tenant_id with the platform
                        // UUID can return only aggregate groups. The documented
                        // customerUuid search returns the actual machines.
                        $query['search'] = "customerUuid = '" . addslashes($tenantId) . "'";
                    }
                    if ($after !== '') {
                        $query['after'] = $after;
                    }

                    $payload = $this->api->get($this->endpoint('workloads'), $query);
                    $pageItems = $this->items($payload);
                    foreach ($pageItems as $item) {
                        if (is_array($item)) {
                            $items[] = $item;
                        }
                    }

                    $next = is_array($payload) ? (string) ($payload['paging']['cursors']['after'] ?? '') : '';
                    if ($pageItems === [] || $next === '' || $next === $after) {
                        break;
                    }

                    $after = $next;
                }
            }

            if ($scopeIds === null) {
                return $items;
            }

            // Cada chamada acima já foi feita com customerUuid de um tenant
            // permitido; não compare o tenant_id numérico retornado no item
            // com o UUID da empresa, pois são identificadores diferentes.
            return array_values(array_filter($items, static fn (mixed $item): bool => is_array($item)));
        });
    }

    protected function isRealDevice(array $device): bool
    {
        if (strtolower((string) ($device['type_alias'] ?? '')) === 'resource.machine') {
            return true;
        }

        return $this->firstString($device, ['agent_id', 'attributes.agent.name']) !== '';
    }
}
