<?php

namespace KilnWeb\Processors\Teams;

use KilnWeb\Accounts;
use KilnWeb\Processors\Base;

/** Give a seat to an email (and send their key), or free a seat (revokes that key). */
final class Member extends Base
{
    protected function handle(): mixed
    {
        $id = $this->str('id', 20);
        if ($this->str('op', 10) === 'remove') {
            $this->api('DELETE', 'teams/' . rawurlencode($id) . '/members/' . rawurlencode($this->str('license', 40)), []);
            return $this->success('Seat freed. That key stops working after the 14-day grace period.');
        }
        $email = Accounts::email($this->str('email'));
        $t = $this->api('GET', 'teams/' . rawurlencode($id));
        $sent = $this->seat($id, $email, $this->flag('send'), $t['name'] ?: $t['ownerEmail']);
        return $this->success("$email has a seat on $id." . ($sent ? ' We emailed them their key.' : ''));
    }
}
