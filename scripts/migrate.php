<?php

// Apply schema.sql. Usage:
//   php scripts/migrate.php           create tables if missing
//   php scripts/migrate.php --fresh   drop everything first (dev only)

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$root = dirname(__DIR__);
$configFile = $root . '/config.php';
if (!file_exists($configFile)) {
    exit("No config.php found. Copy config.example.php to config.php and fill it in.\n");
}
$config = require $configFile;
$db = $config['db'];

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']),
    $db['user'],
    $db['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

if (in_array('--fresh', $argv, true)) {
    // Drop in FK-safe order.
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (['alerts', 'rules', 'transactions'] as $table) {
        $pdo->exec("DROP TABLE IF EXISTS $table");
        echo "dropped $table\n";
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

$sql = file_get_contents($root . '/schema.sql');
foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
    // Skip trailing comment-only fragments.
    if ($statement !== '') {
        $pdo->exec($statement);
    }
}

foreach (['transactions', 'rules', 'alerts'] as $table) {
    $count = $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
    echo sprintf("%-13s %d rows\n", $table, $count);
}
echo "migrate: ok\n";
