#!/bin/bash
# Manual deployment commands for Laravel Forge
# SSH into your server and run these commands

cd /home/forge/zapzone-backend-1oulhaj4.on-forge.com/current/public

# Fix storage symlink for zero-downtime deployments
# Remove old/incorrect symlink if exists
[ -L storage ] && rm storage

# Create proper symlink to shared storage (use absolute path)
ln -sfn /home/forge/zapzone-backend-1oulhaj4.on-forge.com/storage/app/public storage

# Verify the symlink
ls -la storage

# Go back to site root
cd /home/forge/zapzone-backend-1oulhaj4.on-forge.com

# Set proper permissions
chmod -R 775 storage
chmod -R 775 current/bootstrap/cache
chown -R forge:forge storage
chown -R forge:forge current/bootstrap/cache

# Clear all caches
cd current
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
