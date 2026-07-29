# CoreMarket Pay on Account

## Scope

Step 71 adds the canonical `pay_on_account` payment method to Web POS and Web Checkout. It reuses Customer Receivables, Customer Credit Policy, branch-aware pricing, branch inventory, and stored Order totals.

The feature does not collect money. It does not create a Cash Movement, Customer Payment, payment-gateway transaction, wallet movement, or accounting journal.

## Feature Flags

All flags default to disabled:

- `customer_accounts.enabled`
- `customer_accounts.pay_on_account_enabled`
- `pos.pay_on_account_enabled`
- `checkout.pay_on_account_enabled`

The channel flag is effective only when both customer accounts and the shared Pay on Account flag are enabled.

## Eligibility

Pay on Account requires an identified active customer with:

- An existing credit profile.
- `is_credit_allowed=true`.
- `account_status=active`.
- A credit decision that does not exceed the enabled credit limit.
- No overdue balance when payment-term enforcement is enabled.

The server repeats the decision immediately before posting. Client-side visibility is informational and is never trusted.

Decision reasons remain:

- `feature_disabled`
- `account_disabled`
- `account_on_hold`
- `account_blocked`
- `credit_not_allowed`
- `over_credit_limit`
- `overdue_balance`
- `ok`

No manager override bypass is implemented.

## Web POS

Authorized staff can choose Cash or Pay on Account after selecting a customer. The POS displays current balance, available credit, overdue amount, projected balance, and the decision message.

The POS still requires an open shift as its operational session, but an account sale creates no Cash Movement and does not change expected cash. Prices and stock are recalculated on the server using the resolved customer, branch, Price List, Sale price, and Branch Price policies.

## Web Checkout

Only signed-in eligible customers see Pay on Account. Guests never see or submit it successfully. The checkout creates the Order and AR debit within one database transaction; a failed credit decision rolls the operation back.

Web Checkout continues to use the default branch context until explicit storefront branch selection is introduced.

## Order and AR Records

- `order.payment_type=pay_on_account`
- `order.payment_status=unpaid`
- `paid_amount=0`
- Order metadata records the payment method and AR-posted state.
- One AR invoice debit is created from the stored Order total.
- Idempotency key: `order:{order_id}:pay_on_account`.
- Payment terms, due date, credit limit, branch, channel, and method are snapshotted in ledger metadata.

The AR ledger is the source of truth for the customer balance. Sales Invoice and Customer Statement use stored Order and ledger data.

For a completed Sales Return, Step 72 suggests Customer Account Credit for a `pay_on_account` Order. The resulting partial or full credit note reduces the ledger balance without creating a Customer Payment or Cash Movement. The original Order payment status remains unchanged.

## Permissions

- `customer_credit.pay_on_account_pos`
- `customer_credit.pay_on_account_web`

Owner/General Manager and Store Admin receive both permissions. Cashiers receive POS account-sale permission only. Web customer access is determined by the customer credit profile rather than a staff role. Accountant, Delivery, Marketing, and Designer roles do not receive POS sale permission.

## Future

- Flutter POS Pay on Account support.
- Audited manager override and approval workflow.
- Credit sale approval thresholds.
- Overdue reminders and collection workflow.
- Accounting journal integration.
