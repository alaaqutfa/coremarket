# CoreMarket Sales Demo Script

## Core Message

> CoreMarket helps retail and wholesale businesses manage products, POS sales,
> inventory, branches, suppliers, customer accounts, returns, delivery, and
> reports from one practical platform.

## Presenter Rules

- Use a prepared scenario that matches the client's industry.
- Show business outcomes before configuration screens.
- Use only fake or approved Pilot data.
- Never imply that Pilot features are statutory accounting or tax
  certification.
- Never promise Restaurant, Manufacturing, payment-gateway automation, or
  Flutter feature parity as already delivered.
- Keep a manager session and a restricted staff session ready.
- Stop transactional actions if stock, cashbox, or customer balances do not
  match the rehearsed starting state.

## 15-Minute Demo

### Opening: 1 minute

"CoreMarket brings daily store operations into one practical system: products,
sales, stock, purchasing, customer accounts, delivery, and operational
reporting. Today I will show the shortest complete flow from stock to sale and
control."

### Pain points: 2 minutes

Ask:

- Where do stock differences appear today?
- Can managers see what each cashier, buyer, and warehouse employee changed?
- Are supplier and customer balances tracked in separate files?
- Does each branch know its actual stock and selling price?

Reflect the top two answers using the client's language.

### Features to show: 9 minutes

1. Operations dashboard and role-aware navigation.
2. One Product with SKU/barcode, regular price, and current stock.
3. One Purchase Receipt or Inventory Movement proving documented stock entry.
4. Web POS scan/search, customer selection, server-calculated price, and cash
   sale.
5. Receipt and branch/default stock decrease.
6. Cashbox shift expected amount.
7. One relevant differentiator:
   - Price List and Pay on Account for B2B.
   - Serial/IMEI and Warranty for electronics.
   - Stock Transfer and Branch Price for multi-branch.
   - Delivery and COD settlement for delivery businesses.
8. One operational report or customer/supplier statement.

### What not to show yet: 1 minute

- Unrehearsed setup screens.
- Payment-gateway automation.
- Full Restaurant/KOT or Manufacturing/BOM.
- Flutter workflows that do not match the current Web feature set.
- Statutory tax/accounting claims.

### Closing pitch: 1 minute

"The Pilot is designed to validate your real products, staff roles, stock
controls, sales flow, and reports before a wider rollout. We configure only the
features your team needs, train the key users, and measure the result against an
agreed checklist."

### Client questions: 1 minute

- Which single location and team should run the Pilot?
- Which daily process causes the most errors today?
- Do you sell only for cash, or also on customer account?
- Which report must be correct on the first week?

## 30-Minute Demo

### Opening: 2 minutes

State the Core Message, confirm the client's industry, and set the boundary:
"This is a practical Pilot walkthrough, not a generic tour of every menu."

### Pain discovery: 5 minutes

Ask:

- How are products, variants, barcodes, and prices maintained?
- How is stock received, counted, adjusted, and moved between branches?
- Who may approve purchasing, refunds, and customer credit?
- How are supplier payments and customer receivables tracked?
- How are deliveries and COD reconciled?

Select one primary flow and one control flow.

### Features to show: 18 minutes

1. Staff Roles: compare Manager and Cashier/Warehouse visibility.
2. Product identity: SKU, barcode, variant, pricing, tax, and optional
   serial/warranty.
3. Stock entry: Purchase Receipt or Opening Stock with Inventory Movement.
4. POS: customer, price resolution, branch stock validation, payment, receipt.
5. Return: completed Sales Return plus Cash Refund or Account Credit.
6. Cashbox: shift, cash movement, and close expectations.
7. Industry track:
   - Retail: stock count and negative-stock policy.
   - Electronics: serial sale and warranty claim.
   - B2B: Price List, credit decision, Pay on Account, payment allocation.
   - Multi-branch: Transfer lifecycle and Branch Pricing.
   - Delivery: assignment, COD collection, and settlement.
8. Documents: invoice, statement, receipt, or label template.
9. Reports: operational sales, stock, supplier, customer, or accounting
   foundation.

### What not to show yet: 2 minutes

State the relevant known limitations directly. Do not open unrelated owner,
legacy, or experimental pages.

### Closing pitch: 2 minutes

"The value of the Pilot is not only creating sales. It is proving that stock,
cash, customer credit, supplier activity, and staff responsibility remain
consistent through a complete business day."

### Client questions: 1 minute

- What data can you provide for a Pilot?
- Who owns acceptance for stock, cash, and customer balances?
- Which integrations or custom documents are mandatory rather than optional?

## 60-Minute Deep Demo

### Opening and scope: 5 minutes

- Present the Core Message.
- Confirm the selected business flow and excluded modules.
- Explain Ready, Pilot, and Deferred classifications.
- Confirm that the session uses prepared non-production data.

### Operational discovery: 10 minutes

Ask:

- Number of branches, cashiers, warehouses, and daily transactions.
- Product types, variants, serial/IMEI, and barcode quality.
- Purchasing, supplier-payment, and return approval process.
- Cash, customer credit, limits, terms, and overdue policy.
- Delivery volume and COD handoff process.
- Required documents, tax fields, and management reports.
- Hardware, network, and training constraints.

### End-to-end features: 35 minutes

1. Access and governance: individual login, role preset, branch assignment.
2. Catalog: Product, ProductStock variant, pricing layers, tax, serial policy.
3. Inventory setup: strict mode, Opening Stock, audit trail.
4. Purchasing: Supplier, Purchase Order, Quick Product Create, Receipt.
5. Branch operations: Branch Stock, Transfer ship/receive, aggregate mirror.
6. Sales: Web POS cash sale using branch/customer pricing and stock validation.
7. Customer accounts: credit decision, Pay on Account, ledger, payment,
   allocation, statement.
8. Returns: stock return, Cash Refund, or Customer Account Credit.
9. Electronics option: serial receipt/sale/return and Warranty Claim.
10. Delivery option: assignment, status, COD collection, settlement.
11. Cashbox: movements, shift expected cash, close control.
12. Documents: Sales Invoice, Customer/Supplier Statement, Delivery Note,
    labels, safe templates.
13. Reports: stock, movements, receivables, supplier balance, COD, and
    operational accounting views.

### What not to show yet: 3 minutes

- Restaurant tables, KOT, modifiers, and recipe consumption.
- BOM, Production Orders, WIP, yield, and production cost.
- Official tax filing or audited financial statements.
- Payment gateway automation.
- Flutter-only promises for features currently available only on Web.

### Pilot proposal: 5 minutes

- Choose one branch and a limited set of users.
- Select only required feature flags.
- Import a controlled product/customer/supplier sample.
- Reconcile opening stock.
- Train manager, cashier, purchasing, and warehouse owners.
- Run an agreed Pilot period with daily issue tracking.
- Review acceptance evidence before expansion.

### Closing questions: 2 minutes

- What are the three acceptance numbers: stock, cash, and receivables?
- Which staff members will be Pilot owners?
- What must be customized before training?
- What is the preferred Pilot start date and duration?
- What would make the Pilot a clear success or failure?

## Presenter Recovery Lines

If a feature is disabled:

"This capability is feature-gated. We enable it only when the business process,
permissions, and starting data are approved."

If a feature is deferred:

"That workflow is on the roadmap, but it is not included in this Pilot offer. I
would rather show a reliable boundary than promise an incomplete process."

If data is inconsistent:

"I am stopping the transaction here so we do not hide a reconciliation issue.
The Pilot checklist requires us to verify the starting stock/cash/account state
before continuing."
