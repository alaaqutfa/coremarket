# CoreMarket Inventory Governance

## Scope

Step 68 makes inventory quantity a documented operational fact. A Product may be created independently, but every physical Product and Product Stock starts at quantity zero.

Quantity enters or leaves stock only through a recognized source:

- Purchase Receipt
- Sale / POS / Web order
- Sales Return
- Purchase Return
- Opening Stock document
- Stock Adjustment document
- Stock Count Variance
- Emergency Adjustment

Existing sales, purchase, and return services continue to use `InventoryMovementService`. Step 68 adds governance documents above the existing movement audit trail rather than replacing it.

## Settings

- `inventory.strict_inventory_mode=false`
- `inventory.allow_negative_stock=false`
- `inventory.setup_mode_enabled=true`
- `inventory.opening_stock_enabled=true`
- `inventory.adjustments_enabled=true`
- `inventory.adjustment_requires_approval=true`
- `inventory.stock_counts_enabled=true`
- `inventory.emergency_adjustment_enabled=false`
- `inventory.branch_inventory_enabled=false`

Product creation never creates quantity, regardless of strict mode. Strict mode remains the policy guard for operational increases and decreases, while governance requires all manual changes to use documents in every mode.

Setup mode enables controlled opening-stock preparation. It should be disabled after Go-Live. An authorized manager may still post an opening document after setup mode is disabled.

## Opening Stock

Opening Stock is a dedicated adjustment type for initial quantity. It:

1. Starts as a draft and stores product, stock identity, quantity, cost, and user snapshots.
2. Does not change stock while draft or pending.
3. Requires approval when the approval setting is enabled.
4. Can post only when the current stock row is still zero.
5. Creates one idempotent `opening_stock` inventory movement per item.

Opening Stock is not created automatically from Product create, Product import, Product duplicate, or Purchase Quick Product Create.

## Stock Adjustments

Supported adjustment reasons/types include correction, damage, loss, theft, internal use, expired goods, samples, supplier bonus, and emergency adjustment.

The lifecycle is:

`draft -> pending_approval -> approved -> posted`

A reviewer may reject a pending document. Draft, pending, or approved documents may be cancelled. Rejected, cancelled, and posted documents cannot be posted.

Posting locks the document and stock rows, recalculates before/after quantities, applies the negative-stock policy, updates the stock row, and creates an `inventory_movement`. A posted document is idempotent and cannot change quantity twice.

Emergency Adjustment is disabled by default and requires `inventory.adjustments.emergency`.

## Stock Counts

A Stock Count snapshots expected quantity and records the physical counted quantity. Variance is:

`counted quantity - expected quantity`

Draft and pending counts do not change stock. Posting an approved count creates and posts one linked `stock_count_variance` adjustment document. Repeated posting returns the existing posted result without duplicate movement.

## Product Safety

- Product create fields are read-only for quantity and server-side creation forces zero.
- Product edit preserves existing stock quantities and ignores submitted quantity changes.
- Product import and duplicate create zero quantity.
- Purchase Quick Product Create rejects positive opening quantity and directs the operator to Opening Stock or Purchase Receipt.
- The legacy manual adjustment URL remains compatible but creates a governed document rather than writing `qty` directly.

## Negative Stock

When `inventory.allow_negative_stock=false`, posting any decrease that would make stock negative is rejected. When enabled, a documented approved adjustment may post a negative result. Drafts never affect availability.

## Audit Trail

`inventory_adjustment_documents`, their items, reviewer/poster timestamps, immutable identity snapshots, and `inventory_movements` form the Step 68 audit trail. Movement metadata includes document reference, reason, adjustment type, before/after quantities, and branch context.

No historical movement backfill is created.

## Branch Inventory

Step 69 promotes `branch_id` from document context to real branch stock context only when `inventory.branch_inventory_enabled=true`. Branch balances become the availability source while `product_stocks.qty` remains an aggregate compatibility mirror. When disabled, Step 68 unified behavior remains unchanged.

Stock transfers use their own approved document lifecycle and create `transfer_out` and `transfer_in` movements. Branch pricing remains separate and is not implemented.

## Permissions

Owners receive all inventory governance permissions. Store Admins can create, approve, post, and cancel normal documents. Accountants can view/review/post. Warehouse Keepers can create and submit adjustments/counts but cannot approve, post, or perform emergency adjustments. Data Entry/Purchasing can prepare Opening Stock during setup. Cashier, Delivery, Marketing, and Designer roles receive no governance permissions.

## Future

- Branch stock valuation
- Manufacturing and BOM
- Serial number and warranty tracking
- Mobile cycle counting
