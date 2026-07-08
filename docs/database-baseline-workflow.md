# CoreMarket Database Baseline Workflow

## Official Local Baseline Reference

The official local baseline SQL reference is:

- `database/base/coremarket.sql`

This file is a private operational baseline reference for local recovery and baseline rebuild work.

It must not be treated as a Git-native schema system, and it must not be used as a reason to skip runtime database guards.

## Privacy and Git Rules

- SQL baseline files can contain private or client-contaminated data.
- Keep SQL dumps private and operational.
- Do not print SQL content into reports.
- Do not push private SQL dumps if they contain client or legacy runtime data.

The repository `.gitignore` already blocks SQL dump files, including:

- `/*.sql`
- `/**/*.sql`

## Why CoreMarket Needs A Baseline Workflow

CoreMarket cannot currently be rebuilt from tracked migrations alone.

The working runtime schema is much larger than the migration history currently tracked in Git. Because of that:

- do not run `migrate:fresh`
- do not run `db:wipe`
- do not assume a new empty database can boot the app

## Safe Local Runtime Workflow

After importing the local baseline SQL into a runtime database:

```bash
php artisan coremarket:guard-database
php artisan coremarket:clean-baseline --dry-run
php artisan coremarket:audit-baseline-readiness
php artisan coremarket:testing-database-status
php artisan route:list
```

Recommended order:

1. import `database/base/coremarket.sql` into a local runtime database
2. point `.env` to that runtime database
3. run `coremarket:guard-database`
4. run `coremarket:clean-baseline --dry-run`
5. if the preview is correct, run `coremarket:clean-baseline --apply --confirm-clean-baseline`
6. rerun `coremarket:audit-baseline-readiness`
7. run `coremarket:testing-database-status` if you need to know whether legacy command tests can run
8. if needed, run `coremarket:restore-testing-database --dry-run`
9. restore `coremarket_testing` with `coremarket:restore-testing-database --apply --confirm-testing-db-restore`
10. use `coremarket:setup-instance` later to make the store client-specific

To refresh the official private baseline SQL after a successful runtime cleanup:

1. back up the current `database/base/coremarket.sql` into an ignored local backup path such as `storage/app/db-backups/`
2. export the cleaned `coremarket_runtime` database into `database/base/coremarket.sql`
3. run `coremarket:restore-testing-database --apply --confirm-testing-db-restore` to rebuild `coremarket_testing` from the same clean baseline

## What `clean-baseline` Does

`coremarket:clean-baseline` is a safe baseline-neutralization workflow for:

- `business_settings`
- existing shop branding fields that still carry legacy client/public identity

It:

- neutralizes old store/client branding in `business_settings`
- neutralizes safe branding fields in `shops` such as name, slug, phone, address, and meta text
- neutralizes safe legacy metadata in `pages` when branding terms are obvious
- neutralizes category metadata only when obvious legacy branding text is present
- disables unsafe starter-incompatible baseline flags such as popup and vendor mode
- keeps the baseline generic for later client setup

It does not:

- delete tables
- delete products
- delete orders
- delete uploads
- reset products/orders/uploads
- delete shop rows
- reset catalog/content demo data such as categories, pages, or other seeded text content
- rewrite full page bodies aggressively

If catalog, uploads, or order cleanup is required later, use a separate dedicated reset workflow such as a future `coremarket:reset-client-data` command.

## Client-Specific Setup Stays In `setup-instance`

The baseline must stay generic.

Client-specific values must be applied later through:

```bash
php artisan coremarket:setup-instance {instance_id} --dry-run
```

That includes:

- store name
- domain
- support email
- contact information
- safe shop branding fields for the existing baseline shop row when present
- runtime plan
- store mode
- client media assignment later

## Test Safety

CoreMarket tests must never run against a runtime database.

Tests are allowed only against:

- `coremarket_testing`
- or any database name containing `_testing`

If tests are pointed at a runtime database such as:

- `coremarket`
- `core_market`
- `coremarket_runtime`
- `syrian_souq`

the test bootstrap must fail with:

- `Refusing to run tests against non-testing CoreMarket database`

If `coremarket_testing` exists but does not contain the legacy schema expected by command-level tests, inspect it with:

```bash
php artisan coremarket:testing-database-status
```

If the command reports missing tables like `shops`, `currencies`, `languages`, or `roles`, prepare `coremarket_testing` from the private baseline SQL before expecting full legacy command tests to run.

Use:

```bash
php artisan coremarket:restore-testing-database --dry-run
php artisan coremarket:restore-testing-database --apply --confirm-testing-db-restore
```

This workflow is local-only, targets `_testing` databases only, and must never touch `coremarket_runtime`.

## Never Do This On Legacy Runtime Databases

- `php artisan migrate:fresh`
- `php artisan db:wipe`
- destructive cleanup shortcuts
- shared runtime/test database usage
