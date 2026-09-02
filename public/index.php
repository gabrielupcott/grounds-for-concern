<?php

declare(strict_types=1);

// Front controller. Run with:
//   php -S 127.0.0.1:8080 -t public public/index.php

$config = require dirname(__DIR__) . '/src/bootstrap.php';

session_start();

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
use App\RuleFactory;
use App\RuleValidationException;
use App\Sentence;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

$pdo = Database::connect($config['db']);
$engine = new EngineClient($config['engine']['base_url']);
$rules = new RuleRepository($pdo);
$txns = new TransactionRepository($pdo);
$alertRepo = new AlertRepository($pdo);

$twig = new Environment(new FilesystemLoader(dirname(__DIR__) . '/templates'), [
    'strict_variables' => true,
]);
$twig->addFilter(new TwigFilter('money', fn ($cents) => number_format($cents / 100, 2)));
$twig->addFilter(new TwigFilter('rule_sentence', [Sentence::class, 'render']));
$twig->addGlobal('unseen_alerts', $alertRepo->countUnseen());
$twig->addGlobal('active_nav', null);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

/** Shared template data for the builder form. */
$formContext = static fn (): array => [
    'categories' => TransactionRepository::CATEGORIES,
    'merchants' => $txns->distinctMerchants(),
];

try {
    switch (true) {
        case $path === '/':
            echo $twig->render('dashboard.twig', [
                'active_nav' => 'dashboard',
                'recent' => $txns->recent(15),
                'coffee7' => $txns->categoryStatsSince('coffee', 6), // 6 days ago + today = 7 days
                'coffee30' => $txns->categoryStatsSince('coffee', 29),
                'weekTotal' => $txns->totalSince(6),
                'merchants' => $txns->distinctMerchants(),
                'categories' => TransactionRepository::CATEGORIES,
                'flash' => $_SESSION['flash'] ?? [],
                'form_errors' => [],
                'form_submitted' => [],
            ]);
            unset($_SESSION['flash']);
            break;

        // ---- Rules: list, build, edit ------------------------------------

        case $path === '/rules' && $method === 'GET':
            echo $twig->render('rules/index.twig', ['active_nav' => 'rules', 'rules' => $rules->all()]);
            break;

        case $path === '/rules/new' && $method === 'GET':
            echo $twig->render('rules/form.twig', $formContext() + [
                'active_nav' => 'rules',
                'rule' => ['id' => null, 'name' => '', 'definition' => RuleFactory::defaultDefinition()],
                'errors' => [],
                'submitted' => [],
            ]);
            break;

        case $path === '/rules' && $method === 'POST':
            try {
                $definition = RuleFactory::fromForm($_POST);
                $rules->insert($definition['name'], $definition);
                header('Location: /rules?saved=1');
            } catch (RuleValidationException $e) {
                http_response_code(422);
                echo $twig->render('rules/form.twig', $formContext() + [
                    'active_nav' => 'rules',
                    'rule' => ['id' => null, 'name' => '', 'definition' => RuleFactory::defaultDefinition()],
                    'errors' => $e->errors,
                    'submitted' => $_POST,
                ]);
            }
            break;

        case (bool) preg_match('#^/rules/(\d+)/edit$#', $path, $m) && $method === 'GET':
            $rule = $rules->find((int) $m[1]);
            if (!$rule) {
                http_response_code(404);
                echo $twig->render('404.twig');
                break;
            }
            echo $twig->render('rules/form.twig', $formContext() + [
                'active_nav' => 'rules',
                'rule' => $rule,
                'errors' => [],
                'submitted' => [],
            ]);
            break;

        case (bool) preg_match('#^/rules/(\d+)$#', $path, $m) && $method === 'POST':
            try {
                $definition = RuleFactory::fromForm($_POST);
                $rules->update((int) $m[1], $definition['name'], $definition);
                header('Location: /rules?saved=1');
            } catch (RuleValidationException $e) {
                http_response_code(422);
                $rule = $rules->find((int) $m[1]);
                echo $twig->render('rules/form.twig', $formContext() + [
                    'active_nav' => 'rules',
                    'rule' => $rule,
                    'errors' => $e->errors,
                    'submitted' => $_POST,
                ]);
            }
            break;

        case (bool) preg_match('#^/rules/(\d+)/delete$#', $path, $m) && $method === 'POST':
            $rules->delete((int) $m[1]);
            header('Location: /rules');
            break;

        case (bool) preg_match('#^/rules/(\d+)/toggle$#', $path, $m) && $method === 'POST':
            $rule = $rules->find((int) $m[1]);
            if ($rule) {
                $rules->setActive((int) $m[1], !$rule['is_active']);
            }
            header('Location: /rules');
            break;

        // ---- AJAX endpoints used by the builder --------------------------

        case $path === '/rules/preview' && $method === 'POST':
            header('Content-Type: application/json');
            try {
                $definition = RuleFactory::fromForm($_POST);
                echo json_encode(['ok' => true, 'sentence' => Sentence::render($definition)]);
            } catch (RuleValidationException $e) {
                echo json_encode(['ok' => false, 'errors' => array_values($e->errors)]);
            }
            break;

        case $path === '/rules/backtest' && $method === 'POST':
            header('Content-Type: application/json');
            try {
                $definition = RuleFactory::fromForm($_POST);
                $today = (new DateTimeImmutable('today'))->format('Y-m-d');
                $from = (new DateTimeImmutable('89 days ago'))->format('Y-m-d');
                // Pull enough history to cover the widest window ending at `from`.
                $lookback = (new DateTimeImmutable('120 days ago'))->format('Y-m-d');
                $result = $engine->backtest($definition, $txns->sinceForEngine($lookback), $from, $today);
                echo json_encode([
                    'ok' => true,
                    'html' => $twig->render('rules/_backtest_results.twig', ['result' => $result]),
                ]);
            } catch (RuleValidationException $e) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'errors' => array_values($e->errors)]);
            } catch (Throwable $e) {
                http_response_code(502);
                echo json_encode(['ok' => false, 'errors' => ['Could not reach the rule engine. Is it running?']]);
            }
            break;

        // ---- Live fire: log a purchase, evaluate every active rule ----------

        case $path === '/transactions/add' && $method === 'POST':
            $errors = [];
            $merchant = trim((string) ($_POST['merchant'] ?? ''));
            $amountRaw = str_replace(['$', ','], '', (string) ($_POST['amount'] ?? ''));
            $amount = $amountRaw === '' ? 0.0 : round((float) $amountRaw, 2);
            $category = (string) ($_POST['category'] ?? '');
            $date = (string) ($_POST['date'] ?? date('Y-m-d'));

            if ($merchant === '') {
                $errors['merchant'] = 'Who got the money?';
            } elseif (mb_strlen($merchant) > 120) {
                $errors['merchant'] = 'Merchant names max out at 120 characters.';
            }
            if ($amount <= 0) {
                $errors['amount'] = 'Amount must be more than zero.';
            } elseif ($amount > 10000) {
                $errors['amount'] = 'Let’s keep individual purchases under $10,000.';
            }
            if (!in_array($category, TransactionRepository::CATEGORIES, true)) {
                $errors['category'] = 'Pick a category.';
            }
            // The "!" resets unparsed components to zero — without it,
            // createFromFormat fills the time with *now*, and today reads as
            // the future.
            $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            $today = new DateTimeImmutable('today');
            if (!$dt || $dt->format('Y-m-d') !== $date) {
                $errors['date'] = 'That date doesn’t look right.';
            } elseif ($dt > $today) {
                $errors['date'] = 'Can’t log purchases from the future.';
            } elseif ($dt < $today->modify('-365 days')) {
                $errors['date'] = 'Keep it within the last year.';
            }

            if ($errors) {
                http_response_code(422);
                echo $twig->render('dashboard.twig', [
                    'active_nav' => 'dashboard',
                    'recent' => $txns->recent(15),
                    'coffee7' => $txns->categoryStatsSince('coffee', 6),
                    'coffee30' => $txns->categoryStatsSince('coffee', 29),
                    'weekTotal' => $txns->totalSince(6),
                    'merchants' => $txns->distinctMerchants(),
                    'categories' => TransactionRepository::CATEGORIES,
                    'flash' => $_SESSION['flash'] ?? [],
                    'form_errors' => $errors,
                    'form_submitted' => $_POST,
                ]);
                unset($_SESSION['flash']);
                break;
            }

            $txns->add($date, $merchant, $category, (int) round($amount * 100));
            $_SESSION['flash'][] = [
                'type' => 'ok',
                'text' => sprintf('Logged $%s at %s.', number_format($amount, 2), $merchant),
            ];

            // The moment of truth: evaluate every active rule over its window.
            try {
                foreach ($rules->active() as $rule) {
                    $def = $rule['definition'];
                    $windowStart = (new DateTimeImmutable('today'))
                        ->modify(sprintf('-%d days', $def['window_days'] - 1))
                        ->format('Y-m-d');
                    $verdict = $engine->evaluate($def, $txns->sinceForEngine($windowStart), $today->format('Y-m-d'));
                    if ($verdict['triggered']) {
                        $inserted = $alertRepo->insertIgnore(
                            (int) $rule['id'],
                            $today->format('Y-m-d'),
                            (int) $verdict['window_total_cents'],
                            (int) $verdict['transaction_count'],
                            Sentence::render($def)
                        );
                        if ($inserted) {
                            $_SESSION['flash'][] = [
                                'type' => 'fire',
                                'text' => sprintf(
                                    '%s fired — $%s in the last %d days. See Alerts.',
                                    $rule['name'],
                                    number_format($verdict['window_total_cents'] / 100, 2),
                                    $def['window_days']
                                ),
                            ];
                        }
                    }
                }
            } catch (Throwable $e) {
                $_SESSION['flash'][] = [
                    'type' => 'warn',
                    'text' => 'Purchase saved, but the rule engine is unreachable — rules weren’t evaluated.',
                ];
            }

            header('Location: /');
            break;

        // ---- Alerts inbox --------------------------------------------------

        case $path === '/alerts' && $method === 'GET':
            echo $twig->render('alerts/index.twig', [
                'active_nav' => 'alerts',
                'alerts' => $alertRepo->recent(),
            ]);
            break;

        case $path === '/alerts/seen-all' && $method === 'POST':
            $alertRepo->markAllSeen();
            header('Location: /alerts');
            break;

        default:
            http_response_code(404);
            echo $twig->render('404.twig');
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo $twig->render('error.twig', ['message' => $e->getMessage()]);
}
