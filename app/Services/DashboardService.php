<?php

declare(strict_types=1);

namespace NyxCloud\Services;

final class DashboardService extends AbstractAcronisService
{
    private const CACHE_VERSION = 'v16';

    public function summary(array $filters = []): array
    {
        $fast = filter_var($filters['fast'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $cacheKey = 'acronis.dashboard.' . self::CACHE_VERSION . '.' . $this->escopoCacheKey() . '.' . md5(json_encode($filters));

        if ($fast) {
            $cached = $this->cache->getStale($cacheKey);
            if (is_array($cached)) {
                $cached['cache_stale'] = true;
                return $cached;
            }
        }

        return $this->remember($cacheKey, (int) $this->config['cache_ttl']['dashboard'], function () use ($filters, $fast): array {
            $tenantFilters = $filters;
            unset($tenantFilters['fast']);
            $tenants = $this->filterTenantScopedItems($this->items($this->api->get($this->endpoint('tenants'), $this->tenantScopeFilters($tenantFilters))));
            $customers = $this->customerTenants($tenants);
            $devices = array_values(array_filter($this->workloadItems(), fn (array $device): bool => $this->isRealDevice($device)));
            $tasks = $this->taskItems(30);

            $backupTasks = array_values(array_filter($tasks, fn (array $task): bool => $this->isBackupTask($task)));
            $successful = array_values(array_filter($backupTasks, fn (array $task): bool => $this->taskResult($task) === 'ok'));
            $failed = array_values(array_filter($backupTasks, fn (array $task): bool => $this->taskResult($task) === 'failed'));
            $daily = $this->dailyHistory($backupTasks);
            $storage = $this->storageForDashboard($customers, $fast);

            $summary = [
                'total_clientes' => count($customers),
                'total_dispositivos' => count($devices),
                'total_backups' => count($backupTasks),
                'backups_ok' => count($successful),
                'backups_com_falha' => count($failed),
                'ultimo_backup' => $this->latestDate($backupTasks, ['completedAt', 'updatedAt', 'startedAt']),
                'espaco_utilizado' => $storage['total'],
                'armazenamento_disponivel' => $storage['available'],
                'armazenamento_clientes' => $storage['clients'],
                'taxa_sucesso' => count($backupTasks) > 0 ? round((count($successful) / count($backupTasks)) * 100, 2) : 0.0,
                'series_diarias' => $daily['series'],
                'armazenamento_por_dia' => $daily['storage'],
            ];

            if (!$fast) {
                $fastFilters = $filters;
                $fastFilters['fast'] = '1';
                $this->cache->set(
                    'acronis.dashboard.' . self::CACHE_VERSION . '.' . $this->escopoCacheKey() . '.' . md5(json_encode($fastFilters)),
                    $summary,
                    (int) $this->config['cache_ttl']['dashboard']
                );
            }

            return $summary;
        });
    }

    private function storageForDashboard(array $customers, bool $fast): array
    {
        $cacheKey = 'acronis.dashboard.storage.v2.' . $this->escopoCacheKey();

        if ($fast) {
            $cached = $this->cache->get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }

            return [
                'total' => 0,
                'clients' => [],
                'available' => false,
            ];
        }

        return $this->remember(
            $cacheKey,
            (int) ($this->config['cache_ttl']['storage'] ?? 1800),
            fn (): array => $this->storageSummary($customers)
        );
    }

    private function isBackupTask(array $task): bool
    {
        if (($task['policy']['type'] ?? '') === 'backup' || trim((string) ($task['context']['BackupPlanName'] ?? '')) !== '') {
            return true;
        }

        $haystack = strtolower(json_encode([
            $task['type'] ?? '',
            $task['context']['title'] ?? '',
            $task['context']['Specific'] ?? '',
            $task['context']['BackupPlanName'] ?? '',
            $task['policy']['type'] ?? '',
            $task['queue'] ?? '',
        ]));

        return str_contains($haystack, 'backup');
    }

    private function customerTenants(array $tenants): array
    {
        $customers = array_values(array_filter(
            $tenants,
            static fn (array $tenant): bool => strtolower((string) ($tenant['kind'] ?? '')) === 'customer'
        ));

        return $customers !== [] ? $customers : $tenants;
    }

    private function taskResult(array $task): string
    {
        $status = strtolower((string) ($task['result']['code'] ?? $task['state'] ?? 'unknown'));

        return match ($status) {
            'ok', 'success', 'successful', 'completed' => 'ok',
            'error', 'failed', 'fail' => 'failed',
            default => $status,
        };
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

    private function storageSummary(array $customers): array
    {
        $total = 0;
        $clients = [];
        $successfulQueries = 0;
        foreach ($customers as $tenant) {
            $tenantId = (string) ($tenant['id'] ?? $tenant['uuid'] ?? '');
            if ($tenantId === '') {
                continue;
            }

            try {
                $usage = $this->api->get($this->endpoint('tenant_usages', ['tenant_id' => $tenantId]));
                $bytes = $this->sumUsageBytes($usage);
                $successfulQueries++;
                $total += $bytes;
                $clients[] = [
                    'cliente' => (string) ($tenant['name'] ?? $tenant['customer_name'] ?? 'Sem nome'),
                    'bytes' => $bytes,
                ];
            } catch (\Throwable $e) {
                error_log('Acronis excecao ao consultar usage do tenant ' . $tenantId . ': ' . $e->getMessage());
            }
        }

        usort($clients, static fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);

        return [
            'total' => $total,
            'clients' => array_slice($clients, 0, 10),
            'available' => $successfulQueries > 0,
        ];
    }

    private function sumUsageBytes(mixed $usage): int
    {
        if (!is_array($usage)) {
            return 0;
        }

        if (isset($usage['items']) && is_array($usage['items'])) {
            $total = 0;
            foreach ($usage['items'] as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $unit = strtolower((string) ($item['measurement_unit'] ?? ''));
                if ($unit !== '' && !str_contains($unit, 'byte')) {
                    continue;
                }

                $value = $item['absolute_value'] ?? $item['value'] ?? null;
                if (is_numeric($value)) {
                    $total += (int) $value;
                }
            }

            return $total;
        }

        $total = 0;
        array_walk_recursive($usage, static function (mixed $value, string $key) use (&$total): void {
            if (is_numeric($value) && preg_match('/(byte|bytes|storage|space|usage|used)/i', $key) === 1) {
                $total += (int) $value;
            }
        });

        return $total;
    }

    private function dailyHistory(array $tasks): array
    {
        $days = [];
        for ($offset = 29; $offset >= 0; $offset--) {
            $day = date('Y-m-d', strtotime('-' . $offset . ' days'));
            $days[$day] = [
                'date' => $day,
                'backups' => 0,
                'success' => 0,
                'failed' => 0,
                'storage_bytes' => 0,
            ];
        }

        foreach ($tasks as $task) {
            $date = $this->taskDate($task);
            if ($date === '' || !isset($days[$date])) {
                continue;
            }

            $days[$date]['backups']++;
            if ($this->taskResult($task) === 'ok') {
                $days[$date]['success']++;
            } elseif ($this->taskResult($task) === 'failed') {
                $days[$date]['failed']++;
            }
            $days[$date]['storage_bytes'] += $this->taskBytes($task);
        }

        $ordered = array_values($days);

        return [
            'series' => array_map(fn (array $item): array => [
                'date' => $item['date'],
                'label' => date('d/m', strtotime($item['date'])),
                'backups' => $item['backups'],
                'success' => $item['success'],
                'failed' => $item['failed'],
                'bytes' => $item['storage_bytes'],
            ], $ordered),
            'storage' => array_map(fn (array $item): array => [
                'date' => $item['date'],
                'label' => date('d/m', strtotime($item['date'])),
                'bytes' => $item['storage_bytes'],
            ], $ordered),
        ];
    }

    private function taskDate(array $task): string
    {
        foreach (['completedAt', 'updatedAt', 'startedAt'] as $field) {
            $value = (string) ($task[$field] ?? '');
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return date('Y-m-d', $timestamp);
            }
        }

        return '';
    }

    private function taskBytes(array $task): int
    {
        $candidates = [
            $task['progress']['bytesProcessed'] ?? null,
            $task['context']['_runtime']['bytesProcessed'] ?? null,
            $task['progress']['bytesSaved'] ?? null,
            $task['context']['_runtime']['bytesSaved'] ?? null,
        ];

        foreach ($candidates as $value) {
            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return 0;
    }
}
