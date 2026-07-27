# CoreMarket Delivery Workflow

## Scope

Step 63 adds a web-based operational delivery foundation on top of the existing Orders, staff roles, and branch foundation. It does not replace checkout, order management, payment posting, or the legacy order status fields.

The workflow supports restaurants, supermarkets, retail stores, eCommerce stores, and warehouse/distribution businesses that need to assign an order to an employee and track delivery progress.

## Reused CoreMarket foundations

- `orders` remains the source order and provides the order number, customer shipping address, payment type, payment status, and total.
- `delivery_distribution` remains the approved delivery staff preset.
- `store_branches` and `staff_branch_assignments` provide optional branch context.
- Spatie roles and permissions continue to control access.
- Existing money helpers format COD values.
- Existing order delivery status is synchronized only for the compatible `picked_up`, `on_the_way`, `delivered`, and `cancelled` milestones.

The legacy optional Delivery Boy add-on is not used as the foundation because its controller is unavailable in this codebase and its direct order fields do not provide an auditable status/COD history.

## Delivery statuses

Allowed transitions are intentionally small:

| Current | Allowed next status |
| --- | --- |
| `pending_assignment` | `assigned`, `cancelled` |
| `assigned` | `picked_up`, `cancelled` |
| `picked_up` | `out_for_delivery` |
| `out_for_delivery` | `delivered`, `failed` |
| `failed` | `assigned`, `returned` |
| `delivered` | none |
| `returned` | none |
| `cancelled` | none |

Every creation, assignment, status change, and COD update creates an `order_delivery_events` audit entry.

## Access and privacy

- Owner / General Manager and Store Admin can view all deliveries, assign employees, update status, and record operational COD.
- Delivery / Distribution employees can view only deliveries assigned to their own user and use only the operational Picked Up, Out for Delivery, Delivered, and Failed transitions. They do not receive access to the general Orders list/detail pages.
- Accountant can view the safe delivery/COD page and record operational collection when required.
- Cashier, Data Entry, Warehouse, Marketing, and Designer presets receive no delivery-management permission by default.
- Delivery screens expose only the order reference, customer name, phone, delivery address, branch, status, assigned employee, and COD amount/status.
- Delivery screens intentionally exclude product cost, profit, supplier balances, and accounting reports.

## Branch behavior

- When branch support is disabled, delivery can use the existing default branch without requiring branch-specific behavior.
- When branch support is enabled, a delivery employee must be assigned to the delivery branch before assignment.
- The delivery list can be filtered by branch.
- Step 63 does not implement branch-specific inventory or branch-specific pricing.

## COD collection and settlement

COD collection and cashbox settlement are intentionally separate:

- COD amount is snapshotted from the order total for `cash_on_delivery` orders.
- The assigned delivery employee records a pending, partial, collected, or failed collection state.
- Collection means the delivery employee is holding cash; it is not yet cashbox money.
- A Manager, Store Admin, Accountant, or authorized Cashier receives the money through **Receive COD / Settle COD**.
- The receiver must select an open cashbox shift. A Cashier can use only their own open shift.
- Each posted settlement creates one reserved `delivery_cod_settlement` cash-in movement.
- Partial settlement is supported, and collected, settled, and remaining amounts remain visible.
- An idempotency key and locked balance validation prevent duplicate or excessive posting.
- If no eligible shift is open, settlement is blocked with `No open cashbox shift available.`

The settlement does not update `orders.payment_status`. The existing payment-status path invokes broader commission, notification, and order-detail behavior, so it remains separate until a dedicated COD payment integration is approved. No accounting journal is created; the cash movement is marked as accounting pending.

Delivery employees see only the amount to collect and collection status for assigned deliveries. They do not see settlement controls, cashbox internals, product cost, profit, supplier balances, or accounting reports.

## Not included

- GPS tracking, maps, route optimization, proof of delivery, signature, or photo.
- Delivery mobile application.
- WhatsApp or SMS sending.
- Payment gateway or automatic accounting journal posting.
- Cash drawer or hardware integration.
- Branch-specific stock/pricing.

## Future work

- Optional COD payment-status and accounting-journal integration after reconciliation policy approval.
- Mobile-friendly delivery view or dedicated delivery app.
- Route planning and delivery zones.
- Proof of delivery with signature/photo.
- Customer notifications.
