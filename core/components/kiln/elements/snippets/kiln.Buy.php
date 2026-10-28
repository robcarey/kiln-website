<?php
/**
 * The shop: [[!kiln.Buy]] on /buy, [[!kiln.Buy? &action=`price`]] on the Pro pricing card,
 * [[!kiln.Buy? &action=`webhook`]] on the payment webhook page. Always call it uncached.
 * The work happens in core/components/kiln/src/BuyController.php and Shop.php.
 *
 * @var MODX\Revolution\modX $modx
 * @var array $scriptProperties
 */
return KilnWeb\Kiln::get($modx)->buy()->run((string) ($scriptProperties['action'] ?? 'page'));