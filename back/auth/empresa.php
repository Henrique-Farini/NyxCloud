<?php

declare(strict_types=1);

function sincronizarEmpresasAcronis(PDO $pdo): void
{
    if (!function_exists('apiService')) {
        return;
    }

    try {
        $configs = \NyxCloud\Lib\Acronis\AcronisFactory::configs();
        $contaAcronisId = (string) (($configs[0]['client_id'] ?? '') ?: 'principal');
        $clientes = apiService(\NyxCloud\Services\CustomerService::class)->listCustomers();

        foreach ($clientes as $cliente) {
            $tenantId = trim((string) ($cliente['tenant'] ?? ''));
            $nome = trim((string) ($cliente['nome'] ?? ''));
            if ($tenantId === '' || $nome === '') {
                continue;
            }

            $find = $pdo->prepare(
                'SELECT empresa_id FROM acronis_cliente
                 WHERE conta_acronis_id = :conta_id AND tenant_acronis_id = :tenant_id LIMIT 1'
            );
            $find->execute(['conta_id' => $contaAcronisId, 'tenant_id' => $tenantId]);
            $empresaId = (int) ($find->fetchColumn() ?: 0);

            if ($empresaId > 0) {
                $pdo->prepare('UPDATE empresa SET nome = :nome, ativo = TRUE WHERE id = :id')
                    ->execute(['nome' => $nome, 'id' => $empresaId]);
                $pdo->prepare('UPDATE acronis_cliente SET nome = :nome, ativo = TRUE WHERE empresa_id = :id')
                    ->execute(['nome' => $nome, 'id' => $empresaId]);
                continue;
            }

            $pdo->prepare('INSERT INTO empresa (nome, ativo) VALUES (:nome, TRUE)')
                ->execute(['nome' => $nome]);
            $empresaId = (int) $pdo->lastInsertId();
            $pdo->prepare(
                'INSERT INTO acronis_cliente (empresa_id, conta_acronis_id, tenant_acronis_id, nome, ativo)
                 VALUES (:empresa_id, :conta_id, :tenant_id, :nome, TRUE)'
            )->execute([
                'empresa_id' => $empresaId,
                'conta_id' => $contaAcronisId,
                'tenant_id' => $tenantId,
                'nome' => $nome,
            ]);
        }
    } catch (Throwable $e) {
        error_log('Sincronizacao de empresas Acronis indisponivel: ' . $e->getMessage());
    }
}

function escopoAcronisDoUsuario(PDO $pdo, array $usuario): ?array
{
    if (usuarioEhAdministradorGeral($pdo, $usuario)) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT ac.tenant_acronis_id, e.nome
         FROM acronis_cliente ac
         INNER JOIN usuario_empresa ue ON ue.empresa_id = ac.empresa_id
         INNER JOIN empresa e ON e.id = ac.empresa_id
         WHERE ue.usuario_id = :usuario_id AND ac.ativo = TRUE'
    );
    $stmt->execute(['usuario_id' => (int) ($usuario['id'] ?? 0)]);
    $ids = [];
    $names = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        if ((string) ($row['tenant_acronis_id'] ?? '') !== '') {
            $ids[] = (string) $row['tenant_acronis_id'];
        }
        if (trim((string) ($row['nome'] ?? '')) !== '') {
            $names[] = (string) $row['nome'];
        }
    }
    return ['ids' => array_values(array_unique($ids)), 'names' => array_values(array_unique($names))];
}

function aplicarEscopoAcronis(object $service, PDO $pdo, array $usuario): void
{
    if (method_exists($service, 'definirEscopoTenants')) {
        $scope = escopoAcronisDoUsuario($pdo, $usuario);
        $service->definirEscopoTenants($scope === null ? null : $scope['ids'], $scope === null ? null : $scope['names']);
    }
}
