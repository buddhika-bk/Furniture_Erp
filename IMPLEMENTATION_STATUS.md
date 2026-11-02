# ERP workspace redesign — implementation record

## Glass themes and motion update

The shared light glass design now includes a matching midnight navy theme with blue/violet accents. The top-bar and sign-in sun/moon controls update all module surfaces, tables, forms, dialogs and charts. `assets/js/theme.js` applies the saved browser preference before CSS paints; without a saved preference it follows the system theme. Preferences synchronize across browser tabs. `src/styles/glass.css` and `dark.css` contain the new palettes; `src/components/motion.js` handles viewport-triggered chart drawing, bar reveals, pointer highlights and keyboard-accessible chart-value tooltips. Motion respects reduced-motion settings, and the existing solid-surfaces preference remains available.

Reports include quick selectors, refreshed metric cards and race-safe report loading; exports remain disabled until the current result is available. Stock summary cards use actual stock value, line count, reorder status and locations. Validation: 11 frontend tests passed, CSS parsed and built, JavaScript syntax checked, authenticated inventory report and light/dark toggle verified in the browser, and dark preference survived reload. The preview browser's third-party dark-mode stylesheet overrides colors, so clean-browser palette verification remains limited. No backend schema changes are needed for themes.

## Delivered

The existing Laravel domain implementation was retained and audited before replacing its frontend entry point. The new launcher groups Sales/POS, Manufacturing, Inventory, Product Costing, CRM, HR/Payroll, Finance, Purchasing, Delivery/Fleet, Marketing, Reports and Administration. Role permissions come from the backend; hiding a tile does not authorize an API operation.

Frontend structure:

```text
frontend/src/
  main.js                  application shell, navigation and session lifecycle
  app/                     registry, workspace preferences and shared state
  components/              icons and reusable UI context
  modules/<domain>/        domain routes and specialized screen renderers
  modules/shared/pages.js  schema-driven lists, filters, pagination and editing
  services/                workflow commands and protected CSV serialization
  styles/                  local Tailwind input and workspace design system
frontend/assets/js/        shared transport, forms, charts and resource schema
frontend/tests/            transport and export regression tests
```

The shell has glass surfaces, colored application icons, contextual sidebars, mobile navigation, loading/empty/error states, keyboard shortcuts, scoped search, notifications and profile preferences. Data tables use solid surfaces. Reduced-motion CSS and solid-surface preferences avoid mandatory animation or blur. Tailwind is built locally and locked; fonts use system fallbacks.

## API additions and compatibility

Both `/api` and `/api/v1` expose the same audited controllers. Added endpoints include GET `/apps`, GET `/lookups/{resource}`, GET `/search?q=...`, PATCH `/profile`, PUT `/profile/password`, PATCH `/preferences`, GET `/notifications`, POST `/notifications/read` and GET `/operations/{key}`. X-Branch-Id is validated against server-side branch access. Responses include request correlation IDs and private/no-store caching headers.

POS checkout accepts Idempotency-Key. A successful operation and its response commit in the same database transaction; repeated matching commands return the original result and changed payloads are rejected. The frontend saves unresolved checkout keys per signed-in user, exposes status/retry controls, and never automatically retries writes. Network failures and server errors retain an uncertain checkout for reconciliation. This protection currently applies to checkout, not every legacy command.

Record lists, lookups, search and workflow controllers share branch restrictions. Form lookups return only display fields, avoiding employee salary disclosure. Notifications are read independently per user. Authentication tokens move from persistent localStorage to sessionStorage; existing bearer authentication remains compatible. HTTP validation errors map to form fields. Server business rules continue to own stock movement, cost snapshots, approvals, finance posting and payroll.

## Preserved workflows

Manufacturing retains BOMs, costing, release/start/complete transitions and finished-goods movements. Inventory retains opening stock, ledger, transfers and approval-based adjustments. Purchasing retains order approval, receipts and supplier payment. Sales retains split payments, credit limits, loyalty, salesperson commission, cashier sessions, receipts and returns. HR retains attendance, leave and payroll; finance, delivery, campaigns and management reporting remain available through the modular workspace.

## Verification and practical limits

Final local verification on 2026-11-01: backend 12 tests / 159 assertions passed; frontend 6 tests passed; local CSS compilation and JavaScript syntax checks passed. All configured module list endpoints and all 14 management reports were exercised through `/api/v1`. A synthetic product was saved and displayed in the browser preview without console errors. The loyalty ledger's existing singular table mapping was repaired and actual redemption tested. Temporary preview servers were stopped after verification.

Automated backend coverage includes manufacturing cost and stock reconciliation, POS stock/finance/return flows, role permissions, branch isolation, scoped reporting, lookup data minimization, notification ownership, profile/preferences and duplicate checkout prevention. Frontend tests cover session migration, filter serialization, write uncertainty, HTTP validation, session expiration, cancellation and safe CSV output.

Browser checks use a separate seeded SQLite preview, with no changes to the configured business database. An installed browser dark-mode extension overrides the authored light palette; screenshots from that browser show its override. Release acceptance still includes MySQL concurrency/load testing, real device accessibility review and business-owner reconciliation in staging. No production deployment is implied by local tests.

No offline POS synchronization, external card processor, statutory tax/payroll rules, double-entry accounting redesign or background report-export queue was invented. Existing reports run synchronously; large deployments should add measured export limits and queued generation before increasing report volume. Existing customer/marketing/fleet master data retain their organization-wide sharing model.

## Dependency maintenance

Laravel was upgraded from 11 to 12 to retain compatibility with this workspace's PHP 8.2 runtime while moving onto a supported branch. References: [Laravel 12 upgrade guide](https://laravel.com/docs/12.x/upgrade) and [release support matrix](https://laravel.com/docs/12.x/releases). Composer reported no security vulnerability advisories after the update. Dependency audit results describe the installed package set at verification time, not a permanent guarantee.

See UPGRADE.md for migration, smoke tests and rollback steps. The prior frontend files remain available as an audit reference; new index.html does not execute them.
