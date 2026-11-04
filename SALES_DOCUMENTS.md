# Invoices, factory demand and quotations

Open **Sales and POS → New invoice**, or use **New invoice** on the invoice list. Select a showroom branch and products. Walk-in customer is selected by default; choose a registered customer for advances. Quantities support three decimal places. Product prices default from the catalogue; click each invoice product to edit its unit price, description and discount. Fixed discounts apply to the whole line; percentage discounts apply to quantity × unit price. Warranty terms are copied from the product at sale time, so later master-data edits do not rewrite invoices.

- Standard order: available showroom stock is deducted immediately. For 50 bed sheets with 30 available, 30 are allocated and a linked manufacturing request is created for 20.
- An active product BOM and a single configured factory with raw-material and finished-goods warehouses allow automatic creation of a draft production order. Factory staff retain release/start approval; raw materials are consumed only on production start.
- Missing BOM, missing factory configuration or ambiguous factory selection: the request remains **Awaiting BOM approval**. No material recipe is invented.
- **Special BOM Request** applies separately to each product line. Factory instructions are mandatory for checked lines. Their showroom stock is untouched and their full quantity enters the approval queue. Other lines in the same invoice allocate stock normally. Factory instructions are internal and never appear on the customer PDF.
- Manufacturing → **Customer production requests** shows quantities, invoice references, notes and production links. Choose the approved product BOM and factory raw-material warehouse to create production. If a special colour requires different materials, create the appropriate BOM version before approving the request.
- Complete at least the full requested production quantity. Customer goods are allocated immediately against their invoice at the factory; excess production remains in factory stock. Allocation does not mean delivered. Use the existing delivery workflow for transport. Pending production costs use standard estimates and are replaced by actual production costs when completed.

## Payments

The invoice list and details show **Pending** whenever the balance is greater than zero, and **Completed** only at zero balance. This is independent of factory progress. Existing posted invoices use the same computed status, with no data migration. The payment-status filter follows this rule. Existing return records retain their separate underlying return state.

After BOM confirmation, each factory request is **Ready**. Use **Start** to approve/release it, **Process** to issue materials and begin production, then **Completed** to enter the actual output and allocate it to the customer invoice. These buttons are available on Customer production requests and Production orders to users with manufacturing edit permission. The same BOM recipe can serve many orders; progress belongs to each production order, not to the shared recipe. Invoice rows, invoice details and the separate factory PDF show each linked request's current progress. Customer PDFs do not show factory information. Use **Refresh status** to fetch updates made by another user.

Walk-in invoices require full payment; the amount is locked to the total in the editor and the server rejects partial payment, including when the old CRM walk-in record is submitted. Registered customers can enter an advance/payment amount, or zero for pay later. **Add customer** beside the selector opens the CRM registration form; codes are generated automatically. Cashiers must enter a credit limit when registering a new customer through that endpoint; 0 means no credit facility. Account status is always active and existing customer limit changes remain in CRM. Add a delivery charge and any number (up to 30) of named service charges; both are included in the invoice total. Methods: **Cash, Card, Bank transfer, Koko, Other payment**. These record receipts; they do not initiate gateway/card charges. Non-cash receipts use the existing bank-account mapping. Cash receipts update the cashier's open session when one exists.

Use **Invoices → Add payment** for further collections. The backend rejects overpayment and locks invoice balances during collection. Repeated submissions with the same operation key cannot double-post. Uncertain submissions must be confirmed using the original saved operation.

Order balances are maintained per invoice, separately from the legacy customer credit ledger and credit-limit POS workflow. Collect them through **Add payment**, not the legacy CRM credit collection form. Reports → **Outstanding invoice balances (all dates)** includes these balances and follows the selected branch. Legacy customer-credit reports remain separate.

Pending or unpaid manufacturing orders cannot use the existing returns workflow. That workflow becomes available after full allocation and payment. Cancelling a linked production order independently is blocked to avoid abandoning customer demand. Deposit refunds/order cancellations need a dedicated approval workflow and are not introduced by this update.

## Quotations and PDFs

**Sales and POS → New quotation** supports all active product types and categories, including out-of-stock products. Search by name/SKU, filter category and page through the entire catalogue. Configure each product's sale price in the product master, including raw materials that need quotation pricing. Quotations do not change stock, create production demand or record payments. They retain customer, product description, price, shop and currency snapshots, with validity date and terms. Quotation-to-invoice conversion is not automatic.

The saved-document dialog and invoice/quotation lists provide PDF downloads. Documents include shop name, branch contact details, customer details, line quantities/prices, totals, notes and validity/due date. Invoices additionally include allocations, pending production, payments, methods, references and remaining balance. **Administration → Settings** controls company name and currency; branch records provide address/phone. Historical document snapshots remain unchanged when master data changes.

## Daily closing PDF

In POS, choose **Close day**, enter the counted drawer cash and submit. The closing saves a report snapshot and automatically attempts its PDF download. If downloading fails, use the displayed retry button; the till stays closed. Saved reports are also available through **Sales and POS → Daily closing → Closing PDF**. Only the cashier who owns the session or a manager with `sales.approve` within the branch scope can download it.

The report includes cashier identity, branch, Sri Lanka calendar date, closing time, every invoice issued by that cashier that day (with product lines), the payment transaction register, returns processed, and separate Cash/Card/Bank/Koko/Other/Credit summaries. Advances, later invoice payments and CRM credit collections count as receipts. Refunds reduce collected totals. Credit allocations are shown separately and excluded from money collected. Only activity at the session's branch through the closing time is included.

Daily totals include all the cashier's sessions earlier that date. Cash drawer reconciliation is explicitly limited to the session being closed: opening cash, expected cash, counted cash and shortage/overage. For overnight sessions, this distinction prevents confusing a full-shift cash balance with one calendar day's receipts. Closing data is immutable; later transactions do not rewrite old reports. Sessions closed before this feature was installed have no saved closing report. No historical balances are reconstructed automatically.

Validation includes midnight boundaries, other-user exclusion, branch permissions, duplicate close requests, empty days, multiple sessions, cash refunds, CRM collections and snapshot persistence. Fictional sample PDF: `output/verification/sales-documents/daily-closing.pdf`.

## Deployment and verification

The local database migration was applied. For another installation, run `composer install` and `php artisan migrate --force` from `backend`, then `php artisan config:clear`. Reload the browser with Ctrl+Shift+R. No seed/reset is required and no demonstration orders were written into the live database.

Tests: `php vendor/bin/phpunit --configuration phpunit.xml` in `backend`; `npm test` in `frontend`. SalesDocumentsTest covers stock shortage, special orders, missing BOM, factory completion, atomic rejection, payments, idempotent retries, branch/permission enforcement, catalogue access and real PDF responses using an isolated SQLite database. PDF fixtures under `output/verification/sales-documents` contain fictional test data and were visually rendered and inspected. InventoryInvoiceOptionsTest additionally covers generated codes, opening stock, location filters, walk-in rejection, safe cashier registration, mixed special/normal lines, discounts, charges, warranty snapshots, customer/factory PDF separation, print packs and discounted returns.

## Inventory setup and printing (November 2026 update)

Inventory → Warranty manages reusable categories, duration in months and terms. Deactivate obsolete categories; existing invoice snapshots remain intact. Inventory → Products generates SKU codes on creation. The product editor has a Loyalty points tab for the per-unit product value, a warranty selector and a default location selector. Product loyalty values are stored with invoice lines; this update does not change the existing POS spend-based loyalty earning/redemption policy.

Authorized inventory approvers can enter opening quantity when creating a product. This posts an opening movement to the selected warehouse. Setting/changing a product's default location alone does not move stock. Supplier goods must use Purchasing → Purchase orders → Receive goods (GRN), which displays and posts to the purchase order's receiving warehouse. Transfers move existing stock. Products → Stock location filters actual positive stock balances, not just the default location. Location access continues to respect branch permissions; all-branch users can see all configured locations.

Create invoice opens a printable pack: the customer invoice first and, only when required, a separate factory copy on a new page. The browser still controls printer selection and final printing. If popups are blocked, use **Print copies** in saved invoice details. Independent customer/factory PDF buttons remain available for downloading and reprinting from the invoice; factory staff can also download factory PDFs from Customer production requests. PDFs are generated from saved invoice/request records when requested, rather than keeping public files. No factory copy is generated for fully stocked standard invoices.

For version-2 invoice returns, only the discounted goods amount is refunded; delivery/service fees are not automatically refunded as goods. Product profitability excludes those fees from product revenue. Existing version-1 invoices keep their previous pricing calculation.

Latest verification: backend 25 tests / 382 assertions passed; frontend 17 tests passed. Local migration 2026_11_04_000002 applied without reseeding. Browser login was verified; further browser page navigation was blocked by automatic approval review usage limits, so interactive form/physical-printer verification remains unconfirmed. Customer/factory PDFs were generated from isolated fixtures and rendered for layout inspection.

## Sri Lanka clock, payment dates and POS registration

The workspace header shows a live Asia/Colombo clock (UTC+05:30) and date. It synchronizes with the server every minute and on returning to the tab; if the server is unavailable it continues using the device clock with the last known correction. Browser timezone does not affect the displayed Sri Lanka time.

Invoices → Add payment defaults to today's Sri Lanka date and accepts a payment date between the invoice date and today. This date is saved separately from the immutable posting timestamp and displayed in the invoice payment history, customer PDF, finance payment list and new daily closing snapshots. Cash drawer updates and daily closing inclusion follow the actual posting time; selecting an earlier payment date never rewrites closed sessions. Older payments fall back to their posting date in Sri Lanka.

After payment, the refreshed invoice dialog shows the new paid amount, balance and PDF/print controls. POS → Customer → Add customer uses the same CRM registration dialog as invoices. The newly registered customer is selected while retaining the current cart and payment entries; the customer code is generated on the server.

## POS product / bill discounts and credit limits

Each cart product has a Discount button supporting fixed amounts (for the whole line) or percentages. Manual bill discount supports the same types and applies to the amount remaining after product discounts. A configured discount rule and manual bill discount are alternatives; choosing one clears the other. Loyalty redemption applies last. The backend rejects excessive discounts and payment totals that do not reconcile, and allows a fully discounted sale without a payment entry.

Checkout retains Cash, Card, Bank transfer, Koko and Other payment, with split payments. Registered customers also retain the existing Customer credit method and its server-enforced credit limit. POS and invoice customer selectors display the selected customer's configured credit limit. This display is a limit, not a remaining-credit calculation. Both registration popups require a nonnegative credit limit; it is saved to CRM with the generated customer code.

New POS invoices store product discounts, bill discount metadata and the allocated bill/loyalty share on each line. Net line totals sum exactly to the invoice total. Product reports and goods returns use these net amounts. Old invoices retain their previous pricing version.

## Owner-configurable loyalty earning rules

CRM and Loyalty → Loyalty earning rules is available to users with settings permissions (the owner/administrator). Add a name, minimum/optional maximum purchase, spending block amount, points per complete block, priority and active state. Example: every 1,000 earns 10 points; a net purchase of 2,500 earns 20 points. The base is the final invoice total after discounts and redemption, including delivery/services where present. Ranges are inclusive. Highest priority wins; ties use the highest minimum purchase and then the oldest rule. Rules never stack or apply tier multipliers.

Points are awarded once on a registered customer's purchase, including credit orders, in POS and New invoice. Later payments do not award again. Invoice snapshots keep the rule used; editing a rule affects new purchases only. Refunds reverse points cumulatively so several partial returns cannot leave rounding leftovers. Walk-in customers earn no points. Until the owner creates the first rule, the existing spending-setting/tier calculation remains in effect. Once rules exist, disabled or unmatched rules earn zero. Rules can be deactivated instead of deleted.

POS payment choices include a visible Credit option, disabled for walk-in customers. Select a registered customer to enable it. The backend enforces the customer's credit limit and keeps credit allocations separate from cash receipts. Receipts and invoice details show points earned; CRM customer profiles retain the loyalty ledger.
