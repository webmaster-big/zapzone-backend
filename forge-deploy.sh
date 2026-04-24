#!/bin/bash
# Forge auto-deploy script (paste contents into Forge → Site → Deploy Script)
# Triggered automatically on every git push when "Quick Deploy" is enabled.

set -e

cd $FORGE_SITE_PATH

# Pull latest code
git pull origin $FORGE_SITE_BRANCH

# Install PHP dependencies (no dev, optimized autoloader)
$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Run migrations BEFORE reloading FPM so new code never serves requests
# against an un-migrated schema.
$FORGE_PHP artisan migrate --force

# Seed default email notifications for all companies (idempotent — skips existing)
$FORGE_PHP artisan db:seed --class=DefaultEmailNotificationSeeder --force

# Clear and rebuild caches
$FORGE_PHP artisan config:clear
$FORGE_PHP artisan route:clear
$FORGE_PHP artisan view:clear
$FORGE_PHP artisan cache:clear

$FORGE_PHP artisan config:cache
$FORGE_PHP artisan route:cache
$FORGE_PHP artisan event:cache

# Ensure storage symlink exists
$FORGE_PHP artisan storage:link || true

# Reload PHP-FPM LAST so workers pick up new code + cached config + migrated schema
( flock -w 10 9 || exit 1
    echo 'Restarting FPM...'; sudo -S service $FORGE_PHP_FPM reload ) 9>/tmp/fpmlock

echo "Deploy complete: migrations applied, default email notifications seeded."
