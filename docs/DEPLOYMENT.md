# Deployment

This guide is for a server you control. For cPanel-style hosting, follow [Shared hosting](SHARED_HOSTING.md).

The API and the app must share one HTTPS domain. PWA installation, service workers, Bluetooth printing and USB printing all require HTTPS.

## Requirements

- PHP 8.2+ with bcmath, curl, gd, mbstring, openssl, pdo_mysql, xml and zip
- MySQL 8.0+ with InnoDB and utf8mb4. Development and the automated tests ran on MariaDB 10.4, so run the test suite once on your MySQL 8 server before going live.
- Composer, Node 22+, nginx and PHP-FPM
- `mysql` and `mysqldump` client programs for backups

## Steps

1. Create the database and a dedicated user:

   ```sql
   CREATE DATABASE naturolls CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'naturolls'@'localhost' IDENTIFIED BY 'a-strong-password';
   GRANT ALL ON naturolls.* TO 'naturolls'@'localhost';
   ```

2. Install the backend:

   ```bash
   cd /var/www/naturolls/backend
   composer install --no-dev --optimize-autoloader
   cp .env.example .env
   php artisan key:generate
   ```

   In `.env` set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, the `DB_*` values, and a long random `PAYMENT_CALLBACK_SECRET`. Set `BACKUP_KEY` too, and store it somewhere safe outside the server.

3. Create the tables. Run `--seed` once for roles, permissions and starter data, then change the demo passwords and PIN in **Settings › Staff** straight away and replace the demo products, costs, GST rates and stock.

   ```bash
   php artisan migrate --seed --force
   php artisan storage:link
   php artisan config:cache
   php artisan route:cache
   ```

4. Build the app:

   ```bash
   cd ../frontend
   npm ci
   npm run build
   ```

5. Configure nginx:

   ```nginx
   server {
       listen 443 ssl;
       server_name pos.example.com;
       root /var/www/naturolls/frontend/dist;
       index index.html;
       client_max_body_size 8m;

       location / { try_files $uri /index.html; }
       location = /sw.js { add_header Cache-Control "no-cache"; }

       location /storage/ { alias /var/www/naturolls/backend/storage/app/public/; }

       location /api/ {
           include fastcgi_params;
           fastcgi_param SCRIPT_FILENAME /var/www/naturolls/backend/public/index.php;
           fastcgi_pass unix:/run/php/php8.2-fpm.sock;
       }
   }
   ```

6. Add the scheduler, which runs nightly backups and stock reconciliation:

   ```cron
   * * * * * cd /var/www/naturolls/backend && php artisan schedule:run >> /dev/null 2>&1
   ```

   On Windows, use Task Scheduler to run `php artisan schedule:run` every minute.

7. Give the web server user write access to `backend/storage` and `backend/bootstrap/cache`.

## Install on Android

1. Open the site in Chrome on the device.
2. Sign in once while online.
3. Tap the menu, then **Add to Home screen** or **Install app**.
4. Open Naturolls from the home screen. It runs full screen and works offline for order entry.

Each device gets a permanent terminal code at its first sign-in, which keeps its offline order numbers unique. Clearing the browser's site data deletes any orders still waiting to sync, so check the **Offline** bar shows nothing waiting first.

## Updating

```bash
php artisan down
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan up
```

Rebuild the frontend with `npm ci && npm run build`. Installed devices update themselves on their next visit.

## Payment provider callback

`POST /api/v1/payments/callback` accepts confirmations from a UPI or card provider, signed as described in the [API reference](API.md). It refuses all calls until `PAYMENT_CALLBACK_SECRET` is changed from its default. Most providers need a small adapter to map their format to this one, because no specific provider has been integrated yet.
