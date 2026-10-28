<?php

namespace KilnWeb\Processors\Customers;

use KilnWeb\Accounts;
use KilnWeb\Processors\Base;

/**
 * Everything about one email: its MODX account (if any), linked sign-ins, the
 * licences issued to it or held on its account, and the teams it owns.
 */
final class Get extends Base
{
    protected function handle(): mixed
    {
        $email = Accounts::email($this->str('email'));
        $user = $this->kiln->accounts()->find($email);
        $account = null;
        $byAccount = [];
        $teams = $this->api('GET', 'teams?' . $this->query(['ownerEmail' => $email, 'limit' => 100]));
        if ($user) {
            $p = $user->getOne('Profile');
            $account = [
                'id' => (int) $user->get('id'),
                'username' => $user->get('username'),
                'name' => $p ? $p->get('fullname') : '',
                'active' => (bool) $user->get('active'),
                'blocked' => $p ? (bool) $p->get('blocked') : false,
                'staff' => $this->kiln->accounts()->isStaff($user),
                'createdon' => self::when($user->get('createdon')),
                'lastLogin' => $p ? self::when($p->get('thislogin')) : '',
                'logins' => $p ? (int) $p->get('logincount') : 0,
                'identities' => $this->kiln->social()->linkedTo($user),
                'editUrl' => $this->modx->getOption('manager_url') . '?a=security/user/update&id=' . (int) $user->get('id'),
            ];
            $byAccount = $this->api('GET', 'licenses?' . $this->query(['accountRef' => (string) $user->get('id'), 'limit' => 200]));
            $seen = array_column($teams, 'id');
            foreach ($this->api('GET', 'teams?' . $this->query(['ownerAccountRef' => (string) $user->get('id'), 'limit' => 100])) as $t) {
                if (!in_array($t['id'], $seen, true)) {
                    $teams[] = $t;
                }
            }
        }
        $licences = [];
        foreach (array_merge($this->api('GET', 'licenses?' . $this->query(['email' => $email, 'limit' => 200])), $byAccount) as $l) {
            $licences[$l['id']] = $l;
        }
        $users = $this->users($licences);
        $shop = $this->kiln->shop();
        [$orders] = $shop->search($user ? ['userId' => (int) $user->get('id')] : ['email' => $email], 0, 50);
        return $this->success('', [
            'email' => $email,
            'account' => $account,
            'licenses' => array_values(array_map(fn ($l) => $this->row($l, $users), $licences)),
            'teams' => $teams,
            'orders' => array_map(static fn ($o) => \KilnWeb\Processors\Orders\GetList::orderRow($shop, $o), $orders),
        ]);
    }

    /** MODX keeps modUser.createdon as a datetime and the profile's logins as unix seconds. */
    private static function when(mixed $v): string
    {
        $ts = is_numeric($v) ? (int) $v : (is_string($v) && $v !== '' ? strtotime($v) : 0);
        return $ts > 0 ? date('Y-m-d H:i', $ts) : '';
    }
}
