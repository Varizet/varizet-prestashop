<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
// The SDK: $VARIZET_SDK_DIR, the copy next to this module in the Varizet monorepo, or
// .sdk/varizet-php in a standalone checkout (bin/sdk.sh fetches it).
$sdk = getenv('VARIZET_SDK_DIR') ?: __DIR__ . '/../../../packages/sdk-php';
if (!is_file($sdk . '/autoload.php')) {
    $sdk = __DIR__ . '/../.sdk/varizet-php';
}
if (!is_file($sdk . '/autoload.php')) {
    fwrite(STDERR, "PHP SDK not found: run bin/sdk.sh first.\n");
    exit(1);
}
require $sdk . '/autoload.php';
require __DIR__ . '/../varizet/autoload.php';
