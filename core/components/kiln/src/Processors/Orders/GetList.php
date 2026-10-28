<?php

namespace KilnWeb\Processors\Orders;

use KilnWeb\Processors\Base;
use KilnWeb\Shop;

/** The orders grid: newest first, search by order, email or provider reference. */
final class GetList extends Base
{
    protected function handle(): mixed
    {
        $shop = $this->kiln->shop();
        [$orders, $total] = $shop->search(
            ['query' => $this->str('query', 120), 'status' => $this->str('status', 16), 'email' => $this->str('email'), 'userId' => (int) $this->getProperty('userId', 0)],
            max(0, (int) $this->getProperty('start', 0)),
            min(200, (int) $this->getProperty('limit', 0) ?: 25)
        );
        return $this->outputArray(array_map(static fn ($o) => self::orderRow($shop, $o), $orders), $total);
    }

    public static function orderRow(Shop $shop, array $o): array
    {
        return [
            'ref' => $o['ref'],
            'created' => date('Y-m-d H:i', (int) $o['created_at']),
            'email' => $o['email'],
            'userId' => (int) $o['user_id'],
            'summary' => $shop->summary($o),
            'total' => $shop->money((int) $o['total_cents'], true),
            'status' => $o['status'],
            'statusLabel' => Shop::STATUS_LABELS[$o['status']] ?? $o['status'],
            'provider' => $o['provider'],
            'test' => (bool) $o['test'],
            'periodEnd' => $o['period_end'],
            'autoRenew' => (bool) $o['auto_renew'],
        ];
    }
}
