<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$usuario = exigirPerfilAdministrador($pdo);
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if (in_array($method, ['PUT', 'PATCH'], true)) {
    apiResponse(false, new stdClass(), [], 'As janelas de execucao sao importadas automaticamente dos planos Acronis.', 409);
}

try {
    if ($method === 'GET') {
        $data = carregarConfigJanelas();
        apiResponse(true, $data);
    }

    if (!in_array($method, ['PUT', 'PATCH'], true)) {
        apiMethod('GET');
    }

    apiMutationGuard();
    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        apiResponse(false, new stdClass(), [], 'JSON invalido.', 422);
    }

    $config = normalizarConfigJanelas($payload);
    $saved = salvarConfigJanelas($config, (int) $usuario['id']);
    registrarAuditoriaJanelas($pdo, (int) $usuario['id'], 'janelas_atualizadas', [
        'rules_count' => count($config['rules']),
        'version' => $saved['version'],
    ]);

    apiResponse(true, $saved, [], 'Regras de janelas salvas.');
} catch (Throwable $e) {
    apiHandle($e);
}

function caminhoConfigJanelas(): string
{
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'backup_windows.json';
}

function caminhoHistoricoJanelas(): string
{
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'versions';
}

function carregarConfigJanelas(): array
{
    $path = caminhoConfigJanelas();
    if (is_readable($path)) {
        $payload = json_decode((string) file_get_contents($path), true);
        if (is_array($payload)) {
            return [
                'source' => 'json',
                'editable' => false,
                'path' => 'storage/config/backup_windows.json',
                'version' => (string) ($payload['version'] ?? (string) filemtime($path)),
                'timezone' => (string) ($payload['timezone'] ?? 'America/Sao_Paulo'),
                'rules' => is_array($payload['rules'] ?? null) ? array_values($payload['rules']) : [],
                'updated_at' => $payload['updated_at'] ?? null,
            ];
        }
    }

    $fallbackPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR . 'backup_windows.php';
    $fallback = is_file($fallbackPath) ? (require $fallbackPath) : [];

    return [
        'source' => 'php_fallback',
        'editable' => false,
        'path' => 'app/Config/backup_windows.php',
        'version' => is_file($fallbackPath) ? (string) filemtime($fallbackPath) : 'none',
        'timezone' => (string) ($fallback['timezone'] ?? 'America/Sao_Paulo'),
        'rules' => is_array($fallback['rules'] ?? null) ? array_values($fallback['rules']) : [],
        'updated_at' => null,
    ];
}

function normalizarConfigJanelas(array $payload): array
{
    $timezone = trim((string) ($payload['timezone'] ?? 'America/Sao_Paulo'));
    if ($timezone === '') {
        $timezone = 'America/Sao_Paulo';
    }
    try {
        new DateTimeZone($timezone);
    } catch (Throwable) {
        apiResponse(false, new stdClass(), [], 'Timezone invalido.', 422);
    }

    $rules = $payload['rules'] ?? null;
    if (!is_array($rules)) {
        apiResponse(false, new stdClass(), [], 'Informe uma lista de regras.', 422);
    }

    return [
        'version' => date('YmdHis'),
        'updated_at' => date('c'),
        'timezone' => $timezone,
        'rules' => array_values(array_map('normalizarRegraJanela', $rules)),
    ];
}

function normalizarRegraJanela(mixed $rule): array
{
    if (!is_array($rule)) {
        apiResponse(false, new stdClass(), [], 'Regra invalida.', 422);
    }

    $empresa = trim((string) ($rule['empresa'] ?? ''));
    $plano = trim((string) ($rule['plano'] ?? ''));
    $maquina = trim((string) ($rule['maquina'] ?? ''));
    $inicio = normalizarHoraJanela((string) ($rule['inicio'] ?? ''));
    $fim = normalizarHoraJanela((string) ($rule['fim'] ?? ''));
    $meta = max(0, (int) ($rule['meta'] ?? 0));
    $intervalo = max(0, (int) ($rule['intervalo_horas'] ?? 0));
    $dias = is_array($rule['dias_semana'] ?? null) ? array_values(array_unique(array_map('intval', $rule['dias_semana']))) : [];
    $dias = array_values(array_filter($dias, static fn (int $day): bool => $day >= 0 && $day <= 6));
    sort($dias);

    if ($empresa === '' || $plano === '' || $inicio === '' || $fim === '') {
        apiResponse(false, new stdClass(), [], 'Empresa, plano, inicio e fim sao obrigatorios.', 422);
    }
    if ($meta === 0 && $intervalo === 0) {
        apiResponse(false, new stdClass(), [], 'Informe meta ou intervalo_horas.', 422);
    }

    $clean = [
        'empresa' => $empresa,
        'plano' => $plano,
        'inicio' => $inicio,
        'fim' => $fim,
    ];
    if ($maquina !== '') {
        $clean['maquina'] = $maquina;
    }
    if ($intervalo > 0) {
        $clean['intervalo_horas'] = $intervalo;
    }
    if ($meta > 0) {
        $clean['meta'] = $meta;
    }
    if ($dias !== []) {
        $clean['dias_semana'] = $dias;
    }

    foreach (['empresa_aliases', 'plano_aliases'] as $field) {
        if (is_array($rule[$field] ?? null)) {
            $values = array_values(array_filter(array_map(static fn (mixed $value): string => trim((string) $value), $rule[$field])));
            if ($values !== []) {
                $clean[$field] = $values;
            }
        }
    }

    return $clean;
}

function normalizarHoraJanela(string $value): string
{
    $value = trim($value);
    if (!preg_match('/^\d{1,2}:\d{2}$/', $value)) {
        return '';
    }

    [$hour, $minute] = array_map('intval', explode(':', $value));
    if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
        return '';
    }

    return sprintf('%02d:%02d', $hour, $minute);
}

function salvarConfigJanelas(array $config, int $usuarioId): array
{
    $path = caminhoConfigJanelas();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    if (is_file($path)) {
        $historyDir = caminhoHistoricoJanelas();
        if (!is_dir($historyDir)) {
            mkdir($historyDir, 0775, true);
        }
        copy($path, $historyDir . DIRECTORY_SEPARATOR . 'backup_windows_' . date('YmdHis') . '.json');
    }

    $config['updated_by'] = $usuarioId;
    file_put_contents(
        $path,
        json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );

    return carregarConfigJanelas();
}

function registrarAuditoriaJanelas(PDO $pdo, int $atorId, string $acao, array $detalhes = []): void
{
    try {
        // PostgreSQL needs an explicit jsonb cast; MySQL accepts the JSON
        // string directly and does not understand the jsonb type.
        $detalhesValue = env('DB_CONNECTION', 'pgsql') === 'mysql'
            ? ':detalhes'
            : 'CAST(:detalhes AS jsonb)';
        $stmt = $pdo->prepare(
            "INSERT INTO usuario_auditoria (ator_id, acao, detalhes, ip, user_agent, criado_em)
             VALUES (:ator_id, :acao, {$detalhesValue}, :ip, :user_agent, NOW())"
        );
        $stmt->execute([
            'ator_id' => $atorId,
            'acao' => $acao,
            'detalhes' => json_encode($detalhes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        ]);
    } catch (Throwable $e) {
        error_log('Auditoria de janelas indisponivel: ' . $e->getMessage());
    }
}
