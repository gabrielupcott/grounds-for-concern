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

/**
 * Shared template data for the dashboard: stats plus rule-driven meters.
 * A card's meter line comes from the user's own budget rule for that
 * window - no rule, no bar. "Over" respects the rule's operator, so a
 * ">=" budget goes red exactly when it fires.
 */
$dashboardContext = static function () use ($txns, $rules): array {
    $bought7 = $txns->categoryStatsSince('bought', 6); // 6 days ago + today = 7 days
    $bought30 = $txns->categoryStatsSince('bought', 29);

    $meter = static function (array $stats, ?array $rule): ?array {
        if ($rule === null) {
            return null;
        }
        $threshold = (int) round($rule['threshold']['value'] * 100);
        $over = $rule['threshold']['operator'] === '>='
            ? $stats['total_cents'] >= $threshold
            : $stats['total_cents'] > $threshold;
        return ['threshold_cents' => $threshold, 'over' => $over];
    };

    return [
        'active_nav' => 'dashboard',
        'recent' => $txns->recent(15),
        'bought7' => $bought7,
        'bought30' => $bought30,
        'home7' => $txns->categoryStatsSince('home_made', 6),
        'streak' => $txns->homeStreak(),
        'merchants' => $txns->distinctMerchants(),
        'budget7' => $meter($bought7, $rules->budgetForWindow(7)),
        'budget30' => $meter($bought30, $rules->budgetForWindow(30)),
    ];
};

/**
 * Evaluate rules as of today and record an alert for each that fires.
 * Shared by live fire (a purchase just landed) and save-time evaluation
 * (create, edit, resume) - the engine is stateless, so both walk the
 * identical code path. The (rule_id, triggered_on) dedupe makes
 * re-evaluating the same rule on the same day a no-op.
 *
 * @param array $rulesToCheck rule rows (id, name, definition)
 * @return array flash entries to merge into the session
 */
$evaluateAndRecord = static function (array $rulesToCheck) use ($engine, $txns, $alertRepo): array {
    $flash = [];
    try {
        $today = new DateTimeImmutable('today');
        foreach ($rulesToCheck as $rule) {
            $def = $rule['definition'];
            $windowStart = $today
                ->modify(sprintf('-%d days', $def['window_days'] - 1))
                ->format('Y-m-d');
            $verdict = $engine->evaluate($def, $txns->sinceForEngine($windowStart), $today->format('Y-m-d'));
            if (!$verdict['triggered']) {
                continue;
            }
            $inserted = $alertRepo->insertIgnore(
                (int) $rule['id'],
                $today->format('Y-m-d'),
                (int) $verdict['window_total_cents'],
                (int) $verdict['transaction_count'],
                Sentence::render($def)
            );
            if ($inserted) {
                $flash[] = [
                    'type' => 'fire',
                    'text' => sprintf(
                        '%s fired: $%s over %d days.',
                        $rule['name'],
                        number_format($verdict['window_total_cents'] / 100, 2),
                        $def['window_days']
                    ),
                    'link' => ['href' => '/alerts', 'label' => 'See Alerts'],
                ];
            }
        }
    } catch (Throwable $e) {
        $flash[] = [
            'type' => 'warn',
            'text' => 'The rule engine is unreachable. Rules were not evaluated.',
        ];
    }
    return $flash;
};

try {
    switch (true) {
        case $path === '/':
            echo $twig->render('dashboard.twig', $dashboardContext() + [
                'flash' => $_SESSION['flash'] ?? [],
                'form_errors' => [],
                'form_submitted' => [],
            ]);
            unset($_SESSION['flash']);
            break;

        // ---- Rules: list, build, edit ------------------------------------

        case $path === '/rules' && $method === 'GET':
            echo $twig->render('rules/index.twig', [
                'active_nav' => 'rules',
                'rules' => $rules->all(),
                'saved' => isset($_GET['saved']),
                'flash' => $_SESSION['flash'] ?? [],
            ]);
            unset($_SESSION['flash']);
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
                $id = $rules->insert($definition['name'], $definition);
                // A rule can be born already over the line - evaluate at save.
                $_SESSION['flash'] = array_merge($_SESSION['flash'] ?? [], $evaluateAndRecord(
                    [['id' => $id, 'name' => $definition['name'], 'definition' => $definition]]
                ));
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
                // Edits re-evaluate too: a tightened budget may already be crossed.
                $_SESSION['flash'] = array_merge($_SESSION['flash'] ?? [], $evaluateAndRecord(
                    [['id' => (int) $m[1], 'name' => $definition['name'], 'definition' => $definition]]
                ));
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
                $resuming = !$rule['is_active'];
                $rules->setActive((int) $m[1], $resuming);
                if ($resuming) {
                    // Resuming counts as a save: the rule may come back over the line.
                    $_SESSION['flash'] = array_merge($_SESSION['flash'] ?? [], $evaluateAndRecord([$rule]));
                }
            }
            header('Location: /rules');
            break;

        // ---- AJAX endpoints used by the builder --------------------------

        case $path === '/rules/preview' && $method === 'POST':
            header('Content-Type: application/json');
            try {
                // No name required: the preview shows what the rule MEANS,
                // and the engine needs a non-empty one to validate.
                $definition = RuleFactory::fromForm($_POST, requireName: false);
                $definition['name'] = $definition['name'] !== '' ? $definition['name'] : 'Preview';
                $payload = ['ok' => true, 'sentence' => Sentence::render($definition)];

                // Live match count: what the rule sees right now, over its
                // window, as of today. Sentence = meaning; this = present;
                // backtest = past.
                try {
                    $today = new DateTimeImmutable('today');
                    $windowStart = $today
                        ->modify(sprintf('-%d days', $definition['window_days'] - 1))
                        ->format('Y-m-d');
                    $verdict = $engine->evaluate($definition, $txns->sinceForEngine($windowStart), $today->format('Y-m-d'));

                    $gap = null;
                    if (!$verdict['triggered']) {
                        if ($definition['threshold']['metric'] === 'total') {
                            $gapCents = max((int) round($definition['threshold']['value'] * 100) - (int) $verdict['window_total_cents'], 0);
                            $gap = '$' . number_format($gapCents / 100, 2);
                        } else {
                            $gap = (string) max((int) ceil($definition['threshold']['value']) - (int) $verdict['transaction_count'], 0);
                        }
                    }

                    $payload['now'] = [
                        'metric' => $definition['threshold']['metric'],
                        'total_cents' => (int) $verdict['window_total_cents'],
                        'count' => (int) $verdict['transaction_count'],
                        'triggered' => (bool) $verdict['triggered'],
                        'gap' => $gap,
                    ];
                } catch (Throwable $e) {
                    // The sentence alone is still useful without the count.
                }

                echo json_encode($payload);
            } catch (RuleValidationException $e) {
                echo json_encode(['ok' => false, 'errors' => array_values($e->errors)]);
            }
            break;

        case $path === '/rules/backtest' && $method === 'POST':
            header('Content-Type: application/json');
            try {
                $definition = RuleFactory::fromForm($_POST, requireName: false);
                $definition['name'] = $definition['name'] !== '' ? $definition['name'] : 'Preview';
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
            $date = (string) ($_POST['date'] ?? date('Y-m-d'));

            // Home made is a checkbox; it fixes both the category and a
            // sensible merchant. Typing "Home Brew" as the merchant counts too.
            $isHomeMade = isset($_POST['home_made']) || strcasecmp($merchant, 'Home Brew') === 0;
            $category = $isHomeMade ? 'home_made' : 'bought';
            if ($isHomeMade) {
                $merchant = 'Home Brew';
            }

            if ($merchant === '') {
                $errors['merchant'] = 'Merchant is required.';
            } elseif (mb_strlen($merchant) > 120) {
                $errors['merchant'] = 'Merchant must be 120 characters or fewer.';
            }
            if ($amount <= 0) {
                $errors['amount'] = 'Amount must be greater than zero.';
            } elseif ($amount > 10000) {
                $errors['amount'] = 'Amount is too large.';
            }
            // The "!" resets unparsed components to zero - without it,
            // createFromFormat fills the time with *now*, and today reads as
            // the future.
            $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            $today = new DateTimeImmutable('today');
            if (!$dt || $dt->format('Y-m-d') !== $date) {
                $errors['date'] = 'Invalid date.';
            } elseif ($dt > $today) {
                $errors['date'] = 'Date cannot be in the future.';
            } elseif ($dt < $today->modify('-365 days')) {
                $errors['date'] = 'Date is too far in the past.';
            }

            if ($errors) {
                http_response_code(422);
                echo $twig->render('dashboard.twig', $dashboardContext() + [
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
                'text' => sprintf('Added $%s at %s.', number_format($amount, 2), $merchant),
            ];

            // The moment of truth: every active rule gets one shot at the new
            // purchase. Save-time evaluation (create/edit/resume) walks the
            // same helper - one code path, one dedupe.
            $_SESSION['flash'] = array_merge($_SESSION['flash'] ?? [], $evaluateAndRecord($rules->active()));

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
