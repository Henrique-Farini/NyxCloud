<?php

declare(strict_types=1);

namespace NyxCloud\Services;

final class CustomerService extends AbstractAcronisService
{
    private const CACHE_VERSION = 'v18';

    public function listCustomers(array $filters = []): array
    {
        return $this->remember('acronis.customers.' . self::CACHE_VERSION . '.' . $this->escopoCacheKey() . '.' . md5(json_encode($filters)), (int) $this->config['cache_ttl']['customers'], function () use ($filters): array {
            $tenantFilters = array_merge(['limit' => 1000], $filters);
            $tenants = $this->tenantItems($tenantFilters);
            $devices = array_values(array_filter(
                $this->workloadItems(),
                fn (array $device): bool => $this->isRealDevice($device)
            ));
            $tasks = $this->taskItems(30);
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
            $tenantDevices = $this->workloadItemsForTenantFamily($familyIds);
            $deviceCount = count($tenantDevices);
        }

        $latestTask = $this->latestTask($tenantTasks, ['completedAt', 'updatedAt', 'startedAt']);
        $lastBackup = $latestTask !== null ? $this->taskDate($latestTask, ['completedAt', 'updatedAt', 'startedAt']) : '';
        $lastBackupStatus = $latestTask !== null ? $this->taskStatus($latestTask) : 'unknown';
        $summaryTasks = $tenantTasks;
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
            $summaryTasks = array_merge($summaryTasks, $nameMatchedTasks);
            $latestTask = $this->latestTask($nameMatchedTasks, ['completedAt', 'updatedAt', 'startedAt']);
            $lastBackup = $latestTask !== null ? $this->taskDate($latestTask, ['completedAt', 'updatedAt', 'startedAt']) : '';
            $lastBackupStatus = $latestTask !== null ? $this->taskStatus($latestTask) : 'unknown';
        }
        $taskSummary = $this->taskSummary($summaryTasks);

        return [
            'nome' => $this->firstString($tenant, ['name', 'customer_name'], 'Sem nome'),
            'tenant' => $tenantId,
            'quantidade_dispositivos' => $deviceCount,
            'quantidade_planos' => $taskSummary['quantidade_planos'],
            'execucoes_hoje' => $taskSummary['execucoes_hoje'],
            'execucoes_ontem' => $taskSummary['execucoes_ontem'],
            'media_execucoes_dia' => $taskSummary['media_execucoes_dia'],
            'dias_com_execucao' => $taskSummary['dias_com_execucao'],
            'dispositivos' => array_map(fn (array $device): array => $this->mapDeviceSummary($device, $tenantTasks), $tenantDevices),
            'plano' => $this->firstString($tenant, ['edition', 'pricing_mode', 'kind', 'type']),
            'ultimo_backup' => $lastBackup,
            'ultimo_backup_status' => $lastBackupStatus,
            'raw' => $tenant,
        ];
    }

    private function taskSummary(array $tasks): array
    {
        $plans = [];
        $daily = [];

        foreach ($tasks as $task) {
            if (!is_array($task) || !$this->isBackupTask($task)) {
                continue;
            }

            $plan = $this->firstString($task, ['policy.name', 'context.BackupPlanName']);
            if ($plan !== '') {
                $plans[$this->normalizeLookupText($plan)] = true;
            }

            $timestamp = strtotime($this->firstString($task, ['completedAt', 'updatedAt', 'startedAt']));
            if ($timestamp === false) {
                continue;
            }

            $day = date('Y-m-d', $timestamp);
            $daily[$day] = ($daily[$day] ?? 0) + 1;
        }

        $dailyValues = array_values($daily);
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        return [
            'quantidade_planos' => count($plans),
            'execucoes_hoje' => (int) ($daily[$today] ?? 0),
            'execucoes_ontem' => (int) ($daily[$yesterday] ?? 0),
            'media_execucoes_dia' => $dailyValues === [] ? 0 : round(array_sum($dailyValues) / count($dailyValues), 1),
            'dias_com_execucao' => count($dailyValues),
        ];
    }

    private function isBackupTask(array $task): bool
    {
        if (($task['policy']['type'] ?? '') === 'backup') {
            return true;
        }

        if ($this->firstString($task, ['context.BackupPlanName']) !== '') {
            return true;
        }

        $title = strtolower(trim((string) ($task['context']['title'] ?? '')));
        return str_starts_with($title, 'backup plan');
    }

    private function mapDeviceSummary(array $device, array $tasks): array
    {
        $hostname = $this->firstString($device, ['name', 'attributes.hostname', 'attributes.host_name'], 'Dispositivo sem nome');
        $details = [];
        foreach ($tasks as $task) {
            if (!is_array($task)) {
                continue;
            }
            $taskHostname = $this->firstString($task, ['resource.name', 'context.Persistent.Name', 'context.MachineName']);
            if ($taskHostname === '' || $this->normalizeLookupText($taskHostname) !== $this->normalizeLookupText($hostname)) {
                continue;
            }
            $plan = $this->firstString($task, ['policy.name', 'context.BackupPlanName'], 'Sem plano');
            $completedAt = $this->firstString($task, ['completedAt', 'updatedAt', 'startedAt']);
            $key = $this->normalizeLookupText($plan);
            $timestamp = strtotime($completedAt) ?: 0;
            $sizeBytes = $this->taskBytes($task);
            if (isset($details[$key]) && $sizeBytes > 0) {
                $details[$key]['_amostras_tamanho'][] = $sizeBytes;
            }
            if (isset($details[$key]) && (strtotime((string) ($details[$key]['ultimo_backup'] ?? '')) ?: 0) >= $timestamp) {
                continue;
            }
            $details[$key] = [
                'plano' => $plan,
                'ultimo_backup' => $completedAt,
                'tamanho_realizado_bytes' => $sizeBytes,
                'tamanho_realizado' => $sizeBytes > 0 ? $this->formatBytes((float) $sizeBytes) : 'Nao informado',
                'status' => 'success',
                '_amostras_tamanho' => array_merge($details[$key]['_amostras_tamanho'] ?? [], $sizeBytes > 0 ? [$sizeBytes] : []),
            ];
        }
        foreach ($details as &$detail) {
            $samples = array_values(array_filter($detail['_amostras_tamanho'] ?? [], static fn ($value): bool => is_numeric($value) && (int) $value > 0));
            sort($samples, SORT_NUMERIC);
            $count = count($samples);
            $median = $count === 0 ? 0 : ($count % 2 ? $samples[intdiv($count, 2)] : (int) round(($samples[$count / 2 - 1] + $samples[$count / 2]) / 2));
            $detail['media_tamanho_bytes'] = $median;
            $detail['media_tamanho'] = $median > 0 ? $this->formatBytes((float) $median) : 'Nao informado';
            $detail['mediana_tamanho_bytes'] = $median;
            $detail['mediana_tamanho'] = $detail['media_tamanho'];
            unset($detail['_amostras_tamanho']);
        }
        unset($detail);
        $details = array_values($details);
        usort($details, static fn (array $left, array $right): int => (strtotime((string) ($right['ultimo_backup'] ?? '')) ?: 0) <=> (strtotime((string) ($left['ultimo_backup'] ?? '')) ?: 0));
        $latest = $details[0] ?? [];
        $deviceBytes = $this->deviceBackupBytes($device);
        return [
            'cliente' => $this->firstString($device, ['tenant.name', 'tenant_name', 'customer_name']),
            'tenant' => $this->tenantRef($device),
            'hostname' => $hostname,
            'ip' => $this->firstString($device, ['attributes.agent.ip_addresses.0', 'attributes.default.IP.0', 'attributes.ip', 'attributes.ip_address']),
            'plano' => $this->firstString($device, ['cross_policy_status.names', 'attributes.plan_name', 'attributes.protection_plan_name', 'status.plan.name'], $latest['plano'] ?? 'Sem plano'),
            'status' => 'unknown',
            'ultimo_backup' => $latest['ultimo_backup'] ?? $this->firstString($device, ['per_policy_type_statuses.0.last_success_run_time', 'attributes.last_successful_backup', 'attributes.last_backup']),
            'tamanho_realizado_bytes' => (int) ($latest['tamanho_realizado_bytes'] ?? $deviceBytes),
            'tamanho_realizado' => $latest['tamanho_realizado'] ?? ($deviceBytes > 0 ? $this->formatBytes((float) $deviceBytes) : 'Nao informado'),
            'planos_detalhes' => $details,
            'raw' => $device,
        ];
    }

    private function taskBytes(array $task): int
    {
        foreach ([
            $task['progress']['bytesProcessed'] ?? null,
            $task['context']['_runtime']['bytesProcessed'] ?? null,
            $task['progress']['bytesSaved'] ?? null,
            $task['context']['_runtime']['bytesSaved'] ?? null,
            $task['result']['bytesProcessed'] ?? null,
            $task['result']['bytesSaved'] ?? null,
            $task['result']['totalBytes'] ?? null,
            $task['result']['payload']['bytesProcessed'] ?? null,
            $task['result']['payload']['bytesSaved'] ?? null,
            $task['result']['payload']['totalBytes'] ?? null,
            $task['statistics']['bytesProcessed'] ?? null,
            $task['statistics']['totalBytes'] ?? null,
            $task['data']['bytesProcessed'] ?? null,
            $task['data']['totalBytes'] ?? null,
        ] as $value) {
            if (is_numeric($value)) {
                return max(0, (int) $value);
            }
        }
        return 0;
    }

    private function deviceBackupBytes(array $device): int
    {
        foreach ([
            'per_policy_type_statuses.0.last_success_run_size',
            'per_policy_type_statuses.0.last_success_run_bytes',
            'attributes.last_successful_backup_size',
            'attributes.last_successful_backup_bytes',
            'attributes.last_backup_size',
            'attributes.last_backup_bytes',
        ] as $path) {
            $value = $this->firstString($device, [$path]);
            if (is_numeric($value)) {
                return max(0, (int) $value);
            }
        }
        return 0;
    }

    private function normalizeLookupText(string $value): string
    {
        $text = trim(mb_strtolower($value));
        $text = preg_replace('/\s*\([^)]*\)/u', '', $text) ?? $text;
        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
        return preg_replace('/[^a-z0-9]+/i', '', $text) ?? $text;
    }

    private function formatBytes(float $bytes): string
    {
        if ($bytes <= 0) return 'Nao informado';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = 0;
        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }
        return number_format($bytes, $index === 0 ? 0 : 2, ',', '.') . ' ' . $units[$index];
    }

    private function latestTask(array $items, array $fields): ?array
    {
        $latestTimestamp = 0;
        $latestTask = null;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach ($fields as $field) {
                $timestamp = strtotime((string) ($item[$field] ?? ''));
                if ($timestamp !== false && $timestamp > $latestTimestamp) {
                    $latestTimestamp = $timestamp;
                    $latestTask = $item;
                }
            }
        }

        return $latestTask;
    }

    private function taskDate(array $task, array $fields): string
    {
        $latestTimestamp = 0;
        foreach ($fields as $field) {
            $timestamp = strtotime((string) ($task[$field] ?? ''));
            if ($timestamp !== false) {
                $latestTimestamp = max($latestTimestamp, $timestamp);
            }
        }

        return $latestTimestamp > 0 ? date('c', $latestTimestamp) : '';
    }

    private function taskStatus(array $task): string
    {
        $result = is_array($task['result'] ?? null) ? $task['result'] : [];
        $status = strtolower(trim((string) ($result['code'] ?? $task['state'] ?? $task['status'] ?? '')));

        return match ($status) {
            'ok', 'success', 'successful', 'completed', 'complete' => 'success',
            'error', 'failed', 'fail', 'failure' => 'failed',
            'running', 'in_progress', 'processing', 'queued', 'pending' => 'running',
            default => 'unknown',
        };
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

    private function workloadItemsForTenantFamily(array $familyIds): array
    {
        $cacheKey = 'acronis.customer.workload-items.' . self::CACHE_VERSION . '.' . $this->escopoCacheKey() . '.' . md5(json_encode($familyIds));

        return $this->remember($cacheKey, (int) $this->config['cache_ttl']['customers'], function () use ($familyIds): array {
            $itemsById = [];
            foreach ($familyIds as $familyId) {
                try {
                    $items = $this->items($this->api->get($this->endpoint('workloads'), [
                        'search' => "customerUuid = '" . addslashes($familyId) . "'",
                        'include_status' => 'true',
                        'include_all_attributes' => 'true',
                        'limit' => 500,
                    ]));
                } catch (\Throwable $e) {
                    error_log('Acronis excecao ao consultar workloads detalhados do tenant ' . $familyId . ': ' . $e->getMessage());
                    continue;
                }

                foreach ($items as $item) {
                    if (!is_array($item) || !$this->isRealDevice($item)) {
                        continue;
                    }
                    $key = $this->firstString($item, ['id', 'external_id', 'name']);
                    if ($key !== '') {
                        $itemsById[$key] = $item;
                    }
                }
            }
            return array_values($itemsById);
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
