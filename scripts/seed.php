<?php

// Seed ~90 days of deterministic demo transactions.
//
//   php scripts/seed.php
//
// Fixed seed (mt_srand 42) => identical data on every run for a given "today".
// Coffee is deliberately heavy so the demo rule ("spend over $60 on coffee
// within 7 days") has history to backtest against, and the current 7-day
// coffee total is auto-tuned to ~$56.50 — so adding one ~$4.75 latte during
// the demo tips it over the line.
//
// Seeds transactions ONLY. Rules and alerts start empty on purpose:
// the demo builds the rule live, and the app gets to show its empty states.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

// Pin the clock. XAMPP's php.ini defaults to Europe/Berlin; MySQL uses the
// system (Eastern) clock. Without this, PHP seeds "tomorrow".
date_default_timezone_set('America/Toronto');

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

mt_srand(42); // deterministic runs

const DAYS = 90;

$merchants = [
    'coffee'       => ['Tim Hortons', 'Starbucks', 'Second Cup', 'Williams Coffee Pub'],
    'food'         => ["McDonald's", 'Subway', "Harvey's", 'Popeyes', 'Ghazali Shawarma'],
    'groceries'    => ['Fortinos', 'No Frills', 'FreshCo', 'Food Basics'],
    'transport'    => ['Presto', 'Shell', 'Uber'],
    'entertainment' => ['Cineplex', 'Steam'],
    'other'        => ['Amazon', 'Shoppers Drug Mart', 'Dollarama'],
];

function pick(array $items): string
{
    return $items[mt_rand(0, count($items) - 1)];
}

// Round to the nearest quarter — prices read like real till amounts.
function cents(int $min, int $max): int
{
    return (int) (round(mt_rand($min, $max) / 25) * 25);
}

$insert = $pdo->prepare(
    'INSERT INTO transactions (occurred_on, merchant, category, amount_cents) VALUES (?, ?, ?, ?)'
);

function add(PDOStatement $insert, string $date, string $merchant, string $category, int $amount): void
{
    $insert->execute([$date, $merchant, $category, $amount]);
}

$pdo->beginTransaction();
// FK-safe order.
$pdo->exec('DELETE FROM alerts');
$pdo->exec('DELETE FROM rules');
$pdo->exec('DELETE FROM transactions');

for ($i = DAYS; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));

    // Coffee: 0-3 a day, most days 1-2. Avg lands around $7/day (~$47/week),
    // so history crosses $60 only on genuinely heavy weeks.
    $r = mt_rand(1, 100);
    $cups = $r <= 18 ? 0 : ($r <= 54 ? 1 : ($r <= 86 ? 2 : 3));
    for ($c = 0; $c < $cups; $c++) {
        add($insert, $date, pick($merchants['coffee']), 'coffee', cents(325, 625));
    }

    // Food: most days, one meal out.
    if (mt_rand(1, 100) <= 40) {
        add($insert, $date, pick($merchants['food']), 'food', cents(850, 2150));
    }

    // Groceries: ~2 trips a week.
    if (mt_rand(1, 100) <= 28) {
        add($insert, $date, pick($merchants['groceries']), 'groceries', cents(2800, 8500));
    }

    // Transport.
    if (mt_rand(1, 100) <= 30) {
        $m = pick($merchants['transport']);
        $amount = $m === 'Presto' ? (mt_rand(1, 2) * 325)
            : ($m === 'Shell' ? cents(4200, 6800) : cents(900, 1900));
        add($insert, $date, $m, 'transport', $amount);
    }

    // Entertainment (Netflix handled separately — fixed monthly).
    if (mt_rand(1, 100) <= 12) {
        $m = pick($merchants['entertainment']);
        add($insert, $date, $m, 'entertainment', $m === 'Cineplex' ? cents(1400, 1750) : cents(2000, 7900));
    }

    // Everything else.
    if (mt_rand(1, 100) <= 25) {
        add($insert, $date, pick($merchants['other']), 'other', cents(500, 3900));
    }

    // Netflix on the 1st of each month.
    if (date('j', strtotime($date)) === '1') {
        add($insert, $date, 'Netflix', 'entertainment', 1699);
    }
}

// A few deliberately heavy coffee stretches (crunch weeks, long shifts) —
// spaced through history so the backtest has real episodes to find.
foreach ([-75, -50, -25] as $anchor) {
    for ($d = 0; $d < 4; $d++) {
        $date = date('Y-m-d', strtotime("{$anchor} days +{$d} days"));
        for ($c = 0; $c < mt_rand(2, 3); $c++) {
            add($insert, $date, pick($merchants['coffee']), 'coffee', cents(375, 575));
        }
    }
}

// --- Demo tuning: make the current 7-day coffee total land at ~$56.50 -------
// so one more latte crosses the $60 demo threshold. Distribute the adjustment
// across the most recent coffee purchases (keep amounts plausible), and top
// up with a fresh purchase today if the window is unusually dry.

$windowStart = date('Y-m-d', strtotime('-6 days'));
$tuneTarget = 5650;

function weekCoffeeTotal(PDO $pdo, string $windowStart): int
{
    return (int) $pdo->query("SELECT SUM(amount_cents) FROM transactions WHERE category='coffee' AND occurred_on >= '$windowStart'")->fetchColumn();
}

$delta = $tuneTarget - weekCoffeeTotal($pdo, $windowStart);

if ($delta !== 0) {
    $recent = $pdo->query(
        "SELECT id, amount_cents FROM transactions WHERE category='coffee' AND occurred_on >= '$windowStart' ORDER BY occurred_on DESC, id DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    if ($delta > 0) {
        foreach ($recent as $t) {
            if ($delta <= 0) break;
            $take = min(900 - $t['amount_cents'], $delta); // plausible max ~$9
            if ($take > 0) {
                $pdo->prepare('UPDATE transactions SET amount_cents = ? WHERE id = ?')
                    ->execute([$t['amount_cents'] + $take, $t['id']]);
                $delta -= $take;
            }
        }
        // Still short? A coffee run today covers the rest.
        while ($delta > 0) {
            $take = min($delta, 575);
            if ($take < 250) {
                // Fold a tiny remainder into the newest purchase.
                $pdo->exec("UPDATE transactions SET amount_cents = amount_cents + $take WHERE id = " . (int) $recent[0]['id']);
                $delta = 0;
                break;
            }
            add($insert, date('Y-m-d'), pick($merchants['coffee']), 'coffee', $take);
            $delta -= $take;
        }
    } else {
        foreach ($recent as $t) {
            if ($delta >= 0) break;
            $take = min($t['amount_cents'] - 175, -$delta); // plausible min ~$1.75
            if ($take > 0) {
                $pdo->prepare('UPDATE transactions SET amount_cents = ? WHERE id = ?')
                    ->execute([$t['amount_cents'] - $take, $t['id']]);
                $delta += $take;
            }
        }
    }
}

$weekTotal = weekCoffeeTotal($pdo, $windowStart);
echo "current 7-day coffee total: " . sprintf('$%.2f', $weekTotal / 100) . ($weekTotal === $tuneTarget ? " (tuned)" : " (untuned)") . "\n";

$pdo->commit();

// --- Summary -----------------------------------------------------------------

$stats = $pdo->query(
    'SELECT category, COUNT(*) AS n, SUM(amount_cents) AS total FROM transactions GROUP BY category'
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($stats as $s) {
    echo sprintf("%-13s %3d rows  $%8.2f\n", $s['category'], $s['n'], $s['total'] / 100);
}

// How many distinct "heavy coffee weeks" are in the seeded history?
// (Consecutive over-threshold window-days collapse into one episode — the
// same convention the backtest uses, so the numbers match the UI.)
$coffee = $pdo->query("SELECT occurred_on, amount_cents FROM transactions WHERE category='coffee' ORDER BY occurred_on")->fetchAll(PDO::FETCH_ASSOC);
$byDay = [];
foreach ($coffee as $t) {
    $byDay[$t['occurred_on']] = ($byDay[$t['occurred_on']] ?? 0) + (int) $t['amount_cents'];
}
$episodes = 0;
$firingYesterday = false;
foreach ($coffee as $t) {
    $end = $t['occurred_on'];
    $sum = 0;
    for ($d = 0; $d < 7; $d++) {
        $day = date('Y-m-d', strtotime("$end -$d days"));
        $sum += $byDay[$day] ?? 0;
    }
    $firingToday = $sum > 6000;
    if ($firingToday && !$firingYesterday) {
        $episodes++;
    }
    $firingYesterday = $firingToday;
}
echo "heavy coffee weeks (would-fire episodes) in history: $episodes\n";
echo "seed: ok\n";
