<?php

declare(strict_types=1);

namespace NyxCloud\Services;

use DateTimeImmutable;
use DateTimeZone;

final class ExecutionWindowService extends AbstractAcronisService
{
    private const CACHE_VERSION = 'v19';
    private ?array $windowsConfigCache = null;
    private ?DateTimeZone $timezoneCache = null;
    private array $lookupKeyCache = [];

    public function listWindows(array $filters = []): array
    {
        $requestedDate = $this->normalizeDate((string) ($filters['date'] ?? date('Y-m-d')));
        unset($filters['stale']);
        $cacheKey = 'acronis.execution_windows.' . self::CACHE_VERSION . '.' . $this->escopoCacheKey() . '.' . $this->windowsConfigVersion() . '.' . md5(json_encode([$filters, $requestedDate]));

        return $this->remember(
            $cacheKey,
            (int) ($this->config['cache_ttl']['dashboard'] ?? 300),
            function () use ($filters, $requestedDate): array {
            $tasks = $this->taskItems(90);
                $backupTasks = array_values(array_filter(
                    array_map(fn (array $task): array => $this->mapTask($task), $tasks),
                    fn (array $task): bool => $task['is_backup']
                ));

                // As janelas agora são derivadas das políticas e do histórico
                // real da Acronis. Regras manuais antigas não entram mais no
                // resultado exibido para o usuário.
                $rules = $this->automaticRules();
                $date = $this->resolveEffectiveDate($backupTasks, $requestedDate, array_key_exists('date', $filters), $rules);
                $historicalItems = $this->buildHistoricalSuggestions($backupTasks, $date);
                $ruleItems = $rules !== [] ? $this->matchRules($rules, $backupTasks, $date) : [];
                $items = $this->deduplicateItems($this->mergeRuleItems($historicalItems, $ruleItems, $rules));

                usort($items, function (array $left, array $right): int {
                    $severityOrder = ['failed' => 0, 'warning' => 1, 'success' => 2, 'observed' => 3];
                    $leftRank = $severityOrder[$left['status'] ?? 'observed'] ?? 9;
                    $rightRank = $severityOrder[$right['status'] ?? 'observed'] ?? 9;

                    if ($leftRank !== $rightRank) {
                        return $leftRank <=> $rightRank;
                    }

                    return strcmp((string) ($left['empresa'] ?? ''), (string) ($right['empresa'] ?? ''));
                });

                $items = array_map(static function (array $item): array {
                    unset($item['_empresa_key'], $item['_plano_key'], $item['_maquina_key']);
                    return $item;
                }, $items);

                return [
                    'date' => $date,
                    'mode' => $rules !== [] ? 'mixed' : 'historical_reference',
                    'rules_count' => count($rules),
                    'items' => $items,
                ];
            }
        );
    }

    private function mapTask(array $task): array
    {
        $tenant = $this->firstString($task, ['tenant.name']);
        $plan = $this->firstString($task, ['policy.name', 'context.BackupPlanName']);
        $machine = $this->firstString($task, ['resource.name', 'context.Persistent.Name']);
        $startedAt = $this->firstString($task, ['startedAt']);
        $completedAt = $this->firstString($task, ['completedAt', 'updatedAt', 'startedAt']);
        $result = strtolower($this->firstString($task, ['result.code', 'state'], 'unknown'));
        $isBackup = $this->isBackupTask($task) && $tenant !== '' && $plan !== '' && $machine !== '';

        return [
            'empresa' => $tenant,
            'plano' => $plan,
            'maquina' => $machine,
            '_empresa_key' => $this->normalizeLookupText($tenant),
            '_plano_key' => $this->normalizeLookupText($plan),
            '_maquina_key' => $this->normalizeLookupText($machine),
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
            'result' => $result,
            'is_backup' => $isBackup,
        ];
    }

    private function rules(): array
    {
        $config = $this->windowsConfig();
        $rules = is_array($config['rules'] ?? null) ? $config['rules'] : [];

        $rules = array_values(array_filter(array_map(function (mixed $rule): ?array {
            if (!is_array($rule)) {
                return null;
            }

            $empresa = trim((string) ($rule['empresa'] ?? ''));
            $plano = trim((string) ($rule['plano'] ?? ''));
            $maquina = trim((string) ($rule['maquina'] ?? ''));
            $inicio = $this->normalizeHour((string) ($rule['inicio'] ?? ''));
            $fim = $this->normalizeHour((string) ($rule['fim'] ?? ''));
            $meta = max(0, (int) ($rule['meta'] ?? 0));
            $intervaloHoras = max(0, (int) ($rule['intervalo_horas'] ?? 0));
            $diasSemana = array_values(array_filter(
                array_map('intval', is_array($rule['dias_semana'] ?? null) ? $rule['dias_semana'] : []),
                static fn (int $day): bool => $day >= 0 && $day <= 6
            ));

            if ($empresa === '' || $plano === '' || $inicio === '' || $fim === '') {
                return null;
            }

            $empresaAliases = array_values(array_filter(array_map('trim', is_array($rule['empresa_aliases'] ?? null) ? $rule['empresa_aliases'] : [])));
            $planoAliases = array_values(array_filter(array_map('trim', is_array($rule['plano_aliases'] ?? null) ? $rule['plano_aliases'] : [])));

            return [
                'empresa' => $empresa,
                'empresa_aliases' => $empresaAliases,
                'plano' => $plano,
                'plano_aliases' => $planoAliases,
                'maquina' => $maquina,
                '_empresa_keys' => array_values(array_unique(array_map(fn (string $value): string => $this->normalizeLookupText($value), array_merge([$empresa], $empresaAliases)))),
                '_plano_keys' => array_values(array_unique(array_map(fn (string $value): string => $this->normalizeLookupText($value), array_merge([$plano], $planoAliases)))),
                '_maquina_key' => $this->normalizeLookupText($maquina),
                'inicio' => $inicio,
                'fim' => $fim,
                'meta' => $meta,
                'intervalo_horas' => $intervaloHoras,
                'dias_semana' => $diasSemana,
            ];
        }, $rules)));

        if ($this->escopoCacheKey() === 'all') {
            return $rules;
        }

        $allowedNames = array_map(
            fn (string $name): string => $this->normalizeLookupText($name),
            $this->tenantScopeNames()
        );

        return array_values(array_filter($rules, function (array $rule) use ($allowedNames): bool {
            $companyNames = array_merge(
                [(string) ($rule['empresa'] ?? '')],
                is_array($rule['empresa_aliases'] ?? null) ? $rule['empresa_aliases'] : []
            );

            foreach ($companyNames as $companyName) {
                if (in_array($this->normalizeLookupText((string) $companyName), $allowedNames, true)) {
                    return true;
                }
            }

            return false;
        }));
    }

    private function automaticRules(): array
    {
        try {
            $tenantNames = [];
                $tenants = $this->filterTenantScopedItems($this->items($this->api->get($this->endpoint('tenants'), $this->tenantScopeFilters(['limit' => 1000]))));
            foreach ($tenants as $tenant) {
                if (!is_array($tenant)) {
                    continue;
                }
                $name = $this->firstString($tenant, ['name'], '');
                foreach (['id', 'uuid', 'tenant_id'] as $idField) {
                    $id = trim((string) ($tenant[$idField] ?? ''));
                    if ($id !== '' && $name !== '') {
                        $tenantNames[$id] = $name;
                    }
                }
            }

            $plans = [];
            foreach ($this->policyItems() as $container) {
                $policies = is_array($container['policy'] ?? null) ? $container['policy'] : [];
                $total = null;
                foreach ($policies as $policy) {
                    if (is_array($policy) && ($policy['type'] ?? '') === 'policy.protection.total') {
                        $total = $policy;
                        break;
                    }
                }
                if (!is_array($total)) {
                    continue;
                }
                $planName = trim((string) ($total['name'] ?? ''));
                if ($planName === '') {
                    continue;
                }
                $tenantId = trim((string) ($total['tenant_id'] ?? $container['tenant_id'] ?? ''));
                $empresa = $tenantNames[$tenantId] ?? ($tenantId !== '' ? $tenantId : 'Todos os clientes');

                foreach ($policies as $policy) {
                    if (!is_array($policy) || ($policy['type'] ?? '') !== 'policy.backup.machine') {
                        continue;
                    }
                    $backupSets = $policy['settings']['scheduling']['backup_sets'] ?? [];
                    foreach (is_array($backupSets) ? $backupSets : [] as $backupSet) {
                        $schedule = is_array($backupSet) ? ($backupSet['schedule'] ?? []) : [];
                        $time = is_array($schedule) ? ($schedule['alarms']['time'] ?? []) : [];
                        $times = is_array($time['repeat_at'] ?? null) ? $time['repeat_at'] : [];
                        if ($times === [] && isset($time['hour'])) {
                            $times = [$time];
                        }
                        $weekdays = $this->automaticWeekdays($time['weekdays'] ?? [], $schedule['type'] ?? '');
                        foreach ($times as $scheduled) {
                            if (!is_array($scheduled)) {
                                continue;
                            }
                            $hour = max(0, min(23, (int) ($scheduled['hour'] ?? 0)));
                            $minute = max(0, min(59, (int) ($scheduled['minute'] ?? 0)));
                            $start = sprintf('%02d:%02d', $hour, $minute);
                            $plans[] = [
                                'empresa' => $empresa,
                                'plano' => $planName,
                                'maquina' => '',
                                'inicio' => $start,
                                'fim' => $start,
                                'dias_semana' => $weekdays,
                                'meta' => 1,
                                'origem' => 'acronis',
                            ];
                        }
                    }
                }
            }

            return $plans;
        } catch (\Throwable $e) {
            error_log('Nao foi possivel carregar os agendamentos dos planos Acronis: ' . $e->getMessage());
            return [];
        }
    }

    private function automaticWeekdays(mixed $days, string $scheduleType): array
    {
        $map = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];
        $result = [];
        foreach (is_array($days) ? $days : [] as $day) {
            $key = strtolower(trim((string) $day));
            if (isset($map[$key])) {
                $result[] = $map[$key];
            }
        }
        if ($result !== []) {
            return array_values(array_unique($result));
        }
        return strtolower($scheduleType) === 'weekly' ? [1, 2, 3, 4, 5, 6] : [0, 1, 2, 3, 4, 5, 6];
    }

    private function mergeRules(array $automatic, array $manual): array
    {
        $result = [];
        $keys = [];
        foreach (array_merge($automatic, $manual) as $rule) {
            $key = implode('|', [
                $this->normalizeLookupText((string) ($rule['empresa'] ?? '')),
                $this->normalizeLookupText((string) ($rule['plano'] ?? '')),
                (string) ($rule['inicio'] ?? ''),
                (string) ($rule['fim'] ?? ''),
                implode(',', array_map('strval', $rule['dias_semana'] ?? [])),
            ]);
            if (isset($keys[$key])) {
                continue;
            }
            $keys[$key] = true;
            $result[] = $rule;
        }
        return $result;
    }

    private function matchRules(array $rules, array $tasks, string $date): array
    {
        $items = [];

        foreach ($rules as $rule) {
            $scheduledToday = $this->ruleAppliesOnDate($rule, $date);
            $matches = array_values(array_filter($tasks, function (array $task) use ($rule, $date): bool {
                return $this->taskMatchesRule($task, $rule)
                    && $this->inWindow($task['completed_at'], $date, $rule['inicio'], $rule['fim']);
            }));

            $realizado = count($matches);
            $meta = $scheduledToday ? $this->resolveRuleTarget($rule) : 0;
            $faltando = max($meta - $realizado, 0);
            $horariosEsperados = $scheduledToday ? $this->expectedTimes($rule) : [];
            $horariosRealizados = $this->performedTimes($matches);
            $horariosNaoFeitos = $scheduledToday ? $this->missingExpectedTimes($horariosEsperados, $horariosRealizados) : [];

            $items[] = [
                'empresa' => $rule['empresa'],
                'plano' => $rule['plano'],
                'maquina' => $rule['maquina'] !== '' ? $rule['maquina'] : 'Todas as maquinas do plano',
                '_empresa_key' => $rule['_empresa_keys'][0] ?? '',
                '_plano_key' => $rule['_plano_keys'][0] ?? '',
                '_maquina_key' => $rule['_maquina_key'] ?? '',
                'janela' => $this->ruleWindowLabel($rule),
                'meta' => $meta,
                'realizado' => $realizado,
                'faltando' => $faltando,
                'status' => $this->statusFromTotals($realizado, $meta),
                'ultimo_backup' => $this->latestTaskTime($matches),
                'horarios_esperados' => $horariosEsperados,
                'horarios_realizados' => $horariosRealizados,
                'horarios_nao_feitos' => $horariosNaoFeitos,
                'base' => 'rule',
            ];
        }

        return $items;
    }

    private function buildHistoricalSuggestions(array $tasks, string $date): array
    {
        $recent = array_values(array_filter($tasks, function (array $task) use ($date): bool {
            $taskDate = $this->localDate($task['completed_at']);
            if ($taskDate === null) {
                return false;
            }

            $daysAgo = (strtotime($date . ' 23:59:59') - strtotime($taskDate . ' 12:00:00')) / 86400;
            return $daysAgo >= 0 && $daysAgo <= 7;
        }));

        $grouped = [];
        foreach ($recent as $task) {
            $local = $this->localDateTime($task['completed_at']);
            if ($local === null) {
                continue;
            }

            $hour = (int) $local->format('G');
            $bucket = $this->bucketForHour($hour);
            $day = $local->format('Y-m-d');
            $key = $task['_empresa_key'] . '|' . $task['_plano_key'] . '|' . $task['_maquina_key'] . '|' . $bucket['start'];

            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'empresa' => $task['empresa'],
                    'plano' => $task['plano'],
                    'maquina' => $task['maquina'],
                    '_empresa_key' => $task['_empresa_key'],
                    '_plano_key' => $task['_plano_key'],
                    '_maquina_key' => $task['_maquina_key'],
                    'janela' => $bucket['label'],
                    'dias' => [],
                    'today' => 0,
                    'ultimo_backup' => '',
                ];
            }

            $grouped[$key]['dias'][$day] = ($grouped[$key]['dias'][$day] ?? 0) + 1;
            if ($day === $date) {
                $grouped[$key]['today']++;
            }

            if ($grouped[$key]['ultimo_backup'] === '' || strtotime($task['completed_at']) > strtotime($grouped[$key]['ultimo_backup'])) {
                $grouped[$key]['ultimo_backup'] = $task['completed_at'];
            }
        }

        $items = [];
        foreach ($grouped as $group) {
            $samples = array_values($group['dias']);
            $referencia = $samples !== [] ? (int) round(array_sum($samples) / count($samples)) : 0;
            if ($referencia <= 0) {
                continue;
            }

            $realizado = (int) $group['today'];
            $items[] = [
                'empresa' => $group['empresa'],
                'plano' => $group['plano'],
                'maquina' => $group['maquina'],
                '_empresa_key' => $group['_empresa_key'],
                '_plano_key' => $group['_plano_key'],
                '_maquina_key' => $group['_maquina_key'],
                'janela' => $group['janela'],
                'meta' => $referencia,
                'realizado' => $realizado,
                'faltando' => max($referencia - $realizado, 0),
                'status' => $realizado >= $referencia ? 'success' : ($realizado > 0 ? 'warning' : 'observed'),
                'ultimo_backup' => $group['ultimo_backup'],
                'base' => 'historical_reference',
            ];
        }

        usort($items, fn (array $a, array $b): int => $b['meta'] <=> $a['meta']);

        return $items;
    }

    private function isBackupTask(array $task): bool
    {
        $haystack = strtolower((string) json_encode([
            $task['type'] ?? '',
            $task['context']['title'] ?? '',
            $task['context']['BackupPlanName'] ?? '',
            $task['policy']['name'] ?? '',
        ]));

        return str_contains($haystack, 'backup')
            || str_contains($haystack, 'bd')
            || str_contains($haystack, 'srv')
            || str_contains($haystack, 'oracle')
            || str_contains($haystack, 'sql')
            || str_contains($haystack, 'dados');
    }

    private function normalizeDate(string $value): string
    {
        $time = strtotime($value);
        return $time === false ? date('Y-m-d') : date('Y-m-d', $time);
    }

    private function normalizeHour(string $value): string
    {
        if (!preg_match('/^\d{1,2}:\d{2}$/', $value)) {
            return '';
        }

        [$hour, $minute] = array_map('intval', explode(':', $value));
        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            return '';
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    private function resolveEffectiveDate(array $tasks, string $requestedDate, bool $dateWasExplicit, array $rules = []): string
    {
        if ($dateWasExplicit) {
            return $requestedDate;
        }

        $latest = '';
        $hasRequestedDate = false;
        $latestRuleDate = '';

        foreach ($tasks as $task) {
            $taskDate = $this->localDate((string) ($task['completed_at'] ?? ''));
            if ($taskDate === null) {
                continue;
            }

            if ($taskDate === $requestedDate) {
                $hasRequestedDate = true;
            }
            if ($latest === '' || $taskDate > $latest) {
                $latest = $taskDate;
            }

            if ($rules !== [] && $this->matchesAnyRule($task, $rules) && $this->anyRuleAppliesOnDate($rules, $taskDate)) {
                if ($latestRuleDate === '' || $taskDate > $latestRuleDate) {
                    $latestRuleDate = $taskDate;
                }
            }
        }

        if ($hasRequestedDate || $latest === '') {
            return $requestedDate;
        }

        if ($latestRuleDate !== '') {
            return $latestRuleDate;
        }

        return $latest;
    }

    private function inWindow(string $timestamp, string $date, string $start, string $end): bool
    {
        $local = $this->localDateTime($timestamp);
        if ($local === null || $local->format('Y-m-d') !== $date) {
            return false;
        }

        $minutes = ((int) $local->format('G') * 60) + (int) $local->format('i');
        $startMinutes = $this->toMinutes($start);
        $endMinutes = $this->toMinutes($end);

        return $minutes >= $startMinutes && $minutes <= $endMinutes;
    }

    private function toMinutes(string $hour): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $hour));
        return ($hours * 60) + $minutes;
    }

    private function latestTaskTime(array $tasks): string
    {
        $latest = '';
        foreach ($tasks as $task) {
            $time = (string) ($task['completed_at'] ?? '');
            if ($latest === '' || strtotime($time) > strtotime($latest)) {
                $latest = $time;
            }
        }

        return $latest;
    }

    private function bucketForHour(int $hour): array
    {
        $start = (int) floor($hour / 6) * 6;
        $end = min($start + 6, 24);

        return [
            'start' => $start,
            'label' => sprintf('%02d:00 - %02d:00', $start, $end),
        ];
    }

    private function sameText(string $left, string $right): bool
    {
        return $this->normalizeLookupText($left) === $this->normalizeLookupText($right);
    }

    private function deduplicateItems(array $items): array
    {
        $grouped = [];

        foreach ($items as $item) {
            $key = (string) ($item['_empresa_key'] ?? $this->normalizeLookupText((string) ($item['empresa'] ?? ''))) . '|'
                . (string) ($item['_plano_key'] ?? $this->normalizeLookupText((string) ($item['plano'] ?? ''))) . '|'
                . (string) ($item['_maquina_key'] ?? $this->normalizeLookupText((string) ($item['maquina'] ?? ''))) . '|'
                . mb_strtolower(trim((string) ($item['janela'] ?? '')));

            if (!isset($grouped[$key])) {
                $grouped[$key] = $item;
                continue;
            }

            if (($grouped[$key]['base'] ?? '') !== 'rule' && ($item['base'] ?? '') === 'rule') {
                $grouped[$key] = $item;
                continue;
            }

            $currentTime = strtotime((string) ($grouped[$key]['ultimo_backup'] ?? '')) ?: 0;
            $candidateTime = strtotime((string) ($item['ultimo_backup'] ?? '')) ?: 0;
            if ($candidateTime > $currentTime) {
                $grouped[$key] = $item;
            }
        }

        return array_values($grouped);
    }

    private function statusFromTotals(int $realizado, int $meta): string
    {
        if ($meta > 0 && $realizado >= $meta) {
            return 'success';
        }

        if ($meta > 0 && $realizado > 0) {
            return 'warning';
        }

        if ($meta > 0) {
            return 'failed';
        }

        return $realizado > 0 ? 'warning' : 'observed';
    }

    private function expectedTimes(array $rule): array
    {
        $startMinutes = $this->toMinutes((string) ($rule['inicio'] ?? '00:00'));
        $endMinutes = $this->toMinutes((string) ($rule['fim'] ?? '00:00'));
        $intervalHours = (int) ($rule['intervalo_horas'] ?? 0);
        $meta = (int) ($rule['meta'] ?? 0);

        if ($meta === 1 || $startMinutes === $endMinutes) {
            return [sprintf('%02d:%02d', intdiv($startMinutes, 60), $startMinutes % 60)];
        }

        if ($intervalHours <= 0 || $endMinutes < $startMinutes) {
            return [];
        }

        $times = [];
        $step = $intervalHours * 60;
        for ($minutes = $startMinutes; $minutes <= $endMinutes; $minutes += $step) {
            $times[] = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
        }

        return $times;
    }

    private function performedTimes(array $matches): array
    {
        $times = [];
        foreach ($matches as $task) {
            $local = $this->localDateTime((string) ($task['completed_at'] ?? ''));
            if ($local === null) {
                continue;
            }

            $times[] = $local->format('H:i');
        }

        sort($times);
        return array_values(array_unique($times));
    }

    private function mergeTimes(array $left, array $right): array
    {
        $merged = array_values(array_unique(array_merge($left, $right)));
        sort($merged);
        return $merged;
    }

    private function missingExpectedTimes(array $expected, array $performed): array
    {
        $missing = [];

        foreach ($expected as $expectedTime) {
            $expectedMinutes = $this->toMinutes($expectedTime);
            $matched = false;

            foreach ($performed as $performedTime) {
                $performedMinutes = $this->toMinutes($performedTime);
                if (abs($performedMinutes - $expectedMinutes) <= 35) {
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                $missing[] = $expectedTime;
            }
        }

        return $missing;
    }

    private function windowsConfig(): array
    {
        if ($this->windowsConfigCache !== null) {
            return $this->windowsConfigCache;
        }

        $jsonPath = dirname(__DIR__, 2) . '/storage/config/backup_windows.json';
        if (is_readable($jsonPath)) {
            try {
                $json = json_decode((string) file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);
                if (is_array($json)) {
                    $this->windowsConfigCache = $json;
                    return $this->windowsConfigCache;
                }
            } catch (\Throwable $e) {
                error_log('Configuracao JSON de janelas invalida: ' . $e->getMessage());
            }
        }

        $path = dirname(__DIR__) . '/Config/backup_windows.php';
        $config = is_file($path) ? (require $path) : [];
        $this->windowsConfigCache = is_array($config) ? $config : [];

        return $this->windowsConfigCache;
    }

    private function windowsConfigVersion(): string
    {
        $jsonPath = dirname(__DIR__, 2) . '/storage/config/backup_windows.json';
        if (is_file($jsonPath)) {
            return 'json-' . ((string) filemtime($jsonPath));
        }

        $path = dirname(__DIR__) . '/Config/backup_windows.php';
        return is_file($path) ? 'php-' . ((string) filemtime($path)) : 'none';
    }

    private function timezone(): DateTimeZone
    {
        if ($this->timezoneCache !== null) {
            return $this->timezoneCache;
        }

        $config = $this->windowsConfig();
        $timezone = (string) ($config['timezone'] ?? 'America/Sao_Paulo');

        $this->timezoneCache = new DateTimeZone($timezone !== '' ? $timezone : 'America/Sao_Paulo');

        return $this->timezoneCache;
    }

    private function localDateTime(string $timestamp): ?DateTimeImmutable
    {
        if ($timestamp === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($timestamp))->setTimezone($this->timezone());
        } catch (\Throwable) {
            return null;
        }
    }

    private function localDate(string $timestamp): ?string
    {
        $local = $this->localDateTime($timestamp);
        return $local?->format('Y-m-d');
    }

    private function ruleAppliesOnDate(array $rule, string $date): bool
    {
        $days = $rule['dias_semana'] ?? [];
        if ($days === []) {
            return true;
        }

        $dayOfWeek = (int) (new DateTimeImmutable($date, $this->timezone()))->format('w');
        return in_array($dayOfWeek, $days, true);
    }

    private function resolveRuleTarget(array $rule): int
    {
        $meta = (int) ($rule['meta'] ?? 0);
        if ($meta > 0) {
            return $meta;
        }

        $intervalHours = (int) ($rule['intervalo_horas'] ?? 0);
        if ($intervalHours <= 0) {
            return 0;
        }

        $startMinutes = $this->toMinutes((string) $rule['inicio']);
        $endMinutes = $this->toMinutes((string) $rule['fim']);
        if ($endMinutes < $startMinutes) {
            return 0;
        }

        return (int) floor(($endMinutes - $startMinutes) / ($intervalHours * 60)) + 1;
    }

    private function ruleWindowLabel(array $rule): string
    {
        $parts = [$rule['inicio'] . ' - ' . $rule['fim']];

        if ((int) ($rule['intervalo_horas'] ?? 0) > 0) {
            $parts[] = 'a cada ' . (int) $rule['intervalo_horas'] . 'h';
        }

        if (($rule['dias_semana'] ?? []) !== []) {
            $parts[] = $this->weekdaysLabel($rule['dias_semana']);
        }

        return implode(' - ', $parts);
    }

    private function weekdaysLabel(array $days): string
    {
        $normalized = array_values(array_unique(array_map('intval', $days)));
        sort($normalized);

        if ($normalized === [1, 2, 3, 4, 5, 6]) {
            return 'seg-sab';
        }

        $labels = [0 => 'dom', 1 => 'seg', 2 => 'ter', 3 => 'qua', 4 => 'qui', 5 => 'sex', 6 => 'sab'];
        return implode(', ', array_map(fn (int $day): string => $labels[$day] ?? (string) $day, $normalized));
    }

    private function normalizeLookupText(string $value): string
    {
        if (array_key_exists($value, $this->lookupKeyCache)) {
            return $this->lookupKeyCache[$value];
        }

        $text = trim(mb_strtolower($value));
        $text = preg_replace('/\s*\([^)]*\)/u', '', $text) ?? $text;
        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
        $text = preg_replace('/[^a-z0-9]+/i', '', $text) ?? $text;
        $this->lookupKeyCache[$value] = $text;

        return $text;
    }

    private function mergeRuleItems(array $historicalItems, array $ruleItems, array $rules = []): array
    {
        if ($ruleItems === []) {
            return $historicalItems;
        }

        $merged = array_values(array_filter($historicalItems, function (array $item) use ($ruleItems, $rules): bool {
            foreach ($rules as $rule) {
                if ($this->itemMatchesRule($item, $rule)) {
                    return false;
                }
            }

            foreach ($ruleItems as $ruleItem) {
                if (($item['_empresa_key'] ?? '') === ($ruleItem['_empresa_key'] ?? '')
                    && ($item['_plano_key'] ?? '') === ($ruleItem['_plano_key'] ?? '')) {
                    return false;
                }
            }

            return true;
        }));

        return array_merge($merged, $ruleItems);
    }

    private function matchesAnyRule(array $task, array $rules): bool
    {
        foreach ($rules as $rule) {
            if ($this->taskMatchesRule($task, $rule)) {
                return true;
            }
        }

        return false;
    }

    private function taskMatchesRule(array $task, array $rule): bool
    {
        if (!in_array((string) ($task['_empresa_key'] ?? ''), $rule['_empresa_keys'] ?? [], true)) {
            return false;
        }

        if (!in_array((string) ($task['_plano_key'] ?? ''), $rule['_plano_keys'] ?? [], true)) {
            return false;
        }

        return (string) ($rule['_maquina_key'] ?? '') === ''
            || (string) ($task['_maquina_key'] ?? '') === (string) ($rule['_maquina_key'] ?? '');
    }

    private function itemMatchesRule(array $item, array $rule): bool
    {
        return in_array((string) ($item['_empresa_key'] ?? ''), $rule['_empresa_keys'] ?? [], true)
            && in_array((string) ($item['_plano_key'] ?? ''), $rule['_plano_keys'] ?? [], true);
    }

    private function matchesLookup(string $value, string $primary, array $aliases = []): bool
    {
        if ($this->sameText($value, $primary)) {
            return true;
        }

        foreach ($aliases as $alias) {
            if ($this->sameText($value, (string) $alias)) {
                return true;
            }
        }

        return false;
    }

    private function anyRuleAppliesOnDate(array $rules, string $date): bool
    {
        foreach ($rules as $rule) {
            if ($this->ruleAppliesOnDate($rule, $date)) {
                return true;
            }
        }

        return false;
    }
}
