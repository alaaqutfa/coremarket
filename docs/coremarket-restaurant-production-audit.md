# CoreMarket Restaurant and Production Audit

## Scope

Step 74 is an audit and roadmap decision only. It does not add feature flags,
tables, migrations, routes, UI, stock behavior, costing behavior, or accounting
entries.

The audit reviewed the existing Product and ProductStock model, Web POS cart and
checkout flow, Orders, Delivery, Branch Inventory, Purchasing, Inventory
Governance, staff role presets, pricing/tax services, receipt templates, and the
serial/warranty foundation.

## Executive decision

CoreMarket is currently strongest as a retail, wholesale, distribution, and
eCommerce operations platform. Its reusable foundations are substantial, but
neither a restaurant kitchen workflow nor a production workflow exists today.

The recommended next step is **Pilot Readiness**, not implementation of either
mode. A real pilot should identify the first vertical and validate its operating
workflow before new domain tables are introduced.

If a restaurant customer becomes the immediate pilot, implement only a bounded
Restaurant POS track first: feature flag, order type, simple seating, modifiers,
and KOT. Recipe consumption should remain a later phase.

Production/BOM should remain deferred until a manufacturing pilot defines units,
yields, costing, scrap, and production approval requirements.

## Restaurant audit

### What exists

- Products, categories, Product Families, brands, taxes, public/Sale prices,
  customer Price Lists, and branch prices already support menu catalog and price
  presentation.
- `ProductStock` is the existing variant identity. Its attribute/color
  combinations, SKU, barcode, price, and quantity can represent fixed variants
  such as drink size or packaged-product size.
- Web POS has server-side product lookup, cart normalization, pricing, tax,
  branch inventory validation, customer selection, payment handling, and receipt
  output.
- Orders already preserve item price and cost snapshots and distinguish their
  source through fields such as `order_from` and `shipping_type`.
- Delivery, COD settlement, branches, staff access, purchases, inventory,
  customer receivables, and document templates are reusable operational
  foundations.
- POS receipt templates support 80 mm and 58 mm output at the document-template
  level.

### What does not exist

- No restaurant table, floor, seating, or occupancy management exists.
- No Kitchen Order Ticket (KOT), kitchen station, kitchen queue, or kitchen
  printer-routing domain exists.
- No kitchen status lifecycle exists. Delivery/order statuses are not a
  substitute for preparation statuses.
- No waiter or kitchen staff role preset exists.
- No restaurant menu modifier or add-on domain exists. The existing software
  `Addon` model is for application add-ons, not meal choices.
- ProductStock variants are fixed sellable identities; they should not be
  overloaded as order-specific modifiers such as "no onions" or "extra cheese".
- No first-class dine-in, takeaway, and delivery order-type policy exists. The
  current `shipping_type` values describe legacy fulfillment and POS sources,
  not restaurant service modes.
- Stock consumption occurs against the sold ProductStock quantity. There is no
  recipe-based ingredient consumption.
- No split bill, seat assignment, course firing, table transfer, or kitchen
  production timing exists.

### Is the current POS restaurant-ready?

The current Web POS is suitable for a simple counter-sale business that sells
fixed products, such as a small bakery, kiosk, coffee counter, or takeaway shop
that does not need tables, modifiers, KOT, or recipe depletion.

It is not yet suitable as a full restaurant POS. Treating ordinary Orders,
ProductStock variants, delivery statuses, or inventory adjustments as restaurant
tables, modifiers, kitchen states, or recipes would create ambiguous data and
weak auditability.

### Minimum scope for a small restaurant

1. A disabled-by-default Restaurant Mode feature flag.
2. Explicit order types: dine-in, takeaway, and delivery.
3. Simple tables/seating with open/occupied/closed state.
4. Allowlisted menu modifiers/add-ons with price deltas and immutable order-line
   snapshots.
5. A KOT document and kitchen queue separate from the customer receipt.
6. Waiter and kitchen roles with narrowly scoped access.
7. A kitchen status flow separate from payment and delivery status.
8. Recipe-based inventory consumption only after the ordering workflow is stable.

## Restaurant reuse map

| Existing foundation | Reuse | Missing boundary |
| --- | --- | --- |
| Products/categories/families | Menu catalog and grouping | Menu availability and restaurant presentation rules |
| ProductStock variants | Fixed size/color/package variants | Per-order modifiers and special instructions |
| Web POS | Cart, totals, tax, pricing, customer, payment | Tables, service modes, KOT, waiter flow |
| Orders/OrderDetails | Commercial order and immutable price snapshots | Restaurant session/table/modifier/KOT snapshots |
| Delivery workflow | Delivery assignment and COD | Dine-in/takeaway workflow |
| Branches | Outlet identity and staff scope | Restaurant floor/table map |
| Inventory/branches | Ingredient and finished-item balances | Recipe consumption and yield |
| Purchasing/suppliers | Ingredient procurement | Recipe demand/planning |
| Staff roles | Permission preset framework | Waiter and kitchen presets |
| Document templates | Customer receipts and future KOT layout | KOT routing and kitchen station selection |
| Tax/pricing services | Totals and menu pricing | Modifier pricing policy |

## Restaurant Mode roadmap

### REST-01 Restaurant Mode Feature Flag

Add a disabled-by-default mode setting and central capability service. Existing
retail behavior must remain unchanged when disabled.

### REST-02 Tables / Seating

Add restaurant areas and tables with occupancy state and an open restaurant
session/order relation. Start with a list/grid, not a visual floor designer.

### REST-03 Order Types

Introduce explicit dine-in, takeaway, and delivery snapshots on restaurant
orders. Do not infer them from legacy shipping strings.

### REST-04 Menu Modifiers / Add-ons

Create safe modifier groups, choices, min/max selection rules, price deltas, and
immutable Order Detail snapshots. Do not reuse software add-ons or force
modifiers into ProductStock.

### REST-05 Kitchen Order Ticket / KOT

Create a KOT from committed restaurant items, with item notes, quantities,
station, table/order reference, and reprint history. Keep it separate from the
fiscal/customer receipt.

### REST-06 Waiter Role

Add waiter and kitchen presets through the existing Spatie permission system.
Waiters should manage assigned/open tables and orders without cost, profit,
supplier, or accounting access.

### REST-07 Kitchen Status Flow

Add a preparation lifecycle such as queued, accepted, preparing, ready, served,
and cancelled. Kitchen state must not silently change payment or delivery state.

### REST-08 Recipe-based Inventory Consumption

After the order and kitchen flows are proven, consume ingredient quantities from
an approved recipe snapshot. Posting must be transactional, branch-aware,
idempotent, and visible in Inventory Movements.

### REST-09 Restaurant Reports

Add table turnover, preparation time, item/modifier sales, voids, wastage, and
ingredient-consumption variance. Avoid restaurant P&L until costing rules are
approved.

## Production / manufacturing audit

### What exists

- Products, ProductStock variants, Product Families/Sub-families, SKU/barcode
  identity, and serial/IMEI traceability provide reusable item masters.
- Purchase Orders and Receipts can procure raw materials as ordinary products
  and capture purchase cost.
- Inventory Movements are the central stock audit trail.
- Inventory Governance supports Opening Stock, Stock Adjustment, Stock Count,
  damage, loss, theft, internal use, correction, emergency adjustment, supplier
  bonus, expired goods, and samples.
- Branch Inventory and Stock Transfers provide site-level balances and movement.
- Sale lines preserve cost and profit snapshots for commerce reporting.

### What does not exist

- No BOM or recipe model, version, effective date, yield, or substitute material
  exists.
- No explicit product type distinguishes raw material, semi-finished item, and
  finished good.
- No production order, batch, work in progress, release, consume, produce, or
  close lifecycle exists.
- No atomic transformation links material issues to finished-good receipts.
- No robust unit-of-measure conversion or recipe pack/yield conversion exists.
- No production reservation, lot/batch/expiry trace, operation/routing, labor,
  overhead, or production variance calculation exists.
- Current cost snapshots support purchasing and sales margin, not production
  cost rollup.
- There is no production journal or approved production accounting policy.

### Can CoreMarket produce finished goods now?

No. An operator could manually reduce raw materials and increase a finished
product using separate adjustments, but that would not create a BOM-controlled,
linked, atomic, or costed production transaction. This workaround must not be
presented as Manufacturing Mode.

### Minimum scope for a simple factory

1. A disabled-by-default Production Mode feature flag.
2. Explicit product types for raw, semi-finished, and finished goods.
3. Units, conversions, recipe quantity precision, and expected yield.
4. Versioned BOM/recipe definitions.
5. A production order lifecycle with branch/location context.
6. One transactional posting that consumes materials and produces output.
7. Waste, scrap, and yield variance recording.
8. A production cost snapshot with an approved cost policy.
9. Production traceability and operational reports.

## Production reuse map

| Existing foundation | Reuse | Missing boundary |
| --- | --- | --- |
| Product/ProductStock | Material and output identities/variants | Product production type |
| Product Families | Catalog classification | BOM relationship and version |
| Purchasing | Raw material procurement and cost | Material planning |
| Branch Inventory | Site balances and negative-stock policy | WIP and production location |
| Inventory Movements | Auditable material/output movements | Linked production transaction |
| Inventory Governance | Waste, damage, count, correction | Production order and yield |
| Stock Transfers | Move materials/outputs between branches | Production issue/receipt |
| Cost fields | Purchase and sales cost snapshots | BOM rollup, labor, overhead, variance |
| Staff permissions | Role/preset framework | Production planner/operator/approver roles |
| Reports | Inventory and sales reporting foundations | Production, yield, and variance reports |

## Production Mode roadmap

### PROD-01 Production Mode Feature Flag

Add a disabled-by-default mode setting and capability service. Retail and
distribution behavior remains unchanged when disabled.

### PROD-02 Product Type

Add an explicit, additive type: raw material, semi-finished, finished good, or
ordinary resale item. Do not infer type from category/family.

### PROD-03 BOM / Recipe

Add versioned BOMs with quantities, units, expected yield, effective dates, and
approval state. Resolve ProductStock variants explicitly where required.

### PROD-04 Production Order

Add draft, approved/released, in-progress, completed, cancelled, and rejected
states with branch, planned quantity, actual quantity, and BOM snapshot.

### PROD-05 Consume Raw Materials

Post branch-aware material-out Inventory Movements transactionally, enforce
negative-stock policy, and prevent duplicate consumption.

### PROD-06 Produce Finished Goods

Post finished/semi-finished output into branch stock in the same controlled
production transaction and keep the aggregate stock mirror synchronized.

### PROD-07 Waste / Scrap

Record waste, scrap, and by-products against the production order. Reuse
governance reason concepts, but preserve the production-order link.

### PROD-08 Production Cost Snapshot

Snapshot consumed material cost and an approved labor/overhead policy. Do not
recalculate historical production cost from current product prices.

### PROD-09 Production Reports

Add planned-versus-actual quantity, yield, material variance, scrap, output cost,
and order status reports. Accounting journals remain a separate future decision.

## Build / postpone decision

### Build first

- Complete Pilot Readiness and select the first target vertical.
- Validate actual staff workflows, permissions, hardware, document output,
  opening stock, purchasing, POS, returns, and day-end controls in a pilot.
- If the first signed pilot is a restaurant, use the bounded REST-01 through
  REST-07 track before recipe consumption.

### Postpone

- Restaurant recipe inventory until KOT and order snapshots are stable.
- Production/BOM until units, yield, costing, and approval requirements come
  from a real manufacturing pilot.
- Advanced restaurant features such as floor designer, split bills, courses,
  kitchen printer drivers, reservations, and online table ordering.
- Advanced manufacturing features such as MRP, routings, work centers, labor,
  capacity, lots/expiry, subcontracting, and accounting journals.

### Industries covered now

- General retail and supermarkets.
- Electronics/mobile and traceable-item stores.
- Clothing, shoes, and variant-based retail.
- Jewelry/valuable-item operations where serial traceability is sufficient.
- Wholesale and distribution.
- eCommerce plus physical-store operations.
- Purchasing, warehouse, delivery, customer credit, and returns workflows.

### Industries that need the new modes

- Full-service restaurants, cafes with kitchen routing, and dine-in operations
  need Restaurant Mode.
- Bakeries or central kitchens need Restaurant Mode plus recipe consumption, or
  Production Mode when batch manufacturing is the primary workflow.
- Light factories, assembly businesses, and packaged-goods manufacturers need
  Production Mode.

## Architecture guardrails for future work

- Do not overload ProductStock variants as restaurant modifiers.
- Do not overload delivery status as kitchen preparation status.
- Snapshot modifiers, recipe/BOM version, prices, quantities, and costs on the
  business document that posts them.
- Use Inventory Movements as the stock audit trail, but retain the originating
  KOT/production document reference.
- Make recipe/production posting transactional, branch-aware, and idempotent.
- Never model production as unrelated manual stock-out and stock-in adjustments.
- Keep payment, delivery, kitchen, stock, and production statuses separate.
- Do not add production accounting journals until the costing policy is agreed.
