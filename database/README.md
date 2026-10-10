# Database

The single authoritative MySQL database is defined by Laravel migrations and seeders (kept with the backend so they run with `php artisan`):

- `backend/database/migrations/` - six ordered migrations (foundation, master data, inventory and purchasing, manufacturing and costing, sales/CRM/loyalty, HR/payroll/finance/delivery/marketing)
- `backend/database/seeders/DatabaseSeeder.php` - roles, 1 head office + factory + 5 showrooms, warehouses, units, categories, products, a bed-sheet recipe, accounts, rules, opening stock and demo users

## Create it
```bash
mysql -u root -p -e "CREATE DATABASE erp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
cd backend && php artisan migrate --seed
```
To start again from scratch: `php artisan migrate:fresh --seed`.

## Tables
roles, branches, users, audit_logs, settings, sequences, alerts, jobs, failed_jobs, units, categories, products, warehouses, suppliers, customers, departments, positions, employees, vehicles, accounts, expense_categories, stock_balances, stock_movements, stock_transfers, stock_transfer_items, stock_adjustments, purchase_orders, purchase_order_items, goods_receipts, supplier_invoices, supplier_payments, boms, bom_items, production_orders, production_materials, product_costs, discount_rules, loyalty_tiers, loyalty_rewards, campaigns, cashier_sessions, invoices, invoice_items, payments, sales_returns, sales_return_items, loyalty_ledger, commission_rules, commissions, attendance, leaves, payroll_periods, payslips, expenses, financial_transactions, delivery_orders, vehicle_logs, campaign_expenses

## Integrity rules enforced in code
- Every stock change writes one row to `stock_movements` and updates `stock_balances` inside one database transaction with a row lock; stock cannot go negative.
- Invoices are never edited after completion; returns create `sales_returns` and reverse stock, payments, loyalty and commission.
- `invoice_items.unit_cost` stores the cost snapshot at the time of sale, so later cost changes do not alter historic profit.
- Financial postings reference their source document (`ref_type`, `ref_id`); payroll periods move Draft, Reviewed, Approved, Finalized.
- `audit_logs` keeps old and new values for changes to financial, stock, payroll, permission and master data.
