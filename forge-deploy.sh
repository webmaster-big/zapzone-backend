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

# Seed default message wording for all companies. Every one of these is idempotent:
# it only creates what is missing, so wording a manager has edited is never overwritten.
$FORGE_PHP artisan db:seed --class=DefaultEmailNotificationSeeder --force
$FORGE_PHP artisan db:seed --class=DefaultSmsNotificationSeeder --force
$FORGE_PHP artisan db:seed --class=DefaultPhotoMessageTemplateSeeder --force

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

echo "Deploy complete: migrations applied, default email, SMS and photo message templates seeded."
