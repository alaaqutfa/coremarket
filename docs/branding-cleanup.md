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

## Assets Requiring Manual Replacement Later

These assets may still contain legacy visual branding and should be replaced only when official generic or client-specific assets are ready:

- `public/assets/img/logo.png`
- `public/assets/img/app.png`
- `public/assets/img/demo/link/logo.svg`
- demo thumbnails under `public/assets/img/demo/`

Do not replace uploaded instance logos through Git. Those must be uploaded per instance and linked through `business_settings`.
