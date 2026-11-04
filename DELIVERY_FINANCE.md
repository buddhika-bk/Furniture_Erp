# Delivery and finance integration

Invoice creation with a positive delivery charge creates one pending delivery, in the same database transaction, with the invoice's branch, fee and customer address. Missing addresses show “Address to be confirmed”. Delivery & Fleet → Deliveries shows the invoice number and delivery charge. Plan delivery assigns the destination, driver, vehicle and fuel cost before dispatch. Linked delivery charges come from the invoice, not the manual delivery form. Duplicate active deliveries for an invoice are rejected.

Run `php artisan erp:sync-invoice-deliveries` to create missing delivery records for historical charged invoices. This is repeatable and preserves existing delivery records, including their status. It does not change account balances. Historical deliveries require operational review before dispatch.

Finance → Payments displays invoice, customer, method (including Koko and Other), payment date, account, amount and recorded user. Credit payment records are visible but do not represent cash received. Cash & bank ledger shows actual receipts/payments, source IDs and linked payment details for newly recorded invoice, POS, refund and customer collection payments. Older payments remain visible in Payments; historical ledger rows retain their original source and description rather than guessing payment links.

Posting coverage:

- Invoice advance and subsequent balance payments, POS receipts and customer credit collections: cash/bank inflows.
- Customer refunds, supplier payments, approved expenses and finalized payroll: outflows through existing workflows.
- Delivery completion: the confirmed fuel amount posts once as a cash expense; if assigned, the vehicle also receives a fuel log. Repeated completion is rejected. Confirm the amount on Plan delivery before marking Delivered.
- New standalone fuel/maintenance and campaign spend entries: paid cash expenses, posted atomically. Posted entries cannot be edited. Do not duplicate these costs under Expenses or re-enter delivery fuel manually.
- Supplier payments now retain the originating purchase warehouse branch for scoped ledger visibility.

Delivery fees and invoice service charges are already part of the invoice total. They are not posted a second time as income. Unpaid invoices, purchase commitments, stock values and manufacturing costs are not cash movements; they remain in their respective source records. Historical standalone vehicle/campaign costs are not retrospectively debited: prior cash payments may already exist under Expenses and require reconciliation before any backfill.

Deployment: apply migrations before serving this version, then run the historical delivery sync command. Automated tests use an isolated SQLite database; no production sales/payments are created for testing.
