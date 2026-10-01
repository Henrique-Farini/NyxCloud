<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

apiMethod('GET');
exigirPerfilAdministrador($pdo);

try {
    $stmt = $pdo->query(
        "SELECT 1
         FROM information_schema.tables
         WHERE table_name = 'usuario_auditoria'
         LIMIT 1"
    );
    if (!$stmt->fetchColumn()) {
        apiResponse(true, [
            'items' => [],
            'available' => false,
        ], [], 'Auditoria ainda nao inicializada.');
    }

    $limit = min(50, max(10, (int) ($_GET['limit'] ?? 25)));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $period = (int) ($_GET['period'] ?? 30);
    $where = [];
    $params = [];
    if ($period > 0) {
        $from = (new DateTimeImmutable('today'))->modify('-' . max(0, $period - 1) . ' days')->format('Y-m-d 00:00:00');
        $where[] = 'a.criado_em >= :period_from';
        $params['period_from'] = $from;
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $countQuery = $pdo->prepare('SELECT COUNT(*) FROM usuario_auditoria a' . $whereSql);
    $countQuery->execute($params);
    $total = (int) $countQuery->fetchColumn();
    $pages = max(1, (int) ceil($total / $limit));
    $page = min($page, $pages);
    $offset = ($page - 1) * $limit;

    $query = $pdo->prepare(
        "SELECT
            a.id,
            a.acao,
            a.detalhes,
            a.ip,
            a.user_agent,
            a.criado_em,
            ator.nome AS ator_nome,
            ator.email AS ator_email,
            alvo.nome AS alvo_nome,
            alvo.email AS alvo_email
         FROM usuario_auditoria a
         LEFT JOIN usuario ator ON ator.id = a.ator_id
         LEFT JOIN usuario alvo ON alvo.id = a.alvo_usuario_id
         {$whereSql}
         ORDER BY a.criado_em DESC, a.id DESC
         LIMIT :limit OFFSET :offset"
    );
    foreach ($params as $key => $value) {
        $query->bindValue($key, $value);
    }
    $query->bindValue('limit', $limit, PDO::PARAM_INT);
    $query->bindValue('offset', $offset, PDO::PARAM_INT);
    $query->execute();

    $items = array_map(static function (array $row): array {
        $detalhes = json_decode((string) ($row['detalhes'] ?? '{}'), true);
        return [
            'id' => (int) $row['id'],
            'acao' => (string) $row['acao'],
            'detalhes' => is_array($detalhes) ? $detalhes : [],
            'ip' => (string) ($row['ip'] ?? ''),
            'user_agent' => (string) ($row['user_agent'] ?? ''),
            'criado_em' => (string) ($row['criado_em'] ?? ''),
            'ator' => [
                'nome' => (string) ($row['ator_nome'] ?? 'Sistema'),
                'email' => (string) ($row['ator_email'] ?? ''),
            ],
            'alvo' => [
                'nome' => (string) ($row['alvo_nome'] ?? ''),
                'email' => (string) ($row['alvo_email'] ?? ''),
            ],
        ];
    }, $query->fetchAll() ?: []);

    apiResponse(true, [
        'items' => $items,
        'available' => true,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => $pages,
            'period' => $period,
        ],
    ]);
} catch (Throwable $e) {
    apiHandle($e);
}
