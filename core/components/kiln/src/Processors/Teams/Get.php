<?php

namespace KilnWeb\Processors\Teams;

use KilnWeb\Processors\Base;

/** A team with its members, for the team window. */
final class Get extends Base
{
    protected function handle(): mixed
    {
        $t = $this->api('GET', 'teams/' . rawurlencode($this->str('id', 20)));
        $users = $this->users($t['members']);
        $t['members'] = array_map(fn ($l) => $this->row($l, $users), $t['members']);
        return $this->success('', $t);
    }
}
