# CoreMarket Customer Credit Policy

## Scope

Step 67 adds an optional credit-policy profile above the Step 66 customer receivables ledger. It does not add public credit checkout, POS credit payment, accounting journals, historical backfill, reminders, or an automatic manager override.

Customer balance remains ledger-derived:

`balance = posted debits - posted credits`

The profile never stores a balance.

## Feature Flags

- `customer_accounts.enabled=false`: AR and credit-profile pages are unavailable, and the operational Customer Statement remains active.
- `customer_accounts.credit_limits_enabled=false`: Step 66 manual AR posting remains available without credit-profile enforcement.
- `customer_accounts.payment_terms_enabled=false`: due-date and overdue enforcement are disabled; aging remains an invoice-age estimate.

All flags default to disabled.

## Credit Profile

Profiles are created on demand in `customer_account_profiles`; customers are not backfilled.

- `is_credit_allowed` controls whether credit may be granted when enforcement is enabled.
- `credit_limit` is an optional USD limit.
- `payment_terms_days` determines the due-date snapshot for new posted invoices.
- `account_status` is `active`, `on_hold`, or `blocked`.
- Review actor and review time are recorded.

## Calculations

- Current balance: ledger debits minus credits.
- Available credit: `max(0, credit_limit - max(0, current_balance))`.
- Projected balance: current balance plus the order total.
- Overdue balance: outstanding invoice allocations whose saved `due_date` is before today.
- If no due-date snapshot exists, the invoice is not claimed as overdue. General aging may still use its posting date as an estimate.

## Credit Decision

The service returns an allow/deny result and one reason:

- `feature_disabled`
- `account_disabled`
- `account_on_hold`
- `account_blocked`
- `credit_not_allowed`
- `over_credit_limit`
- `overdue_balance`
- `ok`

When limit enforcement is enabled, manual **Post to Customer Account** is blocked for missing, held, blocked, disallowed, or over-limit profiles. When payment terms are enabled, an overdue posted invoice also blocks new credit.

New invoice entries snapshot payment terms, due date, credit limit, and currency in ledger metadata. Existing entries are not changed.

## Permissions

- `customer_credit.view`
- `customer_credit.manage`
- `customer_credit.override_limit`

Managers receive all three permissions. Store Admins and Accountants may view and manage profiles. The override permission is reserved for managers, but Step 67 deliberately adds no automatic bypass: a future audited override workflow must require an explicit reason and snapshot.

Cashiers, Delivery, Marketing, and Designer roles cannot manage credit profiles.

## Checkout Boundary

Step 67 does not expose a public “pay on account” method and does not change Web checkout, Web POS, Flutter POS, or `order.payment_status`. The safe workflow remains manual posting from an existing unpaid Order.

## Future

- Credit payment method in Web/POS.
- Audited manager override with reason and approval snapshot.
- Credit review workflow and limits by plan.
- Overdue reminders and collection tasks.
- Official accounting journal integration.
