<?php

declare(strict_types=1);

namespace NyxCloud\Services;

use DateTimeImmutable;
use DateTimeZone;

final class AlertService extends AbstractAcronisService
{
    private const CACHE_VERSION = 'v28';
    private ?array $windowRulesCache = null;
    private ?DateTimeZone $timezoneCache = null;

    public function listAlerts(array $filters = []): array
    {
        return $this->remember('acronis.alerts.' . self::CACHE_VERSION . '.' . $this->escopoCacheKey() . '.' . md5(json_encode($filters)), (int) $this->config['cache_ttl']['alerts'], function () use ($filters): array {
            $tenants = $this->filterTenantScopedItems($this->items($this->api->get($this->endpoint('tenants'), $this->tenantScopeFilters())));
            $tenantMap = $this->buildTenantMap($tenants);
            $workloads = $this->workloadItems();
            $workloadMap = $this->buildWorkloadMap($workloads, $tenantMap);
            $payload = $this->api->get($this->endpoint('alerts'), array_merge([
                'order' => 'desc(created_at)',
                'limit' => 100,
            ], $filters));
            $tasks = [];
            $taskIndex = [];
            $alertItems = $this->filterTenantScopedItems($this->items($payload));
            $taskLookbackDays = $this->taskLookbackDaysForAlerts($alertItems);
            try {
                $tasks = $this->taskItems($taskLookbackDays);
                $tenantMap = $this->extendTenantMapFromTasks($tenantMap, $tasks);
                $workloadMap = $this->buildWorkloadMap($workloads, $tenantMap);
                $taskIndex = $this->buildTaskIndex($tasks, $tenantMap);
            } catch (\Throwable $e) {
                error_log('Acronis: tarefas recentes indisponiveis para enriquecer alertas: ' . $e->getMessage());
            }

            $native = array_map(
                fn (array $alert): array => $this->mapAlert($alert, $tenantMap, $workloadMap, $taskIndex),
                $alertItems
            );

            try {
                $operational = $this->operationalAlerts($tasks !== [] ? $tasks : $this->taskItems(max(30, $taskLookbackDays)), $workloadMap);
            } catch (\Throwable $e) {
                error_log('Acronis: alertas operacionais indisponiveis: ' . $e->getMessage());
                $operational = [];
            }

            try {
                $missingBackups = $this->missingBackupAlerts($workloads, $tenantMap, $tasks, $native, $operational);
            } catch (\Throwable $e) {
                error_log('Acronis: alertas de backups ausentes indisponiveis: ' . $e->getMessage());
                $missingBackups = [];
            }

            $alerts = array_values(array_filter(
                array_merge($missingBackups, $operational, $native),
                fn (array $alert): bool => !$this->shouldHideAlertByPlan($alert)
            ));
            usort($alerts, static fn (array $a, array $b): int => strcmp(
                (string) ($b['data'] ?? '') . (string) ($b['hora'] ?? ''),
                (string) ($a['data'] ?? '') . (string) ($a['hora'] ?? '')
            ));

            return $alerts;
        });
    }

    private function mapAlert(array $alert, array $tenantMap, array $workloadMap, array $taskIndex = []): array
    {
        $createdAt = $this->firstString($alert, ['createdAt', 'created_at', 'receivedAt', 'updatedAt']);
        $timestamp = $createdAt !== '' ? strtotime($createdAt) : false;
        $cliente = $this->resolveClientName($alert, $tenantMap);
        $maquina = $this->firstString($alert, [
            'resourceName',
            'resource_name',
            'details.resourceName',
            'details.machineName',
            'details.agentName',
            'details.deviceName',
            'details.hostName',
            'details.workloadName',
            'details.fields.Device name',
            'details.fields.Device ID',
            'entity.name',
            'entity.id',
        ], 'Recurso');
        $tipo = $this->firstString($alert, [
            'type',
            'details.type',
            'details.fields.Alert type',
            'details.fields.Resource type',
        ], 'Acronis');
        $mensagem = $this->firstString($alert, [
            'details.description',
            'details.title',
            'message',
            'type',
        ], 'Alerta');
        $origem = $this->firstString($alert, [
            'details.resourceName',
            'details.fields.Path',
            'details.fields.Location',
            'details.fields.Agent location',
            'details.fields.Cluster',
            'details.fields.Host',
            'details.fields.Node',
            'details.fields.VM name',
            'details.fields.Device name',
            'details.machineName',
            'details.agentName',
            'details.deviceName',
            'details.hostName',
            'details.workloadName',
            'resourceName',
            'resource_name',
        ], $maquina);
        $causa = $this->firstString($alert, [
            'details.fields.Error',
            'details.fields.Reason',
            'details.fields.Cause',
            'details.fields.Description',
            'details.errorMessage.context.cause_str',
            'details.errorMessage.debug.msg',
            'details.error.text',
            'details.errorMessage.reason',
            'details.description',
            'details.title',
            'message',
        ], $mensagem);
        $codigo = $this->firstString($alert, [
            'details.fields.Error code',
            'details.fields.Code',
            'details.errorMessage.serCode',
            'details.errorMessage.code',
            'details.error.code',
            'error.code',
            'code',
        ]);
        $recurso = $this->firstString($alert, [
            'details.planName',
            'details.fields.Backup plan',
            'details.fields.Plan',
            'details.fields.Policy',
            'details.fields.Resource',
            'details.fields.Workload',
            'details.fields.Device name',
            'details.machineName',
            'details.agentName',
            'details.deviceName',
            'details.hostName',
            'details.workloadName',
        ], $maquina);
        $workload = $this->findWorkload($workloadMap, $cliente, $maquina);
        if ($workload === []) {
            $workloadIdentifier = $this->firstString($alert, [
                'details.agentId',
                'details.deviceId',
                'details.workloadId',
                'details.resourceId',
                'details.fields.Device ID',
                'entity.id',
            ]);
            if ($workloadIdentifier !== '') {
                $workload = $this->findWorkload($workloadMap, $cliente, $workloadIdentifier);
            }
        }
        if (($maquina === '' || $maquina === 'Recurso') && ($workload['hostname'] ?? '') !== '') {
            $maquina = (string) $workload['hostname'];
        }
        if ($origem === '' || $origem === 'Recurso') {
            $origem = $maquina;
        }
        if ($recurso === '' || $recurso === 'Recurso') {
            $recurso = $maquina;
        }
        $recurso = $this->humanizeAcronisIdentifier($recurso, 'plan');

        $normalized = $this->normalizeAlertTexts($tipo, $codigo, $mensagem, $causa);
        $offlineContext = $this->offlineContextForAlert($normalized['codigo'], $normalized['mensagem'], $normalized['causa'], $workload);
        $mapped = [
            'cliente' => $cliente,
            'maquina' => $maquina,
            'severidade' => $this->firstString($alert, ['severity'], 'unknown'),
            'mensagem' => $normalized['mensagem'],
            'tipo' => $normalized['tipo'],
            'origem' => $origem,
            'causa' => $normalized['causa'],
            'codigo' => $normalized['codigo'],
            'recurso' => $recurso,
            'plano' => $recurso,
            'ip' => (string) ($workload['ip'] ?? ''),
            'dispositivo_offline' => $offlineContext !== '',
            'aviso_offline' => $offlineContext,
            'tamanho' => 'Nao informado pela Acronis',
            'tamanho_bytes' => null,
            'alerta_origem' => 'acronis',
            'data' => $timestamp ? date('Y-m-d', $timestamp) : '',
            'hora' => $timestamp ? date('H:i:s', $timestamp) : '',
            'status' => empty($alert['deletedAt']) && empty($alert['deleted_at']) ? 'open' : 'dismissed',
            'raw' => $alert,
        ];

        return $this->finalizeAlertSizeLabel($this->humanizeAlertIdentifiers($this->enrichAlertFromTasks($mapped, $alert, $taskIndex)));
    }

    private function operationalAlerts(array $tasks, array $workloadMap): array
    {
        $groups = [];
        foreach ($tasks as $task) {
            if (!is_array($task) || !$this->isBackupTask($task)) {
                continue;
            }

            $cliente = $this->firstString($task, ['tenant.name'], 'Cliente nao identificado');
            $maquina = $this->firstString($task, ['resource.name', 'context.Persistent.Name', 'context.MachineName']);
            $plano = $this->firstString($task, ['policy.name', 'context.BackupPlanName'], 'Sem plano');
            $completedAt = $this->firstString($task, ['completedAt', 'updatedAt', 'startedAt']);
            if ($maquina === '' || $completedAt === '' || strtotime($completedAt) === false) {
                continue;
            }

            $key = $this->lookupKey($cliente . '|' . $maquina . '|' . $plano);
            $groups[$key][] = [
                'cliente' => $cliente,
                'maquina' => $maquina,
                'plano' => $plano,
                'completed_at' => $completedAt,
                'timestamp' => strtotime($completedAt),
                'status' => $this->taskStatus($task),
                'bytes' => $this->taskBytes($task),
                'raw' => $task,
            ];
        }

        $alerts = [];
        $minimumTimestamp = strtotime('yesterday 00:00:00');
        foreach ($groups as $items) {
            usort($items, static fn (array $a, array $b): int => $b['timestamp'] <=> $a['timestamp']);
            $latest = $items[0];
            if ($latest['timestamp'] < $minimumTimestamp) {
                continue;
            }

            $baselineData = $this->baselineForExecutionTime($latest, array_slice($items, 1, 20));
            $historicalBaseline = $baselineData['value'];
            $baseline = $baselineData['sample_count'] >= 3 ? $historicalBaseline : null;
            $baselineLabel = $baselineData['label'] !== '' ? ' do horario ' . $baselineData['label'] : '';
            $reason = '';
            $severity = 'warning';
            $code = '';
            if ($latest['status'] === 'failed') {
                $reason = 'A ultima execucao do backup falhou.';
                $severity = 'critical';
                $code = 'BACKUP_FAILED';
            } elseif ($latest['status'] === 'success' && $latest['bytes'] === 0) {
                $reason = 'A execucao foi concluida, mas nao puxou nenhum arquivo.';
                $severity = 'high';
                $code = 'BACKUP_ZERO_SIZE';
            } elseif ($latest['status'] === 'success' && $latest['bytes'] === null && $historicalBaseline !== null) {
                $reason = 'A execucao foi concluida, mas a Acronis nao informou arquivos ou tamanho processado. Padrao historico' . $baselineLabel . ': ' . $this->formatBytes($historicalBaseline) . '.';
                $severity = 'high';
                $code = 'BACKUP_NO_FILES_PROCESSED';
            } elseif (
                is_int($latest['bytes'])
                && $latest['bytes'] > 0
                && $baseline !== null
                && $latest['bytes'] < ($baseline * 0.60)
            ) {
                $reason = 'Tamanho abaixo de 60% do padrao historico' . $baselineLabel . ' (' . $this->formatBytes($baseline) . ').';
                $severity = 'warning';
                $code = 'BACKUP_BELOW_BASELINE';
            }

            if ($reason === '') {
                continue;
            }

            $workload = $this->findWorkload($workloadMap, $latest['cliente'], $latest['maquina']);
            $alerts[] = [
                'cliente' => $latest['cliente'],
                'maquina' => $latest['maquina'],
                'severidade' => $severity,
                'mensagem' => $reason,
                'tipo' => 'Monitoramento de backup',
                'origem' => $latest['maquina'],
                'causa' => $reason,
                'codigo' => $code,
                'recurso' => $latest['plano'],
                'plano' => $latest['plano'],
                'ip' => (string) ($workload['ip'] ?? ''),
                'tamanho' => is_int($latest['bytes'])
                    ? $this->formatBytes($latest['bytes'])
                    : (in_array($code, ['BACKUP_FAILED', 'BACKUP_NO_FILES_PROCESSED'], true) ? 'Nao gerado' : 'Nao informado'),
                'tamanho_bytes' => $latest['bytes'],
                'alerta_origem' => 'monitoramento',
                'data' => date('Y-m-d', $latest['timestamp']),
                'hora' => date('H:i:s', $latest['timestamp']),
                'status' => 'open',
                'raw' => $latest['raw'],
            ];
        }

        return $alerts;
    }

    private function missingBackupAlerts(array $workloads, array $tenantMap, array $tasks, array $nativeAlerts, array $operationalAlerts): array
    {
        $taskMap = $this->latestBackupTaskMap($tasks, $tenantMap);
        $existing = $this->existingMachinePlanAlerts($nativeAlerts, $operationalAlerts);
        $alerts = [];
        $defaultMinimumTimestamp = strtotime('yesterday 00:00:00') ?: (time() - 86400);
        $now = time();

        foreach ($workloads as $workload) {
            if (!is_array($workload) || !$this->isRealDevice($workload)) {
                continue;
            }

            $hostname = $this->firstString($workload, ['name', 'attributes.hostname', 'attributes.host_name']);
            if ($hostname === '') {
                continue;
            }
            $tenantId = $this->firstString($workload, ['tenant_id', 'tenant.id', 'tenant.uuid']);
            $cliente = $tenantMap[$tenantId] ?? $this->firstString($workload, ['tenant.name'], 'Cliente nao identificado');
            $plans = $this->workloadPlans($workload);
            if ($plans === []) {
                continue;
            }

            $offline = $this->workloadIsOffline($workload);
            $lastOnline = $this->firstString($workload, [
                'attributes.agent.last_online',
                'attributes.last_online',
                'status.agent.last_online',
                'last_online',
            ]);
            $ip = $this->firstString($workload, [
                'attributes.agent.ip_addresses.0',
                'attributes.default.IP.0',
                'attributes.ip',
                'attributes.ip_address',
                'attributes.last_ip',
            ]);

            foreach ($plans as $plan) {
                if ($this->isDisabledPlan($plan)) {
                    continue;
                }
                $schedule = $this->backupScheduleStatus($cliente, $hostname, $plan, $now, $defaultMinimumTimestamp);
                if (!$schedule['expected']) {
                    continue;
                }
                $minimumTimestamp = (int) ($schedule['minimum_timestamp'] ?? $defaultMinimumTimestamp);

                $key = $this->lookupKey($cliente . '|' . $hostname . '|' . $plan);
                if (isset($existing[$key])) {
                    continue;
                }

                $latestTimestamp = $taskMap[$key]['timestamp']
                    ?? $taskMap[$this->lookupKey($hostname . '|' . $plan)]['timestamp']
                    ?? $this->workloadLastBackupTimestamp($workload);

                if ($latestTimestamp !== null && $latestTimestamp >= $minimumTimestamp) {
                    continue;
                }

                $missingSince = $latestTimestamp !== null
                    ? date('d/m/Y H:i', $latestTimestamp)
                    : 'sem execucao recente encontrada';
                $offlineNotice = $offline
                    ? $this->offlineNoticeFromWorkload($lastOnline)
                    : '';
                $reason = $offline
                    ? 'Backup esperado nao apareceu no relatorio; dispositivo esta offline na Acronis.'
                    : 'Backup esperado nao apareceu no relatorio; nenhuma execucao recente foi encontrada.';

                $alerts[] = [
                    'cliente' => $cliente,
                    'maquina' => $hostname,
                    'severidade' => $offline ? 'high' : 'warning',
                    'mensagem' => 'Backup esperado nao apareceu no relatorio.',
                    'tipo' => 'Monitoramento de backup',
                    'origem' => $hostname,
                    'causa' => $reason . ' Ultimo backup: ' . $missingSince . '.',
                    'codigo' => $offline ? 'DEVICE_OFFLINE_BACKUP_MISSING' : 'BACKUP_EXPECTED_NOT_RUN',
                    'recurso' => $plan,
                    'plano' => $plan,
                    'ip' => $ip,
                    'dispositivo_offline' => $offline,
                    'aviso_offline' => $offlineNotice,
                    'tamanho' => 'Nao gerado',
                    'tamanho_bytes' => null,
                    'alerta_origem' => 'monitoramento',
                    'data' => date('Y-m-d', $now),
                    'hora' => date('H:i:s', $now),
                    'status' => 'open',
                    'raw' => [
                        'source' => 'workload_missing_backup',
                        'tenant_id' => $tenantId,
                        'hostname' => $hostname,
                        'plan' => $plan,
                        'last_backup_timestamp' => $latestTimestamp,
                    ],
                ];
                $existing[$key] = true;
            }
        }

        return $alerts;
    }

    private function buildTenantMap(array $tenants): array
    {
        $map = [];

        foreach ($tenants as $tenant) {
            if (!is_array($tenant)) {
                continue;
            }

            $name = $this->firstString($tenant, ['name', 'customer_name']);
            if ($name === '') {
                continue;
            }

            foreach (['id', 'uuid', 'tenant_id'] as $field) {
                $value = $this->firstString($tenant, [$field]);
                if ($value !== '') {
                    $map[$value] = $name;
                }
            }
        }

        return $map;
    }

    private function extendTenantMapFromTasks(array $tenantMap, array $tasks): array
    {
        foreach ($tasks as $task) {
            if (!is_array($task)) {
                continue;
            }

            $name = $this->firstString($task, ['tenant.name']);
            if ($name === '') {
                continue;
            }

            foreach (['tenant.id', 'tenant.uuid', 'tenant_id', 'tenantID'] as $path) {
                $value = $this->firstString($task, [$path]);
                if ($value !== '' && !isset($tenantMap[$value])) {
                    $tenantMap[$value] = $name;
                }
            }
        }

        return $tenantMap;
    }

    private function latestBackupTaskMap(array $tasks, array $tenantMap): array
    {
        $map = [];

        foreach ($tasks as $task) {
            if (!is_array($task) || !$this->isBackupTask($task)) {
                continue;
            }

            $timestamp = $this->taskTimestamp($task);
            if ($timestamp === null) {
                continue;
            }

            $cliente = $this->firstString($task, ['tenant.name']);
            if ($cliente === '') {
                foreach (['tenant.id', 'tenant.uuid', 'tenant_id', 'tenantID'] as $path) {
                    $tenantValue = $this->firstString($task, [$path]);
                    if ($tenantValue !== '' && isset($tenantMap[$tenantValue])) {
                        $cliente = $tenantMap[$tenantValue];
                        break;
                    }
                }
            }

            $hostname = $this->firstString($task, ['resource.name', 'context.Persistent.Name', 'context.MachineName']);
            $plan = $this->firstString($task, ['policy.name', 'context.BackupPlanName']);
            if ($hostname === '' || $plan === '') {
                continue;
            }

            foreach ([
                $this->lookupKey($cliente . '|' . $hostname . '|' . $plan),
                $this->lookupKey($hostname . '|' . $plan),
            ] as $key) {
                if ($key === '') {
                    continue;
                }

                if (!isset($map[$key]) || (($map[$key]['timestamp'] ?? 0) < $timestamp)) {
                    $map[$key] = [
                        'timestamp' => $timestamp,
                        'task' => $task,
                    ];
                }
            }
        }

        return $map;
    }

    private function existingMachinePlanAlerts(array ...$alertGroups): array
    {
        $map = [];
        foreach ($alertGroups as $alerts) {
            foreach ($alerts as $alert) {
                if (!is_array($alert)) {
                    continue;
                }

                $cliente = (string) ($alert['cliente'] ?? '');
                $maquina = (string) ($alert['maquina'] ?? '');
                $plano = (string) ($alert['plano'] ?? $alert['recurso'] ?? '');
                if ($maquina === '' || $plano === '') {
                    continue;
                }

                $map[$this->lookupKey($cliente . '|' . $maquina . '|' . $plano)] = true;
                $map[$this->lookupKey($maquina . '|' . $plano)] = true;
            }
        }

        return $map;
    }

    private function workloadPlans(array $workload): array
    {
        $source = $this->firstString($workload, [
            'cross_policy_status.names',
            'attributes.plan_name',
            'attributes.protection_plan_name',
            'status.plan.name',
        ]);
        $plans = array_values(array_filter(array_map('trim', preg_split('/[;,]+/', $source) ?: [])));

        return array_values(array_filter(array_unique($plans), static fn (string $plan): bool => $plan !== '' && $plan !== 'Sem plano'));
    }

    private function ignoreMissingBackupPlan(string $plan): bool
    {
        $normalized = $this->lookupKey($plan);
        if ($normalized === '') {
            return true;
        }

        return str_contains($normalized, 'dados')
            || str_contains($normalized, 'desligar')
            || str_contains($normalized, 'desligado')
            || str_contains($normalized, 'disabled')
            || str_contains($normalized, 'naoexecutar')
            || str_contains($normalized, 'naorodar');
    }

    private function shouldHideAlertByPlan(array $alert): bool
    {
        foreach (['plano', 'recurso'] as $field) {
            $value = trim((string) ($alert[$field] ?? ''));
            if ($value !== '' && $this->isDisabledPlan($value)) {
                return true;
            }
        }

        return false;
    }

    private function ignoreMissingBackupDevice(string $hostname): bool
    {
        $ignoredDevices = [
            'tintamazacm',
        ];

        return in_array($this->lookupKey($hostname), $ignoredDevices, true);
    }

    private function isIgnoredAlertMachine(string $hostname): bool
    {
        return str_contains($this->lookupKey($hostname), 'fileserver');
    }

    private function backupScheduleStatus(string $cliente, string $hostname, string $plan, int $now, int $defaultMinimumTimestamp): array
    {
        $rules = array_values(array_filter(
            $this->windowRules(),
            fn (array $rule): bool => $this->windowRuleMatches($rule, $cliente, $hostname, $plan)
        ));

        if ($rules === []) {
            return [
                'expected' => true,
                'minimum_timestamp' => $defaultMinimumTimestamp,
            ];
        }

        $minimumTimestamp = null;
        foreach ($rules as $rule) {
            $ruleMinimumTimestamp = $this->latestDueWindowMinimumTimestamp($rule, $now);
            if ($ruleMinimumTimestamp !== null) {
                $minimumTimestamp = max($minimumTimestamp ?? $ruleMinimumTimestamp, $ruleMinimumTimestamp);
            }
        }

        if ($minimumTimestamp !== null) {
            return [
                'expected' => true,
                'minimum_timestamp' => $minimumTimestamp,
            ];
        }

        return [
            'expected' => false,
            'minimum_timestamp' => $defaultMinimumTimestamp,
        ];
    }

    private function backupWasExpectedBySchedule(string $cliente, string $hostname, string $plan, int $now): bool
    {
        return $this->backupScheduleStatus($cliente, $hostname, $plan, $now, strtotime('yesterday 00:00:00') ?: ($now - 86400))['expected'];
    }

    private function windowRules(): array
    {
        if ($this->windowRulesCache !== null) {
            return $this->windowRulesCache;
        }

        $config = [];
        $jsonPath = dirname(__DIR__, 2) . '/storage/config/backup_windows.json';
        if (is_readable($jsonPath)) {
            try {
                $decoded = json_decode((string) file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);
                $config = is_array($decoded) ? $decoded : [];
            } catch (\Throwable $e) {
                error_log('Configuracao JSON de janelas invalida para alertas: ' . $e->getMessage());
            }
        }

        if ($config === []) {
            $fallbackPath = dirname(__DIR__) . '/Config/backup_windows.php';
            $fallback = is_file($fallbackPath) ? (require $fallbackPath) : [];
            $config = is_array($fallback) ? $fallback : [];
        }

        $rules = is_array($config['rules'] ?? null) ? $config['rules'] : [];
        $this->windowRulesCache = array_values(array_filter(array_map(function (mixed $rule): ?array {
            if (!is_array($rule)) {
                return null;
            }

            $empresa = trim((string) ($rule['empresa'] ?? ''));
            $plano = trim((string) ($rule['plano'] ?? ''));
            if ($empresa === '' || $plano === '') {
                return null;
            }

            $inicio = $this->normalizeWindowHour((string) ($rule['inicio'] ?? '00:00'));
            $fim = $this->normalizeWindowHour((string) ($rule['fim'] ?? '23:59'));
            $dias = is_array($rule['dias_semana'] ?? null)
                ? array_values(array_filter(array_map('intval', $rule['dias_semana']), static fn (int $day): bool => $day >= 0 && $day <= 6))
                : [];

            return [
                'empresa_keys' => $this->lookupKeyList(array_merge([$empresa], is_array($rule['empresa_aliases'] ?? null) ? $rule['empresa_aliases'] : [])),
                'plano_keys' => $this->lookupKeyList(array_merge([$plano], is_array($rule['plano_aliases'] ?? null) ? $rule['plano_aliases'] : [])),
                'maquina_key' => $this->lookupKey(trim((string) ($rule['maquina'] ?? ''))),
                'inicio' => $inicio,
                'fim' => $fim,
                'intervalo_horas' => max(0, (int) ($rule['intervalo_horas'] ?? 0)),
                'meta' => max(0, (int) ($rule['meta'] ?? 0)),
                'dias_semana' => array_values(array_unique($dias)),
            ];
        }, $rules)));

        return $this->windowRulesCache;
    }

    private function windowRuleMatches(array $rule, string $cliente, string $hostname, string $plan): bool
    {
        if (!in_array($this->lookupKey($cliente), $rule['empresa_keys'] ?? [], true)) {
            return false;
        }

        if (!in_array($this->lookupKey($plan), $rule['plano_keys'] ?? [], true)) {
            return false;
        }

        $machineKey = (string) ($rule['maquina_key'] ?? '');
        return $machineKey === '' || $machineKey === $this->lookupKey($hostname);
    }

    private function latestDueWindowMinimumTimestamp(array $rule, int $now): ?int
    {
        $localNow = (new DateTimeImmutable('@' . $now))->setTimezone($this->scheduleTimezone());
        $days = is_array($rule['dias_semana'] ?? null) ? array_map('intval', $rule['dias_semana']) : [];
        if ($days !== [] && !in_array((int) $localNow->format('w'), $days, true)) {
            return null;
        }

        $endMinutes = $this->windowMinutes((string) ($rule['fim'] ?? '23:59'));
        $startMinutes = $this->windowMinutes((string) ($rule['inicio'] ?? '00:00'));
        $nowMinutes = ((int) $localNow->format('G') * 60) + (int) $localNow->format('i');
        $intervalHours = max(0, (int) ($rule['intervalo_horas'] ?? 0));
        $meta = max(0, (int) ($rule['meta'] ?? 0));

        $dueMinutes = [];
        if ($meta === 1 || $startMinutes === $endMinutes) {
            $dueMinutes[] = $startMinutes;
        } elseif ($intervalHours > 0 && $endMinutes >= $startMinutes) {
            $step = $intervalHours * 60;
            for ($minutes = $startMinutes; $minutes <= $endMinutes; $minutes += $step) {
                $dueMinutes[] = $minutes;
            }
        } else {
            $dueMinutes[] = $endMinutes;
        }

        $latestDueMinute = null;
        foreach ($dueMinutes as $minutes) {
            if ($nowMinutes >= min(1439, $minutes + 35)) {
                $latestDueMinute = max($latestDueMinute ?? $minutes, $minutes);
            }
        }

        if ($latestDueMinute === null) {
            return null;
        }

        $dayStart = $localNow->setTime(0, 0);
        $minimum = $dayStart->modify('+' . max(0, $latestDueMinute - 35) . ' minutes');
        return $minimum->getTimestamp();
    }

    private function scheduleTimezone(): DateTimeZone
    {
        if ($this->timezoneCache !== null) {
            return $this->timezoneCache;
        }

        $timezone = 'America/Sao_Paulo';
        $jsonPath = dirname(__DIR__, 2) . '/storage/config/backup_windows.json';
        if (is_readable($jsonPath)) {
            try {
                $decoded = json_decode((string) file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded) && trim((string) ($decoded['timezone'] ?? '')) !== '') {
                    $timezone = trim((string) $decoded['timezone']);
                }
            } catch (\Throwable) {
                $timezone = 'America/Sao_Paulo';
            }
        }

        $this->timezoneCache = new DateTimeZone($timezone);
        return $this->timezoneCache;
    }

    private function normalizeWindowHour(string $value): string
    {
        if (!preg_match('/^\d{1,2}:\d{2}$/', $value)) {
            return '00:00';
        }

        [$hour, $minute] = array_map('intval', explode(':', $value));
        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            return '00:00';
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    private function windowMinutes(string $hour): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $this->normalizeWindowHour($hour)));
        return ($hours * 60) + $minutes;
    }

    private function lookupKeyList(array $values): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn (mixed $value): string => $this->lookupKey((string) $value), $values),
            static fn (string $value): bool => $value !== ''
        )));
    }

    private function workloadLastBackupTimestamp(array $workload): ?int
    {
        foreach ([
            'per_policy_type_statuses.0.last_success_run_time',
            'attributes.last_successful_backup',
            'attributes.last_backup',
            'status.last_success_run_time',
        ] as $path) {
            $value = $this->firstString($workload, [$path]);
            $timestamp = $value !== '' ? strtotime($value) : false;
            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        return null;
    }

    private function taskTimestamp(array $task): ?int
    {
        foreach (['completedAt', 'updatedAt', 'startedAt'] as $path) {
            $value = $this->firstString($task, [$path]);
            $timestamp = $value !== '' ? strtotime($value) : false;
            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        return null;
    }

    private function offlineNoticeFromWorkload(string $lastOnline): string
    {
        $lastOnline = trim($lastOnline);
        if ($lastOnline === '') {
            return 'Possivel fator: dispositivo offline na Acronis.';
        }

        $timestamp = strtotime($lastOnline);
        $label = $timestamp !== false ? date('d/m/Y H:i', $timestamp) : $lastOnline;
        return 'Possivel fator: dispositivo offline na Acronis. Ultima comunicacao: ' . $label . '.';
    }

    private function buildWorkloadMap(array $workloads, array $tenantMap): array
    {
        $map = [];
        foreach ($workloads as $workload) {
            if (!is_array($workload)) {
                continue;
            }

            $hostname = $this->firstString($workload, ['name', 'attributes.hostname', 'attributes.host_name']);
            if ($hostname === '') {
                continue;
            }

            $tenantId = $this->firstString($workload, ['tenant_id', 'tenant.id', 'tenant.uuid']);
            $cliente = $tenantMap[$tenantId] ?? '';
            $item = [
                'cliente' => $cliente,
                'hostname' => $hostname,
                'offline' => $this->workloadIsOffline($workload),
                'last_online' => $this->firstString($workload, [
                    'attributes.agent.last_online',
                    'attributes.last_online',
                    'status.agent.last_online',
                    'last_online',
                ]),
                'ip' => $this->firstString($workload, [
                    'attributes.agent.ip_addresses.0',
                    'attributes.default.IP.0',
                'attributes.ip',
                'attributes.ip_address',
                'attributes.last_ip',
            ]),
            ];
            $map[$this->lookupKey($cliente . '|' . $hostname)] = $item;
            $map[$this->lookupKey($hostname)] = $item;
            foreach (['id', 'uuid', 'resource_id', 'instance_id', 'agent_id', 'attributes.agent.id', 'attributes.agent_id'] as $path) {
                $identifier = $this->firstString($workload, [$path]);
                if ($identifier !== '') {
                    $map[$this->lookupKey($identifier)] = $item;
                }
            }
        }

        return $map;
    }

    private function buildTaskIndex(array $tasks, array $tenantMap): array
    {
        $index = [];
        foreach ($tasks as $task) {
            if (!is_array($task) || !$this->isBackupTask($task)) {
                continue;
            }

            $completedAt = $this->firstString($task, ['completedAt', 'updatedAt', 'startedAt']);
            $timestamp = $completedAt !== '' ? strtotime($completedAt) : false;
            if ($timestamp === false) {
                continue;
            }

            $cliente = $this->firstString($task, ['tenant.name']);
            if ($cliente === '') {
                foreach (['tenant.uuid', 'tenant.id', 'tenantID', 'tenant_id'] as $path) {
                    $tenantValue = $this->firstString($task, [$path]);
                    if ($tenantValue !== '' && isset($tenantMap[$tenantValue])) {
                        $cliente = $tenantMap[$tenantValue];
                        break;
                    }
                }
            }

            $keys = array_filter([
                $this->lookupKey($cliente . '|' . $this->firstString($task, ['context.ProtectionPlanID', 'policy.id']) . '|' . $this->firstString($task, ['resource.id', 'context.resourceId', 'context.Persistent.ID'])),
                $this->lookupKey($cliente . '|' . $this->firstString($task, ['context.ProtectionPlanID', 'policy.id']) . '|' . $this->firstString($task, ['resource.name', 'context.Persistent.Name', 'context.MachineName'])),
                $this->lookupKey($cliente . '|' . $this->firstString($task, ['context.BackupPlanName', 'policy.name']) . '|' . $this->firstString($task, ['resource.name', 'context.Persistent.Name', 'context.MachineName'])),
                $this->lookupKey($cliente . '|' . $this->firstString($task, ['policy.id']) . '|' . $this->firstString($task, ['resource.id'])),
                $this->lookupKey($this->firstString($task, ['context.ProtectionPlanID', 'policy.id']) . '|' . $this->firstString($task, ['resource.id', 'context.resourceId', 'context.Persistent.ID'])),
                $this->lookupKey($this->firstString($task, ['context.ProtectionPlanID', 'policy.id']) . '|' . $this->firstString($task, ['resource.name', 'context.Persistent.Name', 'context.MachineName'])),
                $this->lookupKey($this->firstString($task, ['context.BackupPlanName', 'policy.name']) . '|' . $this->firstString($task, ['resource.name', 'context.Persistent.Name', 'context.MachineName'])),
                $this->lookupKey($this->firstString($task, ['policy.id']) . '|' . $this->firstString($task, ['resource.id'])),
            ]);

            foreach ($keys as $key) {
                if ($key === '') {
                    continue;
                }

                if (!isset($index[$key]) || (($index[$key]['timestamp'] ?? 0) < $timestamp)) {
                    $index[$key] = [
                        'timestamp' => $timestamp,
                        'task' => $task,
                    ];
                }
            }
        }

        return $index;
    }

    private function findWorkload(array $workloadMap, string $cliente, string $maquina): array
    {
        return $workloadMap[$this->lookupKey($cliente . '|' . $maquina)]
            ?? $workloadMap[$this->lookupKey($maquina)]
            ?? [];
    }

    private function enrichAlertFromTasks(array $mapped, array $alert, array $taskIndex): array
    {
        if ($taskIndex === []) {
            return $mapped;
        }

        $cliente = (string) ($mapped['cliente'] ?? '');
        $planId = $this->firstString($alert, ['details.planId', 'details.planID']);
        $resourceId = $this->firstString($alert, ['details.resourceId', 'details.deviceId', 'details.agentId', 'entity.id']);
        $planName = $this->firstString($alert, ['details.planName', 'details.fields.Backup plan', 'details.fields.Plan'], (string) ($mapped['plano'] ?? ''));
        $resourceName = $this->firstString($alert, ['details.resourceName', 'resourceName', 'entity.name'], (string) ($mapped['maquina'] ?? ''));

        $candidates = array_filter([
            $this->lookupKey($cliente . '|' . $planId . '|' . $resourceId),
            $this->lookupKey($cliente . '|' . $planName . '|' . $resourceName),
            $this->lookupKey($cliente . '|' . $planId . '|' . $resourceName),
            $this->lookupKey($cliente . '|' . $planName . '|' . $resourceId),
            $this->lookupKey($planId . '|' . $resourceId),
            $this->lookupKey($planName . '|' . $resourceName),
            $this->lookupKey($planId . '|' . $resourceName),
            $this->lookupKey($planName . '|' . $resourceId),
        ]);

        $task = null;
        foreach ($candidates as $candidate) {
            if (isset($taskIndex[$candidate]['task']) && is_array($taskIndex[$candidate]['task'])) {
                $task = $taskIndex[$candidate]['task'];
                break;
            }
        }

        if (!is_array($task)) {
            return $mapped;
        }

        $bytes = $this->taskBytes($task);
        if (($mapped['tamanho'] ?? '') === 'Nao informado pela Acronis' && is_int($bytes)) {
            $mapped['tamanho_bytes'] = $bytes;
            $mapped['tamanho'] = $bytes > 0 ? $this->formatBytes($bytes) : '0 B';
        }

        $rawReason = $this->firstString($task, [
            'result.payload.error.localizedMessage',
            'result.payload.error.message',
            'result.payload.error.debug.msg',
            'result.payload.error.reason',
            'result.payload.error.cause',
            'result.payload.error.context.cause_str',
            'result.payload.UnresolvedItemsWarning.0.message',
            'result.payload.UnresolvedItemsWarning.0',
            'result.error.localizedMessage',
            'result.error.message',
            'result.error.debug.msg',
            'result.error.reason',
            'result.error.cause',
            'context.Result',
        ]);

        if ($rawReason !== '') {
            $humanReason = $this->humanizeAlertText($rawReason, (string) ($mapped['codigo'] ?? ''), (string) ($mapped['causa'] ?? ''));
            if ($humanReason !== '') {
                $currentCause = trim((string) ($mapped['causa'] ?? ''));
                if ($currentCause === '' || $currentCause === (string) ($mapped['mensagem'] ?? '')) {
                    $mapped['causa'] = $humanReason;
                } elseif (!str_contains(mb_strtolower($currentCause), mb_strtolower($humanReason))) {
                    $mapped['causa'] = $currentCause . ' - ' . $humanReason;
                }
            }
        }

        return $mapped;
    }

    private function offlineContextForAlert(string $codigo, string $mensagem, string $causa, array $workload): string
    {
        if (!$this->isBackupStatusUnknownAlert($codigo, $mensagem, $causa) || empty($workload['offline'])) {
            return '';
        }

        $lastOnline = trim((string) ($workload['last_online'] ?? ''));
        if ($lastOnline !== '') {
            $timestamp = strtotime($lastOnline);
            $lastOnlineLabel = $timestamp !== false ? date('d/m/Y H:i', $timestamp) : $lastOnline;
            return 'Possivel fator: dispositivo offline na Acronis. Ultima comunicacao: ' . $lastOnlineLabel . '.';
        }

        return 'Possivel fator: dispositivo offline na Acronis.';
    }

    private function isBackupStatusUnknownAlert(string $codigo, string $mensagem, string $causa): bool
    {
        $haystack = mb_strtolower($codigo . ' ' . $mensagem . ' ' . $causa);
        return str_contains($haystack, 'backupstatusunknown')
            || str_contains($haystack, 'status do backup desconhecido');
    }

    private function workloadIsOffline(array $workload): bool
    {
        foreach ([
            'attributes.agent.online',
            'attributes.online',
            'status.agent.online',
            'agent.online',
            'online',
        ] as $path) {
            $value = $this->value($workload, $path);
            if (is_bool($value)) {
                return $value === false;
            }
            if (is_scalar($value)) {
                $normalized = mb_strtolower(trim((string) $value));
                if (in_array($normalized, ['false', '0', 'no', 'offline'], true)) {
                    return true;
                }
                if (in_array($normalized, ['true', '1', 'yes', 'online'], true)) {
                    return false;
                }
            }
        }

        $statusText = mb_strtolower(json_encode([
            $this->firstString($workload, ['status.overall']),
            $this->firstString($workload, ['status.protection']),
            $this->firstString($workload, ['status.agent.status']),
            $this->firstString($workload, ['attributes.agent.status']),
            $this->firstString($workload, ['connection_status']),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

        return str_contains($statusText, 'offline')
            || str_contains($statusText, 'disconnected');
    }

    private function finalizeAlertSizeLabel(array $mapped): array
    {
        if (($mapped['tamanho'] ?? '') !== 'Nao informado pela Acronis') {
            return $mapped;
        }

        $code = trim((string) ($mapped['codigo'] ?? ''));
        $cause = mb_strtolower(trim((string) ($mapped['causa'] ?? '')));
        $type = mb_strtolower(trim((string) ($mapped['tipo'] ?? '')));

        if (in_array($code, ['AgentAutoUpdateFailed', 'M365ApplicationConsentRequired'], true)) {
            $mapped['tamanho'] = 'Nao se aplica';
            return $mapped;
        }

        if (
            str_contains($cause, 'nenhuma origem de backup')
            || str_contains($cause, 'no sources found for planid')
            || str_contains($cause, 'failed to find archive')
        ) {
            $mapped['tamanho'] = 'Nao gerado';
            return $mapped;
        }

        if (str_contains($type, 'atualizacao') || str_contains($type, 'microsoft 365')) {
            $mapped['tamanho'] = 'Nao se aplica';
        }

        return $mapped;
    }

    private function taskLookbackDaysForAlerts(array $alerts): int
    {
        $oldestTimestamp = time();

        foreach ($alerts as $alert) {
            if (!is_array($alert)) {
                continue;
            }

            $createdAt = $this->firstString($alert, ['createdAt', 'created_at', 'receivedAt', 'updatedAt']);
            $timestamp = $createdAt !== '' ? strtotime($createdAt) : false;
            if ($timestamp !== false && $timestamp < $oldestTimestamp) {
                $oldestTimestamp = $timestamp;
            }
        }

        $days = (int) ceil(max(0, time() - $oldestTimestamp) / 86400) + 7;
        return max(30, min(365, $days));
    }

    private function normalizeAlertTexts(string $tipo, string $codigo, string $mensagem, string $causa): array
    {
        $normalizedCode = trim($codigo) !== '' ? trim($codigo) : trim($tipo);
        $translatedType = $this->translateAlertCode($tipo);

        return [
            'tipo' => $translatedType,
            'codigo' => $normalizedCode,
            'mensagem' => $this->humanizeAlertText($mensagem, $normalizedCode, $translatedType),
            'causa' => $this->humanizeAlertText($causa, $normalizedCode, $translatedType),
        ];
    }

    private function humanizeAlertText(string $text, string $fallbackCode = '', string $fallbackText = ''): string
    {
        $text = trim($text);
        if ($text === '') {
            $fallback = $this->translateAlertCode($fallbackCode);
            return $fallback !== '' ? $fallback : $fallbackText;
        }

        $jsonText = $this->translateJsonAlertPayload($text);
        if ($jsonText !== null) {
            return $jsonText;
        }

        $translated = $this->translateAlertCode($text);
        if ($translated !== $text) {
            return $translated;
        }

        if (preg_match('/^No sources found for planID=([A-F0-9-]+)$/i', $text)) {
            return 'Nenhuma origem de backup foi encontrada para o plano configurado.';
        }

        $humanIdentifier = $this->humanizeAcronisIdentifier($text);
        if ($humanIdentifier !== $text) {
            return $humanIdentifier;
        }

        return $text;
    }

    private function humanizeAlertIdentifiers(array $alert): array
    {
        foreach (['recurso', 'plano'] as $field) {
            if (isset($alert[$field])) {
                $alert[$field] = $this->humanizeAcronisIdentifier((string) $alert[$field], 'plan');
            }
        }

        foreach (['mensagem', 'causa', 'tipo'] as $field) {
            if (isset($alert[$field])) {
                $alert[$field] = $this->humanizeAcronisIdentifier((string) $alert[$field]);
            }
        }

        return $alert;
    }

    private function humanizeAcronisIdentifier(string $text, string $context = ''): string
    {
        $value = trim($text);
        if (!preg_match('/^id=([A-F0-9-]{20,})$/i', $value, $matches)) {
            return $text;
        }

        $id = strtoupper($matches[1]);
        return match ($context) {
            'plan' => 'Plano de backup sem nome na Acronis (ID interno: ' . $id . ')',
            'device' => 'Dispositivo sem nome na Acronis (ID interno: ' . $id . ')',
            default => 'ID interno da Acronis: ' . $id,
        };
    }

    private function translateJsonAlertPayload(string $text): ?string
    {
        if (!str_starts_with($text, '{')) {
            return null;
        }

        try {
            $payload = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($payload)) {
            return null;
        }

        $code = trim((string) ($payload['code'] ?? ''));
        $address = trim((string) ($payload['context']['address'] ?? ''));
        $debug = trim((string) ($payload['debug']['msg'] ?? ''));

        if ($code === 'NETWORK_ERROR') {
            $parts = ['Falha de comunicacao com a nuvem Acronis'];
            if ($address !== '') {
                $parts[] = 'destino ' . $address;
            }

            $message = implode(' - ', $parts);
            if ($debug !== '') {
                if (str_contains(strtolower($debug), 'i/o timeout')) {
                    $message .= '. Tempo limite de conexao esgotado.';
                } else {
                    $message .= '. ' . $debug;
                }
            } else {
                $message .= '.';
            }

            return $message;
        }

        return $code !== '' ? $this->translateAlertCode($code) : null;
    }

    private function translateAlertCode(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        $translations = [
            'AgentAutoUpdateFailed' => 'Falha na atualizacao do agente',
            'PlanDeploymentFailed' => 'Falha ao aplicar o plano de backup',
            'BackupDidNotStart' => 'Backup nao iniciado',
            'BackupNotResponding' => 'Backup sem resposta',
            'BackupStatusUnknown' => 'Status do backup desconhecido',
            'MachineOffline30' => 'Maquina offline ha mais de 30 minutos',
            'M365ApplicationConsentRequired' => 'Microsoft 365 sem consentimento do aplicativo',
            'NETWORK_ERROR' => 'Falha de comunicacao com a nuvem Acronis',
            'BACKUP_FAILED' => 'Falha na execucao do backup',
            'BACKUP_ZERO_SIZE' => 'Backup concluido com tamanho zerado',
            'BACKUP_NO_FILES_PROCESSED' => 'Backup concluido sem arquivos processados',
            'BACKUP_BELOW_BASELINE' => 'Backup com tamanho abaixo do padrao historico',
            'BACKUP_EXPECTED_NOT_RUN' => 'Backup esperado nao apareceu no relatorio',
            'DEVICE_OFFLINE_BACKUP_MISSING' => 'Backup ausente com dispositivo offline',
        ];

        return $translations[$trimmed] ?? $trimmed;
    }

    private function isDataPlan(string $plan): bool
    {
        return (bool) preg_match('/\bdados\b/i', $plan);
    }

    private function isDisabledPlan(string $plan): bool
    {
        return (bool) preg_match('/\bdisabled\b/i', $plan);
    }

    private function lookupKey(string $value): string
    {
        $text = trim(mb_strtolower($value));
        $text = preg_replace('/\s*\([^)]*\)/u', '', $text) ?? $text;
        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
        return preg_replace('/[^a-z0-9]+/i', '', $text) ?? $text;
    }

    private function isBackupTask(array $task): bool
    {
        if (($task['policy']['type'] ?? '') === 'backup') {
            return true;
        }

        if ($this->firstString($task, ['context.BackupPlanName']) !== '') {
            return true;
        }

        return str_starts_with(strtolower($this->firstString($task, ['context.title'])), 'backup plan');
    }

    private function taskStatus(array $task): string
    {
        $status = strtolower($this->firstString($task, ['result.code', 'state'], 'unknown'));
        return match ($status) {
            'success', 'successful', 'completed', 'ok' => 'success',
            'error', 'failed', 'fail' => 'failed',
            default => $status,
        };
    }

    private function taskBytes(array $task): ?int
    {
        foreach ([
            $task['progress']['bytesProcessed'] ?? null,
            $task['context']['_runtime']['bytesProcessed'] ?? null,
            $task['progress']['bytesSaved'] ?? null,
            $task['context']['_runtime']['bytesSaved'] ?? null,
        ] as $value) {
            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * Finds a historical reference for the same recurring execution time.
     * Different schedules are kept apart, but equivalent schedules may share
     * a baseline after their historical medians prove consistent.
     *
     * @return array{value:?int, sample_count:int, label:string}
     */
    private function baselineForExecutionTime(array $latest, array $previous): array
    {
        $valid = array_values(array_filter($previous, static function (array $item): bool {
            return ($item['status'] ?? '') === 'success'
                && is_int($item['bytes'] ?? null)
                && $item['bytes'] > 0
                && is_int($item['time_minutes'] ?? null);
        }));

        $latestMinutes = $latest['time_minutes'] ?? null;
        if (!is_int($latestMinutes) || $valid === []) {
            return ['value' => null, 'sample_count' => 0, 'label' => ''];
        }

        // Backups can finish a little before/after their configured time.
        $sameTime = array_values(array_filter(
            $valid,
            fn (array $item): bool => $this->circularMinuteDistance($latestMinutes, $item['time_minutes']) <= 90
        ));

        if (count($sameTime) >= 3) {
            return [
                'value' => $this->median(array_column($sameTime, 'bytes')),
                'sample_count' => count($sameTime),
                'label' => $this->formatExecutionTime($latestMinutes),
            ];
        }

        // With too few samples in the slot, only pool different slots when
        // their medians are demonstrably equivalent. This avoids hiding a
        // real 12:00-vs-18:00 difference while supporting uniform schedules.
        $clusters = $this->timeClusters($valid);
        $clusterMedians = [];
        foreach ($clusters as $cluster) {
            if (count($cluster) < 2) {
                return ['value' => null, 'sample_count' => 0, 'label' => $this->formatExecutionTime($latestMinutes)];
            }
            $clusterMedians[] = $this->median(array_column($cluster, 'bytes'));
        }

        if (count($clusterMedians) > 1) {
            $minimum = min($clusterMedians);
            $maximum = max($clusterMedians);
            if ($minimum <= 0 || $maximum > ($minimum * 1.20)) {
                return ['value' => null, 'sample_count' => 0, 'label' => $this->formatExecutionTime($latestMinutes)];
            }
        }

        return [
            'value' => count($valid) >= 3 ? $this->median(array_column($valid, 'bytes')) : null,
            'sample_count' => count($valid),
            'label' => $this->formatExecutionTime($latestMinutes),
        ];
    }

    /** @return list<list<array>> */
    private function timeClusters(array $items): array
    {
        usort($items, static fn (array $a, array $b): int => $a['time_minutes'] <=> $b['time_minutes']);
        $clusters = [];
        foreach ($items as $item) {
            $last = count($clusters) - 1;
            if ($last < 0 || $item['time_minutes'] - $clusters[$last][count($clusters[$last]) - 1]['time_minutes'] > 90) {
                $clusters[] = [$item];
                continue;
            }
            $clusters[$last][] = $item;
        }

        // Treat 23:xx and 00:xx as one recurring slot across midnight.
        if (count($clusters) > 1
            && $clusters[0][0]['time_minutes'] <= 90
            && $clusters[count($clusters) - 1][count($clusters[count($clusters) - 1]) - 1]['time_minutes'] >= 1350) {
            $first = array_shift($clusters);
            $clusters[count($clusters) - 1] = array_merge($clusters[count($clusters) - 1], $first);
        }

        return $clusters;
    }

    private function circularMinuteDistance(int $left, int $right): int
    {
        $difference = abs($left - $right);
        return min($difference, 1440 - $difference);
    }

    private function executionTimeMinutes(string $completedAt): ?int
    {
        try {
            $date = new DateTimeImmutable($completedAt);
            $local = $date->setTimezone($this->scheduleTimezone());
            return ((int) $local->format('G') * 60) + (int) $local->format('i');
        } catch (\Throwable) {
            return null;
        }
    }

    private function formatExecutionTime(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    private function median(array $values): int
    {
        sort($values, SORT_NUMERIC);
        $count = count($values);
        $middle = intdiv($count, 2);
        return $count % 2 === 1
            ? (int) $values[$middle]
            : (int) round(($values[$middle - 1] + $values[$middle]) / 2);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $value = (float) $bytes;
        $index = 0;
        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        return number_format($value, $index >= 3 ? 1 : 0, ',', '.') . ' ' . $units[$index];
    }

    private function resolveClientName(array $alert, array $tenantMap): string
    {
        $direct = $this->firstString($alert, ['tenant.name']);
        if ($direct !== '') {
            return $direct;
        }

        foreach (['tenant.uuid', 'tenant.id', 'tenantID', 'tenant_id'] as $path) {
            $value = $this->firstString($alert, [$path]);
            if ($value !== '' && isset($tenantMap[$value])) {
                return $tenantMap[$value];
            }
        }

        return $this->firstString($alert, ['tenant.uuid', 'tenant.id', 'tenantID', 'tenant_id'], 'Cliente');
    }
}
