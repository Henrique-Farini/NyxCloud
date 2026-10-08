<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use NyxCloud\Services\AcronisCredentialStore;

apiMethod('GET');
$usuario = exigirAutenticacao($pdo);
exigirPermissaoAcao($pdo, $usuario, 'admin.view');
if (!usuarioEhAdministradorGeral($pdo, $usuario)) {
    apiResponse(false, new stdClass(), [], 'A administração central exige um administrador geral.', 403);
}

try {
    $cachePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'acronis';
    $cacheFiles = 0;
    $cacheBytes = 0;
    if (is_dir($cachePath)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cachePath, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with(strtolower($file->getFilename()), '.json')) {
                $cacheFiles++;
                $cacheBytes += $file->getSize();
            }
        }
    }

    $safeCount = static function (PDO $pdo, string $table): int {
        try {
            return (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    };

    $integrationError = '';
    try {
        $store = new AcronisCredentialStore();
        $integrations = $store->listSafe();
    } catch (Throwable $e) {
        $integrations = [];
        $integrationError = 'Não foi possível consultar as integrações agora.';
    }
    $integrationItems = is_array($integrations) ? ($integrations['items'] ?? $integrations) : [];
    $integrationItems = is_array($integrationItems) ? $integrationItems : [];
    $activeIntegrations = count(array_filter($integrationItems, static fn (mixed $item): bool => is_array($item) && filter_var($item['active'] ?? false, FILTER_VALIDATE_BOOLEAN)));

    $users = $pdo->query(
        'SELECT id, nome, email, perfil, ativo, ultimo_login_em, criado_em
         FROM usuario ORDER BY ativo DESC, nome ASC, email ASC'
    )->fetchAll() ?: [];
    $users = array_map(static function (array $item): array {
        return [
            'id' => (int) $item['id'],
            'nome' => (string) ($item['nome'] ?? ''),
            'email' => (string) ($item['email'] ?? ''),
            'perfil' => normalizarPerfil((string) ($item['perfil'] ?? '')),
            'perfil_nome' => nomePerfil((string) ($item['perfil'] ?? '')),
            'ativo' => filter_var($item['ativo'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'ultimo_login_em' => $item['ultimo_login_em'] ?? null,
            'criado_em' => $item['criado_em'] ?? null,
        ];
    }, $users);
    $activeUsers = count(array_filter($users, static fn (array $item): bool => $item['ativo']));
    $inactiveUsers = count($users) - $activeUsers;
    $usersNeverAccessed = count(array_filter($users, static fn (array $item): bool => empty($item['ultimo_login_em'])));
    $administratorUsers = count(array_filter($users, static fn (array $item): bool => $item['perfil'] === 'admin'));
    $companies = [];
    try {
        $companies = array_map(static fn (array $item): array => [
            'id' => (int) $item['id'],
            'nome' => (string) ($item['nome'] ?? ''),
            'ativo' => filter_var($item['ativo'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ], $pdo->query('SELECT id, nome, ativo FROM empresa ORDER BY nome ASC')->fetchAll() ?: []);
    } catch (Throwable) {
        $companies = [];
    }
    $activeCompanies = count(array_filter($companies, static fn (array $item): bool => $item['ativo']));
    $inactiveCompanies = count($companies) - $activeCompanies;
    $recentAudit = [];
    try {
        $auditStmt = $pdo->query(
            'SELECT a.criado_em, a.acao, a.ip, u.nome AS ator_nome, u.email AS ator_email
             FROM usuario_auditoria a LEFT JOIN usuario u ON u.id = a.ator_id
             ORDER BY a.criado_em DESC LIMIT 10'
        );
        $recentAudit = $auditStmt->fetchAll() ?: [];
    } catch (Throwable) {
        $recentAudit = [];
    }
    $auditCountSince = static function (PDO $pdo, int $days): int {
        try {
            $statement = $pdo->prepare('SELECT COUNT(*) FROM usuario_auditoria WHERE criado_em >= :since');
            $statement->execute(['since' => date('Y-m-d H:i:s', time() - ($days * 86400))]);
            return (int) $statement->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    };

    apiResponse(true, [
        'updated_at' => date('c'),
        'users' => [
            'total' => $safeCount($pdo, 'usuario'),
            'active' => $activeUsers,
            'inactive' => $inactiveUsers,
            'never_logged_in' => $usersNeverAccessed,
            'administrators' => $administratorUsers,
            'items' => $users,
        ],
        'companies' => [
            'total' => $safeCount($pdo, 'empresa'),
            'active' => $activeCompanies,
            'inactive' => $inactiveCompanies,
            'items' => $companies,
        ],
        'audit' => [
            'total' => $safeCount($pdo, 'usuario_auditoria'),
            'last_24h' => $auditCountSince($pdo, 1),
            'last_7d' => $auditCountSince($pdo, 7),
            'recent' => $recentAudit,
        ],
        'integrations' => [
            'total' => count($integrationItems),
            'active' => $activeIntegrations,
            'configured' => count($integrationItems) > 0,
            'error' => $integrationError,
        ],
        'cache' => [
            'path' => 'storage/cache/acronis',
            'files' => $cacheFiles,
            'bytes' => $cacheBytes,
            'writable' => is_dir($cachePath) ? is_writable($cachePath) : is_writable(dirname($cachePath)),
        ],
        'health' => [
            'status' => 'pending',
            'message' => 'A verificação da Acronis será executada separadamente.',
        ],
    ]);
} catch (Throwable $e) {
    apiHandle($e);
}
