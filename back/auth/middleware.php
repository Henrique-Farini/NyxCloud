<?php

declare(strict_types=1);

require_once __DIR__ . '/../conexao.php';
require_once __DIR__ . '/jwt.php';

function perfisDisponiveis(): array
{
    return [
        'admin' => 'Administrador',
        'operador' => 'Operador',
        'leitura' => 'Somente leitura',
    ];
}

function normalizarPerfil(?string $perfil): string
{
    $perfil = strtolower(trim((string) $perfil));
    return array_key_exists($perfil, perfisDisponiveis()) ? $perfil : 'leitura';
}

function nomePerfil(string $perfil): string
{
    $perfil = normalizarPerfil($perfil);
    return perfisDisponiveis()[$perfil];
}

function tabelaUsuarioTemPerfil(PDO $pdo): bool
{
    static $hasPerfil = null;
    if ($hasPerfil !== null) {
        return $hasPerfil;
    }

    $stmt = $pdo->query(
        "SELECT 1
         FROM information_schema.columns
         WHERE table_name = 'usuario'
           AND column_name = 'perfil'
         LIMIT 1"
    );
    $hasPerfil = (bool) $stmt->fetchColumn();

    return $hasPerfil;
}

function tabelaUsuarioTemIdioma(PDO $pdo): bool
{
    static $hasIdioma = null;
    if ($hasIdioma !== null) {
        return $hasIdioma;
    }

    $stmt = $pdo->query(
        "SELECT 1 FROM information_schema.columns
         WHERE table_name = 'usuario' AND column_name = 'idioma' LIMIT 1"
    );
    $hasIdioma = (bool) $stmt->fetchColumn();
    return $hasIdioma;
}

function tabelaUsuarioTemAdministradorGeral(PDO $pdo): bool
{
    static $hasFlag = null;
    if ($hasFlag !== null) {
        return $hasFlag;
    }

    $stmt = $pdo->query(
        "SELECT 1 FROM information_schema.columns
         WHERE table_name = 'usuario' AND column_name = 'administrador_geral' LIMIT 1"
    );
    $hasFlag = (bool) $stmt->fetchColumn();
    return $hasFlag;
}

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

    $perfilSelect = tabelaUsuarioTemPerfil($pdo) ? 'perfil' : "'admin' AS perfil";
    $idiomaSelect = tabelaUsuarioTemIdioma($pdo) ? 'idioma' : "'pt-BR' AS idioma";
    $adminGeralSelect = tabelaUsuarioTemAdministradorGeral($pdo) ? 'administrador_geral' : 'FALSE AS administrador_geral';
    $stmt = $pdo->prepare(
        "SELECT id, nome, email, {$perfilSelect}, {$idiomaSelect}, {$adminGeralSelect}, ativo, criado_em, ultimo_login_em
         FROM usuario WHERE id = :id AND ativo = TRUE"
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
            'message' => 'Autenticacao necessaria.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    return $usuario;
}

function usuarioPodeGerenciarContas(array $usuario): bool
{
    return normalizarPerfil((string) ($usuario['perfil'] ?? '')) === 'admin';
}

function usuarioPodeAcessarContas(array $usuario): bool
{
    return in_array(normalizarPerfil((string) ($usuario['perfil'] ?? '')), ['admin', 'operador'], true);
}

function usuarioEhAdministradorGeral(PDO $pdo, array $usuario): bool
{
    if (!usuarioPodeGerenciarContas($usuario) || !tabelaUsuarioTemAdministradorGeral($pdo)) {
        return false;
    }
    return filter_var($usuario['administrador_geral'] ?? false, FILTER_VALIDATE_BOOLEAN);
}

function empresasAcessiveis(PDO $pdo, array $usuario): array
{
    if (usuarioEhAdministradorGeral($pdo, $usuario)) {
        $stmt = $pdo->query('SELECT id, nome, ativo FROM empresa ORDER BY nome ASC');
    } else {
        $stmt = $pdo->prepare(
            'SELECT e.id, e.nome, e.ativo
             FROM empresa e
             INNER JOIN usuario_empresa ue ON ue.empresa_id = e.id
             WHERE ue.usuario_id = :usuario_id
             ORDER BY e.nome ASC'
        );
        $stmt->execute(['usuario_id' => (int) ($usuario['id'] ?? 0)]);
    }

    return array_map(static function (array $empresa): array {
        $empresa['id'] = (int) $empresa['id'];
        $empresa['ativo'] = filter_var($empresa['ativo'], FILTER_VALIDATE_BOOLEAN);
        return $empresa;
    }, $stmt->fetchAll() ?: []);
}

function exigirPerfilAdministrador(PDO $pdo): array
{
    $usuario = exigirAutenticacao($pdo);
    if (usuarioPodeGerenciarContas($usuario)) {
        return $usuario;
    }

    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'data' => new stdClass(),
        'meta' => new stdClass(),
        'message' => 'Permissao insuficiente para gerenciar contas.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
