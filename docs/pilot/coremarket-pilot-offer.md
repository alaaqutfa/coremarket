# CoreMarket Pilot Offer Structure

## Document Status

This is an editable commercial structure, not an approved price list or binding
offer. Replace every bracketed value after scope review, tax review, and client
approval.

## A. Pilot Offer

### Objective

Configure and validate CoreMarket for one controlled business workflow before a
larger rollout.

### Commercial placeholders

| Item | Editable value |
| --- | --- |
| Setup fee | `[currency] [amount]` |
| Monthly subscription | `[currency] [amount] / month` |
| Pilot duration | `[2/4/6] weeks` |
| Included branch count | `[count]` |
| Included staff accounts | `[count]` |
| Included training sessions | `[count and duration]` |
| Included support hours | `[hours / channel / response window]` |
| Data import allowance | `[products/customers/suppliers row count]` |
| Additional work rate | `[currency] [hour/day rate]` |
| Taxes | `[included/excluded, subject to local review]` |

### Included features

Select only what the Pilot will use:

- Products, variants, SKU, barcode, tax, and baseline pricing.
- Purchasing, suppliers, receipts, payments, returns, and statements.
- Inventory Governance, Opening Stock, adjustments, and stock counts.
- Web POS cash sales, receipts, and cashbox shifts.
- Sales Returns and selected refund method.
- Price Lists and Customer Accounts when configured.
- Branch Inventory, Stock Transfers, and Branch Pricing when configured.
- Delivery and COD when configured.
- Serial/IMEI and Warranty when configured.
- Operational reports and safe PDF/document templates.
- Role presets, staff accounts, and branch assignments.

### Support scope

- Pilot environment configuration.
- Agreed import/template assistance.
- Role and feature-flag configuration.
- Remote or on-site training as stated in the offer.
- Pilot issue triage during the stated support window.
- Acceptance review against the Pilot QA checklist.

### Excluded customizations

- Unlisted custom modules or broad UI redesign.
- Restaurant/KOT, tables, modifiers, and kitchen workflows.
- Manufacturing/BOM and Production Orders.
- Payment-gateway implementation or certification.
- Official tax filing or full accounting certification.
- Flutter POS parity work unless quoted separately.
- Native printer/cash-drawer drivers.
- Third-party integrations, migration of undocumented legacy data, and
  historical ledger backfill unless separately scoped.

### Pilot acceptance

Acceptance is based on the selected rows in
`docs/pilot/coremarket-pilot-qa-checklist.md`, not on every CoreMarket route.
Any deferred or Needs Review capability must be excluded or accepted in writing.

## B. Recommended Package Placeholders

The names below are editable examples. Final modules, limits, and prices require
commercial approval.

### Starter Store

- Suggested fit: one small retail location.
- Example scope: Products, Web POS cash sale, basic inventory, purchasing,
  returns, cashbox, standard documents.
- Branches: `[1]`.
- Staff accounts: `[limit]`.
- Setup: `[currency] [amount]`.
- Subscription: `[currency] [amount/month]`.

### Business Store

- Suggested fit: established retail/electronics/clothing store.
- Example scope: Starter plus variants, Inventory Governance, Serial/Warranty,
  customer accounts, advanced documents.
- Branches: `[1 or more]`.
- Staff accounts: `[limit]`.
- Setup: `[currency] [amount]`.
- Subscription: `[currency] [amount/month]`.

### Wholesale / B2B

- Suggested fit: distributor or store selling on negotiated prices/credit.
- Example scope: Price Lists, customer credit, Pay on Account, payments,
  statements, purchasing/suppliers, delivery/COD.
- Branches: `[count]`.
- Staff accounts: `[limit]`.
- Setup: `[currency] [amount]`.
- Subscription: `[currency] [amount/month]`.

### Multi-Branch Business

- Suggested fit: small chain or distributor with multiple stock locations.
- Example scope: Branch Inventory, initialization, Stock Transfers, Branch
  Pricing, staff branch assignments, branch-aware POS.
- Branches: `[included count and extra-branch price]`.
- Staff accounts: `[limit]`.
- Setup: `[currency] [amount]`.
- Subscription: `[currency] [amount/month]`.

### Enterprise / Custom

- Suggested fit: larger operation with approved custom requirements.
- Scope: `[discovery output and signed statement of work]`.
- Hosting/SLA/security: `[terms]`.
- Setup: `Custom quotation`.
- Subscription: `Custom quotation`.

## C. Implementation Steps

1. **Client information:** legal/trading name, contacts, locations, timezone,
   currency, language, and Pilot owner.
2. **Plan selection:** choose package, limits, Pilot duration, support, and
   explicit exclusions.
3. **Instance setup:** prepare isolated instance, clean baseline, backup policy,
   branding, and approved feature flags.
4. **Product import:** validate categories, products, variants, SKU/barcode,
   taxes, prices, and optional serial policies.
5. **Branches:** create default/active branches, assignments, and initialization
   plan when included.
6. **Users and roles:** create individual accounts, approved presets, and branch
   access; do not share Super Admin.
7. **Opening stock:** import/count, reconcile, approve, post, and sign the
   opening balance.
8. **Training:** manager, cashier, purchasing/data entry, warehouse, accountant,
   and delivery sessions as selected.
9. **Go-live checklist:** complete QA, hardware test, backups, rollback,
   reconciliation, support contacts, and acceptance signatures.

## D. What the Client Must Provide

- Product list with SKU/barcode, categories, variants, unit, tax, cost, regular
  price, Sale price, and optional serial/warranty policy.
- Current stock counted as of an agreed cutoff date and split by branch when
  applicable.
- Branch names, addresses, default branch, and responsible manager.
- Staff names, emails, job roles, and branch assignments.
- Supplier list, opening balances, and required purchasing terms.
- Customer list, Price List assignment, credit permission, limits, terms, and
  approved opening balances when included.
- Approved logo, store name, contact details, colors, invoice/footer text, and
  document requirements.
- Tax rules and a statement of required statutory handling from the client's
  qualified advisor.
- Accepted payment methods and cashbox/shift policy.
- Delivery zones, fees, COD rules, staff, and handoff policy.
- Supported hardware inventory: POS devices, scanners, printers, network, and
  UPS.

## Change Control

- A request outside the signed Pilot scope becomes a documented change request.
- No change is promised verbally during a demo.
- High-risk changes affecting stock, cash, customer balance, or permissions
  require acceptance criteria and rollback steps.
- Restaurant, Production, payment gateway, Flutter catch-up, and third-party
  integrations require separate scope and quotation.
