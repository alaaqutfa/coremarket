# CoreMarket Pilot v0.1 Live Readiness Report

Status date: 2026-07-30

## Release Identity

- Backend pre-release code commit: `7a8b09933ae0a26b9208cbc42664cebfb8958a02`
- Flutter POS code commit: `72be9e014b1536e61cf956880ad0a22ee16c7a8d`
- Database baseline commit: `9cdbd556ccb2847c6afd6746cf1c66073a5511b5`
- Backend tag: `v0.1-coremarket-pilot`
- Flutter tag: `v0.1-coremarket-pos-windows-pilot`
- Windows executable:
  `build/windows/x64/runner/Release/coremarket_pos.exe`
- Windows package:
  `build/windows/x64/runner/Release/CoreMarket_POS_Windows_Pilot_v0.1.zip`
- Package SHA256:
  `EB0792D76D9736FA90F11C5246EC804C0423CDDF855CE1B557371FB5E6644F65`

Tags are created only after the final documentation commits and therefore
identify the complete Pilot v0.1 source and release documentation.

## Final QA

### Backend and Web

- `php artisan optimize:clear`: passed.
- `php artisan route:list`: passed, with 1,419 output lines.
- `php artisan coremarket:guard-database`: passed its read-only legacy runtime
  minimum against `coremarket_runtime`; no database changes were made.
- `git diff --check`: passed.
- Admin, Operations, Web POS, Cashbox, Products, Purchases, Inventory
  Governance, Branch Stock, Stock Transfers, Branch Pricing, Customer
  Receivables, Returns/Refunds, Serial/Warranty, reports, and document routes
  are registered.

The current local `coremarket_testing` database was intentionally restored by
DB-01 to its pre-audit 119-table baseline. The Step 78 rerun therefore cannot
execute modern feature tests that require tables such as `store_branches`,
`price_lists`, `customer_ledger_entries`, `product_branch_prices`,
`sales_return_refunds`, and `product_serial_units`. `StaffRole` passed 3/3;
other requested filters stopped on missing-schema errors, not failed business
assertions. No migration or testing-baseline restore was allowed in Step 78.

This is a known environment baseline limitation rather than an unverified code
claim. DB-01 imported the regenerated 149-table `coremarket_test.sql` and
recorded zero failures across Product, Purchase, Sales Return/Refund, Web POS,
Branch Inventory/Pricing, Inventory Governance, Stock Transfer, Customer
Credit/Receivables, Credit Payment, Serial/Warranty, Operations, and Staff Role
filters. The later Flutter compatibility change was also covered by passing
Web POS, Credit Payment, Serial/Warranty, Branch Pricing, Branch Inventory, and
Operations filters before the old testing database was restored.

### Flutter Windows POS

- Flutter 3.44.0 and Dart 3.12.0.
- `flutter analyze`: passed with no issues.
- `flutter test`: 38 passed; 2 credentialed live-demo tests skipped by default.
- `flutter build windows --release`: passed.
- The ZIP contains the executable, Flutter runtime, plugin DLLs, and data
  assets only. No source, `.env`, or Git metadata is included.

## Smoke Readiness

Backend/Web route readiness:

- Admin login and Operations dashboard.
- Web POS and both canonical Cashbox routes.
- Products, purchases, returns, inventory governance, and reports.
- Branch stock, transfers, and pricing.
- Customer receivables, account credit, refunds, and credit notes.
- Serial/IMEI, warranty, PDFs, and document templates.

Flutter POS readiness:

- Login, shift context, cash sale, receipt, and cash-only offline queue.
- Server-side Branch Pricing, Price Lists, and branch stock validation.
- Online Pay on Account with credit summary and server revalidation.
- Online Serial/IMEI selection and serialized checkout.
- Returns, refunds, credit notes, warranty lookup, and warranty claims remain
  in the Web Operations UI.

## Known Limitations

- The runtime database is not synchronized to the latest schema.
- `coremarket_testing` now uses the committed 149-table/33-migration baseline.
- `coremarket_demo` has the latest CoreMarket feature tables, but retains three
  known legacy Payku migration-ledger anomalies.
- Pay on Account and serialized sales require an online connection.
- Flutter offline queue supports cash sales only.
- Flutter returns/refunds and warranty workflows remain Web-only.
- No payment gateway, Restaurant/KOT, Production/BOM, official VAT filing, or
  full statutory accounting close is included.
- Receipt printing must be accepted on the client's actual printer.
- `CashboxUiTest` retains a documented baseline-sensitive warning. The
  canonical `/operations/cashbox` and `/operations/cashboxes` routes are both
  registered; neither route was renamed.

## QA79 Database Rehearsal

QA79 completed on 2026-07-31:

- backed up `.env`, `coremarket_testing`, `coremarket_demo`, and both migration
  ledgers under
  `storage/app/backups/qa79_demo_sync/20260731_103933/`
- restored `coremarket_testing` from the committed `coremarket_test.sql`
- verified 149 tables, 33 migration rows, and all recent critical tables
- recorded 258 passing focused tests and two non-blocking, baseline-sensitive
  expectations in StorefrontPrice and Cashbox
- applied only the six clone-tested Step 67-73 migrations to `coremarket_demo`
- ran Operations permissions and Staff Role preset seeders
- verified demo at 149 tables and 30 migration rows
- ran Branch Inventory initialization in dry-run mode only: 30 scanned,
  30 proposed, 0 skipped, and 0 differences
- completed authenticated read-only smoke checks for the enabled demo flows

The full evidence and route status are in
`docs/pilot/coremarket-demo-sync-report.md`.

## Demo80 Pilot Features And Branding

Demo80 completed on 2026-07-31:

- backed up the demo environment, full database, migration ledger, and
  settings before any write
- repeatedly confirmed the configured, active Laravel, and selected MySQL
  database as `coremarket_demo`
- synchronized stale demo application code additively while preserving
  `.env`, storage, uploads, vendor, build output, `database/base`, and logos
- enabled only the requested demo feature settings
- repeated Branch Inventory dry-run with 30 scanned, 30 proposed, 0 skipped,
  and 0 differences, then applied the reviewed initialization
- verified 30 initialized branch balances and zero aggregate mirror
  mismatches
- added a guarded, idempotent Pilot preparation command for the missing
  branch-pricing, customer-credit, AR, return-credit, Serial/IMEI, and warranty
  examples
- received HTTP 200 from all 18 authenticated Admin, Operations, Branch,
  Governance, AR, Returns, and Warranty smoke targets
- audited both already tracked CoreMarket logo assets without editing or
  staging them

The complete evidence, settings list, demo data inventory, routes, and
rollback notes are in `docs/pilot/coremarket-demo-pilot-readiness.md`.

## Deployment Runbook

These commands are documented only and were not run:

```powershell
git fetch origin --tags
git checkout v0.1-coremarket-pilot
composer install --no-dev --prefer-dist --optimize-autoloader
php artisan coremarket:guard-database
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Before `migrate --force`, take a verified SQL backup, confirm the actual
connection name, reconcile legacy/Payku migration-ledger drift, and rehearse
the exact migration plan on a clone. Branch Inventory initialization is a
separate reviewed action and must start with `--dry-run`.

Windows POS deployment consists of checksum verification, extraction of the
complete ZIP folder, endpoint configuration without embedding credentials, and
a supervised login/cash-sale/receipt smoke test.

## Rollback Runbook

- Stop new transactions and preserve application and database logs.
- Restore the verified pre-deployment database backup rather than running a
  blind migration rollback.
- Check out the previously approved backend commit or tag and reinstall its
  locked Composer dependencies.
- Restore the previous Windows POS package as a complete folder.
- Clear/rebuild Laravel caches and repeat the smoke checklist.

## Decision

- Backend/Web Pilot: **GO for a supervised pilot** on a prepared, migrated
  instance after the database rehearsal and manual acceptance checklist.
- Local Demo Pilot: **GO for a supervised full Web presentation**. Advanced
  Inventory Governance, Branch Inventory/Transfers, Branch Pricing, Customer
  Receivables/Credit, Pay on Account, Serial/IMEI, and Warranty are enabled in
  `coremarket_demo`; the reviewed Branch Inventory initialization is applied.
- Flutter Windows POS Pilot: **GO for supervised online cash, account, and
  serialized sales**, with cash-only offline support.
- Live Deployment: **NO-GO in this step**. No deployment was performed, and
  runtime migration reconciliation, backups, target-environment smoke tests,
  printer acceptance, and rollback approval remain mandatory.
