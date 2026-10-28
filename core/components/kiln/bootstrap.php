<?php
/**
 * MODX 3 loads this for the `kiln` namespace on every request: it makes the
 * KilnWeb\ classes (src/) and Composer packages (vendor/, e.g. Hybridauth)
 * available to snippets.
 */
if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        if (str_starts_with($class, 'KilnWeb\\')) {
            $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 8)) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    });
}
