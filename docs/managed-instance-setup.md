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

## What Must Stay Out of Git

- `.env`
- secrets and license keys
- client logos and favicon files
- uploaded media under `public/uploads`
- SQL dumps
- client product import files containing real data
- temporary passwords

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

`--apply` is intentionally blocked for now and still runs as dry-run.

## Store Admin Creation Later

During actual configuration:

- create a `users` row with `user_type=staff`
- create the linked `staff` row
- assign the `store_admin` role
- provide a temporary password outside Git
- change password on first handover if the project later adds that flow

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
