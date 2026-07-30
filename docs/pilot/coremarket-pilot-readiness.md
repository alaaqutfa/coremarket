# CoreMarket Pilot Readiness

## Purpose

This guide defines how to present CoreMarket as a controlled commercial pilot.
It is not a production certification, a promise of every future module, or a
replacement for client-specific acceptance testing.

The pilot must use fake or approved client data, an isolated database, named
staff accounts, documented feature flags, and a rehearsed rollback plan.

## Current Pilot Positioning

CoreMarket is suitable for a controlled pilot with:

- Supermarkets and general retail stores.
- Mobile and electronics stores.
- Clothing and shoe stores using ProductStock variants.
- Wholesale and B2B distributors.
- Small multi-branch businesses.
- Businesses combining storefront orders, POS sales, purchasing, inventory,
  delivery, and customer accounts.

CoreMarket is not yet a complete solution for:

- Full restaurant POS with tables, KOT, kitchen states, and menu modifiers.
- Manufacturing with BOM, recipes, production orders, yield, and production
  costing.
- Official tax filing or jurisdiction-specific accounting compliance.
- Flutter POS serial scanning, Pay on Account, and the newest Web POS workflows.
- Automated production payment-gateway processing.

## Readiness Classification

### Ready to demonstrate

- Products, categories, variants, SKU, and barcode identity.
- Supplier, Purchase Order, Purchase Receipt, and Purchase Return workflows.
- Inventory stock, movements, opening stock, adjustments, and stock counts.
- Web POS cash sale and receipt foundation.
- Sales Returns, operational refunds, and credit notes.
- Cashboxes, shifts, and manual cash movements.
- Sales, purchase, supplier, statement, receipt, and label documents.
- Permission presets and staff role separation.

### Pilot-ready with configuration and rehearsal

- Price Lists and customer-specific storefront pricing.
- Branch Inventory, initialization, Stock Transfers, and Branch Pricing.
- Customer Receivables, credit limits, payment terms, and Pay on Account.
- Delivery assignment, COD collection, and COD cashbox settlement.
- Serial/IMEI receiving and Web POS sale.
- Warranty policies and claims.
- Web Checkout and customer-account pricing.
- Accounting and operational report foundations.

These features are disabled by default or depend on prepared data, permissions,
open shifts, customer profiles, or branch assignments. They must be configured
and rehearsed in the exact pilot environment.

### Deferred

- Restaurant Mode, seating, KOT, modifiers, and kitchen workflows.
- BOM, recipes, Production Orders, WIP, yield, and manufacturing costing.
- Official VAT filing, statutory reports, and formal accounting certification.
- Full balance-sheet and accounting close certification for a jurisdiction.
- Payment-gateway automation in the Pilot offer.
- Flutter POS catch-up for serials, customer credit, refunds, and later Web
  features.
- Native printer drivers, direct cash-drawer control, GPS, route optimization,
  and delivery mobile app.

## Pilot Preconditions

- Record the approved source commit and the demo/client instance release level.
- Confirm the instance uses an isolated non-runtime pilot database.
- Do not assume `coremarket-demo` matches source; synchronize it only in a
  separately approved step.
- Back up the pilot database before transactional rehearsal.
- Run the protected demo seed only against a database ending in `_demo`.
- Assign every operator an individual staff account and approved role preset.
- Enable only the feature flags required by the selected demo flow.
- Prepare products, customers, suppliers, branches, cashboxes, and opening stock
  before the client meeting.
- Test scanner and printer hardware on the actual workstation.
- Keep a second browser session available for manager/accountant verification.

Protected demo planning command:

```powershell
$env:DB_DATABASE="coremarket_demo"
php artisan coremarket:seed-demo --dry-run --with-samples=standard
Remove-Item Env:DB_DATABASE
```

Do not run apply/reset as part of an unapproved client presentation.

## Demo Flow 1: Supermarket / Retail Store

### Business story

A store receives products from a supplier, controls opening stock, sells at the
counter, accepts a return, and reconciles the cashier shift.

### Preparation

- One Store Admin, Cashier, Warehouse Keeper, and Accountant.
- One active cashbox and a prepared shift opening balance.
- Three products: regular price, active Sale price, and barcode/SKU variant.
- One supplier and one draft Purchase Order.
- Strict Inventory Mode and negative-stock policy recorded.

### Walkthrough

1. Create or open a Product with name, SKU/barcode, tax, cost, and regular price.
2. Show that Product creation alone does not create stock.
3. Post Opening Stock in setup mode or receive the prepared Purchase Order.
4. Confirm the Inventory Movement and resulting stock quantity.
5. Open a cashier shift and scan/search the Product in Web POS.
6. Complete one cash sale and open the receipt.
7. Verify branch/default stock decreased and the cash shift expected amount
   increased.
8. Create a Sales Return for one item.
9. Complete a Cash Refund only through an authorized open shift.
10. Verify returned stock, cash movement out, shift summary, and basic
    sales/profit report.

### Success criteria

- Stock changes exactly once at each posted business event.
- Receipt totals match the stored Order.
- Refund cannot exceed the completed return.
- Cash in/out appears in the expected shift.
- Reports are presented as operational, not statutory tax certification.

## Demo Flow 2: Electronics / Mobile Store

### Business story

An electronics store receives traceable units, sells one exact unit, records its
warranty, and processes a serialized return.

### Preparation

- Enable serial/IMEI and warranty flags in the pilot instance.
- One ProductStock marked serial tracked and, when applicable, IMEI tracked.
- A Purchase Receipt with exact serial/IMEI values.
- An active warranty policy.

### Walkthrough

1. Show the Product and ProductStock variant identity.
2. Receive the prepared quantity and enter one serial/IMEI per unit.
3. Demonstrate duplicate serial/IMEI rejection.
4. Confirm the units are in stock and carry branch context.
5. Select an available unit in Web POS and complete the sale.
6. Confirm the unit is marked sold and linked to the Order Detail.
7. Show the resolved warranty policy and expiry snapshot.
8. Create a Warranty Claim and progress its operational status.
9. Create a Sales Return using the serial linked to the original sale.
10. Confirm an unrelated serial cannot be returned and the accepted unit returns
    to stock.

### Success criteria

- Received quantity equals supplied serial-unit count.
- A sold or unavailable unit cannot be sold again.
- Warranty activity does not silently change stock or accounting.
- Web Checkout limitation for serialized products is explained before the demo.

## Demo Flow 3: Wholesale / B2B

### Business story

A distributor assigns negotiated pricing and controlled credit to a business
customer, sells on account, receives a payment, and issues a return credit note.

### Preparation

- Enable customer accounts, credit-limit, payment-terms, and the selected Pay on
  Account channel.
- Enable Price Lists and assign one customer to a prepared list.
- Create a customer credit profile with active status, limit, and payment terms.
- Prepare one product with public, Sale, and Price List examples.

### Walkthrough

1. Open the customer profile and explain credit allowed, credit limit, terms,
   current balance, available credit, and overdue amount.
2. Show Price List A/B/C concepts and the customer's assigned list.
3. Compare guest/public price with the signed-in customer's resolved price.
4. Show the credit decision before posting the sale.
5. Complete Pay on Account through Web POS or Web Checkout.
6. Verify no Cash Movement and no Customer Payment were created by the sale.
7. Confirm one AR invoice debit, due-date snapshot, and updated outstanding
   balance.
8. Record a customer payment and allocate it to the invoice.
9. Complete a partial Sales Return and credit the Customer Account.
10. Confirm one credit-note ledger entry, no cash movement, and the updated
    Customer Statement.

### Success criteria

- Guest and other customers never see the negotiated customer price.
- Blocked, on-hold, over-limit, or overdue customers cannot buy on account.
- Retry does not duplicate the AR invoice or credit note.
- Ledger debit minus credit equals the displayed customer balance.

## Demo Flow 4: Multi-Branch Store

### Business story

A business initializes existing stock into its main branch, transfers units to a
second branch, applies a branch price, and sells from the correct branch.

### Preparation

- Create one default active branch and one destination branch.
- Assign cashier and warehouse staff to the correct branches.
- Keep Branch Inventory disabled until initialization is reviewed.
- Prepare one ProductStock with aggregate quantity.

### Walkthrough

1. Run or show the dry-run plan:

   ```bash
   php artisan coremarket:branch-inventory-initialize
   ```

2. Explain that apply requires explicit approval:

   ```bash
   php artisan coremarket:branch-inventory-initialize --apply --confirm-branch-inventory
   ```

3. Enable Branch Inventory only after initialization is reconciled.
4. Show branch quantity and aggregate `product_stocks.qty`.
5. Create a Stock Transfer from main branch to destination.
6. Submit, approve, ship, and receive the transfer.
7. Show source decrease at shipping and destination increase at receipt.
8. Confirm aggregate quantity remains unchanged after the completed transfer.
9. Add a Branch Price and demonstrate safe fallback for another branch.
10. Complete a sale from the assigned branch and verify its stock movement.

### Success criteria

- No branch balance is duplicated during initialization.
- Staff see only assigned branches unless granted all-branch access.
- Transfer out/in movements are linked and idempotent.
- Branch price never leaks between branches.

## Demo Flow 5: Delivery / COD

### Business story

A manager assigns an Order to a delivery employee, the employee records COD
collection, and an authorized receiver settles the money into an open cashbox.

### Preparation

- One deliverable Order with customer address/phone.
- One delivery employee assigned to the relevant branch.
- One manager/accountant and one open receiving cashbox shift.

### Walkthrough

1. Ensure the Order has a Delivery record.
2. Assign the Delivery user and show that they see assigned deliveries only.
3. Progress through picked up and out for delivery.
4. Record the COD collected amount.
5. Show that the Delivery user has no settle button or accounting details.
6. As manager/accountant/cashier with permission, receive the COD.
7. Verify the settlement and one Cash Movement in.
8. Retry the same settlement and demonstrate duplicate/over-settlement
   protection.
9. Review collected, settled, and remaining COD amounts.

### Success criteria

- Delivery staff never see cost, profit, supplier, or accounting data.
- Settlement requires permission and the configured open-shift rule.
- One remittance creates at most one cash posting.

## Demo Flow 6: Purchasing / Supplier

### Business story

A purchasing clerk orders and receives products, the accountant records a
supplier payment, and the warehouse completes a Purchase Return.

### Preparation

- One supplier with a prepared Purchase Order.
- One existing product and one unknown SKU to demonstrate Quick Product Create.
- Assigned purchasing, warehouse, and accountant users.

### Walkthrough

1. Create/open the Supplier and Purchase Order.
2. Scan/search an existing Product.
3. Demonstrate the unknown-product prompt and AJAX Quick Product Create.
4. Receive the Purchase Order once and verify stock/cost snapshots.
5. Open the Supplier ledger/balance.
6. Record an authorized Supplier Payment.
7. Create and complete a partial Purchase Return.
8. Confirm branch/default stock decrease and supplier balance effect.
9. Download the Purchase PDF and Supplier Statement PDF.

### Success criteria

- Quick Product Create does not silently create stock.
- Receipt and return posting are idempotent.
- Purchasing staff cannot perform supplier payment without permission.
- Supplier statement uses recorded operational ledger data.

## Demo Flow 7: Inventory Control

### Business story

A warehouse team establishes initial stock, reports damage, performs a cycle
count, and obtains manager approval before stock changes.

### Preparation

- Record strict mode, setup mode, approval, and negative-stock settings.
- One Warehouse Keeper and one approving manager.
- Products with known expected quantities.

### Walkthrough

1. Show that direct Product stock editing is blocked in Strict Inventory Mode.
2. Create an Opening Stock document while setup mode permits it.
3. Show that draft/pending documents do not change stock.
4. Approve and post, then inspect the Inventory Movement.
5. Create a damage/correction Stock Adjustment.
6. Demonstrate approval requirements and rejection behavior.
7. Create a Stock Count, enter counted quantities, and review variance.
8. Post the approved variance adjustment.
9. Demonstrate negative-stock rejection when disabled.
10. Review source document, reason, actor, and branch in the audit trail.

### Success criteria

- Every stock mutation has an approved business source.
- Draft, pending, rejected, and cancelled documents do not change stock.
- Posted documents cannot post twice.
- Branch context is real only when Branch Inventory is enabled.

## Client-facing Risk Notes

- This is a Pilot, not statutory accounting or tax certification.
- Features disabled by default must be explicitly selected and tested.
- Demo data and feature flags must match the story before the meeting.
- The current local development database must not be sold as a clean client
  baseline.
- Hardware validation requires the actual scanner and printer.
- Flutter POS does not yet expose all recent Web workflows.
- Serialized products are blocked from Web Checkout until safe allocation is
  implemented.
- Restaurant and Production modes remain roadmap items.

## Pilot Exit Criteria

The Pilot may be proposed for Go-Live planning only when:

- Every selected QA item is signed off against the target instance.
- Opening stock, branch initialization, and staff assignments reconcile.
- Cash sale, return/refund, shift close, and required PDFs are verified.
- Credit and COD flows reconcile when included.
- Roles have been tested with real job profiles, not only manager access.
- Backup, restore, support owner, and rollback process are documented.
- Known limitations are accepted in writing.
- Commercial scope and excluded customizations are signed.
