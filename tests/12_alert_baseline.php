<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use NyxCloud\Services\AlertService;

$service = (new ReflectionClass(AlertService::class))->newInstanceWithoutConstructor();
$method = new ReflectionMethod(AlertService::class, 'baselineForExecutionTime');
$method->setAccessible(true);

$item = static function (string $time, int $bytes): array {
    return [
        'status' => 'success',
        'bytes' => $bytes,
        'time_minutes' => (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2),
    ];
};

$differentSchedules = $method->invoke($service, $item('12:00', 900), [
    $item('12:05', 1000),
    $item('11:55', 980),
    $item('18:00', 500),
    $item('18:05', 510),
    $item('17:55', 490),
]);

$equivalentSchedules = $method->invoke($service, $item('12:00', 700), [
    $item('12:05', 1000),
    $item('11:55', 980),
    $item('18:00', 1010),
    $item('18:05', 990),
]);

$success = $differentSchedules['value'] === null
    && $equivalentSchedules['value'] !== null
    && $equivalentSchedules['sample_count'] === 4;

echo json_encode([
    'success' => $success,
    'different_schedules_are_separated' => $differentSchedules,
    'equivalent_schedules_can_share_baseline' => $equivalentSchedules,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

exit($success ? 0 : 1);
