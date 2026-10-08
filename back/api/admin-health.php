<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use NyxCloud\Lib\Acronis\AcronisFactory;

apiMethod('GET');
$usuario = exigirAutenticacao($pdo);
exigirPermissaoAcao($pdo, $usuario, 'admin.view');
if (!usuarioEhAdministradorGeral($pdo, $usuario)) {
    apiResponse(false, new stdClass(), [], 'A administração central exige um administrador geral.', 403);
}

$startedAt = microtime(true);
try {
    $configs = AcronisFactory::configs();
    $config = $configs[0] ?? AcronisFactory::config();
    $api = AcronisFactory::api($config);
    $endpoint = (string) ($config['endpoints']['tenants'] ?? '/api/2/tenants');
    $api->get($endpoint, ['limit' => 1]);
    apiResponse(true, [
        'status' => 'online',
        'message' => 'Acronis respondeu corretamente.',
        'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        'checked_at' => date('c'),
    ]);
} catch (Throwable $e) {
    error_log('Diagnostico Acronis administrativo: ' . $e->getMessage());
    apiResponse(true, [
        'status' => 'offline',
        'message' => 'Não foi possível validar a comunicação com a Acronis.',
        'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        'checked_at' => date('c'),
    ]);
}
