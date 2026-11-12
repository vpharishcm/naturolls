# Deploying to shared hosting

These steps are for cPanel-style hosting. Other control panels have the same parts under different names. The package was tested on Apache with PHP forbidden from running other programs or creating symlinks, as many hosts do.

## Check your plan first

| Needed | Why |
| --- | --- |
| PHP 8.2 or newer | Choose it in **MultiPHP Manager** or **Select PHP Version**. |
| PHP extensions: bcmath, ctype, curl, dom, fileinfo, gd, mbstring, openssl, pdo_mysql, tokenizer, xml, zip | Most are on by default. Turn on any missing ones in **Select PHP Version**. |
| MySQL 8 or MariaDB 10.4+ | Development and testing used MariaDB 10.4. Test MySQL 8 with a few sales first if your host uses it. |
| Free SSL certificate | The app cannot install, print by Bluetooth or USB, or work offline without HTTPS. |
| A domain or subdomain of its own | For example `pos.example.com`. A folder such as `example.com/pos` does not work. |
| Cron jobs | For nightly backups. |

## 1. Build the package on your computer

With the local database running, and using your real address:

```bash
powershell -ExecutionPolicy Bypass -File deploy\build-hosting-package.ps1 -Url https://pos.example.com
```

It creates `dist-hosting\`:

| File | What it is |
| --- | --- |
| `naturolls_app.zip` | The server code. Goes **outside** the web folder. |
| `public_html.zip` | The app. Goes **inside** the web folder. |
| `database-start.sql` | Tables, your items and categories, zero stock, no demo customer or offer. |
| `FIRST_LOGIN.txt` | New random owner and staff logins and the backup key. **Do not upload this.** Store it somewhere safe. |

## 2. Create the database

In **MySQL Databases**, create a database and a user with a strong password, and add the user to the database with all privileges. Note the three names. Most hosts add your account name as a prefix.

In **phpMyAdmin**, choose the database, open **Import**, and import `database-start.sql`.

## 3. Upload the files

In **File Manager**, your home folder should end up like this:

```
/home/youraccount/
├── naturolls_app/     ← extract naturolls_app.zip here (it makes this folder itself)
└── public_html/       ← extract public_html.zip inside your domain's web folder
```

Make sure the hidden file `.htaccess` is in the web folder. Turn on **Show Hidden Files** to see it.

**If your subdomain's web folder is inside `public_html`** (for example `public_html/pos`), keep `naturolls_app` in your home folder and change one line in the web folder's `index.php`:

```php
$base = __DIR__.'/../../naturolls_app';
```

## 4. Fill in the settings

Edit `naturolls_app/.env` and replace the three `CHANGE_ME` values with your database name, user and password. Most hosts keep `DB_HOST=localhost`. Do not change the other values, and keep the password in its quotes.

If folder permissions need setting, give `naturolls_app/storage` and `naturolls_app/bootstrap/cache` permission 755, and their contents too.

## 5. Check it works

1. Open `https://your-address/api/v1/auth/me`. You should see `Please sign in again.` A blank or error page means something is wrong; see Troubleshooting.
2. Open `https://your-address`, and sign in using `FIRST_LOGIN.txt`.
3. Open **Settings › Business** and set your business name, address and receipt footer.
4. Check prices in **Products**, enter stock in **Daily Production** or **Inventory**, then make one test sale and print it.
5. Open **Settings › Backups** and press **BACK UP NOW**. It should succeed.

## 6. Set up cron jobs

In **Cron Jobs**, add these three, using your own home folder. Find your PHP path with your host; common ones are `/usr/local/bin/php` and `/opt/cpanel/ea-php82/root/usr/bin/php`.

| Time (minute hour * * *) | Command | Job |
| --- | --- | --- |
| `30 23 * * *` | `/usr/local/bin/php /home/youraccount/naturolls_app/artisan backup:run` | Backup |
| `45 23 * * *` | `/usr/local/bin/php /home/youraccount/naturolls_app/artisan inventory:reconcile --repair` | Stock check |
| `0 3 * * *` | `/usr/local/bin/php /home/youraccount/naturolls_app/artisan idempotency:prune` | Tidy up |

These run on the server's clock, which may not be India time. Check the time shown on the Cron Jobs page and adjust. These separate jobs replace `schedule:run`, which needs a permission many hosts block.

Next day, confirm a new backup appears in **Settings › Backups**.

## 7. Set up the devices

Open the address in Chrome on each device, sign in once while online, then use **Add to Home screen** or **Install app**. Create real staff logins in **Settings › Staff**.

## What is different online

- **Printing:** the **Network** printer option does not work, because a server on the internet cannot reach a printer in your shop. Use **System print**, **Bluetooth** or **USB**.
- **Internet:** billing and live stock need a connection. New orders can still be taken offline and sync later. If the internet is unreliable at your counter, plan for this, because invoices cannot be issued while offline.
- **Backups:** files stay on the same server until you copy them. Download one regularly from **Settings › Backups**. Also back up `naturolls_app/storage/app/public`, which holds product photos.
- **Time limits:** a shared server can be slower than your computer, and hosts limit resources. Very large PDF reports may hit a limit; Excel and CSV are lighter.

## Restoring a backup

If your plan has **Terminal** or SSH:

```bash
cd ~/naturolls_app
php artisan backup:restore naturolls_20261101_233000.sql.gz.enc --force
php artisan inventory:reconcile
```

Without either, add a cron job with that restore command set to run once in the next few minutes, wait for it, then **delete the cron job straight away**, or it will keep replacing your data.

Restoring replaces the whole database. See the [backup guide](BACKUP.md).

## Updating later

1. Build a new package with the same address.
2. In `naturolls_app`, replace everything **except** `.env` and `storage`. Keep those two, or you will lose your keys, photos and backups.
3. Replace the web folder's contents with `public_html.zip`. Change the `$base` line again if you edited it.
4. Run `php artisan migrate --force` by Terminal, SSH or a one-time cron job. **Do not import `database-start.sql` again**: it would erase your data.

Take and download a backup first. Ignore the new `FIRST_LOGIN.txt`, because existing logins stay the same.

## Troubleshooting

| What you see | Likely cause |
| --- | --- |
| HTTP 500 or a blank page | Wrong PHP version, or the `$base` path. Check `naturolls_app/storage/logs` and your host's **Errors** page. |
| "Something went wrong on the server" | Check the newest file in `naturolls_app/storage/logs`. Database login details are the most common cause. |
| Signed out straight after signing in | The `.htaccess` file is missing from the web folder. |
| Screens work until you refresh, then 404 | The `.htaccess` file is missing, or your host has rewrites turned off. |
| Too many redirects | Your host handles HTTPS in its own way. Remove the three `RewriteCond` lines and the `RewriteRule` under "Send everyone to HTTPS" in `.htaccess`, and use the host's **Force HTTPS** option instead. |
| Product photos do not show | `APP_URL` in `.env` must match your address exactly. |
| Backup failed | Open **Settings › Backups** for the message. Usually `storage` is not writable. |
