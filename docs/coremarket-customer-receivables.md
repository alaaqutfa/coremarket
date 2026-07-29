# CoreMarket Customer Receivables

## Scope

Step 66 adds an optional customer accounts receivable ledger. It reuses Orders, customers, Cashbox shifts, Cash Movements, Sales Returns, permissions, money formatting, and the existing Customer Statement PDF. It does not add double-entry accounting, VAT filing, payment gateways, wallet changes, loyalty changes, or historical backfill.

The feature is disabled by default through:

`customer_accounts.enabled=false`

When disabled, Orders, Storefront, POS, and the operational Customer Statement continue to behave as before. No ledger entry is posted automatically.

## Ledger Rule

- A debit increases the amount the customer owes.
- A credit decreases the amount the customer owes.
- Customer balance equals posted debits minus posted credits.
- Invoice entries use the saved Order total and never mutate historical Order totals.
- Every Order invoice entry has an idempotency key, so the same Order cannot be posted twice.
- Existing Orders and Sales Returns are not backfilled.

## Invoice Posting

Authorized staff can use **Post to Customer Account** from an unpaid customer Order. Posting creates one debit entry linked to the Order. Paid or partially paid Orders are not posted through this conservative foundation because their existing payment data is operational and is not an allocation ledger.

Step 71 automatically posts only explicitly selected, policy-approved `pay_on_account` Orders from Web POS or Web Checkout. Other Orders remain manual and no historical Order is backfilled.

## Customer Payments

Customer payments create:

- One `customer_payments` record.
- One credit `customer_ledger_entries` record.
- Optional allocations to one or more posted invoice entries.

Allocations use database transactions and row locks. Negative allocations, over-allocation, allocation beyond the payment amount, and allocation to another customer are rejected.

Cash payments require an open Cashbox shift. A successful cash payment creates one idempotent cash-in movement and updates expected shift cash. Non-cash methods do not require an open shift and do not create a Cash Movement.

Order `payment_status` is not changed by this foundation. Allocated and outstanding amounts are derived from the ledger and allocations.

## Customer Statement

When customer accounts are enabled, the existing Customer Statement PDF uses posted ledger entries and shows opening, running, and closing balances.

When disabled, the existing operational statement remains available and continues to use available Orders, paid status/amounts, and completed Sales Returns. The PDF labels the two modes clearly.

## Aging

The read-only summary groups outstanding posted invoice entries by age:

- Current
- 1-30 days
- 31-60 days
- 61-90 days
- 90+ days

This is an age estimate from each invoice ledger entry date. Credit limits and payment terms are not implemented in Step 66.

Step 67 adds optional on-demand credit profiles and due-date snapshots. When payment terms are enabled, new invoice entries store `payment_terms_days` and `due_date` in metadata and aging uses that due date. Existing ledger entries are not backfilled or mutated.

## Credit Policy

- Customer balance remains derived from ledger debits minus credits.
- Credit profiles store permission, limit, terms, and status, but never a balance.
- Available credit equals the limit minus the positive current ledger balance.
- Limit enforcement and payment-term enforcement have separate disabled-by-default feature flags.
- Manual Order posting is blocked by the credit policy only when its relevant feature is enabled.
- Step 71 adds disabled-by-default Web/POS Pay on Account without a manager bypass.

## Pay on Account Posting

An approved account sale creates one invoice debit with `order:{order_id}:pay_on_account`. The ledger snapshots its POS/Web source, branch, payment terms, due date, credit limit, and payment method.

No `customer_payments` row is created because no money was received. No Cash Movement is created. The Order stays `unpaid`; later settlement occurs through the existing Customer Payment and allocation workflow.

See `docs/coremarket-customer-credit-policy.md` for the decision reasons and formulas.

## Sales Return Credit Notes

Step 72 can post all or part of a completed Sales Return as an AR credit note. The ledger entry is a `credit`, so it reduces the customer balance and appears in the ledger-based Customer Statement.

The refund record and credit note are idempotent. Account credit creates no Customer Payment and no Cash Movement. Existing Sales Returns are not backfilled, and `order.payment_status` is not changed.

## Permissions

- `customer_receivables.view`
- `customer_receivables.manage`
- `customer_payments.create`
- `customer_payments.cancel`
- `customer_ledger.view`
- `customer_statements.ledger_export`

Managers and Store Admins can manage receivables. Accountants can view ledgers, record payments, and export statements. Cashiers can record cash payments only through their own open shift. Delivery, Marketing, and Designer roles do not receive AR permissions.

## Future Work

- Flutter POS support for approved credit checkout.
- Customer credit limits and payment terms.
- Accounting journal integration.
- Refund approval and credit-note cancellation workflow.
- Overdue reminders.
- Full AR aging and collection workflows.
- Audited manager credit-limit override.
