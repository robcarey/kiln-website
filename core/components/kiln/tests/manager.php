<?php
/**
 * Extras → Kiln Licences end to end: a throwaway Administrator signs in to the
 * real manager (https://kilndev.test/manager/), opens the page, and drives every
 * processor through MODX's connector the way the page's buttons do. Mail is read
 * from Kiln's Mailpit; the licence server is license.kilndev.test.
 *
 *   php core/components/kiln/tests/manager.php
 *
 * Everything it creates (users, licences, teams, their audit rows, mail) is
 * removed at the end, and revoked.json is republished.
 */
declare(strict_types=1);

use KilnWeb\Kiln;
use MODX\Revolution\modMenu;
use MODX\Revolution\modSystemSetting;
use MODX\Revolution\modUser;
use MODX\Revolution\modUserProfile;
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

function http(string $url, ?array $post = null, array $headers = [], ?string $jar = null): array
{
    $ch = curl_init(str_starts_with($url, 'http') ? $url : SITE . $url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($jar) {
        curl_setopt_array($ch, [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    }
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string) curl_exec($ch);
    return [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), $body];
}

function mailpit(string $path): array
{
    $ch = curl_init(MAILPIT . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    return json_decode((string) curl_exec($ch), true) ?? [];
}

function mailTo(string $to, string $re, int $min = 1): ?array
{
    for ($i = 0; $i < 20; $i++) {
        $hits = array_values(array_filter(mailpit('/api/v1/search?query=' . rawurlencode('to:"' . $to . '"'))['messages'] ?? [], fn ($m) => preg_match($re, $m['Subject'])));
        if (count($hits) >= $min) {
            return mailpit('/api/v1/message/' . $hits[0]['ID']) + ['count' => count($hits)];
        }
        usleep(250000);
    }
    return null;
}

// ---- set up: MODX in-process for the throwaway users -----------------------------
define('MODX_API_MODE', true);
require dirname(__DIR__, 4) . '/config.core.php';
require MODX_CORE_PATH . 'vendor/autoload.php';
$modx = new modX();
$modx->initialize('mgr');

$run = bin2hex(random_bytes(3));
$adminName = "e2e-$run-admin";
$adminPw = 'Admin-pass-' . bin2hex(random_bytes(6));
$nobody = "e2e-$run-nobody@example.test";
$customer = "e2e-$run-cust@example.test";
$member = "e2e-$run-member@example.test";
echo "manager end to end (run $run)\n";

$admin = $modx->newObject(modUser::class);
$admin->fromArray(['username' => $adminName, 'active' => 1]);
$admin->set('password', $adminPw);
$p = $modx->newObject(modUserProfile::class);
$p->fromArray(['email' => "$adminName@example.test", 'fullname' => 'E2E Admin']);
$admin->addOne($p);
$admin->save();
$admin->joinGroup('Administrator', 'Super User');
$cust = Kiln::get($modx)->accounts()->create($customer, 'Cara Customer', true);

$menu = $modx->getObject(modMenu::class, ['namespace' => 'kiln', 'action' => 'licenses']);
check($menu !== null && $menu->get('parent') === 'components', 'setup put “Kiln Licences” under Extras');

// ---- who may use it ---------------------------------------------------------------
$probe = new KilnWeb\Processors\Licenses\GetList($modx, []);
$modx->user = $cust;
check($probe->checkPermissions() === false, 'a customer account cannot run the licence processors');
$modx->user = $admin;
check($probe->checkPermissions() === false, 'nor can an Administrator without a manager session');
[$s] = http('/connectors/index.php', ['action' => 'KilnWeb\\Processors\\Licenses\\GetList']);
check($s === 401, 'the connector refuses calls without a manager token');

// ---- sign in to the manager and open the page ------------------------------------
$jar = tempnam(sys_get_temp_dir(), 'kiln-mgr');
http('/manager/', ['login' => 1, 'username' => $adminName, 'password' => $adminPw, 'returnUrl' => '/manager/'], [], $jar);
[$s, $page] = http('/manager/?a=licenses&namespace=kiln', null, [], $jar);
check($s === 200 && str_contains($page, 'kilnlic-panel-div') && str_contains($page, 'mgr/licenses.js'), 'the Kiln Licences page opens for an Administrator');
$token = preg_match('/"auth":"([^"]+)"/', $page, $m) ? $m[1] : '';
check($token !== '', 'and carries the manager token');
[$s, $js] = http('/assets/themes/kiln/mgr/licenses.js');
check($s === 200 && str_contains($js, 'kilnlic-grid-licenses'), 'its script is served');

/** One connector call, as the page's Ext code makes it. */
$call = function (string $action, array $params = []) use ($jar, $token): array {
    [, $body] = http('/connectors/index.php', ['action' => 'KilnWeb\\Processors\\' . $action, 'HTTP_MODAUTH' => $token] + $params, ['modAuth: ' . $token], $jar);
    return json_decode($body, true) ?? ['success' => false, 'message' => 'not JSON: ' . substr($body, 0, 200)];
};

// ---- minting ------------------------------------------------------------------------
$r = $call('Licenses\\Create', ['email' => strtoupper($nobody), 'plan' => 'comp', 'notes' => 'e2e', 'send' => 1]);
$comp = $r['object'] ?? [];
check(!empty($r['success']) && ($comp['plan'] ?? '') === 'comp' && $comp['exp'] === null && $comp['account'] === '', 'mint a complimentary licence for an email with no account');
check(str_contains($r['message'] ?? '', 'when they sign up'), 'and the message says it is waiting for them');
$mail = mailTo($nobody, '/^Your Kiln licence key \(' . preg_quote($comp['licenseId'] ?? 'x', '/') . '\)$/');
check($mail !== null && str_contains($mail['HTML'] ?? '', $comp['serial'] ?? 'x'), 'the key is emailed');
check(str_contains($mail['HTML'] ?? '', SITE . '/account/register'), 'with links to the website, not the manager');

$r = $call('Licenses\\Create', ['email' => $customer, 'plan' => 'lifetime', 'send' => 0]);
check(!empty($r['success']) && ($r['object']['account'] ?? '') === $customer && str_contains($r['message'], 'on their account'), 'mint for an existing customer goes straight onto their account');
$r = $call('Licenses\\Create', ['email' => $nobody, 'plan' => 'annual']);
check(empty($r['success']) && str_contains($r['message'] ?? '', 'paid-through'), 'an annual licence without a paid-through date is refused');
$r = $call('Licenses\\Create', ['email' => $nobody, 'plan' => 'annual', 'paidThrough' => '2027-06-30T00:00:00']);
$annual = $r['object'] ?? [];
check(!empty($r['success']) && $annual['exp'] === '2027-07-14', 'annual from an Ext date: valid for 14 days past paid-through');
$r = $call('Licenses\\Create', ['email' => $nobody, 'plan' => 'team']);
check(empty($r['success']) && str_contains($r['message'] ?? '', 'Teams tab'), 'team seats are not minted here');
$r = $call('Licenses\\Create', ['email' => 'not-an-email', 'plan' => 'comp']);
check(empty($r['success']) && str_contains($r['message'] ?? '', 'valid email'), 'a bad email is refused');

// ---- trials ------------------------------------------------------------------------
$r = $call('Licenses\\Create', ['email' => $nobody, 'plan' => 'trial', 'send' => 1]);
check(!empty($r['success']) && ($r['object']['exp'] ?? '') === gmdate('Y-m-d', strtotime('+7 days')), 'one-click 7-day trial');
$r = $call('Licenses\\Create', ['email' => $nobody, 'plan' => 'trial']);
check(empty($r['success']) && str_contains($r['message'] ?? '', 'already had a trial'), 'a second trial is refused, in plain words');
$r = $call('Licenses\\Create', ['email' => $nobody, 'plan' => 'trial', 'force' => 1]);
check(!empty($r['success']), 'unless you allow it');

// ---- the grid -------------------------------------------------------------------------
$r = $call('Licenses\\GetList', ['query' => "e2e-$run-nobody", 'start' => 0, 'limit' => 2]);
check(($r['total'] ?? 0) == 4 && count($r['results'] ?? []) === 2, 'search pages: total 4, two on this page');
$r = $call('Licenses\\GetList', ['query' => "e2e-$run-nobody", 'plan' => 'trial']);
check(($r['total'] ?? 0) == 2 && ($r['results'][0]['planLabel'] ?? '') === 'Pro · 7-day trial', 'filter by plan, with readable plan names');
$r = $call('Licenses\\Get', ['id' => $comp['licenseId'] ?? 'x']);
check(!empty($r['success']) && ($r['object']['audit'][0]['action'] ?? '') === 'mint' && str_starts_with($r['object']['serial'] ?? '', 'KILN1.'), 'details: key and history, found by KILN id');

// ---- actions on one licence -----------------------------------------------------------
$r = $call('Licenses\\Update', ['id' => $comp['id'] ?? 'x', 'op' => 'extend', 'exp' => '2028-01-31', 'send' => 1]);
check(!empty($r['success']) && ($r['object']['exp'] ?? '') === '2028-01-31', 'change expiry');
check((mailTo($nobody, '/^Your Kiln licence key \(' . preg_quote($comp['licenseId'] ?? 'x', '/') . '\)$/', 2)['count'] ?? 0) >= 2, 'and the new key is emailed');
$r = $call('Licenses\\Update', ['id' => $comp['id'] ?? 'x', 'op' => 'extend', 'never' => 1]);
check(!empty($r['success']) && $r['object']['exp'] === null, 'back to never expires');
$r = $call('Licenses\\Update', ['id' => $comp['id'] ?? 'x', 'op' => 'renew', 'paidThrough' => '2029-01-01']);
check(empty($r['success']), 'only annual licences renew');
$r = $call('Licenses\\Update', ['id' => $annual['id'] ?? 'x', 'op' => 'renew', 'paidThrough' => '2028-06-30']);
check(!empty($r['success']) && ($r['object']['exp'] ?? '') === '2028-07-14', 'renew an annual licence');
$r = $call('Licenses\\Update', ['id' => $comp['id'] ?? 'x', 'op' => 'revoke', 'reason' => 'e2e']);
check(!empty($r['success']) && ($r['object']['state'] ?? '') === 'revoked', 'revoke');
$revoked = json_decode((string) file_get_contents(LIC_DIR . '/public/revoked.json'), true);
check(in_array($comp['id'] ?? 'x', $revoked['revoked'] ?? [], true), 'revoked.json lists it');
$r = $call('Licenses\\Resend', ['id' => $comp['id'] ?? 'x']);
check(empty($r['success']) && str_contains($r['message'] ?? '', 'revoked'), 'a revoked key is not emailed');
$r = $call('Licenses\\Update', ['id' => $comp['id'] ?? 'x', 'op' => 'unrevoke']);
check(!empty($r['success']) && ($r['object']['state'] ?? '') === 'active', 'unrevoke');
$r = $call('Licenses\\Resend', ['id' => $comp['id'] ?? 'x', 'to' => $member]);
check(!empty($r['success']) && mailTo($member, '/^Your Kiln licence key/') !== null, 'email a key to another address');
$r = $call('Licenses\\Update', ['id' => $comp['id'] ?? 'x', 'op' => 'transfer', 'email' => $customer, 'send' => 1]);
check(!empty($r['success']) && ($r['object']['email'] ?? '') === $customer && ($r['object']['account'] ?? '') === $customer, 'transfer to a customer: new email and their account');

// ---- teams -------------------------------------------------------------------------------
$r = $call('Teams\\Create', ['plan' => 'team', 'ownerEmail' => $customer, 'name' => 'E2E Studio', 'seats' => 3, 'members' => "$member\ne2e-$run-m2@example.test", 'send' => 1]);
check(empty($r['success']) && str_contains($r['message'] ?? '', 'paid-through'), 'a Team needs a paid-through date');
$r = $call('Teams\\Create', ['plan' => 'team', 'ownerEmail' => $customer, 'name' => 'E2E Studio', 'seats' => 3, 'paidThrough' => '2027-10-31',
    'members' => "$member, e2e-$run-m2@example.test", 'send' => 1]);
$team = $r['object'] ?? [];
check(!empty($r['success']) && str_contains($r['message'], '2 seat(s) given out, 2 key(s) emailed'), 'create a Team and hand out two seats');
check(mailTo("e2e-$run-m2@example.test", '/^Your Kiln licence key/') !== null, 'members get their keys by email');
$r = $call('Teams\\Member', ['id' => $team['id'] ?? 'x', 'op' => 'add', 'email' => "e2e-$run-m3@example.test", 'send' => 0]);
check(!empty($r['success']), 'give the third seat');
$r = $call('Teams\\Member', ['id' => $team['id'] ?? 'x', 'op' => 'add', 'email' => "e2e-$run-m4@example.test"]);
check(empty($r['success']) && str_contains($r['message'] ?? '', 'Every seat'), 'a full team refuses a fourth');
$r = $call('Teams\\Get', ['id' => $team['id'] ?? 'x']);
$seats = $r['object']['members'] ?? [];
check(count($seats) === 3 && ($r['object']['seatsUsed'] ?? 0) === 3, 'the team window lists three members');
$r = $call('Licenses\\GetList', ['teamId' => $team['id'] ?? 'x']);
check(($r['total'] ?? 0) == 3, 'the members grid (licences by team) agrees');
$r = $call('Teams\\Member', ['id' => $team['id'] ?? 'x', 'op' => 'remove', 'license' => $seats[0]['id'] ?? 'x']);
check(!empty($r['success']) && $call('Teams\\Get', ['id' => $team['id'] ?? 'x'])['object']['seatsUsed'] === 2, 'free a seat');
$r = $call('Teams\\Update', ['id' => $team['id'] ?? 'x', 'seats' => 5, 'paidThrough' => '2028-10-31', 'name' => 'E2E Studio Ltd', 'status' => 'active']);
check(!empty($r['success']) && $r['object']['seats'] === 5 && $r['object']['memberExp'] === '2028-11-14', 'edit: more seats and renewed (seats re-signed to 14 days past)');
$r = $call('Teams\\Create', ['plan' => 'enterprise', 'ownerEmail' => $nobody, 'seats' => 25]);
check(!empty($r['success']) && $r['object']['plan'] === 'enterprise' && $r['object']['paidThrough'] === null, 'an Enterprise licence can have no end date');
$r = $call('Teams\\GetList', ['query' => 'E2E Studio Ltd']);
check(($r['total'] ?? 0) == 1, 'teams search');

// ---- audit and customers ------------------------------------------------------------------
$r = $call('Audit\\GetList', ['licenseId' => $comp['licenseId'] ?? 'x']);
$actions = array_column($r['results'] ?? [], 'action');
check(in_array('revoke', $actions, true) && in_array('transfer', $actions, true), 'audit log for one licence, by KILN id');
check(($r['results'][0]['actor'] ?? '') === "modx:admin:$adminName", 'records which admin did it');
$r = $call('Audit\GetList', ['limit' => 0]);
check(count($r['results'] ?? []) > 1, 'the unpaged audit grid (limit=0) gets the newest rows, not one');
$r = $call('Customers\\Get', ['email' => $customer]);
$c = $r['object'] ?? [];
check(!empty($r['success']) && ($c['account']['username'] ?? '') === $customer && count($c['licenses']) === 2 && count($c['teams']) === 1, 'customer view: account, both licences and the team they own');
check(str_starts_with($c['account']['createdon'] ?? '', date('Y-m-d')), 'with the real sign-up date');
$r = $call('Customers\\Get', ['email' => $nobody]);
check(!empty($r['success']) && $r['object']['account'] === null && count($r['object']['licenses']) === 3, 'customer view for an email with no account');

// ---- the sales switch and orders -------------------------------------------------------------
$setting = fn (string $k) => $modx->getObject(modSystemSetting::class, ['key' => $k]);
$keepSales = (string) $setting('kiln.sales')->get('value');
$keepTest = (string) $setting('kiln.payments_test_mode')->get('value');
$r = $call('Sales\\Set', ['mode' => 'paused']);
check(!empty($r['success']) && ($r['object']['mode'] ?? '') === 'paused' && $setting('kiln.sales')->get('value') === 'paused', 'the sales switch pauses sales');
$r = $call('Sales\\Set', ['mode' => 'sometimes']);
check(empty($r['success']), 'and refuses anything but open, paused or soon');
$call('Sales\\Set', ['mode' => $keepSales]);

$t = $setting('kiln.payments_test_mode');
$t->set('value', '1');
$t->save();
$modx->setOption('kiln.payments_test_mode', '1');
$modx->cacheManager->refresh();
sleep(3); // the web server's opcache rechecks MODX's cached settings file every 2 s
$shop = Kiln::get($modx)->shop();
$order = $shop->createOrder($cust, $shop->lines(['annual' => 1]));
[$body, $headers] = $shop->provider('test')->event('paid', $order['ref']);
$shop->webhook('test', $body, $headers);
$r = $call('Orders\\GetList', ['query' => $order['ref']]);
check(($r['total'] ?? 0) == 1 && ($r['results'][0]['statusLabel'] ?? '') === 'Complete' && $r['results'][0]['summary'] === 'Annual', 'the Orders tab finds a paid order');
$r = $call('Orders\\Get', ['ref' => $order['ref']]);
$o = $r['object'] ?? [];
check(count($o['items'][0]['licences'] ?? []) === 1 && ($o['events'][0]['processed'] ?? false) === true, 'order details: the key it made and its payment event');
$r = $call('Orders\\Update', ['ref' => $order['ref'], 'op' => 'resend']);
check(!empty($r['success']) && mailTo($customer, '/^Your Kiln order ' . preg_quote($order['ref'], '/') . '$/') !== null, 'email the receipt again');
$was = $shop->order($order['ref'])['period_end'];
$r = $call('Orders\\Update', ['ref' => $order['ref'], 'op' => 'renew_test']);
check(!empty($r['success']) && $shop->order($order['ref'])['period_end'] > $was, 'simulate a renewal (test payments)');
$r = $call('Orders\\Update', ['ref' => $order['ref'], 'op' => 'refund']);
$key = $o['items'][0]['licences'][0]['licenseId'] ?? 'x';
check(!empty($r['success']) && $shop->order($order['ref'])['status'] === 'refunded' && $call('Licenses\\Get', ['id' => $key])['object']['state'] === 'revoked', 'mark refunded: order refunded, key revoked');
$r = $call('Customers\\Get', ['email' => $customer]);
check(count($r['object']['orders'] ?? []) === 1, 'the customer view lists their orders');
$t->set('value', $keepTest);
$t->save();
$modx->cacheManager->refresh();
$db = Kiln::get($modx)->store();
foreach ($db->all('SELECT id, ref FROM ' . $db->t('orders') . ' WHERE email LIKE ?', ["e2e-$run%"]) as $row) {
    $db->run('DELETE FROM ' . $db->t('order_items') . ' WHERE order_id = ?', [$row['id']]);
    $db->run('DELETE FROM ' . $db->t('payment_events') . ' WHERE order_ref = ?', [$row['ref']]);
    $db->run('DELETE FROM ' . $db->t('orders') . ' WHERE id = ?', [$row['id']]);
}

// ---- clean up ----------------------------------------------------------------------------
foreach ($modx->getCollection(modUser::class, ['username:LIKE' => "e2e-$run%"]) as $u) {
    $u->remove();
}
$cfg = require LIC_DIR . '/config.php';
$pdo = new PDO($cfg['db']['dsn'], $cfg['db']['user'], $cfg['db']['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$like = "e2e-$run%";
$pdo->prepare("DELETE FROM audit_log WHERE license_id IN (SELECT id FROM licenses WHERE email LIKE ?) OR team_id IN (SELECT id FROM teams WHERE owner_email LIKE ?)")->execute([$like, $like]);
$pdo->prepare('DELETE FROM licenses WHERE email LIKE ?')->execute([$like]);
$pdo->prepare('DELETE FROM teams WHERE owner_email LIKE ?')->execute([$like]);
exec('"' . PHP_BINARY . '" "' . LIC_DIR . '/bin/publish-revoked.php"');
$ids = array_column(mailpit('/api/v1/search?query=' . rawurlencode('to:e2e-' . $run))['messages'] ?? [], 'ID');
if ($ids) {
    $ch = curl_init(MAILPIT . '/api/v1/messages');
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_POSTFIELDS => json_encode(['IDs' => $ids]), CURLOPT_RETURNTRANSFER => true]);
    curl_exec($ch);
}
@unlink($jar);

echo $failures ? "\n$failures failed\n" : "\nall passed\n";
exit($failures ? 1 : 0);
