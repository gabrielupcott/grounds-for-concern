<?php

// Copy this file to config.php and fill in your local values.
// config.php is gitignored — real credentials never get committed.

return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'grounds',
        'user' => 'grounds',
        'pass' => '',
    ],

    // The Go rule engine service (see engine/).
    'engine' => [
        'base_url' => 'http://127.0.0.1:8081',
    ],
];
