<?php

declare(strict_types=1);

namespace NyxCloud\Services;

final class AlertService extends AbstractAcronisService
{
    private const CACHE_VERSION = 'v6';

    public function listAlerts(array $filters = []): array
    {
        return $this->remember('acronis.alerts.' . self::CACHE_VERSION . '.' . md5(json_encode($filters)), (int) $this->config['cache_ttl']['alerts'], function () use ($filters): array {
            $tenants = $this->items($this->api->get($this->endpoint('tenants'), $this->tenantScopeFilters()));
            $tenantMap = $this->buildTenantMap($tenants);
            $workloads = $this->items($this->api->get($this->endpoint('workloads'), [
                'include_all_attributes' => 'true',
                'limit' => 500,
            ]));
            $workloadMap = $this->buildWorkloadMap($workloads, $tenantMap);
            $payload = $this->api->get($this->endpoint('alerts'), array_merge([
                'order' => 'desc(created_at)',
                'limit' => 100,
            ], $filters));

            $native = array_map(
                fn (array $alert): array => $this->mapAlert($alert, $tenantMap, $workloadMap),
                $this->items($payload)
            );

            try {
                $operational = $this->operationalAlerts($this->taskItems(30), $workloadMap);
            } catch (\Throwable $e) {
                error_log('Acronis: alertas operacionais indisponiveis: ' . $e->getMessage());
                $operational = [];
            }

            $alerts = array_merge($operational, $native);
            usort($alerts, static fn (array $a, array $b): int => strcmp(
                (string) ($b['data'] ?? '') . (string) ($b['hora'] ?? ''),
                (string) ($a['data'] ?? '') . (string) ($a['hora'] ?? '')
            ));

            return $alerts;
        });
    }

    private function mapAlert(array $alert, array $tenantMap, array $workloadMap): array
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

        return [
            'cliente' => $cliente,
            'maquina' => $maquina,
            'severidade' => $this->firstString($alert, ['severity'], 'unknown'),
            'mensagem' => $mensagem,
            'tipo' => $tipo,
            'origem' => $origem,
            'causa' => $causa,
            'codigo' => $codigo,
            'recurso' => $recurso,
            'plano' => $recurso,
            'ip' => (string) ($workload['ip'] ?? ''),
            'tamanho' => 'Nao informado pela Acronis',
            'tamanho_bytes' => null,
            'alerta_origem' => 'acronis',
            'data' => $timestamp ? date('Y-m-d', $timestamp) : '',
            'hora' => $timestamp ? date('H:i:s', $timestamp) : '',
            'status' => empty($alert['deletedAt']) && empty($alert['deleted_at']) ? 'open' : 'dismissed',
            'raw' => $alert,
        ];
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

            $previousSizes = [];
            foreach (array_slice($items, 1, 20) as $previous) {
                if ($previous['status'] === 'success' && is_int($previous['bytes']) && $previous['bytes'] > 0) {
                    $previousSizes[] = $previous['bytes'];
                }
            }

            $baseline = count($previousSizes) >= 3 ? $this->median($previousSizes) : null;
            $reason = '';
            $severity = 'warning';
            $code = '';

            if ($latest['status'] === 'failed') {
                $reason = 'A ultima execucao do backup falhou.';
                $severity = 'critical';
                $code = 'BACKUP_FAILED';
            } elseif ($latest['bytes'] === 0) {
                $reason = 'A execucao foi concluida com tamanho zerado.';
                $severity = 'high';
                $code = 'BACKUP_ZERO_SIZE';
            } elseif (is_int($latest['bytes']) && $latest['bytes'] > 0 && $baseline !== null && $latest['bytes'] < ($baseline * 0.60)) {
                $reason = 'Tamanho abaixo de 60% do padrao historico (' . $this->formatBytes($baseline) . ').';
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
                    : ($latest['status'] === 'failed' ? 'Nao gerado' : 'Nao informado'),
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

    private function findWorkload(array $workloadMap, string $cliente, string $maquina): array
    {
        return $workloadMap[$this->lookupKey($cliente . '|' . $maquina)]
            ?? $workloadMap[$this->lookupKey($maquina)]
            ?? [];
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
