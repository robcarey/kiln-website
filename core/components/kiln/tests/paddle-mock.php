<?php
/**
 * A stand-in for Paddle's API, for tests/paddle.php only:
 *
 *   KILN_PADDLE_MOCK=<state.json> php -S 127.0.0.1:8790 tests/paddle-mock.php
 *
 * Answers the calls PaddleProvider and _build/paddle-setup.php make, in Paddle's
 * shapes ({data, meta} or {error}), and keeps everything in the state file so
 * the test can read what was sent and change what Paddle "knows" (mark a
 * transaction paid, make the next call fail).
 */
$file = getenv('KILN_PADDLE_MOCK') ?: sys_get_temp_dir() . '/kiln-paddle-mock.json';
$s = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
$s += ['key' => 'pdl_sdbx_apikey_e2e', 'customers' => [], 'txns' => [], 'products' => [], 'notifications' => [], 'adjustments' => [], 'cancelled' => [], 'log' => [], 'fail' => []];

$method = $_SERVER['REQUEST_METHOD'];
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
parse_str((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $q);
$body = json_decode((string) file_get_contents('php://input'), true);

$id = static fn (string $prefix): string => $prefix . '_' . substr(str_shuffle(str_repeat('abcdefghjkmnpqrstvwxyz0123456789', 3)), 0, 26);
$reply = static function (int $code, array $json) use (&$s, $file): never {
    file_put_contents($file, json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($json, JSON_UNESCAPED_SLASHES);
    exit;
};
$ok = static fn ($data) => $reply(200, ['data' => $data, 'meta' => ['request_id' => 'mock']]);
$err = static fn (int $code, string $c, string $detail) => $reply($code, ['error' => ['type' => 'request_error', 'code' => $c, 'detail' => $detail]]);

if (str_starts_with($path, '/portal/')) {
    file_put_contents($file, json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    echo '<!doctype html><title>Mock Paddle portal</title><p>Mock customer portal for ' . htmlspecialchars(substr($path, 8)) . '</p>';
    exit;
}

$s['log'][] = ['method' => $method, 'path' => $path, 'query' => $q, 'body' => $body];
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer ' . $s['key']) {
    $err(403, 'forbidden', 'bad API key');
}
foreach ($s['fail'] as $i => $f) {
    if ($f === "$method $path") {
        unset($s['fail'][$i]);
        $err(400, 'bad_request', 'mock failure for ' . $f);
    }
}

switch (true) {
    case $method === 'GET' && $path === '/customers':
        $ok(array_values(array_filter($s['customers'], static fn ($c) => !isset($q['email']) || $c['email'] === $q['email'])));
    case $method === 'POST' && $path === '/customers':
        foreach ($s['customers'] as $c) {
            if ($c['email'] === ($body['email'] ?? '')) {
                $err(409, 'customer_already_exists', 'customer email conflicts with customer of id ' . $c['id']);
            }
        }
        $c = ['id' => $id('ctm'), 'email' => (string) $body['email'], 'status' => 'active'];
        $s['customers'][] = $c;
        $ok($c);
    case $method === 'POST' && $path === '/transactions':
        $t = ['id' => $id('txn'), 'status' => 'ready', 'origin' => 'api', 'customer_id' => $body['customer_id'] ?? null, 'subscription_id' => null,
            'currency_code' => $body['currency_code'] ?? 'USD', 'custom_data' => $body['custom_data'] ?? null, 'items' => $body['items'] ?? [], 'billing_period' => null,
            'details' => ['totals' => ['grand_total' => (string) array_sum(array_map(static fn ($i) => (int) $i['price']['unit_price']['amount'] * (int) $i['quantity'], $body['items'] ?? []))]]];
        $t['checkout'] = ['url' => 'https://kilndev.test/buy?_ptxn=' . $t['id']];
        $s['txns'][$t['id']] = $t;
        $ok($t);
    case $method === 'GET' && preg_match('#^/transactions/(txn_\w+)$#', $path, $m) === 1:
        isset($s['txns'][$m[1]]) ? $ok($s['txns'][$m[1]]) : $err(404, 'entity_not_found', 'no such transaction');
    case $method === 'POST' && $path === '/adjustments':
        $a = ['id' => $id('adj'), 'status' => 'pending_approval'] + (array) $body;
        $s['adjustments'][] = $a;
        $ok($a);
    case $method === 'POST' && preg_match('#^/subscriptions/(sub_\w+)/cancel$#', $path, $m) === 1:
        $s['cancelled'][] = $m[1];
        $ok(['id' => $m[1], 'status' => 'canceled']);
    case $method === 'POST' && preg_match('#^/customers/(ctm_\w+)/portal-sessions$#', $path, $m) === 1:
        $ok(['id' => $id('cpls'), 'customer_id' => $m[1], 'urls' => ['general' => ['overview' => 'http://' . $_SERVER['HTTP_HOST'] . '/portal/' . $m[1]], 'subscriptions' => []]]);
    case $method === 'GET' && preg_match('#^/products/(pro_\w+)$#', $path, $m) === 1:
        isset($s['products'][$m[1]]) ? $ok($s['products'][$m[1]]) : $err(404, 'entity_not_found', 'no such product');
    case $method === 'POST' && $path === '/products':
        $p = ['id' => $id('pro'), 'status' => 'active'] + (array) $body;
        $s['products'][$p['id']] = $p;
        $ok($p);
    case $method === 'GET' && $path === '/notification-settings':
        $ok(array_values($s['notifications']));
    case $method === 'POST' && $path === '/notification-settings':
        $n = ['id' => $id('ntfset'), 'active' => true, 'endpoint_secret_key' => 'pdl_ntfset_' . bin2hex(random_bytes(16))] + (array) $body;
        $n['subscribed_events'] = array_map(static fn ($e) => ['name' => $e], $body['subscribed_events'] ?? []);
        $s['notifications'][$n['id']] = $n;
        $ok($n);
}
$err(404, 'not_found', "mock has no $method $path");
