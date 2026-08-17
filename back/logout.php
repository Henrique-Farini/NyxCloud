<?php

declare(strict_types=1);

require_once __DIR__ . '/auth/jwt.php';

limparCookieJwt();

header('Cache-Control: no-store');
header('Location: index.php?logout=1');
exit;
