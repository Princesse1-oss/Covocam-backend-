#!/bin/bash
set -e

echo "=== Resetting database ==="
php bin/console dbal:run-sql "DROP SCHEMA IF EXISTS public CASCADE" 2>/dev/null || true
php bin/console dbal:run-sql "CREATE SCHEMA public" 2>/dev/null || true

echo "=== Creating schema from entities ==="
php bin/console doctrine:schema:create --force --no-interaction 2>/dev/null || php bin/console doctrine:schema:create 2>/dev/null || echo "Schema create failed"

echo "=== Seeding admin user ==="
php bin/console app:create-user 2>/dev/null || echo "Admin already exists or creation failed"

echo "=== Clearing cache ==="
php bin/console cache:clear --env=prod --no-debug || true

echo "=== Starting server ==="
exec php -d upload_max_filesize=10M -d post_max_size=12M -d max_execution_time=300 -S 0.0.0.0:8000 -t public
