#!/bin/bash
set -e

echo "=== Running migrations ==="
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration || echo "Migrations skipped (DB not ready yet)"

echo "=== Clearing cache ==="
php bin/console cache:clear --env=prod --no-debug || true

echo "=== Starting server ==="
exec php -S 0.0.0.0:8000 -t public
