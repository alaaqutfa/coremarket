# CoreMarket Managed Client Instance Launch Plan

## Purpose

This document defines the exact launch plan for creating a clean managed client instance from the CoreMarket baseline.

It must be used with the clean baseline strategy in
[docs/clean-baseline-database-strategy.md](/C:/xampp/htdocs/coremarket/docs/clean-baseline-database-strategy.md).

Do not use the current legacy/demo local database as a client baseline.

## Managed Service Model

Each client instance is managed by us.

The client receives:

- Store Admin access only
- product management
- order management
- basic storefront settings access appropriate for the plan

The client does not receive:

- source code
- server access
- database access
- Super Admin access
- payment gateway setup in the starter offer
- CorePilotOS connector access at this stage

## Required Instance Isolation

Every client-ready instance must have:

- a separate database
- a separate `.env`
- a separate uploads/media path
- a separate domain or subdomain
- a separate Store Admin account
- a separate license snapshot/config

Never point a client instance at the shared demo database or shared demo uploads.

## Client Instance Configuration Checklist

Prepare the following before launch:

- instance ID
- domain or subdomain
- store name
- store logo files
- store colors/brand guidance
- contact phone
- WhatsApp number
- contact email
- contact address
- timezone
- default currency
- Store Admin name
- Store Admin email
- applied plan code: `starter`
- product limit: `50`
- monthly order limit: `300`
- COD/manual order preference

## Clean Baseline Source

Build each client instance from the clean managed baseline strategy, not from the current demo/legacy development database.

The baseline should provide:

- working schema compatible with the current application
- platform owner admin access
- `store_admin` role availability
- starter-safe `business_settings`
- vendor mode disabled
- popup disabled
- wallet disabled
- COD/manual payment enabled
- no old branding
- no demo products
- no old uploads

## Exact Launch Steps

1. Start from the approved CoreMarket code version in Git.
2. Prepare a dedicated clean database from the managed baseline artifact.
3. Create an instance-specific `.env` outside Git.
4. Set CoreMarket license values for the instance:
   - `COREMARKET_LICENSE_ENABLED=true`
   - `COREMARKET_INSTANCE_ID`
   - `COREMARKET_LICENSE_DOMAIN`
   - `COREMARKET_APPLIED_PLAN_CODE=starter`
   - `COREMARKET_PLAN_CODE=starter`
   - `COREMARKET_LICENSE_STATUS=active`
   - `COREMARKET_LICENSE_EXPIRES_AT` if contract dates are already defined
5. Point the instance to its own uploads/media storage path.
6. Run `php artisan coremarket:setup-instance {instance_id} --dry-run` with the client-neutral setup inputs first.
7. Review the setup summary before any apply step.
8. Run the guarded apply step only after payment and internal approval.
9. Upload client logo/favicon/media outside Git.
10. Assign media upload IDs in `business_settings`.
11. Create or verify the Store Admin account using the managed workflow.
12. Confirm starter feature gates remain in effect.
13. Add initial categories and products.
14. Perform launch QA before exposing the domain publicly.

## Required `.env` Values Per Client Instance

At minimum:

- `APP_NAME`
- `APP_URL`
- database host, port, name, username, password
- mail settings if transactional mail is needed
- `COREMARKET_LICENSE_ENABLED`
- `COREMARKET_INSTANCE_ID`
- `COREMARKET_LICENSE_KEY`
- `COREMARKET_LICENSE_DOMAIN`
- `COREMARKET_APPLIED_PLAN_CODE`
- `COREMARKET_PLAN_CODE`
- `COREMARKET_LICENSE_STATUS`
- `COREMARKET_LICENSE_STARTS_AT`
- `COREMARKET_LICENSE_EXPIRES_AT`
- `COREMARKET_LICENSE_GRACE_UNTIL`

These values must stay out of Git.

## Required Business Settings Per Client Instance

At minimum configure:

- `website_name`
- `site_name`
- `site_motto`
- `meta_title`
- `meta_description`
- `contact_phone`
- `contact_email`
- `contact_address`
- `helpline_number`
- `frontend_copyright_text`
- `system_default_currency`
- `timezone`
- `cash_payment`
- `vendor_system_activation`
- `wallet_system`
- `show_website_popup`
- `show_cookies_agreement`

After uploads are available, also assign:

- `header_logo`
- `footer_logo`
- `site_icon`
- `system_logo_white`
- `system_logo_black`

## Store Admin Setup Rules

The client Store Admin must:

- use the `store_admin` role only
- manage products and orders
- manage safe visible store settings if enabled
- remain blocked from platform/system/env/payment/vendor/POS surfaces

The client Store Admin must not receive:

- platform owner access
- unrestricted admin role management
- source or file access
- server access
- database credentials

## Starter Plan Defaults

For the managed starter offer, keep:

- `applied_plan_code = starter`
- products limit = `50`
- monthly orders limit = `300`
- vendor mode disabled
- seller registration disabled
- seller panel disabled
- wallet disabled
- payment gateways disabled
- COD/manual ordering enabled
- mobile app links disabled
- POS disabled

## Product and Catalog Preparation

Before launch, prepare:

- final category structure
- up to 50 products
- stock quantities
- product images
- clean product descriptions
- published status only for client-approved products

Use either:

- manual admin entry
- controlled import template

Do not import legacy demo catalog content into the client instance.

## WhatsApp and Contact Setup

For starter instances:

- set `whatsapp_number` if supported by settings
- otherwise use `contact_phone` or `helpline_number` as the fallback source
- verify the WhatsApp CTA appears only when a number is present
- keep WhatsApp as a simple contact/order assist surface, not a full API integration

## COD and Manual Order Setup

Before launch, confirm:

- `cash_payment` is enabled
- online gateways remain hidden
- wallet payment remains hidden
- checkout messaging is clear for manual/COD flow
- order placement works without a payment gateway

## What Must Stay Out of Git

- client `.env`
- client database exports
- client uploads
- client logos
- favicon files
- contact lists
- temporary passwords
- production mail credentials
- production license keys

## Launch QA Checklist

Run the following before launch:

- homepage loads correctly
- dynamic store name appears correctly
- no old branding is visible
- categories and products render correctly
- product details page renders correctly
- cart works
- checkout works
- COD/manual order path is clear
- admin order visibility works
- Store Admin restrictions still hold
- WhatsApp CTA appears only when configured
- mobile responsive behavior is acceptable
- no seller/vendor/demo surfaces leak into the starter storefront

## What Remains Before Launch

The following are still operational tasks, not core-code tasks:

- prepare the clean baseline database artifact
- create the real client database
- create the real client `.env`
- upload logos and media
- create the real Store Admin credentials
- add categories/products
- run final order QA on the client instance
- connect the real domain and SSL during deployment

## Out of Scope for This Plan

This launch plan does not include:

- source code delivery
- payment gateway integration
- CorePilotOS connector setup
- mobile app setup
- ERP/POS rollout
- marketplace multi-vendor enablement
