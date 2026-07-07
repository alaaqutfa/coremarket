# CoreMarket Runtime Stabilization Readiness

## Scope

This note covers the current CoreMarket SaaS runtime layer only:

- readiness commands
- managed setup tooling
- runtime feature access
- license and limits UI
- store admin restrictions
- client-facing admin surfaces added for managed SaaS

It does not certify the current local database as a client-ready baseline.

## Commands Verified

The following checks are part of the runtime readiness pass:

```bash
php artisan coremarket:audit-baseline-readiness
php artisan coremarket:setup-instance --help
php artisan coremarket:setup-instance demo-starter --dry-run --store-name="Demo Starter" --domain="starter.example.test" --admin-email="starter@example.test" --admin-name="Starter Admin" --plan=starter --store-mode=single_store --currency=USD --language=English
php artisan coremarket:setup-instance demo-marketplace --dry-run --store-name="Demo Marketplace" --domain="market.example.test" --admin-email="market@example.test" --admin-name="Marketplace Admin" --plan=marketplace --store-mode=marketplace --currency=USD --language=English
php artisan route:list
php artisan test
```

## What Is Ready

- runtime plan and store mode resolution
- feature and limit access services
- setup-instance dry-run and guarded apply workflow
- store admin restriction middleware
- subscription overview page
- add-on request catalog page
- limited translations page for already-enabled languages
- limited currency rates page for already-enabled currencies
- read-only baseline readiness audit command
- automated test suite for the current runtime layer

## What Is Not Ready Yet

- current local database as a reusable client baseline
- clean white-label business settings by default
- clean storefront branding in database-driven content
- clean uploads/media inventory for client delivery
- owner-admin activation data synced from CorePilotOS
- production-grade payment gateway enablement
- production checkout certification on a clean client baseline

## UI Findings From The Stabilization Pass

### Fixed Now

- the generic `403` page no longer renders inside the storefront layout
- denied admin routes now show a neutral, standalone access-denied screen without popup, cookie, footer, or storefront clutter
- the access-denied copy was corrected from the legacy typo

### Acceptable For Now

- subscription and add-on pages are readable on desktop and mobile without horizontal overflow
- limited translations and currency pages render safely on mobile with narrow tables and no horizontal page overflow
- store admin sidebar active states are visible for subscription, add-ons, translations, and currency rates

### Left For Later

- page titles and many public-facing labels still reflect legacy database content such as old store names and domains
- activation owner page still depends on local/demo database values for some displayed context
- debug toolbar overlays can interfere with local visual inspection but are development-only and not part of the managed runtime deliverable

## Store Admin Access Status

Store admin is expected to:

- access `/admin/my-subscription`
- access `/admin/addons`
- access `/admin/website/translations` when `translations_limited` is enabled
- access `/admin/website/currency-rates` when `currencies_limited` is enabled

Store admin must not:

- access `/admin/activation`
- access owner-only setup/system pages
- add, delete, activate, or deactivate languages
- add, delete, activate, or deactivate currencies

## Requires Clean Baseline Database

The following remain blocked by the current legacy/demo database:

- client-ready default business settings
- neutral storefront content out of the box
- vendor disabled and wallet disabled in persisted baseline settings
- reliable white-label screenshots for sales or onboarding
- clean QA order verification without legacy branding noise

Use the clean baseline database strategy before selling or launching managed client instances from a fresh database.

## Requires CorePilotOS Connector Later

These items should remain outside CoreMarket for now and be applied later from CorePilotOS:

- commercial plan catalog
- pricing
- renewals
- billing
- subscription activation and suspension source of truth
- client lifecycle automation

CoreMarket should continue to enforce runtime access only from an applied snapshot.

## Should Not Be Sold Yet

Do not sell the current local database as a ready-to-launch client instance.

Do not present the current local storefront as a polished white-label demo without first cleaning:

- business settings
- legacy domains and contact data
- old storefront copy
- baseline media
- managed baseline feature flags persisted in database

## Recommended Next Operational Step

Before any real client onboarding:

1. finalize the clean baseline database strategy
2. build or import a sanitized managed baseline database
3. rerun `coremarket:audit-baseline-readiness`
4. run `coremarket:setup-instance --dry-run` for the target plan and mode
5. validate storefront and admin QA again on that clean baseline
