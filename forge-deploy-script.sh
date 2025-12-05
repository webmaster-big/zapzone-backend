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

# Fix storage symlink - CRITICAL for zero-downtime deployments
# Remove old symlink from new release
rm -f /home/forge/zapzone-backend-1oulhaj4.on-forge.com/current/public/storage
# Create symlink with absolute path to shared storage directory
ln -nfs /home/forge/zapzone-backend-1oulhaj4.on-forge.com/storage/app/public \
        /home/forge/zapzone-backend-1oulhaj4.on-forge.com/current/public/storage

$RESTART_QUEUES()
