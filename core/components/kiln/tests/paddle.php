<?php
/**
 * Paddle end to end, through the real site (https://kilndev.test) against a
 * stand-in Paddle API (tests/paddle-mock.php on 127.0.0.1:8790), with webhooks
 * signed the way Paddle signs them:
 *
 *  - _build/paddle-setup.php makes the products and the webhook destination;
 *  - the sales bar's problems (missing or mismatched keys);
 *  - checkout creates a Paddle customer and a transaction whose prices come from
 *    the site's settings, then opens Paddle.js on /buy?checkout=REF;
 *  - the order page asks Paddle (reconcile) and fulfils without waiting for
 *    the webhook; the webhook then changes nothing;
 *  - signatures: wrong, stale and missing are refused;
 *  - subscription.created links the subscription; "Manage billing" opens the portal;
 *  - a renewal (no custom_data, found by subscription) moves the keys on;
 *  - scheduled cancel / resume; a refund requested from the manager reaches
 *    Paddle, and the approved adjustment revokes everything and cancels the
 *    subscription; partial refunds are left alone;
 *  - cancel at checkout brings the cart back; a Paddle error keeps the cart;
 *  - /buy?_ptxn=… (Paddle's own links) opens Paddle.js signed out.
 *
 *   php core/components/kiln/tests/paddle.php
 *
 * It puts every setting back and deletes what it made.
 */
declare(strict_types=1);

use KilnWeb\Kiln;
use MODX\Revolution\modSystemSetting;
use MODX\Revolution\modUser;
use MODX\Revolution\modX;

const SITE = 'https://kilndev.test';
const MAILPIT = 'http://127.0.0.1:8025';
const LIC_DIR = 'H:/SITES/KILN SERVER/licensing';
const MOCK_PORT = 8790;

$failures = 0;
function check(bool $ok, string $text): void
{
    global $failures;
    if (!$ok) {
        $failures++;
    }
    echo '  ' . ($ok ? 'PASS' : 'FAIL') . "  $text\n";
}

final class Browser
{
    private string $jar;

    public function __construct()
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'kiln-paddle');
    }

    /** @return array{0: int, 1: string, 2: string} status, body, final URL */
    public function go(string $path, ?array $post = null): array
    {
        $ch = curl_init(str_starts_with($path, 'http') ? $path : SITE . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 6,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_TIMEOUT => 30,
        ]);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $body = (string) curl_exec($ch);
        return [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), $body, (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL)];
    }

    public function submit(string $path, array $fields): array
    {
        [, $page] = $this->go($path);
        preg_match('/name="csrf" value="([0-9a-f]+)"/', $page, $m);
        return $this->go($path, $fields + ['csrf' => $m[1] ?? '']);
    }

    public function done(): void
    {
        @unlink($this->jar);
    }
}

function mailpit(string $path): array
{
    $ch = curl_init(MAILPIT . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    return json_decode((string) curl_exec($ch), true) ?? [];
}

function mailTo(string $to, string $re): ?array
{
    for ($i = 0; $i < 20; $i++) {
        foreach (mailpit('/api/v1/search?query=' . rawurlencode('to:"' . $to . '"'))['messages'] ?? [] as $msg) {
            if (preg_match($re, $msg['Subject'])) {
                return mailpit('/api/v1/message/' . $msg['ID']);
            }
        }
        usleep(250000);
    }
    return null;
}

/** What the stand-in Paddle holds; change it with mock(fn (&$s) => …). */
function mock(?callable $change = null): array
{
    global $mockFile;
    $s = json_decode((string) file_get_contents($mockFile), true) ?: [];
    if ($change) {
        $change($s);
        file_put_contents($mockFile, json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
    return $s;
}

/** POST a Paddle event to the site, signed with $secret (or a given header). */
function paddleHook(string $type, array $data, string $secret, ?string $sigHeader = null): array
{
    $body = json_encode(['event_id' => 'evt_' . bin2hex(random_bytes(13)), 'event_type' => $type, 'occurred_at' => gmdate('c'), 'notification_id' => 'ntf_x', 'data' => $data], JSON_UNESCAPED_SLASHES);
    $ts = (string) time();
    $sig = $sigHeader ?? "ts=$ts;h1=" . hash_hmac('sha256', "$ts:$body", $secret);
    $ch = curl_init(SITE . '/payment-webhook?provider=paddle');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Paddle-Signature: ' . $sig],
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_TIMEOUT => 30]);
    $out = (string) curl_exec($ch);
    return [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode($out, true)['message'] ?? $out, $body];
}

// ---- set up ---------------------------------------------------------------------------
define('MODX_API_MODE', true);
require dirname(__DIR__, 4) . '/config.core.php';
require MODX_CORE_PATH . 'vendor/autoload.php';
$modx = new modX();
$modx->initialize('web');
$kiln = Kiln::get($modx);
$api = $kiln->licenses();

$mockFile = sys_get_temp_dir() . '/kiln-paddle-mock-' . bin2hex(random_bytes(4)) . '.json';
file_put_contents($mockFile, '{}');
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . MOCK_PORT, __DIR__ . '/paddle-mock.php'],
    [['pipe', 'r'], ['file', sys_get_temp_dir() . '/kiln-paddle-mock.log', 'a'], ['file', sys_get_temp_dir() . '/kiln-paddle-mock.log', 'a']],
    $pipes, null, ['KILN_PADDLE_MOCK' => $mockFile] + getenv()
);
for ($i = 0; $i < 40 && !@fsockopen('127.0.0.1', MOCK_PORT); $i++) {
    usleep(100000);
}

$created = [];
$settings = function (array $values, bool $wait = true) use ($modx, &$created): void {
    foreach ($values as $k => $v) {
        $s = $modx->getObject(modSystemSetting::class, ['key' => $k]);
        if (!$s) {
            $s = $modx->newObject(modSystemSetting::class);
            $s->fromArray(['key' => $k, 'namespace' => 'kiln', 'area' => 'Paddle', 'xtype' => 'textfield'], '', true);
            $created[] = $k;
        }
        $s->set('value', $v);
        $s->save();
        $modx->setOption($k, $v);
    }
    $modx->cacheManager->refresh();
    if ($wait) {
        sleep(3); // the web server's opcache rechecks MODX's cached settings file every 2 s
    }
};
$keys = ['kiln.sales', 'kiln.payments_test_mode', 'kiln.payment_provider', 'kiln.price_annual', 'kiln.price_lifetime', 'kiln.price_team_seat', 'kiln.team_min_seats', 'kiln.currency',
    'kiln.paddle_env', 'kiln.paddle_api_key', 'kiln.paddle_client_token', 'kiln.paddle_webhook_secret', 'kiln.paddle_product_annual', 'kiln.paddle_product_lifetime', 'kiln.paddle_product_team'];
$keep = [];
foreach ($keys as $k) {
    $s = $modx->getObject(modSystemSetting::class, ['key' => $k]);
    $keep[$k] = $s ? (string) $s->get('value') : null;
}

echo "paddle end to end\n";
$settings([
    'kiln.payment_provider' => 'paddle', 'kiln.payments_test_mode' => '0', 'kiln.sales' => 'open',
    'kiln.price_annual' => '49', 'kiln.price_lifetime' => '129', 'kiln.price_team_seat' => '39', 'kiln.team_min_seats' => '3', 'kiln.currency' => 'USD',
    'kiln.paddle_env' => 'sandbox', 'kiln.paddle_api_key' => 'pdl_sdbx_apikey_e2e', 'kiln.paddle_client_token' => 'live_wrongenv',
    'kiln.paddle_webhook_secret' => '', 'kiln.paddle_product_annual' => '', 'kiln.paddle_product_lifetime' => '', 'kiln.paddle_product_team' => '',
    'kiln.paddle_api_url' => 'http://127.0.0.1:' . MOCK_PORT,
], false);

// ---- not ready yet, then setup ---------------------------------------------------------------
$problems = implode(' ', $kiln->shop()->provider()->problems());
check(str_contains($problems, 'not a sandbox token') && str_contains($problems, 'Webhook secret') && str_contains($problems, 'paddle-setup.php'), 'not ready: wrong-environment token, no webhook secret, no products');

exec('"' . PHP_BINARY . '" "' . dirname(__DIR__) . '/_build/paddle-setup.php" --webhook=' . escapeshellarg(SITE . '/payment-webhook?provider=paddle') . ' 2>&1', $out, $rc);
$out = implode("\n", $out);
$m = mock();
check($rc === 0 && count($m['products']) === 3 && count($m['notifications']) === 1, 'paddle-setup.php made 3 products and one webhook destination');
$hook = array_values($m['notifications'])[0] ?? [];
check(count($hook['subscribed_events'] ?? []) === 6 && ($hook['traffic_source'] ?? '') === 'all' && !str_contains($out, (string) ($hook['endpoint_secret_key'] ?? 'x')), 'the destination has the six events, and the secret is never printed');
exec('"' . PHP_BINARY . '" "' . dirname(__DIR__) . '/_build/paddle-setup.php" --webhook=' . escapeshellarg(SITE . '/payment-webhook?provider=paddle') . ' 2>&1', $out2);
check(count(mock()['products']) === 3 && count(mock()['notifications']) === 1 && str_contains(implode("\n", $out2), '= annual'), 'running it again makes nothing new');
$modx->getCacheManager()->refresh();
$secret = (string) $modx->getObject(modSystemSetting::class, ['key' => 'kiln.paddle_webhook_secret'])->get('value');
$made = [];
foreach (['annual', 'lifetime', 'team'] as $plan) {
    $made["kiln.paddle_product_$plan"] = (string) $modx->getObject(modSystemSetting::class, ['key' => "kiln.paddle_product_$plan"])->get('value');
}
$settings(['kiln.paddle_client_token' => 'test_e2etoken', 'kiln.paddle_webhook_secret' => $secret] + $made);
check($kiln->shop()->provider()->ready() && $kiln->shop()->testMode(), 'with a sandbox token Paddle is ready, and the shop counts it as test mode');

$run = bin2hex(random_bytes(3));
$email = "e2e-$run-payer@example.test";
$password = 'payer pass ' . $run;
$user = $kiln->accounts()->create($email, 'Pat Payer', true);
$kiln->accounts()->setPassword($user, $password);
$uid = (int) $user->get('id');

// ---- checkout ------------------------------------------------------------------------------------
$b = new Browser();
$b->submit('/account/sign-in', ['email' => $email, 'password' => $password, 'mode' => 'password']);
$b->submit('/buy', ['do' => 'add', 'plan' => 'annual']);
$b->submit('/buy', ['do' => 'add', 'plan' => 'lifetime']);
[, $page] = $b->submit('/buy', ['do' => 'add', 'plan' => 'team', 'seats' => '3']);
check(str_contains($page, '$295 USD'), 'cart: $49 + $129 + 3 × $39 = $295');
[, $page, $url] = $b->submit('/buy', ['do' => 'checkout']);
$ref = preg_match('/checkout=(K-[A-Z0-9]{8})/', $url, $mm) ? $mm[1] : '';
$o = $kiln->shop()->order($ref);
check($ref !== '' && $o && str_starts_with((string) $o['provider_ref'], 'txn_') && str_starts_with((string) $o['provider_customer'], 'ctm_'), "checkout made order $ref with a Paddle transaction and customer");
check(str_contains($page, 'cdn.paddle.com/paddle/v2/paddle.js') && str_contains($page, 'js/kiln-paddle.js') && str_contains($page, 'data-txn="' . $o['provider_ref'] . '"')
    && str_contains($page, 'data-token="test_e2etoken"') && str_contains($page, 'data-env="sandbox"'), 'the checkout page loads Paddle.js with the transaction, token and environment');
check(str_contains($page, 'Paddle sandbox') && str_contains($page, '4242 4242 4242 4242') && str_contains($page, 'Total before tax'), 'it says sandbox, gives the test card and says tax comes on top');
$m = mock();
$txn = $m['txns'][$o['provider_ref']] ?? [];
$byName = [];
foreach ($txn['items'] ?? [] as $it) {
    $byName[$it['price']['description']] = $it;
}
$a = $byName['Kiln Pro · Annual'] ?? null;
$l = $byName['Kiln Pro · Lifetime'] ?? null;
$t = $byName['Kiln Pro · Team'] ?? null;
check($a && $l && $t && $a['price']['unit_price']['amount'] === '4900' && $l['price']['unit_price']['amount'] === '12900' && $t['price']['unit_price']['amount'] === '3900' && $t['quantity'] === 3,
    'Paddle got the site’s prices: 4900, 12900, 3900 × 3 seats');
check(isset($a['price']['billing_cycle']) && isset($t['price']['billing_cycle']) && !isset($l['price']['billing_cycle']) && $a['price']['billing_cycle']['interval'] === 'year', 'Annual and Team renew yearly, Lifetime is one-off');
check(($txn['custom_data']['kiln_order'] ?? '') === $ref && ($a['price']['product_id'] ?? '') === (string) $kiln->opt('kiln.paddle_product_annual'), 'the transaction carries the order ref and the Kiln products');
$customers = count($m['customers']);

// ---- paid: reconcile, then the webhook ---------------------------------------------------------------
[, $page] = $b->go('/buy?order=' . $ref);
check(str_contains($page, 'Waiting for your payment') && str_contains($page, 'Return to checkout'), 'before Paddle has the money the order page waits and offers the checkout again');
$periodEnd = gmdate('Y-m-d', strtotime('+1 year'));
mock(function (&$s) use ($o, $periodEnd) {
    $s['txns'][$o['provider_ref']]['status'] = 'completed';
    $s['txns'][$o['provider_ref']]['origin'] = 'web';
    $s['txns'][$o['provider_ref']]['billing_period'] = ['starts_at' => gmdate('Y-m-d\TH:i:s\Z'), 'ends_at' => $periodEnd . 'T10:00:00.000Z'];
});
[, $page] = $b->go('/buy?order=' . $ref);
$o = $kiln->shop()->order($ref);
check(str_contains($page, 'Your order is complete') && $o['status'] === 'fulfilled', 'once Paddle says completed, the order page fulfils it straight away (no webhook needed)');
check($o['period_end'] === $periodEnd, "the period end is Paddle's billing period ($periodEnd)");
$mine = $api->call('GET', 'licenses?orderRef=' . $ref);
check(count($mine) === 2 && ($api->call('GET', 'teams?ownerEmail=' . rawurlencode($email))[0]['seats'] ?? 0) === 3, 'an annual key, a lifetime key and a 3-seat team were issued');
$mail = mailTo($email, '/^Your Kiln order ' . preg_quote($ref, '/') . '$/');
check($mail !== null && str_contains($mail['HTML'] ?? '', 'Paddle, who handles payment for us') && str_contains($mail['HTML'] ?? '', 'Test order'), 'the receipt mentions Paddle’s own tax receipt, and that it is a sandbox (test) order');

$completed = mock()['txns'][$o['provider_ref']];
[$code, $msg] = paddleHook('transaction.completed', $completed, $secret);
check($code === 200 && $msg === 'already fulfilled', 'the webhook arriving afterwards changes nothing');
check(count($api->call('GET', 'licenses?orderRef=' . $ref)) === 2, 'and mints nothing twice');
[$code] = paddleHook('transaction.completed', $completed, 'wrong-secret');
check($code === 401, 'a webhook signed with the wrong secret is refused');
$old = (string) (time() - 3600);
[$code] = paddleHook('transaction.completed', $completed, $secret, "ts=$old;h1=" . str_repeat('a', 64));
check($code === 401, 'a stale signature is refused');
[$code] = paddleHook('transaction.completed', $completed, $secret, '');
check($code === 401, 'no signature is refused');

// ---- subscription, billing portal --------------------------------------------------------------------
$sub = 'sub_' . substr(bin2hex(random_bytes(13)), 0, 26);
[$code, $msg] = paddleHook('subscription.created', ['id' => $sub, 'status' => 'active', 'customer_id' => $o['provider_customer'], 'transaction_id' => $o['provider_ref'], 'custom_data' => ['kiln_order' => $ref]], $secret);
check($code === 200 && $msg === 'linked' && $kiln->shop()->order($ref)['provider_sub'] === $sub, 'subscription.created links the subscription to the order');
[, $page] = $b->go('/account/');
check(str_contains($page, 'Manage billing'), 'the account page offers “Manage billing”');
[, $page, $url] = $b->submit('/account/', ['do' => 'billing', 'order' => $ref]);
check(str_contains($url, '127.0.0.1:' . MOCK_PORT . '/portal/' . $o['provider_customer']) && str_contains($page, 'Mock customer portal'), 'and it opens Paddle’s customer portal for that customer');

// ---- renewal, cancel, resume -------------------------------------------------------------------------------
$renewEnd = gmdate('Y-m-d', strtotime('+2 years'));
$renewTxn = 'txn_' . substr(bin2hex(random_bytes(13)), 0, 26);
[$code, $msg] = paddleHook('transaction.completed', ['id' => $renewTxn, 'status' => 'completed', 'origin' => 'subscription_recurring', 'subscription_id' => $sub, 'customer_id' => $o['provider_customer'],
    'custom_data' => null, 'billing_period' => ['starts_at' => $periodEnd . 'T10:00:00Z', 'ends_at' => $renewEnd . 'T10:00:00Z'], 'details' => ['totals' => ['grand_total' => '16600']]], $secret);
check($code === 200 && $msg === 'renewed to ' . $renewEnd, 'a renewal found by its subscription (no custom_data) moves the order on to ' . $renewEnd);
$annual = array_values(array_filter($api->call('GET', 'licenses?orderRef=' . $ref), fn ($x) => $x['plan'] === 'annual'))[0];
check($annual['exp'] === gmdate('Y-m-d', strtotime($renewEnd . ' +14 days')), 'the annual key is re-signed for the new year (+14 days grace)');
check(mailTo($email, '/^Your Kiln Pro renewal/') !== null, 'and the renewal is emailed');

[$code, $msg] = paddleHook('subscription.updated', ['id' => $sub, 'status' => 'active', 'scheduled_change' => ['action' => 'cancel', 'effective_at' => $renewEnd . 'T10:00:00Z']], $secret);
check($code === 200 && $msg === 'renewal cancelled' && (int) $kiln->shop()->order($ref)['auto_renew'] === 0, 'cancelling in the portal (scheduled cancel) turns renewal off');
[$code, $msg] = paddleHook('subscription.updated', ['id' => $sub, 'status' => 'active', 'scheduled_change' => null], $secret);
check($code === 200 && $msg === 'renewal back on' && (int) $kiln->shop()->order($ref)['auto_renew'] === 1, 'taking the cancel back turns it on again');

// ---- refunds --------------------------------------------------------------------------------------------------
[$code, $msg] = paddleHook('adjustment.created', ['id' => 'adj_partial', 'action' => 'refund', 'type' => 'partial', 'status' => 'approved', 'transaction_id' => $renewTxn, 'subscription_id' => $sub], $secret);
check($code === 200 && $msg === 'ignored' && $kiln->shop()->order($ref)['status'] === 'fulfilled', 'a partial refund is left for Rob to handle by hand');
$o = $kiln->shop()->order($ref);
check($kiln->shop()->requestRefund($o, 'admin:e2e') === true, 'refunding from the manager asks Paddle for the refund');
$adj = mock()['adjustments'][0] ?? [];
check(($adj['transaction_id'] ?? '') === $renewTxn && ($adj['type'] ?? '') === 'full' && ($adj['action'] ?? '') === 'refund', 'for the latest charge (the renewal), in full');
check($kiln->shop()->order($ref)['status'] === 'fulfilled', 'the keys keep working until Paddle approves it');
[$code, $msg] = paddleHook('adjustment.updated', ['id' => 'adj_full', 'action' => 'refund', 'type' => 'full', 'status' => 'pending_approval', 'transaction_id' => $renewTxn, 'subscription_id' => $sub], $secret);
check($code === 200 && $msg === 'ignored', 'a refund still pending approval changes nothing');
[$code, $msg] = paddleHook('adjustment.updated', ['id' => 'adj_full', 'action' => 'refund', 'type' => 'full', 'status' => 'approved', 'transaction_id' => $renewTxn, 'subscription_id' => $sub], $secret);
check($code === 200 && $msg === 'refunded' && $kiln->shop()->order($ref)['status'] === 'refunded', 'the approved refund marks the order refunded');
check(count(array_filter($api->call('GET', 'licenses?orderRef=' . $ref), fn ($x) => $x['status'] === 'revoked')) === 2, 'and revokes its keys');
check(in_array($sub, mock()['cancelled'], true), 'and cancels the subscription at Paddle so it never charges again');

// ---- cancel, errors, Paddle's own links --------------------------------------------------------------------
$b->submit('/buy', ['do' => 'add', 'plan' => 'lifetime']);
[, , $url] = $b->submit('/buy', ['do' => 'checkout']);
$ref2 = preg_match('/checkout=(K-[A-Z0-9]{8})/', $url, $mm) ? $mm[1] : '';
check(count(mock()['customers']) === $customers, 'a second checkout reuses the same Paddle customer');
[, $page] = $b->submit('/buy?checkout=' . $ref2, ['do' => 'cancel', 'order' => $ref2]);
check(str_contains($page, 'Checkout cancelled') && str_contains($page, 'Your cart') && $kiln->shop()->order($ref2)['status'] === 'cancelled', 'cancel order: cancelled, cart back');
mock(function (&$s) {
    $s['fail'][] = 'POST /transactions';
});
[, $page] = $b->submit('/buy', ['do' => 'checkout']);
check(str_contains($page, 'We couldn’t start the checkout') && str_contains($page, 'Your cart'), 'when Paddle errors, the buyer is told and keeps the cart');

$signedOut = new Browser();
[, $page] = $signedOut->go('/buy?_ptxn=' . $renewTxn);
check(str_contains($page, 'cdn.paddle.com/paddle/v2/paddle.js') && str_contains($page, 'data-txn="' . $renewTxn . '"') && str_contains($page, 'data-auto="0"'), 'a Paddle link (/buy?_ptxn=…) loads Paddle.js for that transaction, even signed out');
[, $page] = $signedOut->go('/buy?_ptxn=' . urlencode('txn_<script>'));
check(!str_contains($page, 'paddle.js'), 'a malformed _ptxn is ignored');

// ---- clean up ----------------------------------------------------------------------------------------------
proc_terminate($server);
foreach ($keep as $k => $v) {
    if ($v === null) {
        continue;
    }
    $s = $modx->getObject(modSystemSetting::class, ['key' => $k]);
    $s->set('value', $v);
    $s->save();
}
foreach (array_unique(array_merge($created, ['kiln.paddle_api_url'])) as $k) {
    if ($s = $modx->getObject(modSystemSetting::class, ['key' => $k])) {
        $s->remove();
    }
}
$modx->cacheManager->refresh();
$db = $kiln->store();
foreach ($db->all('SELECT id, ref FROM ' . $db->t('orders') . ' WHERE email LIKE ?', ["e2e-$run%"]) as $row) {
    $db->run('DELETE FROM ' . $db->t('order_items') . ' WHERE order_id = ?', [$row['id']]);
    $db->run('DELETE FROM ' . $db->t('payment_events') . ' WHERE order_ref = ?', [$row['ref']]);
    $db->run('DELETE FROM ' . $db->t('orders') . ' WHERE id = ?', [$row['id']]);
}
foreach ($modx->getCollection(modUser::class, ['username:LIKE' => "e2e-$run%"]) as $u) {
    $u->remove();
}
$cfg = require LIC_DIR . '/config.php';
$pdo = new PDO($cfg['db']['dsn'], $cfg['db']['user'], $cfg['db']['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->prepare('DELETE FROM audit_log WHERE license_id IN (SELECT id FROM licenses WHERE email LIKE ?) OR team_id IN (SELECT id FROM teams WHERE owner_email LIKE ?)')->execute(["e2e-$run%", "e2e-$run%"]);
$pdo->prepare('DELETE FROM licenses WHERE email LIKE ?')->execute(["e2e-$run%"]);
$pdo->prepare('DELETE FROM teams WHERE owner_email LIKE ?')->execute(["e2e-$run%"]);
exec('"' . PHP_BINARY . '" "' . LIC_DIR . '/bin/publish-revoked.php"');
$ids = array_column(mailpit('/api/v1/search?query=' . rawurlencode('to:e2e-' . $run))['messages'] ?? [], 'ID');
if ($ids) {
    $ch = curl_init(MAILPIT . '/api/v1/messages');
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_POSTFIELDS => json_encode(['IDs' => $ids]), CURLOPT_RETURNTRANSFER => true]);
    curl_exec($ch);
}
@unlink($mockFile);
$b->done();
$signedOut->done();

echo $failures ? "\n$failures failed\n" : "\nall passed\n";
exit($failures ? 1 : 0);
