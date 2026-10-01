<?php

declare(strict_types=1);

namespace NyxCloud\Services;

final class AcronisCredentialStore
{
    private string $path;
    private ?\PDO $pdo = null;
    private bool $useDatabase = false;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'acronis_accounts.json';
        if ($path === null) {
            global $pdo;
            if ($pdo instanceof \PDO) {
                $this->pdo = $pdo;
                $this->useDatabase = true;
            }
        }
    }

    public function listSafe(): array
    {
        $data = $this->read();
        $activeIds = $this->activeIds($data);
        $items = [];
        foreach ($data['accounts'] as $account) {
            $items[] = [
                'id' => $account['id'],
                'name' => $account['name'],
                'region' => $account['region'],
                'base_url' => $account['base_url'],
                'client_id' => $account['client_id'],
                'secret_set' => $this->decrypt((string) $account['client_secret']) !== '',
                'active' => in_array($account['id'], $activeIds, true),
                'updated_at' => $account['updated_at'] ?? '',
            ];
        }

        $native = $this->nativeAccount();
        if ($native !== null && !$this->hasEquivalentAccount($items, $native['base_url'], $native['client_id'])) {
            $items[] = [
                'id' => 'nativo',
                'name' => 'Nativo',
                'region' => $native['region'],
                'base_url' => $native['base_url'],
                'client_id' => $native['client_id'],
                'secret_set' => $native['client_secret'] !== '',
                'active' => in_array('nativo', $activeIds, true),
                'updated_at' => '',
            ];
        }

        return ['active_id' => $data['active_id'], 'active_ids' => $activeIds, 'items' => $items];
    }

    public function config(): array
    {
        $data = $this->read();
        $accounts = [];
        foreach ($data['accounts'] as $account) {
            $account['client_secret'] = $this->decrypt((string) ($account['client_secret'] ?? ''));
            $accounts[] = $account;
        }

        return [
            'active_id' => (string) ($data['active_id'] ?? ''),
            'active_ids' => $this->activeIds($data),
            'accounts' => $accounts,
        ];
    }

    public function activeConfig(): array
    {
        $data = $this->read();
        $activeIds = $this->activeIds($data);
        foreach ($data['accounts'] as $account) {
            if (!in_array($account['id'], $activeIds, true)) {
                continue;
            }

            return [
                'base_url' => $account['base_url'],
                'client_id' => $account['client_id'],
                'client_secret' => $this->decrypt((string) $account['client_secret']),
            ];
        }

        if (($data['active_id'] ?? '') !== '' && $data['active_id'] !== 'nativo') {
            foreach ($data['accounts'] as $account) {
                if ($account['id'] === $data['active_id']) {
                    return [
                        'base_url' => $account['base_url'],
                        'client_id' => $account['client_id'],
                        'client_secret' => $this->decrypt((string) $account['client_secret']),
                    ];
                }
            }
        }

        return [];
    }

    public function save(array $input): array
    {
        $data = $this->read();
        $name = trim((string) ($input['name'] ?? ''));
        $region = strtoupper(trim((string) ($input['region'] ?? 'BR')));
        $baseUrl = rtrim(trim((string) ($input['base_url'] ?? '')), '/');
        $clientId = trim((string) ($input['client_id'] ?? ''));
        $clientSecret = (string) ($input['client_secret'] ?? '');
        $providedId = $this->slug((string) ($input['id'] ?? ''));

        if ($name === '' || $baseUrl === '' || $clientId === '') {
            throw new \InvalidArgumentException('Nome, URL e client ID sao obrigatorios.');
        }

        $resolvedId = $providedId !== '' ? $this->resolveAccountId($data['accounts'], $providedId) : null;
        $id = $resolvedId ?? ($providedId !== '' ? $providedId : $this->generateUniqueAccountId($name, $region, $data['accounts']));
        if (!preg_match('/^https:\/\/[a-z0-9.-]+/i', $baseUrl)) {
            throw new \InvalidArgumentException('URL da Acronis deve iniciar com https://.');
        }

        $found = false;
        foreach ($data['accounts'] as &$account) {
            if ($account['id'] !== $id) {
                continue;
            }
            $found = true;
            $account['name'] = $name;
            $account['region'] = $region;
            $account['base_url'] = $baseUrl;
            $account['client_id'] = $clientId;
            if ($clientSecret !== '') {
                $account['client_secret'] = $this->encrypt($clientSecret);
            }
            $account['updated_at'] = gmdate(DATE_ATOM);
            break;
        }
        unset($account);

        if (!$found) {
            if ($clientSecret === '') {
                throw new \InvalidArgumentException('Client secret obrigatorio para nova integracao.');
            }
            $data['accounts'][] = [
                'id' => $id,
                'name' => $name,
                'region' => $region,
                'base_url' => $baseUrl,
                'client_id' => $clientId,
                'client_secret' => $this->encrypt($clientSecret),
                'updated_at' => gmdate(DATE_ATOM),
            ];
        }

        $activeIds = $this->activeIds($data);
        if (($input['active'] ?? false) && !in_array($id, $activeIds, true)) {
            $activeIds[] = $id;
        }
        $data['active_ids'] = $activeIds;
        if ($data['active_id'] === '') {
            $data['active_id'] = $id;
        }

        $this->write($data);
        $this->clearAcronisCache();

        return $this->listSafe();
    }

    public function activate(string $id, bool $active = true): array
    {
        $id = $this->slug($id);
        $data = $this->read();
        $activeIds = $this->activeIds($data);

        if ($id === 'nativo') {
            if ($active) {
                if (!in_array('nativo', $activeIds, true)) {
                    $activeIds[] = 'nativo';
                }
            } else {
                $activeIds = array_values(array_filter($activeIds, static fn (string $value): bool => $value !== 'nativo'));
            }
            $data['active_ids'] = $activeIds;
            $data['active_id'] = $activeIds[0] ?? '';
            $this->write($data);
            $this->clearAcronisCache();
            return $this->listSafe();
        }

        $resolvedId = $this->resolveAccountId($data['accounts'], $id) ?? $id;
        foreach ($data['accounts'] as $account) {
            if ($account['id'] !== $resolvedId) {
                continue;
            }

            if ($active) {
                if (!in_array($account['id'], $activeIds, true)) {
                    $activeIds[] = $account['id'];
                }
            } else {
                $activeIds = array_values(array_filter($activeIds, static fn (string $value): bool => $value !== $account['id']));
            }

            $data['active_ids'] = $activeIds;
            $data['active_id'] = $activeIds[0] ?? '';
            $this->write($data);
            $this->clearAcronisCache();
            return $this->listSafe();
        }

        throw new \InvalidArgumentException('Integracao nao encontrada.');
    }

    public function delete(string $id): array
    {
        $id = $this->slug($id);
        $data = $this->read();
        $resolvedId = $this->resolveAccountId($data['accounts'], $id) ?? $id;
        $data['accounts'] = array_values(array_filter(
            $data['accounts'],
            static fn (array $account): bool => $account['id'] !== $resolvedId
        ));
        $data['active_ids'] = array_values(array_filter(
            $this->activeIds($data),
            static fn (string $value): bool => $value !== $resolvedId
        ));
        if ($data['active_id'] === $resolvedId) {
            $data['active_id'] = $data['active_ids'][0] ?? '';
        }
        $this->write($data);
        $this->clearAcronisCache();

        return $this->listSafe();
    }

    private function read(): array
    {
        if ($this->useDatabase) {
            return $this->readDatabase();
        }

        if (!is_file($this->path)) {
            return ['active_id' => '', 'active_ids' => [], 'accounts' => []];
        }

        $data = json_decode((string) file_get_contents($this->path), true);
        if (!is_array($data)) {
            return ['active_id' => '', 'active_ids' => [], 'accounts' => []];
        }

        return [
            'active_id' => (string) ($data['active_id'] ?? ''),
            'active_ids' => $this->normalizeActiveIds($data['active_ids'] ?? ($data['active_id'] ?? '')),
            'accounts' => array_values(array_filter($data['accounts'] ?? [], 'is_array')),
        ];
    }

    private function write(array $data): void
    {
        if ($this->useDatabase) {
            $this->writeDatabase($data);
            return;
        }

        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($this->path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    private function readDatabase(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, nome, regiao, base_url, client_id, client_secret_encrypted, ativo, atualizado_em
             FROM acronis_integracao ORDER BY nome ASC'
        );
        $rows = $stmt->fetchAll() ?: [];

        if ($rows === []) {
            $this->importLegacyJson();
            $stmt = $this->pdo->query(
                'SELECT id, nome, regiao, base_url, client_id, client_secret_encrypted, ativo, atualizado_em
                 FROM acronis_integracao ORDER BY nome ASC'
            );
            $rows = $stmt->fetchAll() ?: [];
        }

        $accounts = [];
        $activeIds = [];
        foreach ($rows as $row) {
            $id = (string) $row['id'];
            $accounts[] = [
                'id' => $id,
                'name' => (string) $row['nome'],
                'region' => (string) $row['regiao'],
                'base_url' => rtrim((string) $row['base_url'], '/'),
                'client_id' => (string) $row['client_id'],
                'client_secret' => (string) $row['client_secret_encrypted'],
                'updated_at' => (string) ($row['atualizado_em'] ?? ''),
            ];
            if ((bool) $row['ativo']) {
                $activeIds[] = $id;
            }
        }

        return [
            'active_id' => $activeIds[0] ?? '',
            'active_ids' => $activeIds,
            'accounts' => $accounts,
        ];
    }

    private function writeDatabase(array $data): void
    {
        $this->pdo->beginTransaction();
        try {
            foreach ((array) ($data['accounts'] ?? []) as $account) {
                $id = (string) ($account['id'] ?? '');
                if ($id === '') {
                    continue;
                }

                $exists = $this->pdo->prepare('SELECT 1 FROM acronis_integracao WHERE id = :id');
                $exists->execute(['id' => $id]);
                $active = in_array($id, $this->activeIds($data), true);
                $params = [
                    'id' => $id,
                    'nome' => (string) ($account['name'] ?? ''),
                    'regiao' => (string) ($account['region'] ?? 'BR'),
                    'base_url' => rtrim((string) ($account['base_url'] ?? ''), '/'),
                    'client_id' => (string) ($account['client_id'] ?? ''),
                    'secret' => (string) ($account['client_secret'] ?? ''),
                    'ativo' => $active ? 1 : 0,
                ];
                if ($exists->fetchColumn()) {
                    $sql = 'UPDATE acronis_integracao
                            SET nome=:nome, regiao=:regiao, base_url=:base_url, client_id=:client_id,
                                client_secret_encrypted=:secret, ativo=:ativo, atualizado_em=CURRENT_TIMESTAMP
                            WHERE id=:id';
                } else {
                    $sql = 'INSERT INTO acronis_integracao
                            (id, nome, regiao, base_url, client_id, client_secret_encrypted, ativo)
                            VALUES (:id, :nome, :regiao, :base_url, :client_id, :secret, :ativo)';
                }
                $this->pdo->prepare($sql)->execute($params);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function importLegacyJson(): void
    {
        if (!is_file($this->path)) {
            return;
        }
        $data = json_decode((string) file_get_contents($this->path), true);
        if (!is_array($data) || !is_array($data['accounts'] ?? null)) {
            return;
        }
        $activeIds = $this->normalizeActiveIds($data['active_ids'] ?? ($data['active_id'] ?? ''));
        $native = $this->nativeAccount();
        if ($native !== null && !$this->hasEquivalentAccount($data['accounts'], $native['base_url'], $native['client_id'])) {
            $data['accounts'][] = [
                'id' => 'nativo',
                'name' => $native['name'],
                'region' => $native['region'],
                'base_url' => $native['base_url'],
                'client_id' => $native['client_id'],
                'client_secret' => $this->encrypt($native['client_secret']),
                'updated_at' => gmdate(DATE_ATOM),
            ];
        }
        $this->writeDatabase([
            'accounts' => $data['accounts'],
            'active_ids' => $activeIds,
        ]);
    }

    private function encrypt(string $value): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($value, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Falha ao criptografar secret.');
        }

        return 'v1:' . base64_encode($iv . $tag . $cipher);
    }

    private function decrypt(string $value): string
    {
        if (!str_starts_with($value, 'v1:')) {
            return '';
        }

        $raw = base64_decode(substr($value, 3), true);
        if ($raw === false || strlen($raw) < 29) {
            return '';
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag);

        return is_string($plain) ? $plain : '';
    }

    private function key(): string
    {
        $secret = (string) \env('JWT_SECRET', '');
        if (strlen($secret) < 32) {
            throw new \RuntimeException('JWT_SECRET ausente ou fraca.');
        }

        return hash('sha256', $secret, true);
    }

    private function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_-]+/', '-', $value) ?? '';
        return trim($value, '-');
    }

    private function generateUniqueAccountId(string $name, string $region, array $accounts): string
    {
        $base = $this->slug($region !== '' ? $region . '-' . $name : $name);
        if ($base === '') {
            $base = 'acronis';
        }

        $ids = array_map(static fn (array $account): string => (string) ($account['id'] ?? ''), $accounts);
        if (!in_array($base, $ids, true)) {
            return $base;
        }

        $suffix = 2;
        do {
            $candidate = $base . '-' . $suffix;
            $suffix++;
        } while (in_array($candidate, $ids, true));

        return $candidate;
    }

    private function nativeAccount(): ?array
    {
        $baseUrl = rtrim(trim((string) env('ACRONIS_BASE_URL', '')), '/');
        $clientId = trim((string) env('ACRONIS_CLIENT_ID', ''));
        $clientSecret = trim((string) env('ACRONIS_CLIENT_SECRET', ''));
        if ($baseUrl === '' || $clientId === '') {
            return null;
        }

        return [
            'id' => 'nativo',
            'name' => 'Nativo',
            'region' => strtoupper(trim((string) env('ACRONIS_REGION', 'NATIVO')) ?: 'NATIVO'),
            'base_url' => $baseUrl,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ];
    }

    private function resolveAccountId(array $accounts, string $selector): ?string
    {
        $needle = $this->slug($selector);
        if ($needle === '') {
            return null;
        }

        foreach ($accounts as $account) {
            if (!is_array($account)) {
                continue;
            }

            $id = (string) ($account['id'] ?? '');
            if ($this->slug($id) === $needle) {
                return $id;
            }
        }

        foreach ($accounts as $account) {
            if (!is_array($account)) {
                continue;
            }

            $id = (string) ($account['id'] ?? '');
            $name = (string) ($account['name'] ?? '');
            $region = (string) ($account['region'] ?? '');
            $label = $this->slug($region . '-' . $name);
            if ($label === $needle || $this->slug($region) === $needle || $this->slug($name) === $needle) {
                return $id;
            }
        }

        return null;
    }

    private function activeIds(array $data): array
    {
        $raw = $data['active_ids'] ?? ($data['active_id'] ?? '');
        return $this->normalizeActiveIds($raw);
    }

    private function normalizeActiveIds(mixed $value): array
    {
        $values = [];
        if (is_string($value)) {
            $value = trim($value);
            if ($value !== '') {
                $values[] = $value;
            }
        } elseif (is_array($value)) {
            foreach ($value as $entry) {
                $entry = trim((string) $entry);
                if ($entry !== '') {
                    $values[] = $entry;
                }
            }
        }

        return array_values(array_unique($values));
    }

    private function hasEquivalentAccount(array $items, string $baseUrl, string $clientId): bool
    {
        foreach ($items as $item) {
            if ((string) ($item['base_url'] ?? '') === $baseUrl || (string) ($item['client_id'] ?? '') === $clientId) {
                return true;
            }
        }

        return false;
    }

    private function clearAcronisCache(): void
    {
        $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'acronis';
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
}
