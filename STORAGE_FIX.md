# Laravel Forge Deployment Fix for Storage Access

## Problem
The `/storage/` URL returns 403 Forbidden error because the symbolic link is not properly configured in production.

## Solution

### Step 1: SSH into your Laravel Forge server
```bash
ssh forge@your-server-ip
```

### Step 2: Navigate to your site directory
```bash
cd /home/forge/zapzone-backend-1oulhaj4.on-forge.com
```

### Step 3: Remove old storage symlink (if exists)
```bash
if [ -L public/storage ]; then rm public/storage; fi
```

### Step 4: Create storage symbolic link
```bash
php artisan storage:link
```

### Step 5: Set proper permissions
```bash
chmod -R 775 storage bootstrap/cache
chown -R forge:forge storage bootstrap/cache
```

### Step 6: Verify the symlink
```bash
ls -la public/storage
```
You should see: `public/storage -> /home/forge/zapzone-backend-1oulhaj4.on-forge.com/storage/app/public`

### Step 7: Test access
Visit: https://zapzone-backend-1oulhaj4.on-forge.com/storage/

## Automated Deployment (Optional)

To make this automatic on every deployment, update your Laravel Forge deployment script:

1. Go to your Laravel Forge dashboard
2. Navigate to your site
3. Click on "Deployments" tab
4. Replace the deployment script with the contents of `.forge-deploy.sh`

## Alternative: Nginx Configuration

If the symlink exists but still getting 403, add this to your Nginx configuration:

```nginx
location /storage/ {
    alias /home/forge/zapzone-backend-1oulhaj4.on-forge.com/storage/app/public/;
    autoindex off;
}
```

Then reload Nginx:
```bash
sudo nginx -t
sudo service nginx reload
```

## Troubleshooting

### Check if symlink exists:
```bash
ls -la public/storage
```

### Check storage permissions:
```bash
ls -la storage/app/public
```

### Check Nginx error logs:
```bash
sudo tail -f /var/log/nginx/2954793-error.log
```

### Verify file exists:
```bash
# Test with an actual file
ls storage/app/public/
```
