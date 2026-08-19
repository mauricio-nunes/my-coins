# Repository Guidelines

## Project Structure & Module Organization

My Coins is a Laravel 13 and AdminLTE 4 personal-finance prototype. PHP application code lives in `app/`; controllers are grouped under `app/Http/Controllers/Finance`, session-backed mock data is managed by `app/Services/DemoFinanceStore.php`, and shared helpers live in `app/Support/`. Browser routes are defined in `routes/web.php`.

Blade pages and components are in `resources/views/`; frontend entry points are `resources/css/adminlte.css` and `resources/js/adminlte.js`. Configuration belongs in `config/`, with environment defaults documented in `.env.example`. PHPUnit tests live in `tests/Feature` and `tests/Unit`; Playwright scenarios live in `tests/browser`.

## Build, Test, and Development Commands

Docker is the supported default workflow:

- `make setup` builds containers, installs dependencies, generates the app key, and builds assets.
- `make fix-permissions` repairs generated `node_modules` and `public/build` ownership after container UID conflicts.
- `make up` starts Laravel on port 8000 and Vite on port 5173.
- `make build` creates production frontend assets.
- `make test` runs the PHPUnit suite.
- `make e2e` runs Playwright desktop/mobile flows and axe accessibility checks.
- `make format` applies Laravel Pint formatting.
- `make debug` starts the stack with Xdebug enabled on port 9003.

For Docker Compose v2, append `COMPOSE="docker compose"` to Make commands.
The `up` and `debug` targets recreate disposable service containers to avoid the legacy Compose 1.29 `ContainerConfig` bug; do not replace this with a plain `docker-compose up` without dropping v1 support.

## Coding Style & Naming Conventions

Use four spaces in PHP and Blade, two spaces in JavaScript, and follow PSR-12/Laravel conventions. Run Pint before submitting changes. Use `StudlyCase` for PHP classes, `camelCase` for methods and variables, and descriptive controller names such as `BudgetController`. Blade files use lowercase feature directories and conventional names such as `accounts/index.blade.php`.

Keep monetary values as integer cents. User-facing copy, validation, dates, and currency should remain pt-BR/BRL. Finance data must stay consistent through `DemoFinanceStore` rather than page-specific fixtures.

## Testing Guidelines

Name PHPUnit tests by behavior, for example `test_used_category_cannot_be_deleted`. Add feature tests for routes, validation, and session mutations; add unit tests for calculations and formatting. UI changes should include or update Playwright coverage and must not introduce serious or critical axe violations.

## Commit & Pull Request Guidelines

History currently contains only `Initial commit`, so no established convention exists. Use short, imperative subjects such as `Add credit card account type`. Pull requests should explain behavior and validation changes, list commands run, link relevant issues, and include desktop/mobile screenshots for visible UI changes. Never commit `.env`, credentials, `vendor/`, `node_modules/`, or generated build artifacts.
