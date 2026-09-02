# Grounds for Concern

A small self-hosted budget watcher. Define rules like *"alert me when I spend
more than $60 on coffee within any 7 days"*, and the app watches your
transaction feed and fires an alert the moment a new purchase pushes you over
the line.

- **PHP + Twig** front end (no framework, plain PDO)
- **Go** rule engine — a small stateless HTTP service that does evaluation and
  backtesting
- **MySQL** storage; rules are stored as data (JSON), not code

Work in progress.

## The idea

Budget apps bury you in charts. The interesting question is simpler:
*"tell me when I'm overdoing it, and prove it with my own history."*

So every rule builder screen has two extra things:

1. A **plain-English preview** that rewrites itself as you build the rule.
2. A **backtest** — "this rule would have fired 3 times in the last 90 days,
   here are the dates" — so you trust it before you save it.

## Setup

TBD — will be filled in as the pieces land (PHP app, Go engine, MySQL schema).
