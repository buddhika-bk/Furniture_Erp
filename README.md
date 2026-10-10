# Furniture ERP - Manufacturing, Retail & Business Management

One system for the factory, five showrooms and head office: stock, recipes and production, product costing, POS, customers and loyalty, HR and payroll, finance, purchasing, delivery, marketing and reports.

```
erp/
  backend/    Laravel 11 REST API (PHP 8.2+, MySQL)  - all business rules
  frontend/   HTML5 + Tailwind CSS + JavaScript      - responsive gold and white web app
  database/   Database notes (migrations and seeders live in backend/database)
  docs/       Implementation notes and items to confirm
```

## Run it
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
python3 -m http.server 8080            # http://localhost:8080
```
Open http://localhost:8080 and sign in with `admin@erp.test` / `Password@123`.
Other demo users (same password): `manager1@`, `cashier1@`, `cashier2@`, `store@`, `factory@`, `hr@`, `accounts@` + `erp.test`.
If the API runs elsewhere, change **Server address** on the sign-in screen or edit `frontend/assets/js/config.js`.

## Try the end-to-end flow
Purchase order (approve, receive) -> production order (release, start, complete) -> transfer finished goods to a showroom -> sell on the POS -> see stock, credit, loyalty, commission, finance and profit reports update. Change all demo passwords before real use. See `docs/IMPLEMENTATION_NOTES.md`.
