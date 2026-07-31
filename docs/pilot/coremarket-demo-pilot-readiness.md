# CoreMarket Demo Pilot Readiness

## Scope

Demo80 prepared the local `coremarket_demo` database for a supervised
commercial Pilot presentation. It did not migrate or modify
`coremarket_runtime`, `coremarket_testing`, `database/base`, CorePilotOS, or
Flutter POS, and it did not deploy the application.

The source repository had already advanced beyond the requested QA79 commit
to `814c27482f7abfb208855b6bc5e448ae9b9c868a`, matching `origin/main`.
The demo application code was synchronized additively because its schema was
current but its working copy did not contain the Branch Inventory command or
the latest feature routes. The sync preserved demo `.env`, `storage`,
`vendor`, uploads, build output, `database/base`, and both local logo files.

## Safety And Backup

Before any demo write, Laravel configuration, the active connection, and
`SELECT DATABASE()` all returned `coremarket_demo`. The backup is:

`C:\xampp\htdocs\coremarket-demo\storage\app\backups\demo80_pilot_features\20260731_105829`

It contains:

- source and demo `.env` backups
- a complete `coremarket_demo` SQL dump
- a migrations-table SQL dump
- a `business_settings` SQL dump
- the additive code-sync log

The demo `.env` SHA-256 remained unchanged after code synchronization.

## Enabled Demo Settings

The detailed keys below were absent and therefore using application defaults.
They were added only to `coremarket_demo` through the existing
`business_settings` convention:

| Setting | Demo value |
| --- | --- |
| `inventory.strict_inventory_mode` | `true` |
| `inventory.allow_negative_stock` | `false` |
| `inventory.branch_inventory_enabled` | `true` |
| `inventory.serial_tracking_enabled` | `true` |
| `inventory.imei_tracking_enabled` | `true` |
| `inventory.warranty_tracking_enabled` | `true` |
| `catalog.advanced_variants_enabled` | `true` |
| `pricing.branch_pricing_enabled` | `true` |
| `pricing.branch_pricing_priority` | `branch_price_first` |
| `customer_accounts.enabled` | `true` |
| `customer_accounts.credit_limits_enabled` | `true` |
| `customer_accounts.payment_terms_enabled` | `true` |
| `customer_accounts.pay_on_account_enabled` | `true` |
| `pos.pay_on_account_enabled` | `true` |
| `checkout.pay_on_account_enabled` | `true` |
| `inventory.setup_mode_enabled` | `true` |
| `inventory.opening_stock_enabled` | `true` |
| `inventory.adjustments_enabled` | `true` |
| `inventory.adjustment_requires_approval` | `true` |
| `inventory.stock_counts_enabled` | `true` |
| `inventory.emergency_adjustment_enabled` | `false` |

Emergency adjustments remain deliberately disabled.

## Branch Inventory Initialization

The repeated dry-run matched QA79:

- default branch: Main Branch (`#1`)
- product stock rows scanned: 30
- proposed balances: 30
- skipped: 0
- differences: 0

The guarded apply then created 30 branch balances. Before Pilot sample
preparation, aggregate `product_stocks.qty` and the branch-balance sum were
both `1704`, with zero per-stock mismatches. After the serialized Pilot
opening stock and sale, the aggregate mirror still had zero mismatches.

## Pilot Demo Data

The existing protected demo seeder was not rerun because its legacy stock
operations are not safe to repeat after branch initialization. A small
guarded command was added instead:

```powershell
php artisan coremarket:demo-pilot-prepare
php artisan coremarket:demo-pilot-prepare --apply --confirm-demo-pilot
```

The command is dry-run by default, refuses non-`*_demo` databases, explicitly
blocks runtime/testing/legacy database names, runs transactionally, and uses
stable idempotency keys. A second apply produced no additional records.

Demo80 added only the missing modern Pilot scenarios:

- one serialized smartphone variant with documented opening stock
- one active Main Branch price (`679`, public compare price `699`)
- two Serial/IMEI units: one sold on account and one available
- one active 12-month warranty policy
- one active customer credit profile with a `5000` limit and 30-day terms
- one unpaid `pay_on_account` order and its AR invoice debit
- one partial customer-account return credit and linked AR credit note

Current presentation minimums:

- 1 Super Admin, 1 cashier, 1 accountant, and warehouse/delivery staff
- 1 branch
- 31 stocked products, including 1 advanced variant
- 31 SKU/barcode stock identities
- 1 branch price and 1 customer credit profile
- 4 suppliers and 2 purchase receipts
- 19 orders, including 1 Pay on Account order
- 2 sales returns and 1 posted account-credit refund
- 1 available serialized unit and 1 warranty policy
- 2 delivery records

The existing Super Admin provides store-management coverage; there is no
separate `store_admin` demo account. Existing delivery records have no
positive COD amount, so the COD workflow can be shown but a populated COD
settlement example still requires a deliberate demo transaction.

## Authenticated Smoke Results

All tested pages returned HTTP 200:

- `/admin`
- `/admin/products/all` (the registered products page; `/admin/products` is
  not the canonical route)
- `/operations`
- `/operations/pos`
- `/operations/cashbox`
- `/operations/inventory/adjustments`
- `/operations/inventory/stock-counts`
- `/operations/inventory/branch-stock`
- `/operations/inventory/stock-transfers`
- `/operations/pricing/branch-prices`
- `/operations/deliveries`
- `/operations/document-templates`
- `/operations/accounting/reports`
- `/operations/sales-returns`
- `/operations/warranty`
- `/operations/customer-receivables`
- `/operations/customers/38/receivables`
- `/operations/customers/38/account-profile`

## Branding Status

The two CoreMarket identity files were already tracked by the source commit
before Demo80 and were not edited or staged in this step:

- `public/assets/img/logo.png`: 141,531 bytes, 5510 x 900, horizontal
  CoreMarket wordmark. It is referenced by admin navigation, storefront
  navigation/footer, invoices, email, and download views.
- `public/assets/img/core-market-logo.png`: 261,739 bytes, 3000 x 3000,
  square CoreMarket icon. No direct application reference was found.

The assets are visually consistent CoreMarket branding. Any future file
replacement or UI wiring should be handled as a separate
`COREMARKET-BRANDING-81-APPROVE-LOGOS` decision with placement and responsive
rendering acceptance. Demo80 did not recommit or overwrite them.

## Presentation Position

Ready to show:

- Web POS cash and Pay on Account
- branch stock, transfers, and branch pricing
- strict inventory governance and approvals
- customer credit, AR ledger, statement, and return credit
- serialized product, IMEI, warranty policy, and warranty workflow
- purchasing, sales returns, deliveries, cashbox, reports, and documents

Web-only:

- returns/refunds and credit notes
- warranty management
- advanced Operations administration

Not included:

- payment gateway automation
- Restaurant/KOT
- Production/BOM
- official tax filing or full statutory accounting
- Flutter feature changes in this step

## Rollback Notes

For a full rollback, stop demo use and restore
`coremarket_demo.sql` from the backup folder. To roll back only settings,
restore `coremarket_demo_business_settings.sql`. Do not use `migrate:fresh`,
`db:wipe`, or a wildcard database command.

## Decision

- Demo Full Presentation: **GO**, supervised and local.
- Live Deployment: **NO-GO in Demo80**. Runtime migration reconciliation,
  production backups, target smoke tests, printer acceptance, and deployment
  approval remain separate requirements.
