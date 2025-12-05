#!/bin/bash
# Migrate existing images from public/images to storage/app/public/images
# Run this ONCE after deploying the new code

echo "======================================"
echo "Image Migration Script"
echo "Moving images from public to storage"
echo "======================================"
echo ""

cd /home/forge/zapzone-backend-1oulhaj4.on-forge.com

# Create storage directories
echo "Creating storage directories..."
mkdir -p storage/app/public/images/attractions
mkdir -p storage/app/public/images/packages
mkdir -p storage/app/public/images/addons
chmod -R 775 storage/app/public/images
chown -R forge:forge storage/app/public/images
echo "✓ Directories created"
echo ""

# Move attractions images
if [ -d "current/public/images/attractions" ] && [ "$(ls -A current/public/images/attractions 2>/dev/null)" ]; then
    echo "Migrating attractions images..."
    cp -r current/public/images/attractions/* storage/app/public/images/attractions/ 2>/dev/null || true
    COUNT=$(ls -1 storage/app/public/images/attractions 2>/dev/null | wc -l)
    echo "✓ Migrated $COUNT attraction images"
else
    echo "No attraction images to migrate"
fi
echo ""

# Move packages images
if [ -d "current/public/images/packages" ] && [ "$(ls -A current/public/images/packages 2>/dev/null)" ]; then
    echo "Migrating packages images..."
    cp -r current/public/images/packages/* storage/app/public/images/packages/ 2>/dev/null || true
    COUNT=$(ls -1 storage/app/public/images/packages 2>/dev/null | wc -l)
    echo "✓ Migrated $COUNT package images"
else
    echo "No package images to migrate"
fi
echo ""

# Move addons images
if [ -d "current/public/images/addons" ] && [ "$(ls -A current/public/images/addons 2>/dev/null)" ]; then
    echo "Migrating addons images..."
    cp -r current/public/images/addons/* storage/app/public/images/addons/ 2>/dev/null || true
    COUNT=$(ls -1 storage/app/public/images/addons 2>/dev/null | wc -l)
    echo "✓ Migrated $COUNT addon images"
else
    echo "No addon images to migrate"
fi
echo ""

# Set permissions
echo "Setting permissions..."
chmod -R 775 storage/app/public/images
chown -R forge:forge storage/app/public/images
echo "✓ Permissions set"
echo ""

# Verify storage symlink
echo "Verifying storage symlink..."
if [ -L "current/public/storage" ]; then
    TARGET=$(readlink current/public/storage)
    echo "✓ Storage symlink exists: $TARGET"
else
    echo "⚠ WARNING: Storage symlink does not exist!"
    echo "Run: cd current/public && ln -sfn /home/forge/zapzone-backend-1oulhaj4.on-forge.com/storage/app/public storage"
fi
echo ""

echo "======================================"
echo "Migration Complete!"
echo "======================================"
echo ""
echo "Images are now accessible via:"
echo "https://zapzone-backend-1oulhaj4.on-forge.com/storage/images/attractions/"
echo "https://zapzone-backend-1oulhaj4.on-forge.com/storage/images/packages/"
echo "https://zapzone-backend-1oulhaj4.on-forge.com/storage/images/addons/"
echo ""
echo "You can safely remove old images later with:"
echo "rm -rf current/public/images"
echo ""
