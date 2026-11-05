<?php

// A webhook receiver for e2e runs (servers.sh starts it on 127.0.0.1:8113 with
// `php -S ... webhook-receiver.php`). Every request is appended to the log as one
// JSON line: time, method, path, the X-Webhook-* headers and the raw body (what a
// signature is computed over). /fail answers 500, anything else 200 "ok".
// Read it with `servers.sh webhooks`.

$log = getenv('WEBHOOK_RECEIVER_LOG') ?: sys_get_temp_dir().'/react-saas-e2e/webhooks.log';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

$headers = [];
foreach (getallheaders() as $name => $value) {
    if (stripos($name, 'x-webhook-') === 0 || strcasecmp($name, 'content-type') === 0 || strcasecmp($name, 'user-agent') === 0) {
        $headers[strtolower($name)] = $value;
    }
}

file_put_contents($log, json_encode([
    'at' => gmdate('c'),
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
    'path' => $path,
    'headers' => $headers,
    'body' => file_get_contents('php://input'),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);

if ($path === '/fail') {
    http_response_code(500);
    echo 'receiver says no';

    return;
}

http_response_code(200);
echo 'ok';
