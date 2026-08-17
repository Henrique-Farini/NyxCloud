<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use NyxCloud\Lib\Acronis\AcronisFactory;

headerIfWeb();

try {
    $token = AcronisFactory::oauth()->getAccessToken();
    outputJson([
        'success' => true,
        'token_type' => 'Bearer',
        'access_token' => $token,
    ]);
} catch (Throwable $e) {
    outputError($e);
}

function headerIfWeb(): void
{
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: application/json; charset=utf-8');
    }
}

function outputJson(array $payload): void
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

function outputError(Throwable $e): void
{
    http_response_code(500);
    outputJson([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}

