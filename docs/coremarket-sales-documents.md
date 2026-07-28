# CoreMarket Sales Documents

## Scope

Step 65 extends the existing mPDF and Document Template foundations. It does not add accounting journals, an accounts receivable ledger, payment processing, notifications, or printer integration.

## Sales Invoice

- Operations route: `/operations/orders/{order}/invoice-pdf`.
- Template type: `sales_invoice`.
- Uses saved order number, date, payment type/status, customer snapshot, order item prices, tax, shipping, discounts, paid amount, and stored grand total.
- The saved final order price is shown. Other customer Price Lists, product cost, profit, and supplier data are never included.
- POS receipt and Sales Invoice remain separate: the receipt is cashier/thermal oriented, while the Sales Invoice is an A4 customer document.

## Customer Statement

- Operations route: `/operations/customers/{customer}/statement/pdf`.
- Template type: `customer_statement`.
- Supports `date_from` and `date_to`.
- When `customer_accounts.enabled` is enabled, the statement uses posted AR ledger entries, customer payments, and allocations from Step 66.
- When the feature is disabled, it retains the operational fallback based on available Orders, order payment status/paid amount, and completed Sales Returns.
- No historical Orders or returns are backfilled automatically.
- COD settlement is not counted as a customer payment because Step 64 intentionally leaves order payment status unchanged.
- Step 66 is a receivables foundation, not a full double-entry accounting or official VAT system.

## Delivery Note and Packing Slip

- Routes:
  - `/operations/orders/{order}/delivery-note-pdf`
  - `/operations/orders/{order}/packing-slip-pdf`
- Template types: `delivery_note` and `packing_slip`.
- Include order, customer contact/address, delivery status/employee, optional COD amount, product identity, SKU/barcode, and quantities.
- Exclude selling prices in packing documents as well as cost, profit, supplier, and accounting information.
- Delivery employees can export only documents for deliveries assigned to them.

## Order Routes

- `/admin/all_orders` remains the official order list.
- `/admin/orders` remains registered as a compatibility alias and redirects to `/admin/all_orders`.
- Existing route names and storefront invoice links remain available.

## Permissions

- `sales_invoices.view`, `sales_invoices.export`
- `customer_statements.view`, `customer_statements.export`
- `delivery_notes.view`, `delivery_notes.export`

Managers and Store Admins receive all sales-document permissions. Accountants receive Sales Invoice and Customer Statement access. Cashiers receive Sales Invoice access scoped to their own cashier orders. Delivery staff receive assigned Delivery Note/Packing Slip access only. Template designers may manage safe visual templates but do not receive customer financial data permissions.

## Template Security

- No raw HTML, Blade, PHP, or JavaScript editor exists.
- Colors, columns, dimensions, margins, footer text, and display switches remain server validated.
- Templates resolve to an active default and fall back safely when no stored template is available.
