# CoreMarket Artisan Commands

## Safety Conventions

- Confirm `APP_ENV`, configured `DB_DATABASE`, the active Laravel database,
  and `SELECT DATABASE()` before any command that can write data.
- Take a verified database backup before production setup, cleanup, branch
  initialization, or any other apply mode.
- Run dry-run modes first whenever they exist.
- Never use a demo, QA, testing restore, or baseline cleanup command against a
  client production database.
- None of these commands replaces deployment approval, migrations, or a
  rollback plan.

Commands in `app/Console/Commands` are auto-discovered by
`app/Console/Kernel.php`. `routes/console.php` also registers Laravel's
`inspire` closure command.

## Command Index

| Command | Writes DB | Production use | Backup first |
| --- | --- | --- | --- |
| `coremarket:accounting-core-audit` | No | Safe read-only | No |
| `coremarket:accounting-events-audit` | No | Safe read-only | No |
| `coremarket:audit-baseline-readiness` | No | Safe read-only | No |
| `coremarket:branch-inventory-initialize` | Only with `--apply` | Controlled one-time initialization | Yes for apply |
| `coremarket:clean-baseline` | Only with confirmed apply | Baseline build/cleanup only | Yes |
| `coremarket:clean-storefront-settings` | Only with confirmed apply | Controlled settings cleanup | Yes |
| `coremarket:client-setup` | Yes | Intended for first client setup | Yes |
| `coremarket:demo-pilot-prepare` | Only with confirmed apply | No, demo DB only | Yes for apply |
| `coremarket:guard-database` | No | Safe and recommended | No |
| `coremarket:restore-testing-database` | Only with confirmed apply | Never; testing DB only | Yes |
| `coremarket:runtime-db-diagnostics` | No | Safe read-only | No |
| `coremarket:seed-demo` | Only with confirmed apply | Never; demo DB only | Yes |
| `coremarket:seed-qa-store` | Only with confirmed apply | Never; local QA only | Yes |
| `coremarket:setup-instance` | Only with confirmed apply | Controlled managed-instance setup | Yes |
| `coremarket:stock-identity-audit` | No | Safe read-only | No |
| `coremarket:testing-database-status` | No | Never targets production; reads testing DB | No |
| `coremarket:vat-audit` | No | Safe read-only | No |
| `inspire` | No | Safe | No |

## Read-Only Audit Commands

### `coremarket:guard-database`

- **File:** `app/Console/Commands/CoreMarketGuardDatabase.php`
- **Signature:** `coremarket:guard-database`
- **Purpose:** Reports the active database, table count, and required runtime
  tables. It never writes data.
- **When:** Before setup, migrations, tests, or opening an unfamiliar local
  instance.
- **Production:** Safe and recommended.
- **Backup:** Not required.
- **Example:** `php artisan coremarket:guard-database`
- **Forbidden:** Do not treat a PASS as migration or business-flow acceptance.

### `coremarket:runtime-db-diagnostics`

- **File:** `app/Console/Commands/CoreMarketRuntimeDbDiagnostics.php`
- **Signature:** `coremarket:runtime-db-diagnostics`
- **Purpose:** Shows default/runtime snapshot database context and persisted
  plan/status without displaying credentials or the sync token.
- **When:** Diagnosing runtime snapshot storage or plan resolution.
- **Production:** Safe read-only.
- **Backup:** Not required.
- **Example:** `php artisan coremarket:runtime-db-diagnostics`
- **Forbidden:** Do not use its output as permission to mutate the reported DB.

### `coremarket:audit-baseline-readiness`

- **File:** `app/Console/Commands/CoreMarketAuditBaselineReadiness.php`
- **Signature:** `coremarket:audit-baseline-readiness`
- **Purpose:** Audits clean managed-instance baseline tables, branding,
  language/currency, data counts, and readiness without writes.
- **When:** Before exporting or accepting a clean baseline.
- **Production:** Safe read-only, but primarily a baseline tool.
- **Backup:** Not required.
- **Example:** `php artisan coremarket:audit-baseline-readiness`
- **Forbidden:** It does not clean or export a baseline.

### `coremarket:stock-identity-audit`

- **File:** `app/Console/Commands/CoreMarketStockIdentityAudit.php`
- **Signature:** `coremarket:stock-identity-audit`
- **Purpose:** Checks SKU, barcode, variant, and stock identity consistency.
- **When:** Before import acceptance, barcode rollout, or serialized inventory.
- **Production:** Safe read-only.
- **Backup:** Not required.
- **Example:** `php artisan coremarket:stock-identity-audit`
- **Forbidden:** Do not interpret findings as automatic repair instructions.

### `coremarket:accounting-events-audit`

- **File:** `app/Console/Commands/CoreMarketAccountingEventsAudit.php`
- **Signature:** `coremarket:accounting-events-audit`
- **Purpose:** Counts missing/duplicate Accounting Lite events and missing cost
  snapshots.
- **When:** Accounting reconciliation and pre-pilot review.
- **Production:** Safe read-only.
- **Backup:** Not required.
- **Example:** `php artisan coremarket:accounting-events-audit`
- **Forbidden:** It does not post or backfill events.

### `coremarket:accounting-core-audit`

- **File:** `app/Console/Commands/CoreMarketAccountingCoreAudit.php`
- **Signature:** `coremarket:accounting-core-audit {--apply} {--confirm}`
- **Purpose:** Audits journals, event links, duplicate sources, balance, and
  required accounts. Apply mode is intentionally unavailable.
- **When:** Accounting Core health checks.
- **Production:** Safe only without `--apply`.
- **Backup:** Not required for read-only use.
- **Example:** `php artisan coremarket:accounting-core-audit`
- **Forbidden:** `--apply` is refused; do not script around that guard.

### `coremarket:vat-audit`

- **File:** `app/Console/Commands/CoreMarketVatAudit.php`
- **Signature:** `coremarket:vat-audit`
- **Purpose:** Audits tax rates/snapshots and sales, return, purchase, and
  expense tax coverage.
- **When:** Tax data QA before formal compliance work.
- **Production:** Safe read-only.
- **Backup:** Not required.
- **Example:** `php artisan coremarket:vat-audit`
- **Forbidden:** It is not VAT filing or tax certification.

### `coremarket:testing-database-status`

- **File:** `app/Console/Commands/CoreMarketTestingDatabaseStatus.php`
- **Signature:** `coremarket:testing-database-status`
- **Purpose:** Reads `.env.testing` context and reports testing baseline health,
  counts, demo markers, and required tables.
- **When:** Before running tests or restoring the testing baseline.
- **Production:** The command itself is read-only, but it must inspect the
  dedicated testing database only.
- **Backup:** Not required.
- **Example:** `php artisan coremarket:testing-database-status`
- **Forbidden:** Never point `.env.testing` to runtime/demo/production.

## Controlled Data Commands

### `coremarket:client-setup`

- **File:** `app/Console/Commands/CoreMarketClientSetupCommand.php`
- **Signature:**

```text
coremarket:client-setup
  --project=
  --admin-email= | --email=
  --password= | --pass=
  --domain=
  --plan=enterprise
  [--write-env]
  [--enable-enterprise]
  [--force]
```

- **Purpose:** Runs Operations and Staff Role seeders, creates or recognizes
  the first Admin, assigns `owner_general_manager` with `store_admin`
  fallback, optionally enables Enterprise settings, and safely prepares a
  CorePilot sync token.
- **When:** Once after importing a clean client baseline and configuring the
  client `.env`.
- **DB writes:** Yes. Permissions/roles are idempotently seeded; Admin and
  selected `business_settings` are created or updated.
- **Role fallback:** If the roles or permissions tables are unavailable, the
  command skips their seeders and role assignment with a warning, but can still
  create the first user as `user_type=admin`.
- **Production:** Intended for controlled first installation after backup and
  DB-target verification.
- **Backup:** Full DB backup required. With `--write-env`, the command also
  creates `storage/app/backups/client_setup/<timestamp>/.env` before writing.
- **Overwrite protection:** Existing passwords and tokens are preserved unless
  `--force` is supplied. Existing customer accounts cannot be converted to
  Admin without `--force`.
- **Secrets:** The full `COREPILOT_SYNC_TOKEN` is never printed; only six
  leading and trailing characters are shown.
- **Example:**

```powershell
php artisan coremarket:client-setup `
  --project="Syrian Souq" `
  --admin-email="admin@corepilot-os.com" `
  --password="use-a-secure-handoff-password" `
  --domain="syriansouq.com" `
  --plan="enterprise" `
  --write-env `
  --enable-enterprise
```

- **Forbidden:** Do not commit `.env`, paste the token into tickets/logs, use
  `--force` casually, or run before importing the schema.

### `coremarket:setup-instance`

- **File:** `app/Console/Commands/CoreMarketSetupInstance.php`
- **Signature:** `coremarket:setup-instance {instance_id} [--dry-run]
  [--apply] [--confirm-instance-setup] [--create-store-admin]` plus store,
  domain, plan, admin, contact, currency, language, location, and metadata
  options listed by `php artisan help coremarket:setup-instance`.
- **Purpose:** Previews or applies managed-instance branding/settings, shop
  fields, and an optional `store_admin`. It never edits `.env`.
- **When:** Detailed managed-instance branding after the base client setup.
- **DB writes:** Only confirmed apply mode.
- **Production:** Controlled use after dry-run and backup.
- **Backup:** Required before apply.
- **Example:** `php artisan coremarket:setup-instance client-store --dry-run
  --store-name="Client Store" --domain="store.example.com"`
- **Forbidden:** Do not pass secrets as branding fields or assume it writes
  license/environment values.

### `coremarket:branch-inventory-initialize`

- **File:** `app/Console/Commands/CoreMarketBranchInventoryInitialize.php`
- **Signature:** `coremarket:branch-inventory-initialize [--dry-run] [--apply]
  [--confirm-branch-inventory] [--branch-id=]`
- **Purpose:** Initializes missing default-branch balances from aggregate
  `product_stocks.qty`.
- **When:** One-time conversion of an existing unified-stock installation.
- **DB writes:** Only with confirmed apply.
- **Production:** Controlled and safe only after a clean dry-run.
- **Backup:** Required before apply.
- **Example:** `php artisan coremarket:branch-inventory-initialize --dry-run`
- **Forbidden:** Never apply blindly, choose the wrong branch, or use it as a
  recurring stock synchronization job.

See the detailed Branch Inventory section below.

### `coremarket:clean-storefront-settings`

- **File:** `app/Console/Commands/CoreMarketCleanStorefrontSettings.php`
- **Signature:** `coremarket:clean-storefront-settings [--dry-run] [--apply]
  [--confirm-storefront-cleanup]`
- **Purpose:** Previews or neutralizes an allowlisted set of storefront
  `business_settings` without touching commercial rows.
- **When:** Preparing a clone/baseline with inherited storefront settings.
- **DB writes:** Only confirmed apply mode.
- **Production:** Use only for an approved settings cleanup.
- **Backup:** Required before apply.
- **Example:** `php artisan coremarket:clean-storefront-settings --dry-run`
- **Forbidden:** Do not use as a general settings reset.

### `coremarket:clean-baseline`

- **File:** `app/Console/Commands/CoreMarketCleanBaseline.php`
- **Signature:** `coremarket:clean-baseline [--dry-run] [--apply]
  [--confirm-clean-baseline]`
- **Purpose:** Neutralizes allowlisted branding/settings/shop/page/translation
  surfaces for a clean baseline without deleting commercial data.
- **When:** Baseline build and validation, not normal client operations.
- **DB writes:** Only confirmed apply mode.
- **Production:** Not a routine production command.
- **Backup:** Required before apply.
- **Example:** `php artisan coremarket:clean-baseline --dry-run`
- **Forbidden:** Never run apply on a branded live client without an explicit
  cleanup decision and rollback backup.

## Demo And QA Commands

### `coremarket:seed-demo`

- **File:** `app/Console/Commands/CoreMarketSeedDemoCommand.php`
- **Signature:** `coremarket:seed-demo [--dry-run] [--apply]
  [--confirm-demo-seed] [--reset] [--with-samples=standard|large]`
- **Purpose:** Creates the protected synthetic demo dataset.
- **When:** Dedicated `*_demo` databases only.
- **DB writes:** Confirmed apply; `--reset` rebuilds recognized demo records.
- **Production:** Never.
- **Backup:** Required before apply/reset.
- **Example:** `php artisan coremarket:seed-demo --dry-run`
- **Forbidden:** Runtime, testing, client production, or any DB without the
  `_demo` suffix.

### `coremarket:demo-pilot-prepare`

- **File:** `app/Console/Commands/CoreMarketDemoPilotPrepare.php`
- **Signature:** `coremarket:demo-pilot-prepare [--dry-run] [--apply]
  [--confirm-demo-pilot]`
- **Purpose:** Adds missing Pilot branch-price, credit/AR, return-credit,
  Serial/IMEI, and warranty examples to a protected demo database.
- **When:** After the base demo is seeded and Branch Inventory is reviewed.
- **DB writes:** Confirmed apply only; stable idempotency markers are used.
- **Production:** Never.
- **Backup:** Required before apply.
- **Example:** `php artisan coremarket:demo-pilot-prepare`
- **Forbidden:** Runtime/testing/client databases; the command explicitly
  blocks known non-demo names.

### `coremarket:seed-qa-store`

- **File:** `app/Console/Commands/CoreMarketSeedQaStore.php`
- **Signature:** `coremarket:seed-qa-store [--dry-run] [--apply]
  [--confirm-qa-seed] [--password=]`
- **Purpose:** Creates local-only QA storefront/COD resources.
- **When:** Controlled local end-to-end QA.
- **DB writes:** Confirmed apply only.
- **Production:** Never.
- **Backup:** Required before apply.
- **Example:** `php artisan coremarket:seed-qa-store --dry-run`
- **Forbidden:** Never expose the QA password or run on production/demo unless
  that target is explicitly the isolated QA environment.

## Testing Restore Command

### `coremarket:restore-testing-database`

- **File:** `app/Console/Commands/CoreMarketRestoreTestingDatabase.php`
- **Signature:** `coremarket:restore-testing-database [--dry-run] [--apply]
  [--from-clean-baseline] [--confirm-testing-db-restore]`
- **Purpose:** Drops/recreates only the protected testing database and imports
  the selected private SQL baseline.
- **When:** Resetting `coremarket_testing` before deterministic tests.
- **DB writes:** Destructive confirmed apply to testing only.
- **Production:** Never.
- **Backup:** Back up testing state if it matters; always verify target names.
- **Example:** `php artisan coremarket:restore-testing-database --dry-run`
- **Forbidden:** Runtime/demo/client DBs, wildcard names, or bypassing the
  `_testing` and runtime-separation guards.

## Framework Closure Command

### `inspire`

- **File:** `routes/console.php`
- **Signature:** `inspire`
- **Purpose:** Prints a Laravel inspirational quote.
- **When:** Developer convenience only.
- **DB writes:** No.
- **Production:** Safe but operationally unnecessary.
- **Backup:** No.
- **Example:** `php artisan inspire`
- **Forbidden:** None; it is unrelated to CoreMarket setup.

## Branch Inventory Initialization

### Dry-Run

```powershell
php artisan coremarket:branch-inventory-initialize --dry-run
```

This reads existing `product_stocks`, resolves the requested/default branch,
and reports what would be created in `product_stock_branch_balances`. It does
not write rows and is safe to repeat.

Use it:

- after importing products and legacy aggregate stock
- before converting unified stock to branch stock
- before enabling branch-aware sales operationally
- immediately before any reviewed `--apply`

The report includes the default/selected branch, scanned rows, proposed rows,
already skipped rows, and differences. Any unexpected branch, skipped count,
or difference is a stop condition that must be investigated.

### Apply

```powershell
php artisan coremarket:branch-inventory-initialize --apply --confirm-branch-inventory
```

Apply creates only missing branch balances, assigning each current
`product_stocks.qty` to the selected/default branch. `product_stocks.qty`
remains the backward-compatible aggregate mirror. The initializer skips stock
rows that already have branch balances, which makes reruns non-duplicating,
but a rerun is not a repair tool for reported differences.

Safety rules:

- require a verified DB backup
- print and confirm the actual database target
- accept only a clean dry-run with the intended branch
- run once during controlled conversion, not as a cron job
- do not apply randomly to runtime or after branch operations have started
- investigate every difference rather than overwriting it

## Multi-Branch Support Answer

Yes. CoreMarket supports branches through Branch Inventory, Branch Pricing,
Stock Transfers, POS branch context/default fallback, and Staff Branch
Assignments. It does **not** yet provide complete accounting-per-branch P&L,
balance sheet, or statutory accounting separation. In Arabic:

> نعم، CoreMarket يدعم تعدد الأفرع في المخزون، أسعار الفروع، التحويلات، سياق
> الفرع في POS، وتعيين الموظفين للفروع. لكنه ليس بعد نظام محاسبة مستقل وكامل
> لكل فرع.
