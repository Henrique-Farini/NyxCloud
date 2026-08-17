<?php

declare(strict_types=1);

namespace NyxCloud\Lib\Cache;

final class FileCache implements CacheInterface
{
    public function __construct(private readonly string $basePath)
    {
        if (!is_dir($this->basePath)) {
            mkdir($this->basePath, 0775, true);
        }
    }

    public function get(string $key): mixed
    {
        $file = $this->fileName($key);
        if (!is_readable($file)) {
            return null;
        }

        $payload = json_decode((string) file_get_contents($file), true);
        if (!is_array($payload) || ($payload['expires_at'] ?? 0) < time()) {
            $this->delete($key);
            return null;
        }

        return $payload['value'] ?? null;
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        file_put_contents($this->fileName($key), json_encode([
            'expires_at' => time() + $ttlSeconds,
            'value' => $value,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), LOCK_EX);
    }

    public function delete(string $key): void
    {
        $file = $this->fileName($key);
        if (is_file($file)) {
            unlink($file);
        }
    }

    private function fileName(string $key): string
    {
        return $this->basePath . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
    }
}

