<?php

namespace KilnWeb;

use KilnWeb\Payments\PaddleProvider;
use KilnWeb\Payments\TestProvider;

/**
 * /buy and the other places the shop shows up:
 *   [[!kiln.Buy]]                       the buy page: plans, cart, checkout, order status
 *   [[!kiln.Buy? &action=`price`]]      the price on the home page's Pro card
 *   [[!kiln.Buy? &action=`webhook`]]    the payment webhook page (?provider=…)
 *
 * The page follows the sales switch (Shop::mode()); webhooks never do, because
 * a payment that went through must always be fulfilled.
 */
final class BuyController
{
    private Web $web;
    private Shop $shop;

    public function __construct(private Kiln $kiln)
    {
        $this->web = $kiln->web();
        $this->shop = $kiln->shop();
    }

    public function run(string $action): string
    {
        return match ($action) {
            'price' => $this->price(),
            'webhook' => $this->webhook(),
            default => $this->safely(),
        };
    }

    private function safely(): string
    {
        try {
            return $this->page();
        } catch (UserError $e) {
            return $this->page($e);
        }
    }

    private function page(?UserError $e = null): string
    {
        if ($e === null && $this->web->isPost()) {
            $this->web->checkCsrf();
            $this->post();
        }
        if (($ref = $this->web->query('order', 20)) !== '') {
            return $this->orderPage($ref, $e);
        }
        if (($ref = $this->web->query('checkout', 20)) !== '') {
            $order = $this->ownOrder($ref);
            return $order['provider'] === 'paddle' ? $this->paddleCheckout($order, $e) : $this->testCheckout($order, $e);
        }
        if (preg_match('/^txn_[a-z0-9]{26}$/', $this->web->query('_ptxn', 40))) {
            return $this->paddleLink($e);
        }
        if (!$this->shop->isOpen()) {
            return $this->web->chunk('buy.Closed', [
                'alert' => $this->alert($e),
                'mode' => $this->shop->mode(),
                'message' => $this->web->e($this->shop->closedMessage()),
                'url_download' => $this->web->url('download'),
                'url_compare' => $this->web->url('compare'),
            ]);
        }
        return $this->web->chunk('buy.Plans', [
            'alert' => $this->alert($e),
            'test' => $this->shop->testMode() ? '1' : '',
            'price_annual' => $this->shop->money($this->shop->price('annual')),
            'price_lifetime' => $this->shop->money($this->shop->price('lifetime')),
            'price_seat' => $this->shop->money($this->shop->price('team')),
            'min_seats' => (string) $this->shop->minSeats(),
            'seats' => (string) ($this->shop->cart()['team'] ?? $this->shop->minSeats()),
            'currency' => $this->shop->currency(),
            'cart' => $this->cart(),
        ]);
    }

    // ---- form posts -------------------------------------------------------------------

    private function post(): never
    {
        $do = $this->web->post('do', 20);
        $plan = $this->web->post('plan', 20);
        $cart = $this->shop->cart();
        if (in_array($do, ['add', 'set', 'remove'], true) && !in_array($plan, Shop::PLANS, true)) {
            throw new UserError('Please pick a plan.');
        }
        switch ($do) {
            case 'add':
                $this->needOpen();
                $cart[$plan] = $plan === 'team' ? (int) $this->web->post('seats', 6) : ($cart[$plan] ?? 0) + 1;
                $this->shop->setCart($cart);
                $this->web->redirect($this->web->url('buy') . '#cart');
            case 'set':
                $cart[$plan] = (int) $this->web->post('n', 6);
                $this->shop->setCart($cart);
                $this->web->redirect($this->web->url('buy') . '#cart');
            case 'remove':
                unset($cart[$plan]);
                $this->shop->setCart($cart);
                $this->web->redirect($this->web->url('buy') . '#cart');
            case 'checkout':
                $this->needOpen();
                $user = $this->kiln->user();
                if (!$user) {
                    $this->web->session('return', 'buy');
                    $this->web->flash('Sign in or create an account to finish your order. Your cart is saved.');
                    $this->web->redirect($this->web->url('signin'));
                }
                if (!$this->shop->canCheckout()) {
                    $this->kiln->log('checkout refused: sales are open but the payment provider is not ready');
                    throw new UserError('Checkout isn’t available right now. Please try again shortly.');
                }
                $order = $this->shop->createOrder($user, $this->shop->lines($cart));
                $url = $this->shop->checkout($order);
                $this->shop->setCart([]);
                $this->web->redirect($url);
            case 'test_pay':
                $order = $this->ownOrder($this->web->post('order', 20));
                $test = $this->shop->provider('test');
                if (!$test instanceof TestProvider || !$test->ready() || $order['status'] !== 'pending') {
                    throw new UserError('That test checkout has closed.');
                }
                [$body, $headers] = $test->event('paid', $order['ref'], ['amountCents' => (int) $order['total_cents']]);
                [$code, $msg] = $this->shop->webhook('test', $body, $headers);
                if ($code !== 200) {
                    throw new UserError("The test payment didn’t go through ($msg).");
                }
                $this->web->redirect($this->web->url('buy', ['order' => $order['ref']]));
            case 'cancel':
            case 'test_cancel':
                $this->shop->cancel($this->ownOrder($this->web->post('order', 20)));
                $this->web->flash('Checkout cancelled. Your cart is as you left it.');
                $this->web->redirect($this->web->url('buy') . '#cart');
        }
        $this->web->redirect($this->web->url('buy'));
    }

    private function needOpen(): void
    {
        if (!$this->shop->isOpen()) {
            throw new UserError($this->shop->closedMessage());
        }
    }

    private function ownOrder(string $ref): array
    {
        $user = $this->kiln->user();
        $order = $ref !== '' ? $this->shop->order($ref) : null;
        if (!$user || !$order || (int) $order['user_id'] !== (int) $user->get('id')) {
            throw new UserError('We couldn’t find that order on your account.');
        }
        return $order;
    }

    // ---- pieces -------------------------------------------------------------------------

    private function cart(): string
    {
        $lines = $this->shop->lines($this->shop->cart());
        if (!$lines) {
            return '';
        }
        $rows = '';
        foreach ($lines as $l) {
            $plan = $this->web->e($l['plan']);
            $isTeam = $l['plan'] === 'team';
            $n = $isTeam ? $l['seats'] : $l['qty'];
            $rows .= '<tr><td><strong>' . $this->web->e($l['label']) . '</strong><div class="k-auth__hint">'
                . $this->web->e($this->shop->money($l['unit']) . ($isTeam ? ' per seat per year' : ($l['plan'] === 'annual' ? ' per year' : ' once'))) . '</div></td>'
                . '<td><form method="post" class="k-cart__qty"><input type="hidden" name="csrf" value="' . $this->web->csrf() . '"><input type="hidden" name="do" value="set"><input type="hidden" name="plan" value="' . $plan . '">'
                . '<label class="visually-hidden" for="n-' . $plan . '">' . ($isTeam ? 'Seats' : 'Quantity') . '</label>'
                . '<input class="form-control form-control-sm" type="number" id="n-' . $plan . '" name="n" value="' . $n . '" min="' . ($isTeam ? $this->shop->minSeats() : 1) . '" max="' . ($isTeam ? 500 : 10) . '">'
                . '<button class="k-linkbtn" type="submit">Update</button></form>' . ($isTeam ? '<div class="k-auth__hint">seats</div>' : '') . '</td>'
                . '<td class="k-cart__money">' . $this->web->e($this->shop->money($l['line'])) . '</td>'
                . '<td><form method="post"><input type="hidden" name="csrf" value="' . $this->web->csrf() . '"><input type="hidden" name="do" value="remove"><input type="hidden" name="plan" value="' . $plan . '">'
                . '<button class="k-linkbtn" type="submit" aria-label="Remove ' . $this->web->e($l['label']) . '">Remove</button></form></td></tr>';
        }
        return $this->web->chunk('buy.Cart', [
            'rows' => $rows,
            'total' => $this->web->e($this->shop->money($this->shop->total($lines), true)),
            'signed_in' => $this->kiln->user() ? '1' : '',
            'has_annual' => array_filter($lines, static fn ($l) => $l['plan'] !== 'lifetime') ? '1' : '',
        ]);
    }

    private function orderRows(array $o): string
    {
        $rows = '';
        foreach ($o['items'] as $i) {
            $what = $i['plan'] === 'team' ? Shop::ITEM_LABELS['team'] . " · {$i['seats']} seats" : Shop::ITEM_LABELS[$i['plan']] . ($i['qty'] > 1 ? " × {$i['qty']}" : '');
            $rows .= '<tr><td>' . $this->web->e($what) . '</td><td class="k-cart__money">' . $this->web->e($this->shop->money((int) $i['line_cents'])) . '</td></tr>';
        }
        return $rows;
    }

    /** Paddle's overlay checkout, opened on our page for the order's transaction. */
    private function paddleCheckout(array $order, ?UserError $e): string
    {
        $paddle = $this->shop->provider('paddle');
        if (!$paddle instanceof PaddleProvider || !$paddle->ready() || $order['status'] !== 'pending' || !str_starts_with((string) $order['provider_ref'], 'txn_')) {
            $this->web->redirect($this->web->url('buy', ['order' => $order['ref']]));
        }
        $this->paddleScripts($paddle);
        return $this->web->chunk('buy.PaddleCheckout', [
            'alert' => $this->alert($e),
            'ref' => $this->web->e($order['ref']),
            'rows' => $this->orderRows($order),
            'total' => $this->web->e($this->shop->money((int) $order['total_cents'], true)),
            'txn' => $this->web->e($order['provider_ref']),
            'token' => $this->web->e($paddle->clientToken()),
            'env' => $paddle->env(),
            'success' => $this->web->e($this->web->url('buy', ['order' => $order['ref']])),
            'auto' => '1',
            'test' => $paddle->testing() ? '1' : '',
        ]);
    }

    /**
     * A Paddle link (/buy?_ptxn=…): Paddle sends these to update a card on a
     * subscription or pay an invoice. Paddle.js opens the checkout by itself.
     */
    private function paddleLink(?UserError $e): string
    {
        $paddle = $this->shop->provider('paddle');
        if (!$paddle instanceof PaddleProvider || !$paddle->ready()) {
            $this->web->redirect($this->web->url('account'));
        }
        $this->paddleScripts($paddle);
        return $this->web->chunk('buy.PaddleLink', [
            'alert' => $this->alert($e),
            'txn' => $this->web->e($this->web->query('_ptxn', 40)),
            'token' => $this->web->e($paddle->clientToken()),
            'env' => $paddle->env(),
            'success' => $this->web->e($this->web->url('account')),
            'auto' => '0',
        ]);
    }

    private function paddleScripts(PaddleProvider $paddle): void
    {
        $this->kiln->modx->regClientScript('https://cdn.paddle.com/paddle/v2/paddle.js');
        $this->kiln->modx->regClientScript($this->kiln->opt('kiln.theme_url') . 'js/kiln-paddle.js?v=' . $this->kiln->opt('kiln.asset_version', '1'));
    }

    private function testCheckout(array $order, ?UserError $e): string
    {
        $test = $this->shop->provider('test');
        if (!$test || !$test->ready() || $order['status'] !== 'pending') {
            $this->web->redirect($this->web->url('buy', ['order' => $order['ref']]));
        }
        return $this->web->chunk('buy.TestCheckout', [
            'alert' => $this->alert($e),
            'ref' => $this->web->e($order['ref']),
            'rows' => $this->orderRows($order),
            'total' => $this->web->e($this->shop->money((int) $order['total_cents'], true)),
        ]);
    }

    private function orderPage(string $ref, ?UserError $e): string
    {
        if (!$this->kiln->user()) {
            $this->web->session('return', 'account');
            $this->web->redirect($this->web->url('signin'));
        }
        $o = $this->shop->reconcile($this->ownOrder($ref));
        $status = $o['status'];
        $licences = '';
        if ($status === 'fulfilled') {
            foreach ($o['items'] as $i) {
                foreach ($i['license_ids'] as $id) {
                    try {
                        $l = $this->kiln->licenses()->call('GET', 'licenses/' . $id);
                        $licences .= '<li><span class="k-lic__id">' . $this->web->e($l['licenseId']) . '</span> ' . $this->web->e(AccountController::PLAN_LABELS[$l['plan']] ?? $l['plan']) . '</li>';
                    } catch (\RuntimeException) {
                        // the list is a nicety; the account page and the email have the keys
                    }
                }
                if ($i['team_id']) {
                    $licences .= '<li><span class="k-lic__id">' . $this->web->e($i['team_id']) . '</span> Team with ' . (int) $i['seats'] . ' seats: hand them out from your account</li>';
                }
            }
        }
        [$title, $text] = match ($status) {
            'fulfilled' => ['Thank you! Your order is complete.', 'We’ve emailed your receipt and licence keys to ' . $o['email'] . '. They’re on your account page too.'],
            'paid' => ['Payment received.', 'We’re issuing your licence keys now. Refresh this page in a moment.'],
            'pending' => ['Waiting for your payment to be confirmed.', 'This usually takes a few seconds. Refresh this page in a moment.'],
            'refunded' => ['This order was refunded.', 'Its licence keys no longer work.'],
            default => ['This order was cancelled.', 'Nothing was charged.'],
        };
        return $this->web->chunk('buy.Order', [
            'alert' => $this->alert($e),
            'status' => $status,
            'title' => $this->web->e($title),
            'text' => $this->web->e($text),
            'ref' => $this->web->e($o['ref']),
            'rows' => $this->orderRows($o),
            'total' => $this->web->e($this->shop->money((int) $o['total_cents'], true)),
            'licences' => $licences,
            'url_self' => $this->web->url('buy', ['order' => $o['ref']]),
            'url_checkout' => $status === 'pending' && $o['provider'] === 'paddle' ? $this->web->url('buy', ['checkout' => $o['ref']]) : '',
        ]);
    }

    private function alert(?UserError $e): string
    {
        $flash = $this->web->flash();
        $out = '';
        if ($flash) {
            $out .= '<div class="k-callout k-callout--' . $this->web->e($flash['kind']) . '" role="status">' . $this->web->e($flash['message']) . '</div>';
        }
        if ($e) {
            $out .= '<div class="k-callout k-callout--error" role="alert">' . $this->web->e($e->getMessage()) . '</div>';
        }
        return $out;
    }

    // ---- the home page's Pro card ------------------------------------------------------------

    private function price(): string
    {
        if ($this->shop->mode() === 'soon') {
            $shown = trim((string) $this->kiln->opt('kiln.price_pro_display', ''));
            return $shown !== ''
                ? '<div class="k-plan__price">' . $this->web->e($shown) . '</div>'
                : '<div class="k-plan__price k-plan__price--tba">Launch pricing soon</div>';
        }
        return '<div class="k-plan__price">' . $this->web->e($this->shop->money($this->shop->price('annual'))) . '<small>/ year</small></div>'
            . '<p class="k-plan__alt">or ' . $this->web->e($this->shop->money($this->shop->price('lifetime'))) . ' once, for life. Teams from '
            . $this->web->e($this->shop->money($this->shop->price('team'))) . ' a seat.</p>';
    }

    // ---- payment webhooks --------------------------------------------------------------------

    private function webhook(): never
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'message' => 'POST only']);
            exit;
        }
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
            }
        }
        [$code, $msg] = $this->shop->webhook((string) ($_GET['provider'] ?? ''), (string) file_get_contents('php://input'), $headers);
        http_response_code($code);
        echo json_encode(['ok' => $code < 300, 'message' => $msg]);
        exit;
    }
}
