<?php

namespace KilnWeb\Processors\Sales;

use KilnWeb\Payments\PaddleProvider;
use KilnWeb\Processors\Base;
use KilnWeb\Shop;
use KilnWeb\UserError;
use MODX\Revolution\modSystemSetting;

/**
 * The sales switch: soon | open | paused. Saves kiln.sales and clears the whole
 * site cache, so every price and buy button on the site changes at once.
 */
final class Set extends Base
{
    protected function handle(): mixed
    {
        $mode = $this->str('mode', 10);
        if (!isset(Shop::MODES[$mode])) {
            throw new UserError('Pick open, paused or soon.');
        }
        if ($mode === 'open' && !$this->kiln->accountsOpen()) {
            // Buying needs an account, and kiln.accounts hides them.
            throw new UserError('Accounts are hidden (kiln.accounts), and buyers need one. Set kiln.accounts to open first.');
        }
        $s = $this->modx->getObject(modSystemSetting::class, ['key' => 'kiln.sales']);
        if (!$s) {
            throw new UserError('The kiln.sales setting is missing. Run _build/setup.php.');
        }
        $s->set('value', $mode);
        $s->save();
        $this->modx->setOption('kiln.sales', $mode);
        $this->modx->cacheManager->refresh();
        $this->kiln->log('sales switched to ' . $mode . ' by ' . $this->modx->user->get('username'));
        return $this->success(match ($mode) {
            'open' => 'Sales are open. Anyone can buy Kiln Pro from /buy.',
            'paused' => 'Sales are paused. /buy says so, and payments already made are still fulfilled.',
            'soon' => 'Back to “coming soon”. /buy says Kiln Pro goes on sale soon.',
        }, self::state($this->kiln->shop()));
    }

    /** What the manager page shows next to the switch. */
    public static function state(Shop $shop): array
    {
        $p = $shop->provider();
        return [
            'mode' => $shop->mode(),
            'label' => Shop::MODES[$shop->mode()],
            'provider' => $p ? $p->key() : '',
            'ready' => $p !== null && $p->ready(),
            'problems' => $p ? $p->problems() : ['No payment provider is chosen (kiln.payment_provider).'],
            'test' => $shop->testMode(),
            'simulate' => $shop->canSimulate(),
            'sandbox' => $p instanceof PaddleProvider && $p->testing(),
        ];
    }
}
