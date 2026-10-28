<?php

namespace KilnWeb\Processors\Licenses;

use KilnWeb\Processors\Base;

/** The licences grid: search, plan/status filters, one page at a time. */
final class GetList extends Base
{
    protected function handle(): mixed
    {
        $res = $this->api('GET', 'licenses?' . $this->query([
            'withTotal' => 1,
            'q' => $this->str('query', 120),
            'plan' => $this->str('plan', 20),
            'status' => $this->str('status', 20),
            'email' => $this->str('email'),
            'accountRef' => $this->str('accountRef', 20),
            'teamId' => $this->str('teamId', 20),
            'limit' => max(1, min(200, (int) $this->getProperty('limit', 25))),
            'offset' => max(0, (int) $this->getProperty('start', 0)),
        ]));
        $users = $this->users($res['items']);
        return $this->outputArray(array_map(fn ($l) => $this->row($l, $users), $res['items']), $res['total']);
    }
}
