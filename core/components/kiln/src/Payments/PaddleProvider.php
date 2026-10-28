<?php

namespace KilnWeb\Payments;

use KilnWeb\Kiln;
use KilnWeb\Shop;

/**
 * Paddle Billing (merchant of record: Paddle sells, charges sales tax and VAT,
 * and pays out). How a purchase goes:
 *
 *   checkout   find or create the Paddle customer for the buyer's email, then
 *              create a transaction whose prices come from the site's own
 *              kiln.price_* settings (non-catalog prices on the three Kiln
 *              products), with custom_data.kiln_order = our order ref.
 *              /buy?checkout=REF opens Paddle's overlay for that transaction.
 *   paid       transaction.completed (origin web/api): fulfil. The order page
 *              also asks Paddle directly (reconcile), so a slow webhook never
 *              leaves the buyer staring at "waiting".
 *   renewal    transaction.completed with origin subscription_recurring: move
 *              every annual key and the team on to the new billing period.
 *   refund     adjustment.created/updated, refund or chargeback, approved and
 *              full: revoke everything and cancel the subscription.
 *   cancel     subscription.updated with a scheduled cancel, or
 *              subscription.canceled: stop showing "renews".
 *
 * Settings (area Sales): kiln.paddle_env (sandbox | live), kiln.paddle_api_key,
 * kiln.paddle_client_token, kiln.paddle_webhook_secret, kiln.paddle_product_annual,
 * kiln.paddle_product_lifetime, kiln.paddle_product_team. _build/paddle-setup.php
 * makes the products and the webhook destination.
 */
final class PaddleProvider implements PaymentProvider
{
    private const API = ['sandbox' => 'https://sandbox-api.paddle.com', 'live' => 'https://api.paddle.com'];
    private const SKEW = 300; // seconds a webhook signature stays good
    private const PRICE_NAMES = ['annual' => 'Annual licence', 'lifetime' => 'Lifetime licence', 'team' => 'Team seat (per year)'];

    public function __construct(private Kiln $kiln)
    {
    }

    public function key(): string
    {
        return 'paddle';
    }

    public function env(): string
    {
        return $this->kiln->opt('kiln.paddle_env', 'sandbox') === 'live' ? 'live' : 'sandbox';
    }

    public function testing(): bool
    {
        return $this->env() === 'sandbox';
    }

    public function clientToken(): string
    {
        return trim((string) $this->kiln->opt('kiln.paddle_client_token', ''));
    }

    public function problems(): array
    {
        $out = [];
        $env = $this->env();
        $key = $this->secret('kiln.paddle_api_key');
        $token = $this->clientToken();
        if ($key === '') {
            $out[] = 'API key (kiln.paddle_api_key) is empty.';
        } elseif (str_starts_with($key, 'pdl_') && !str_contains($key, $env === 'live' ? '_live_' : '_sdbx_')) {
            $out[] = "The API key is not a $env key.";
        }
        if ($token === '') {
            $out[] = 'Client-side token (kiln.paddle_client_token) is empty.';
        } elseif (!str_starts_with($token, $env === 'live' ? 'live_' : 'test_')) {
            $out[] = "The client-side token is not a $env token.";
        }
        if ($this->secret('kiln.paddle_webhook_secret') === '') {
            $out[] = 'Webhook secret (kiln.paddle_webhook_secret) is empty.';
        }
        foreach (Shop::PLANS as $plan) {
            if (trim((string) $this->kiln->opt("kiln.paddle_product_$plan", '')) === '') {
                $out[] = "Product for $plan (kiln.paddle_product_$plan) is empty: run _build/paddle-setup.php.";
            }
        }
        return $out;
    }

    public function ready(): bool
    {
        return !$this->problems();
    }

    // ---- checkout ----------------------------------------------------------------------

    public function createCheckout(array $order, string $successUrl, string $cancelUrl): array
    {
        $customer = $this->customerFor($order['email']);
        $items = [];
        foreach ($order['items'] as $i) {
            $plan = $i['plan'];
            $price = [
                'description' => Shop::ITEM_LABELS[$plan],
                'name' => self::PRICE_NAMES[$plan],
                'product_id' => trim((string) $this->kiln->opt("kiln.paddle_product_$plan")),
                'unit_price' => ['amount' => (string) (int) $i['unit_cents'], 'currency_code' => $order['currency']],
                'quantity' => $plan === 'team' ? ['minimum' => 1, 'maximum' => 500] : ['minimum' => 1, 'maximum' => 10],
                'tax_mode' => 'account_setting',
            ];
            if ($plan !== 'lifetime') {
                $price['billing_cycle'] = ['interval' => 'year', 'frequency' => 1];
            }
            $items[] = ['quantity' => (int) ($plan === 'team' ? $i['seats'] : $i['qty']), 'price' => $price];
        }
        $txn = $this->api('POST', '/transactions', [
            'items' => $items,
            'customer_id' => $customer,
            'currency_code' => $order['currency'],
            'collection_mode' => 'automatic',
            'custom_data' => ['kiln_order' => $order['ref'], 'kiln_user' => (string) $order['user_id']],
        ]);
        return ['url' => $this->kiln->web()->url('buy', ['checkout' => $order['ref']]), 'ref' => (string) $txn['id'], 'customer' => $customer];
    }

    /** The Paddle customer for this email, made the first time. */
    private function customerFor(string $email): string
    {
        $found = $this->api('GET', '/customers?email=' . rawurlencode($email));
        if (!empty($found[0]['id'])) {
            return (string) $found[0]['id'];
        }
        try {
            return (string) $this->api('POST', '/customers', ['email' => $email])['id'];
        } catch (\RuntimeException $e) {
            // made by someone else in the meantime (or archived): look again
            $found = $this->api('GET', '/customers?status=active,archived&email=' . rawurlencode($email));
            if (!empty($found[0]['id'])) {
                return (string) $found[0]['id'];
            }
            throw $e;
        }
    }

    // ---- webhooks ------------------------------------------------------------------------

    public function verifyWebhook(string $body, array $headers): bool
    {
        $secret = $this->secret('kiln.paddle_webhook_secret');
        $ts = null;
        $sigs = [];
        foreach (explode(';', (string) ($headers['paddle-signature'] ?? '')) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 'ts') {
                $ts = $v;
            } elseif ($k === 'h1' && $v !== '') {
                $sigs[] = $v; // more than one while a secret is being rotated
            }
        }
        if ($secret === '' || $ts === null || !ctype_digit($ts) || abs(time() - (int) $ts) > self::SKEW) {
            return false;
        }
        $want = hash_hmac('sha256', $ts . ':' . $body, $secret);
        foreach ($sigs as $sig) {
            if (hash_equals($want, $sig)) {
                return true;
            }
        }
        return false;
    }

    public function mapEvent(string $body): ?array
    {
        $e = json_decode($body, true);
        if (!is_array($e) || !isset($e['event_id'], $e['event_type'], $e['data']) || !is_array($e['data'])) {
            return null;
        }
        $d = $e['data'];
        $id = (string) $e['event_id'];
        switch ($e['event_type']) {
            case 'transaction.completed':
                return $this->fromTransaction($id, $d);
            case 'subscription.created':
                $ref = $this->refFrom($d['custom_data'] ?? null) ?? $this->orderByTxn($d['transaction_id'] ?? null);
                return $ref ? $this->event($id, 'subscribed', $ref, $d['customer_id'] ?? null, (string) $d['id']) : null;
            case 'subscription.canceled':
            case 'subscription.updated':
                $ref = $this->orderBySubscription($d['id'] ?? null) ?? $this->refFrom($d['custom_data'] ?? null);
                if (!$ref) {
                    return null;
                }
                $cancelling = $e['event_type'] === 'subscription.canceled' || ($d['scheduled_change']['action'] ?? null) === 'cancel';
                if (!$cancelling && (($d['status'] ?? '') !== 'active' || !empty($d['scheduled_change']))) {
                    return null; // paused, past due, …: Paddle chases those
                }
                return $this->event($id, $cancelling ? 'cancelled' : 'resumed', $ref, $d['customer_id'] ?? null, (string) $d['id']);
            case 'adjustment.created':
            case 'adjustment.updated':
                if (!in_array($d['action'] ?? '', ['refund', 'chargeback'], true) || ($d['status'] ?? '') !== 'approved' || ($d['type'] ?? '') !== 'full') {
                    return null; // pending, rejected, credits and partial refunds: nothing to revoke (partial ones are handled by hand)
                }
                $ref = $this->orderByTxn($d['transaction_id'] ?? null) ?? $this->orderBySubscription($d['subscription_id'] ?? null);
                return $ref ? ['txn' => $d['transaction_id'] ?? null] + $this->event($id, 'refunded', $ref, $d['customer_id'] ?? null, $d['subscription_id'] ?? null) : null;
        }
        return null;
    }

    public function reconcile(array $order): ?array
    {
        $txn = (string) ($order['provider_ref'] ?? '');
        if (!str_starts_with($txn, 'txn_')) {
            return null;
        }
        $d = $this->api('GET', '/transactions/' . rawurlencode($txn));
        if (!in_array($d['status'] ?? '', ['paid', 'completed'], true)) {
            return null;
        }
        return $this->fromTransaction('reconcile:' . $txn, $d);
    }

    private function fromTransaction(string $id, array $d): ?array
    {
        $ref = $this->refFrom($d['custom_data'] ?? null) ?? $this->orderBySubscription($d['subscription_id'] ?? null);
        if (!$ref) {
            return null;
        }
        $end = (string) ($d['billing_period']['ends_at'] ?? '');
        return [
            'periodEnd' => preg_match('/^\d{4}-\d{2}-\d{2}/', $end) ? substr($end, 0, 10) : null,
            'amountCents' => isset($d['details']['totals']['grand_total']) ? (int) $d['details']['totals']['grand_total'] : null,
            'txn' => (string) $d['id'],
        ] + $this->event($id, ($d['origin'] ?? '') === 'subscription_recurring' ? 'renewed' : 'paid', $ref, $d['customer_id'] ?? null, $d['subscription_id'] ?? null);
    }

    private function event(string $id, string $type, string $ref, ?string $customer, ?string $subscription): array
    {
        return ['id' => $id, 'type' => $type, 'orderRef' => $ref, 'customer' => $customer, 'subscription' => $subscription];
    }

    private function refFrom(mixed $custom): ?string
    {
        $ref = is_array($custom) ? (string) ($custom['kiln_order'] ?? '') : '';
        return preg_match('/^K-[A-Z0-9]{8}$/', $ref) ? $ref : null;
    }

    private function orderBySubscription(?string $sub): ?string
    {
        if (!$sub) {
            return null;
        }
        $db = $this->kiln->store();
        return $db->value('SELECT ref FROM ' . $db->t('orders') . " WHERE provider = 'paddle' AND provider_sub = ? ORDER BY id LIMIT 1", [$sub]);
    }

    private function orderByTxn(?string $txn): ?string
    {
        if (!$txn) {
            return null;
        }
        $db = $this->kiln->store();
        return $db->value('SELECT ref FROM ' . $db->t('orders') . " WHERE provider = 'paddle' AND provider_ref = ? LIMIT 1", [$txn])
            ?? $db->value('SELECT order_ref FROM ' . $db->t('payment_events') . " WHERE provider = 'paddle' AND provider_txn = ? LIMIT 1", [$txn]);
    }

    // ---- after the sale ----------------------------------------------------------------------

    public function refund(array $order, ?string $txn, string $reason): bool
    {
        $txn = $txn ?: (string) $order['provider_ref'];
        if (!str_starts_with($txn, 'txn_')) {
            return false;
        }
        $this->api('POST', '/adjustments', ['action' => 'refund', 'type' => 'full', 'transaction_id' => $txn, 'reason' => mb_substr($reason, 0, 200) ?: 'refund']);
        return true;
    }

    public function stopRenewal(array $order): void
    {
        if (empty($order['provider_sub'])) {
            return;
        }
        try {
            $this->api('POST', '/subscriptions/' . rawurlencode($order['provider_sub']) . '/cancel', ['effective_from' => 'immediately']);
        } catch (\RuntimeException $e) {
            $this->kiln->log("paddle: could not cancel subscription {$order['provider_sub']} of {$order['ref']}: " . $e->getMessage());
        }
    }

    public function manageUrl(array $order): ?string
    {
        if (empty($order['provider_customer'])) {
            return null;
        }
        $body = !empty($order['provider_sub']) ? ['subscription_ids' => [$order['provider_sub']]] : [];
        $s = $this->api('POST', '/customers/' . rawurlencode($order['provider_customer']) . '/portal-sessions', $body);
        return $s['urls']['general']['overview'] ?? null; // Paddle's customer portal: card, invoices, cancel
    }

    // ---- the API -------------------------------------------------------------------------------

    /**
     * One call to Paddle's API (also used by _build/paddle-setup.php); returns its `data`.
     *
     * @throws \RuntimeException with Paddle's error code and detail
     */
    public function api(string $method, string $path, ?array $body = null): mixed
    {
        $key = $this->secret('kiln.paddle_api_key');
        if ($key === '') {
            throw new \RuntimeException('paddle: no API key');
        }
        $base = self::API[$this->env()];
        $override = rtrim(trim((string) $this->kiln->opt('kiln.paddle_api_url', '')), '/');
        if ($override !== '' && $this->env() === 'sandbox') {
            $base = $override; // the test suite's stand-in Paddle; never used live
        }
        $ch = curl_init($base . $path);
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json', 'Accept: application/json', 'Paddle-Version: 1'],
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body ?: new \stdClass(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opts);
        $res = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($res === false) {
            throw new \RuntimeException('paddle unreachable: ' . curl_error($ch));
        }
        $json = json_decode((string) $res, true);
        if ($status >= 300 || !is_array($json) || !array_key_exists('data', $json)) {
            $err = is_array($json) ? ($json['error'] ?? []) : [];
            throw new \RuntimeException("paddle $method $path: $status " . ($err['code'] ?? 'bad_reply') . ' ' . ($err['detail'] ?? substr((string) $res, 0, 200)), $status);
        }
        return $json['data'];
    }

    private function secret(string $key): string
    {
        return trim((string) $this->kiln->opt($key, ''));
    }
}
