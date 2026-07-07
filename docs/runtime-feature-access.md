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
