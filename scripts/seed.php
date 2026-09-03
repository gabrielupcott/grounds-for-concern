<?php

// Seed ~90 days of deterministic coffee spending.
//
//   php scripts/seed.php
//
// Fixed seed (mt_srand 42) => identical data on every run for a given "today".
// Coffee only: bought (cafés) + home made (cheap). A streak pass guarantees a
// few home-made days ending today, and the 7-day BOUGHT total is tuned to
// $56.50 - so the demo rule ("more than $60 on bought coffee within 7 days")
// is one latte away from firing.
//
// Seeds transactions plus ONE built-in rule: the weekly bought budget the
// dashboard meter reads its threshold from (rules are data - delete or edit
// it like any other). Alerts still start empty on purpose: they're earned
// by live fires.

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

// Pin the clock. XAMPP's php.ini defaults to Europe/Berlin; MySQL uses the
// system (Eastern) clock. Without this, PHP seeds "tomorrow".
date_default_timezone_set('America/Toronto');

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']),
    $db['user'],
    $db['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

mt_srand(42); // deterministic runs

const DAYS = 90;
const HOME_BREW = 'Home Brew';

// The whole merchant universe — keeps the app's autocomplete short.
$cafes = ['Tim Hortons', "Paisley's Coffee House", 'Relay Coffee', 'Mulberry Coffeehouse'];

function pick(array $items): string
{
    return $items[mt_rand(0, count($items) - 1)];
}

// Round to the nearest quarter - prices read like real till amounts.
function cents(int $min, int $max): int
{
    return (int) (round(mt_rand($min, $max) / 25) * 25);
}

function day(int $offset): string
{
    return date('Y-m-d', strtotime("$offset days"));
}

$pdo->beginTransaction();
// FK-safe order.
$pdo->exec('DELETE FROM alerts');
$pdo->exec('DELETE FROM rules');
$pdo->exec('DELETE FROM transactions');

$insert = $pdo->prepare(
    'INSERT INTO transactions (occurred_on, merchant, category, amount_cents) VALUES (?, ?, ?, ?)'
);

for ($i = -DAYS; $i <= 0; $i++) {
    $date = day($i);

    // Bought coffee: 0-3 a day, most days 1-2. Tuned so a normal week sits in
    // the high $40s — history crosses $60 only on genuinely heavy weeks.
    $r = mt_rand(1, 100);
    $cups = $r <= 26 ? 0 : ($r <= 61 ? 1 : ($r <= 88 ? 2 : 3));
    for ($c = 0; $c < $cups; $c++) {
        $insert->execute([$date, pick($cafes), 'bought', cents(325, 625)]);
    }

    // Home made: most days, 1-2 cups, a fraction of café prices.
    if (mt_rand(1, 100) <= 55) {
        for ($c = 0, $n = mt_rand(1, 2); $c < $n; $c++) {
            $insert->execute([$date, HOME_BREW, 'home_made', cents(35, 75)]);
        }
    }
}

// A few deliberately heavy café stretches (crunch weeks, long shifts),
// spaced through history so the backtest has real episodes to find.
foreach ([-75, -50, -25] as $anchor) {
    for ($d = 0; $d < 4; $d++) {
        $date = day($anchor + $d);
        for ($c = 0, $n = mt_rand(2, 3); $c < $n; $c++) {
            $insert->execute([$date, pick($cafes), 'bought', cents(375, 575)]);
        }
    }
}

// --- Streak pass: last 3 days are home-made-only ------------------------------
// No bought coffee, at least one home made each day. (Runs before the tuner,
// which then fixes the bought week total.)

for ($i = -2; $i <= 0; $i++) {
    $date = day($i);
    $pdo->prepare("DELETE FROM transactions WHERE category='bought' AND occurred_on=?")->execute([$date]);
    $hasHome = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE category='home_made' AND occurred_on=?");
    $hasHome->execute([$date]);
    if ((int) $hasHome->fetchColumn() === 0) {
        $insert->execute([$date, HOME_BREW, 'home_made', cents(35, 75)]);
    }
}

// --- Demo tuning: 7-day bought total lands at exactly $56.50 -------------------
// Distribute the adjustment across recent café purchases (plausible amounts);
// top up with a fresh purchase today if the window is dry.

$windowStart = day(-6);
$tuneTarget = 5650;

function weekBoughtTotal(PDO $pdo, string $windowStart): int
{
    return (int) $pdo->query(
        "SELECT SUM(amount_cents) FROM transactions WHERE category='bought' AND occurred_on >= '$windowStart'"
    )->fetchColumn();
}

$delta = $tuneTarget - weekBoughtTotal($pdo, $windowStart);

if ($delta !== 0) {
    $recent = $pdo->query(
        "SELECT id, amount_cents FROM transactions WHERE category='bought' AND occurred_on >= '$windowStart' ORDER BY occurred_on DESC, id DESC"
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
        while ($delta > 0) {
            $take = min($delta, 575);
            if ($take < 250) {
                // Fold a tiny remainder into the newest purchase.
                $pdo->exec("UPDATE transactions SET amount_cents = amount_cents + $take WHERE id = " . (int) $recent[0]['id']);
                $delta = 0;
                break;
            }
            // Top up 4 days back: inside the demo week, outside the streak.
            $insert->execute([day(-3), pick($cafes), 'bought', $take]);
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

$weekTotal = weekBoughtTotal($pdo, $windowStart);
echo 'current 7-day bought total: ' . sprintf('$%.2f', $weekTotal / 100) . ($weekTotal === $tuneTarget ? " (tuned)\n" : " (untuned)\n");

// --- Episode trim: history should show exactly 3 heavy weeks (the injected
// crunch weeks). If an organic week also crosses $60, shave the weakest
// excess episode back under the line so the backtest tells a clean story.

function boughtEpisodes(PDO $pdo): array
{
    $rows = $pdo->query("SELECT id, occurred_on, amount_cents FROM transactions WHERE category='bought' ORDER BY occurred_on")->fetchAll(PDO::FETCH_ASSOC);
    $byDay = [];
    foreach ($rows as $t) {
        $byDay[$t['occurred_on']] = ($byDay[$t['occurred_on']] ?? 0) + (int) $t['amount_cents'];
    }
    $episodes = [];
    $firingYesterday = false;
    foreach ($rows as $t) {
        $end = $t['occurred_on'];
        $sum = 0;
        for ($d = 0; $d < 7; $d++) {
            $sum += $byDay[date('Y-m-d', strtotime("$end -$d days"))] ?? 0;
        }
        $firingToday = $sum > 6000;
        if ($firingToday && !$firingYesterday) {
            $episodes[] = ['end' => $end, 'peak' => $sum];
        }
        $firingYesterday = $firingToday;
    }
    return $episodes;
}

$maxEpisodes = 3;
$guard = 0;
while (count($episodes = boughtEpisodes($pdo)) > $maxEpisodes && $guard++ < 10) {
    // Weakest episode first - injected crunch weeks are strong, so organic
    // strays get trimmed before anything the story depends on.
    usort($episodes, fn ($a, $b) => $a['peak'] <=> $b['peak']);
    $weak = $episodes[0];
    $excess = $weak['peak'] - 6000 + 25; // under the line, with margin
    $window = [];
    for ($d = 0; $d < 7; $d++) {
        $window[] = date('Y-m-d', strtotime("{$weak['end']} -$d days"));
    }
    // Never touch the demo week - the tuner owns it.
    $txns = $pdo->query(
        "SELECT id, amount_cents FROM transactions WHERE category='bought' AND occurred_on < '$windowStart' AND occurred_on IN ('" . implode("','", $window) . "') ORDER BY amount_cents DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
    $progress = false;
    foreach ($txns as $t) {
        if ($excess <= 0) break;
        $shave = min($t['amount_cents'] - 250, $excess); // keep amounts plausible
        if ($shave > 0) {
            $pdo->prepare('UPDATE transactions SET amount_cents = ? WHERE id = ?')
                ->execute([$t['amount_cents'] - $shave, $t['id']]);
            $excess -= $shave;
            $progress = true;
        }
    }
    if (!$progress) break; // can't shave further without violating guards
}

// --- Built-in rule: the weekly bought budget -----------------------------------
// Dashboard meters draw their line from the user's rules (no rule, no bar),
// so the demo's flagship budget ships as seeded data.

$weeklyBudget = [
    'name' => 'Weekly bought budget',
    'window_days' => 7,
    'group' => [
        'match' => 'all',
        'conditions' => [['field' => 'category', 'operator' => 'is', 'value' => 'bought']],
    ],
    'threshold' => ['metric' => 'total', 'operator' => '>', 'value' => 60],
];
$pdo->prepare('INSERT INTO rules (name, definition) VALUES (?, ?)')
    ->execute([$weeklyBudget['name'], json_encode($weeklyBudget, JSON_THROW_ON_ERROR)]);

$pdo->commit();

// --- Summary -------------------------------------------------------------------

$stats = $pdo->query(
    'SELECT category, COUNT(*) AS n, SUM(amount_cents) AS total FROM transactions GROUP BY category'
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($stats as $s) {
    echo sprintf("%-10s %3d rows  $%8.2f\n", $s['category'], $s['n'], $s['total'] / 100);
}

// Distinct heavy BOUGHT weeks - same convention as the backtest.
echo "heavy bought weeks (would-fire episodes) in history: " . count(boughtEpisodes($pdo)) . "\n";

// Current home-made streak (days in a row: >=1 home made, 0 bought).
$days = $pdo->query(
    "SELECT occurred_on,
            MAX(category='bought') AS had_bought,
            MAX(category='home_made') AS had_home
     FROM transactions GROUP BY occurred_on ORDER BY occurred_on DESC LIMIT 60"
)->fetchAll(PDO::FETCH_ASSOC);
$streak = 0;
foreach ($days as $d) {
    if ($d['had_bought'] || !$d['had_home']) break;
    $streak++;
}
echo "home-made streak: $streak days\n";
echo "built-in rule: Weekly bought budget: spend over \$60 on cafe coffee in 7 days\n";
echo "seed: ok\n";
