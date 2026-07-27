# CoreMarket Staff Role Access Matrix

CoreMarket uses the existing Spatie role and permission system. These presets are client staff roles; `Super Admin` remains reserved for the platform owner and is not a client role.

## Role Matrix

| Role | Allowed pages and actions | Forbidden actions | Sensitive data visibility |
| --- | --- | --- | --- |
| Owner / General Manager | Store operations, staff, products, orders, customers, inventory, purchasing, pricing, accounting, POS, and safe storefront settings | Platform/system update, destructive product/category/page deletion, CorePilot controls | Cost, profit, supplier balances, cashbox, reports, customer contact, product prices |
| Store Admin | Existing safe `store_admin` preset for daily store operations and storefront setup | Platform/system settings and destructive actions blocked by the existing store-admin guard | Cost, supplier balances, cashbox, accounting reports, customer contact, product prices |
| Accountant | Accounting reports, expenses, supplier ledger, supplier payments/statements, purchase read access, cashbox reports, sales read access | Product/family/website management, POS selling, destructive catalog actions | Cost, profit, supplier balances, cashbox, accounting reports; customer contact only through permitted order details |
| Cashier | POS, sell, receipts, loyalty redemption, open/close shift, cashbox and customer lookup within POS | Suppliers, purchasing, accounting reports, product management | Product selling prices and assigned cashbox; no cost, profit, or supplier balances |
| Data Entry / Purchasing Clerk | Products and categories create/edit, suppliers, purchase orders, barcode lookup, receipt, purchase return draft | Supplier payment, accounting/profit reports, price lists, destructive product deletion | Cost and product prices needed for purchasing; no profit or supplier balance |
| Warehouse Keeper | Stock dashboard, movements, audit/adjustment, barcode lookup, purchase receiving and returns | Price lists, supplier payments, accounting reports, storefront design | Stock and cost needed for receiving; no profit, cashbox, or supplier balance |
| Delivery / Distribution | Assigned Delivery Board records and operational Picked Up / Out for Delivery / Delivered / Failed updates | General Orders list/detail, products, suppliers, purchasing, accounting, cashbox | Assigned customer phone/address and COD amount only; no cost/profit/supplier data |
| Marketing Employee | Product read access, coupons, flash deals, blogs, and storefront page read access | Supplier, cost, cashbox, accounting and inventory control | Product selling prices; no cost, profit, or supplier balances |
| Designer / Content Employee | Appearance, header/footer, pages, and blog content | Product pricing, suppliers, inventory, cashbox, accounting | No cost/profit/supplier balance; product-image-only access needs a future granular permission |

## Preset Names

- `owner_general_manager`
- `store_admin`
- `accountant`
- `cashier`
- `data_entry_purchasing`
- `warehouse_keeper`
- `delivery_distribution`
- `marketing_employee`
- `designer_content`

Run the presets idempotently:

```powershell
php artisan db:seed --class=Database\\Seeders\\StaffRolePresetSeeder
```

## Known Permission Gaps

- Delivery visibility is scoped by `order_deliveries.delivery_user_id`; the preset does not receive the general Orders list/detail permissions.
- Product image-only editing does not have a granular permission, so the designer preset does not receive product edit access.
- Cost, profit, customer phone, and product-price field-level masking is not a general framework today. Presets avoid sensitive pages where possible; field-level controls are future hardening.
- Supplier payment currently uses `supplier_payments.create`; separate view/manage permissions can be added only when the UI gains distinct actions.

## Demo Role QA

The protected demo seed defines these staff accounts with password `Demo@2026!`:

- `owner@coremarket.demo`
- `accountant@coremarket.demo`
- `cashier@coremarket.demo`
- `data.entry@coremarket.demo`
- `warehouse@coremarket.demo`
- `delivery@coremarket.demo`
- `marketing@coremarket.demo`
- `designer@coremarket.demo`

The existing `inventory@coremarket.demo` account remains as a compatibility alias for the warehouse role.

To apply later to the isolated demo database only:

```powershell
$env:DB_DATABASE="coremarket_demo"
php artisan db:seed --class=Database\\Seeders\\StaffRolePresetSeeder
php artisan coremarket:seed-demo --apply --confirm-demo-seed --with-samples=standard
Remove-Item Env:DB_DATABASE
```
