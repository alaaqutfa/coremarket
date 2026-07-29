# CoreMarket Serial, IMEI, Variant, and Warranty Foundation

## Scope

Step 73 extends the existing `ProductStock` variant model. It does not create a second variation engine.

- `ProductStock.variant`, `sku`, `barcode`, quantity, and price remain the variant identity and stock record.
- `catalog.advanced_variants_enabled` exposes the existing size/color/attribute combination foundation as an opt-in capability.
- Serial, IMEI, and warranty features are disabled by default.

## Feature Settings

- `inventory.serial_tracking_enabled`
- `inventory.imei_tracking_enabled`
- `inventory.warranty_tracking_enabled`
- `catalog.advanced_variants_enabled`

Global serial tracking and the selected `ProductStock.serial_tracking_enabled` flag must both be enabled before unit-level enforcement applies. IMEI tracking additionally requires IMEI 1 for newly received units.

## Purchase Receiving

A serial-tracked purchase line accepts one identity per received unit. The safe text format is:

`SERIAL|IMEI1|IMEI2`

One line is required for every received item. Duplicated serial numbers and IMEIs are rejected. The unit stores the Purchase Order, Purchase Receipt, ProductStock variant, and resolved branch. Stock quantity and serial creation occur in the same database transaction.

## Web POS

Web POS search exposes available serial units only to authorized operators. Checkout accepts `serial_unit_ids` and revalidates them server-side.

- Selected unit count must equal sale quantity.
- A unit must belong to the selected ProductStock and resolved branch.
- Sold, reserved, damaged, removed, or warranty-claim units cannot be sold.
- Units are reserved and then marked sold in the same Order transaction.
- Warranty expiry is snapshotted at sale time from the matching active policy.

Flutter POS was not changed in Step 73.

## Web Checkout

Customer-facing serial selection is intentionally not exposed. While serial tracking is enabled, a cart containing a serial-tracked variant is blocked with an assisted-POS message. Public checkout remains unchanged for normal products.

## Sales Returns

The return form lists sold serial units linked to each Order Detail. Returned quantity must match selected unit count. A unit from another order line cannot be returned. On return completion, the branch stock reversal and serial transition back to `in_stock` occur transactionally.

## Warranty Policies and Claims

Policies may apply to a Product or a specific ProductStock variant. The specific variant policy has priority. Warranty Claims can be opened by Serial/IMEI and follow:

- received
- under_review
- sent_to_supplier
- repaired
- replaced
- rejected
- returned_to_customer
- closed

Opening or changing a claim does not change stock, cash, receivables, Orders, or accounting. Replacement and supplier-warranty logistics require separate documented workflows.

## Access

- Owner and Store Admin: all serial, policy, and claim permissions.
- Warehouse Keeper: serial receive/view and claim view.
- Cashier: limited serial view/sell and claim create/view.
- Accountant: read-only serial, policy, and claim access.
- Delivery, Marketing, and Designer roles: no access by default.

## Future

- Flutter POS serial/IMEI scan and selection.
- Supplier warranty and RMA workflow.
- Controlled replacement stock transaction.
- Mobile warranty intake.
- Unit reservation for Web orders.
- Jewelry-specific certificates and valuation metadata.
