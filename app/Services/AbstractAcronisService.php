<?php

declare(strict_types=1);

namespace NyxCloud\Services;

use NyxCloud\Lib\Acronis\AcronisApi;
use NyxCloud\Lib\Cache\CacheInterface;

abstract class AbstractAcronisService
{
    private ?array $tenantScopeCache = null;

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

        return $this->remember('acronis.tasks.daily.v2.' . $days, 300, function () use ($days): array {
            $items = [];
            $after = '';
            $since = gmdate('Y-m-d\TH:i:s\Z', time() - ($days * 86400));

            for ($page = 0; $page < 100; $page++) {
                $query = $after === ''
                    ? ['limit' => 1000, 'completedAt' => 'gt(' . $since . ')']
                    : ['limit' => 1000, 'after' => $after];

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

            return $items;
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
