<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'PUT'], true)) {
    apiResponse(false, new stdClass(), [], 'Metodo nao permitido.', 405);
}

$usuario = exigirAutenticacao($pdo);

try {
    if ($method === 'GET') {
        $stmt = $pdo->query('SELECT dados FROM alertas_preferencias WHERE id = 1 LIMIT 1');
        $raw = $stmt->fetchColumn();
        $data = is_string($raw) ? json_decode($raw, true) : $raw;
        apiResponse(true, is_array($data) ? $data : []);
    }

    if (normalizarPerfil((string) ($usuario['perfil'] ?? '')) === 'leitura') {
        apiResponse(false, new stdClass(), [], 'O perfil somente leitura nao pode alterar preferencias.', 403);
    }

    apiMutationGuard();
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        apiResponse(false, new stdClass(), [], 'Preferencias invalidas.', 422);
    }

    $allowed = ['known', 'hidden', 'resolved', 'hidden_clients', 'hidden_devices', 'hidden_plans', 'device_categories'];
    $data = [];
    foreach ($allowed as $key) {
        if (!array_key_exists($key, $payload)) {
            continue;
        }
        $value = $payload[$key];
        if ($key === 'device_categories') {
            if (!is_array($value)) {
                apiResponse(false, new stdClass(), [], 'Categorias de dispositivo invalidas.', 422);
            }
            $data[$key] = $value;
            continue;
        }
        if (!is_array($value)) {
            apiResponse(false, new stdClass(), [], 'Lista de preferencias invalida.', 422);
        }
        $data[$key] = array_values(array_unique(array_filter(array_map('strval', $value), static fn (string $item): bool => $item !== '')));
    }

    if ($data === []) {
        apiResponse(false, new stdClass(), [], 'Nenhuma preferencia informada.', 422);
    }

    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
        $sql = 'INSERT INTO alertas_preferencias (id, dados, atualizado_em) VALUES (1, CAST(:dados AS jsonb), NOW())
                ON CONFLICT (id) DO UPDATE SET dados = EXCLUDED.dados, atualizado_em = NOW()';
    } else {
        $sql = 'INSERT INTO alertas_preferencias (id, dados) VALUES (1, CAST(:dados AS JSON))
                ON DUPLICATE KEY UPDATE dados = VALUES(dados), atualizado_em = CURRENT_TIMESTAMP';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['dados' => $json]);

    apiResponse(true, $data, [], 'Preferencias salvas.');
} catch (Throwable $e) {
    apiHandle($e);
}
