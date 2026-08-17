<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'back' . DIRECTORY_SEPARATOR . 'config.php';

date_default_timezone_set((string) env('APP_TIMEZONE', 'America/Sao_Paulo'));

spl_autoload_register(static function (string $class): void {
    $prefix = 'NyxCloud\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

    if (is_readable($path)) {
        require_once $path;
    }
});
