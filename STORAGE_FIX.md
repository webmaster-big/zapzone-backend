# Laravel Forge Storage Fix

## Problem
When using Laravel Forge's zero-downtime deployments, the `storage:link` command creates symlinks inside each release directory, but these symlinks break because they point to the wrong location.

## Solution
The `.forge-deploy.sh` script now:

1. **Removes `artisan storage:link`** from the deployment script (it's not needed)
2. **Creates the proper symlink** after `$ACTIVATE_RELEASE()` runs
3. **Points to shared storage** at `$FORGE_SITE_PATH/storage/app/public`

## Forge Deployment Structure
```
/home/forge/zapzone-backend-1oulhaj4.on-forge.com/
├── current -> releases/XXXXXX (symlink managed by Forge)
├── releases/
│   ├── XXXXXX/
│   └── YYYYYY/
└── storage/
    └── app/
        └── public/
```

## What the Script Does
After deployment activates the new release:
```bash
# Remove old symlink from release directory
rm $FORGE_SITE_PATH/current/public/storage

# Create proper symlink to shared storage
ln -sfn $FORGE_SITE_PATH/storage/app/public $FORGE_SITE_PATH/current/public/storage
```

## Manual Fix (Run this NOW via SSH)
SSH into your server and run these commands:

```bash
cd /home/forge/zapzone-backend-1oulhaj4.on-forge.com/current/public

# Remove incorrect symlink
[ -L storage ] && rm storage

# Create correct symlink to shared storage
ln -sfn /home/forge/zapzone-backend-1oulhaj4.on-forge.com/storage/app/public storage

# Verify it's correct
ls -la storage
# Should show: storage -> /home/forge/zapzone-backend-1oulhaj4.on-forge.com/storage/app/public

# Set permissions
cd /home/forge/zapzone-backend-1oulhaj4.on-forge.com
chmod -R 775 storage
```

## Verification
After deployment, check:
```bash
ls -la /home/forge/zapzone-backend-1oulhaj4.on-forge.com/current/public/storage
```

Should show:
```
storage -> /home/forge/zapzone-backend-1oulhaj4.on-forge.com/storage/app/public
```

## Update Forge Deployment Script
Copy the contents of `.forge-deploy.sh` to your Laravel Forge deployment script via the Forge dashboard.
