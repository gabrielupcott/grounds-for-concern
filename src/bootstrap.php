<?php

declare(strict_types=1);

// Shared bootstrap: autoload, clock, config.
//
// The clock is pinned on purpose: XAMPP's php.ini defaults to Europe/Berlin
// while MySQL uses the system (Eastern) clock. Un-pinned, PHP happily seeds
// future-dated transactions. The app also passes explicit dates into SQL
// rather than relying on MySQL's CURDATE(), so there is exactly one clock.

date_default_timezone_set('America/Toronto');

require dirname(__DIR__) . '/vendor/autoload.php';

$configFile = dirname(__DIR__) . '/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    exit("No config.php found. Copy config.example.php to config.php and fill it in.\n");
}

return require $configFile;
