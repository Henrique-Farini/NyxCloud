<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use NyxCloud\Lib\Acronis\AcronisFactory;
use NyxCloud\Services\AlertService;

$config = AcronisFactory::config();
$service = new AlertService(
    AcronisFactory::api($config),
    AcronisFactory::cache($config),
    $config
);
$alerts = $service->listAlerts();
$required = ['cliente', 'maquina', 'codigo', 'causa', 'ip', 'plano', 'tamanho', 'data', 'hora', 'status'];
$missingFields = [];
$keys = [];
$duplicates = 0;
$previousTimestamp = PHP_INT_MAX;
$sorted = true;
$quality = [
    'client_unidentified' => 0,
    'device_unidentified' => 0,
    'ip_missing' => 0,
    'size_missing' => 0,
];
$incompleteOperational = [];

foreach ($alerts as $index => $alert) {
    foreach ($required as $field) {
        if (!array_key_exists($field, $alert)) {
            $missingFields[] = $index . ':' . $field;
        }
    }

    $timestamp = strtotime((string) ($alert['data'] ?? '') . ' ' . (string) ($alert['hora'] ?? '')) ?: 0;
    if ($timestamp > $previousTimestamp) {
        $sorted = false;
    }
    $previousTimestamp = $timestamp;

    $rawId = (string) ($alert['raw']['id'] ?? $alert['raw']['uuid'] ?? '');
    $key = $rawId !== '' ? $rawId : implode('|', [
        (string) ($alert['cliente'] ?? ''),
        (string) ($alert['maquina'] ?? ''),
        (string) ($alert['codigo'] ?? ''),
        (string) ($alert['data'] ?? ''),
        (string) ($alert['hora'] ?? ''),
        (string) ($alert['plano'] ?? ''),
    ]);
    $key = mb_strtolower(trim($key), 'UTF-8');
    if (isset($keys[$key])) {
        $duplicates++;
    }
    $keys[$key] = true;

    if (trim((string) ($alert['cliente'] ?? '')) === '' || str_contains(mb_strtolower((string) $alert['cliente']), 'nao identificado')) {
        $quality['client_unidentified']++;
    }
    if (trim((string) ($alert['maquina'] ?? '')) === '' || ($alert['maquina'] ?? '') === 'Recurso') {
        $quality['device_unidentified']++;
    }
    if (trim((string) ($alert['ip'] ?? '')) === '') {
        $quality['ip_missing']++;
    }
    if (trim((string) ($alert['tamanho'] ?? '')) === '' || str_starts_with((string) ($alert['tamanho'] ?? ''), 'Nao informado')) {
        $quality['size_missing']++;
    }
    if (($alert['alerta_origem'] ?? '') === 'monitoramento'
        && (trim((string) ($alert['ip'] ?? '')) === '' || ($alert['tamanho'] ?? '') === 'Nao informado')) {
        $incompleteOperational[] = [
            'client' => $alert['cliente'] ?? '',
            'device' => $alert['maquina'] ?? '',
            'plan' => $alert['plano'] ?? '',
            'ip' => $alert['ip'] ?? '',
            'size' => $alert['tamanho'] ?? '',
            'resource_id' => $alert['raw']['resource']['id'] ?? '',
        ];
    }
}

$result = [
    'success' => $missingFields === []
        && $sorted
        && $duplicates === 0
        && $quality['client_unidentified'] === 0
        && $quality['device_unidentified'] === 0
        && $incompleteOperational === [],
    'total' => count($alerts),
    'operational' => count(array_filter($alerts, static fn (array $alert): bool => ($alert['alerta_origem'] ?? '') === 'monitoramento')),
    'native' => count(array_filter($alerts, static fn (array $alert): bool => ($alert['alerta_origem'] ?? '') === 'acronis')),
    'duplicates_detected' => $duplicates,
    'sorted_descending' => $sorted,
    'missing_fields' => $missingFields,
    'data_quality' => $quality,
    'incomplete_operational' => $incompleteOperational,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['success'] ? 0 : 1);
