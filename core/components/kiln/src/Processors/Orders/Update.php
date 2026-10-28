<?php

namespace KilnWeb\Processors\Orders;

use KilnWeb\Payments\TestProvider;
use KilnWeb\Processors\Base;
use KilnWeb\UserError;

/**
 * resend          email the receipt and keys again
 * refund          mark refunded and revoke every key (after refunding the money at the provider)
 * renew_test      test payments only: send a signed "renewed" webhook through the real handler
 * refund_test     test payments only: send a signed "refunded" webhook
 */
final class Update extends Base
{
    protected function handle(): mixed
    {
        $shop = $this->kiln->shop();
        $o = $shop->order($this->str('ref', 20)) ?? throw new UserError('No such order.');
        $op = $this->str('op', 20);
        switch ($op) {
            case 'resend':
                if ($o['status'] !== 'fulfilled') {
                    throw new UserError('Only complete orders have keys to send.');
                }
                if (!$shop->email($o)) {
                    throw new UserError('The email could not be sent. The MODX error log has the reason.');
                }
                return $this->success("Emailed the receipt and keys for {$o['ref']} to {$o['email']}.");
            case 'refund':
                if ($o['status'] !== 'fulfilled' && $o['status'] !== 'paid') {
                    throw new UserError('Only paid orders can be refunded.');
                }
                try {
                    $atProvider = $shop->requestRefund($o, 'admin:' . $this->modx->user->get('username'));
                } catch (\RuntimeException $e) {
                    $this->kiln->log("refund of {$o['ref']}: " . $e->getMessage());
                    throw new UserError('Paddle refused the refund: ' . $e->getMessage());
                }
                if ($atProvider) {
                    return $this->success("Refund of {$o['ref']} requested at Paddle. The money goes back once Paddle approves it (sandbox: within ten minutes), and then its keys are revoked automatically and the subscription is cancelled.");
                }
                return $this->success("{$o['ref']} is marked refunded and its keys are revoked (they stop working after the 14-day grace period). Refund the money at the payment provider if you haven’t.");
            case 'renew_test':
            case 'refund_test':
                $test = $shop->provider('test');
                if (!$test instanceof TestProvider || !$test->ready() || $o['provider'] !== 'test') {
                    throw new UserError('Simulated events are only for test orders while test payments are on.');
                }
                $type = $op === 'renew_test' ? 'renewed' : 'refunded';
                $extra = $type === 'renewed' && $o['period_end'] ? ['periodEnd' => gmdate('Y-m-d', strtotime($o['period_end'] . ' +1 year'))] : [];
                [$body, $headers] = $test->event($type, $o['ref'], $extra);
                [$code, $msg] = $shop->webhook('test', $body, $headers);
                if ($code !== 200) {
                    throw new UserError("The webhook answered $code: $msg.");
                }
                return $this->success("Test $type event for {$o['ref']}: $msg.");
        }
        throw new UserError('Unknown action.');
    }
}
