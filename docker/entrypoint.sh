#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

mkdir -p logs uploads uploads/secure
chown -R www-data:www-data logs uploads

if [[ "${APP_ENV:-production}" == "production" ]]; then
  required_vars=(BASE_URL DB_HOST DB_USERNAME DB_PASSWORD DB_NAME)
  for required in "${required_vars[@]}"; do
    if [[ -z "${!required:-}" ]]; then
      echo "[entrypoint] Missing required environment variable in production: ${required}" >&2
      exit 1
    fi
  done
fi

if [[ "${RUN_MIGRATIONS_ON_START:-false}" == "true" ]]; then
  echo "[entrypoint] Running database migrations..."
  php migrate.php
fi

exec "$@"
