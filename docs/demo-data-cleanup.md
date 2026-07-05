# CoreMarket Demo Data Cleanup Plan

## Current State

The current local database is suitable for development and inspection, but it is not a safe client-facing baseline.

Read-only audit findings show:

- legacy storefront branding is still present in `business_settings`
- demo product content is still present in `products`
- homepage links still point to old demo or legacy domains
- popup and cookie-driven first-impression content is still configured in the database
- catalog relationships are incomplete or inconsistent in several places

This means the current database should not be reused directly for a managed client instance without a controlled cleanup pass.

## Why Direct Cleanup Is Risky

The current dataset has structural issues that increase cleanup risk:

- `products` contains a large published catalog with legacy content
- `categories` is empty while products still reference category IDs
- most products reference brand IDs that do not exist in `brands`
- `carts` still reference some products
- there are no protective soft-delete columns on key catalog tables
- there are no useful foreign keys enforcing safe relational cleanup in the core catalog tables

Because of this, hard deletion is not the preferred first move.

## Safe Actions vs Dangerous Actions

Safe actions for a later cleanup step:

- update storefront `business_settings`
- disable or neutralize popup and promo settings
- replace old demo links in footer, menus, and homepage link settings
- unpublish demo products when the affected products are confirmed safe to hide
- create new neutral categories and curated baseline products in a controlled baseline database
- replace logo and media IDs only after approved assets are uploaded

Dangerous actions that should not happen without a dedicated cleanup execution step:

- deleting products in bulk
- deleting uploads in bulk
- deleting shops or users tied to legacy data
- changing catalog records without checking cart or order references
- relying on current migrations alone to rebuild the same working dataset

## Recommended Strategy

Recommended approach: keep the current database as a contaminated dev/reference dataset and prepare a cleaner baseline separately.

Practical options:

- Preferred now:
  keep this database for development and auditing only, then prepare a controlled baseline database or import set for future managed instances
- Possible later:
  run a guided cleanup command or script that only updates safe storefront settings and selectively unpublishes validated demo products
- Avoid as a first move:
  hard-delete catalog data from the current database

For the clean managed-instance database plan, see
[docs/clean-baseline-database-strategy.md](/C:/xampp/htdocs/coremarket/docs/clean-baseline-database-strategy.md).

## Storefront Cleanup Scope

The following database-driven surfaces should be cleaned before any public handoff:

- `website_name`
- `site_name`
- `site_motto`
- `meta_title`
- `meta_description`
- `frontend_copyright_text`
- `contact_email`
- `contact_phone`
- `contact_address`
- `header_menu_links`
- `widget_one_links`
- `topbar_banner_link`
- `home_slider_links`
- `home_banner1_links`
- `home_banner2_links`
- `home_banner3_links`
- popup-related settings
- cookie-agreement text and behavior

Media upload IDs should be reviewed separately after approved assets exist.

## Product and Catalog Cleanup Guidance

For the current dataset:

- do not delete demo products blindly
- inspect cart references before hiding products
- prefer `unpublish` or equivalent visibility controls over delete where possible
- treat alcohol, legacy-branded, and domain-linked products as likely demo-content cleanup candidates
- review flash deals, homepage banners, and popup content together with product visibility

## QA Accounts Plan

For future functional QA, create local-only test accounts outside Git:

- one customer QA account
- one `store_admin` QA account
- optional one platform-owner QA account if isolated testing is needed

Guidelines:

- do not store passwords in Git
- clearly label accounts as local QA only
- create them through a dedicated command or manual checklist in a future step

## End-to-End Order QA Prerequisites

A realistic end-to-end order QA pass later should require:

- at least one published in-stock product
- a clean visible category path
- a customer QA account or guest-checkout path
- shipping and city settings that allow checkout
- COD or manual order enabled
- verified Store Admin access to orders

Expected test journey:

1. Homepage
2. Product list
3. Product details
4. Cart
5. Login or register
6. Checkout
7. COD or manual order placement
8. Admin order visibility
9. Store Admin order visibility

## Rule for Future Managed Instances

Do not use the current local demo-contaminated database as the default source for new client instances.

Before any client setup:

1. clean or replace storefront settings
2. validate catalog quality
3. confirm safe media replacements
4. create QA accounts
5. run an end-to-end order QA pass
