<?php

namespace KilnWeb\Processors\Teams;

use KilnWeb\Processors\Base;

final class GetList extends Base
{
    protected function handle(): mixed
    {
        $teams = $this->api('GET', 'teams?' . $this->query(['q' => $this->str('query', 120), 'limit' => 500]));
        return $this->outputArray($teams, count($teams));
    }
}
