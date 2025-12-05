#!/bin/bash

$CREATE_RELEASE()

cd $FORGE_RELEASE_DIRECTORY

$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Build assets
npm ci || npm install && npm run build

# Run migrations
$FORGE_PHP artisan migrate --force

# Clear caches
$FORGE_PHP artisan config:clear
$FORGE_PHP artisan cache:clear
$FORGE_PHP artisan route:clear
$FORGE_PHP artisan view:clear

# Optimize
$FORGE_PHP artisan optimize

$ACTIVATE_RELEASE()

# Fix storage symlink after activation
# The storage:link command creates wrong symlink inside release directory
# We need to recreate it to point to shared storage
cd $FORGE_SITE_PATH/current/public

# Remove incorrect symlink if it exists
[ -L storage ] && rm storage

# Create correct symlink to shared storage (absolute path)
ln -sfn $FORGE_SITE_PATH/storage/app/public storage

# Set proper permissions
chmod -R 775 $FORGE_SITE_PATH/storage
chmod -R 775 $FORGE_SITE_PATH/current/bootstrap/cache

$RESTART_QUEUES()
