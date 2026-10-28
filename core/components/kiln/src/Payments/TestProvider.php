<?php

namespace KilnWeb\Payments;

use KilnWeb\Kiln;

/**
 * A pretend provider for building and testing the shop: its "checkout" is a
 * page on /buy with Pay and Cancel buttons, and its webhooks are JSON signed
 * with a key derived from kiln.app_secret. No money moves.
 *
 * Only usable while kiln.payments_test_mode is on, so it can never take a
 * real order on the live site by accident.
 */
final class TestProvider implements PaymentProvider
{
    public function __construct(private Kiln $kiln)
    {
    }

    public function key(): string
    {
        return 'test';
    }

    public function ready(): bool
    {
        return (bool) $this->kiln->opt('kiln.payments_test_mode', false);
    }

    public function problems(): array
    {
        return $this->ready() ? [] : ['Test Payments (kiln.payments_test_mode) is off.'];
    }

    public function testing(): bool
    {
        return true;
    }

    public function reconcile(array $order): ?array
    {
        return null; // its only payments are its own webhooks
    }

    public function refund(array $order, ?string $txn, string $reason): bool
    {
        return false;
    }

    public function stopRenewal(array $order): void
    {
    }

    public function manageUrl(array $order): ?string
    {
        return null;
    }

    public function createCheckout(array $order, string $successUrl, string $cancelUrl): array
    {
        return ['url' => $this->kiln->web()->url('buy', ['checkout' => $order['ref']]), 'ref' => 'test_cs_' . bin2hex(random_bytes(8))];
    }

    public function verifyWebhook(string $body, array $headers): bool
    {
        $sig = (string) ($headers['x-kiln-test-signature'] ?? '');
        return $sig !== '' && hash_equals(hash_hmac('sha256', $body, $this->secret()), $sig);
    }

    public function mapEvent(string $body): ?array
    {
        $e = json_decode($body, true);
        if (!is_array($e) || !isset($e['id'], $e['type'], $e['orderRef']) || !in_array($e['type'], ['paid', 'renewed', 'refunded', 'cancelled'], true)) {
            return null;
        }
        return [
            'id' => (string) $e['id'],
            'type' => $e['type'],
            'orderRef' => (string) $e['orderRef'],
            'periodEnd' => isset($e['periodEnd']) ? (string) $e['periodEnd'] : null,
            'amountCents' => isset($e['amountCents']) ? (int) $e['amountCents'] : null,
            'txn' => null,
        ];
    }

    /**
     * A signed webhook, as the test checkout page, the manager and the tests send them.
     *
     * @return array{0: string, 1: array<string, string>} body and headers
     */
    public function event(string $type, string $orderRef, array $extra = []): array
    {
        $body = json_encode(['id' => 'evt_test_' . bin2hex(random_bytes(8)), 'type' => $type, 'orderRef' => $orderRef] + $extra, JSON_UNESCAPED_SLASHES);
        return [$body, ['x-kiln-test-signature' => hash_hmac('sha256', $body, $this->secret())]];
    }

    private function secret(): string
    {
        return hash_hmac('sha256', 'kiln-test-payments', (string) $this->kiln->opt('kiln.app_secret', ''));
    }
}
