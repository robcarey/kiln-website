<?php
/**
 * Connect the site to Paddle, once per environment (sandbox, then live).
 *
 *   php core/components/kiln/_build/paddle-setup.php
 *   php core/components/kiln/_build/paddle-setup.php --webhook=https://kilndev.com/payment-webhook?provider=paddle
 *
 * Needs kiln.paddle_env and kiln.paddle_api_key set first. Then it:
 *  - makes the three Kiln Pro products in Paddle (Annual, Lifetime, Team) unless
 *    their kiln.paddle_product_* setting already names one that exists;
 *  - with --webhook, makes (or finds) the notification destination for that URL,
 *    subscribed to the events the shop uses, and saves its secret in
 *    kiln.paddle_webhook_secret. The URL must be reachable from the internet,
 *    so on kilndev.test leave it out.
 *
 * Safe to re-run. Prints ids, never keys or secrets.
 */

use KilnWeb\Kiln;
use KilnWeb\Payments\PaddleProvider;
use MODX\Revolution\modSystemSetting;
use MODX\Revolution\modX;

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

define('MODX_API_MODE', true);
require dirname(__DIR__, 4) . '/config.core.php';
require MODX_CORE_PATH . 'vendor/autoload.php';
require_once dirname(__DIR__) . '/bootstrap.php';

$modx = new modX();
$modx->initialize('mgr');
$kiln = Kiln::get($modx);
$paddle = new PaddleProvider($kiln);

$webhook = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--webhook=')) {
        $webhook = substr($arg, 10);
    } else {
        exit("Unknown option $arg\n");
    }
}

$save = function (string $key, string $value) use ($modx): void {
    $s = $modx->getObject(modSystemSetting::class, ['key' => $key]);
    if (!$s) {
        exit("The $key setting is missing: run _build/setup.php first.\n");
    }
    $s->set('value', $value);
    $s->save();
    $modx->setOption($key, $value);
};

if (trim((string) $kiln->opt('kiln.paddle_api_key', '')) === '') {
    exit("Set kiln.paddle_api_key (and kiln.paddle_env) first.\n");
}
echo 'Paddle ' . $paddle->env() . "\n";

// ---- products --------------------------------------------------------------------------
$products = [
    'annual' => ['Kiln Pro · Annual', 'Kiln Pro for one person on all their own machines, renewed every year. Includes every Pro feature and update while it runs.'],
    'lifetime' => ['Kiln Pro · Lifetime', 'Kiln Pro for one person on all their own machines, paid once, never expires.'],
    'team' => ['Kiln Pro · Team', 'Kiln Pro seats for a team, renewed every year. Each member gets their own licence key; the owner hands seats out from their kilndev.com account.'],
];
foreach ($products as $plan => [$name, $description]) {
    $key = "kiln.paddle_product_$plan";
    $id = trim((string) $kiln->opt($key, ''));
    if ($id !== '') {
        try {
            $p = $paddle->api('GET', '/products/' . rawurlencode($id));
            echo "  = $plan: $id ({$p['name']}, {$p['status']})\n";
            continue;
        } catch (RuntimeException $e) {
            echo "  ! $plan: $id not found in this environment, making a new one\n";
        }
    }
    $p = $paddle->api('POST', '/products', [
        'name' => $name,
        'description' => $description,
        'tax_category' => 'standard',
        'type' => 'standard',
        'custom_data' => ['kiln_plan' => $plan],
    ]);
    $save($key, (string) $p['id']);
    echo "  + $plan: {$p['id']}\n";
}

// ---- the webhook destination ----------------------------------------------------------------
if ($webhook !== null) {
    if (!preg_match('#^https://[^/]+/.*provider=paddle#', $webhook)) {
        exit("--webhook must be the https URL of /payment-webhook?provider=paddle\n");
    }
    $events = ['transaction.completed', 'subscription.created', 'subscription.updated', 'subscription.canceled', 'adjustment.created', 'adjustment.updated'];
    $found = null;
    foreach ($paddle->api('GET', '/notification-settings') as $n) {
        if (($n['destination'] ?? '') === $webhook && ($n['type'] ?? '') === 'url') {
            $found = $n;
            break;
        }
    }
    if ($found) {
        $have = array_map(static fn ($e) => is_array($e) ? $e['name'] : $e, $found['subscribed_events'] ?? []);
        if (array_diff($events, $have) || !$found['active']) {
            $found = $paddle->api('PATCH', '/notification-settings/' . rawurlencode($found['id']), ['subscribed_events' => $events, 'active' => true]);
            echo "  ~ webhook {$found['id']}: events updated\n";
        } else {
            echo "  = webhook {$found['id']}\n";
        }
    } else {
        $found = $paddle->api('POST', '/notification-settings', [
            'description' => 'kilndev.com shop (licences, renewals, refunds)',
            'destination' => $webhook,
            'type' => 'url',
            'subscribed_events' => $events,
            'api_version' => 1,
            'include_sensitive_fields' => false,
            'traffic_source' => $paddle->env() === 'sandbox' ? 'all' : 'platform',
        ]);
        echo "  + webhook {$found['id']} -> $webhook\n";
    }
    if (empty($found['endpoint_secret_key'])) {
        exit("Paddle didn't return the webhook's secret key: copy it from Developer tools → Notifications into kiln.paddle_webhook_secret.\n");
    }
    $save('kiln.paddle_webhook_secret', (string) $found['endpoint_secret_key']);
    echo "  webhook secret saved\n";
}

$modx->cacheManager->refresh();
$problems = $paddle->problems();
echo $problems ? "\nStill to do:\n  - " . implode("\n  - ", $problems) . "\n" : "\nPaddle is ready. Set kiln.payment_provider to paddle to use it.\n";
