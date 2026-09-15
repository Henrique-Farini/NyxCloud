<?php

declare(strict_types=1);

// Erros de inicializacao e consultas tambem devem respeitar o contrato JSON.
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
set_exception_handler(static function (Throwable $error): void {
    error_log('Falha na API: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'data' => new stdClass(),
        'meta' => new stdClass(),
        'message' => 'Servico indisponivel. Verifique a configuracao do servidor.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
});
