# CoreMarket Pilot v0.1 Release Notes

Release tags:

- Backend: `v0.1-coremarket-pilot`
- Windows POS: `v0.1-coremarket-pos-windows-pilot`

## Included

- Products, variants, SKU/barcode, Price Lists, sale prices, and Branch Pricing.
- POS and Web Checkout with server-side pricing and stock validation.
- Branch Inventory, stock transfers, opening stock, adjustments, counts, and
  inventory movement audit trails.
- Purchasing, suppliers, purchase receipts/returns, and supplier documents.
- Customer receivables, credit limits, payment terms, Pay on Account, customer
  payments, statements, returns, cash refunds, and credit notes.
- Delivery assignment, COD collection, and COD cashbox settlement.
- Cashboxes, shifts, operational accounting/report foundations, and sales
  documents.
- Serial/IMEI stock identity, warranty policies, and warranty claims.
- Safe document templates for purchasing, sales, statements, receipts, and
  product labels.
- Flutter Windows POS for cash sales, online account sales, branch-aware
  pricing/stock, serialized sales, receipts, printing foundation, and a
  cash-only offline queue.

## Suitable Pilot Industries

- Supermarket and general retail.
- Mobile and electronics stores.
- Clothing and footwear stores.
- Wholesale/B2B distribution.
- Small multi-branch retail businesses.

## Not Included Yet

- Payment gateway automation.
- Full Restaurant/KOT, table, waiter, or modifier workflows.
- Manufacturing, BOM, recipes, or production orders.
- Official VAT filing or a complete statutory accounting suite.
- Flutter return/refund, credit-note, and warranty-management screens.
- Offline Pay on Account or offline serialized-item sales.

## Known Limitations

- Deployment requires a separately approved database migration rehearsal
  because runtime/demo databases were not synchronized in this release step.
- Branch Inventory requires reviewed initialization before it becomes the
  active stock source.
- The Windows printer flow needs acceptance with the client's actual printer.
- Returns/refunds and warranty claims are handled in the Web Operations UI.
- The committed testing SQL baseline is current, but the local
  `coremarket_testing` database was restored to its older pre-audit state.

## Pilot Recommendation

Use v0.1 for a supervised, limited-scope pilot with prepared master data,
trained staff, verified backups, and daily reconciliation. Begin with one store
or one branch, validate cashbox and stock flows, then expand only after the
client signs off on pricing, inventory, documents, and reporting.
