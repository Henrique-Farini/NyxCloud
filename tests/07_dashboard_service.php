<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use NyxCloud\Lib\Acronis\AcronisFactory;
use NyxCloud\Services\DashboardService;

$config = AcronisFactory::config();
$service = new DashboardService(
    AcronisFactory::api($config),
    AcronisFactory::cache($config),
    $config
);
$summary = $service->summary();
$required = [
    'total_clientes',
    'total_dispositivos',
    'total_backups',
    'backups_ok',
    'backups_com_falha',
    'espaco_utilizado',
    'taxa_sucesso',
    'series_diarias',
];
$missing = array_values(array_filter($required, static fn (string $field): bool => !array_key_exists($field, $summary)));
$dailyTotal = array_sum(array_map(
    static fn (array $day): int => (int) ($day['backups'] ?? 0),
    is_array($summary['series_diarias'] ?? null) ? $summary['series_diarias'] : []
));
$processed = (int) ($summary['total_backups'] ?? 0);
$successful = (int) ($summary['backups_ok'] ?? 0);
$failed = (int) ($summary['backups_com_falha'] ?? 0);
$valid = $missing === []
    && $processed >= 0
    && $successful >= 0
    && $failed >= 0
    && ($successful + $failed) <= $processed
    && (int) ($summary['espaco_utilizado'] ?? -1) >= 0
    && $dailyTotal <= $processed;

echo json_encode([
    'success' => $valid,
    'metrics' => [
        'completed' => $successful,
        'processed' => $processed,
        'failed' => $failed,
        'protected_bytes' => (int) ($summary['espaco_utilizado'] ?? 0),
        'clients' => (int) ($summary['total_clientes'] ?? 0),
        'devices' => (int) ($summary['total_dispositivos'] ?? 0),
    ],
    'daily_total' => $dailyTotal,
    'missing_fields' => $missing,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($valid ? 0 : 1);
