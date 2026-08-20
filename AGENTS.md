# Repository Guidelines

## Project Structure & Module Organization

My Coins is a Laravel 13/AdminLTE 4 personal-finance application. PHP code lives in `app/`; finance controllers are under `app/Http/Controllers/Finance`, persistence rules belong in `app/Services/FinanceStore.php`, and shared helpers live in `app/Support/`. Routes are in `routes/web.php`.

Blade templates are in `resources/views/`; frontend entry points are `resources/css/adminlte.css` and `resources/js/adminlte.js`. Database migrations and seeders live in `database/`. PHPUnit tests are split between `tests/Feature` and `tests/Unit`; Playwright scenarios are in `tests/browser`.

## Build, Test, and Development Commands

Docker is the supported workflow:

- `make setup` builds containers, installs dependencies, migrates MySQL, creates the owner, and builds assets.
- `make up` starts Laravel, Vite, and MySQL.
- `make build` builds production assets.
- `make test` runs PHPUnit against `my_coins_testing`.
- `make e2e` runs desktop/mobile Playwright and axe checks against `my_coins_e2e`.
- `make format` applies Laravel Pint.
- `make fix-permissions` repairs ownership of generated frontend files.
- `make debug` enables Xdebug on port 9003.

For Compose v2, append `COMPOSE="docker compose"`. Keep the disposable-container cleanup in `up`/`debug`; it preserves support for legacy Compose 1.29 and its `ContainerConfig` bug.

## Coding Style & Naming Conventions

Use four spaces in PHP and Blade, two in JavaScript, and follow PSR-12/Laravel conventions. Use `StudlyCase` classes, `camelCase` methods, and descriptive names such as `BudgetController`. Blade files use lowercase feature directories, for example `accounts/index.blade.php`.

Store money as integer cents. Keep user-facing text, validation, dates, and currency in pt-BR/BRL. Scope every finance query to the authenticated owner and centralize persistence behavior in `FinanceStore`.

## Testing Guidelines

Name tests by behavior, such as `test_used_category_cannot_be_deleted`. Cover routes, validation, ownership, database mutations, and calculations. Visible changes require Playwright coverage and no serious or critical axe violations. Never point test configuration at development or production databases.

## Commits, Pull Requests & Security

Use short imperative commits, for example `Persist finance data in MySQL`. PRs should describe behavior, list verification commands, link issues, and include desktop/mobile screenshots for UI changes.

Never commit `.env`, credentials, `vendor/`, `node_modules/`, or build artifacts. Create the owner with `php artisan mycoins:install`; recover access with `php artisan mycoins:reset-password`. Preserve forced initial password changes, database sessions, soft-deleted audit records, and ownership boundaries.
