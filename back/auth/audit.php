<?php

declare(strict_types=1);

function registrarAuditoriaAdministrativa(PDO $pdo, ?int $atorId, string $acao, array $detalhes = [], ?int $alvoId = null): void
{
    try {
        $json = json_encode($detalhes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $valorJson = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? ':detalhes'
            : 'CAST(:detalhes AS jsonb)';
        $stmt = $pdo->prepare(
            "INSERT INTO usuario_auditoria (ator_id, alvo_usuario_id, acao, detalhes, ip, user_agent, criado_em)
             VALUES (:ator_id, :alvo_usuario_id, :acao, {$valorJson}, :ip, :user_agent, CURRENT_TIMESTAMP)"
        );
        $stmt->execute([
            'ator_id' => $atorId,
            'alvo_usuario_id' => $alvoId,
            'acao' => $acao,
            'detalhes' => $json,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        ]);
    } catch (Throwable $e) {
        error_log('Auditoria indisponivel: ' . $e->getMessage());
    }
}
