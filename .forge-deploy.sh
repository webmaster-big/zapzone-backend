#!/bin/bash

# Laravel Forge Auto-Deploy Script
# This script runs automatically on every deployment

set -e

echo "Starting deployment..."

cd $FORGE_SITE_PATH

# Pull latest changes (Forge does this automatically, but just in case)
git pull origin $FORGE_SITE_BRANCH

# Install/update Composer dependencies
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

# Create storage symbolic link if it doesn't exist
if [ ! -L public/storage ]; then
    echo "Creating storage symbolic link..."
    php artisan storage:link
fi

# Ensure storage directories exist and have correct permissions
echo "Setting storage permissions..."
chmod -R 775 storage bootstrap/cache
chown -R forge:forge storage bootstrap/cache

# Run database migrations (uncomment if needed)
# php artisan migrate --force

# Clear and cache config
echo "Clearing caches..."
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

echo "Optimizing application..."
php artisan config:cache
php artisan route:cache
php artisan optimize

# Reload PHP-FPM
echo "Reloading PHP-FPM..."
sudo service php8.4-fpm reload

echo "Deployment completed successfully!"
