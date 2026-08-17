<?php

declare(strict_types=1);

require_once __DIR__ . '/../conexao.php';
require_once __DIR__ . '/jwt.php';

function usuarioAutenticado(PDO $pdo): ?array
{
    $payload = null;
    foreach (obterTokensDaRequisicao() as $token) {
        $candidate = lerTokenJwt($token);
        if ($candidate !== null && !empty($candidate['sub'])) {
            $payload = $candidate;
            break;
        }
    }
    if ($payload === null || empty($payload['sub'])) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, nome, email, ativo, criado_em, ultimo_login_em
         FROM usuario WHERE id = :id AND ativo = TRUE'
    );
    $stmt->execute(['id' => (int) $payload['sub']]);
    $usuario = $stmt->fetch();

    return $usuario ?: null;
}

function exigirAutenticacao(PDO $pdo): array
{
    $usuario = usuarioAutenticado($pdo);
    if ($usuario === null) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'data' => new stdClass(),
            'meta' => new stdClass(),
            'message' => 'Autenticação necessária.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    return $usuario;
}
