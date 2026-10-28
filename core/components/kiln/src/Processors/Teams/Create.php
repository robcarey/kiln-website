<?php

namespace KilnWeb\Processors\Teams;

use KilnWeb\Accounts;
use KilnWeb\Processors\Base;
use KilnWeb\UserError;

/**
 * A Team (bought by the seat, renews yearly) or an Enterprise licence (custom
 * seats and terms, invoiced by hand). The owner manages the seats from their
 * account; members listed here get a seat and their key straight away.
 */
final class Create extends Base
{
    protected function handle(): mixed
    {
        $plan = $this->str('plan', 20) === 'enterprise' ? 'enterprise' : 'team';
        $owner = Accounts::email($this->str('ownerEmail'));
        $members = preg_split('/[\s,;]+/', $this->str('members', 20000), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $members = array_values(array_unique(array_map([Accounts::class, 'email'], $members)));
        $paid = $this->date('paidThrough');
        if ($plan === 'team' && $paid === null) {
            throw new UserError('A Team renews yearly, so it needs a paid-through date.');
        }
        $ownerUser = $this->customer($owner);
        $t = $this->api('POST', 'teams', [
            'plan' => $plan,
            'seats' => (int) $this->getProperty('seats', 0),
            'ownerEmail' => $owner,
            'ownerAccountRef' => $ownerUser ? (string) $ownerUser->get('id') : null,
            'paidThrough' => $paid,
            'name' => $this->str('name', 120) ?: null,
            'orderRef' => $this->str('orderRef', 100) ?: null,
            'notes' => $this->str('notes', 2000) ?: null,
        ]);
        $sent = 0;
        foreach ($members as $email) {
            $sent += $this->seat($t['id'], $email, $this->flag('send'), $t['name'] ?: $owner) ? 1 : 0;
        }
        return $this->success(
            ($plan === 'enterprise' ? 'Enterprise licence ' : 'Team ') . "{$t['id']} created for $owner with {$t['seats']} seats."
            . ($members ? ' ' . count($members) . ' seat(s) given out' . ($sent ? ", $sent key(s) emailed." : '.') : '')
            . ($ownerUser ? '' : ' The owner manages it from their account once they sign up with that email.'),
            $t
        );
    }
}
