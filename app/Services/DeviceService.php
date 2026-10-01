<?php

declare(strict_types=1);

namespace NyxCloud\Services;

final class DeviceService extends AbstractAcronisService
{
    private const CACHE_VERSION = 'v13';

    public function listDevices(array $filters = []): array
    {
        return $this->remember('acronis.devices.' . self::CACHE_VERSION . '.' . $this->escopoCacheKey() . '.' . md5(json_encode($filters)), (int) $this->config['cache_ttl']['devices'], function () use ($filters): array {
            $workloads = $this->workloadItems($filters);

            try {
                $tenants = $this->filterTenantScopedItems($this->items($this->api->get($this->endpoint('tenants'), $this->tenantScopeFilters())));
            } catch (\Throwable $e) {
                error_log('Acronis: inventario carregado sem mapa de clientes: ' . $e->getMessage());
                $tenants = [];
            }

            try {
                $tasks = $this->taskItems(30);
            } catch (\Throwable $e) {
                error_log('Acronis: inventario carregado sem historico de tarefas: ' . $e->getMessage());
                $tasks = [];
            }
            $tenantMap = $this->tenantMapByNumericId($tenants, $tasks);
            $planStats = $this->buildPlanStats($tasks);

            return array_values(array_map(
                fn (array $device): array => $this->mapDevice($device, $tenantMap, $planStats),
                array_filter($workloads, fn (array $device): bool => $this->isRealDevice($device))
            ));
        });
    }

    public function dailyExecutions(): array
    {
        return $this->remember('acronis.devices.daily-executions.v3.' . $this->escopoCacheKey(), 120, function (): array {
            $devices = $this->listDevices([]);
            $tasks = $this->taskItems(30);
            $deviceMap = $this->deviceLookupMap($devices);
            $days = [date('Y-m-d'), date('Y-m-d', strtotime('-1 day'))];
            $rows = [];

            foreach ($tasks as $task) {
                if (!is_array($task) || !$this->isBackupTask($task)) {
                    continue;
                }

                $completedAt = $this->firstString($task, ['completedAt', 'updatedAt', 'startedAt']);
                $timestamp = strtotime($completedAt);
                if ($timestamp === false) {
                    continue;
                }

                $day = date('Y-m-d', $timestamp);
                if (!in_array($day, $days, true)) {
                    continue;
                }

                $cliente = $this->firstString($task, ['tenant.name'], 'Cliente nao identificado');
                $hostname = $this->firstString($task, ['resource.name', 'context.Persistent.Name', 'context.MachineName'], 'Dispositivo nao identificado');
                $plan = $this->firstString($task, ['policy.name', 'context.BackupPlanName'], 'Sem plano');
                $device = $deviceMap[$this->machineKey($cliente, $hostname)] ?? $deviceMap[$this->machineKey('', $hostname)] ?? [];
                $key = $day . '|' . $this->planKey($cliente, $hostname, $plan);

                if (isset($rows[$key]) && strtotime((string) $rows[$key]['ultimo_backup']) >= $timestamp) {
                    continue;
                }

                $sizeBytes = $this->firstTaskBytesNullable($task);
                $rows[$key] = [
                    'data' => $day,
                    'cliente' => $cliente,
                    'hostname' => $hostname,
                    'plano' => $plan,
                    'ultimo_backup' => $completedAt,
                    'status' => $this->normalizeTaskStatus($task),
                    'tamanho_bytes' => $sizeBytes,
                    'tamanho' => $sizeBytes === null ? 'Nao informado' : ($sizeBytes > 0 ? $this->formatBytes((float) $sizeBytes) : '0 B'),
                    'ip' => (string) ($device['ip'] ?? ''),
                ];
            }

            $grouped = ['hoje' => [], 'ontem' => []];
            foreach ($rows as $row) {
                $grouped[$row['data'] === $days[0] ? 'hoje' : 'ontem'][] = $row;
            }

            foreach ($grouped as &$items) {
                usort($items, fn (array $a, array $b): int => strnatcasecmp(
                    $a['cliente'] . '|' . $a['hostname'] . '|' . $a['plano'],
                    $b['cliente'] . '|' . $b['hostname'] . '|' . $b['plano']
                ));
            }
            unset($items);

            return [
                'datas' => ['hoje' => $days[0], 'ontem' => $days[1]],
                'hoje' => $grouped['hoje'],
                'ontem' => $grouped['ontem'],
            ];
        });
    }

    private function mapDevice(array $device, array $tenantMap, array $planStats): array
    {
        $attributes = is_array($device['attributes'] ?? null) ? $device['attributes'] : [];
        $merged = array_merge($device, ['attributes' => $attributes]);
        $tenantId = $this->firstString($merged, ['tenant_id']);
        $hostname = $this->firstString($merged, ['name', 'attributes.hostname', 'attributes.host_name']);
        $plans = $this->extractPlans($this->firstString($merged, [
            'cross_policy_status.names',
            'attributes.plan_name',
            'attributes.protection_plan_name',
            'status.plan.name',
        ]));
        $details = [];
        foreach ($plans as $plan) {
            $detail = $this->resolvePlanDetail(
                $tenantMap[$tenantId]['nome'] ?? '',
                $hostname,
                $plan,
                $planStats
            );
            if ($detail !== null) {
                $details[] = $detail;
            }
        }

        usort($details, static fn (array $a, array $b): int => strtotime((string) ($b['ultimo_backup'] ?? '')) <=> strtotime((string) ($a['ultimo_backup'] ?? '')));
        $primaryDetail = $details[0] ?? null;
        $deviceLastBackup = $this->firstString($merged, [
            'per_policy_type_statuses.0.last_success_run_time',
            'attributes.last_successful_backup',
            'attributes.last_backup',
        ]);
        $lastBackup = $this->latestDate($deviceLastBackup, (string) ($primaryDetail['ultimo_backup'] ?? ''));
        $sizeBytes = (int) ($primaryDetail['tamanho_realizado_bytes'] ?? 0);

        return [
            'cliente' => $tenantMap[$tenantId]['nome'] ?? 'Cliente nao identificado',
            'tenant' => $tenantId,
            'hostname' => $hostname,
            'sistema_operacional' => $this->firstString($merged, [
                'attributes.agent.os_name',
                'attributes.os',
                'attributes.os_name',
                'attributes.operating_system',
                'attributes.default.OperatingSystem.0',
            ]),
            'ip' => $this->firstString($merged, [
                'attributes.agent.ip_addresses.0',
                'attributes.default.IP.0',
                'attributes.ip',
                'attributes.ip_address',
                'attributes.last_ip',
            ]),
            'plano' => $this->firstString($merged, [
                'cross_policy_status.names',
                'attributes.plan_name',
                'attributes.protection_plan_name',
                'status.plan.name',
            ]),
            'status' => $this->normalizeStatus($merged),
            'ultimo_backup' => $lastBackup,
            'dias_sem_backup' => $lastBackup !== '' ? $this->daysSinceDate($lastBackup) : null,
            'tamanho_realizado_bytes' => $sizeBytes,
            'tamanho_realizado' => $sizeBytes > 0 ? $this->formatBytes((float) $sizeBytes) : 'Nao informado',
            'planos_detalhes' => $details,
            'raw' => $device,
        ];
    }

    private function buildPlanStats(array $tasks): array
    {
        $stats = [];

        foreach ($tasks as $task) {
            if (!is_array($task) || !$this->isBackupTask($task)) {
                continue;
            }

            $tenant = $this->firstString($task, ['tenant.name']);
            $machine = $this->firstString($task, ['resource.name', 'context.Persistent.Name', 'context.MachineName']);
            $plan = $this->firstString($task, ['policy.name', 'context.BackupPlanName']);
            $completedAt = $this->firstString($task, ['completedAt', 'updatedAt', 'startedAt']);

            if ($tenant === '' || $machine === '' || $plan === '' || $completedAt === '') {
                continue;
            }

            $key = $this->planKey($tenant, $machine, $plan);
            if (isset($stats[$key]) && strtotime($stats[$key]['ultimo_backup']) >= strtotime($completedAt)) {
                continue;
            }

            $sizeBytes = $this->firstTaskBytes($task);
            $stats[$key] = [
                'plano' => $plan,
                'ultimo_backup' => $completedAt,
                'dias_sem_backup' => $this->daysSinceDate($completedAt),
                'tamanho_realizado_bytes' => $sizeBytes,
                'tamanho_realizado' => $sizeBytes > 0 ? $this->formatBytes((float) $sizeBytes) : 'Nao informado',
                'status' => $this->normalizeTaskStatus($task),
            ];
        }

        return $stats;
    }

    private function latestDate(string ...$dates): string
    {
        $latest = '';
        $latestTimestamp = 0;
        foreach ($dates as $date) {
            $timestamp = strtotime($date);
            if ($timestamp !== false && $timestamp > $latestTimestamp) {
                $latestTimestamp = $timestamp;
                $latest = $date;
            }
        }

        return $latest;
    }

    private function resolvePlanDetail(string $cliente, string $hostname, string $plan, array $planStats): ?array
    {
        if ($plan === '') {
            return null;
        }

        $key = $this->planKey($cliente, $hostname, $plan);
        if (!isset($planStats[$key])) {
            return [
                'plano' => $plan,
                'ultimo_backup' => '',
                'dias_sem_backup' => null,
                'tamanho_realizado_bytes' => 0,
                'tamanho_realizado' => 'Nao informado',
                'status' => 'unknown',
            ];
        }

        return $planStats[$key];
    }

    private function extractPlans(string $source): array
    {
        $plans = array_values(array_filter(array_map('trim', explode(';', $source))));
        return $plans === [] ? ['Sem plano'] : array_values(array_unique($plans));
    }

    private function planKey(string $cliente, string $hostname, string $plan): string
    {
        return $this->normalizeLookupText($cliente) . '|' . $this->normalizeLookupText($hostname) . '|' . $this->normalizeLookupText($plan);
    }

    private function machineKey(string $cliente, string $hostname): string
    {
        return $this->normalizeLookupText($cliente) . '|' . $this->normalizeLookupText($hostname);
    }

    private function deviceLookupMap(array $devices): array
    {
        $map = [];
        foreach ($devices as $device) {
            if (!is_array($device)) {
                continue;
            }

            $cliente = (string) ($device['cliente'] ?? '');
            $hostname = (string) ($device['hostname'] ?? '');
            if ($hostname === '') {
                continue;
            }

            $map[$this->machineKey($cliente, $hostname)] = $device;
            $map[$this->machineKey('', $hostname)] = $device;
        }

        return $map;
    }

    private function normalizeLookupText(string $value): string
    {
        $text = trim(mb_strtolower($value));
        $text = preg_replace('/\s*\([^)]*\)/u', '', $text) ?? $text;
        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
        $text = preg_replace('/[^a-z0-9]+/i', '', $text) ?? $text;
        return $text;
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

    private function firstTaskBytes(array $task): int
    {
        return $this->firstTaskBytesNullable($task) ?? 0;
    }

    private function firstTaskBytesNullable(array $task): ?int
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

        return null;
    }

    private function normalizeTaskStatus(array $task): string
    {
        $result = strtolower($this->firstString($task, ['result.code', 'state'], 'unknown'));

        return match ($result) {
            'success', 'successful', 'completed', 'ok' => 'success',
            'error', 'failed', 'fail' => 'failed',
            'running', 'progress' => 'running',
            'warning', 'queued', 'pending' => 'queued',
            default => 'unknown',
        };
    }

    private function daysSinceDate(string $backup): ?int
    {
        if ($backup === '') {
            return null;
        }

        $timestamp = strtotime($backup);
        if ($timestamp === false) {
            return null;
        }

        $diff = time() - $timestamp;
        if ($diff <= 0) {
            return 0;
        }

        return (int) floor($diff / 86400);
    }

    private function normalizeStatus(array $device): string
    {
        $status = strtolower($this->firstString($device, [
            'cross_policy_status.status',
            'status.overall',
            'status.protection',
            'status',
            'enabled',
        ], 'unknown'));

        $normalized = match ($status) {
            'ok', 'idle', 'success', 'successful' => 'success',
            'warning', 'queued', 'pending' => 'queued',
            'error', 'failed', 'fail' => 'failed',
            default => $status,
        };

        if ($normalized !== 'unknown') {
            return $normalized;
        }

        $runningState = strtoupper($this->firstString($device, [
            'cross_policy_status.running.running_state',
        ]));

        return $runningState === 'RUNNING' ? 'running' : 'unknown';
    }

    private function daysSinceBackup(array $device): ?int
    {
        $backup = $this->firstString($device, [
            'per_policy_type_statuses.0.last_success_run_time',
            'attributes.last_successful_backup',
            'attributes.last_backup',
            'updated_at',
            'updatedAt',
        ]);

        if ($backup === '') {
            return null;
        }

        $timestamp = strtotime($backup);
        if ($timestamp === false) {
            return null;
        }

        $diff = time() - $timestamp;
        if ($diff <= 0) {
          return 0;
        }

        return (int) floor($diff / 86400);
    }

    private function tenantMapByNumericId(array $tenants, array $tasks): array
    {
        $tenantByUuid = [];
        foreach ($tenants as $tenant) {
            if (!is_array($tenant)) {
                continue;
            }

            $uuid = $this->firstString($tenant, ['id', 'uuid', 'tenant_id']);
            if ($uuid === '') {
                continue;
            }

            $tenantByUuid[$uuid] = [
                'nome' => $this->firstString($tenant, ['name', 'customer_name'], 'Sem nome'),
                'raw' => $tenant,
            ];
        }

        $map = [];
        foreach ($tasks as $task) {
            if (!is_array($task)) {
                continue;
            }

            $uuid = $this->firstString($task, ['tenant.uuid']);
            $numericId = $this->firstString($task, ['tenant.id']);
            if ($uuid === '' || $numericId === '') {
                continue;
            }

            $map[$numericId] = $tenantByUuid[$uuid] ?? [
                'nome' => $this->firstString($task, ['tenant.name'], 'Cliente nao identificado'),
                'raw' => $task['tenant'] ?? [],
            ];
        }

        return $map;
    }

    private function firstNumericString(array $source, array $paths): string
    {
        foreach ($paths as $path) {
            $value = $this->value($source, $path);
            if (!is_scalar($value)) {
                continue;
            }

            $digits = preg_replace('/[^\d.]/', '', (string) $value);
            if ($digits !== null && $digits !== '' && is_numeric($digits)) {
                return $digits;
            }
        }

        return '';
    }

    private function formatBytes(float $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $index = 0;

        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }

        $decimals = $index >= 3 ? 1 : 0;

        return number_format($bytes, $decimals, ',', '.') . ' ' . $units[$index];
    }
}
