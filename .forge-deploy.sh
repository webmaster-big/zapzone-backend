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

# Fix storage symlink after activation (current symlink now points to new release)
# Remove the storage symlink from the release directory
if [ -L $FORGE_SITE_PATH/current/public/storage ]; then
    rm $FORGE_SITE_PATH/current/public/storage
fi

# Create proper symlink to shared storage
ln -sfn $FORGE_SITE_PATH/storage/app/public $FORGE_SITE_PATH/current/public/storage

# Set proper permissions
chmod -R 775 $FORGE_SITE_PATH/storage
chmod -R 775 $FORGE_SITE_PATH/current/bootstrap/cache

$RESTART_QUEUES()
