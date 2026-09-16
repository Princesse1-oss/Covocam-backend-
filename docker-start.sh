#!/bin/bash
set -e

# ============================================================
# Corrige les colonnes manquantes de signalement sur message
# (idempotent : ADD COLUMN IF NOT EXISTS). La base Neon passe
# en autosuspend -> on réessaie jusqu'à ce qu'elle réponde.
# ============================================================
fix_message_columns() {
    php bin/console doctrine:query:sql "ALTER TABLE message ADD COLUMN IF NOT EXISTS est_signale BOOLEAN NOT NULL DEFAULT false" --env=prod --no-interaction
    php bin/console doctrine:query:sql "ALTER TABLE message ADD COLUMN IF NOT EXISTS raison_signalement TEXT DEFAULT NULL" --env=prod --no-interaction
    php bin/console doctrine:query:sql "ALTER TABLE message ADD COLUMN IF NOT EXISTS date_signalement TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL" --env=prod --no-interaction
}

echo "=== Waiting for database ==="
DB_READY=0
for i in $(seq 1 12); do
    if php bin/console doctrine:query:sql "SELECT 1" --env=prod --no-interaction >/dev/null 2>&1; then
        DB_READY=1
        echo "Database is ready."
        break
    fi
    echo "Database not ready (attempt $i/12), retrying in 10s..."
    sleep 10
done

if [ "$DB_READY" = "0" ]; then
    echo "ERROR: database not reachable after retries, continuing anyway."
fi

echo "=== Fixing message signalement columns ==="
fix_message_columns || echo "WARN: message columns fix failed (will retry later)."

echo "=== Updating schema from entities ==="
for i in 1 2 3; do
    if php bin/console doctrine:schema:update --force --no-interaction --env=prod 2>&1; then
        echo "Schema update succeeded."
        break
    else
        echo "Schema update attempt $i/3 failed, retrying in 10s..."
        sleep 10
    fi
done

echo "=== Seeding admin user ==="
php bin/console app:create-user --env=prod 2>&1 || echo "Admin already exists or creation failed"

echo "=== Clearing cache ==="
php bin/console cache:clear --env=prod --no-debug >/dev/null 2>&1 || true

echo "=== Starting server ==="
exec php -d upload_max_filesize=10M -d post_max_size=12M -d max_execution_time=300 -S 0.0.0.0:8000 -t public