# Storage Symlink Fix for Laravel Forge Zero-Downtime Deployments

## Problem
After deployments, `/storage` URL returns 403 Forbidden because the symlink breaks when switching between release directories.

## Root Cause
Laravel Forge's zero-downtime deployment structure:
```
/home/forge/zapzone-backend-1oulhaj4.on-forge.com/
├── current -> releases/20251206123456/
├── releases/
│   ├── 20251206123456/
│   │   └── public/storage -> ../../storage/app/public  (WRONG - breaks on release switch)
│   └── 20251205120000/
└── storage/  (shared across all releases)
    └── app/
        └── public/
```

The `storage:link` command creates a symlink inside each release's `public/` directory, but it points to a relative path that becomes invalid after `$ACTIVATE_RELEASE()` switches the `current` symlink.

## Solution

### Step 1: SSH into your Forge server
```bash
ssh forge@your-server-ip
cd /home/forge/zapzone-backend-1oulhaj4.on-forge.com
```

### Step 2: Remove the broken symlink from the current release
```bash
rm -f current/public/storage
```

### Step 3: Create a permanent symlink in the shared storage directory
```bash
# The symlink should be in the PERSISTENT location (outside releases)
# Laravel Forge automatically shares the storage/ directory across releases
ln -nfs /home/forge/zapzone-backend-1oulhaj4.on-forge.com/storage/app/public \
        /home/forge/zapzone-backend-1oulhaj4.on-forge.com/current/public/storage
```

### Step 4: Update deployment script to recreate symlink on each deploy

**IMPORTANT:** Remove `$FORGE_PHP artisan storage:link` from the deployment script.

Instead, add this AFTER `$ACTIVATE_RELEASE()`:

```bash
# Recreate storage symlink for the new release
ln -nfs /home/forge/zapzone-backend-1oulhaj4.on-forge.com/storage/app/public \
        /home/forge/zapzone-backend-1oulhaj4.on-forge.com/current/public/storage
```

### Updated Deployment Script
```bash
$CREATE_RELEASE()

cd $FORGE_RELEASE_DIRECTORY

$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader
$FORGE_PHP artisan optimize
$FORGE_PHP artisan migrate --force
$FORGE_PHP artisan config:clear
$FORGE_PHP artisan cache:clear
$FORGE_PHP artisan route:clear
$FORGE_PHP artisan view:clear

npm ci || npm install && npm run build

$ACTIVATE_RELEASE()

# Fix storage symlink - MUST be after $ACTIVATE_RELEASE()
ln -nfs /home/forge/zapzone-backend-1oulhaj4.on-forge.com/storage/app/public \
        /home/forge/zapzone-backend-1oulhaj4.on-forge.com/current/public/storage

$RESTART_QUEUES()
```

### Step 5: Verify the fix
```bash
# Check if symlink exists and points to correct location
ls -la /home/forge/zapzone-backend-1oulhaj4.on-forge.com/current/public/storage

# Expected output:
# lrwxrwxrwx 1 forge forge 71 Dec  6 12:34 storage -> /home/forge/zapzone-backend-1oulhaj4.on-forge.com/storage/app/public
```

### Step 6: Test in browser
Visit: `https://zapzone-backend-1oulhaj4.on-forge.com/storage/`

You should see a directory listing or proper file access (not 403).

## Why This Works
1. The `storage/` directory is shared across all releases (Forge does this automatically)
2. The symlink points to an **absolute path** to the shared storage, not a relative path
3. The symlink is recreated AFTER `$ACTIVATE_RELEASE()` so it always points to the correct location in the `current/` release
4. The `-nfs` flags ensure the symlink is force-created even if it exists

## Alternative: One-Time Manual Fix
If you don't want to modify the deployment script, you can create the symlink manually after each deployment:

```bash
ssh forge@your-server
cd /home/forge/zapzone-backend-1oulhaj4.on-forge.com
rm -f current/public/storage
ln -nfs $PWD/storage/app/public $PWD/current/public/storage
```

But it's better to add it to the deployment script so it happens automatically.
