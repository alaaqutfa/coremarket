# CoreMarket Branding Cleanup Notes

## Static Defaults

The core repository should use `CoreMarket` only as a generic fallback brand in visible static text.

Safe code-level branding surfaces include:

- installation and update screens
- demo helper screens
- generic admin help text
- `.env.example` defaults
- generic documentation

## Instance-Driven Branding

Client-specific branding must stay out of the core codebase and be applied per instance through `business_settings`.

Hardcoded client/store names are not allowed in core code. Store-facing naming should resolve dynamically from:

1. `website_name`
2. `site_name`
3. `config('app.name')`
4. `CoreMarket` as the final fallback only

Recommended branding keys to configure per instance:

- `website_name`
- `site_motto`
- `meta_title`
- `meta_description`
- `header_logo`
- `footer_logo`
- `site_icon`
- `system_logo_white`
- `system_logo_black`
- `frontend_copyright_text`
- `contact_phone`
- `contact_email`
- `contact_address`
- social links such as Facebook, Instagram, X/Twitter, YouTube, LinkedIn, and WhatsApp when supported

`business_settings` are also the main source of storefront contamination in legacy imports. Before any managed instance goes live, review:

- public store-name keys
- meta title and description
- footer and copyright text
- header/footer/demo links
- popup and cookie settings
- watermark text
- app/store download links

## Legacy Names Purged or Neutralized

Visible legacy names should not appear in the managed baseline:

- `Group Coin`
- `groupcoin`
- `Syrian Souq`
- `SyrianSouq`
- `Syrian-Souq`
- `الشاهين`
- `الشاهين للتسوق`
- `activeitzone`
- `Active IT Zone`
- `codecanyon`
- `CodeCanyon`
- `Active eCommerce`
- `Active eCommerce CMS`

Any remaining `AIZ` references are technical namespaces only and should be treated as implementation detail unless a future refactor proves they can be renamed safely.

## Assets Requiring Manual Replacement Later

These assets may still contain legacy visual branding and should be replaced only when official generic or client-specific assets are ready:

- `public/assets/img/logo.png`
- `public/assets/img/app.png`
- `public/assets/img/demo/link/logo.svg`
- demo thumbnails under `public/assets/img/demo/`

Do not replace uploaded instance logos through Git. Those must be uploaded per instance and linked through `business_settings`.

## White-Label Setup Reminder

- Configure public naming through `business_settings`, not core code.
- Keep client logos, favicons, and media outside Git.
- Use `CoreMarket` only when no instance-specific store name has been configured yet.
- Disable or replace old popup marketing content before public launch.
- Remove demo/store links that point to previous brands or environments.
- Replace media/logo upload IDs per instance instead of reusing legacy upload references.

## Visual Identity Note

CoreMarket visual identity should align with the CorePilotOS family, but with a commerce-focused storefront feel:

- SaaS modern
- product-card friendly
- cart and checkout clarity
- commerce-friendly CTAs
- not a dashboard-only technical look
- clean default spacing and restrained accent usage
- white-label ready defaults that client instances can override through settings

## Current Core Visual Priorities

Safe visual cleanup in core should focus on:

- generic fallback typography and spacing
- clearer storefront and checkout guidance surfaces
- reusable CTA styling for order/contact actions
- removing demo-like visual clutter from default public views

Defer these until official assets or instance requirements exist:

- final CoreMarket logo
- favicon set
- admin white/black logos
- frontend header and footer logo artwork
- store hero and promotional placeholder artwork
- mobile app promo artwork if that feature remains enabled for future plans
