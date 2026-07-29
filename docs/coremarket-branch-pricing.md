# CoreMarket Branch Pricing

## Scope

Step 70 adds optional branch-specific selling prices without replacing public product prices, temporary Sale prices, or customer Price Lists. Branch Inventory, stock transfers, and accounting are unchanged.

The implementation uses `product_branch_prices` rather than another Price List hierarchy. A branch price is a direct override for one sellable product stock/variant at one branch, while the existing Price Lists remain customer assignments such as Wholesale A/B/C and VIP. The branch and product-stock pair is unique to prevent duplicate active definitions.

## Feature Settings

- `pricing.branch_pricing_enabled=false`
- `pricing.branch_pricing_priority=branch_price_first`

When disabled, all existing pricing behavior remains unchanged and saved branch prices are retained but ignored.

Supported priority policies:

- `branch_price_first`: branch, customer Price List, Sale, regular.
- `customer_price_first`: customer Price List, branch, Sale, regular.
- `sale_price_first`: Sale, customer Price List, branch, regular.
- `lowest_price`: the lowest valid branch, customer, Sale, or regular price.

An inactive, future, expired, or missing branch price is ignored.

## Branch Resolution and Fallback

POS resolves the cashier/operator branch and recalculates every cart line server-side. A client-supplied price is never trusted.

Web checkout and Storefront do not currently expose a branch selector. They use the active default branch when branch pricing is enabled. If the default branch has no valid branch price, resolution safely falls back according to the selected priority.

Resolved prices are request-scoped. No final branch or customer price is stored in shared public cache, which prevents one branch price from leaking into another branch request.

## Historical Documents

New POS orders retain their pricing snapshot in order metadata, including branch and source. `order_details.price` remains the historical sale amount.

Sales Invoice rendering reads stored Order and Order Detail totals. It does not recalculate against current branch, Sale, or Price List data.

## Permissions

- `pricing.branch_prices.view`
- `pricing.branch_prices.manage`

Owners and Store Admins can view and manage. Accountants can view. Data Entry / Purchasing may view. Cashiers, warehouse, delivery, marketing, and designer roles do not manage branch prices.

## Out of Scope

- Branch-specific Price Lists.
- Branch Inventory changes.
- Branch profit and loss.
- Automatic price synchronization between branches.
- Flutter POS changes.
- Storefront branch selection.
