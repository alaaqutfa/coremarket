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
php artisan route:list
```

Recommended order:

1. import `database/base/coremarket.sql` into a local runtime database
2. point `.env` to that runtime database
3. run `coremarket:guard-database`
4. run `coremarket:clean-baseline --dry-run`
5. if the preview is correct, run `coremarket:clean-baseline --apply --confirm-clean-baseline`
6. rerun `coremarket:audit-baseline-readiness`
7. use `coremarket:setup-instance` later to make the store client-specific

## What `clean-baseline` Does

`coremarket:clean-baseline` is a safe baseline-neutralization workflow for `business_settings` only.

It:

- neutralizes old store/client branding in `business_settings`
- disables unsafe starter-incompatible baseline flags such as popup and vendor mode
- keeps the baseline generic for later client setup

It does not:

- delete tables
- delete products
- delete orders
- delete uploads
- reset shop/catalog runtime data

If catalog or upload cleanup is required later, use a separate dedicated reset workflow.

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

## Never Do This On Legacy Runtime Databases

- `php artisan migrate:fresh`
- `php artisan db:wipe`
- destructive cleanup shortcuts
- shared runtime/test database usage
