#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

mkdir -p logs uploads uploads/secure
chown -R www-data:www-data logs uploads

is_placeholder_value() {
  local value="${1:-}"
  case "$value" in
    change_me|change_me_root|your-email@example.com|your-app-password|noreply@example.com|https://your-domain.example.com)
      return 0
      ;;
  esac
  return 1
}

wait_for_database() {
  local max_attempts="${DB_WAIT_MAX_ATTEMPTS:-30}"
  local sleep_seconds="${DB_WAIT_SLEEP_SECONDS:-2}"
  local attempt=1

  while [[ "${attempt}" -le "${max_attempts}" ]]; do
    if php -r '
      $host = getenv("DB_HOST") ?: "db";
      $port = (int)(getenv("DB_PORT") ?: 3306);
      $user = getenv("DB_USERNAME") ?: "";
      $pass = getenv("DB_PASSWORD") ?: "";
      $name = getenv("DB_NAME") ?: "";
      mysqli_report(MYSQLI_REPORT_OFF);
      $conn = @new mysqli($host, $user, $pass, $name, $port);
      if ($conn && !$conn->connect_errno) {
          $conn->close();
          exit(0);
      }
      exit(1);
    ' >/dev/null 2>&1; then
      return 0
    fi

    echo "[entrypoint] Waiting for database connection (${attempt}/${max_attempts})..."
    sleep "${sleep_seconds}"
    attempt=$((attempt + 1))
  done

  return 1
}

if [[ "${APP_ENV:-production}" == "production" ]]; then
  required_vars=(
    BASE_URL
    DB_HOST
    DB_USERNAME
    DB_PASSWORD
    DB_NAME
    SMTP_HOST
    SMTP_USERNAME
    SMTP_PASSWORD
    SMTP_FROM_EMAIL
  )

  for required in "${required_vars[@]}"; do
    current_value="${!required:-}"

    if [[ -z "${current_value}" ]]; then
      echo "[entrypoint] Missing required environment variable in production: ${required}" >&2
      exit 1
    fi

    if is_placeholder_value "${current_value}"; then
      echo "[entrypoint] Environment variable '${required}' still uses an example value. Set a real production value." >&2
      exit 1
    fi
  done

  if [[ "${DB_USERNAME}" == "root" ]]; then
    echo "[entrypoint] DB_USERNAME must be a non-root application user in production." >&2
    exit 1
  fi

  if [[ "${ALLOW_INSECURE_BASE_URL:-false}" != "true" && ! "${BASE_URL}" =~ ^https:// ]]; then
    echo "[entrypoint] BASE_URL must start with https:// in production. Set ALLOW_INSECURE_BASE_URL=true only for temporary testing." >&2
    exit 1
  fi
fi

# Wait for DB readiness by default so first requests do not fail on cold start.
if [[ "${WAIT_FOR_DB_ON_START:-true}" == "true" ]]; then
  if ! wait_for_database; then
    echo "[entrypoint] Database did not become ready in time at ${DB_HOST:-db}:${DB_PORT:-3306}. Aborting startup." >&2
    exit 1
  fi
fi

if [[ "${RUN_MIGRATIONS_ON_START:-false}" == "true" ]]; then
  echo "[entrypoint] Running database migrations..."
  php migrate.php
fi

exec "$@"
