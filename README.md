# Naturolls POS

Point of sale for Naturolls: orders, live stock, GST billing, thermal printing, daily popsicle production, reports, loyalty, offers and offline order entry, in one app.

- `backend/`: Laravel 12 REST API (`/api/v1`), MySQL
- `frontend/`: React, TypeScript and Tailwind installable PWA, built from the Naturolls design

## Run it locally

Needs PHP 8.2+, Composer, Node 22+ and MySQL 8 (or MariaDB 10.4+).

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
php artisan serve --host=127.0.0.1 --port=8000
```

In a second terminal:

```bash
cd frontend
npm install
npm run dev
```

Open http://127.0.0.1:5173. Demo logins are `admin` / `admin123` (owner PIN 1234) and `staff` / `staff123`. **Change these before going live.**

## Tests

The backend tests use a database named `naturolls_test`, which must exist. Tests wipe it.

```bash
cd backend && php artisan test
```

```bash
cd frontend && npx vitest run
```

## Documentation

| Guide | For |
| --- | --- |
| [User guide](docs/USER_GUIDE.md) | Counter staff and owner |
| [Deployment](docs/DEPLOYMENT.md) | Server setup, PWA installation |
| [Shared hosting](docs/SHARED_HOSTING.md) | cPanel-style hosting: building the package, upload, cron, updates |
| [Automatic deployment](docs/AUTOMATED_DEPLOY.md) | GitHub setup: what is pushed, tests and deploy on every push |
| [Printer setup](docs/PRINTER.md) | Connecting the thermal printer |
| [Backup and restore](docs/BACKUP.md) | Backups and recovery |
| [API reference](docs/API.md) | Developers |
| [Architecture and database](docs/ARCHITECTURE.md) | Developers |
