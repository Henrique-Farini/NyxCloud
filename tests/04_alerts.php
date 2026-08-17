<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use NyxCloud\Lib\Acronis\AcronisFactory;

if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
}

try {
    $config = AcronisFactory::config();
    $payload = AcronisFactory::api($config)->get($config['endpoints']['alerts'], [
        'order' => 'desc(created_at)',
        'limit' => 100,
    ]);

    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT) . PHP_EOL;
}

