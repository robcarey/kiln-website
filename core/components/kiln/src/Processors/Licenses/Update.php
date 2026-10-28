<?php

namespace KilnWeb\Processors\Licenses;

use KilnWeb\Accounts;
use KilnWeb\Processors\Base;
use KilnWeb\UserError;

/** revoke, unrevoke, extend, renew (annual) or transfer one licence. */
final class Update extends Base
{
    protected function handle(): mixed
    {
        $op = $this->str('op', 20);
        $path = 'licenses/' . rawurlencode($this->str('id', 40)) . '/';
        $l = match ($op) {
            'revoke' => $this->api('POST', $path . 'revoke', ['reason' => $this->str('reason', 500) ?: null]),
            'unrevoke' => $this->api('POST', $path . 'unrevoke', []),
            'extend' => $this->api('POST', $path . 'extend', [
                'exp' => $this->flag('never') ? null : ($this->date('exp') ?? throw new UserError('Pick a new expiry date, or tick “Never expires”.')),
            ]),
            'renew' => $this->api('POST', $path . 'renew', [
                'paidThrough' => $this->date('paidThrough') ?? throw new UserError('Pick the new paid-through date.'),
            ]),
            'transfer' => $this->transfer($path),
            default => throw new UserError('Unknown action.'),
        };
        $sent = $op !== 'revoke' && $this->flag('send') && $this->email($l);
        $msg = match ($op) {
            'revoke' => "Revoked {$l['licenseId']}. Kiln drops to Free on that machine after the 14-day grace period.",
            'unrevoke' => "{$l['licenseId']} is active again.",
            'extend' => "{$l['licenseId']} now " . ($l['exp'] ? "runs until {$l['exp']}." : 'never expires.'),
            'renew' => "{$l['licenseId']} is paid through {$l['paidThrough']}.",
            'transfer' => "{$l['licenseId']} now belongs to {$l['email']}.",
        };
        return $this->success($msg . ($sent ? " We emailed the key to {$l['email']}." : ''), $this->row($l, $this->users([$l])));
    }

    private function transfer(string $path): array
    {
        $email = Accounts::email($this->str('email'));
        $user = $this->customer($email);
        return $this->api('POST', $path . 'transfer', ['email' => $email, 'accountRef' => $user ? (string) $user->get('id') : null]);
    }
}
