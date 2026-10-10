# Integrated ERP – Backend (Laravel 11 / MySQL)

Modular monolith: controllers → validation/authorization → domain services → Eloquent → MySQL.
All stock, costing, sales, payroll and finance rules live in `app/Services` (never in the UI).

## Setup
```bash
composer install
cp .env.example .env && php artisan key:generate
# create the MySQL database/user from .env, then:
php artisan migrate --seed          # SEED_DEMO=false in .env to skip demo data
php artisan serve                   # http://localhost:8000  (API under /api)
php artisan queue:work              # background jobs
php artisan test                    # PHPUnit (SQLite in memory)
```
Scheduler (alerts): `* * * * * php /path/to/artisan schedule:run`.

Default admin: `admin@example.com` / `ChangeMe!2026` (override with ADMIN_EMAIL / ADMIN_PASSWORD before seeding; change immediately).
Demo cashier (Showroom 1): `cashier@example.com` / `ChangeMe!2026`.

## Service map
| Service | Responsibility |
|---|---|
| InventoryService | Atomic, row-locked stock ledger, weighted-average cost, transfers, adjustments, counts |
| CostingService | BOM cost: material (+wastage) + labour + packaging + overhead + extra; cost versions |
| ManufacturingService | Production orders, controlled status flow, material issue, completion, batches |
| PurchasingService | PO approval, goods receipt → stock + supplier invoice, supplier payments (FIFO) |
| SalesService | POS checkout, discounts, tiers, credit limits, loyalty, returns, cashier sessions |
| LoyaltyService / CommissionService | Points earn/redeem/reverse; commission rule (highest applicable) |
| PayrollService | Draft → Reviewed → Approved → Finalized with OT, allowances, deductions, commission |
| FinanceService / ProfitabilityService / ReportService | Postings referencing source documents, P&L, product & branch profitability, reports |

See `../docs/IMPLEMENTATION_NOTES.md` for business rules, assumptions and the list of items to confirm.
