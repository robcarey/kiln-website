<?php

namespace KilnWeb\Payments;

/**
 * A payment provider (PaddleProvider for real money, TestProvider for building
 * and testing). The shop never trusts the browser: an order is paid only when
 * the provider says so, through its signed webhook or when asked directly
 * (reconcile), and every event is applied once (keyed on its id).
 */
interface PaymentProvider
{
    /** Short name stored on orders and used in the webhook URL (?provider=…). */
    public function key(): string;

    /** Whether it can take payments right now (keys present, test mode on, …). */
    public function ready(): bool;

    /** What stops ready() being true, in words for the manager page. @return list<string> */
    public function problems(): array;

    /** True when no real money moves (the test provider, Paddle's sandbox). */
    public function testing(): bool;

    /**
     * Start paying for a pending order.
     *
     * @param array $order a kiln_orders row with 'items'
     * @return array{url: string, ref: string} where to send the buyer, and the provider's own id for this checkout
     */
    public function createCheckout(array $order, string $successUrl, string $cancelUrl): array;

    /** @param array<string, string> $headers lower-cased names */
    public function verifyWebhook(string $body, array $headers): bool;

    /**
     * Translate a verified webhook into the shop's terms, or null for events we ignore.
     *
     * @return array{id: string, type: 'paid'|'renewed'|'refunded'|'cancelled'|'resumed'|'subscribed', orderRef: string,
     *               periodEnd?: ?string, amountCents?: ?int, txn?: ?string, subscription?: ?string, customer?: ?string}|null
     */
    public function mapEvent(string $body): ?array;

    /** Ask the provider whether a pending order has been paid; a 'paid' event (as mapEvent makes) or null. */
    public function reconcile(array $order): ?array;

    /** Ask the provider to give the money back. False when it can't (refund by hand there). */
    public function refund(array $order, ?string $txn, string $reason): bool;

    /** Stop a refunded order's subscription so it never charges again. */
    public function stopRenewal(array $order): void;

    /** Where the buyer updates their card or cancels renewal, or null. */
    public function manageUrl(array $order): ?string;
}
