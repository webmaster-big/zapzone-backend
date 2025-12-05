#!/bin/bash
# Manual deployment commands for Laravel Forge
# SSH into your server and run these commands

cd /home/forge/zapzone-backend-1oulhaj4.on-forge.com

# Clear all caches
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

# Optimize
php artisan config:cache
php artisan route:cache
php artisan optimize

# Fix storage symlink (zero-downtime deployment fix)
# Remove old symlink if exists
rm -f current/public/storage
# Create new symlink with absolute path to shared storage
ln -nfs /home/forge/zapzone-backend-1oulhaj4.on-forge.com/storage/app/public \
        /home/forge/zapzone-backend-1oulhaj4.on-forge.com/current/public/storage

# Verify symlink
echo "Storage symlink status:"
ls -la current/public/storage

# Restart PHP-FPM
sudo service php8.2-fpm reload
# or if using different PHP version:
# sudo service php8.3-fpm reload
