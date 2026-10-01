<?php

declare(strict_types=1);

namespace NyxCloud\Services;

final class CustomerService extends AbstractAcronisService
{
    private const CACHE_VERSION = 'v14';

    public function listCustomers(array $filters = []): array
    {
        return $this->remember('acronis.customers.' . self::CACHE_VERSION . '.' . $this->escopoCacheKey() . '.' . md5(json_encode($filters)), (int) $this->config['cache_ttl']['customers'], function () use ($filters): array {
            $tenantFilters = array_merge(['limit' => 1000], $filters);
            $tenants = $this->tenantItems($tenantFilters);
            $devices = array_values(array_filter(
                $this->workloadItems(),
                fn (array $device): bool => $this->isRealDevice($device)
            ));
            $tasks = $this->taskItems(90);
            $tenantFamilyMap = $this->tenantFamilyMap($tenants);
            $tenantNumericMap = $this->tenantNumericMap($tasks);
            $devicesByTenant = $this->groupByTenant($devices);
            $tasksByTenant = $this->groupByTenant($tasks);
            $customers = array_values(array_filter(
                $tenants,
                static fn (array $tenant): bool => strtolower((string) ($tenant['kind'] ?? '')) === 'customer'
            ));
            if ($customers === []) {
                $customers = $tenants;
            }

            return array_map(
                fn (array $tenant): array => $this->mapCustomer($tenant, $devicesByTenant, $tasksByTenant, $tenantFamilyMap, $tenantNumericMap),
                $customers
            );
        });
    }

    private function tenantItems(array $filters): array
    {
        $items = [];
        $after = '';
        $baseQuery = $this->tenantScopeFilters($filters);

        for ($page = 0; $page < 100; $page++) {
            $query = $baseQuery;
            if ($after !== '') {
                $query['after'] = $after;
            }

            $payload = $this->api->get($this->endpoint('tenants'), $query);
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
    }

    private function mapCustomer(array $tenant, array $devicesByTenant, array $tasksByTenant, array $tenantFamilyMap, array $tenantNumericMap): array
    {
        $tenantId = $this->firstString($tenant, ['id', 'uuid', 'tenant_id']);
        $familyIds = $tenantFamilyMap[$tenantId] ?? [$tenantId];
        $numericFamilyIds = array_values(array_filter(array_map(
            fn (string $familyId): string => $tenantNumericMap[$familyId] ?? '',
            $familyIds
        )));
        $allFamilyIds = array_values(array_unique(array_merge($familyIds, $numericFamilyIds)));
        $tenantDevices = $this->familyItems($devicesByTenant, $allFamilyIds);
        $tenantTasks = $this->familyItems($tasksByTenant, $allFamilyIds);
        $deviceCount = count($tenantDevices);

        if ($deviceCount === 0) {
            // Workloads expose an internal numeric tenant_id while the tenant
            // catalog uses UUIDs. Query by customerUuid when the local maps do
            // not contain a usable numeric bridge.
            $deviceCount = $this->workloadCountForTenantFamily($familyIds);
        }

        $lastBackup = $this->latestDate($tenantTasks, ['completedAt', 'updatedAt', 'startedAt']);
        if ($lastBackup === '') {
            $targetName = mb_strtolower(trim((string) $this->firstString($tenant, ['name', 'customer_name'])));
            $targetName = preg_replace('/\s*\([^)]*\)/u', '', $targetName) ?? $targetName;
            $nameMatchedTasks = [];
            foreach ($tasksByTenant as $group) {
                foreach ($group as $task) {
                    $taskName = mb_strtolower(trim($this->firstString($task, ['tenant.name'])));
                    if ($targetName !== '' && $taskName !== '' && (str_contains($taskName, $targetName) || str_contains($targetName, $taskName))) {
                        $nameMatchedTasks[] = $task;
                    }
                }
            }
            $lastBackup = $this->latestDate($nameMatchedTasks, ['completedAt', 'updatedAt', 'startedAt']);
        }

        return [
            'nome' => $this->firstString($tenant, ['name', 'customer_name'], 'Sem nome'),
            'tenant' => $tenantId,
            'quantidade_dispositivos' => $deviceCount,
            'plano' => $this->firstString($tenant, ['edition', 'pricing_mode', 'kind', 'type']),
            'ultimo_backup' => $lastBackup,
            'raw' => $tenant,
        ];
    }

    private function latestDate(array $items, array $fields): string
    {
        $latest = 0;
        foreach ($items as $item) {
            foreach ($fields as $field) {
                $timestamp = strtotime((string) ($item[$field] ?? ''));
                if ($timestamp !== false) {
                    $latest = max($latest, $timestamp);
                }
            }
        }

        return $latest > 0 ? date('c', $latest) : '';
    }

    private function tenantFamilyMap(array $tenants): array
    {
        $childrenByParent = [];
        foreach ($tenants as $tenant) {
            $tenantId = $this->firstString($tenant, ['id', 'uuid', 'tenant_id']);
            $parentId = $this->firstString($tenant, ['parent_id', 'parentId']);

            if ($tenantId === '') {
                continue;
            }

            if ($parentId !== '') {
                $childrenByParent[$parentId][] = $tenantId;
            }
        }

        $familyMap = [];
        foreach ($tenants as $tenant) {
            $tenantId = $this->firstString($tenant, ['id', 'uuid', 'tenant_id']);
            if ($tenantId === '') {
                continue;
            }

            $familyMap[$tenantId] = $this->collectDescendants($tenantId, $childrenByParent);
        }

        return $familyMap;
    }

    private function collectDescendants(string $tenantId, array $childrenByParent): array
    {
        $family = [$tenantId];
        $queue = [$tenantId];

        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($childrenByParent[$current] ?? [] as $childId) {
                if (in_array($childId, $family, true)) {
                    continue;
                }

                $family[] = $childId;
                $queue[] = $childId;
            }
        }

        return $family;
    }

    private function tenantRef(array $payload): string
    {
        return $this->firstString($payload, [
            'tenant_id',
            'tenantId',
            'tenant.id',
            'tenant.uuid',
            'context.tenant_id',
            'context.tenant.id',
            'context.tenant.uuid',
        ]);
    }

    private function groupByTenant(array $items): array
    {
        $grouped = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $tenantId = $this->tenantRef($item);
            if ($tenantId !== '') {
                $grouped[$tenantId][] = $item;
            }
        }

        return $grouped;
    }

    private function familyItems(array $grouped, array $familyIds): array
    {
        $items = [];
        foreach ($familyIds as $familyId) {
            if (isset($grouped[$familyId])) {
                array_push($items, ...$grouped[$familyId]);
            }
        }

        return $items;
    }

    private function workloadCountForTenantFamily(array $familyIds): int
    {
        $cacheKey = 'acronis.customer.workload-count.' . self::CACHE_VERSION . '.' . $this->escopoCacheKey() . '.' . md5(json_encode($familyIds));

        return $this->remember($cacheKey, (int) $this->config['cache_ttl']['customers'], function () use ($familyIds): int {
            $workloadIds = [];

            foreach ($familyIds as $familyId) {
                try {
                $items = array_values(array_filter($this->items($this->api->get($this->endpoint('workloads'), [
                    'search' => "customerUuid = '" . addslashes($familyId) . "'",
                    'include_status' => 'true',
                    'include_all_attributes' => 'true',
                    'limit' => 500,
                ])), fn (array $device): bool => $this->isRealDevice($device)));
                } catch (\Throwable $e) {
                    error_log('Acronis excecao ao consultar workloads do tenant ' . $familyId . ': ' . $e->getMessage());
                    continue;
                }

                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $workloadId = $this->firstString($item, ['id', 'external_id', 'name']);
                    if ($workloadId !== '') {
                        $workloadIds[$workloadId] = true;
                    }
                }
            }

            return count($workloadIds);
        });
    }

    private function tenantNumericMap(array $tasks): array
    {
        $map = [];

        foreach ($tasks as $task) {
            if (!is_array($task)) {
                continue;
            }

            $uuid = $this->firstString($task, ['tenant.uuid']);
            $numericId = $this->firstString($task, ['tenant.id']);

            if ($uuid !== '' && $numericId !== '') {
                $map[$uuid] = $numericId;
            }
        }

        return $map;
    }
}
