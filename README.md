<<<<<<< HEAD
# Webpoint One - Manufacturing, Retail & Business Management
=======
# Furniture ERP - Manufacturing, Retail & Business Management
>>>>>>> a10abfe73d70d57bf1a513604d56226304c3e535

One system for the factory, five showrooms and head office: stock, recipes and production, product costing, POS, customers and loyalty, HR and payroll, finance, purchasing, delivery, marketing and reports.

```
erp/
  backend/    Laravel 12 REST API (PHP 8.2+, MySQL)  - all business rules
  frontend/   Modular JavaScript + local Tailwind CSS - responsive glass workspace
  database/   Database notes (migrations and seeders live in backend/database)
  docs/       Implementation notes and items to confirm
```

## Run it

For an existing installation, follow [UPGRADE.md](UPGRADE.md). Do not seed or recreate an existing business database. The new workspace requires the `2026_11_01_000007_create_workspace_tables` migration.

```bash
# 1. Backend
cd backend
composer install
cp .env.example .env && php artisan key:generate
# create an empty MySQL database named "erp" and set DB_* in .env
php artisan migrate --seed
php artisan serve                      # http://localhost:8000

# 2. Frontend (any static server)
cd ../frontend
npm ci
npm run build:css
python3 -m http.server 8080            # http://localhost:8080
```
Open http://localhost:8080 and sign in with `admin@erp.test` / `Password@123`.
Other demo users (same password): `manager1@`, `cashier1@`, `cashier2@`, `store@`, `factory@`, `hr@`, `accounts@` + `erp.test`.
If the API runs elsewhere, change **Server address** on the sign-in screen or edit `frontend/assets/js/config.js`.

## Updated workspace

Use the sun/moon button in the top bar (or on the sign-in page) to switch between light glass and midnight glass themes. The selection is remembered in this browser and synchronized across its tabs. Before a manual selection, the app follows the operating system's color preference. Chart reveals and pointer highlights respect reduced-motion settings. Third-party browser dark-mode extensions can override the app palette; exclude this site in those extensions to see its designed colors.

The application launcher exposes twelve permission-filtered modules, pinned/recent apps, contextual navigation, branch switching, record search, individual notifications, profile settings and reduced-motion/solid-surface support. Existing business workflows remain in Laravel. The frontend loads `src/main.js`; the old `assets/js/app.js` and `pages.js` are retained for reference and are not loaded.

Run `npm test` in `frontend` and `php vendor/phpunit/phpunit/phpunit --testsuite Feature` in `backend`. Backend tests use an isolated in-memory SQLite database. See [IMPLEMENTATION_STATUS.md](IMPLEMENTATION_STATUS.md) for architecture, verification and release boundaries.

## Try the end-to-end flow
Purchase order (approve, receive) -> production order (release, start, complete) -> transfer finished goods to a showroom -> sell on the POS -> see stock, credit, loyalty, commission, finance and profit reports update. Change all demo passwords before real use. See `docs/IMPLEMENTATION_NOTES.md`.
