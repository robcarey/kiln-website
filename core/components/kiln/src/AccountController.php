<?php

namespace KilnWeb;

use MODX\Revolution\modUser;

/**
 * The account pages. Each page holds one uncached call,
 *   [[!kiln.Account? &action=`signin`]]
 * and this class handles its form posts, redirects and rendering (chunks
 * account.*). Every POST carries the session's CSRF token.
 *
 * Pending verification lives in the session as
 *   ['email' => …, 'purpose' => verify|signin|reset|link]
 * so the verify page never takes an email from the URL.
 */
final class AccountController
{
    private Web $web;
    private Accounts $accounts;

    public const PLAN_LABELS = [
        'annual' => 'Pro · Annual',
        'lifetime' => 'Pro · Lifetime',
        'team' => 'Pro · Team seat',
        'enterprise' => 'Pro · Enterprise seat',
        'trial' => 'Pro · 7-day trial',
        'comp' => 'Pro · Complimentary',
    ];

    public function __construct(private Kiln $kiln)
    {
        $this->web = $kiln->web();
        $this->accounts = $kiln->accounts();
    }

    public function run(string $action): string
    {
        $method = match ($action) {
            'navlink' => 'navlink',
            'signin' => 'signin',
            'register' => 'register',
            'verify' => 'verify',
            'forgot' => 'forgot',
            'signout' => 'signout',
            'connect' => 'connect',
            'dashboard' => 'dashboard',
            default => null,
        };
        if ($method === null) {
            return '';
        }
        if (!$this->kiln->accountsVisible()) {
            // kiln.accounts is hidden: no header link, and the pages don't exist.
            if ($method === 'navlink') {
                return '';
            }
            $this->kiln->modx->sendErrorPage();
        }
        try {
            return $this->$method();
        } catch (UserError $e) {
            // A form problem: show the same page again with the message.
            return $this->$method($e);
        }
    }

    private function alert(?UserError $e): string
    {
        $flash = $this->web->flash();
        $out = '';
        if ($flash) {
            $out .= '<div class="k-callout k-callout--' . $this->web->e($flash['kind']) . '" role="status">' . $this->web->e($flash['message']) . '</div>';
        }
        if ($e) {
            $out .= '<div class="k-callout k-callout--error" role="alert">' . $this->web->e($e->getMessage()) . '</div>';
        }
        return $out;
    }

    private function socialButtons(string $verb): string
    {
        $html = '';
        foreach ($this->kiln->social()->enabled() as $key => $label) {
            $html .= $this->web->chunk('account.SocialButton', [
                'provider' => $key,
                'label' => "$verb with $label",
                'href' => $this->web->url('connect', ['provider' => $key]),
            ]);
        }
        return $html === '' ? '' : '<div class="k-auth__social">' . $html . '</div><div class="k-auth__or"><span>or</span></div>';
    }

    private function needGuest(): void
    {
        if ($this->kiln->user()) {
            $this->web->redirect($this->web->url('account'));
        }
    }

    // ---- header link -----------------------------------------------------------

    private function navlink(): string
    {
        $user = $this->kiln->user();
        return $this->web->chunk('account.NavLink', [
            'href' => $user ? $this->web->url('account') : $this->web->url('signin'),
            'label' => $user ? 'Account' : 'Sign in',
            'signed_in' => $user ? '1' : '0',
        ]);
    }

    // ---- sign in -----------------------------------------------------------------

    private function signin(?UserError $e = null): string
    {
        $this->needGuest();
        if ($r = $this->web->query('return')) {
            $this->web->session('return', $r);
        }
        if ($e === null && $this->web->isPost()) {
            $this->web->checkCsrf();
            $email = Accounts::email($this->web->post('email', 254));
            if ($this->web->post('mode', 10) === 'code') {
                $user = $this->accounts->find($email);
                if ($user && !$this->accounts->isStaff($user)) {
                    $this->kiln->codes()->send($email, $user->get('active') ? 'signin' : 'verify', (int) $user->get('id'));
                }
                // Same answer whether or not there is an account, so this can't be used to probe emails.
                $this->web->session('pending', ['email' => $email, 'purpose' => $user && !$user->get('active') ? 'verify' : 'signin']);
                $this->web->redirect($this->web->url('verify'));
            }
            $user = $this->accounts->passwordSignIn($email, $this->web->secret('password'));
            if (!$user->get('active')) {
                $this->kiln->codes()->send($email, 'verify', (int) $user->get('id'));
                $this->web->session('pending', ['email' => $email, 'purpose' => 'verify']);
                $this->web->flash('Almost there: confirm your email with the code we just sent.');
                $this->web->redirect($this->web->url('verify'));
            }
            $this->accounts->signIn($user);
            $this->accounts->claim($user);
            $this->web->redirect($this->web->returnTo());
        }
        return $this->web->chunk('account.SignIn', [
            'alert' => $this->alert($e),
            'social' => $this->socialButtons('Sign in'),
            'email' => $this->web->e($this->web->post('email', 254)),
        ]);
    }

    // ---- register ----------------------------------------------------------------

    private function register(?UserError $e = null): string
    {
        $this->needGuest();
        if ($e === null && $this->web->isPost()) {
            $this->web->checkCsrf();
            if (!$this->web->turnstileOk()) {
                throw new UserError('Please complete the “I’m human” check.');
            }
            $password = $this->web->secret('password');
            $this->accounts->checkPassword($password, $this->web->secret('password_confirm'));
            $user = $this->accounts->register($this->web->post('name', 100), $this->web->post('email', 254), $password);
            $email = $this->accounts->emailOf($user);
            $this->kiln->codes()->send($email, 'verify', (int) $user->get('id'));
            $this->web->session('pending', ['email' => $email, 'purpose' => 'verify']);
            $this->web->redirect($this->web->url('verify'));
        }
        return $this->web->chunk('account.Register', [
            'alert' => $this->alert($e),
            'social' => $this->socialButtons('Sign up'),
            'name' => $this->web->e($this->web->post('name', 100)),
            'email' => $this->web->e($this->web->post('email', 254)),
            'min_length' => (string) max(10, (int) $this->kiln->opt('password_min_length', 10)),
            'turnstile' => $this->web->turnstileWidget(),
        ]);
    }

    // ---- forgot password ---------------------------------------------------------

    private function forgot(?UserError $e = null): string
    {
        $this->needGuest();
        if ($e === null && $this->web->isPost()) {
            $this->web->checkCsrf();
            $email = Accounts::email($this->web->post('email', 254));
            $user = $this->accounts->find($email);
            if ($user && !$this->accounts->isStaff($user)) {
                $this->kiln->codes()->send($email, 'reset', (int) $user->get('id'));
            }
            $this->web->session('pending', ['email' => $email, 'purpose' => 'reset']);
            $this->web->redirect($this->web->url('verify'));
        }
        return $this->web->chunk('account.Forgot', [
            'alert' => $this->alert($e),
            'email' => $this->web->e($this->web->post('email', 254)),
        ]);
    }

    // ---- the code page -----------------------------------------------------------

    private function verify(?UserError $e = null): string
    {
        $pending = $this->web->session('pending');
        if (!is_array($pending) || empty($pending['email'])) {
            $this->web->redirect($this->web->url($this->kiln->user() ? 'account' : 'signin'));
        }
        ['email' => $email, 'purpose' => $purpose] = $pending;

        if ($e === null && $this->web->isPost()) {
            $this->web->checkCsrf();
            if ($this->web->post('do', 10) === 'resend') {
                $user = $this->accounts->find($email);
                if ($purpose === 'link' || ($user && !$this->accounts->isStaff($user))) {
                    $this->kiln->codes()->send($email, $purpose, $user ? (int) $user->get('id') : null, $pending['payload'] ?? []);
                }
                $this->web->flash("We’ve sent a new code to $email.");
                $this->web->redirect($this->web->url('verify'));
            }
            if ($purpose === 'reset') {
                $this->accounts->checkPassword($this->web->secret('password'), $this->web->secret('password_confirm'));
            }
            $row = $this->kiln->codes()->check($email, $purpose, $this->web->post('code', 20));
            $this->web->session('pending', null, true);
            $this->finish($purpose, $email, $row);
        }

        $copy = [
            'verify' => ['Check your email', 'to confirm your address and finish creating your account'],
            'signin' => ['Check your email', 'to sign in'],
            'reset' => ['Choose a new password', 'and pick a new password'],
            'link' => ['Confirm your email', 'to connect it to your account'],
        ][$purpose];
        return $this->web->chunk('account.Verify', [
            'alert' => $this->alert($e),
            'heading' => $copy[0],
            'why' => $copy[1],
            'email' => $this->web->e($email),
            'purpose' => $purpose,
            'is_reset' => $purpose === 'reset' ? '1' : '0',
            'min_length' => (string) max(10, (int) $this->kiln->opt('password_min_length', 10)),
        ]);
    }

    /** A code was right: do what it was for. */
    private function finish(string $purpose, string $email, array $row): never
    {
        if ($purpose === 'link') {
            $p = $row['payload'];
            $user = $this->accounts->find($email) ?? $this->accounts->create($email, (string) ($p['name'] ?? ''), true);
            $this->kiln->social()->link($user, (string) $p['provider'], (string) $p['uid'], $email, $p['name'] ?? null);
            $this->accounts->activate($user);
            $this->accounts->signIn($user);
            $this->web->flash(Social::PROVIDERS[$p['provider']][0] . ' is now connected to your account.');
            $this->web->redirect($this->web->returnTo());
        }
        $user = $this->accounts->find($email);
        if (!$user || $this->accounts->isStaff($user)) {
            throw new UserError('That code has expired. Send yourself a new one.');
        }
        if ($purpose === 'reset') {
            $this->accounts->setPassword($user, $this->web->secret('password'));
        }
        $this->accounts->activate($user);
        $this->accounts->signIn($user);
        $this->web->flash(match ($purpose) {
            'verify' => 'Welcome to Kiln! Your account is ready.',
            'reset' => 'Your new password is set.',
            default => 'You’re signed in.',
        });
        $this->web->redirect($this->web->returnTo());
    }

    // ---- sign out ----------------------------------------------------------------

    private function signout(): string
    {
        try {
            $this->web->checkCsrf();
        } catch (UserError) {
            $this->web->redirect($this->web->url('account')); // a stale link: nothing happens
        }
        $this->accounts->signOut();
        $this->web->redirect((string) $this->kiln->opt('site_url'));
    }

    // ---- Google / GitHub / Facebook ----------------------------------------------

    private function connect(?UserError $e = null): string
    {
        if ($e !== null) {
            $this->web->flash($e->getMessage(), 'error');
            $this->web->redirect($this->web->url($this->kiln->user() ? 'account' : 'signin'));
        }
        $social = $this->kiln->social();

        // Second step for a provider that gave no verified email: ask for one.
        if ($this->web->isPost() && is_array($pend = $this->web->session('social'))) {
            $this->web->checkCsrf();
            $email = Accounts::email($this->web->post('email', 254));
            $this->web->session('social', null, true);
            $this->kiln->codes()->send($email, 'link', null, $pend);
            $this->web->session('pending', ['email' => $email, 'purpose' => 'link', 'payload' => $pend]);
            $this->web->redirect($this->web->url('verify'));
        }
        if (is_array($pend = $this->web->session('social')) && $this->web->query('provider') === '') {
            return $this->web->chunk('account.ConnectEmail', [
                'alert' => $this->alert(null),
                'provider' => $this->web->e(Social::PROVIDERS[$pend['provider']][0]),
            ]);
        }

        $key = $this->web->query('provider', 20);
        $profile = $social->authenticate($key); // redirects away on the first leg
        $uid = (string) $profile->identifier;
        $verified = strtolower(trim((string) $profile->emailVerified));
        $name = trim((string) ($profile->displayName ?: trim($profile->firstName . ' ' . $profile->lastName)));
        $label = Social::PROVIDERS[$key][0];
        $current = $this->kiln->user();
        $owner = $social->identityUser($key, $uid);

        if ($current) {
            // Signed in already: connect this provider to the current account.
            if ($owner && (int) $owner->get('id') !== (int) $current->get('id')) {
                throw new UserError("That $label account is connected to a different Kiln account.");
            }
            $social->link($current, $key, $uid, $verified ?: null, $name ?: null);
            $this->web->flash("$label is now connected to your account.");
            $this->web->redirect($this->web->url('account'));
        }
        if ($owner) {
            if ($this->accounts->isStaff($owner)) {
                throw new UserError('Use the manager to sign in to this account.');
            }
            $this->accounts->signIn($owner);
            $this->accounts->claim($owner);
            $this->web->redirect($this->web->returnTo());
        }
        if ($verified !== '' && filter_var($verified, FILTER_VALIDATE_EMAIL)) {
            $user = $this->accounts->find($verified);
            if ($user && $this->accounts->isStaff($user)) {
                throw new UserError('Use the manager to sign in to this account.');
            }
            $isNew = $user === null;
            $user ??= $this->accounts->create($verified, $name, true);
            $social->link($user, $key, $uid, $verified, $name ?: null);
            $this->accounts->activate($user);
            $this->accounts->signIn($user);
            $this->web->flash($isNew ? 'Welcome to Kiln! Your account is ready.' : "You’re signed in. $label is now connected to your account.");
            $this->web->redirect($this->web->returnTo());
        }
        // No verified email from the provider: prove one with a code.
        $this->web->session('social', ['provider' => $key, 'uid' => $uid, 'name' => $name]);
        $this->web->redirect($this->web->url('connect'));
    }

    // ---- the account page ------------------------------------------------------

    private function dashboard(?UserError $e = null): string
    {
        $user = $this->kiln->user();
        if (!$user) {
            $this->web->session('return', 'account');
            $this->web->redirect($this->web->url('signin'));
        }
        $uid = (int) $user->get('id');
        $api = $this->kiln->licenses();

        if ($e === null && $this->web->isPost()) {
            $this->web->checkCsrf();
            $this->dashboardAction($user);
        }

        $this->accounts->claim($user);
        $licences = $teams = [];
        $apiDown = false;
        try {
            $licences = $api->forAccount($uid);
            $teams = $api->teamsOwnedBy($uid);
        } catch (\Throwable $ex) {
            $apiDown = true;
            $this->kiln->log('account page: licence server: ' . $ex->getMessage());
        }

        $cards = '';
        foreach ($licences as $l) {
            $cards .= $this->licenceCard($l);
        }
        $teamHtml = '';
        foreach ($teams as $t) {
            $teamHtml .= $this->teamPanel($t);
        }
        $linked = $this->kiln->social()->linkedTo($user);
        $connect = '';
        foreach ($this->kiln->social()->enabled() as $key => $label) {
            $connect .= isset($linked[$key])
                ? '<li><span class="k-badge">' . $this->web->e($label) . '</span> connected</li>'
                : '<li><a href="' . $this->web->e($this->web->url('connect', ['provider' => $key])) . '">Connect ' . $this->web->e($label) . '</a></li>';
        }

        return $this->web->chunk('account.Dashboard', [
            'alert' => $this->alert($e) . ($apiDown ? '<div class="k-callout" role="status">Your licences can’t be shown right now. Please try again in a few minutes.</div>' : ''),
            'name' => $this->web->e($this->accounts->nameOf($user) ?: $this->accounts->emailOf($user)),
            'email' => $this->web->e($this->accounts->emailOf($user)),
            'licences' => $cards,
            'licence_count' => (string) count($licences),
            'teams' => $teamHtml,
            'connections' => $connect,
            'trial' => $this->trialOffer($licences),
            'orders' => $this->ordersList($uid),
            'url_buy' => $this->web->url('buy'),
            'url_download' => $this->web->url('download'),
        ]);
    }

    /**
     * The self-serve 7-day trial, only while kiln.trials_public is on, and only
     * for someone with no active licence who has never had a trial.
     */
    private function trialOffer(array $licences): string
    {
        if (!$this->kiln->opt('kiln.trials_public', false)) {
            return '';
        }
        foreach ($licences as $l) {
            if ($l['plan'] === 'trial' || ($l['status'] === 'active' && !$l['expired'])) {
                return '';
            }
        }
        return '<div class="k-card k-account__empty"><p><strong>Try Kiln Pro free for 7 days.</strong> Everything unlocked, no card needed. '
            . 'When it ends, Kiln goes back to Free unless you get Pro.</p>'
            . '<form method="post"><input type="hidden" name="csrf" value="' . $this->web->e($this->web->csrf()) . '"><input type="hidden" name="do" value="trial">'
            . '<button class="k-btn k-btn--heat" type="submit">Start my free trial</button></form></div>';
    }

    private function ordersList(int $uid): string
    {
        $shop = $this->kiln->shop();
        $rows = '';
        foreach ($shop->ordersFor($uid) as $o) {
            if ($o['status'] === 'pending') {
                continue; // an unfinished checkout isn't an order yet
            }
            $rows .= '<li><div><strong>' . $this->web->e($o['ref']) . '</strong> <span class="k-auth__hint">' . date('M j, Y', (int) $o['created_at']) . ' · '
                . $this->web->e($shop->summary($o)) . '</span></div><div class="k-orders__right"><span>' . $this->web->e($shop->money((int) $o['total_cents'], true)) . '</span>'
                . '<span class="k-badge">' . $this->web->e(Shop::STATUS_LABELS[$o['status']] ?? $o['status']) . '</span>'
                . ($o['status'] === 'fulfilled' ? '<form method="post"><input type="hidden" name="csrf" value="' . $this->web->e($this->web->csrf()) . '"><input type="hidden" name="do" value="email_receipt">'
                    . '<input type="hidden" name="order" value="' . $this->web->e($o['ref']) . '"><button class="k-linkbtn" type="submit">Email receipt</button></form>' : '')
                . ($o['status'] === 'fulfilled' && $o['provider_sub'] ? '<form method="post"><input type="hidden" name="csrf" value="' . $this->web->e($this->web->csrf()) . '"><input type="hidden" name="do" value="billing">'
                    . '<input type="hidden" name="order" value="' . $this->web->e($o['ref']) . '"><button class="k-linkbtn" type="submit">Manage billing</button></form>' : '')
                . '</div></li>';
        }
        return $rows;
    }

    /** Form posts on the account page: email a key or a receipt, start a trial, manage team seats. */
    private function dashboardAction(modUser $user): never
    {
        $uid = (int) $user->get('id');
        $api = $this->kiln->licenses();
        $do = $this->web->post('do', 20);

        if ($do === 'email_key') {
            $id = $this->web->post('license', 40);
            $mine = array_filter($api->forAccount($uid), fn ($l) => $l['id'] === $id);
            if (!$mine) {
                throw new UserError('That licence isn’t on your account.');
            }
            $this->sendLicenceEmail($this->accounts->emailOf($user), reset($mine), $this->accounts->nameOf($user));
            $this->web->flash('We’ve emailed that licence key to ' . $this->accounts->emailOf($user) . '.');
        } elseif ($do === 'email_receipt') {
            $o = $this->kiln->shop()->order($this->web->post('order', 20));
            if (!$o || (int) $o['user_id'] !== $uid || $o['status'] !== 'fulfilled') {
                throw new UserError('That order isn’t on your account.');
            }
            $this->kiln->shop()->email($o);
            $this->web->flash('We’ve emailed the receipt for ' . $o['ref'] . ' to ' . $o['email'] . '.');
        } elseif ($do === 'billing') {
            $o = $this->kiln->shop()->order($this->web->post('order', 20));
            if (!$o || (int) $o['user_id'] !== $uid || !$o['provider_sub']) {
                throw new UserError('That order isn’t on your account.');
            }
            $url = $this->kiln->shop()->manageUrl($o);
            if (!$url) {
                throw new UserError('We couldn’t open billing just now. Please try again, or email ' . $this->kiln->opt('kiln.support_email', 'hello@kilndev.com') . '.');
            }
            $this->web->redirect($url);
        } elseif ($do === 'trial' && $this->kiln->opt('kiln.trials_public', false)) {
            $email = $this->accounts->emailOf($user);
            try {
                $l = $api->call('POST', 'trials', ['email' => $email, 'accountRef' => (string) $uid, 'actor' => "web:user:$uid"], "trial:$uid");
            } catch (LicenseApiError $ex) {
                throw new UserError($ex->errorCode === 'trial_used' ? 'This email has already had a free trial.' : 'That didn’t work. Please try again.');
            }
            $this->sendLicenceEmail($email, $l, $this->accounts->nameOf($user));
            $this->web->flash('Your 7-day trial has started. Paste the key below into Kiln → Settings → License; we’ve emailed it to you too.');
        } elseif ($do === 'team_add' || $do === 'team_remove') {
            $teamId = $this->web->post('team', 20);
            $owned = array_filter($api->teamsOwnedBy($uid), fn ($t) => $t['id'] === $teamId);
            if (!$owned) {
                throw new UserError('That team isn’t on your account.');
            }
            $actor = "web:user:$uid";
            try {
                if ($do === 'team_add') {
                    $email = Accounts::email($this->web->post('email', 254));
                    $member = $this->accounts->find($email);
                    $team = $api->call('POST', "teams/$teamId/members", ['email' => $email, 'accountRef' => $member && $member->get('active') ? (string) $member->get('id') : null, 'actor' => $actor]);
                    $seat = array_values(array_filter($team['members'], fn ($l) => $l['email'] === $email && $l['status'] === 'active'))[0] ?? null;
                    if ($seat) {
                        $this->sendLicenceEmail($email, $seat, '', $this->accounts->nameOf($user) ?: $this->accounts->emailOf($user));
                    }
                    $this->web->flash("$email has a seat now. We’ve emailed them their licence key.");
                } else {
                    $api->call('DELETE', "teams/$teamId/members/" . rawurlencode($this->web->post('license', 40)) . '', ['actor' => $actor]);
                    $this->web->flash('Seat freed. That person’s key stops working after a 14-day grace period.');
                }
            } catch (LicenseApiError $ex) {
                throw new UserError(match ($ex->errorCode) {
                    'no_seats' => 'Every seat on this team is in use. Free one up, or add seats.',
                    'already_member' => 'That person already has a seat on this team.',
                    'bad_email' => 'Please enter a valid email address.',
                    default => 'That didn’t work. Please try again.',
                });
            }
        }
        $this->web->redirect($this->web->url('account'));
    }

    public function sendLicenceEmail(string $to, array $l, string $name = '', string $giftFrom = ''): bool
    {
        return $this->kiln->mailer()->send($to, 'Your Kiln licence key (' . $l['licenseId'] . ')', 'email.License', [
            'name' => $this->web->e($name),
            'gift_from' => $this->web->e($giftFrom),
            'plan' => self::PLAN_LABELS[$l['plan']] ?? 'Kiln Pro',
            'license_id' => $l['licenseId'],
            'serial' => $l['serial'],
            'expires' => $l['exp'] ? $this->date($l['exp']) : '',
            'url_account' => $this->web->url('account'),
            'url_register' => $this->web->url('register'),
            'url_download' => $this->web->url('download'),
            'accounts_open' => $this->kiln->accountsOpen() ? '1' : '',
        ]);
    }

    private function date(?string $ymd): string
    {
        return $ymd ? date('M j, Y', strtotime($ymd . 'T12:00:00Z')) : '';
    }

    private function licenceCard(array $l): string
    {
        [$status, $statusClass] = match (true) {
            $l['status'] === 'revoked' => ['Revoked', 'off'],
            $l['expired'] => ['Expired', 'off'],
            default => ['Active', 'on'],
        };
        $term = match (true) {
            $l['exp'] === null => 'Never expires',
            $l['plan'] === 'annual' && !$l['expired'] => 'Renews by ' . $this->date($l['paidThrough']),
            $l['expired'] => 'Expired ' . $this->date($l['exp']),
            default => 'Valid until ' . $this->date($l['exp']),
        };
        return $this->web->chunk('account.LicenseCard', [
            'id' => $this->web->e($l['id']),
            'license_id' => $this->web->e($l['licenseId']),
            'plan' => $this->web->e(self::PLAN_LABELS[$l['plan']] ?? $l['plan']),
            'serial' => $this->web->e($l['serial']),
            'status' => $status,
            'status_class' => $statusClass,
            'term' => $this->web->e($term),
            'issued' => $this->date($l['iat']),
            'team' => $this->web->e((string) $l['teamId']),
            'usable' => $statusClass === 'on' ? '1' : '0',
        ]);
    }

    private function teamPanel(array $t): string
    {
        $rows = '';
        foreach ($t['members'] as $m) {
            if ($m['status'] !== 'active') {
                continue;
            }
            $rows .= '<li><span>' . $this->web->e($m['email']) . '</span><form method="post"><input type="hidden" name="csrf" value="' . $this->web->csrf() . '">'
                . '<input type="hidden" name="do" value="team_remove"><input type="hidden" name="team" value="' . $this->web->e($t['id']) . '">'
                . '<input type="hidden" name="license" value="' . $this->web->e($m['id']) . '">'
                . '<button class="k-btn k-btn--ghost k-btn--sm" type="submit" data-k-confirm="Free this seat? Their key stops working after 14 days.">Remove</button></form></li>';
        }
        return $this->web->chunk('account.TeamPanel', [
            'id' => $this->web->e($t['id']),
            'name' => $this->web->e($t['name'] ?: ($t['plan'] === 'enterprise' ? 'Enterprise licence' : 'Team licence')),
            'seats' => (string) $t['seats'],
            'used' => (string) $t['seatsUsed'],
            'free' => (string) max(0, $t['seats'] - $t['seatsUsed']),
            'term' => $t['paidThrough'] ? 'Renews by ' . $this->date($t['paidThrough']) : 'No end date',
            'members' => $rows,
            'full' => $t['seatsUsed'] >= $t['seats'] ? '1' : '0',
        ]);
    }
}
