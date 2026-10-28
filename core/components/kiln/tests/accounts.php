<?php
/**
 * Accounts end to end, through the real site (https://kilndev.test) the way a
 * person uses it, with codes and keys read from Kiln's Mailpit:
 *
 *  - a licence minted for an email BEFORE it has an account shows up once that
 *    email registers and verifies;
 *  - register → 4-letter code → signed in; wrong codes count down;
 *  - sign out, password sign-in, sign-in by code, forgot password → new password;
 *  - teams: the owner gives a seat by email, the member gets the key by email,
 *    the owner frees the seat;
 *  - "Email me this key".
 *
 *   php core/components/kiln/tests/accounts.php
 *
 * Needs: kilndev.test and license.kilndev.test running in Kiln, Mailpit on
 * 127.0.0.1:8025, the licence server's config at H:\SITES\KILN SERVER\licensing.
 * Everything it creates (users, codes, licences, teams, mail) is deleted at the end.
 */
declare(strict_types=1);

const SITE = 'https://kilndev.test';
const MAILPIT = 'http://127.0.0.1:8025';
const LIC_CONFIG = 'H:/SITES/KILN SERVER/licensing/config.php';

$failures = 0;
function check(bool $ok, string $text): void
{
    global $failures;
    if (!$ok) {
        $failures++;
    }
    echo '  ' . ($ok ? 'PASS' : 'FAIL') . "  $text\n";
}

/** A browser: cookies kept, redirects followed, returns [status, body, finalUrl]. */
final class Browser
{
    private string $jar;

    public function __construct()
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'kiln-e2e');
    }

    public function go(string $path, ?array $post = null): array
    {
        $ch = curl_init(str_starts_with($path, 'http') ? $path : SITE . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 6,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_TIMEOUT => 20,
        ]);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $body = (string) curl_exec($ch);
        return [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), $body, (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL)];
    }

    /** GET a page, then POST its form with the page's CSRF token added. */
    public function submit(string $path, array $fields): array
    {
        [, $page] = $this->go($path);
        preg_match('/name="csrf" value="([0-9a-f]+)"/', $page, $m);
        return $this->go($path, $fields + ['csrf' => $m[1] ?? '']);
    }

    public function csrf(): string
    {
        [, $page] = $this->go('/account/');
        preg_match('/name="csrf" value="([0-9a-f]+)"/', $page, $m);
        return $m[1] ?? '';
    }
}

function mailpit(string $path, string $method = 'GET'): array
{
    $ch = curl_init(MAILPIT . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 10]);
    return json_decode((string) curl_exec($ch), true) ?? [];
}

/** Newest message to $to whose subject matches $re (waits briefly for delivery). */
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

function codeFor(string $to): string
{
    $m = mailTo($to, '/^[A-Z]{4} is your Kiln code$/');
    return $m ? substr($m['Subject'], 0, 4) : '';
}

function clearMail(string $to): void
{
    $ids = array_column(mailpit('/api/v1/search?query=' . rawurlencode('to:"' . $to . '"'))['messages'] ?? [], 'ID');
    if ($ids) {
        $ch = curl_init(MAILPIT . '/api/v1/messages');
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_POSTFIELDS => json_encode(['IDs' => $ids]), CURLOPT_RETURNTRANSFER => true]);
        curl_exec($ch);
    }
}

/** Signed call to the licence server, as MODX makes them. */
function lic(string $method, string $path, ?array $body = null): array
{
    $cfg = require LIC_CONFIG;
    $raw = $body === null ? '' : json_encode($body);
    $ts = (string) time();
    $uri = '/v1/' . $path;
    $sig = hash_hmac('sha256', "$method\n$uri\n$ts\n" . hash('sha256', $raw), $cfg['clients']['modx']);
    $ch = curl_init('http://license.kilndev.test' . $uri);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_POSTFIELDS => $raw ?: null,
        CURLOPT_HTTPHEADER => ['X-Kiln-Client: modx', "X-Kiln-Ts: $ts", "X-Kiln-Sig: $sig", 'Idempotency-Key: ' . bin2hex(random_bytes(8))]]);
    return json_decode((string) curl_exec($ch), true)['data'] ?? [];
}

$run = bin2hex(random_bytes(3));
$email = "e2e-$run@example.test";
$member = "e2e-$run-member@example.test";
$password = 'correct horse ' . $run;
echo "accounts end to end ($email)\n";

// ---- a licence waiting before the account exists ---------------------------
$waiting = lic('POST', 'licenses', ['email' => $email, 'plan' => 'comp', 'notes' => 'e2e', 'actor' => 'e2e']);
check(!empty($waiting['licenseId']) && $waiting['accountRef'] === null, "licence {$waiting['licenseId']} minted for an email with no account");

// ---- register + verify -----------------------------------------------------------
$b = new Browser();
[$s, $page] = $b->submit('/account/register', ['name' => 'Eve Tester', 'email' => strtoupper($email), 'password' => 'short', 'password_confirm' => 'short']);
check(str_contains($page, 'at least'), 'a short password is refused with a reason');
[$s, $page, $url] = $b->submit('/account/register', ['name' => 'Eve Tester', 'email' => strtoupper($email), 'password' => $password, 'password_confirm' => $password]);
check(str_ends_with($url, '/account/verify') && str_contains($page, $email), 'register goes to the code page (email lowercased)');
$code = codeFor($email);
check(preg_match('/^[A-HJ-NP-Z]{4}$/', $code) === 1, "a 4-letter code arrived by email ($code)");
$wrong = $code === 'ZZZZ' ? 'YYYY' : 'ZZZZ';
[, $page] = $b->submit('/account/verify', ['code' => $wrong]);
check(str_contains($page, '4 tries left'), 'a wrong code counts down the tries');
[$s, $page, $url] = $b->submit('/account/verify', ['code' => strtolower($code)]);
check(str_ends_with($url, '/account/') && str_contains($page, 'Hi, Eve Tester'), 'the right code (any case) signs in to the account page');
check(str_contains($page, $waiting['licenseId']) && str_contains($page, $waiting['serial']), 'the licence minted earlier is on the account, key and all');
check(str_contains($page, 'Welcome to Kiln'), 'a welcome message shows once');
check(lic('GET', 'licenses/' . $waiting['id'])['accountRef'] !== null, 'the licence server now ties it to the account');
[, $page] = $b->go('/account/sign-in');
[, $home] = $b->go('/');
check(str_contains($home, '>Account</a>') || str_contains($home, 'is-signed-in'), 'the header shows "Account" when signed in');

// ---- email me this key -----------------------------------------------------------
clearMail($email);
$b->go('/account/', ['csrf' => $b->csrf(), 'do' => 'email_key', 'license' => $waiting['id']]);
$m = mailTo($email, '/^Your Kiln licence key/');
check($m !== null && str_contains($m['HTML'] ?? '', $waiting['serial']), '"Email me this key" sends the key');

// ---- sign out / password / code sign-in -------------------------------------------
[, $page] = $b->go('/account/');
preg_match('#href="([^"]+/account/sign-out\?t=[0-9a-f]+)"#', $page, $so);
[, , $url] = $b->go(html_entity_decode($so[1] ?? ''));
[, , $url2] = $b->go('/account/');
check(str_ends_with($url2, '/account/sign-in'), 'sign out ends the session');
[, $page] = $b->submit('/account/sign-in', ['email' => $email, 'password' => 'not it at all', 'mode' => 'password']);
check(str_contains($page, 'don’t match'), 'a wrong password is refused');
[, $page, $url] = $b->submit('/account/sign-in', ['email' => $email, 'password' => $password, 'mode' => 'password']);
check(str_ends_with($url, '/account/') && str_contains($page, 'Hi, Eve Tester'), 'password sign-in works');
$b->go(html_entity_decode($so[1] ?? '')); // stale token: ignored
[, , $url] = $b->go('/account/');
check(str_ends_with($url, '/account/'), 'a stale sign-out link does nothing');
preg_match('#href="([^"]+/account/sign-out\?t=[0-9a-f]+)"#', $b->go('/account/')[1], $so);
$b->go(html_entity_decode($so[1]));
clearMail($email);
[, $page, $url] = $b->submit('/account/sign-in', ['email' => $email, 'mode' => 'code']);
$code = codeFor($email);
[, $page, $url] = $b->submit('/account/verify', ['code' => $code]);
check(str_ends_with($url, '/account/') && $code !== '', 'sign-in by emailed code works');
$b->go(html_entity_decode(preg_match('#href="([^"]+/account/sign-out\?t=[0-9a-f]+)"#', $page, $so) ? $so[1] : ''));

// ---- unknown email asking for a code: same answer, no email ----------------------
$ghost = "e2e-$run-nobody@example.test";
[, $page, $url] = $b->submit('/account/sign-in', ['email' => $ghost, 'mode' => 'code']);
check(str_ends_with($url, '/account/verify') && mailTo($ghost, '/./') === null, 'an unknown email gets the same page and no email');

// ---- forgot password ---------------------------------------------------------------
clearMail($email);
$b->submit('/account/forgot', ['email' => $email]);
$code = codeFor($email);
$newPw = 'a brand new password ' . $run;
[, $page] = $b->submit('/account/verify', ['code' => $code, 'password' => $newPw, 'password_confirm' => 'different']);
check(str_contains($page, 'don’t match'), 'reset refuses mismatched passwords (code not spent)');
[, $page, $url] = $b->submit('/account/verify', ['code' => $code, 'password' => $newPw, 'password_confirm' => $newPw]);
check(str_ends_with($url, '/account/') && str_contains($page, 'new password is set'), 'reset by code sets the new password and signs in');
$b->go(html_entity_decode(preg_match('#href="([^"]+/account/sign-out\?t=[0-9a-f]+)"#', $page, $so) ? $so[1] : ''));
[, , $url] = $b->submit('/account/sign-in', ['email' => $email, 'password' => $newPw, 'mode' => 'password']);
check(str_ends_with($url, '/account/'), 'and the new password works');

// ---- teams ---------------------------------------------------------------------------
$team = lic('POST', 'teams', ['ownerEmail' => $email, 'seats' => 3, 'paidThrough' => gmdate('Y-m-d', strtotime('+1 year')), 'name' => 'E2E Studio', 'actor' => 'e2e']);
[, $page] = $b->go('/account/');
check(str_contains($page, 'E2E Studio') && str_contains($page, '0 of 3 seats used'), 'a team bought by this email shows on the account page');
$b->go('/account/', ['csrf' => $b->csrf(), 'do' => 'team_add', 'team' => $team['id'], 'email' => $member]);
$m = mailTo($member, '/^Your Kiln licence key/');
[, $page] = $b->go('/account/');
check($m !== null && str_contains($m['HTML'] ?? '', 'gave you a seat'), 'giving a seat emails the member their own key');
check(str_contains($page, '1 of 3 seats used') && str_contains($page, $member), 'the seat shows as used');
$seat = array_values(array_filter(lic('GET', 'teams/' . $team['id'])['members'], fn ($l) => $l['email'] === $member))[0] ?? [];
$b->go('/account/', ['csrf' => $b->csrf(), 'do' => 'team_remove', 'team' => $team['id'], 'license' => $seat['id'] ?? '']);
[, $page] = $b->go('/account/');
check(str_contains($page, '0 of 3 seats used') && lic('GET', 'licenses/' . ($seat['id'] ?? 'x'))['status'] === 'revoked', 'removing the member frees the seat and revokes their key');
$other = lic('POST', 'teams', ['ownerEmail' => "e2e-$run-other@example.test", 'seats' => 3, 'paidThrough' => '2030-01-01', 'actor' => 'e2e']);
[, $page] = $b->go('/account/', ['csrf' => $b->csrf(), 'do' => 'team_add', 'team' => $other['id'], 'email' => $member]);
check(str_contains($page, 'isn’t on your account'), "someone else's team can't be managed");

// ---- clean up test users -------------------------------------------------------------
define('MODX_API_MODE', true);
require dirname(__DIR__, 4) . '/config.core.php';
require MODX_CORE_PATH . 'vendor/autoload.php';
$modx = new MODX\Revolution\modX();
$modx->initialize('mgr');

// ---- self-serve trial (only while kiln.trials_public is on) ---------------------------
$setTrials = function (string $v) use ($modx): string {
    $s = $modx->getObject(MODX\Revolution\modSystemSetting::class, ['key' => 'kiln.trials_public']);
    $old = (string) $s->get('value');
    $s->set('value', $v);
    $s->save();
    $modx->cacheManager->refresh();
    sleep(3); // the web server's opcache rechecks MODX's cached settings file every 2 s
    return $old;
};
$trialsWas = $setTrials('0');
[, $page] = $b->go('/account/');
check(!str_contains($page, 'Start my free trial'), 'no trial offer while kiln.trials_public is off');
$setTrials('1');
[, $page] = $b->go('/account/');
check(!str_contains($page, 'Start my free trial'), 'none either while they hold an active licence');
lic('POST', 'licenses/' . $waiting['id'] . '/revoke', ['actor' => 'e2e']);
[, $page] = $b->go('/account/');
check(str_contains($page, 'Start my free trial'), 'the trial offer shows once trials are public and they have no active licence');
[, $page] = $b->go('/account/', ['csrf' => $b->csrf(), 'do' => 'trial']);
check(str_contains($page, 'Your 7-day trial has started') && str_contains($page, 'Pro · 7-day trial') && !str_contains($page, 'Start my free trial'), 'one click starts it, and the offer goes away');
check(mailTo($email, '/^Your Kiln licence key/') !== null, 'the trial key is emailed');
[, $page] = $b->go('/account/', ['csrf' => $b->csrf(), 'do' => 'trial']);
check(count(array_filter(lic('GET', 'licenses?email=' . rawurlencode($email)), fn ($l) => $l['plan'] === 'trial')) === 1, 'posting again (double-click, refresh) gives back the same trial, never a second');
$setTrials($trialsWas);
foreach ($modx->getCollection(MODX\Revolution\modUser::class, ['username:LIKE' => "e2e-$run%"]) as $u) {
    $u->remove();
}
$store = KilnWeb\Kiln::get($modx)->store();
$store->run('DELETE FROM ' . $store->t('auth_codes') . ' WHERE email LIKE ?', ["e2e-$run%"]); // so reruns aren't held up by the code limit
$cfg = require LIC_CONFIG;
$pdo = new PDO($cfg['db']['dsn'], $cfg['db']['user'], $cfg['db']['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->prepare('DELETE FROM audit_log WHERE license_id IN (SELECT id FROM licenses WHERE email LIKE ?) OR team_id IN (SELECT id FROM teams WHERE owner_email LIKE ?)')->execute(["e2e-$run%", "e2e-$run%"]);
$pdo->prepare('DELETE FROM licenses WHERE email LIKE ?')->execute(["e2e-$run%"]);
$pdo->prepare('DELETE FROM teams WHERE owner_email LIKE ?')->execute(["e2e-$run%"]);
exec('"' . PHP_BINARY . '" "' . dirname(LIC_CONFIG) . '/bin/publish-revoked.php"');
foreach ([$email, $member, $ghost] as $addr) {
    clearMail($addr);
}

echo $failures ? "\n$failures failed\n" : "\nall passed\n";
exit($failures ? 1 : 0);
