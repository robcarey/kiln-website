<?php

namespace KilnWeb\Processors\Licenses;

use KilnWeb\Accounts;
use KilnWeb\Processors\Base;
use KilnWeb\UserError;

/**
 * Mint an individual licence for any email: annual, lifetime, complimentary or
 * a 7-day trial. If that email already has an active account the licence goes
 * straight onto it; otherwise it waits and is claimed when they sign up and
 * verify that address.
 */
final class Create extends Base
{
    protected function handle(): mixed
    {
        $email = Accounts::email($this->str('email'));
        $plan = $this->str('plan', 20);
        $user = $this->customer($email);
        $ref = $user ? (string) $user->get('id') : null;
        $notes = $this->str('notes', 2000) ?: null;

        if ($plan === 'trial') {
            $l = $this->api('POST', 'trials', ['email' => $email, 'accountRef' => $ref, 'force' => $this->flag('force'), 'notes' => $notes]);
        } elseif (in_array($plan, ['annual', 'lifetime', 'comp'], true)) {
            $body = ['email' => $email, 'plan' => $plan, 'accountRef' => $ref, 'notes' => $notes, 'orderRef' => $this->str('orderRef', 100) ?: null];
            if ($plan === 'annual') {
                $body['paidThrough'] = $this->date('paidThrough') ?? throw new UserError('An annual licence needs a paid-through date.');
            } elseif ($plan === 'comp') {
                $body['exp'] = $this->date('exp');
            }
            $l = $this->api('POST', 'licenses', $body);
        } else {
            throw new UserError('Team and Enterprise licences are made on the Teams tab.');
        }

        $sent = $this->flag('send') && $this->email($l);
        return $this->success(
            "Minted {$l['licenseId']} for $email."
            . ($sent ? ' We emailed them the key.' : '')
            . ($ref ? ' It is on their account.' : ' It will appear on their account when they sign up with this email.'),
            $this->row($l, $user ? [(int) $user->get('id') => $user] : [])
        );
    }
}
