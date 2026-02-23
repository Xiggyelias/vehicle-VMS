# Vehicle Registration System

Production-focused PHP/MySQL system for vehicle registration, driver authorization, and admin management.

## Stack
- PHP 8.2 + Apache
- MySQL 8
- Docker / Docker Compose
- Dokploy-compatible deployment

## Quick Start (Docker)
1. Copy env template:
   - `.env.dokploy.example` -> `.env`
2. Set required values:
   - `BASE_URL`, `DB_HOST=db`, `DB_USERNAME`, `DB_PASSWORD`, `DB_DATABASE` (or `DB_NAME`), `MYSQL_ROOT_PASSWORD`
   - SMTP values (`SMTP_HOST`, `SMTP_USERNAME`, `SMTP_PASSWORD`, `SMTP_FROM_EMAIL`)
   - Use real values (example placeholders are rejected in production startup checks)
3. Start stack:
   - `docker compose up -d --build`
4. Run migrations:
   - `php migrate.php --status`
   - `php migrate.php`
5. Check health:
   - `GET /health-check.php`

## Dokploy
- Use `docker-compose.yml`
- See `DOKPLOY.md` for full setup and post-deploy verification.

## Production Notes
- Apache hardening config is applied from `docker/apache-prod.conf`
- Entry point checks required production env vars (`docker/entrypoint.sh`)
- Optional auto-migrations at startup:
  - `RUN_MIGRATIONS_ON_START=true`
- Health check validates:
  - PHP runtime
  - DB connectivity
  - Writable `uploads/` and `logs/`

## Migrations
- CLI runner: `migrate.php`
- Supports:
  - `--status`
  - `--dry-run`
  - `--file=<name.sql>`
  - `--path=<dir>`
  - `--force`

## Tests
- PHPUnit config: `phpunit.xml`
- Unit tests:
  - `tests/Unit/SecurityMiddlewareTest.php`
  - `tests/Unit/AuthFunctionsTest.php`
- Integration test:
  - `tests/Integration/VehicleCrudIntegrationTest.php`

Run:
- `vendor/bin/phpunit --configuration phpunit.xml --testsuite Unit`
- `vendor/bin/phpunit --configuration phpunit.xml --testsuite Integration`

## API Docs
- AJAX endpoint contracts are documented in `API.md`.
