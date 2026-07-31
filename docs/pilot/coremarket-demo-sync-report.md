# CoreMarket QA79 Testing Baseline and Demo Sync Report

Status date: 2026-07-31

## Scope and Safety

- Backend commit at start:
  `6705ee38545757e9969eca459b6098433aefc7a1`.
- Release tag `v0.1-coremarket-pilot` resolved to the same commit locally and
  on `origin`.
- `coremarket_runtime` was not migrated, restored, seeded, or otherwise
  changed.
- No Flutter POS, CorePilotOS, `database/base`, build, or deployment action was
  performed.
- The two local logo files remained outside staging.

Backup root:

`storage/app/backups/qa79_demo_sync/20260731_103933/`

Backups created before database writes:

- `env.backup`
- `coremarket_testing.sql`
- `coremarket_testing_migrations.sql`
- `coremarket_demo.sql`
- `coremarket_demo_migrations.sql`

## Testing Baseline Restore

The protected restore command first reported:

- runtime database: `coremarket_runtime`
- testing target: `coremarket_testing`
- source: `database/base/coremarket_test.sql`
- source size: 7,450,064 bytes

Laravel and MySQL independently returned `coremarket_testing` before the
destructive restore. The dry-run succeeded, then the confirmed apply restored
only `coremarket_testing`.

Validation after restore:

- tables: 149
- migration rows: 33
- all 16 recent critical tables: present
- legacy command tables: present
- `database/base` files: unchanged

## Backend QA

Pre-test guards:

- Laravel environment: `testing`
- configured and actual test database: `coremarket_testing`
- `php artisan optimize:clear`: passed
- `php artisan route:list`: passed, 1,419 output lines
- read-only runtime guard: passed
- `git diff --check`: passed

| Filter | Result |
| --- | ---: |
| Product | 25 passed |
| Purchase | 24 passed |
| PurchaseReturn | 5 passed |
| SalesReturn | 15 passed |
| SalesReturnRefund | 6 passed |
| WebPos | 45 passed |
| BranchInventory | 6 passed |
| BranchPricing | 9 passed |
| InventoryGovernance | 8 passed |
| StockTransfer | 5 passed |
| CustomerCredit | 7 passed |
| CustomerReceivable | 6 passed |
| CreditPaymentMethod | 8 passed |
| SerialWarranty | 7 passed |
| Operations | 33 passed |
| StaffRole | 3 passed |
| StorefrontPrice | 5 passed, 1 baseline-sensitive assertion |
| PriceList | 9 passed |
| Cashbox | 32 passed, 1 known sidebar expectation |

Total: 258 passed and 2 non-functional, baseline-sensitive test assertions.

The Storefront assertion expected exactly one Price List after creating one,
but the current testing baseline intentionally starts with five seeded lists.
The public-price fallback and customer isolation assertions passed.

The Cashbox assertion expected both the dashboard and management URLs in the
sidebar. The current navigation intentionally links to
`/operations/cashbox`, while `/operations/cashboxes` remains a registered and
working management route. The other 32 Cashbox tests passed. Neither warning
justified changing production code during this rehearsal.

## Demo Schema Sync

Laravel and MySQL independently returned `coremarket_demo` before migration.
The pre-sync backup was already complete.

Before sync:

- tables: 136
- migration rows: 24
- products: 30
- orders: 18
- product stock rows: 30
- branches: 1

Plain `php artisan migrate --force` was not used because DB-01 documented three
legacy Payku migration-ledger anomalies. Only the six clone-tested CoreMarket
migrations were applied:

- `2026_07_29_000001_create_customer_account_profiles`
- `2026_07_29_000002_create_inventory_governance_foundation`
- `2026_07_29_000003_create_branch_inventory_and_stock_transfers`
- `2026_07_29_000004_create_product_branch_prices`
- `2026_07_29_000005_create_sales_return_refunds`
- `2026_07_29_000006_create_serial_warranty_foundation`

Seeders run:

- `Database\Seeders\OperationsPermissionSeeder`
- `Database\Seeders\StaffRolePresetSeeder`

After sync:

- tables: 149
- migration rows: 30
- permissions: 372
- all 16 recent critical tables: present
- Laravel route list: passed
- read-only database guard: passed

The three remaining Laravel pending entries are the known Payku legacy
anomalies. Their schema/ledger reconciliation remains a separate controlled
task and does not block the six CoreMarket feature schemas added here.

## Branch Inventory Dry-Run

Command:

`php artisan coremarket:branch-inventory-initialize --dry-run`

Result:

- default branch: `Main Branch (#1)`
- product stock rows scanned: 30
- balances that would be created: 30
- skipped: 0
- differences: 0
- rows written: 0

`--apply --confirm-branch-inventory` was not run. Branch Inventory remains
disabled until this initialization is explicitly approved and applied.

## Demo Smoke

Authenticated read-only GET checks returning HTTP 200:

- `/admin`
- `/admin/products/all`
- `/operations`
- `/operations/inventory`
- `/operations/pos`
- `/operations/cashbox`
- `/operations/pricing/branch-prices`
- `/operations/deliveries`
- `/operations/document-templates`
- `/operations/accounting/reports`
- `/operations/sales-returns`
- `/operations/warranty`

Routes exist but returned HTTP 404 because their feature gates remain disabled:

- `/operations/inventory/adjustments`
- `/operations/inventory/stock-counts`
- `/operations/inventory/branch-stock`
- `/operations/inventory/stock-transfers`
- `/operations/customer-receivables`

No feature flag was silently enabled. In particular, Branch Inventory was not
enabled before its balances were initialized.

## Decision

- Backend tests: **GO with two documented non-blocking test expectations**.
- Demo presentation: **GO for the currently enabled supervised Pilot flows**.
- Advanced Branch Inventory, governance, transfer, and AR demonstrations:
  **conditional** on explicit feature activation; Branch Inventory additionally
  requires the reviewed apply initialization.
- Live deployment: **NO-GO** until runtime migration reconciliation, backup,
  target smoke testing, printer acceptance, and rollback approval are complete.
