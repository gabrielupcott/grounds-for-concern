# Grounds for Concern

A small self-hosted budget watcher for one habit: coffee. Purchases are
split **home made** vs **bought**, you define rules like *"alert me when I
spend more than $60 on bought coffee within any 7 days"*, and the app
watches your feed and fires an alert the moment a new purchase pushes you
over the line. There's also a home-made streak, because the cheapest cup
is the one you brew yourself.

- **PHP + Twig** front end (no framework, plain PDO)
- **Go** rule engine — a small stateless HTTP service that does evaluation and
  backtesting
- **MySQL** storage; rules are stored as data (JSON), not code

## The idea

Budget apps bury you in charts. The interesting question is simpler:
*"tell me when I'm overdoing it, and prove it with my own history."*

So the rule builder has three things you won't usually find:

1. A **plain-English preview** that rewrites itself as you build the rule.
2. A **live match count** — as you build, the sidebar shows what the rule sees
   right now ("Current Total: $56.50 ($3.50 from firing)") against your
   real data.
3. A **backtest** — "this rule would have fired 3 times in the last 90 days,
   here are the dates" — so you trust it before you save it.

Rules can watch the category (home made / bought), the merchant, or both —
so a café budget can exclude home brew, or count it. The streak card tracks
consecutive days with at least one home-made cup and zero bought coffee.

## Try it

1. Log a `$4.75` bought coffee on the dashboard. Watch the weekly meter and
   the home-made streak.
2. Build the rule above (the builder starts you close to it). Watch the
   preview sentence and live count change as you click. Run the backtest.
3. Save it, then log one more coffee. The rule fires, and the alert lands in
   the inbox with the exact window: how much, across how many purchases.

## Setup

You need PHP 8+, Go 1.22+, and MySQL 8+. (Developed on Windows with XAMPP's
PHP; anywhere `php` runs works.)

```bash
# 1. Create the database and a user (once)
mysql -u root -p -e "CREATE DATABASE grounds CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER 'grounds'@'localhost' IDENTIFIED BY 'grounds'; GRANT ALL PRIVILEGES ON grounds.* TO 'grounds'@'localhost';"

# 2. Configure (gitignored — never commit real credentials)
cp config.example.php config.php   # then edit the password

# 3. Install PHP dependencies (Twig, PHPUnit)
php composer.phar install

# 4. Create tables and seed ~90 days of demo transactions
php scripts/migrate.php
php scripts/seed.php

# 5. Run it
scripts/start.bat        # Windows: boots both processes and opens the browser
# or manually:
go run ./engine/cmd/grounds-api          # rule engine on :8081
php -S 127.0.0.1:8080 -t public public/index.php   # app on :8080
```

## How it fits together

```
Browser ──► PHP app :8080 (front controller + Twig)
              │  PDO
              ▼
            MySQL (transactions / rules / alerts)
              │  JSON over HTTP
              ▼
            Go engine :8081 (stateless: rule + transactions in, verdict out)
```

PHP owns data and presentation. Go owns the math — it never touches the
database, which makes it trivially testable and lets live evaluation and
backtesting share the exact same code path. Rules are JSON documents built
from constrained selects, so the UI can't construct logic the engine can't
parse. Money is integer cents everywhere except display.

Alerts are deduplicated by a database constraint (`UNIQUE (rule_id,
triggered_on)`) — evaluating the same rule twice on the same day can't spam
the inbox.

## Tests

```bash
cd engine && go test ./...     # rule matching, windows, thresholds, backtest oracle
php composer.phar test         # form → rule mapping, English sentence rendering
# (if php isn't on PATH: php vendor/phpunit/phpunit/phpunit)
```

The backtest has an oracle test: the sliding-window implementation must agree
with a naive evaluate-every-day replay on generated data.

## Deliberate scope

Single user, no auth, no delivery beyond the inbox, one level of condition
grouping. The point was to finish the core loop — build → preview → backtest
→ save → fire — rather than sprawl. Nesting condition groups is the obvious
next step; the rule JSON is shaped so it's an additive change.
