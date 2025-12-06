#!/bin/bash
# Manual storage symlink fix for Laravel Forge
# Run this script via SSH to fix the storage symlink immediately

echo "======================================"
echo "Laravel Forge Storage Symlink Fix"
echo "======================================"
echo ""

# Navigate to the site directory
cd /home/forge/zapzone-backend-1oulhaj4.on-forge.com

echo "Current directory: $(pwd)"
echo ""

# Show current symlink status
echo "Current public/storage symlink:"
ls -la current/public/storage 2>/dev/null || echo "Symlink does not exist"
echo ""

# Navigate to public directory
cd current/public

# Remove old/incorrect symlink
echo "Removing old symlink..."
if [ -L storage ]; then
    rm storage
    echo "✓ Old symlink removed"
else
    echo "No symlink to remove"
fi
echo ""

# Create correct symlink
echo "Creating new symlink..."
ln -sfn /home/forge/zapzone-backend-1oulhaj4.on-forge.com/storage/app/public storage
echo "✓ New symlink created"
echo ""

# Verify the symlink
echo "New symlink status:"
ls -la storage
echo ""

# Check if target directory exists
echo "Target directory check:"
ls -la /home/forge/zapzone-backend-1oulhaj4.on-forge.com/storage/app/public 2>/dev/null || echo "⚠ Target directory does not exist!"
echo ""

# Go back to site root
cd /home/forge/zapzone-backend-1oulhaj4.on-forge.com

# Set proper permissions
echo "Setting permissions..."
chmod -R 775 storage
chmod -R 775 current/bootstrap/cache
chown -R forge:forge storage
chown -R forge:forge current/bootstrap/cache
echo "✓ Permissions set"
echo ""

# Create storage/app/public directory if it doesn't exist
if [ ! -d "storage/app/public" ]; then
    echo "Creating storage/app/public directory..."
    mkdir -p storage/app/public
    chmod 775 storage/app/public
    chown forge:forge storage/app/public
    echo "✓ Directory created"
else
    echo "✓ storage/app/public directory exists"
fi
echo ""

# Clear caches
cd current
echo "Clearing caches..."
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
echo "✓ Caches cleared"
echo ""

# Optimize
echo "Optimizing..."
php artisan config:cache
php artisan route:cache
php artisan optimize
echo "✓ Optimization complete"
echo ""

# Restart PHP-FPM
echo "Restarting PHP-FPM..."
sudo service php8.4-fpm reload || sudo service php8.3-fpm reload || sudo service php8.2-fpm reload
echo "✓ PHP-FPM restarted"
echo ""

echo "======================================"
echo "Storage symlink fix complete!"
echo "======================================"
echo ""
echo "Test the storage URL:"
echo "https://zapzone-backend-1oulhaj4.on-forge.com/storage/"
echo ""
