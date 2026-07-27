# CoreMarket Pricing Rules

## Price meanings

- **Regular price** is the official public selling price stored as the product or stock unit price.
- **Sale price** is a temporary promotion derived from the existing discount value, type, and active dates.
- **Price list price** is a customer-specific price from an active assigned list such as Wholesale A/B/C or VIP.
- A price list never replaces or redefines the sale price.

## Storefront display

- Guests see the active sale price, otherwise the regular price.
- Logged-in customers without an assigned price list follow the same public-price rule.
- Customers with an assigned active price list receive their resolved customer price only when `pricing.price_lists_enabled` is enabled.
- When the feature is disabled, all storefront customers fall back to sale/regular pricing. Existing lists and assignments are retained.
- Supported priority modes are `customer_price_first`, `sale_price_first`, and `lowest_price`. The default is `customer_price_first`.
- Storefront labels use neutral wording such as `Your Price`, `Sale`, and `Regular Price`; list names do not need to be exposed.

## Safety and consistency

- Product/catalog records may be cached, but a resolved customer price must be calculated per request and must never be stored in a shared public cache.
- Only authenticated users with `user_type=customer` are eligible for customer-specific display pricing.
- The display helper returns normalized money with no more than two decimal places.
- Web cart and checkout continue to resolve pricing on the server. Storefront display is informative; checkout remains the source of truth for the final price.
- Branch-specific pricing is not implemented in Step 62. Branch policy remains unified until a later implementation is approved.
