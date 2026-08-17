<?php

declare(strict_types=1);

putenv('JWT_SECRET=stale-short-secret');
require_once dirname(__DIR__) . '/back/config.php';

$secret = (string) env('JWT_SECRET', '');
$success = strlen($secret) >= 32 && $secret !== 'stale-short-secret';

echo json_encode([
    'success' => $success,
    'secret_length' => strlen($secret),
    'stale_process_value_ignored' => $success,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($success ? 0 : 1);
