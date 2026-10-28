<?php

namespace KilnWeb\Processors\Licenses;

use KilnWeb\Processors\Base;

/** One licence with its audit trail, for the details window. */
final class Get extends Base
{
    protected function handle(): mixed
    {
        $l = $this->api('GET', 'licenses/' . rawurlencode($this->str('id', 40)));
        $row = $this->row($l, $this->users([$l]));
        $row['audit'] = $this->api('GET', 'audit?' . $this->query(['licenseId' => $l['id'], 'limit' => 50]));
        return $this->success('', $row);
    }
}
