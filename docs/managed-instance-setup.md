# CoreMarket Managed Instance Setup

## Purpose

This project supports managed white-label instances without hardcoding client data into the core codebase.

## Core Code vs Instance Config

- Core code:
  reusable application logic, feature gates, license guard, roles, limits, routes, and views
- Instance config:
  `.env`, `business_settings`, roles/users created for the client instance, and uploaded media
- Client content:
  logos, favicons, product images, product data, contact details, and store copy

## What Goes in `.env`

Use `.env` for instance-specific technical values only:

- `APP_NAME`
- `APP_URL`
- database connection values
- mail settings
- CoreMarket license values:
  - `COREMARKET_LICENSE_ENABLED`
  - `COREMARKET_INSTANCE_ID`
  - `COREMARKET_LICENSE_KEY`
  - `COREMARKET_LICENSE_DOMAIN`
  - `COREMARKET_PLAN_CODE`
  - `COREMARKET_LICENSE_STATUS`
  - `COREMARKET_LICENSE_STARTS_AT`
  - `COREMARKET_LICENSE_EXPIRES_AT`
  - `COREMARKET_LICENSE_GRACE_UNTIL`

Example:

```bash
APP_NAME="Client Store"
APP_URL=https://example-store.com
COREMARKET_LICENSE_ENABLED=true
COREMARKET_INSTANCE_ID=client-store
COREMARKET_LICENSE_DOMAIN=example-store.com
COREMARKET_PLAN_CODE=ecommerce_starter
COREMARKET_LICENSE_STATUS=active
```

## What Goes in `business_settings`

Use `business_settings` for the public white-label surface:

- `website_name`
- `site_motto`
- `meta_title`
- `meta_description`
- `contact_address`
- `contact_phone`
- `contact_email`
- `helpline_number`
- `frontend_copyright_text`
- `vendor_system_activation`
- `wallet_system`
- `cash_payment`
- `system_default_currency`
- `timezone`

Media-related keys such as `site_icon`, `header_logo`, `footer_logo`, `system_logo_white`, and `system_logo_black`
must be assigned after the related files are uploaded and their upload IDs are known.

Legacy storefront values in `business_settings` must be reviewed before any client handoff.
Old store names, demo links, popup copy, watermark text, and marketing links should never be carried into a managed client instance unchanged.

## What Must Stay Out of Git

- `.env`
- secrets and license keys
- client logos and favicon files
- uploaded media under `public/uploads`
- SQL dumps
- client product import files containing real data
- temporary passwords

Clean baseline database strategy is documented separately in
[docs/clean-baseline-database-strategy.md](/C:/xampp/htdocs/coremarket/docs/clean-baseline-database-strategy.md).
Use that document when deciding how a new managed instance database is created before this setup flow is applied.

## Dry-Run Setup Command

Use the generic setup planner:

```bash
php artisan coremarket:setup-instance client-store --dry-run --store-name="Client Store" --domain=example-store.com --admin-email=owner@example-store.com
```

Current behavior:

- builds a setup plan only
- does not write to the database
- does not modify `.env`
- does not create users
- does not upload media

## Safe Apply Command

`--apply` can now update only the allowed `business_settings`, but only when all safety conditions are met.

Example:

```bash
php artisan coremarket:setup-instance client-store --apply --confirm-instance-setup --store-name="Client Store" --domain=example-store.com --admin-email=owner@example-store.com --site-motto="Managed Store" --contact-phone="+10000000000"
```

Apply safety rules:

- `--apply` must be present
- `--confirm-instance-setup` must be present
- `instance_id` must be provided
- `--store-name`, `--domain`, and `--admin-email` must be provided
- the command prints the full summary before writing

What the command writes in apply mode:

- only the safe `business_settings` from the setup map
- no destructive deletes
- no logo upload IDs
- no payment credentials

What the command does not write:

- `.env`
- media or uploads
- products
- orders
- payment gateway secrets

## Storefront Cleanup Audit Command

Use the generic storefront cleanup planner when the baseline still contains legacy branding or demo links in `business_settings`:

```bash
php artisan coremarket:clean-storefront-settings --dry-run
```

Apply mode is intentionally guarded:

```bash
php artisan coremarket:clean-storefront-settings --apply --confirm-storefront-cleanup
```

Cleanup scope:

- safe storefront `business_settings` only
- popup marketing defaults
- public meta and store-name defaults
- legacy demo links in menu/footer/link settings

Cleanup does not touch:

- products
- orders
- users
- shops
- uploads
- logo upload IDs
- media files

## Demo Data Warning

The current local development database should be treated as legacy/demo-contaminated unless it has already passed a dedicated cleanup step.

Before using any database as a managed-instance baseline:

- review `business_settings` for old store names, demo links, popup content, and cookie text
- review products, brands, categories, flash deals, and homepage-linked content
- avoid direct hard-delete cleanup when catalog relations are unclear

See [docs/demo-data-cleanup.md](/C:/xampp/htdocs/coremarket/docs/demo-data-cleanup.md) for the audit and cleanup planning guidance.

## Store Admin Handling

Optional flag:

```bash
--create-store-admin
```

Current status:

- still preview-only in this step
- requires `--apply` and `--confirm-instance-setup`
- requires `--admin-name` and `--admin-email`
- does not create a real user yet
- does not generate or print a password
- final user creation should happen only after payment and manual password handoff approval

## Media Uploads Later

Client logos, favicon, and branded images should be:

1. uploaded through the managed environment
2. stored outside Git
3. linked through `business_settings` upload IDs

## Product Import Later

Suggested template columns:

- `name`
- `description`
- `category_name`
- `brand_name`
- `unit_price`
- `current_stock`
- `unit`
- `sku`
- `image_filename`
- `tags`
- `published`

## Managed Hosting Checklist

1. Pull the approved repo version on the managed server.
2. Prepare an instance-specific `.env`.
3. Create a dedicated database.
4. Run `composer install --no-dev --optimize-autoloader`.
5. Run `php artisan key:generate` if needed.
6. Run `php artisan storage:link`.
7. Upload media outside Git.
8. Apply white-label `business_settings`.
9. Create the Store Admin account.
10. Verify route list, homepage, login, branding, product flow, and checkout flow.

## Before Client Payment

- run `dry-run` only
- do not create an instance
- do not create a Store Admin
- do not upload assets
- do not deploy

## After Client Payment

- prepare the instance `.env` outside Git
- create the dedicated database
- run the setup command with `--apply --confirm-instance-setup`
- run storefront cleanup or verify the cleanup plan before exposing the instance publicly
- upload logos and media outside Git
- create or verify the Store Admin account
- test homepage, login, product flow, and order flow
