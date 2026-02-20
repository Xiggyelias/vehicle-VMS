# Dokploy Deployment Guide

## 1) Create the app
- In Dokploy, create a **Docker Compose** app.
- Point it to this repository/branch.
- Use `docker-compose.yml` from the project root.

## 2) Configure environment variables
- Add all variables from `.env.dokploy.example`.
- Required in production:
  - `BASE_URL`
  - `DB_USERNAME`
  - `DB_PASSWORD`
  - `DB_NAME`
  - `MYSQL_ROOT_PASSWORD`
  - SMTP credentials (`SMTP_HOST`, `SMTP_USERNAME`, `SMTP_PASSWORD`, etc.)
- Optional:
  - `RUN_MIGRATIONS_ON_START=true` (runs `php migrate.php` at container boot)
  - `OCR_SPACE_API_KEY`
  - Google OAuth values

## 3) Domain and port
- Attach your domain to the `app` service.
- Use container port `80`.

## 4) Deploy
- Trigger deployment in Dokploy.
- Health checks:
  - App: `GET /health-check.php`
  - DB: `mysqladmin ping`

## 5) Database initialization
- Recommended first deploy flow:
  1. Keep `RUN_MIGRATIONS_ON_START=false`.
  2. Deploy once.
  3. Open app container terminal in Dokploy and run:
     - `php migrate.php --status`
     - `php migrate.php`
  4. Restore/import your existing DB backup (if migrating legacy data).

## 6) Post-deploy verification
- Confirm app health endpoint returns HTTP 200:
  - `https://your-domain/health-check.php`
- Confirm login, vehicle CRUD, and admin pages.
- Confirm uploads and logs are writable (`uploads_data`, `logs_data` volumes).

## Notes
- Container startup now enforces required production env vars.
- Apache is hardened to block direct access to sensitive files (`.env`, `.sql`, `.log`, `tests/`, `database/`).
