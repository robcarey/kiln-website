<?php

namespace KilnWeb;

/**
 * Four-letter email codes for verifying an address, signing in without a
 * password, resetting a password and confirming a social login's email.
 *
 * 24 letters (no I or O) give 331,776 codes. A code lasts 15 minutes, allows
 * 5 tries, and a newer code for the same email and purpose replaces it. At
 * most 3 codes per email and 10 per address are sent per 15 minutes. Only an
 * HMAC of the code is stored.
 */
final class Codes
{
    public const PURPOSES = ['verify', 'signin', 'reset', 'link'];
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    private const TTL = 900;
    private const TRIES = 5;

    public function __construct(private Kiln $kiln)
    {
    }

    private function hash(string $email, string $purpose, string $code): string
    {
        return hash_hmac('sha256', "$purpose|$email|$code", (string) $this->kiln->opt('kiln.app_secret', 'kiln'));
    }

    public static function normalise(string $code): string
    {
        return preg_replace('/[^A-Z]/', '', strtoupper($code)) ?? '';
    }

    /** Make a code, store its hash and email it. Returns the code (for tests). */
    public function send(string $email, string $purpose, ?int $userId = null, array $payload = []): string
    {
        $db = $this->kiln->store();
        $ip = $this->kiln->web()->ip();
        $since = time() - self::TTL;
        $t = $db->t('auth_codes');
        if ((int) $db->value("SELECT COUNT(*) FROM $t WHERE email = ? AND created_at > ?", [$email, $since]) >= 3
            || (int) $db->value("SELECT COUNT(*) FROM $t WHERE ip = ? AND created_at > ?", [$ip, $since]) >= 10) {
            throw new UserError('We’ve sent a few codes already. Please wait a few minutes and try again.');
        }
        $code = '';
        for ($i = 0; $i < 4; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        $now = time();
        $db->run("UPDATE $t SET used_at = ? WHERE email = ? AND purpose = ? AND used_at IS NULL", [$now, $email, $purpose]);
        $db->run(
            "INSERT INTO $t (email, purpose, code_hash, user_id, payload, expires_at, ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [$email, $purpose, $this->hash($email, $purpose, $code), $userId, $payload ? json_encode($payload) : null, $now + self::TTL, $ip, $now]
        );

        $what = [
            'verify' => ['Confirm your email', 'Enter this code to finish creating your Kiln account.'],
            'signin' => ['Your sign-in code', 'Enter this code to sign in to your Kiln account.'],
            'reset' => ['Reset your password', 'Enter this code to choose a new password for your Kiln account.'],
            'link' => ['Confirm your email', 'Enter this code to connect your email to your Kiln account.'],
        ][$purpose];
        $this->kiln->mailer()->send($email, "$code is your Kiln code", 'email.Code', [
            'code' => $code,
            'heading' => $what[0],
            'intro' => $what[1],
            'minutes' => (string) (self::TTL / 60),
        ]);
        return $code;
    }

    /**
     * Check a code; on success it is spent and its row (with decoded payload)
     * returned. Wrong codes count against the tries left.
     */
    public function check(string $email, string $purpose, string $code): array
    {
        $db = $this->kiln->store();
        $t = $db->t('auth_codes');
        $row = $db->one("SELECT * FROM $t WHERE email = ? AND purpose = ? AND used_at IS NULL ORDER BY id DESC LIMIT 1", [$email, $purpose]);
        if (!$row || (int) $row['expires_at'] < time()) {
            throw new UserError('That code has expired. Send yourself a new one.', 'code');
        }
        if ((int) $row['attempts'] >= self::TRIES) {
            throw new UserError('Too many tries with that code. Send yourself a new one.', 'code');
        }
        if (!hash_equals($row['code_hash'], $this->hash($email, $purpose, self::normalise($code)))) {
            $db->run("UPDATE $t SET attempts = attempts + 1 WHERE id = ?", [$row['id']]);
            $left = self::TRIES - (int) $row['attempts'] - 1;
            throw new UserError($left > 0 ? "That code isn’t right. $left " . ($left === 1 ? 'try' : 'tries') . ' left.' : 'Too many tries with that code. Send yourself a new one.', 'code');
        }
        $db->run("UPDATE $t SET used_at = ? WHERE id = ?", [time(), $row['id']]);
        $row['payload'] = $row['payload'] ? json_decode($row['payload'], true) : [];
        return $row;
    }
}
