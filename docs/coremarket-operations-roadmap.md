# CoreMarket Operations Roadmap

## Purpose and scope

This document locks the implementation order after the Operations live QA. It is an audit and architecture plan, not approval to build the complete purchasing or accounting workflows in one change.

The current POS, storefront, inventory, purchasing, loyalty, cashbox, and accounting foundations must remain operational while the work is delivered in small, testable phases. CorePilotOS, Flutter POS, database baselines, and generated frontend assets are outside this roadmap step.

## Locked policies

1. All monetary display and conversion must go through one application service/helper. Operations views must not format raw database values independently.
2. The transactional base currency is USD. Its conversion rate must be normalized to `1`. A display currency such as LBP is represented relative to USD; the acceptance example is `1 USD = 89,500 LBP`.
3. UI money displays use no more than two decimal places. Stored values retain the existing database precision. Currency-specific precision can be added later without permitting every view to choose its own formatting.
4. Orders and purchasing documents preserve immutable currency, exchange-rate, tax, price, and cost snapshots. The server remains the source of truth for totals.
5. A tax is selected per item through an explicit enabled state and tax rate. Manual tax amounts are not the long-term source of truth.
6. A received purchase invoice is the normal positive-stock boundary in strict inventory mode. Product creation initializes stock at zero in that mode.
7. Negative stock is disabled by default. Any future override must be an explicit setting and pass through one stock policy service used by POS, storefront, API, and inventory adjustments.
8. Purchase receipt pricing supports an explicit mode: fixed sale price or margin-derived sale price. Both the input and calculated result are snapshotted.
9. POS and eCommerce share products, stock, pricing, tax, customer, and accounting domain rules. Channel-specific controllers must not implement conflicting calculations.
10. Purchase PDF output follows a finalized purchase invoice snapshot; it must not reconstruct historical totals from mutable product/settings data.

## Current audit findings

### Money and currency

- `app/Http/Helpers.php` provides `convert_price`, `format_price`, and `single_price`, but many Operations views use raw values or independent `number_format` calls.
- Confirmed raw or locally formatted output exists across POS receipts, cashbox pages, purchasing pages, expenses, returns, accounting events, and loyalty order traces.
- The base currency is selected through `system_default_currency`; currencies store an `exchange_rate`. There is no explicit immutable transaction-rate policy shared by all Operations workflows.
- The current conversion formula assumes coherent rates relative to a base. The audited testing data has USD selected as base while its rate is not `1`, so adding LBP at `89,500` without normalization would produce an incorrect result.
- The configured decimal count in the audited testing data is `0`, which does not meet the desired two-decimal Operations presentation.
- The request-header currency path depends on session exchange-rate state instead of reliably resolving the requested currency. This must be corrected with service tests before exposing currency switching broadly.

Recommended target:

- Introduce a `MoneyFormatter` and `CurrencyConversionService` (or equivalent existing-style services).
- Normalize USD as base rate `1`, validate positive rates, and test USD/LBP in both directions.
- Replace raw Operations rendering incrementally, starting with purchasing, POS/cashbox, and accounting reports.
- Add currency/rate/base-total snapshots to purchasing and any other documents that currently depend on mutable settings.

### Tax

- The legacy `taxes` and `product_taxes` path has Super Admin management and category/type-oriented tax behavior.
- The accounting foundation also has `tax_rates` and `tax_snapshots`, including inclusive/exclusive calculation support, but no complete Super Admin CRUD/default-rate workflow.
- Purchasing currently accepts a manual `tax_amount` per item and does not select a `tax_rate_id` or preserve a calculation snapshot.
- A general tax should be implemented through the accounting `tax_rates` foundation rather than adding a third tax system. A compatibility/mapping decision for legacy product taxes is required.

Recommended target:

- Add guarded Super Admin CRUD for general tax rates and an optional default tax setting.
- Add an item-level taxable toggle and rate selection to purchase invoices.
- Store tax rate, mode, base, amount, and identifying snapshot on each posted document item.
- Keep tax filing and VAT posting out of scope until calculation and snapshot tests are complete.

### Purchase orders and invoices

- The Create Purchase Order page has product and stock dropdowns but no scanner-first product lookup.
- The Add Item failure was caused by the view using `@push('script')` while the backend layout renders `@yield('script')`; the script was absent from the response. The isolated fix changes the view to the layout's section convention and adds a rendering regression test.
- The form has quantity, unit cost, manual tax, and discount. It has no margin/fixed-sale-price controls or structured tax-rate selection.
- Receiving is idempotent, locks relevant records, increases stock, updates the product purchase price, writes inventory movements, and records its existing accounting event.
- Receiving does not update variant sale prices or product sale price from a declared fixed/margin policy.
- Purchase receipts are operational receipt records, not yet complete supplier invoices: supplier invoice identity, due/payment state, conversion snapshot, PDF, and payable allocation are missing.

Recommended purchase-invoice flow:

1. Scanner/search selects an exact stock/SKU/barcode record.
2. Each line captures quantity, unit cost, tax rule, discount, and fixed-price or margin policy.
3. Server calculates line and document totals in transaction and base currency.
4. Posting/receiving updates stock, cost, selected sale-price policy, inventory movement, and supplier payable atomically.
5. The posted snapshot becomes the source for PDF, returns, statements, and accounting.

### Supplier accounting

- Suppliers currently have identity/contact metadata and purchase-order relations, but no authoritative balance, payments, allocation, aging, or statement ledger.
- Cash, credit, and partial payment behavior therefore cannot be represented safely by the current purchase receipt alone.

Recommended schema plan:

- `supplier_ledger_entries`: invoice, payment, return, adjustment, debit/credit, transaction/base currency values, exchange rate, dates, reference, and idempotency metadata.
- `supplier_payments`: payment source/method, currency/rate, amount, status, reference, and actor.
- `supplier_payment_allocations`: many-to-many allocation of payments to posted supplier invoices.
- Purchase invoice fields or a dedicated posted invoice table for supplier invoice number/date, due date, status, totals, and snapshots.
- `purchase_returns` and `purchase_return_items`, valued from the posted purchase cost snapshot and linked to inventory-out and supplier-credit entries.

Accounting journals are a later integration phase after the payable and posting policy is approved; this audit does not create journals.

### Inventory policy

- There is no `allow_negative_stock` or strict purchase-only entry setting today.
- POS and storefront checkout reject insufficient stock, and manual inventory adjustment also prevents a negative target.
- Product creation can set initial stock directly, and manual adjustments can add stock, so purchase receiving is not the only inbound path.

Recommended settings and enforcement:

- `inventory_entry_mode`: `flexible` or `purchase_only`.
- `allow_negative_stock`: boolean, default `false`.
- One `InventoryPolicyService` must authorize all stock changes by channel and reason.
- In `purchase_only`, product creation starts at zero and positive manual adjustment requires a separate exceptional permission and audit reason, or is blocked.

### Purchase PDF

- The project already uses mPDF for sales invoice output.
- There is no purchase invoice/receipt PDF route or template.
- Reuse the existing PDF stack only after a posted purchase snapshot exists. The template should receive resolved store name, logo fallback, accent color, currency, supplier data, item tax/cost details, and totals from a dedicated payload builder.

### Navigation and dashboard

The sidebar currently mixes a newer Operations group with older product, order, POS, and setup sections. The proposed order is:

1. Dashboard
2. Sell: POS, Orders, Sales Returns
3. Catalog: Products, Categories, Brands, Pricing and Tax
4. Inventory: Stock, Barcode, Movements, Low Stock, Audit
5. Purchasing: Suppliers, Purchase Invoices/Orders, Receipts, Returns, Payments and Statements
6. Customers and Loyalty
7. Finance: Cashbox, Expenses, Accounting and Tax
8. eCommerce, Content and Marketing
9. Reports
10. Settings and System

The dashboard should add a permission- and feature-aware quick action grid for New POS Sale, New Purchase Invoice, Receive Stock, Add Product, Barcode Lookup, New Expense, Shift Action, Supplier Payment, Inventory Audit, and Reports.

### Authentication and phone forms

- Web login supports email or phone with country code, and shared auth JavaScript uses an international telephone input.
- Phone normalization and uniqueness are inconsistent: some checks use raw phone only, some combine country code, and one registration layout contains an Iraq-specific client pattern.
- API registration does not yet demonstrate one shared E.164 normalization path with the web forms.

Recommended target:

- Introduce one `PhoneNumberNormalizer` used by web/API validation, persistence, login, and lookup.
- Store or derive a normalized E.164 value and enforce a single uniqueness policy.
- Remove layout-specific country assumptions and add country-code/national-number tests for every auth layout and API.
- Audit/backfill existing numbers before adding a unique normalized index.

### Accounting reports

Current foundations include accounting dashboard data, events, journals, general ledger, trial balance, profit/loss, VAT snapshots, and operational revenue/COGS/expense summaries. They are not yet a complete accounting reporting suite.

Missing or incomplete reports include:

- Balance sheet and asset/liability/equity reconciliation.
- Supplier payable statements and aging.
- Customer receivable statements and aging.
- Inventory valuation by an approved costing method and GL reconciliation.
- Filing-grade input/output tax reports.
- Cash flow and hardened channel profitability.

Implementation order: monetary invariants, purchase invoice/payables, inventory costing, journal coverage and reconciliation, hardened P&L, balance sheet, supplier/customer aging, tax reports, then cash flow.

## Migration plan

No migrations are part of this audit. Candidate migrations should be split and reviewed independently:

1. Monetary and document snapshots: transaction/base currency, exchange rate, base totals, and explicit rounding policy where missing.
2. Purchase invoice/tax/pricing snapshots: supplier invoice identity, due/status fields, tax rate snapshot, pricing mode, margin, and resulting sale price.
3. Supplier payable ledger, payments, and allocations.
4. Purchase return header/items and references to original posted costs.
5. Optional normalized phone column/index after a production-data audit.

Settings such as strict inventory and negative stock should use the established business-settings mechanism unless schema evidence requires otherwise.

Step 50 delivers the additive supplier accounting and purchase return foundation from items 3 and 4. The supplier ledger is an operational subledger, not double-entry accounting: received purchases are credits that increase the USD amount owed, while supplier payments and completed purchase returns are debits that decrease it. Payment allocation, aging, journal posting, statement PDF, and supplier portal workflows remain deferred.

## Product pricing vocabulary

- `cost_price`: the supplier purchase cost captured by purchasing and inventory cost snapshots.
- `regular_price` / `retail_price`: the default official selling price.
- `sale_price`: a temporary promotion or discount price. It is not a customer pricing level.
- `price_list_price`: a future customer- or segment-specific price. Price Lists A/B/C belong to this concept and are not implemented by the money foundation.

## Delivery phases

| Phase | Scope | Exit criteria |
| --- | --- | --- |
| 47 | Audit, roadmap, Add Item rendering fix | Roadmap approved; focused regression passes |
| 48 | Money, currency, and tax foundation | USD base/rate policy, LBP conversion tests, two-decimal money helpers, tax calculation snapshot |
| 49 | Purchase Invoice + Barcode + Cost/Regular/Sale Price workflow | Scanner lookup, tax selection, fixed/margin pricing, atomic stock and cost snapshots |
| 50 | Supplier Accounting + Purchase Returns foundation | Payable ledger design, cash/credit/partial payments, statements, and cost-based return policy |
| 51 | Inventory Strict Mode + Negative Stock Policy | Central negative-stock and purchase-only inbound enforcement |
| 52 | Price Lists + customer pricing levels A/B/C | Explicit customer/segment price lists without overloading `sale_price` |
| 53 | Product Family / Sub Family + inventory classification | Approved internal hierarchy, migration, filters, imports, and reporting; this is not a storefront category |
| 54 | Purchase Invoice PDF + Supplier Statement PDF | Branded immutable purchase and supplier documents after ledger stabilization |
| 55 | Accounting Reports foundation | Valuation, balance sheet, aging, tax, and reconciliation in approved order |
| 56 | Sidebar and dashboard | Permission-aware navigation order and quick actions |
| 57 | Phone normalization | Shared E.164 flow and web/API regression coverage |
| 58 | Unified channel regression | POS/storefront purchasing, stock, money, tax and accounting contract tests |
| Later | Flutter mobile POS | Camera barcode scanning after backend contracts are stable |

Step 49 intentionally reuses `products.unit_price` as the regular retail price and the existing product promotion discount fields for an explicitly supplied temporary `sale_price`. Price Lists A/B/C remain a separate Step 52 concept. Purchase and supplier statement PDFs remain deferred until Step 54, after the operational supplier ledger has stabilized.

## Inventory policy settings

Step 51 uses the existing business-settings mechanism with config fallbacks; it does not add an inventory settings table.

- `inventory.strict_inventory_mode`: defaults to `false`. When enabled, new or duplicated products start with zero stock, existing quantities are preserved during product edits, and inbound stock must come from purchase receipts, sales returns, or authorized adjustments.
- `inventory.allow_negative_stock`: defaults to `false`. When disabled, every protected stock-decrease path rejects a quantity that would make a variant negative. When enabled, decreases may pass below zero while the existing inventory movement remains the audit record.

Strict inventory mode protects inventory and costing discipline. Negative-stock permission is a per-instance policy. Price Lists A/B/C and Family/Sub Family remain outside Step 51.

## Explicit non-goals for this step

- No complete accounting or purchasing workflow.
- No tax/VAT posting policy change.
- No baseline, CorePilotOS, Flutter, payment gateway, or generated asset changes.
- No broad sidebar/dashboard redesign.
- No purchase PDF or mobile barcode implementation.
