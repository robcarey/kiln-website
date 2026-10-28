<?php
/**
 * Buying Kiln Pro end to end, through the real site (https://kilndev.test) with
 * the test payment provider, keys and receipts read from Kiln's Mailpit:
 *
 *  - the sales switch: soon / paused / open change /buy and the home page price;
 *  - a cart with any mix of Annual, Lifetime and a Team; signing in keeps the cart;
 *  - checkout -> the provider's page -> paid webhook -> keys minted onto the
 *    account, one email with every key, order on the account page;
 *  - webhooks over HTTP: bad signature refused, duplicates ignored, renewal moves
 *    the annual keys and the team on, refund revokes everything;
 *  - cancel brings the cart back; paused and "provider not ready" refuse checkout.
 *
 *   php core/components/kiln/tests/buy.php
 *
 * It records the sales settings first and puts them back at the end, and deletes
 * everything it creates (user, orders, licences, teams, mail).
 */
declare(strict_types=1);

use KilnWeb\Kiln;
use MODX\Revolution\modSystemSetting;
use MODX\Revolution\modUser;
use MODX\Revolution\modX;

const SITE = 'https://kilndev.test';
const MAILPIT = 'http://127.0.0.1:8025';
const LIC_DIR = 'H:/SITES/KILN SERVER/licensing';

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
        $this->jar = tempnam(sys_get_temp_dir(), 'kiln-buy');
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

    /** GET a page, then POST to it with that page's CSRF token. */
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

function webhook(string $body, array $headers): array
{
    $ch = curl_init(SITE . '/payment-webhook?provider=test');
    $h = ['Content-Type: application/json'];
    foreach ($headers as $k => $v) {
        $h[] = "$k: $v";
    }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $h,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_TIMEOUT => 30]);
    $out = (string) curl_exec($ch);
    return [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode($out, true)['message'] ?? $out];
}

// ---- set up ---------------------------------------------------------------------------
define('MODX_API_MODE', true);
require dirname(__DIR__, 4) . '/config.core.php';
require MODX_CORE_PATH . 'vendor/autoload.php';
$modx = new modX();
$modx->initialize('web');
$kiln = Kiln::get($modx);
$api = $kiln->licenses();

/** Change a setting the way the manager switch does: save, clear the cache, wait out opcache. */
$set = function (string $key, string $value) use ($modx): string {
    $s = $modx->getObject(modSystemSetting::class, ['key' => $key]);
    $old = (string) $s->get('value');
    $s->set('value', $value);
    $s->save();
    $modx->setOption($key, $value);
    $modx->cacheManager->refresh();
    sleep(3); // the web server's opcache rechecks MODX's cached settings file every 2 s
    return $old;
};
$keep = [];
foreach (['kiln.sales', 'kiln.payments_test_mode', 'kiln.payment_provider', 'kiln.price_annual', 'kiln.price_lifetime', 'kiln.price_team_seat', 'kiln.team_min_seats', 'kiln.currency', 'kiln.sales_message'] as $k) {
    $keep[$k] = (string) $modx->getObject(modSystemSetting::class, ['key' => $k])->get('value');
}
foreach (['kiln.payment_provider' => 'test', 'kiln.price_annual' => '49', 'kiln.price_lifetime' => '129', 'kiln.price_team_seat' => '39', 'kiln.team_min_seats' => '3', 'kiln.currency' => 'USD', 'kiln.sales_message' => ''] as $k => $v) {
    $s = $modx->getObject(modSystemSetting::class, ['key' => $k]);
    $s->set('value', $v);
    $s->save();
    $modx->setOption($k, $v);
}

$run = bin2hex(random_bytes(3));
$email = "e2e-$run-buyer@example.test";
$password = 'buyer pass ' . $run;
$user = $kiln->accounts()->create($email, 'Bea Buyer', true);
$kiln->accounts()->setPassword($user, $password);
$uid = (int) $user->get('id');
echo "buying end to end ($email)\n";

// ---- the sales switch ------------------------------------------------------------------
$set('kiln.payments_test_mode', '1');
$set('kiln.sales', 'soon');
$b = new Browser();
[, $page] = $b->go('/buy');
check(str_contains($page, 'goes on sale soon') && !str_contains($page, 'Add to cart'), 'soon: /buy says it goes on sale soon, no cart');
[, $home] = $b->go('/');
check(str_contains($home, 'Launch pricing soon'), 'soon: the home page Pro card says “Launch pricing soon”');

$set('kiln.sales', 'paused');
[, $page] = $b->go('/buy');
check(str_contains($page, 'Purchases are paused') && !str_contains($page, 'Add to cart'), 'paused: /buy says purchases are paused');
[, $home] = $b->go('/');
check(str_contains($home, '$49<small>/ year</small>') && str_contains($home, '$129 once'), 'paused: the home page still shows the prices');

$set('kiln.sales', 'open');
[, $page] = $b->go('/buy');
check(str_contains($page, 'Add to cart') && str_contains($page, '$49') && str_contains($page, '$129') && str_contains($page, '$39'), 'open: /buy shows Annual, Lifetime and Team with prices');
check(str_contains($page, 'Test mode.'), 'and says it is in test mode');

// ---- the cart ------------------------------------------------------------------------------
[, $page] = $b->submit('/buy', ['do' => 'add', 'plan' => 'annual']);
check(str_contains($page, 'Your cart') && str_contains($page, 'Sign in to check out'), 'add Annual while signed out: the cart shows, with “Sign in to check out”');
[, $page, $url] = $b->submit('/buy', ['do' => 'checkout']);
check(str_contains($url, '/account/sign-in') && str_contains($page, 'Your cart is saved'), 'checking out signed out goes to sign-in, cart saved');
[, $page, $url] = $b->submit('/account/sign-in', ['email' => $email, 'password' => $password, 'mode' => 'password']);
check(str_ends_with(parse_url($url, PHP_URL_PATH), '/buy') && str_contains($page, 'Kiln Pro · Annual') && str_contains($page, '>Check out<'), 'after signing in they are back on /buy with the cart');
$b->submit('/buy', ['do' => 'add', 'plan' => 'lifetime']);
$b->submit('/buy', ['do' => 'add', 'plan' => 'team', 'seats' => '2']);
[, $page] = $b->submit('/buy', ['do' => 'set', 'plan' => 'annual', 'n' => '2']);
check(str_contains($page, 'value="3" min="3"'), 'a Team is never smaller than 3 seats');
check(str_contains($page, '$344 USD'), 'total: 2 × $49 + $129 + 3 × $39 = $344 USD');
[, $page] = $b->submit('/buy', ['do' => 'add', 'plan' => 'nonsense']);
check(str_contains($page, 'Please pick a plan'), 'an unknown plan is refused');

// ---- checkout and payment --------------------------------------------------------------------
[, $page, $url] = $b->submit('/buy', ['do' => 'checkout']);
$ref = preg_match('/checkout=(K-[A-Z0-9]{8})/', $url, $m) ? $m[1] : '';
check($ref !== '' && str_contains($page, 'Pay $344 USD (test)'), "checkout opens the test provider’s page for order $ref");
$o = $kiln->shop()->order($ref);
check($o && $o['status'] === 'pending' && count($o['items']) === 3 && (int) $o['total_cents'] === 34400, 'the order is stored as awaiting payment, 3 items, $344');
[, $page] = $b->go('/buy');
check(!str_contains($page, 'Your cart'), 'the cart is empty once the order exists');

$other = new Browser();
[, , $url] = $other->go('/buy?order=' . $ref);
check(str_contains($url, '/account/sign-in'), 'someone signed out cannot see the order');

[, $page, $url] = $b->submit('/buy?checkout=' . $ref, ['do' => 'test_pay', 'order' => $ref]);
check(str_contains($url, 'order=' . $ref) && str_contains($page, 'Your order is complete'), 'pay: the order page says it is complete');
$o = $kiln->shop()->order($ref);
$mine = $api->call('GET', 'licenses?orderRef=' . $ref);
$annual = array_values(array_filter($mine, fn ($l) => $l['plan'] === 'annual'));
$life = array_values(array_filter($mine, fn ($l) => $l['plan'] === 'lifetime'));
$exp = gmdate('Y-m-d', strtotime(gmdate('Y-m-d', strtotime('+1 year')) . ' +14 days'));
check(count($annual) === 2 && count($life) === 1 && $annual[0]['exp'] === $exp && $life[0]['exp'] === null, "2 annual keys (valid to $exp) and 1 lifetime key were minted");
check(count(array_filter($mine, fn ($l) => $l['accountRef'] === (string) $uid)) === 3, 'all on the buyer’s account');
$team = $api->call('GET', 'teams?ownerEmail=' . rawurlencode($email))[0] ?? [];
check(($team['seats'] ?? 0) === 3 && ($team['orderRef'] ?? '') === $ref && ($team['ownerAccountRef'] ?? '') === (string) $uid, 'a 3-seat team owned by the buyer');
foreach ($annual as $l) {
    check(str_contains($page, $l['licenseId']), "the order page lists {$l['licenseId']}");
    break;
}
$mail = mailTo($email, '/^Your Kiln order ' . preg_quote($ref, '/') . '$/');
$html = $mail['HTML'] ?? '';
check($mail !== null && str_contains($html, $annual[0]['serial']) && str_contains($html, $annual[1]['serial']) && str_contains($html, $life[0]['serial']), 'one email with every key');
check(str_contains($html, 'Test order') && str_contains($html, '$344 USD') && str_contains($html, $team['id'] ?? 'x'), 'it says test order, shows the total and the team');
[, $page] = $b->go('/account/');
check(str_contains($page, $ref) && str_contains($page, 'Complete') && str_contains($page, 'Pro · Lifetime') && str_contains($page, 'seats used'), 'the account page shows the order, the keys and the team');

// ---- webhooks over HTTP -----------------------------------------------------------------------
$test = $kiln->shop()->provider('test');
[$body, $headers] = $test->event('renewed', $ref, ['periodEnd' => gmdate('Y-m-d', strtotime('+2 years'))]);
[$s] = webhook($body, ['x-kiln-test-signature' => str_repeat('0', 64)]);
check($s === 401, 'a webhook with a bad signature is refused');
[$s, $msg] = webhook($body, $headers);
$new = gmdate('Y-m-d', strtotime('+2 years'));
check($s === 200 && str_contains($msg, 'renewed to ' . $new), 'a renewal webhook is applied');
[$s, $msg] = webhook($body, $headers);
check($s === 200 && $msg === 'duplicate', 'the same event again is ignored');
$renewed = $api->call('GET', 'licenses/' . $annual[0]['id']);
check($renewed['exp'] === gmdate('Y-m-d', strtotime($new . ' +14 days')) && $renewed['id'] === $annual[0]['id'], 'the annual key is re-signed, same licence id, a year further on');
check($api->call('GET', 'teams/' . $team['id'])['paidThrough'] === $new, 'the team moves on too');
check(mailTo($email, '/^Your Kiln Pro renewal/') !== null, 'and the renewed keys are emailed');

[$body, $headers] = $test->event('refunded', $ref);
[$s] = webhook($body, $headers);
check($s === 200 && $kiln->shop()->order($ref)['status'] === 'refunded', 'a refund webhook marks the order refunded');
$after = $api->call('GET', 'licenses?orderRef=' . $ref);
check(count(array_filter($after, fn ($l) => $l['status'] === 'revoked')) === 3, 'every key from the order is revoked');
check($api->call('GET', 'teams/' . $team['id'])['status'] === 'cancelled', 'and the team is cancelled');
$revoked = json_decode((string) file_get_contents(LIC_DIR . '/public/revoked.json'), true);
check(in_array($life[0]['id'], $revoked['revoked'] ?? [], true), 'revoked.json lists them');

// ---- cancel, pause, no provider -----------------------------------------------------------------
$b->submit('/buy', ['do' => 'add', 'plan' => 'lifetime']);
[, , $url] = $b->submit('/buy', ['do' => 'checkout']);
$ref2 = preg_match('/checkout=(K-[A-Z0-9]{8})/', $url, $m) ? $m[1] : '';
[, $page] = $b->submit('/buy?checkout=' . $ref2, ['do' => 'test_cancel', 'order' => $ref2]);
check(str_contains($page, 'Checkout cancelled') && str_contains($page, 'Your cart') && $kiln->shop()->order($ref2)['status'] === 'cancelled', 'cancel at the provider: order cancelled, cart back');

$set('kiln.sales', 'paused');
[, $page] = $b->go('/buy', ['do' => 'checkout', 'csrf' => preg_match('/name="csrf" value="([0-9a-f]+)"/', $b->go('/account/')[1], $m) ? $m[1] : '']);
check(str_contains($page, 'Purchases are paused') && !str_contains($page, 'Pay $'), 'paused: checkout is refused');
$set('kiln.sales', 'open');
$set('kiln.payments_test_mode', '0');
[, $page] = $b->submit('/buy', ['do' => 'checkout']);
check(str_contains($page, 'Checkout isn’t available right now'), 'open but no provider ready: checkout says it isn’t available');
[$body, $headers] = $test->event('paid', $ref2);
check(webhook($body, $headers)[0] === 403, 'and test webhooks are refused while test payments are off');

// ---- clean up -----------------------------------------------------------------------------------
foreach ($keep as $k => $v) {
    $s = $modx->getObject(modSystemSetting::class, ['key' => $k]);
    $s->set('value', $v);
    $s->save();
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
$b->done();
$other->done();

echo $failures ? "\n$failures failed\n" : "\nall passed\n";
exit($failures ? 1 : 0);
