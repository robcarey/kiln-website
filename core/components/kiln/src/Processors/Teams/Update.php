<?php

namespace KilnWeb\Processors\Teams;

use KilnWeb\Processors\Base;

/** Seats, name, paid-through (a renewal re-signs every active seat) or cancel. */
final class Update extends Base
{
    protected function handle(): mixed
    {
        $body = [];
        if ($this->str('seats', 6) !== '') {
            $body['seats'] = (int) $this->getProperty('seats');
        }
        if ($this->getProperty('name') !== null) {
            $body['name'] = $this->str('name', 120) ?: null;
        }
        if ($this->flag('noEnd')) {
            $body['paidThrough'] = null;
        } elseif (($paid = $this->date('paidThrough')) !== null) {
            $body['paidThrough'] = $paid;
        }
        if (in_array($this->str('status', 20), ['active', 'cancelled'], true)) {
            $body['status'] = $this->str('status', 20);
        }
        $t = $this->api('PATCH', 'teams/' . rawurlencode($this->str('id', 20)), $body);
        return $this->success("Team {$t['id']} saved.", $t);
    }
}
