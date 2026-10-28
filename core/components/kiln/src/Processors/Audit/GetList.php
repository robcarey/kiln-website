<?php

namespace KilnWeb\Processors\Audit;

use KilnWeb\Processors\Base;

/** The licence server's audit log, newest first. */
final class GetList extends Base
{
    protected function handle(): mixed
    {
        $licence = $this->str('licenseId', 40);
        if (preg_match('/^KILN-/i', $licence)) {
            $licence = $this->api('GET', 'licenses/' . rawurlencode($licence))['id'];
        }
        $rows = $this->api('GET', 'audit?' . $this->query([
            'licenseId' => $licence,
            'teamId' => $this->str('teamId', 20),
            'actor' => $this->str('actor', 120),
            'action' => $this->str('auditAction', 40),
            // an unpaged MODX grid sends limit=0: that means "the default", not "one row"
            'limit' => min(500, (int) $this->getProperty('limit', 0) ?: 200),
        ]));
        foreach ($rows as &$r) {
            $r['detail'] = $r['detail'] === null ? '' : json_encode($r['detail'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        return $this->outputArray($rows, count($rows));
    }
}
