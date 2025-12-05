#!/bin/bash
# Manual deployment commands for Laravel Forge
# SSH into your server and run these commands

cd /home/forge/zapzone-backend-1oulhaj4.on-forge.com

# Fix storage symlink for zero-downtime deployments
# Remove old symlink if exists
if [ -L public/storage ]; then
    rm public/storage
fi

# Create proper symlink to shared storage
ln -sfn /home/forge/zapzone-backend-1oulhaj4.on-forge.com/storage/app/public /home/forge/zapzone-backend-1oulhaj4.on-forge.com/current/public/storage

# Set proper permissions
chmod -R 775 storage
chmod -R 775 bootstrap/cache
chown -R forge:forge storage
chown -R forge:forge bootstrap/cache

# Clear all caches
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

# Optimize
php artisan config:cache
php artisan route:cache
php artisan optimize

# Restart PHP-FPM
sudo service php8.2-fpm reload
# or if using different PHP version:
# sudo service php8.3-fpm reload
