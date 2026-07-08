# CoreMarket Local QA Store Seed

## Purpose

This tooling creates or updates a local-only QA storefront dataset for end-to-end order testing.

It is not a client baseline.
It is not the official demo/testing SQL baseline.
It is not a production seed.
It must not be used for any client instance.

For baseline restoration workflows:

- `database/base/coremarket.sql` stays the clean client baseline
- `database/base/coremarket_test.sql` stays the fake demo/testing baseline

## Command Usage

Dry-run:

```bash
php artisan coremarket:seed-qa-store --dry-run
```

Apply:

```bash
php artisan coremarket:seed-qa-store --apply --confirm-qa-seed
```

Optional local-only password override:

```bash
php artisan coremarket:seed-qa-store --apply --confirm-qa-seed --password="qa-coremarket-local"
```

## What the Command Creates or Updates

- `QA CoreMarket Customer`
- `QA CoreMarket Store Admin`
- `QA CoreMarket Category`
- `QA CoreMarket Sample Product`
- product stock for the QA sample product
- required QA checkout settings:
  - `cash_payment = 1`
  - `vendor_system_activation = 0`
  - `wallet_system = 0`
  - `show_website_popup = 0`
  - `show_cookies_agreement = 0`

The command is idempotent and uses stable QA identifiers so it can be run repeatedly without duplicating data.

## Local QA Flow

Use the seeded data for this local functional flow:

1. Home
2. Product
3. Cart
4. Login
5. Checkout
6. COD Order
7. Admin Order View

## QA Credentials

The command uses a local-only QA password.

Default:

```text
qa-coremarket-local
```

Accounts:

- `qa.customer.coremarket@example.test`
- `qa.storeadmin.coremarket@example.test`

Do not reuse these credentials for any public or client environment.
