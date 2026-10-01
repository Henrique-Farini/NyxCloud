<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$usuario = exigirPerfilAdministrador($pdo);

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if ($method === 'GET') {
        sincronizarEmpresasAcronis($pdo);
        apiResponse(true, [
            'items' => empresasAcessiveis($pdo, $usuario),
            'administrador_geral' => usuarioEhAdministradorGeral($pdo, $usuario),
        ]);
    }

    apiMethod('GET');
} catch (Throwable $e) {
    apiHandle($e);
}
