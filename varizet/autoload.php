<?php

// Loads the bundled SDK (lib/varizet-php, added by bin/build-zip.sh) and Varizet\PrestaShop\ classes.
if (!class_exists(\Varizet\VarizetClient::class)) {
    require_once __DIR__ . '/lib/varizet-php/autoload.php';
}
spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'Varizet\\PrestaShop\\', 19) === 0) {
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 19)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
