# Production Deployment Checklist

**Last Updated:** February 18, 2026

This checklist is aligned with the Docker + Dokploy setup in this repository.

## 1) Secrets and environment
- Do not commit `.env`.
- Set production env values in Dokploy or server secrets:
  - `APP_ENV=production`
  - `BASE_URL=https://your-domain`
  - `DB_HOST`, `DB_USERNAME`, `DB_PASSWORD`, `DB_NAME`
  - `SESSION_SECURE=true`
  - `SESSION_HTTP_ONLY=true`
  - `DISPLAY_ERRORS=false`
  - `LOG_ERRORS=true`
  - SMTP credentials

## 2) Build/runtime hardening
- `Dockerfile` uses production install (`composer install --no-dev`).
- Apache hardening is enabled via `docker/apache-prod.conf`:
  - directory listing disabled
  - sensitive files blocked
  - `tests/` and `database/` blocked from web access
- Startup checks run in `docker/entrypoint.sh`:
  - required env validation in production
  - rejects placeholder/example values
  - requires HTTPS `BASE_URL` (unless `ALLOW_INSECURE_BASE_URL=true`)
  - requires non-root DB app user
  - waits for DB readiness before auto-migrations
  - optional migration execution with `RUN_MIGRATIONS_ON_START=true`

## 3) Health and readiness
- Endpoint: `GET /health-check.php`
  - checks PHP runtime
  - checks DB connectivity
  - checks writable storage (`uploads/`, `logs/`)
- Docker healthcheck is wired to `health-check.php`.

## 4) Database setup
- Use migration runner:
  - `php migrate.php --status`
  - `php migrate.php`
- Import legacy data backup after schema is ready.
- Create admin account using:
  - `php scripts/create-admin.php`

## 5) Security validation
- Verify admin endpoints require admin auth.
- Verify CSRF protection for mutating actions.
- Verify rate limiting is active.
- Verify session regeneration on login/privilege changes.
- Verify logs are persisted and monitored:
  - `logs/error.log`
  - `logs/audit.log`

## 6) Functional smoke tests
- User login (Google and standard flows if enabled)
- Vehicle CRUD
- Driver CRUD
- Admin dashboard and reports
- Password reset email flow

## 7) Operational tasks
- Configure HTTPS at the Dokploy ingress/proxy layer.
- Set backup strategy for DB and `uploads_data` volume.
- Monitor health status and error logs after deploy.
