#!/bin/bash
# Manual deployment commands for Laravel Forge
# SSH into your server and run these commands

cd /home/forge/zapzone-backend-1oulhaj4.on-forge.com

# Create storage symbolic link
# Remove old symlink if exists
if [ -L public/storage ]; then
    rm public/storage
fi

# Create new symlink
php artisan storage:link

# Ensure storage directories have correct permissions
chmod -R 775 storage bootstrap/cache
chown -R forge:forge storage bootstrap/cache

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
sudo service php8.4-fpm reload
