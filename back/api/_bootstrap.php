<?php

declare(strict_types=1);

require_once __DIR__ . '/_errors.php';

require_once dirname(__DIR__) . '/auth/middleware.php';
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use NyxCloud\Lib\Acronis\AcronisFactory;
use NyxCloud\Lib\Acronis\Exceptions\AuthenticationException as AcronisAuthenticationException;
use NyxCloud\Lib\Acronis\Exceptions\CommunicationException;
use NyxCloud\Lib\Acronis\Exceptions\HttpException;
use NyxCloud\Lib\Acronis\MultiAcronisApi;
use NyxCloud\Services\AlertService;
use NyxCloud\Services\CustomerService;
use NyxCloud\Services\DashboardService;
use NyxCloud\Services\DeviceService;
use NyxCloud\Services\ExecutionWindowService;

header('Content-Type: application/json; charset=utf-8');

function apiResponse(bool $success, mixed $data = new stdClass(), array $meta = [], string $message = '', int $status = 200): never
{
    http_response_code($status);
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'meta' => (object) $meta,
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function apiMethod(string $method): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== strtoupper($method)) {
        apiResponse(false, new stdClass(), [], 'Metodo nao permitido.', 405);
    }
}

function apiMutationGuard(): void
{
    exigirCsrfParaMutacao();
}

function apiFilters(array $allowed = []): array
{
    $source = $_GET;
    if ($allowed === []) {
        $allowed = array_keys($source);
    }

    $filters = [];
    foreach ($allowed as $key) {
        if (!array_key_exists($key, $source)) {
            continue;
        }

        $value = $source[$key];
        if (is_array($value)) {
            $clean = array_values(array_filter(array_map('trim', $value), static fn (string $item): bool => $item !== ''));
            if ($clean !== []) {
                $filters[$key] = $clean;
            }
            continue;
        }

        $value = trim((string) $value);
        if ($value !== '') {
            $filters[$key] = $value;
        }
    }

    return $filters;
}

function apiService(string $service): object
{
    $configs = AcronisFactory::configs();
    $config = $configs[0] ?? AcronisFactory::config();
    $api = count($configs) > 1 ? AcronisFactory::multiApi($configs) : AcronisFactory::api($config);
    $cache = AcronisFactory::cache($config);

    return match ($service) {
        DashboardService::class => new DashboardService($api, $cache, $config),
        AlertService::class => new AlertService($api, $cache, $config),
        CustomerService::class => new CustomerService($api, $cache, $config),
        DeviceService::class => new DeviceService($api, $cache, $config),
        ExecutionWindowService::class => new ExecutionWindowService($api, $cache, $config),
        default => throw new InvalidArgumentException('Servico invalido.'),
    };
}

function apiHandle(Throwable $e): never
{
    error_log($e->getMessage());

    if ($e instanceof AcronisAuthenticationException) {
        apiResponse(false, new stdClass(), [], 'Falha de autenticacao com a Acronis.', 502);
    }

    if ($e instanceof CommunicationException) {
        apiResponse(false, new stdClass(), [], 'Falha de comunicacao com a Acronis.', 502);
    }

    if ($e instanceof HttpException) {
        $status = $e->statusCode();
        $mappedStatus = $status >= 400 && $status < 500 ? 502 : 503;
        $response = $e->response();
        $meta = ['acronis_status' => $status];

        if (is_array($response)) {
            $meta['acronis_response'] = $response;
        }

        apiResponse(false, new stdClass(), $meta, 'A Acronis retornou um erro ao processar a solicitacao.', $mappedStatus);
    }

    apiResponse(false, new stdClass(), [], 'Erro interno do servidor.', 500);
}
