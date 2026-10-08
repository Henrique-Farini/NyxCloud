<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__) . '/auth/email.php';
require_once dirname(__DIR__) . '/auth/audit.php';

try {
    $usuario = exigirAutenticacao($pdo);
    exigirPermissaoAcao($pdo, $usuario, 'alerts.notifications');
    $scope = escopoNotificacao($pdo, $usuario);
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        apiResponse(true, [
            'scope' => $scope,
            'config' => buscarConfiguracaoNotificacao($pdo, $scope['chave']),
            'usuarios' => destinatariosPermitidos($pdo, $scope, $usuario),
        ]);
    }

    apiMutationGuard();
    $input = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($input)) {
        apiResponse(false, new stdClass(), [], 'JSON invalido.', 422);
    }

    if ($method === 'PUT') {
        $result = salvarConfiguracaoNotificacao($pdo, $usuario, $scope, $input);
        apiResponse(true, $result, [], 'Preferencias de notificacao salvas.');
    }

    if ($method === 'POST' && (string) ($input['action'] ?? '') === 'test') {
        enviarTesteNotificacao($pdo, $usuario, $scope);
    }

    apiResponse(false, new stdClass(), [], 'Metodo nao permitido.', 405);
} catch (InvalidArgumentException $e) {
    apiResponse(false, new stdClass(), [], $e->getMessage(), 422);
} catch (DomainException $e) {
    apiResponse(false, new stdClass(), [], $e->getMessage(), 403);
} catch (Throwable $e) {
    apiHandle($e);
}

function escopoNotificacao(PDO $pdo, array $usuario): array
{
    if (usuarioEhAdministradorGeral($pdo, $usuario)) {
        return [
            'tipo' => 'global',
            'chave' => 'global',
            'empresa_id' => null,
            'nome' => 'Todos os alertas do ambiente',
            'descricao' => 'O administrador geral recebe os alertas gerais do NyxCloud.',
        ];
    }

    $empresas = array_values(array_filter(
        empresasAcessiveis($pdo, $usuario),
        static fn (array $empresa): bool => filter_var($empresa['ativo'] ?? false, FILTER_VALIDATE_BOOLEAN)
    ));
    if (count($empresas) !== 1) {
        throw new DomainException('Este administrador precisa estar vinculado a uma unica empresa para configurar notificacoes.');
    }

    $empresa = $empresas[0];
    return [
        'tipo' => 'empresa',
        'chave' => 'empresa:' . (int) $empresa['id'],
        'empresa_id' => (int) $empresa['id'],
        'nome' => (string) $empresa['nome'],
        'descricao' => 'A configuracao vale somente para esta empresa.',
    ];
}

function destinatariosPermitidos(PDO $pdo, array $scope, array $usuario): array
{
    if ($scope['tipo'] === 'global') {
        return [[
            'id' => (int) $usuario['id'],
            'nome' => (string) ($usuario['nome'] ?? ''),
            'email' => (string) ($usuario['email'] ?? ''),
            'ativo' => true,
        ]];
    }

    $stmt = $pdo->prepare(
        'SELECT u.id, u.nome, u.email, u.ativo
         FROM usuario u
         INNER JOIN usuario_empresa ue ON ue.usuario_id = u.id
         WHERE ue.empresa_id = :empresa_id
           AND u.ativo = TRUE
         ORDER BY u.nome ASC, u.email ASC'
    );
    $stmt->execute(['empresa_id' => (int) $scope['empresa_id']]);
    return array_map(static fn (array $item): array => [
        'id' => (int) $item['id'],
        'nome' => (string) ($item['nome'] ?? ''),
        'email' => (string) ($item['email'] ?? ''),
        'ativo' => filter_var($item['ativo'] ?? false, FILTER_VALIDATE_BOOLEAN),
    ], $stmt->fetchAll() ?: []);
}

function buscarConfiguracaoNotificacao(PDO $pdo, string $scopeKey): array
{
    $stmt = $pdo->prepare(
        'SELECT id, ativo, modo, frequencia
         FROM alertas_email_configuracao
         WHERE escopo_chave = :escopo_chave
         LIMIT 1'
    );
    $stmt->execute(['escopo_chave' => $scopeKey]);
    $config = $stmt->fetch();
    if (!$config) {
        return [
            'id' => null,
            'ativo' => false,
            'modo' => 'actionable',
            'frequencia' => 'instant',
            'usuario_ids' => [],
            'emails' => [],
        ];
    }

    $recipientStmt = $pdo->prepare(
        'SELECT d.usuario_id, d.email, COALESCE(u.email, d.email) AS resolved_email
         FROM alertas_email_destinatario d
         LEFT JOIN usuario u ON u.id = d.usuario_id
         WHERE d.configuracao_id = :configuracao_id AND d.ativo = TRUE
         ORDER BY d.id ASC'
    );
    $recipientStmt->execute(['configuracao_id' => (int) $config['id']]);
    $userIds = [];
    $emails = [];
    foreach ($recipientStmt->fetchAll() ?: [] as $recipient) {
        if (!empty($recipient['usuario_id'])) {
            $userIds[] = (int) $recipient['usuario_id'];
            continue;
        }
        $email = strtolower(trim((string) ($recipient['email'] ?? '')));
        if ($email !== '') {
            $emails[] = $email;
        }
    }

    return [
        'id' => (int) $config['id'],
        'ativo' => filter_var($config['ativo'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'modo' => (string) ($config['modo'] ?? 'actionable'),
        'frequencia' => (string) ($config['frequencia'] ?? 'instant'),
        'usuario_ids' => array_values(array_unique($userIds)),
        'emails' => array_values(array_unique($emails)),
    ];
}

function salvarConfiguracaoNotificacao(PDO $pdo, array $usuario, array $scope, array $input): array
{
    $ativo = filter_var($input['ativo'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $modo = (string) ($input['modo'] ?? 'actionable');
    $frequencia = (string) ($input['frequencia'] ?? 'instant');
    if (!in_array($modo, ['all', 'actionable'], true)) {
        throw new InvalidArgumentException('Modo de alerta invalido.');
    }
    if (!in_array($frequencia, ['instant', 'hourly', 'daily'], true)) {
        throw new InvalidArgumentException('Frequencia de alerta invalida.');
    }

    $allowedUsers = destinatariosPermitidos($pdo, $scope, $usuario);
    $allowedById = [];
    foreach ($allowedUsers as $allowedUser) {
        $allowedById[(int) $allowedUser['id']] = $allowedUser;
    }

    $requestedIds = is_array($input['usuario_ids'] ?? null) ? $input['usuario_ids'] : [];
    $userIds = [];
    foreach ($requestedIds as $requestedId) {
        $id = (int) $requestedId;
        if ($id > 0 && isset($allowedById[$id])) {
            $userIds[] = $id;
        }
    }
    $userIds = array_values(array_unique($userIds));

    $requestedEmails = is_array($input['emails'] ?? null) ? $input['emails'] : [];
    $emails = [];
    foreach ($requestedEmails as $requestedEmail) {
        $email = strtolower(trim((string) $requestedEmail));
        if ($email === '') {
            continue;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Existe um destinatario com e-mail invalido.');
        }
        $emails[] = $email;
    }
    $emails = array_values(array_unique($emails));

    if ($ativo && ($userIds === [] && $emails === [])) {
        throw new InvalidArgumentException('Escolha pelo menos um destinatario antes de ativar os alertas por e-mail.');
    }

    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $pdo->beginTransaction();
    try {
        if ($driver === 'mysql') {
            $upsert = $pdo->prepare(
                'INSERT INTO alertas_email_configuracao (escopo, empresa_id, escopo_chave, ativo, modo, frequencia, criado_por, atualizado_por)
                 VALUES (:escopo, :empresa_id, :escopo_chave, :ativo, :modo, :frequencia, :criado_por, :atualizado_por)
                 ON DUPLICATE KEY UPDATE empresa_id = VALUES(empresa_id), ativo = VALUES(ativo), modo = VALUES(modo), frequencia = VALUES(frequencia), atualizado_por = VALUES(atualizado_por)'
            );
        } else {
            $upsert = $pdo->prepare(
                'INSERT INTO alertas_email_configuracao (escopo, empresa_id, escopo_chave, ativo, modo, frequencia, criado_por, atualizado_por)
                 VALUES (:escopo, :empresa_id, :escopo_chave, :ativo, :modo, :frequencia, :criado_por, :atualizado_por)
                 ON CONFLICT (escopo_chave) DO UPDATE SET empresa_id = EXCLUDED.empresa_id, ativo = EXCLUDED.ativo, modo = EXCLUDED.modo, frequencia = EXCLUDED.frequencia, atualizado_por = EXCLUDED.atualizado_por, atualizado_em = NOW()'
            );
        }
        $upsert->execute([
            'escopo' => $scope['tipo'],
            'empresa_id' => $scope['empresa_id'],
            'escopo_chave' => $scope['chave'],
            'ativo' => $ativo ? 1 : 0,
            'modo' => $modo,
            'frequencia' => $frequencia,
            'criado_por' => (int) $usuario['id'],
            'atualizado_por' => (int) $usuario['id'],
        ]);

        $idStmt = $pdo->prepare('SELECT id FROM alertas_email_configuracao WHERE escopo_chave = :escopo_chave LIMIT 1');
        $idStmt->execute(['escopo_chave' => $scope['chave']]);
        $configId = (int) $idStmt->fetchColumn();
        if ($configId < 1) {
            throw new RuntimeException('Configuracao de notificacao nao encontrada apos salvar.');
        }

        $pdo->prepare('DELETE FROM alertas_email_destinatario WHERE configuracao_id = :configuracao_id')->execute(['configuracao_id' => $configId]);
        $userRecipient = $pdo->prepare('INSERT INTO alertas_email_destinatario (configuracao_id, usuario_id, nome, ativo) VALUES (:configuracao_id, :usuario_id, :nome, TRUE)');
        foreach ($userIds as $userId) {
            $userRecipient->execute(['configuracao_id' => $configId, 'usuario_id' => $userId, 'nome' => $allowedById[$userId]['nome']]);
        }
        $emailRecipient = $pdo->prepare('INSERT INTO alertas_email_destinatario (configuracao_id, email, nome, ativo) VALUES (:configuracao_id, :email, :nome, TRUE)');
        foreach ($emails as $email) {
            $emailRecipient->execute(['configuracao_id' => $configId, 'email' => $email, 'nome' => $email]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    registrarAuditoriaAdministrativa($pdo, (int) $usuario['id'], 'alertas_email_atualizados', [
        'escopo' => $scope['chave'],
        'ativo' => $ativo,
        'modo' => $modo,
        'frequencia' => $frequencia,
        'usuarios' => count($userIds),
        'emails_externos' => count($emails),
    ]);

    return buscarConfiguracaoNotificacao($pdo, $scope['chave']);
}

function enviarTesteNotificacao(PDO $pdo, array $usuario, array $scope): never
{
    $config = buscarConfiguracaoNotificacao($pdo, $scope['chave']);
    if (!$config['ativo']) {
        apiResponse(false, new stdClass(), [], 'Ative a notificacao antes de enviar um teste.', 422);
    }

    $allowedUsers = destinatariosPermitidos($pdo, $scope, $usuario);
    $allowedById = [];
    foreach ($allowedUsers as $allowedUser) {
        $allowedById[(int) $allowedUser['id']] = $allowedUser;
    }
    $recipients = [];
    foreach ($config['usuario_ids'] as $userId) {
        if (!empty($allowedById[(int) $userId]['email'])) {
            $item = $allowedById[(int) $userId];
            $recipients[strtolower($item['email'])] = ['email' => $item['email'], 'nome' => $item['nome']];
        }
    }
    foreach ($config['emails'] as $email) {
        $recipients[strtolower($email)] = ['email' => $email, 'nome' => $email];
    }
    if ($recipients === []) {
        apiResponse(false, new stdClass(), [], 'Nenhum destinatario valido foi encontrado.', 422);
    }

    $scopeName = (string) $scope['nome'];
    $subject = 'Teste de alertas | NyxCloud';
    $html = '<div style="font-family:Arial,sans-serif;color:#1f2937"><h2>Teste de notificacoes NyxCloud</h2><p>Este e-mail confirma que a configuracao de alertas esta funcionando.</p><p><strong>Escopo:</strong> ' . htmlspecialchars($scopeName, ENT_QUOTES, 'UTF-8') . '</p></div>';
    $text = "Teste de notificacoes NyxCloud. Escopo: {$scopeName}.";
    $sent = 0;
    $failed = 0;
    $smtpPasswordMissing = false;
    foreach ($recipients as $recipient) {
        try {
            enviarEmailNotificacao((string) $recipient['email'], (string) $recipient['nome'], $subject, $html, $text);
            $sent++;
        } catch (Throwable $e) {
            $failed++;
            $smtpPasswordMissing = $smtpPasswordMissing || str_contains($e->getMessage(), 'MAIL_PASSWORD');
            error_log('Teste de notificacao nao enviado: ' . $e->getMessage());
        }
    }

    registrarAuditoriaAdministrativa($pdo, (int) $usuario['id'], 'alertas_email_teste', [
        'escopo' => $scope['chave'],
        'destinatarios' => count($recipients),
        'enviados' => $sent,
        'falhas' => $failed,
    ]);

    if ($sent === 0) {
        $message = $smtpPasswordMissing
            ? 'MAIL_PASSWORD nao esta configurada no arquivo .env do servidor.'
            : 'Nao foi possivel enviar o teste. Verifique host, porta, usuario e senha SMTP.';
        apiResponse(false, ['enviados' => 0, 'falhas' => $failed], [], $message, 502);
    }
    apiResponse(true, ['enviados' => $sent, 'falhas' => $failed], [], $failed > 0 ? 'Teste enviado parcialmente.' : 'Teste enviado com sucesso.');
}
