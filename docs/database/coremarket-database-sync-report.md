# CoreMarket Database and SQL Baseline Sync Report

## Audit identity

- Audit date: 2026-07-30
- Source branch: `main`
- Source commit before this audit:
  `5929f4c5a623a21ee5b61e042c9e2f328baf282b`
- Application environment from `.env`: `local`
- Database from `.env`: `coremarket_runtime`
- Scope: database audit, backups, isolated SQL baseline regeneration,
  validation, and test execution only.
- No runtime migration, demo migration, deployment, feature change, CorePilotOS
  change, or Flutter POS change was performed.

## Safety process

Every database sequence printed:

1. `APP_ENV` from `.env`.
2. `DB_DATABASE` from `.env`.
3. The intended database override.
4. The actual database returned through the Laravel connection.

The sequence stopped unless the actual database matched the intended database.
No password was printed or written to this report.

## Backup

Backup directory:

```text
C:\xampp\htdocs\coremarket\storage\app\backups\db_sync_01\20260730_135616
```

Files:

| File | Purpose |
| --- | --- |
| `.env` | Original project environment |
| `coremarket.sql` | Original clean baseline before regeneration |
| `coremarket_test.sql` | Original testing baseline before regeneration |
| `coremarket_runtime.sql` | Read-only runtime database dump |
| `coremarket_testing.sql` | Original testing database dump |
| `coremarket_demo.sql` | Read-only demo database dump |
| `generated_coremarket.sql` | Generated and validated clean baseline |
| `generated_coremarket_test.sql` | Generated and validated testing baseline |

Old backups were not overwritten. Backups and generated working dumps remain
outside Git.

## Git safety before work

- Branch: `main`
- `HEAD` and `origin/main` both pointed to
  `5929f4c5a623a21ee5b61e042c9e2f328baf282b`.
- Existing unrelated work:
  - modified `public/assets/img/logo.png`
  - untracked `public/assets/img/core-market-logo.png`
- `database/base` was clean before regeneration.
- The logo files remained unstaged and were not copied into any baseline.
- Repository `.gitignore` excludes all `*.sql` files, and the two baseline files
  were not previously tracked. This task explicitly requires committing
  regenerated baselines, so only the two validated `database/base` paths are
  force-added by exact name. No backup, runtime, demo, build, or temporary dump
  is added.

## Migration inventory

CoreMarket has 29 migration files under `database/migrations`. Laravel also
registers vendor migrations from Socialiter and Payku.

### Actual databases

| Database | Tables | Applied migration rows | Laravel registered pending | Missing critical tables |
| --- | ---: | ---: | ---: | ---: |
| `coremarket_runtime` | 117 | 11 | 22 | 18 |
| `coremarket_testing` | 119 | 12 | 21 | 18 |
| `coremarket_demo` | 136 | 24 | 9 | 13 |

`coremarket_testing` was temporarily replaced with the regenerated testing
baseline for the required test run, then restored from its pre-audit dump. The
numbers above are its restored original state.

### Runtime findings

`coremarket_runtime` was inspected read-only and was not changed.

CoreMarket migration files pending by ledger comparison:

- `2019_12_14_000001_create_personal_access_tokens_table`
- `2022_06_29_075906_create_product_queries_table`
- `2026_07_15_000001_add_web_pos_fields_to_orders_table`
- `2026_07_17_000001_create_loyalty_points_foundation_tables`
- `2026_07_17_000002_add_loyalty_redemption_fields`
- All migrations from `2026_07_23_000001` through
  `2026_07_29_000006`

Laravel also reports three Payku migrations as pending even though their tables
already exist partially:

- `2021_06_07_000000_create_payku_transactions_table`
- `2021_06_07_000001_create_payku_payments_table`
- `2021_12_15_000000_add_new_columns_to_tables`

Therefore, plain `php artisan migrate` is unsafe on the old runtime database.
The first Payku migration attempts to create an existing table.

The 18 missing critical runtime tables are:

- `purchase_returns`
- `purchase_return_items`
- `customer_ledger_entries`
- `customer_payments`
- `customer_payment_allocations`
- `customer_account_profiles`
- `inventory_adjustment_documents`
- `inventory_adjustment_items`
- `stock_counts`
- `stock_count_items`
- `product_stock_branch_balances`
- `stock_transfers`
- `stock_transfer_items`
- `product_branch_prices`
- `sales_return_refunds`
- `product_serial_units`
- `product_warranty_policies`
- `warranty_claims`

### Testing findings

The restored original `coremarket_testing` remains at 119 tables and 12
migration rows. It is intentionally unchanged after the audit.

It has the same 18 missing critical tables as runtime. Laravel reports 21
pending migrations, including:

- the 14 CoreMarket migrations from `2026_07_23_000001` through
  `2026_07_29_000006`
- legacy/application ledger gaps
- three Payku migrations
- the Socialiter migration because the original testing baseline does not have
  `social_credentials`

The regenerated `database/base/coremarket_test.sql`, not the restored live
testing database, is the new source for the next controlled testing-baseline
restore.

### Demo findings

`coremarket_demo` was inspected and backed up but not changed.

It has six recent CoreMarket migrations pending:

- `2026_07_29_000001_create_customer_account_profiles`
- `2026_07_29_000002_create_inventory_governance_foundation`
- `2026_07_29_000003_create_branch_inventory_and_stock_transfers`
- `2026_07_29_000004_create_product_branch_prices`
- `2026_07_29_000005_create_sales_return_refunds`
- `2026_07_29_000006_create_serial_warranty_foundation`

Laravel additionally reports the same three Payku migrations as pending.

The 13 missing critical demo tables are exactly the tables created by those six
recent CoreMarket migrations:

- `customer_account_profiles`
- `inventory_adjustment_documents`
- `inventory_adjustment_items`
- `stock_counts`
- `stock_count_items`
- `product_stock_branch_balances`
- `stock_transfers`
- `stock_transfer_items`
- `product_branch_prices`
- `sales_return_refunds`
- `product_serial_units`
- `product_warranty_policies`
- `warranty_claims`

## Original SQL baseline validation

Both original SQL files imported successfully into isolated temporary
databases, so neither file had an SQL syntax/import failure. Both were outdated.

| Original file | Size | Tables after import | Migration rows | CoreMarket pending | Missing critical |
| --- | ---: | ---: | ---: | ---: | ---: |
| `coremarket.sql` | 7,341,937 bytes | 120 | 16 | 14 | 18 |
| `coremarket_test.sql` | 7,375,492 bytes | 119 | 15 | 14 | 18 |

Original clean data:

- users: 0
- products: 0
- orders: 0
- personal access tokens: 0
- explicit CoreMarket demo markers: 0

Original testing fixtures:

- users: 1 Admin fixture
- products: 4
- orders: 0
- personal access tokens: 0

The clean/test data separation was valid and was preserved.

## Baseline regeneration

The original clean and testing SQL files were imported into isolated build
databases. Only targeted migrations were applied.

System seeders:

- `Database\Seeders\OperationsPermissionSeeder`
- `Database\Seeders\StaffRolePresetSeeder`
- `DatabaseSeeder`
  - `PriceListSeeder`
  - `DocumentTemplateSeeder`
- `Database\Seeders\AccountingCoreSeeder`

No demo seeder, QA store seeder, fake transaction seeder, or historical
backfill was run.

### Legacy migration reconciliation

The old baselines already contained `payku_transactions` and `payku_payments`,
but their migration rows were absent and the Payku upgrade was partially
reflected in schema.

Only inside the isolated build databases:

- added nullable `payku_transactions.full_name`
- added nullable `payku_payments.deposit_date`
- verified existing `payment_key` and `transaction_key`
- recorded the three Payku migration rows after schema reconciliation

The testing build also applied the missing Socialiter
`2019_10_13_000000_create_social_credentials_table` migration.

This reconciliation is included in the regenerated SQL snapshots. No source
migration file and no real database was changed.

## Regenerated baseline validation

The generated files were exported with `--skip-comments` so they contain no
build database name, `CREATE DATABASE`, `USE`, or `DEFINER` statement.

Each final generated file was imported into a new empty validation database.

| Final file | Size | Tables | Migration rows | Laravel pending | Missing critical |
| --- | ---: | ---: | ---: | ---: | ---: |
| `database/base/coremarket.sql` | 7,414,343 bytes | 149 | 33 | 0 | 0 |
| `database/base/coremarket_test.sql` | 7,450,065 bytes | 149 | 33 | 0 | 0 |

### Final clean baseline contents

- users: 0
- products: 0
- orders: 0
- personal access tokens: 0
- fake/demo users: 0
- explicit demo markers: 0
- permissions: 372
- roles: 13
- document templates: 11
- default Price Lists: 5
- system accounting accounts: 12
- default branches: 1

The only matching `example` business value is the intentional neutral
`support@example.com` placeholder. No demo credentials, demo transactions, or
runtime database name is stored in the clean snapshot.

### Final testing baseline contents

- users: 1 fake Admin fixture
- products: 4 minimal fixtures
- orders: 0
- personal access tokens: 0
- permissions: 372
- roles: 13
- document templates: 11
- default Price Lists: 5
- system accounting accounts: 12
- default branches: 1

The testing baseline has the latest schema while preserving only its previous
minimal fixture profile.

## Demo sync commands for a future approved step

Do not run plain `php artisan migrate` because the old demo migration ledger has
three Payku anomalies.

First print and verify the target:

```powershell
Get-Content .env | Select-String '^(APP_ENV|DB_DATABASE)='
$env:DB_DATABASE="coremarket_demo"
php artisan tinker --execute="echo 'APP_ENV=' . app()->environment() . PHP_EOL; echo 'ACTUAL_DB=' . DB::selectOne('SELECT DATABASE() AS db')->db . PHP_EOL;"
```

Back up before migration. Supply credentials locally without placing them in
Git or shell history:

```powershell
$stamp = Get-Date -Format "yyyyMMdd_HHmmss"
$dump = "storage/app/backups/demo_sync_$stamp.sql"
C:\xampp\mysql\bin\mysqldump.exe --host=localhost --port=3306 --user=<local-user> --single-transaction --skip-lock-tables --default-character-set=utf8mb4 --result-file=$dump coremarket_demo
```

Apply only the six clone-tested migrations:

```powershell
php artisan migrate --path=database/migrations/2026_07_29_000001_create_customer_account_profiles.php --force
php artisan migrate --path=database/migrations/2026_07_29_000002_create_inventory_governance_foundation.php --force
php artisan migrate --path=database/migrations/2026_07_29_000003_create_branch_inventory_and_stock_transfers.php --force
php artisan migrate --path=database/migrations/2026_07_29_000004_create_product_branch_prices.php --force
php artisan migrate --path=database/migrations/2026_07_29_000005_create_sales_return_refunds.php --force
php artisan migrate --path=database/migrations/2026_07_29_000006_create_serial_warranty_foundation.php --force
php artisan db:seed --class="Database\Seeders\OperationsPermissionSeeder" --force
php artisan db:seed --class="Database\Seeders\StaffRolePresetSeeder" --force
php artisan coremarket:branch-inventory-initialize --dry-run
```

The exact sequence above succeeded against a clone restored from the current
demo dump. The Branch Inventory dry-run scanned 30 ProductStock rows and
proposed 30 balances with zero differences. Do not use `--apply` until that
output is reviewed and the feature is intentionally enabled.

Clean up the override:

```powershell
Remove-Item Env:DB_DATABASE
```

## Future live database migration commands

No live/runtime database migration was performed.

The following recent CoreMarket sequence succeeded against a clone restored
from the current runtime dump:

```powershell
$env:DB_DATABASE="coremarket_runtime"
php artisan tinker --execute="echo 'ACTUAL_DB=' . DB::selectOne('SELECT DATABASE() AS db')->db . PHP_EOL;"

php artisan migrate --path=database/migrations/2026_07_23_000001_create_supplier_accounting_and_purchase_returns_tables.php --force
php artisan migrate --path=database/migrations/2026_07_23_000002_create_customer_price_lists_foundation.php --force
php artisan migrate --path=database/migrations/2026_07_23_000003_create_product_family_classification_foundation.php --force
php artisan migrate --path=database/migrations/2026_07_27_000001_create_store_branches_and_staff_assignments.php --force
php artisan migrate --path=database/migrations/2026_07_27_000002_create_document_templates_foundation.php --force
php artisan migrate --path=database/migrations/2026_07_27_000003_create_order_delivery_foundation.php --force
php artisan migrate --path=database/migrations/2026_07_27_000004_create_delivery_cod_settlements.php --force
php artisan migrate --path=database/migrations/2026_07_28_000001_create_customer_receivables_foundation.php --force
php artisan migrate --path=database/migrations/2026_07_29_000001_create_customer_account_profiles.php --force
php artisan migrate --path=database/migrations/2026_07_29_000002_create_inventory_governance_foundation.php --force
php artisan migrate --path=database/migrations/2026_07_29_000003_create_branch_inventory_and_stock_transfers.php --force
php artisan migrate --path=database/migrations/2026_07_29_000004_create_product_branch_prices.php --force
php artisan migrate --path=database/migrations/2026_07_29_000005_create_sales_return_refunds.php --force
php artisan migrate --path=database/migrations/2026_07_29_000006_create_serial_warranty_foundation.php --force

php artisan db:seed --class="Database\Seeders\OperationsPermissionSeeder" --force
php artisan db:seed --class="Database\Seeders\StaffRolePresetSeeder" --force
Remove-Item Env:DB_DATABASE
```

This is a rehearsal result, not deployment approval. After that sequence, the
runtime clone still reported eight older pending migrations:

- personal access tokens
- three Payku migrations
- product queries
- Web POS fields
- loyalty foundation
- loyalty redemption fields

Those legacy ledger/schema differences require a separate compatibility review.
Do not run plain `migrate`, and do not execute the live sequence without a new
backup, maintenance window, rollback owner, and explicit approval.

## Health checks

- `php artisan optimize:clear`: passed.
- `php artisan route:list`: passed, 1,417 routes.
- `php artisan coremarket:guard-database`: passed read-only against
  `coremarket_runtime`.
- Runtime guard reported 117 tables and all seven legacy critical guard tables
  present.

The existing guard checks the legacy runtime minimum only. It does not prove
that recent CoreMarket tables or migrations are present.

## Focused tests

Tests ran only while:

- `APP_ENV=testing`
- `DB_DATABASE=coremarket_testing`
- Laravel actual connection returned `coremarket_testing`
- the regenerated `coremarket_test.sql` was imported

Results:

| Filter | Result |
| --- | ---: |
| Product | 25 passed |
| Purchase | 24 passed |
| SalesReturn | 15 passed |
| SalesReturnRefund | 6 passed |
| WebPos | 45 passed |
| BranchInventory | 6 passed |
| BranchPricing | 9 passed |
| InventoryGovernance | 8 passed |
| StockTransfer | 5 passed |
| CustomerCredit | 7 passed |
| CustomerReceivable | 6 passed |
| CreditPaymentMethod | 7 passed |
| SerialWarranty | 6 passed |
| Operations | 31 passed |
| StaffRole | 3 passed |

Failed filters: 0.

After testing, `coremarket_testing` was dropped/recreated and restored from the
pre-audit `coremarket_testing.sql` dump. Its restored counts are:

- tables: 119
- migration rows: 12
- users: 1
- products: 4
- orders: 0

No testing database change remains permanently.

## Temporary database cleanup

All eight SQL validation/build databases and both runtime/demo migration-rehearsal
clones were dropped by exact name after validation. The following protected
databases remain present:

- `coremarket_runtime`
- `coremarket_testing`
- `coremarket_demo`

## Known risks

- Actual runtime and demo databases remain behind the current source.
- Plain Laravel migration is unsafe on the old databases due to Payku schema
  and migration-ledger drift.
- The current runtime guard does not verify the recent Operations tables.
- The regenerated test SQL is latest, but the actual `coremarket_testing`
  database was restored to its original old state to meet the no-permanent-
  mutation rule.
- Branch Inventory initialization changes balances and needs a separately
  reviewed apply step.
- SQL baselines may contain extensive neutral translation/settings data and
  should remain controlled repository artifacts.

## Recommendation

### SQL baseline status: GO

Both baseline SQL files are importable, current through Step 75 schema, contain
all critical tables, have no registered pending migrations, and passed all
focused tests when used as the testing database source.

### Actual database estate: NO-GO for deployment

Do not deploy or claim database synchronization yet:

- runtime was not migrated
- demo was not migrated
- actual testing was restored to its original state
- Payku/legacy migration ledger drift remains on old databases

Proceed with a separately approved targeted demo sync first. Schedule runtime
migration only after legacy migration reconciliation, fresh backup, maintenance
window, and rollback approval.
