<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

putenv('JWT_SECRET=12345678901234567890123456789012');

$configPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'acronis_accounts.json';
$backupPath = $configPath . '.bak';
$original = file_exists($configPath) ? file_get_contents($configPath) : null;

if ($original !== null) {
    file_put_contents($backupPath, $original);
}

$data = [
    'active_id' => 'us',
    'accounts' => [
        [
            'id' => 'us',
            'name' => 'Acronis US',
            'region' => 'US',
            'base_url' => 'https://us.example.com',
            'client_id' => 'client-us',
            'client_secret' => 'v1:' . base64_encode(random_bytes(12) . random_bytes(16) . random_bytes(16)),
            'updated_at' => '2026-09-01T00:00:00+00:00',
        ],
        [
            'id' => 'br',
            'name' => 'Acronis BR',
            'region' => 'BR',
            'base_url' => 'https://br.example.com',
            'client_id' => 'client-br',
            'client_secret' => 'v1:' . base64_encode(random_bytes(12) . random_bytes(16) . random_bytes(16)),
            'updated_at' => '2026-09-01T00:00:00+00:00',
        ],
    ],
];

file_put_contents($configPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR . 'acronis.php';
$config = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR . 'acronis.php';

if (!is_array($config['accounts'] ?? null) || count($config['accounts']) !== 2) {
    fwrite(STDERR, "Expected config accounts to keep both integrations, got: " . json_encode($config['accounts'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    @unlink($configPath);
    if ($backupPath !== null && file_exists($backupPath)) {
        rename($backupPath, $configPath);
    }
    exit(1);
}

if (($config['active_id'] ?? '') !== 'us') {
    fwrite(STDERR, "Expected active_id to remain 'us', got: " . json_encode($config['active_id'] ?? null) . PHP_EOL);
    @unlink($configPath);
    if ($backupPath !== null && file_exists($backupPath)) {
        rename($backupPath, $configPath);
    }
    exit(1);
}

if (count($config['active_ids'] ?? []) > 1 && (($config['base_url'] ?? '') !== '' || ($config['client_id'] ?? '') !== '')) {
    fwrite(STDERR, "Expected multi-active config to not collapse to a single client, got: " . json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    @unlink($configPath);
    if ($backupPath !== null && file_exists($backupPath)) {
        rename($backupPath, $configPath);
    }
    exit(1);
}

echo "OK: a configuração mantém todas as integrações no mesmo conjunto e preserva a ativa." . PHP_EOL;

@unlink($configPath);
if ($backupPath !== null && file_exists($backupPath)) {
    rename($backupPath, $configPath);
}
