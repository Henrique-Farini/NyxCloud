<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use NyxCloud\Services\AcronisCredentialStore;

putenv('JWT_SECRET=12345678901234567890123456789012');

$path = __DIR__ . DIRECTORY_SEPARATOR . 'tmp_acronis_accounts.json';
@unlink($path);

$store = new AcronisCredentialStore($path);

$first = $store->save([
    'name' => 'Acronis US Prod',
    'region' => 'US',
    'base_url' => 'https://us.example.com',
    'client_id' => 'client-1',
    'client_secret' => 'secret-1',
    'active' => true,
]);

$second = $store->save([
    'name' => 'Acronis US Stage',
    'region' => 'US',
    'base_url' => 'https://us-stage.example.com',
    'client_id' => 'client-2',
    'client_secret' => 'secret-2',
    'active' => false,
]);

$accounts = json_decode((string) file_get_contents($path), true);
$ids = array_map(static fn (array $account): string => (string) ($account['id'] ?? ''), $accounts['accounts'] ?? []);

if (count($ids) < 2 || count(array_unique($ids)) !== count($ids)) {
    fwrite(STDERR, "Expected at least 2 unique id values without duplicates, got: " . json_encode($ids, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}

if (count($first['items']) < 1 || count($second['items']) < 2) {
    fwrite(STDERR, "Expected both saves to keep at least the original entries plus the native environment entry, got: " . json_encode([
        'first' => $first['items'],
        'second' => $second['items'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}

$firstId = '';
foreach (($first['items'] ?? []) as $item) {
    if (($item['client_id'] ?? '') === 'client-1') {
        $firstId = (string) ($item['id'] ?? '');
        break;
    }
}

if ($firstId === '') {
    fwrite(STDERR, "Expected the first saved integration to remain identifiable in the list, got: " . json_encode($first, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}

$toggle = $store->activate($firstId, false);
if (in_array($firstId, $toggle['active_ids'] ?? [], true)) {
    fwrite(STDERR, "Expected the disabled integration to be removed from the active set, got: " . json_encode($toggle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}

echo "OK: as integrações persistidas continuam únicas e não sobrescrevem umas às outras." . PHP_EOL;
