<?php

declare(strict_types=1);

require_once __DIR__ . '/_errors.php';

header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/../conexao.php';
    require_once __DIR__ . '/../auth/jwt.php';
    require_once __DIR__ . '/../auth/audit.php';
    require_once __DIR__ . '/../auth/middleware.php';
} catch (Throwable $e) {
    error_log('Falha ao inicializar o login: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Servico de autenticacao indisponivel. Verifique a configuracao do servidor.',
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metodo nao permitido.']);
    exit;
}

$dados = json_decode(file_get_contents('php://input'), true);
$dados = is_array($dados) ? array_merge($_POST, $dados) : $_POST;
$login = trim((string) ($dados['email'] ?? ''));
$senha = (string) ($dados['senha'] ?? '');
$lembrar = filter_var($dados['lembrar'] ?? false, FILTER_VALIDATE_BOOLEAN);

if ($login === '' || $senha === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Informe seu e-mail ou usuario e uma senha.']);
    exit;
}

limparRateLimitExpirado($pdo);
verificarLimiteLogin($pdo, $login);

$perfilSelect = tabelaUsuarioTemPerfil($pdo) ? 'perfil' : "'admin' AS perfil";
$idiomaSelect = tabelaUsuarioTemIdioma($pdo) ? 'idioma' : "'pt-BR' AS idioma";
$tokenVersionSelect = tabelaUsuarioTemTokenVersion($pdo) ? 'token_version' : '0 AS token_version';
$stmt = $pdo->prepare(
    "SELECT id, nome, email, {$perfilSelect}, {$idiomaSelect}, {$tokenVersionSelect}, senha_hash
     FROM usuario
     WHERE ativo = TRUE
       AND (
            LOWER(email) = LOWER(:email_login)
            OR LOWER(nome) = LOWER(:nome_login)
       )
     ORDER BY id ASC
     LIMIT 1"
);
$stmt->execute(['email_login' => $login, 'nome_login' => $login]);
$usuario = $stmt->fetch();

if (!$usuario || !password_verify($senha, $usuario['senha_hash'])) {
    registrarFalhaLogin($pdo, $login);
    registrarAuditoriaAdministrativa($pdo, $usuario ? (int) $usuario['id'] : null, 'login_falhou', [
        'identificador_hash' => hash('sha256', strtolower($login)),
    ], $usuario ? (int) $usuario['id'] : null);
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Usuario/e-mail ou senha invalidos.']);
    exit;
}

limparFalhasLogin($pdo, $login);

$pdo->prepare('UPDATE usuario SET ultimo_login_em = NOW(), atualizado_em = NOW() WHERE id = :id')
    ->execute(['id' => $usuario['id']]);

try {
    $ttl = nyxcloudJwtTtl($lembrar);
    $token = criarTokenJwt([
        'sub' => (string) $usuario['id'],
        'email' => $usuario['email'],
        'ver' => (int) ($usuario['token_version'] ?? 0),
    ], $ttl);
    definirCookieJwt($token, $ttl, $lembrar);
    $csrfToken = definirCookieCsrf(null, $ttl);
} catch (Throwable $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Autenticacao indisponivel.']);
    exit;
}

registrarAuditoriaAdministrativa($pdo, (int) $usuario['id'], 'login_sucesso', [
    'lembrar' => $lembrar,
], (int) $usuario['id']);

echo json_encode([
    'success' => true,
    'expires_in' => $ttl,
    'remembered' => $lembrar,
    'csrf_token' => $csrfToken,
    'user' => [
        'id' => (int) $usuario['id'],
        'nome' => $usuario['nome'],
        'email' => $usuario['email'],
        'perfil' => normalizarPerfil((string) ($usuario['perfil'] ?? '')),
        'perfil_nome' => nomePerfil((string) ($usuario['perfil'] ?? '')),
        'idioma' => in_array($usuario['idioma'] ?? '', ['pt-BR', 'en-US'], true) ? $usuario['idioma'] : 'pt-BR',
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

function chaveLimiteLogin(string $login): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nyxcloud-login-' . hash('sha256', strtolower($ip . '|' . $login)) . '.json';
}

function lerFalhasLogin(string $login): array
{
    $file = chaveLimiteLogin($login);
    if (!is_readable($file)) {
        return [];
    }

    $payload = json_decode((string) file_get_contents($file), true);
    return is_array($payload) ? array_values(array_filter(array_map('intval', $payload))) : [];
}

function verificarLimiteLogin(PDO $pdo, string $login): void
{
    $key = hash('sha256', strtolower((string) ($_SERVER['REMOTE_ADDR'] ?? 'cli') . '|' . $login));
    $stmt = $pdo->prepare('SELECT attempts, window_started_at FROM login_rate_limit WHERE rate_key = :rate_key');
    $stmt->execute(['rate_key' => $key]);
    $row = $stmt->fetch() ?: null;
    if (!$row || strtotime((string) $row['window_started_at']) < time() - 900) {
        return;
    }
    if ((int) $row['attempts'] < 5) {
        return;
    }

    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Muitas tentativas. Aguarde 15 minutos e tente novamente.']);
    exit;
}

function limparRateLimitExpirado(PDO $pdo): void
{
    $sql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
        ? "DELETE FROM login_rate_limit WHERE updated_at < CURRENT_TIMESTAMP - INTERVAL 1 DAY"
        : "DELETE FROM login_rate_limit WHERE updated_at < CURRENT_TIMESTAMP - INTERVAL '1 day'";
    $pdo->exec($sql);
}

function registrarFalhaLogin(PDO $pdo, string $login): void
{
    $key = hash('sha256', strtolower((string) ($_SERVER['REMOTE_ADDR'] ?? 'cli') . '|' . $login));
    $stmt = $pdo->prepare('SELECT attempts, window_started_at FROM login_rate_limit WHERE rate_key = :rate_key');
    $stmt->execute(['rate_key' => $key]);
    $row = $stmt->fetch() ?: null;
    if (!$row || strtotime((string) $row['window_started_at']) < time() - 900) {
        $sql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? 'INSERT INTO login_rate_limit (rate_key, attempts, window_started_at, updated_at) VALUES (:rate_key, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE attempts = 1, window_started_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP'
            : 'INSERT INTO login_rate_limit (rate_key, attempts, window_started_at, updated_at) VALUES (:rate_key, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) ON CONFLICT (rate_key) DO UPDATE SET attempts = 1, window_started_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP';
    } else {
        $sql = 'UPDATE login_rate_limit SET attempts = attempts + 1, updated_at = CURRENT_TIMESTAMP WHERE rate_key = :rate_key';
    }
    $pdo->prepare($sql)->execute(['rate_key' => $key]);
}

function limparFalhasLogin(PDO $pdo, string $login): void
{
    $key = hash('sha256', strtolower((string) ($_SERVER['REMOTE_ADDR'] ?? 'cli') . '|' . $login));
    $pdo->prepare('DELETE FROM login_rate_limit WHERE rate_key = :rate_key')->execute(['rate_key' => $key]);
}
