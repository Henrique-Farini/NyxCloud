<?php
require_once __DIR__ . '/app/bootstrap.php';

use NyxCloud\Lib\Acronis\AcronisFactory;

$config = AcronisFactory::config();
$api = AcronisFactory::api($config);
$client = $api->get(str_replace('{client_id}', rawurlencode((string) $config['client_id']), (string) $config['endpoints']['client']));
echo json_encode($client, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
