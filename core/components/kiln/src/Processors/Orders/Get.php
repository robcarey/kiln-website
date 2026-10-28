<?php

namespace KilnWeb\Processors\Orders;

use KilnWeb\Processors\Base;
use KilnWeb\UserError;

/** One order: its items, the licences and team it made, and every payment event. */
final class Get extends Base
{
    protected function handle(): mixed
    {
        $shop = $this->kiln->shop();
        $o = $shop->order($this->str('ref', 20)) ?? throw new UserError('No such order.');
        $items = [];
        foreach ($o['items'] as $i) {
            $licences = [];
            foreach ($i['license_ids'] as $id) {
                $l = $this->api('GET', 'licenses/' . $id);
                $licences[] = ['id' => $l['id'], 'licenseId' => $l['licenseId'], 'state' => $l['status'] === 'revoked' ? 'revoked' : ($l['expired'] ? 'expired' : 'active'), 'exp' => $l['exp']];
            }
            $items[] = [
                'plan' => $i['plan'], 'qty' => (int) $i['qty'], 'seats' => $i['seats'] !== null ? (int) $i['seats'] : null,
                'line' => $shop->money((int) $i['line_cents']), 'licences' => $licences, 'teamId' => $i['team_id'],
            ];
        }
        $db = $this->kiln->store();
        $events = array_map(static fn ($e) => [
            'at' => date('Y-m-d H:i', (int) $e['received_at']), 'type' => $e['type'], 'id' => $e['event_id'],
            'processed' => (bool) $e['processed_at'], 'error' => (string) $e['error'], 'periodEnd' => $e['period_end'],
        ], $db->all('SELECT * FROM ' . $db->t('payment_events') . ' WHERE order_ref = ? ORDER BY id', [$o['ref']]));
        $when = static fn ($ts) => $ts ? date('Y-m-d H:i', (int) $ts) : '';
        return $this->success('', GetList::orderRow($shop, $o) + [
            'items' => $items,
            'events' => $events,
            'providerRef' => (string) $o['provider_ref'],
            'providerSub' => (string) $o['provider_sub'],
            'providerCustomer' => (string) $o['provider_customer'],
            'paidAt' => $when($o['paid_at']),
            'fulfilledAt' => $when($o['fulfilled_at']),
            'refundedAt' => $when($o['refunded_at']),
            'emailedAt' => $when($o['emailed_at']),
        ]);
    }
}
