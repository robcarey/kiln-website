<?php

namespace KilnWeb;

use Hybridauth\Hybridauth;
use Hybridauth\User\Profile;
use MODX\Revolution\modUser;

/**
 * Sign in with Google, GitHub or Facebook (Hybridauth 3). A provider shows up
 * only once its kiln.oauth_<provider>_id and _secret settings are filled in.
 * The callback URL to register with each provider is the Connect page:
 *   https://kilndev.com/account/connect?provider=google   (and github, facebook)
 *
 * Linking rule: a known identity signs in its account; otherwise a VERIFIED
 * email from the provider joins (or creates) the account with that email; with
 * no verified email the visitor proves one with a code first.
 */
final class Social
{
    public const PROVIDERS = [
        'google' => ['Google', 'Google', 'openid email profile'],
        'github' => ['GitHub', 'GitHub', 'read:user user:email'],
        'facebook' => ['Facebook', 'Facebook', 'email public_profile'],
    ];

    public function __construct(private Kiln $kiln)
    {
    }

    /** @return array<string,string> provider key => label, for the ones configured */
    public function enabled(): array
    {
        $out = [];
        foreach (self::PROVIDERS as $key => [$label]) {
            if ($this->kiln->opt("kiln.oauth_{$key}_id", '') !== '' && $this->kiln->opt("kiln.oauth_{$key}_secret", '') !== '') {
                $out[$key] = $label;
            }
        }
        return $out;
    }

    private function hybridauth(string $key): Hybridauth
    {
        [, $class, $scope] = self::PROVIDERS[$key];
        return new Hybridauth([
            'callback' => $this->kiln->web()->url('connect', ['provider' => $key]),
            'providers' => [$class => [
                'enabled' => true,
                'keys' => ['id' => (string) $this->kiln->opt("kiln.oauth_{$key}_id"), 'secret' => (string) $this->kiln->opt("kiln.oauth_{$key}_secret")],
                'scope' => $scope,
            ]],
        ]);
    }

    /**
     * Runs both legs: with no ?code it redirects to the provider and never
     * returns; on the way back it returns the profile.
     */
    public function authenticate(string $key): Profile
    {
        if (!isset($this->enabled()[$key])) {
            throw new UserError('That sign-in option isn’t available.');
        }
        $class = self::PROVIDERS[$key][1];
        $ha = $this->hybridauth($key);
        try {
            $adapter = $ha->authenticate($class);
            $profile = $adapter->getUserProfile();
            $adapter->disconnect();
            return $profile;
        } catch (\Throwable $e) {
            $this->kiln->log("social sign-in ($key) failed: " . $e->getMessage());
            throw new UserError(self::PROVIDERS[$key][0] . ' sign-in didn’t complete. Please try again, or use your email instead.');
        }
    }

    public function identityUser(string $key, string $uid): ?modUser
    {
        $db = $this->kiln->store();
        $row = $db->one('SELECT user_id FROM ' . $db->t('identities') . ' WHERE provider = ? AND provider_uid = ?', [$key, $uid]);
        if (!$row) {
            return null;
        }
        $db->run('UPDATE ' . $db->t('identities') . ' SET last_used_at = ? WHERE provider = ? AND provider_uid = ?', [time(), $key, $uid]);
        return $this->kiln->modx->getObject(modUser::class, (int) $row['user_id']) ?: null;
    }

    public function link(modUser $user, string $key, string $uid, ?string $email, ?string $name): void
    {
        $db = $this->kiln->store();
        $db->run(
            'INSERT INTO ' . $db->t('identities') . ' (user_id, provider, provider_uid, email, display_name, created_at, last_used_at) VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), email = VALUES(email), display_name = VALUES(display_name), last_used_at = VALUES(last_used_at)',
            [(int) $user->get('id'), $key, $uid, $email, $name !== null ? mb_substr($name, 0, 191) : null, time(), time()]
        );
    }

    /** @return array<string, array{email:?string, since:int}> the providers linked to this account */
    public function linkedTo(modUser $user): array
    {
        $db = $this->kiln->store();
        $out = [];
        foreach ($db->all('SELECT provider, email, created_at FROM ' . $db->t('identities') . ' WHERE user_id = ?', [(int) $user->get('id')]) as $r) {
            $out[$r['provider']] = ['email' => $r['email'], 'since' => (int) $r['created_at']];
        }
        return $out;
    }
}
