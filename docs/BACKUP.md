# Backup and restore

## How it works

- A backup runs every night at 23:30 through the scheduler. See [Deployment](DEPLOYMENT.md) step 6; **without the scheduler, no automatic backups run**.
- **Settings › Backups** shows the history and has **BACK UP NOW** and **Download**.
- Files are compressed and encrypted with AES-256-GCM in `backend/storage/app/private/backups`, outside the public web root. Each run records a SHA-256 checksum.
- Files older than `BACKUP_RETENTION_DAYS` (default 30) are deleted after each successful backup.
- A failure is logged, shown on the Dashboard, and emailed to `MAIL_FROM_ADDRESS` when mail is configured.

## Settings in `.env`

| Key | Purpose |
| --- | --- |
| `BACKUP_DRIVER` | `auto` (default) uses `mysqldump` and `mysql` where the host allows them and PHP otherwise. `native` or `php` forces one. |
| `BACKUP_MYSQLDUMP`, `BACKUP_MYSQL` | Paths to the programs, if they are not on the PATH |
| `BACKUP_RETENTION_DAYS` | Days to keep files |
| `BACKUP_KEY` | Encryption key. Falls back to `APP_KEY` if empty. |

**Keep the key outside the server.** Without it, backups cannot be read. Also copy the backup files to another machine or cloud storage regularly: backups on the same disk do not survive a disk failure.

## Restore

Restoring **replaces the whole database**. Sales made after the backup are lost, so take a fresh backup first if the database is still readable.

```bash
cd backend
php artisan down
php artisan backup:run
php artisan backup:restore naturolls_20261101_233000.sql.gz.enc
php artisan inventory:reconcile
php artisan up
```

The file must be in the backups folder. The command checks its checksum when the file is listed in the backup history, and refuses a damaged file.

After restoring:

- The backup history goes back to its state at backup time. Files on disk are not affected.
- Sign-in sessions go back too, so staff may need to sign in again.
- Number counters also rewind. Devices may be holding offline orders from after the backup; these sync normally.

## New server

1. Install as in [Deployment](DEPLOYMENT.md), using the same `BACKUP_KEY` or `APP_KEY`.
2. Run `php artisan migrate --force` to create the database.
3. Copy the file into `storage/app/private/backups` and run `backup:restore`.
4. Copy `storage/app/public/products` from the old server if you have it. **Product photos are not in the database backup**, so back this folder up separately.

Practise a restore on a spare database before you need one.
