<?php
/**
 * Account pages: [[!kiln.Account? &action=`signin|register|verify|forgot|signout|connect|dashboard|navlink`]]
 * Always call it uncached. The work happens in core/components/kiln/src/AccountController.php.
 *
 * @var MODX\Revolution\modX $modx
 * @var array $scriptProperties
 */
return KilnWeb\Kiln::get($modx)->account()->run((string) ($scriptProperties['action'] ?? 'dashboard'));