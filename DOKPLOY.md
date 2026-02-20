# Dokploy Deployment Guide

## 1) Create the app
- In Dokploy, create a **Docker Compose** app.
- Point it to this repository/branch.
- Use `docker-compose.yml` from the project root.

## 2) Configure environment variables
- Add all variables from `.env.dokploy.example`.
- Required in production:
  - `BASE_URL`
  - `APP_PORT` (keep `80` unless you intentionally change container listen port)
  - `DB_HOST` (if using internal compose DB, keep `db`; if using external/managed DB, set the actual host)
  - `DB_PORT`
  - `DB_USERNAME`
  - `DB_PASSWORD`
  - `DB_NAME`
  - `MYSQL_ROOT_PASSWORD`
  - SMTP credentials (`SMTP_HOST`, `SMTP_USERNAME`, `SMTP_PASSWORD`, etc.)
- Production validation enforced at container startup:
  - `DB_USERNAME` must not be `root`
  - `BASE_URL` must be HTTPS (`https://...`)
  - Example placeholders like `change_me` / `your-email@example.com` are rejected
- Optional:
  - `RUN_MIGRATIONS_ON_START=true` (runs `php migrate.php` at container boot)
  - `WAIT_FOR_DB_ON_START=true` (recommended; blocks app start until DB is reachable)
  - `DB_HOST_FALLBACKS=db,mysql,database` (optional host fallback list, comma-separated)
  - `OCR_SPACE_API_KEY`
  - Google OAuth values:
    - `GOOGLE_UX_MODE=popup` (recommended; avoids redirect URI mismatch)
    - `GOOGLE_CALLBACK_URL=https://vehicle.africau.co.zw/auth/google/callback` (used when `GOOGLE_UX_MODE=redirect`)
  - Legacy callback alias is also supported: `https://vehicle.africau.co.zw/google-callback.php`
  - `ALLOW_INSECURE_BASE_URL=true` (temporary non-HTTPS testing only)

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
- Container startup waits for DB readiness before running auto-migrations.
- Apache is hardened to block direct access to sensitive files (`.env`, `.sql`, `.log`, `tests/`, `database/`).
