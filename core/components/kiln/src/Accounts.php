<?php

namespace KilnWeb;

use MODX\Revolution\modUser;
use MODX\Revolution\modUserProfile;

/**
 * Customer accounts are ordinary MODX users (username = lowercased email) in
 * the Customers group, with no manager access. An account stays inactive until
 * its email is proven with a code (or by Google/GitHub/Facebook); proving an
 * email also claims any licences already minted for it.
 */
final class Accounts
{
    public const GROUP = 'Customers';

    public function __construct(private Kiln $kiln)
    {
    }

    public static function email(string $email): string
    {
        $email = strtolower(trim($email));
        if ($email === '' || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new UserError('Please enter a valid email address.', 'email');
        }
        return $email;
    }

    public function find(string $email): ?modUser
    {
        $modx = $this->kiln->modx;
        $user = $modx->getObject(modUser::class, ['username' => $email]);
        if (!$user) {
            $profile = $modx->getObject(modUserProfile::class, ['email' => $email]);
            $user = $profile ? $modx->getObject(modUser::class, (int) $profile->get('internalKey')) : null;
        }
        return $user ?: null;
    }

    public function emailOf(modUser $user): string
    {
        $p = $user->getOne('Profile');
        return strtolower((string) ($p ? $p->get('email') : $user->get('username')));
    }

    public function nameOf(modUser $user): string
    {
        $p = $user->getOne('Profile');
        return (string) ($p ? $p->get('fullname') : '');
    }

    private function minLength(): int
    {
        return max(10, (int) $this->kiln->opt('password_min_length', 10));
    }

    public function checkPassword(string $password, string $confirm): void
    {
        if (mb_strlen($password) < $this->minLength()) {
            throw new UserError('Use a password of at least ' . $this->minLength() . ' characters.', 'password');
        }
        if ($password !== $confirm) {
            throw new UserError('The two passwords don’t match.', 'password_confirm');
        }
    }

    /**
     * New account, inactive until the code arrives. Registering again with an
     * email that never got verified just refreshes the details.
     */
    public function register(string $name, string $email, string $password): modUser
    {
        $email = self::email($email);
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new UserError('Please tell us your name.', 'name');
        }
        $user = $this->find($email);
        if ($user && $user->get('active')) {
            throw new UserError('There’s already an account for this email. Sign in, or reset your password if you’ve forgotten it.', 'email');
        }
        $user ??= $this->create($email, $name, false);
        $user->set('password', $password);
        $user->save();
        $profile = $user->getOne('Profile');
        $profile->set('fullname', $name);
        $profile->save();
        return $user;
    }

    /** A MODX user in the Customers group; social sign-ups arrive already active. */
    public function create(string $email, string $name, bool $active): modUser
    {
        $modx = $this->kiln->modx;
        /** @var modUser $user */
        $user = $modx->newObject(modUser::class);
        $user->set('username', $email);
        $user->set('password', bin2hex(random_bytes(24))); // replaced on register; social accounts may never use one
        $user->set('active', $active ? 1 : 0);
        /** @var modUserProfile $profile */
        $profile = $modx->newObject(modUserProfile::class);
        $profile->set('email', $email);
        $profile->set('fullname', $name);
        $user->addOne($profile);
        if (!$user->save()) {
            throw new UserError('We couldn’t create your account just now. Please try again.');
        }
        $user->joinGroup(self::GROUP, 'Member');
        return $user;
    }

    /** The email is proven: switch the account on and bring in waiting licences. */
    public function activate(modUser $user): void
    {
        if (!$user->get('active')) {
            $user->set('active', 1);
            $user->save();
        }
        $this->claim($user);
    }

    /** Ask the licence server to attach every unclaimed licence for this email. Best effort. */
    public function claim(modUser $user): int
    {
        try {
            return (int) ($this->kiln->licenses()->claim($this->emailOf($user), (int) $user->get('id'))['claimed'] ?? 0);
        } catch (\Throwable $e) {
            $this->kiln->log('licence claim failed for user ' . $user->get('id') . ': ' . $e->getMessage());
            return 0;
        }
    }

    public function passwordSignIn(string $email, string $password): modUser
    {
        $web = $this->kiln->web();
        $email = self::email($email);
        $db = $this->kiln->store();
        if (!$db->throttle('pw:ip:' . $web->ip(), 20, 900) || !$db->throttle('pw:email:' . $email, 8, 900)) {
            throw new UserError('Too many sign-in attempts. Wait a few minutes, or sign in with an email code instead.');
        }
        $user = $this->find($email);
        if (!$user || !$user->passwordMatches($password) || $this->isStaff($user)) {
            throw new UserError('That email and password don’t match an account.', 'password');
        }
        return $user;
    }

    /** Manager users never sign in through the public site. */
    public function isStaff(modUser $user): bool
    {
        return (bool) $user->get('sudo') || $user->isMember('Administrator');
    }

    public function setPassword(modUser $user, string $password): void
    {
        $user->set('password', $password);
        $user->save();
    }

    public function signIn(modUser $user): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $user->addSessionContext('web');
        $this->kiln->modx->user = $user;
        $profile = $user->getOne('Profile');
        if ($profile) {
            $profile->set('lastlogin', $profile->get('thislogin'));
            $profile->set('thislogin', time());
            $profile->set('logincount', (int) $profile->get('logincount') + 1);
            $profile->save();
        }
    }

    public function signOut(): void
    {
        $user = $this->kiln->user();
        if ($user) {
            $user->removeSessionContext('web');
        }
        $this->kiln->web()->session('csrf', null, true);
    }
}
