<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

apiMethod('PATCH');
apiMutationGuard();
$usuario = exigirAutenticacao($pdo);
$payload = json_decode((string) file_get_contents('php://input'), true);
$idioma = trim((string) (is_array($payload) ? ($payload['idioma'] ?? '') : ''));

if (!in_array($idioma, ['pt-BR', 'en-US'], true)) {
    apiResponse(false, new stdClass(), [], 'Idioma invalido.', 422);
}

try {
    $stmt = $pdo->prepare('UPDATE usuario SET idioma = :idioma, atualizado_em = NOW() WHERE id = :id');
    $stmt->execute(['idioma' => $idioma, 'id' => (int) $usuario['id']]);
    apiResponse(true, ['idioma' => $idioma], [], 'Idioma atualizado.');
} catch (Throwable $e) {
    apiHandle($e);
}
