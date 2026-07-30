# CoreMarket Pilot QA Checklist

## Usage

Run this checklist against the exact Pilot instance and approved commit. Record
the tester, date, evidence, and issue reference for every row.

Status meanings:

- **Ready:** stable core workflow; still requires instance smoke testing.
- **Pilot:** implemented but configuration, data, role, or hardware dependent.
- **Needs review:** do not promise without targeted acceptance evidence.

Risk meanings:

- **Low:** read-only or easily reversible.
- **Medium:** changes operational configuration or controlled master data.
- **High:** changes stock, cash, receivables, payments, or customer Orders.

## Environment Record

| Field | Value |
| --- | --- |
| Source commit | |
| Instance URL | |
| Database classification | Demo / Pilot |
| Backup reference | |
| Tester | |
| Test date | |
| Selected business flow | |
| Enabled feature flags | |
| Known exceptions | |

## Checklist

| Area | What to test | Expected result | Risk | Status | Result / evidence |
| --- | --- | --- | --- | --- | --- |
| Login/Admin access | Sign in as Manager, Cashier, Accountant, Warehouse, and one forbidden role | Correct landing page; restricted pages return hidden/403; no shared admin account required | Low | Ready | |
| Products | Create/edit a Product with category, tax, cost, regular and Sale price | Product saves; Product creation alone does not add stock in governed flow | Medium | Ready | |
| Variants | Create/use ProductStock size/color variant with unique SKU/barcode | Correct variant resolves in search, purchase, POS, and stock views | Medium | Ready | |
| Serial/IMEI | Receive exact units, reject duplicate identity, sell one unit | Unit count matches receipt; duplicate blocked; selected unit becomes sold | High | Pilot | |
| Warranty | Resolve policy, expiry, create claim, update claim status | Correct policy snapshot; claim remains operational and does not silently move stock/cash | Medium | Pilot | |
| Purchase Receipt | Receive prepared Purchase Order once | Branch/default stock and supplier ledger update exactly once; movement/reference visible | High | Ready | |
| Supplier Payment | Record authorized payment and inspect supplier balance | Payment appears once; unauthorized purchasing user is blocked | High | Pilot | |
| Purchase Return | Complete partial return | Stock decreases once; supplier balance effect and status are correct | High | Ready | |
| Opening Stock | Create draft, approve/post in setup mode | Draft does not change stock; posting creates one movement and quantity | High | Ready | |
| Stock Adjustment | Submit damage/correction adjustment through approval | Pending/rejected does not change stock; approved post changes once | High | Ready | |
| Stock Count | Enter expected/counted quantities and post variance | Variance is correct; stock changes only after approved posting | High | Ready | |
| Branch Inventory | Dry-run initialization, reconcile, then verify branch balances | No dry-run writes; initialized balances equal prior aggregate; default fallback works | High | Pilot | |
| Stock Transfer | Create, submit, approve, ship, receive | Source decreases on ship; destination increases on receive; aggregate unchanged | High | Pilot | |
| Branch Pricing | Resolve configured branch price and another-branch fallback | Correct branch/customer/Sale priority; no cross-branch price leak | High | Pilot | |
| Price Lists | Assign customer and compare guest/customer prices | Assigned customer sees resolved price; guest/other customer sees public price | High | Pilot | |
| POS Cash Sale | Open shift, scan/search, sell, open receipt | Server recalculates price/stock; Order paid; stock/cash change once | High | Ready | |
| POS Pay on Account | Use eligible and blocked customers | Eligible sale creates AR debit and no cash movement; blocked decision is enforced server-side | High | Pilot | |
| Web Checkout | Complete public/COD or approved checkout flow | Stored totals and stock are correct; customer pricing does not leak; serialized items are blocked | High | Pilot | |
| Customer Receivables | Post/use invoice entry and inspect balance/aging | Debits minus credits equals balance; duplicate posting blocked | High | Pilot | |
| Customer Payments | Record non-cash or open-shift cash payment and allocate | One payment/credit entry; allocation cannot exceed available amount | High | Pilot | |
| Sales Return | Return full/partial quantity, including branch/serial case | Original-order limits enforced; stock returns once to correct/default branch | High | Ready | |
| Cash Refund | Refund completed return through authorized open shift | One Cash Movement out; expected cash decreases; over/duplicate refund blocked | High | Pilot | |
| Credit Note | Credit Customer Account for completed return | One ledger credit; no Customer Payment or Cash Movement; statement updates | High | Pilot | |
| Delivery COD | Assign employee, update status, record collection | Employee sees assigned deliveries and COD amount only; status transition is valid | High | Pilot | |
| COD Settlement | Receive partial/full COD into authorized open shift | Settlement and one Cash Movement in; remaining amount correct; duplicate blocked | High | Pilot | |
| Cashbox | Create/open shift, cash in/out, close/reconcile | Canonical dashboard/list routes work; expected and counted cash reconcile | High | Pilot | |
| Reports | Compare sales, stock, supplier/customer, COD, and accounting views to source records | Totals reconcile for tested data; reports are labelled operational/informational | High | Needs review | |
| PDFs/Documents | Generate receipt, invoice, statements, delivery note, labels, template preview | PDF opens; stored prices/totals used; sensitive cost/profit absent from client documents | Medium | Ready | |
| Permissions | Attempt direct URLs/actions with restricted roles | Server-side authorization blocks forbidden actions, not only sidebar links | High | Pilot | |
| Staff Roles | Verify each role preset and branch assignment against real job | Cashier/Delivery/Marketing/Designer cannot access accounting/supplier/cost areas | High | Pilot | |

## Feature Flag Gate

Record the expected state before testing:

| Feature | Expected default | Pilot decision |
| --- | --- | --- |
| Price Lists | Disabled | |
| Branch Inventory | Disabled | |
| Branch Pricing | Disabled | |
| Customer Accounts / AR | Disabled | |
| Credit Limits | Disabled | |
| Payment Terms | Disabled | |
| Pay on Account POS | Disabled | |
| Pay on Account Web | Disabled | |
| Serial / IMEI | Disabled | |
| Warranty | Disabled | |
| Emergency Adjustment | Disabled | |

## Transaction Reconciliation

For every High-risk test, record before/after values:

- ProductStock aggregate and branch quantities.
- Inventory Movement count/reference.
- Order/Return status and stored total.
- Cash Movement count and shift expected cash.
- Customer ledger debit/credit/balance.
- Supplier ledger/balance.
- Serial-unit status and linked Order Detail.

## Pilot Gate

- [ ] All selected Ready items pass.
- [ ] Every selected Pilot item has evidence and an owner.
- [ ] No unresolved High-risk discrepancy exists.
- [ ] Needs Review items are excluded or accepted in writing.
- [ ] Hardware checks pass on the target workstation.
- [ ] Restore/rollback process is documented.
- [ ] Client acceptance owner signs the selected flow.
