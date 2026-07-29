# CoreMarket Branch Inventory

## Scope

Step 69 adds optional branch-level inventory and documented inter-branch transfers. It extends the existing Branch Foundation, Inventory Governance, and `inventory_movements`; it does not replace them.

Branch pricing, branch profit and loss, manufacturing, and warehouse routing are outside this step.

## Feature Flag

`inventory.branch_inventory_enabled=false`

When disabled, existing stock behavior remains and `product_stocks.qty` is used normally. Branch Stock and Stock Transfer pages are hidden or blocked.

When enabled:

- `product_stock_branch_balances.quantity` is the availability source for a product variant at a branch.
- `product_stocks.qty` is maintained as the sum of all branch balances for backward compatibility.
- `products.current_stock` remains the sum of the product stock rows.
- Missing operation context falls back to the staff primary branch, then the default branch.

The feature must not be enabled until current aggregate stock has been initialized.

## Initialization

The command is dry-run by default:

```bash
php artisan coremarket:branch-inventory-initialize
```

Apply requires an explicit confirmation:

```bash
php artisan coremarket:branch-inventory-initialize --apply --confirm-branch-inventory
```

Use `--branch-id=ID` only when the current aggregate stock belongs to a non-default branch. Existing balance rows are skipped, so rerunning the command is idempotent. The command reports scanned rows, rows created or proposed, skipped rows, and aggregate differences.

## Operational Integration

- Purchase Receipt increases the selected branch, or the resolved default branch.
- Opening Stock, Adjustments, and Stock Counts use the document branch as real stock context.
- Web POS resolves the cashier primary/default branch and validates/decreases that branch.
- Web and API checkout use the resolved default/assigned branch without requiring a client contract change.
- Sales Returns restore the order/return branch when known, otherwise the default branch.
- Purchase Returns decrease the selected/default branch.
- Inventory movements store `store_branch_id` in safe metadata.

The backend remains compatible with Flutter POS because a request without a branch uses the assigned/default branch.

## Stock Transfers

Lifecycle:

`draft -> pending_approval -> approved -> shipped -> received`

- Draft, pending, and approved transfers do not change stock.
- Shipping decreases the source branch and creates one `transfer_out` movement.
- Receiving increases the destination branch and creates one `transfer_in` movement.
- Repeated ship or receive actions are idempotent.
- Source and destination must differ.
- Negative source balances follow `inventory.allow_negative_stock`.
- Rejected or cancelled transfers cannot ship.
- After a full transfer, aggregate `product_stocks.qty` returns to its pre-transfer value.

Stock in a shipped but not received transfer is operationally in transit and is not available at either branch.

## Staff Access

- Owner and Store Admin can access all branches.
- Accountant can view branch stock and transfers.
- Warehouse Keeper can view, create, ship, receive, and cancel transfers for assigned branches.
- Data Entry/Purchasing can view and create transfer requests for assigned branches.
- Cashier stock checks use the assigned/default branch but Cashier cannot manage transfers.
- Delivery, Marketing, and Designer roles cannot access branch inventory management.
- Staff without assignments safely fall back to the default branch until assignments are completed.

## Audit and Safety

Branch mutations run in database transactions and lock the stock and branch balance rows. Transfers use document status, item references, and idempotent inventory movements to prevent duplicate posting.

No historical movements are backfilled. Branch balance initialization is explicit, never automatic.

## Future

- Branch pricing
- Branch stock alerts and reorder rules
- Native mobile transfer receiving
- Inter-branch costing
- Branch profit and loss
