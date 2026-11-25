#!/bin/sh
# Runs before every container command (web, queue, scheduler).
# Only the web container (ROLE=web) migrates and caches, so the three don't race.
set -e
cd /app

if [ "${ROLE:-web}" = "web" ]; then
  # Wait for MySQL (first boot initialises the data directory, which takes a while)
  for i in $(seq 1 60); do
    php artisan db:show >/dev/null 2>&1 && break
    echo "Waiting for the database ($i)…"
    sleep 3
  done
  php artisan migrate --force
  php artisan optimize
  php artisan storage:link >/dev/null 2>&1 || true
  # Prints a table; a FAIL line means a setting in .env needs fixing (docs in deploy/README.md)
  php artisan app:production-check || echo "!! app:production-check found problems: see above"
else
  # Workers and the scheduler only need the cached config
  sleep 20
  php artisan optimize
fi

exec "$@"
