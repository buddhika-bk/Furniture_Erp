# Upgrade an existing Webpoint One installation

## Prepare

Back up the MySQL database, uploaded files, deployed code and environment configuration. Verify that the database backup can be restored in staging. Preserve the existing APP_KEY and database settings. Do not run `migrate:fresh`, `db:wipe`, demo seeders or `key:generate` on an existing installation.

Requirements: PHP 8.2 or newer with the application's database extensions, Composer, Node.js/npm for CSS compilation, and MySQL. Use a patched PHP runtime for production. Laravel is now pinned through composer.lock to the tested 12.x dependency set.

## Build and verify in staging

Run from the repository root in PowerShell:

```powershell
Push-Location frontend
npm.cmd ci
npm.cmd test
npm.cmd run build:css
Pop-Location
Push-Location backend
composer install --no-interaction
php vendor/phpunit/phpunit/phpunit --testsuite Feature
php artisan migrate --pretend
php artisan migrate
Pop-Location
```

Check every command's exit status before continuing. The new migration adds `users.ui_preferences`, `notification_reads` and `api_operations`; it does not replace business tables. PHPUnit uses a separate in-memory database, not the configured MySQL database. Staging migration and production MySQL behavior must also be checked.

## Start locally

In one terminal, run `php artisan serve --host=127.0.0.1 --port=8000` from `backend`. In another, run `php -S 127.0.0.1:8080` from `frontend`. Open http://127.0.0.1:8080. Configure CORS_ALLOWED_ORIGINS to include that exact origin, and use the existing ERP credentials. The connection settings on login can select another API address.

## Production rollout

1. Schedule a maintenance window and deploy the tested code and lockfiles together. Serve only `backend/public` as the API document root; serve `frontend` as the static app root. Require HTTPS.
2. Install locked backend packages using `composer install --no-dev --prefer-dist --optimize-autoloader`. Build CSS before uploading frontend files. No public CDN is required.
3. Run `php artisan down`, then `php artisan migrate --force`. Set APP_ENV=production, APP_DEBUG=false, and exact trusted CORS origins. Keep secrets out of the static frontend.
4. Run `php artisan config:cache`, restart PHP workers and queue workers if deployed, and run `php artisan up` after smoke checks. Keep the existing scheduler for ERP alerts.
5. Verify login, allowed/forbidden branch selection, product editing, production completion, stock transfer, POS sale/retry/return, payroll and reports using approved test records in staging first. Reconcile stock and finance totals before opening to all users.

## Rollback

Deploy the previous application release if smoke checks fail; keep the additive workspace tables unless their removal has been explicitly assessed. Do not automatically roll back migrations after new transactions have been posted. Restore a database backup only through a controlled recovery procedure that accounts for transactions since the backup. Keep the old `/api` contract available while clients move to `/api/v1`.

## Report definitions

Stock, production, supplier payables, payroll transactions and approvals respect the selected branch. Customer credit balances, campaign expenditure and vehicle logs use existing organization-wide data models. Their combined reports require All branches; a branch dashboard displays Organization only for customer credit. Product revenue allocates invoice-level discounts proportionally. Profit reports are management reports, not a certified statutory accounting ledger, and cross-period returns need accountant review.
