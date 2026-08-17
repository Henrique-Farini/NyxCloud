<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use NyxCloud\Lib\Acronis\AcronisFactory;

if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
}

try {
    $config = AcronisFactory::config();
    $payload = AcronisFactory::api($config)->get($config['endpoints']['tasks'], [
        'limit' => 100,
    ]);

    if (in_array('--raw', $argv ?? [], true) || ($_GET['raw'] ?? '') === '1') {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit;
    }

    $items = items($payload);
    $backupTasks = array_values(array_filter($items, 'isBackupTask'));
    $mapped = array_map('mapBackupTask', $backupTasks);

    echo json_encode([
        'success' => true,
        'total_retornado_api' => count($items),
        'total_backups' => count($mapped),
        'items' => $mapped,
        'paging' => $payload['paging'] ?? null,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT) . PHP_EOL;
}

function items(mixed $payload): array
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

function isBackupTask(array $task): bool
{
    if (($task['policy']['type'] ?? '') === 'backup') {
        return true;
    }

    if (!empty($task['context']['BackupPlanName'])) {
        return true;
    }

    $title = strtolower(trim((string) ($task['context']['title'] ?? '')));

    return str_starts_with($title, 'backup plan');
}

function mapBackupTask(array $task): array
{
    return [
        'cliente' => (string) ($task['tenant']['name'] ?? ''),
        'tenant_id' => (string) ($task['tenant']['id'] ?? ''),
        'tenant_uuid' => (string) ($task['tenant']['uuid'] ?? ''),
        'maquina' => (string) ($task['resource']['name'] ?? $task['context']['MachineName'] ?? ''),
        'plano' => (string) ($task['policy']['name'] ?? $task['context']['BackupPlanName'] ?? ''),
        'status' => (string) ($task['state'] ?? ''),
        'resultado' => (string) ($task['result']['code'] ?? ''),
        'inicio' => (string) ($task['startedAt'] ?? ''),
        'conclusao' => (string) ($task['completedAt'] ?? ''),
        'progresso' => progress($task),
        'bytes_processados' => (int) ($task['progress']['bytesProcessed'] ?? $task['context']['_runtime']['bytesProcessed'] ?? 0),
        'bytes_salvos' => (int) ($task['progress']['bytesSaved'] ?? $task['context']['_runtime']['bytesSaved'] ?? 0),
        'id' => (string) ($task['idString'] ?? $task['uuid'] ?? ''),
    ];
}

function progress(array $task): string
{
    $current = $task['progress']['current'] ?? null;
    $total = $task['progress']['total'] ?? null;

    if (!is_numeric($current) || !is_numeric($total) || (int) $total === 0) {
        return '';
    }

    return round(((int) $current / (int) $total) * 100, 2) . '%';
}
