#!/bin/bash
set -e

echo "=== Resetting database ==="
php bin/console dbal:run-sql "DROP SCHEMA IF EXISTS public CASCADE" 2>/dev/null || true
php bin/console dbal:run-sql "CREATE SCHEMA public" 2>/dev/null || true
php bin/console dbal:run-sql "ALTER DATABASE covocam_db SET search_path TO public" 2>/dev/null || true

echo "=== Running migrations ==="
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --all-or-nothing || echo "Migrations failed, continuing..."

echo "=== Clearing cache ==="
php bin/console cache:clear --env=prod --no-debug || true

echo "=== Starting server ==="
exec php -S 0.0.0.0:8000 -t public
