# CoreMarket Clean Baseline Database Strategy

## Purpose

CoreMarket needs a reusable managed-instance baseline that is safe for future client setups.

The current local database is not that baseline. It remains useful for development, route coverage, and legacy inspection, but it should not be copied into a new client instance unchanged.

## Current Schema Risk

The repository and the live database are significantly out of sync:

- the repository currently contains only 4 migration files
- `php artisan migrate:status` reports additional pending migrations that are not represented as a complete schema history
- the live database currently contains 96 tables
- the live `users` table already has many columns that do not exist in the tracked `create_users_table` migration

This means a fresh database built from tracked migrations alone would not match the working application schema.

## Baseline Strategy Options

### Option A: Migrations Only

Use only the tracked migrations to build a fresh client baseline.

Pros:

- cleanest Git-native workflow
- easy to reason about in principle

Cons:

- not viable with the current repository state
- tracked migrations do not cover the live application schema
- core tables such as `business_settings`, catalog, checkout, roles, payments, and geo tables would be missing

Recommendation:

- not recommended now

### Option B: Sanitized SQL Baseline

Build a clean database once, then export a sanitized SQL baseline and reuse it for future instances.

Pros:

- can preserve the real working schema
- avoids depending on incomplete migrations
- practical for managed hosting

Cons:

- SQL baselines drift unless actively maintained
- SQL files are easy to contaminate with legacy data if generated carelessly
- large SQL artifacts should not live in the public Git repository

Recommendation:

- viable, but should not be the only control layer

### Option C: Cleanup Current Database In Place

Take the current local database and remove or disable legacy/demo content until it becomes the baseline.

Pros:

- no need to reconstruct missing schema

Cons:

- highest contamination risk
- current dataset contains legacy storefront settings, demo links, legacy products, stale carts, and inconsistent catalog relations
- cleanup mistakes could damage useful development reference data

Recommendation:

- not recommended as the primary baseline path

### Option D: Hybrid Controlled Baseline

Use the live working schema as the source of truth, create a new isolated clean baseline database once, seed only the minimal managed-instance data, then export a sanitized baseline artifact outside Git.

Pros:

- preserves the real application schema
- avoids relying on incomplete migrations
- avoids reusing the contaminated demo database directly
- works well with the existing managed-instance setup commands and docs

Cons:

- requires an explicit one-time baseline build process
- needs private handling for the exported SQL or database artifact

Recommendation:

- recommended now

## Recommended Approach

Use a **hybrid controlled baseline**:

1. treat the current live database as a schema reference, not as a client baseline
2. create a new clean database in an isolated local environment later
3. carry over the required schema
4. insert only minimal baseline data
5. export the sanitized baseline as a private artifact outside Git
6. apply instance-specific settings later with the existing `coremarket:setup-instance` workflow

## Required Table Groups

These tables should exist in a clean managed baseline schema, even when many of them start nearly empty.

### Core Auth and Access

- `users`
- `password_resets`
- `personal_access_tokens`
- `roles`
- `permissions`
- `model_has_roles`
- `role_has_permissions`
- `staff`

### Storefront and Instance Settings

- `business_settings`
- `pages`
- `page_translations`
- `languages`
- `currencies`
- `app_translations`
- `translations`

### Geography and Addressing

- `countries`
- `states`
- `cities`
- `zones`
- `addresses`

### Catalog Core

- `products`
- `product_translations`
- `product_stocks`
- `product_taxes`
- `categories`
- `category_translations`
- `product_categories`
- `brands`
- `brand_translations`
- `attributes`
- `attribute_values`
- `attribute_category`
- `colors`
- `uploads`

### Orders and Checkout

- `carts`
- `combined_orders`
- `orders`
- `order_details`
- `payments`
- `transactions`
- `payment_methods`
- `coupon_usages`
- `coupons`

### Customer-Facing Supporting Tables

- `reviews`
- `wishlists`
- `searches`
- `subscribers`
- `tickets`
- `ticket_replies`

## Optional or Legacy-Heavy Tables

These tables can remain present in the schema for compatibility, but they do not need baseline data for the starter single-store managed instance.

### Vendor and Marketplace Surfaces

- `sellers`
- `shops`
- `follow_sellers`
- `seller_withdraw_requests`
- `commission_histories`

### Customer Posting and Package Systems

- `customer_products`
- `customer_product_translations`
- `customer_packages`
- `customer_package_translations`
- `customer_package_payments`
- `user_coupons`

### Wallet, Delivery, and Pickup Extensions

- `wallets`
- `carriers`
- `carrier_ranges`
- `carrier_range_prices`
- `pickup_points`
- `pickup_point_translations`

### Marketing and Optional Content

- `flash_deals`
- `flash_deal_products`
- `flash_deal_translations`
- `dynamic_popups`
- `custom_alerts`
- `blogs`
- `blog_categories`
- `firebase_notifications`

### Communication and Admin Extras

- `conversations`
- `messages`
- `notifications`
- `notification_types`
- `notification_type_translations`
- `addons`

## Minimal Baseline Data

The clean baseline should start with data only where it is required for bootstrapping the storefront and admin surface.

### Users and Admin Access

- one platform owner admin user in `users`
- one matching `staff` record if the admin area expects staff linkage
- `roles`, `permissions`, `role_has_permissions`, and `model_has_roles` populated enough to support:
  - platform owner access
  - `store_admin` role availability

### Business Settings

At minimum:

- `website_name`
- `site_name`
- `site_motto`
- `meta_title`
- `meta_description`
- `contact_phone`
- `contact_email`
- `contact_address`
- `system_default_currency`
- `timezone`
- `cash_payment = 1`
- `vendor_system_activation = 0`
- `wallet_system = 0`
- `show_website_popup = 0`
- `show_cookies_agreement` set intentionally for the baseline policy
- empty or neutral values for menu/footer/demo-link settings

### Currency, Language, and Geography

At minimum:

- one active default currency
- one active default language
- country/state/city data kept only to the extent required by checkout and address flows

### Payment Methods

- payment method rows may stay present for compatibility
- online gateways should remain inactive for the starter baseline
- COD/manual order flow should remain available through settings and feature gates

### Catalog and Media

- no products
- no legacy categories
- no legacy brands
- no legacy uploads
- no client logo upload IDs

## What Stays Out of Git

- `.env`
- client credentials and license keys
- exported client-specific SQL
- sanitized baseline SQL if it contains environment-specific secrets or operational data
- uploaded media
- client logos and favicon files
- temporary QA passwords

If a reusable sanitized SQL baseline is created later, keep it as a private operational artifact, not as a normal tracked repository file.

## Practical Build Sequence for a Future Step

1. create a brand new empty database outside the contaminated local demo DB
2. reconstruct the required schema from the live working schema reference
3. insert only the minimal baseline data listed above
4. verify homepage, login, admin dashboard, product creation, and starter checkout flow
5. export a sanitized private baseline artifact
6. use `coremarket:setup-instance` to apply instance-specific settings later

## Current Decision

Until a dedicated baseline build step is executed:

- do not use the current demo/legacy database as a client baseline
- do not rely on migrations alone to create a new managed instance database
- prefer a controlled hybrid baseline artifact derived from the live working schema

## Read-Only Readiness Audit

Use the baseline readiness audit command to inspect the current database without writing any data:

```bash
php artisan coremarket:audit-baseline-readiness
```

The command reports:

- core table counts
- required baseline setting presence
- vendor, wallet, popup, and cash/manual payment status
- legacy branding warnings
- schema drift between tracked migrations and the live database

The command is read-only and does not modify the database.
