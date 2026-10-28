<?php

namespace KilnWeb\Processors\Licenses;

use KilnWeb\Accounts;
use KilnWeb\Processors\Base;
use KilnWeb\UserError;

/** Email a licence key again, to its holder or to another address. */
final class Resend extends Base
{
    protected function handle(): mixed
    {
        $l = $this->api('GET', 'licenses/' . rawurlencode($this->str('id', 40)));
        if ($l['status'] !== 'active') {
            throw new UserError('That licence is revoked. Unrevoke it before sending it again.');
        }
        $to = $this->str('to') !== '' ? Accounts::email($this->str('to')) : $l['email'];
        if (!$this->email($l, $to)) {
            throw new UserError("The email to $to could not be sent. The MODX error log has the reason.");
        }
        return $this->success("Emailed {$l['licenseId']} to $to.");
    }
}
