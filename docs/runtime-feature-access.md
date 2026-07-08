# CoreMarket Runtime Feature Access

## Purpose

CoreMarket is not the commercial plan manager.

CorePilotOS remains the source of truth for:

- plan catalog
- pricing
- subscriptions
- renewals
- activation and suspension decisions

CoreMarket only consumes an applied runtime context and enforces:

- feature access
- store mode behavior
- runtime limits

## Supported Applied Plan Codes

- `starter`
- `business`
- `marketplace`
- `enterprise`

Legacy compatibility maps `ecommerce_starter` to `starter`.

## Supported Store Modes

- `single_store`
- `marketplace`
- `owned_coremarket_store`

## Runtime Feature Keys

- `multi_vendor`
- `sellers`
- `pos`
- `blog`
- `marketing_basic`
- `marketing_advanced`
- `support_basic`
- `support_advanced`
- `staff_management`
- `reports_basic`
- `reports_advanced`
- `uploads_manager`
- `loyalty_points`
- `website_appearance`
- `website_pages_limited`
- `translations_limited`
- `currencies_limited`
- `addon_requests`
- `subscription_page`

## Runtime Limits

- `products_limit`
- `monthly_orders_limit`
- `staff_limit`
- `sellers_limit`
- `storage_mb_limit`
  - uploaded media and images allowance for the store
  - includes measurable storefront uploads such as product images, banners, shop assets, and similar stored media
  - does not mean PHP memory limit or server RAM

## Resolution Order

Applied plan currently resolves from:

1. `COREMARKET_APPLIED_PLAN_CODE`
2. license config fallback
3. `COREMARKET_PLAN_CODE`
4. legacy config fallback
5. safe default `starter`

Store mode currently resolves from:

1. `COREMARKET_STORE_MODE`
2. `COREMARKET_LICENSE_STORE_MODE`
3. plan default store mode
4. safe default `single_store`

## Reuse and Scope

This foundation extends the existing CoreMarket feature/license layer.

- `CoreMarketFeatureService` remains available for existing callers
- `CoreMarketLicenseService` continues to own runtime license state
- `EnsureCoreMarketFeature` and `EnsureCoreMarketLicense` stay in place

This step does not yet:

- gate new routes
- manage subscriptions
- manage pricing
- implement billing
- implement payment gateways
- implement loyalty behavior
- refactor sidebars

The managed instance setup command consumes this matrix to preview and apply runtime plan and store mode values without turning CoreMarket into the commercial plan manager.

## CorePilotOS Runtime Snapshot Receiver

CoreMarket can now receive a runtime snapshot from CorePilotOS through dedicated internal API endpoints:

- `POST /api/corepilot/runtime-snapshot/preview`
- `POST /api/corepilot/runtime-snapshot/apply`

Authentication rules:

- a dedicated sync token is required
- the token is read from `COREPILOT_RUNTIME_SYNC_TOKEN`
- requests may send it using `X-CorePilot-Sync-Token`
- missing token returns `401`
- invalid token returns `403`

Payload scope:

- `status`
- `applied_plan`
- `store_mode`
- `features`
- `limits`
- safe `store` metadata
- safe `support` metadata

Preview behavior:

- validates and normalizes the payload
- returns the normalized runtime snapshot
- writes nothing

Apply behavior:

- validates and normalizes the payload
- stores runtime-only snapshot keys in `business_settings`
- updates mapped legacy runtime toggles such as vendor mode and wallet mode when needed
- does not touch products, orders, payments, or customer data

Runtime resolution now prefers the persisted CorePilotOS-applied snapshot before env/config fallbacks.

## Limited Localization Controls

Client-facing localization controls should stay intentionally narrow.

- `translations_limited` enables a safe admin page for editing translation values only for languages already enabled by owner/admin users.
- `currencies_limited` enables a safe admin page for updating exchange rates only for currencies already enabled by owner/admin users.
- Store admin users must not add, delete, activate, or deactivate languages or currencies from these limited pages.
- Default language and default currency remain owner/admin responsibilities through the existing full setup pages.

## Admin Navigation Gating

Admin navigation should reuse the same runtime feature matrix instead of hardcoded client-specific menu forks.

- Sidebar visibility must follow runtime features such as `sellers`, `pos`, `blog`, `marketing_basic`, `marketing_advanced`, `support_basic`, `support_advanced`, `reports_basic`, `reports_advanced`, `uploads_manager`, `staff_management`, and `addon_requests`.
- Owner-only areas such as activation, system, unsafe setup pages, and internal configuration surfaces must remain hidden from `store_admin`.
- Route access must not rely on sidebar hiding alone. Disabled sections should return a safe `404` through the existing CoreMarket feature middleware when the runtime feature is off.

## Activation Control Center

`/admin/activation` is an internal CoreMarket control overview for owner/admin support users.

It should be treated as a read-only runtime dashboard that shows:

- license status
- applied plan
- store mode
- enabled runtime features
- runtime limits
- domain and support context
- setup readiness notes

Store admin or client admin users must not access this page.

The page should not be used as a legacy toggle surface for:

- environment writes
- vendor/demo activation
- payment activation
- install/update callbacks

Those changes must flow through managed setup tooling or future CorePilotOS-applied runtime context instead.

## Client Subscription Overview

`/admin/my-subscription` is the client-visible runtime overview for store admins and owner/admin support users.

It should stay read-only and should not become a billing console inside CoreMarket.

The page may show:

- applied plan
- store mode
- license status
- enabled features
- disabled features
- runtime limits
- safe usage counters such as products, monthly orders, and uploads count

The page should also remind client users that:

- CorePilotOS manages subscriptions, pricing, activation, and upgrades
- CoreMarket only reflects the applied runtime access snapshot
- upgrade or activation requests should go through support

## Add-on Request Catalog

`/admin/addons` is a request-only catalog in the managed CoreMarket baseline.

It must not behave like a code installer or external vendor marketplace.

Safe behavior:

- list managed add-on capabilities such as sellers, POS, marketing, support, reports, and payment setup
- show whether a capability is enabled in the current runtime snapshot
- show whether the current applied plan can include it or requires an upgrade
- link the user to support or WhatsApp for activation requests
- link back to `/admin/my-subscription` for plan and limit review

Unsafe legacy behavior that must stay disabled:

- zip upload and code installation
- direct addon activation toggles
- external vendor activation callbacks
- purchase-code driven install flows
- SQL execution from addon packages
