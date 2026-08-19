<?php
declare(strict_types=1);

/**
 * Bootstrap for the Integration suite.
 *
 * The opposite of tests/bootstrap.php: that one loads OCP *stubs* so unit tests
 * can run without a server. These tests exist precisely to exercise the real
 * thing — groupfolder resolution, ACLs, mounted views — so they load Nextcloud
 * itself and must run inside the server (see scripts/run-integration-tests.sh).
 */

$ncRoot = getenv('NEXTCLOUD_ROOT') ?: '/var/www/html';
$base = $ncRoot . '/lib/base.php';

if (!file_exists($base)) {
    fwrite(STDERR, PHP_EOL . sprintf(
        "Integration tests need a real Nextcloud, but %s does not exist.\n"
        . "Set NEXTCLOUD_ROOT, or run them in the server container:\n"
        . "    scripts/run-integration-tests.sh\n\n",
        $base
    ));
    exit(1);
}

require_once $base;

// The app's own classes are autoloaded by Nextcloud once the app is enabled,
// but the test classes are not part of the app namespace map.
require_once __DIR__ . '/../vendor/autoload.php';

\OC_App::loadApp('intravox');
