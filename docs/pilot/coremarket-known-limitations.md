# CoreMarket Pilot Known Limitations

## How to Use This Document

Share the limitations relevant to the selected Pilot before commercial sign-off.
Do not hide a limitation behind a feature flag or a roadmap label.

## Commercial Scope Limitations

- Payment-gateway automation is not included in the current Pilot offer. Existing
  legacy gateway routes are not a statement of production certification.
- Restaurant Mode is not implemented.
- Tables, seating, KOT, kitchen status, and menu modifiers/add-ons are not
  implemented.
- Manufacturing and Production Mode are not implemented.
- BOM, recipes, Production Orders, WIP, yield, and production cost rollup are not
  implemented.
- CoreMarket does not provide official VAT filing.
- Operational accounting/report foundations are not a certified full accounting
  balance sheet, trial balance, statutory close, or jurisdiction-specific filing
  solution.

## Client Instance and Demo Limitations

- The local development database is not a reusable clean client baseline.
- `coremarket-demo` is not synchronized automatically and currently does not
  include every latest source step unless a separate controlled sync is
  requested and verified.
- A passing source route list does not certify the demo/client instance schema,
  feature flags, permissions, or sample data.
- `docs/coremarket-pilot-release-checklist.md` contains dated 2026-07-24
  evidence and older Step 50-56 blockers. Treat it as historical evidence, not
  the current Step 75 readiness decision, until Step 76 refreshes it against the
  selected Pilot instance.
- Demo credentials and fake data must never be reused for a client or production
  environment.
- Protected demo seeding requires an isolated `_demo` database and explicit
  confirmation.
- White-label branding, uploads, contact data, and feature snapshots require
  client-instance review before presentation.

## Web and Flutter Boundaries

- Flutter POS does not yet support serial/IMEI scanning and selection, Pay on
  Account, refund/credit-note workflows, and all latest Web operations.
- Flutter POS source is not changed by Steps 58-75.
- Web Checkout blocks serial-tracked ProductStock variants because safe
  customer-side unit allocation is not implemented.
- Web Storefront uses default branch context until explicit safe branch
  selection is implemented.
- CoreMarket is online-first; full offline inventory synchronization and conflict
  resolution are not Pilot-certified.

## Feature Configuration Limitations

- Branch Pricing is disabled by default and requires deliberate enablement,
  branch data, permission testing, and pricing-priority validation.
- Branch Inventory is disabled by default and requires the dry-run-first
  initialization command before it becomes the source of branch availability.
- `product_stocks.qty` remains an aggregate compatibility mirror when Branch
  Inventory is enabled.
- Price Lists are disabled by default and require customer assignment and
  per-request cache/privacy verification.
- Customer Accounts, credit limits, payment terms, and Pay on Account are
  disabled by default and require customer profiles and acceptance rules.
- Serial/IMEI and Warranty tracking are disabled by default and require
  ProductStock configuration.
- Opening Stock is a setup operation; setup mode should be disabled after
  reconciliation.
- Emergency Inventory Adjustment is disabled by default.

## Financial and Reporting Limitations

- Customer Receivables is an operational AR ledger, not a complete accounting
  engine.
- Supplier and customer statements depend on recorded operational entries; no
  automatic historical backfill is performed.
- Pay on Account creates an AR debit but no cash movement or customer payment.
- Customer Account Credit creates a credit note but no customer payment or cash
  movement.
- COD collection is operational until an authorized settlement posts to an open
  cashbox under the configured rule.
- Production journals, branch P&L, tax filing, and jurisdiction-specific
  accounting certification remain outside the Pilot.

## Hardware Limitations

- Native receipt-printer driver integration is not included.
- Direct raw USB ESC/POS, automatic printing, and cash-drawer opening are not
  implemented.
- Receipt printing relies on the supported Windows printer path and must be
  tested with the actual 58 mm or 80 mm device.
- Barcode scanners are treated as keyboard input and must be configured to send
  one complete code and an Enter suffix.
- A UPS and stable local network are operational recommendations, not software
  guarantees.

## Known QA Warning

`CashboxUiTest` has a known baseline-sensitive route warning in prior QA
reporting. The current canonical routes are:

- `/operations/cashbox` for the Cashbox dashboard.
- `/operations/cashboxes` for Cashbox management.

Both routes are registered in the current source. Before a Pilot, rerun the
Cashbox UI checks against the exact target baseline and treat any route warning
as an environment/release-level issue until verified. Do not rename either route
or infer readiness from `route:list` alone.

## Deferred Roadmap

- Pilot QA cleanup and exact-instance acceptance.
- Demo synchronization only when explicitly requested.
- Client onboarding pack and clean instance preparation.
- Flutter POS feature catch-up.
- Restaurant/KOT only for a validated restaurant client requirement.
- Production/BOM only for a validated manufacturing client requirement.
