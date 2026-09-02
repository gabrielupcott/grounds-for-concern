<?php

declare(strict_types=1);

// Front controller. Run with:
//   php -S 127.0.0.1:8080 public/index.php

$config = require dirname(__DIR__) . '/src/bootstrap.php';

// Let the built-in server serve static files directly.
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $file = __DIR__ . $path;
    if ($path !== '/' && is_file($file)) {
        return false;
    }
}

use App\Database;
use App\EngineClient;
use App\Repositories\AlertRepository;
use App\Repositories\RuleRepository;
use App\Repositories\TransactionRepository;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

$pdo = Database::connect($config['db']);
$engine = new EngineClient($config['engine']['base_url']);

$twig = new Environment(new FilesystemLoader(dirname(__DIR__) . '/templates'), [
    'strict_variables' => true,
]);
$twig->addFilter(new TwigFilter('money', fn ($cents) => number_format($cents / 100, 2)));

$alertRepo = new AlertRepository($pdo);
$twig->addGlobal('unseen_alerts', $alertRepo->countUnseen());
$twig->addGlobal('active_nav', null);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch (true) {
        case $path === '/':
            $txns = new TransactionRepository($pdo);
            echo $twig->render('dashboard.twig', [
                'active_nav' => 'dashboard',
                'recent' => $txns->recent(15),
                'coffee7' => $txns->categoryStatsSince('coffee', 6), // 6 days ago + today = 7 days
                'coffee30' => $txns->categoryStatsSince('coffee', 29),
                'weekTotal' => $txns->totalSince(6),
            ]);
            break;

        default:
            http_response_code(404);
            echo $twig->render('404.twig');
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo $twig->render('error.twig', ['message' => $e->getMessage()]);
}
