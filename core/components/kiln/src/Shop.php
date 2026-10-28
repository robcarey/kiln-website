<?php

namespace KilnWeb;

use KilnWeb\Payments\PaddleProvider;
use KilnWeb\Payments\PaymentProvider;
use KilnWeb\Payments\TestProvider;
use MODX\Revolution\modUser;

/**
 * Selling Kiln Pro: the sales switch, prices, the cart, orders, and what a
 * payment provider's webhooks do (fulfil, renew, refund).
 *
 * The switch is one system setting, kiln.sales:
 *   soon   - not on sale yet (/buy explains; prices say "Launch pricing soon")
 *   open   - on sale
 *   paused - temporarily off; prices still show, checkout says it's paused
 * Extras → Kiln Licences flips it in one click and clears the cache.
 * Webhooks are always honoured whatever the switch says: if money was taken,
 * the licence is issued.
 *
 * Fulfilment is safe to repeat: each licence is minted with an idempotency key
 * made from the order, item and number, and recorded on the item as it goes,
 * so a retried webhook finishes the job without minting twice.
 */
final class Shop
{
    public const MODES = ['soon' => 'Coming soon', 'open' => 'Open', 'paused' => 'Paused'];
    public const PLANS = ['annual', 'lifetime', 'team'];
    public const ITEM_LABELS = ['annual' => 'Kiln Pro · Annual', 'lifetime' => 'Kiln Pro · Lifetime', 'team' => 'Kiln Pro · Team'];
    public const STATUS_LABELS = ['pending' => 'Awaiting payment', 'paid' => 'Paid', 'fulfilled' => 'Complete', 'refunded' => 'Refunded', 'cancelled' => 'Cancelled'];
    private const REF_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const MAX_QTY = 10;
    private const MAX_SEATS = 500;

    public function __construct(private Kiln $kiln)
    {
    }

    // ---- the sales switch ------------------------------------------------------------

    public function mode(): string
    {
        $m = strtolower(trim((string) $this->kiln->opt('kiln.sales', 'soon')));
        return isset(self::MODES[$m]) ? $m : 'soon';
    }

    public function isOpen(): bool
    {
        return $this->mode() === 'open';
    }

    /** What /buy says while sales aren't open: kiln.sales_message, or a default for the mode. */
    public function closedMessage(): string
    {
        $custom = trim((string) $this->kiln->opt('kiln.sales_message', ''));
        if ($custom !== '') {
            return $custom;
        }
        return $this->mode() === 'paused'
            ? 'Purchases are paused for a little while. Please check back soon. Licences you already have keep working.'
            : 'Kiln Pro goes on sale soon. Until then the free version runs one site with the complete stack, and upgrading later is just a matter of pasting your licence key into Kiln.';
    }

    public function provider(?string $key = null): ?PaymentProvider
    {
        return match ($key ?? (string) $this->kiln->opt('kiln.payment_provider', 'test')) {
            'test' => new TestProvider($this->kiln),
            'paddle' => new PaddleProvider($this->kiln),
            default => null,
        };
    }

    /** Open, and a provider that can take money right now. */
    public function canCheckout(): bool
    {
        $p = $this->provider();
        return $this->isOpen() && $p !== null && $p->ready();
    }

    /** The chosen provider is ready but no real money moves (test provider, Paddle sandbox). */
    public function testMode(): bool
    {
        $p = $this->provider();
        return $p !== null && $p->ready() && $p->testing();
    }

    /** The manager may send simulated events (renew, refund) to test orders. */
    public function canSimulate(): bool
    {
        return $this->provider('test')->ready();
    }

    // ---- prices ------------------------------------------------------------------------

    /** Price in cents: per year (annual), once (lifetime), per seat per year (team). */
    public function price(string $plan): int
    {
        $key = ['annual' => 'kiln.price_annual', 'lifetime' => 'kiln.price_lifetime', 'team' => 'kiln.price_team_seat'][$plan] ?? null;
        return $key ? (int) round(100 * (float) $this->kiln->opt($key, 0)) : 0;
    }

    public function currency(): string
    {
        return strtoupper(trim((string) $this->kiln->opt('kiln.currency', 'USD'))) ?: 'USD';
    }

    public function money(int $cents, bool $withCode = false): string
    {
        $s = '$' . number_format($cents / 100, $cents % 100 ? 2 : 0);
        return $withCode ? $s . ' ' . $this->currency() : $s;
    }

    public function minSeats(): int
    {
        return max(1, (int) $this->kiln->opt('kiln.team_min_seats', 3));
    }

    // ---- the cart: ['annual' => qty, 'lifetime' => qty, 'team' => seats] in the session --

    public function cart(): array
    {
        $c = $this->kiln->web()->session('cart');
        return is_array($c) ? $c : [];
    }

    public function setCart(array $cart): void
    {
        $clean = [];
        foreach (self::PLANS as $plan) {
            $n = (int) ($cart[$plan] ?? 0);
            if ($n > 0) {
                $clean[$plan] = $plan === 'team' ? min(self::MAX_SEATS, max($this->minSeats(), $n)) : min(self::MAX_QTY, $n);
            }
        }
        $clean ? $this->kiln->web()->session('cart', $clean) : $this->kiln->web()->session('cart', null, true);
    }

    /** @return list<array{plan: string, label: string, qty: int, seats: ?int, unit: int, line: int}> */
    public function lines(array $cart): array
    {
        $out = [];
        foreach (self::PLANS as $plan) {
            $n = (int) ($cart[$plan] ?? 0);
            if ($n <= 0) {
                continue;
            }
            $unit = $this->price($plan);
            $out[] = $plan === 'team'
                ? ['plan' => $plan, 'label' => self::ITEM_LABELS[$plan], 'qty' => 1, 'seats' => $n, 'unit' => $unit, 'line' => $unit * $n]
                : ['plan' => $plan, 'label' => self::ITEM_LABELS[$plan], 'qty' => $n, 'seats' => null, 'unit' => $unit, 'line' => $unit * $n];
        }
        return $out;
    }

    public function total(array $lines): int
    {
        return array_sum(array_column($lines, 'line'));
    }

    // ---- orders --------------------------------------------------------------------------

    public function createOrder(modUser $user, array $lines): array
    {
        if (!$lines) {
            throw new UserError('Your cart is empty.');
        }
        foreach ($lines as $l) {
            if ($l['unit'] <= 0) {
                throw new UserError('That plan isn’t for sale right now.');
            }
        }
        $db = $this->kiln->store();
        $p = $this->provider();
        do {
            $ref = 'K-';
            for ($i = 0; $i < 8; $i++) {
                $ref .= self::REF_ALPHABET[random_int(0, strlen(self::REF_ALPHABET) - 1)];
            }
        } while ($db->value('SELECT 1 FROM ' . $db->t('orders') . ' WHERE ref = ?', [$ref]));
        $db->run(
            'INSERT INTO ' . $db->t('orders') . ' (ref, user_id, email, status, currency, total_cents, provider, test, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$ref, (int) $user->get('id'), $this->kiln->accounts()->emailOf($user), 'pending', $this->currency(), $this->total($lines), $p ? $p->key() : '', $this->testMode() ? 1 : 0, time()]
        );
        $id = $db->lastId();
        foreach ($lines as $l) {
            $db->run(
                'INSERT INTO ' . $db->t('order_items') . ' (order_id, plan, qty, seats, unit_cents, line_cents) VALUES (?, ?, ?, ?, ?, ?)',
                [$id, $l['plan'], $l['qty'], $l['seats'], $l['unit'], $l['line']]
            );
        }
        return $this->order($ref);
    }

    /** An order with its items (license_ids decoded), by ref. */
    public function order(string $ref): ?array
    {
        $db = $this->kiln->store();
        $o = $db->one('SELECT * FROM ' . $db->t('orders') . ' WHERE ref = ?', [strtoupper(trim($ref))]);
        if (!$o) {
            return null;
        }
        $o['items'] = array_map(static function (array $i): array {
            $i['license_ids'] = $i['license_ids'] ? (json_decode($i['license_ids'], true) ?: []) : [];
            return $i;
        }, $db->all('SELECT * FROM ' . $db->t('order_items') . ' WHERE order_id = ? ORDER BY id', [$o['id']]));
        return $o;
    }

    public function ordersFor(int $userId): array
    {
        $db = $this->kiln->store();
        $refs = $db->all('SELECT ref FROM ' . $db->t('orders') . " WHERE user_id = ? AND status <> 'cancelled' ORDER BY id DESC LIMIT 50", [$userId]);
        return array_map(fn ($r) => $this->order($r['ref']), $refs);
    }

    /** For the manager grid. @return array{0: list<array>, 1: int} */
    public function search(array $f, int $start, int $limit): array
    {
        $db = $this->kiln->store();
        $where = [];
        $args = [];
        if (($q = trim((string) ($f['query'] ?? ''))) !== '') {
            $where[] = '(ref = ? OR email LIKE ? OR provider_ref = ?)';
            array_push($args, strtoupper($q), '%' . addcslashes(strtolower($q), '%_') . '%', $q);
        }
        if (!empty($f['status'])) {
            $where[] = 'status = ?';
            $args[] = (string) $f['status'];
        }
        if (!empty($f['userId'])) {
            $where[] = 'user_id = ?';
            $args[] = (int) $f['userId'];
        }
        if (!empty($f['email'])) {
            $where[] = 'email = ?';
            $args[] = strtolower((string) $f['email']);
        }
        $cond = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $total = (int) $db->value('SELECT COUNT(*) FROM ' . $db->t('orders') . $cond, $args);
        $refs = $db->all('SELECT ref FROM ' . $db->t('orders') . $cond . ' ORDER BY id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $start), $args);
        return [array_map(fn ($r) => $this->order($r['ref']), $refs), $total];
    }

    /** One line for lists: "Annual ×2, Team (5 seats)". */
    public function summary(array $order): string
    {
        $parts = [];
        foreach ($order['items'] as $i) {
            $name = ucfirst($i['plan']);
            $parts[] = $i['plan'] === 'team' ? "$name ({$i['seats']} seats)" : ($i['qty'] > 1 ? "$name ×{$i['qty']}" : $name);
        }
        return implode(', ', $parts);
    }

    /** Hand a pending order to the provider; returns where to send the buyer. */
    public function checkout(array $order): string
    {
        $p = $this->provider();
        if (!$p || !$p->ready()) {
            throw new UserError('Checkout isn’t available right now. Please try again shortly.');
        }
        $web = $this->kiln->web();
        try {
            $co = $p->createCheckout($order, $web->url('buy', ['order' => $order['ref']]), $web->url('buy'));
        } catch (\RuntimeException $e) {
            $this->kiln->log("checkout for {$order['ref']} failed: " . $e->getMessage());
            $this->cancel($order);
            throw new UserError('We couldn’t start the checkout just now. Your cart is saved; please try again in a minute.');
        }
        $this->update($order['id'], ['provider' => $p->key(), 'provider_ref' => $co['ref'], 'provider_customer' => $co['customer'] ?? null]);
        return $co['url'];
    }

    /**
     * A pending order the buyer is waiting on: ask the provider directly, and if
     * it was paid, apply that just as the webhook would (the webhook, when it
     * comes, is then a no-op). Returns the order as it now stands.
     */
    public function reconcile(array $order): array
    {
        $p = $order['status'] === 'pending' ? $this->provider($order['provider']) : null;
        if (!$p || !$p->ready()) {
            return $order;
        }
        try {
            $e = $p->reconcile($order);
        } catch (\RuntimeException $ex) {
            $this->kiln->log("reconcile {$order['ref']}: " . $ex->getMessage());
            return $order;
        }
        if ($e && $e['orderRef'] === $order['ref']) {
            $this->record($p, $e);
        }
        return $this->order($order['ref']);
    }

    /**
     * Rob refunds an order from the manager. With a provider that can (Paddle),
     * the money goes back there and the keys are revoked when its "refund
     * approved" webhook arrives. Otherwise it is marked refunded here and now.
     *
     * @return bool true when the provider took the refund request
     */
    public function requestRefund(array $o, string $by): bool
    {
        $p = $this->provider($o['provider']);
        if ($p && $p->ready() && !$p instanceof TestProvider) {
            $db = $this->kiln->store();
            $txn = $db->value('SELECT provider_txn FROM ' . $db->t('payment_events') . " WHERE provider = ? AND order_ref = ? AND type IN ('paid', 'renewed') AND provider_txn IS NOT NULL ORDER BY id DESC LIMIT 1", [$p->key(), $o['ref']]);
            if ($p->refund($o, $txn, "Refund of Kiln order {$o['ref']} by $by")) {
                $this->kiln->log("refund of {$o['ref']} requested at {$p->key()} by $by");
                return true;
            }
        }
        $this->refund($o, $by);
        return false;
    }

    /** Where the buyer manages their subscription (card, cancel), if the provider has such a page. */
    public function manageUrl(array $o): ?string
    {
        $p = $this->provider($o['provider']);
        try {
            return $p && $p->ready() ? $p->manageUrl($o) : null;
        } catch (\RuntimeException $e) {
            $this->kiln->log("manage link for {$o['ref']}: " . $e->getMessage());
            return null;
        }
    }

    /** The buyer backed out before paying. Their cart comes back. */
    public function cancel(array $order): void
    {
        if ($order['status'] !== 'pending') {
            return;
        }
        $this->update($order['id'], ['status' => 'cancelled', 'cancelled_at' => time()]);
        $cart = [];
        foreach ($order['items'] as $i) {
            $cart[$i['plan']] = $i['plan'] === 'team' ? (int) $i['seats'] : (int) $i['qty'];
        }
        $this->setCart($cart);
    }

    // ---- webhooks --------------------------------------------------------------------------

    /**
     * A provider's webhook, verified and applied once per event id.
     * 500 means "we couldn't finish, send it again" (the provider retries).
     *
     * @return array{0: int, 1: string} HTTP status and a short message
     */
    public function webhook(string $providerKey, string $body, array $headers): array
    {
        $p = $this->provider($providerKey);
        if (!$p) {
            return [404, 'unknown provider'];
        }
        if (!$p->ready()) {
            return [403, 'provider not enabled'];
        }
        if (!$p->verifyWebhook($body, $headers)) {
            return [401, 'bad signature'];
        }
        try {
            $e = $p->mapEvent($body);
        } catch (\Throwable $ex) {
            $this->kiln->log("payment webhook ({$p->key()}) could not be read: " . $ex->getMessage());
            return [500, 'not processed yet'];
        }
        return $e === null ? [200, 'ignored'] : $this->record($p, $e);
    }

    /** Store an event once by id and apply it. @return array{0: int, 1: string} */
    private function record(PaymentProvider $p, array $e): array
    {
        $db = $this->kiln->store();
        $t = $db->t('payment_events');
        $row = $db->one("SELECT * FROM $t WHERE provider = ? AND event_id = ?", [$p->key(), $e['id']]);
        if ($row && $row['processed_at']) {
            return [200, 'duplicate'];
        }
        if (!$row) {
            $db->run(
                "INSERT INTO $t (provider, event_id, type, order_ref, provider_txn, amount_cents, period_end, received_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$p->key(), $e['id'], $e['type'], $e['orderRef'], $e['txn'] ?? null, $e['amountCents'] ?? null, $e['periodEnd'] ?? null, time()]
            );
        }
        try {
            $msg = $this->apply($e);
            $db->run("UPDATE $t SET processed_at = ?, error = NULL WHERE provider = ? AND event_id = ?", [time(), $p->key(), $e['id']]);
            return [200, $msg];
        } catch (\Throwable $ex) {
            $db->run("UPDATE $t SET error = ? WHERE provider = ? AND event_id = ?", [mb_substr($ex->getMessage(), 0, 1000), $p->key(), $e['id']]);
            $this->kiln->log("payment event {$e['id']} ({$e['type']} {$e['orderRef']}): " . $ex->getMessage());
            return [500, 'not processed yet'];
        }
    }

    private function apply(array $e): string
    {
        $o = $this->order($e['orderRef']);
        if (!$o) {
            $this->kiln->log("payment event {$e['id']} for unknown order {$e['orderRef']}");
            return 'unknown order';
        }
        // The provider's subscription and customer ids, the first time an event names them.
        $link = [];
        foreach (['subscription' => 'provider_sub', 'customer' => 'provider_customer'] as $from => $col) {
            if (!empty($e[$from]) && empty($o[$col])) {
                $link[$col] = (string) $e[$from];
            }
        }
        if ($link) {
            $this->update($o['id'], $link);
            $o = $this->order($o['ref']);
        }
        switch ($e['type']) {
            case 'paid':
                if (in_array($o['status'], ['refunded', 'fulfilled'], true)) {
                    return 'already ' . $o['status'];
                }
                if ($o['status'] !== 'paid') {
                    // a cancelled order can still be paid (checkout closed after paying): the money was taken, so it is fulfilled
                    $renews = (bool) array_filter($o['items'], static fn ($i) => $i['plan'] !== 'lifetime');
                    $end = !empty($e['periodEnd']) ? $e['periodEnd'] : gmdate('Y-m-d', strtotime('+1 year'));
                    $this->update($o['id'], ['status' => 'paid', 'paid_at' => time(), 'period_end' => $renews ? $end : null]);
                }
                $this->fulfil($this->order($o['ref']));
                return 'fulfilled';
            case 'renewed':
                return $this->renew($o, $e['periodEnd'] ?? null);
            case 'refunded':
                $this->refund($o, 'provider');
                return 'refunded';
            case 'cancelled':
                $this->update($o['id'], ['auto_renew' => 0]);
                return 'renewal cancelled';
            case 'resumed':
                if ($o['status'] === 'fulfilled' && !(int) $o['auto_renew']) {
                    $this->update($o['id'], ['auto_renew' => 1]);
                    return 'renewal back on';
                }
                return 'no change';
            case 'subscribed':
                return $link ? 'linked' : 'no change';
        }
        return 'ignored';
    }

    /** Mint what a paid order bought, onto the buyer's account, then email it all. */
    public function fulfil(array $o): void
    {
        $api = $this->kiln->licenses();
        $user = $this->kiln->modx->getObject(modUser::class, (int) $o['user_id']);
        $ref = $user && $user->get('active') ? (string) $user->get('id') : null;
        $actor = 'web:order:' . $o['ref'];
        $db = $this->kiln->store();
        foreach ($o['items'] as $item) {
            if ($item['plan'] === 'team') {
                if (!$item['team_id']) {
                    $t = $api->call('POST', 'teams', [
                        'plan' => 'team', 'seats' => (int) $item['seats'], 'ownerEmail' => $o['email'], 'ownerAccountRef' => $ref,
                        'paidThrough' => $o['period_end'], 'orderRef' => $o['ref'], 'actor' => $actor,
                    ], "order:{$o['ref']}:{$item['id']}:team");
                    $db->run('UPDATE ' . $db->t('order_items') . ' SET team_id = ? WHERE id = ?', [$t['id'], $item['id']]);
                }
                continue;
            }
            $ids = $item['license_ids'];
            for ($n = count($ids); $n < (int) $item['qty']; $n++) {
                $body = ['email' => $o['email'], 'plan' => $item['plan'], 'accountRef' => $ref, 'orderRef' => $o['ref'], 'actor' => $actor];
                if ($item['plan'] === 'annual') {
                    $body['paidThrough'] = $o['period_end'];
                }
                $ids[] = $api->call('POST', 'licenses', $body, "order:{$o['ref']}:{$item['id']}:$n")['id'];
                $db->run('UPDATE ' . $db->t('order_items') . ' SET license_ids = ? WHERE id = ?', [json_encode($ids), $item['id']]);
            }
        }
        $this->update($o['id'], ['status' => 'fulfilled', 'fulfilled_at' => time()]);
        $o = $this->order($o['ref']);
        if (!$o['emailed_at'] && $this->email($o)) {
            $this->update($o['id'], ['emailed_at' => time()]);
        }
    }

    /** A subscription renewal: re-sign every annual key and the team for the new period. */
    public function renew(array $o, ?string $periodEnd): string
    {
        if ($o['status'] !== 'fulfilled') {
            return 'order is ' . $o['status'];
        }
        if (!array_filter($o['items'], static fn ($i) => $i['plan'] !== 'lifetime')) {
            return 'nothing renews';
        }
        $base = $o['period_end'] ?: gmdate('Y-m-d');
        $new = $periodEnd && preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodEnd) ? $periodEnd : gmdate('Y-m-d', strtotime($base . ' +1 year'));
        if ($o['period_end'] && $new <= $o['period_end']) {
            return 'already renewed';
        }
        $api = $this->kiln->licenses();
        $actor = 'web:order:' . $o['ref'];
        foreach ($o['items'] as $item) {
            if ($item['plan'] === 'annual') {
                foreach ($item['license_ids'] as $id) {
                    $api->call('POST', "licenses/$id/renew", ['paidThrough' => $new, 'actor' => $actor], "renew:{$o['ref']}:$new:$id");
                }
            } elseif ($item['plan'] === 'team' && $item['team_id']) {
                $api->call('PATCH', 'teams/' . $item['team_id'], ['paidThrough' => $new, 'actor' => $actor], "renew:{$o['ref']}:$new:team");
            }
        }
        $this->update($o['id'], ['period_end' => $new]);
        $this->email($this->order($o['ref']), 'renewal');
        return 'renewed to ' . $new;
    }

    /** Refunded (or charged back): every key from this order stops working, after the app's 14-day grace. */
    public function refund(array $o, string $by): void
    {
        if ($o['status'] === 'refunded') {
            return;
        }
        $api = $this->kiln->licenses();
        $actor = 'web:order:' . $o['ref'] . ":refund:$by";
        foreach ($o['items'] as $item) {
            foreach ($item['license_ids'] as $id) {
                $api->call('POST', "licenses/$id/revoke", ['reason' => 'refund ' . $o['ref'], 'actor' => $actor], "refund:{$o['ref']}:$id");
            }
            if ($item['team_id']) {
                foreach ($api->call('GET', 'licenses?teamId=' . rawurlencode($item['team_id']) . '&status=active&limit=500') as $seat) {
                    $api->call('POST', "licenses/{$seat['id']}/revoke", ['reason' => 'refund ' . $o['ref'], 'actor' => $actor], "refund:{$o['ref']}:{$seat['id']}");
                }
                $api->call('PATCH', 'teams/' . $item['team_id'], ['status' => 'cancelled', 'actor' => $actor], "refund:{$o['ref']}:team");
            }
        }
        $this->update($o['id'], ['status' => 'refunded', 'refunded_at' => time(), 'auto_renew' => 0]);
        // a refunded subscription must never charge again
        $this->provider($o['provider'])?->stopRenewal($o);
    }

    // ---- email ------------------------------------------------------------------------------

    /** The receipt with every key in it (kind: order | renewal). */
    public function email(array $o, string $kind = 'order'): bool
    {
        $api = $this->kiln->licenses();
        $web = $this->kiln->web();
        $rows = '';
        $keys = '';
        $team = '';
        foreach ($o['items'] as $item) {
            $what = $item['plan'] === 'team' ? self::ITEM_LABELS['team'] . " · {$item['seats']} seats" : self::ITEM_LABELS[$item['plan']] . ($item['qty'] > 1 ? " × {$item['qty']}" : '');
            $rows .= '<tr><td style="padding:8px 0;border-bottom:1px solid #eee">' . $web->e($what) . '</td><td style="padding:8px 0;border-bottom:1px solid #eee;text-align:right">'
                . $web->e($this->money((int) $item['line_cents'])) . '</td></tr>';
            foreach ($item['license_ids'] as $id) {
                $l = $api->call('GET', 'licenses/' . $id);
                $keys .= '<p style="margin:16px 0 6px;font-size:13px;color:#120d0b"><strong>' . $web->e($l['licenseId']) . '</strong> · '
                    . $web->e(AccountController::PLAN_LABELS[$l['plan']] ?? $l['plan']) . ($l['exp'] ? ' · valid until ' . $web->e($l['exp']) : ' · never expires') . '</p>'
                    . '<div style="font-family:\'JetBrains Mono\',Consolas,monospace;font-size:12px;line-height:1.5;word-break:break-all;padding:12px 14px;border-radius:10px;background:#fff4ec;color:#7c2d12;border:1px solid #fed7aa">'
                    . $web->e($l['serial']) . '</div>';
            }
            if ($item['team_id']) {
                $team .= '<p style="font-size:15px;line-height:1.6;color:#4a403b">Your team <strong>' . $web->e($item['team_id']) . '</strong> has ' . (int) $item['seats']
                    . ' seats' . ($o['period_end'] ? ', paid through ' . $web->e($o['period_end']) : '') . '. Hand them out from <a href="' . $web->e($web->url('account')) . '" style="color:#c2410c">your account</a>: each person gets their own key by email.</p>';
            }
        }
        $user = $this->kiln->modx->getObject(modUser::class, (int) $o['user_id']);
        $subject = $kind === 'renewal' ? "Your Kiln Pro renewal ({$o['ref']})" : "Your Kiln order {$o['ref']}";
        return $this->kiln->mailer()->send($o['email'], $subject, 'email.Order', [
            'name' => $user ? $web->e($this->kiln->accounts()->nameOf($user)) : '',
            'heading' => $kind === 'renewal' ? 'Your Kiln Pro has renewed' : 'Thanks for buying Kiln Pro',
            'intro' => $kind === 'renewal'
                ? 'Your subscription renewed' . ($o['period_end'] ? ' and now runs to ' . $web->e($o['period_end']) : '') . '. Kiln picks up the renewed key by itself; it’s also below.'
                : 'Your order is complete. Your licence keys are below and on your account page.'
                    . ($o['provider'] === 'paddle' ? ' Paddle, who handles payment for us, emails your tax receipt separately; the charge shows as PADDLE.NET* on your statement.' : ''),
            'ref' => $web->e($o['ref']),
            'date' => gmdate('M j, Y', (int) ($o['paid_at'] ?: time())),
            'rows' => $rows,
            'total' => $web->e($this->money((int) $o['total_cents'], true)),
            'keys' => $keys,
            'team' => $team,
            'test' => $o['test'] ? '1' : '',
            'url_account' => $web->url('account'),
            'url_download' => $web->url('download'),
        ]);
    }

    private function update(int|string $id, array $set): void
    {
        $db = $this->kiln->store();
        $cols = implode(', ', array_map(static fn ($k) => "$k = ?", array_keys($set)));
        $db->run('UPDATE ' . $db->t('orders') . " SET $cols WHERE id = ?", [...array_values($set), (int) $id]);
    }
}
