# Customer Returns, Refunds, and Credit Notes

## Scope

Step 72 keeps the existing Sales Return stock reversal and adds a separate, documented financial action. A completed return can be refunded partially or fully through Cashbox cash-out or an AR customer-account credit.

No historical Sales Return is refunded automatically. No payment gateway, wallet/store credit, VAT filing, accounting journal, notification, or Flutter POS refund flow is added.

## Stock and Financial Effects

- Completing the Sales Return continues to restore inventory through the existing branch-aware Inventory Movement flow.
- Draft or pending returns cannot create a refund.
- A financial refund is a separate posted `sales_return_refunds` record.
- The refundable ceiling is the stored Sales Return total.
- Posted partial refunds reduce the remaining refundable amount.
- `order.payment_status` is not changed; the existing Order and AR ledger remain the historical sources.

## Cash Refund

A cash refund requires:

- `sales_returns.refunds.cash`.
- An active Cashbox with an open shift.
- For a Cashier, the Cashier's own open shift.
- Enough expected cash to cover the refund.

Posting creates one `cash_movements` row with direction `out`, type `sales_return_refund`, and a reference to the refund record. It creates no Customer Payment and no AR credit note.

## Customer Account Credit

An account credit requires an identified customer, enabled Customer Accounts, and `sales_returns.refunds.credit_account`.

Posting creates one `customer_ledger_entries` row:

- `entry_type=credit_note`
- `direction=credit`
- linked Sales Return and original Order

The credit decreases `debits - credits`, appears in the AR Customer Statement, and creates neither a Customer Payment nor a Cash Movement. For `pay_on_account` Orders this is the suggested refund method because it directly reduces the outstanding ledger balance.

## Duplicate and Over-Refund Protection

- Every request has a unique idempotency key.
- Repeating the same key returns the existing refund.
- The Sales Return row is locked while the remaining refundable amount is checked.
- A refund greater than the remaining amount is rejected.
- Cash Movement and credit-note creation each have a single linked refund reference.

## Permissions

- Owner/General Manager and Store Admin: view, cash refund, account credit, and credit-note access.
- Accountant: view refunds and create account credit notes.
- Cashier: view returns and post cash refunds through their own open shift.
- Warehouse, Delivery, Marketing, and Designer roles: no financial refund or credit-note access.

Cost and profit reversal values remain hidden from Cashiers.

## Future

- Refund approval and cancellation workflow.
- Customer wallet/store credit.
- Flutter POS refund support.
- Official accounting journal posting.
