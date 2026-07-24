# CoreMarket Pilot Release Checklist

## Release Decision

Current decision: **NO-GO until the local demo instance is synchronized**.

The CoreMarket source and Flutter POS Windows build pass their automated release checks. The isolated `coremarket-demo` endpoint is still based on an older application/database state and does not expose the Operations features added in Steps 50-56. A pilot handoff must not start until the demo copy and its `_demo` database are upgraded in a separate, controlled step.

## Pilot Scope

The pilot is intended to demonstrate:

- Operations dashboard and permission-aware quick actions
- Web POS and Flutter POS cash sales
- Product, SKU, and barcode search
- Customer lookup, loyalty earning, and loyalty redemption
- Inventory stock, movements, policy, and product families
- Purchase orders, receipts, and supplier returns
- Supplier payments, ledger, and statement PDF
- Customer price lists
- Purchase and receipt PDFs
- Cashbox and shift operations
- Read-only accounting and operations reports
- Flutter cart draft, pending sync queue, receipt preview/copy, and Windows printer foundation

## Not Included

- Production deployment or CorePilotOS live connection
- Payment gateway processing
- Official VAT filing
- Full double-entry accounting redesign
- Full offline inventory or automatic conflict resolution
- Direct raw USB ESC/POS transport
- Cash drawer control or automatic receipt printing
- Storefront price-list display integration
- Historical supplier ledger or cost snapshot backfill

## Local Demo Environment

- Backend URL: `http://localhost/coremarket-demo`
- Operations API: `http://localhost/coremarket-demo/api/v2/operations`
- Demo database: `coremarket_demo`
- Isolated environment: `C:\xampp\htdocs\coremarket-demo\.env`

The isolated environment must point to `coremarket_demo` and use the demo database for its runtime feature snapshot. Do not change the original CoreMarket `.env`.

Before each pilot:

1. Synchronize the local `coremarket-demo` application copy with the approved CoreMarket commit.
2. Back up `coremarket_demo`.
3. Apply only the approved pending migrations to `coremarket_demo`.
4. Run the protected standard demo seed only when a reset is intentionally required.
5. Verify the Operations routes and required feature flags.
6. Run the read-only screen and API checklist below.
7. Confirm that no real customer data or tokens remain.

## Demo Accounts

These accounts are fake and local-only:

| Role | Email |
| --- | --- |
| Store Admin | `admin@coremarket.demo` |
| Cashier | `cashier@coremarket.demo` |
| Inventory Manager | `inventory@coremarket.demo` |
| Accountant | `accountant@coremarket.demo` |

Use the local demo password documented in `docs/coremarket-demo.md`. Never reuse demo credentials for a client or production installation.

## Required Hardware

- Windows POS computer supported by the current Flutter Windows toolchain
- USB barcode scanner configured as keyboard input with an Enter suffix
- Thermal receipt printer installed through a supported Windows printer driver
- 58mm or 80mm paper matching the selected printer profile
- Stable local network access to the CoreMarket API

Recommended pilot checks:

- Disable scanner prefixes that are not part of the saved barcode.
- Confirm one scan produces one Enter event and one cart addition.
- Confirm the selected printer is visible in Windows before opening the POS app.
- Keep receipt preview/copy available as the printer fallback.

## Backend QA

- [ ] Store Admin login succeeds.
- [ ] Operations dashboard and quick actions load.
- [ ] My Subscription loads with the expected local plan/features.
- [ ] Web POS opens with an active cashier shift.
- [ ] Product search resolves a known SKU and barcode.
- [ ] Customer search returns demo customers.
- [ ] Price-list customer pricing resolves as expected.
- [ ] One cash checkout succeeds, if transactional QA is approved.
- [ ] Official receipt opens and totals match the API response.
- [ ] Purchase Order supports manual and barcode item entry.
- [ ] Purchase receipt updates stock exactly once.
- [ ] Purchase return completes exactly once and updates supplier balance.
- [ ] Supplier payment and ledger display correctly.
- [ ] Supplier Statement PDF downloads.
- [ ] Price Lists, Product Families, and Inventory Policy load.
- [ ] Accounting Reports load without claiming official VAT filing.

## Flutter And Hardware QA

- [ ] `flutter analyze` passes.
- [ ] `flutter test` passes.
- [ ] `flutter build windows` succeeds.
- [ ] The release EXE starts.
- [ ] Cashier login and POS session load from the demo API.
- [ ] F2 restores product-search focus.
- [ ] Scanner typing plus Enter adds one matching product.
- [ ] Quantity and remove controls update the cart correctly.
- [ ] Customer and loyalty information load.
- [ ] Pending Sync remains non-official and cannot print an official receipt.
- [ ] Receipt preview and clipboard copy work.
- [ ] Windows printer discovery lists the installed thermal printer.
- [ ] Printer failure leaves preview/copy available.
- [ ] Cart draft restores after application restart.
- [ ] Logout removes the locally stored access token.

Windows release output:

```text
D:\Work\GitHub\Mobile\CoreMarket\coremarket_pos\build\windows\x64\runner\Release\coremarket_pos.exe
```

Build output is local and must not be committed.

## Current QA Evidence

On 2026-07-24:

- Backend route inventory completed successfully.
- Store Admin web login/logout succeeded against `coremarket-demo`.
- Operations, Web POS, Purchase Orders, Purchase Receipts, Suppliers, and My Subscription returned HTTP 200.
- Cashier API login, open shift on `DEMO-MAIN`, SKU search, and customer search succeeded.
- No checkout was performed.
- The temporary API QA token was deleted.
- Backend regression filters passed after applying the three Step 50-53 migrations temporarily to `coremarket_testing`; they were rolled back afterward.
- Flutter analyze passed with no issues.
- Flutter tests passed: 35 passed and 2 skipped.
- The opt-in live Flutter API test passed cashier login, session, SKU search, customer search, and logout; its checkout test remained disabled.
- Flutter Windows release build succeeded and the EXE remained running during a smoke launch.
- Windows exposes two virtual printers only: OneNote and Microsoft Print to PDF. A physical thermal printer was not available for validation.
- Scanner behavior, printer fallback, cart draft, pending queue, and receipt formatting passed automated tests; physical scanner/printer validation remains required.

## Known Limitations And Blockers

### Pilot blockers

- `C:\xampp\htdocs\coremarket-demo` is not synchronized with the current CoreMarket source.
- `coremarket_demo` has the Step 50-53 migrations pending.
- The stale demo endpoint returns 404 for Purchase Returns, Price Lists, Product Families, Inventory Policy, Supplier Statement PDF, and Accounting Reports.
- No physical USB scanner or thermal printer was available for this QA run.

### Accepted limitations

- Profit and COGS can be estimated when historical cost snapshots are missing.
- Tax reports are informational and are not official VAT filings.
- Supplier balances cover ledger entries from the supplier-ledger foundation onward; no historical backfill is created.
- Storefront price-list display remains pending Step 58.
- Direct ESC/POS USB, auto-print, and cash drawer support remain future work.
- CorePilotOS deployment or live synchronization is outside this release step.

## Go / No-Go

Mark **GO** only when every blocker below is closed:

- [ ] Demo application copy matches the approved backend commit.
- [ ] Demo database is backed up and approved migrations are applied.
- [ ] All required Operations pages return success on the demo endpoint.
- [ ] Backend and Flutter automated checks pass after synchronization.
- [ ] A physical scanner passes SKU/barcode, Enter, duplicate-scan, and F2 checks.
- [ ] A physical thermal printer is discovered and prints a 58mm or 80mm test receipt.
- [ ] Receipt preview/copy fallback is confirmed.
- [ ] At most one approved end-to-end checkout is completed and reconciled.
- [ ] No real data, persistent QA token, build artifact, or secret is included.
- [ ] Rollback and support contacts are agreed before the client visit.

If any item remains open, keep the decision **NO-GO** and document the owner and follow-up step rather than changing business logic during release QA.
