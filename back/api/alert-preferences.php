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
        $usuarioId = (int) ($usuario['id'] ?? 0);
        if (normalizarPerfil((string) ($usuario['perfil'] ?? '')) === 'leitura') {
            // Leitura acompanha o operador responsável por pelo menos uma mesma empresa.
            $stmt = $pdo->prepare(
                'SELECT ap.dados
                 FROM alertas_preferencias ap
                 INNER JOIN usuario operador ON operador.id = ap.usuario_id AND operador.perfil = \'operador\'
                 INNER JOIN usuario_empresa operador_empresa ON operador_empresa.usuario_id = operador.id
                 INNER JOIN usuario_empresa leitura_empresa ON leitura_empresa.empresa_id = operador_empresa.empresa_id
                 WHERE leitura_empresa.usuario_id = :usuario_id
                 ORDER BY ap.atualizado_em DESC
                 LIMIT 1'
            );
            $stmt->execute(['usuario_id' => $usuarioId]);
            $raw = $stmt->fetchColumn();
            if ($raw === false) {
                $stmt = $pdo->query('SELECT dados FROM alertas_preferencias WHERE usuario_id = 0 LIMIT 1');
                $raw = $stmt->fetchColumn();
            }
        } else {
            $stmt = $pdo->prepare('SELECT dados FROM alertas_preferencias WHERE usuario_id IN (0, :usuario_id) ORDER BY usuario_id DESC LIMIT 1');
            $stmt->execute(['usuario_id' => $usuarioId]);
            $raw = $stmt->fetchColumn();
        }
        $data = is_string($raw) ? json_decode($raw, true) : $raw;
        apiResponse(true, is_array($data) ? $data : []);
    }

    $perfil = normalizarPerfil((string) ($usuario['perfil'] ?? ''));
    if (!usuarioPodeAcao($usuario, 'alerts.preferences')) {
        apiResponse(false, new stdClass(), [], 'Este perfil nao pode alterar preferencias de alertas.', 403);
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
    $usuarioId = $perfil === 'admin' ? 0 : (int) ($usuario['id'] ?? 0);
    if ($usuarioId <= 0 && $perfil !== 'admin') {
        apiResponse(false, new stdClass(), [], 'Usuario invalido para salvar preferencias.', 422);
    }

    $pdo->beginTransaction();
    if ($perfil === 'admin') {
        // A configuração do administrador é a nova base compartilhada.
        $pdo->exec('DELETE FROM alertas_preferencias WHERE usuario_id <> 0');
    }
    $stmt = $pdo->prepare(
        'INSERT INTO alertas_preferencias (usuario_id, dados) VALUES (:usuario_id, CAST(:dados AS JSON))
         ON DUPLICATE KEY UPDATE dados = VALUES(dados), atualizado_em = CURRENT_TIMESTAMP'
    );
    $stmt->execute(['usuario_id' => $usuarioId, 'dados' => $json]);
    $pdo->commit();

    apiResponse(true, $data, [], 'Preferencias salvas.');
} catch (Throwable $e) {
    apiHandle($e);
}
