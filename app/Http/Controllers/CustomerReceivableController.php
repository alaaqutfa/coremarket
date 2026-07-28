<?php

namespace App\Http\Controllers;

use App\Models\CashierShift;
use App\Models\CustomerLedgerEntry;
use App\Models\Order;
use App\Models\User;
use App\Services\CoreMarketCustomerAccountFeatureService;
use App\Services\CoreMarketCustomerReceivableService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CustomerReceivableController extends Controller
{
    public function __construct(
        private CoreMarketCustomerAccountFeatureService $features,
        private CoreMarketCustomerReceivableService $receivables
    ) {
    }

    public function index(): View
    {
        $this->authorizeFeature('customer_receivables.view');
        $customers = User::query()
            ->where('user_type', 'customer')
            ->whereHas('customerLedgerEntries')
            ->orderBy('name')
            ->paginate(25);
        $customers->getCollection()->transform(function (User $customer) {
            $customer->setAttribute('receivable_balance', $this->receivables->customerBalance($customer));
            return $customer;
        });

        return view('backend.operations.customer-receivables.index', [
            'customers' => $customers,
            'aging' => $this->receivables->agingSummary(),
            'customersWithBalance' => CustomerLedgerEntry::query()
                ->select('customer_id')
                ->groupBy('customer_id')
                ->havingRaw("SUM(CASE WHEN direction = 'debit' THEN amount ELSE -amount END) > 0")
                ->count(),
        ]);
    }

    public function show(Request $request, User $customer): View
    {
        $this->authorizeFeature('customer_ledger.view');
        $this->assertCustomer($customer);
        $filters = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'entry_type' => 'nullable|in:invoice,payment,credit_note,debit_adjustment,credit_adjustment,opening_balance',
        ]);
        $entries = $this->receivables->customerLedger($customer, $filters)->paginate(40)->withQueryString();
        $invoiceEntries = CustomerLedgerEntry::query()
            ->with('order:id,code')
            ->where('customer_id', $customer->id)
            ->where('entry_type', 'invoice')
            ->orderBy('occurred_at')
            ->get()
            ->map(function (CustomerLedgerEntry $entry) {
                $entry->setAttribute(
                    'outstanding_amount',
                    $entry->order ? $this->receivables->outstandingAmountForOrder($entry->order) : 0.0
                );
                return $entry;
            })
            ->filter(fn (CustomerLedgerEntry $entry) => (float) $entry->outstanding_amount > 0);

        return view('backend.operations.customer-receivables.show', [
            'customer' => $customer,
            'entries' => $entries,
            'filters' => $filters,
            'balance' => $this->receivables->customerBalance($customer),
            'aging' => $this->receivables->agingSummary($customer),
            'invoiceEntries' => $invoiceEntries,
            'openShifts' => $this->availableOpenShifts(),
            'paymentKey' => (string) Str::uuid(),
        ]);
    }

    public function postOrder(Order $order): RedirectResponse
    {
        $this->authorizeFeature('customer_receivables.manage');
        try {
            $entry = $this->receivables->createInvoiceEntryFromOrder($order, auth()->user());
        } catch (DomainException $exception) {
            return back()->withErrors(['customer_account' => $exception->getMessage()]);
        }

        return redirect()
            ->route('operations.customers.receivables.show', $entry->customer_id)
            ->with('success', translate('Order posted to customer account.'));
    }

    public function storePayment(Request $request, User $customer): RedirectResponse
    {
        $this->authorizeFeature('customer_payments.create');
        $this->assertCustomer($customer);
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,bank_transfer,cheque,card_manual,other',
            'cashier_shift_id' => 'nullable|integer|exists:cashier_shifts,id',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000',
            'idempotency_key' => 'required|string|max:100',
            'allocations' => 'nullable|array',
            'allocations.*' => 'nullable|numeric|min:0',
        ]);
        $user = auth()->user();
        if (
            $user->user_type !== 'admin'
            && $user->hasRole('cashier')
            && ! $user->hasAnyRole(['owner_general_manager', 'store_admin', 'accountant'])
            && $data['payment_method'] !== 'cash'
        ) {
            abort(403);
        }
        $allocations = collect($data['allocations'] ?? [])
            ->filter(fn ($amount) => is_numeric($amount) && (float) $amount > 0)
            ->map(fn ($amount, $entryId) => [
                'customer_ledger_entry_id' => (int) $entryId,
                'amount' => (float) $amount,
            ])
            ->values()
            ->all();

        try {
            $this->receivables->recordCustomerPayment(
                $customer,
                $data['amount'],
                $data['payment_method'],
                $user,
                filled($data['cashier_shift_id'] ?? null) ? CashierShift::query()->findOrFail($data['cashier_shift_id']) : null,
                $data['idempotency_key'],
                $allocations,
                $data['reference'] ?? null,
                $data['notes'] ?? null
            );
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['customer_payment' => $exception->getMessage()]);
        }

        return back()->with('success', translate('Customer payment recorded successfully.'));
    }

    private function authorizeFeature(string $permission): void
    {
        $user = auth()->user();
        if (! $user || ($user->user_type !== 'admin' && ! $user->can($permission))) {
            abort(403);
        }
        if (! $this->features->enabled()) {
            abort(404);
        }
    }

    private function assertCustomer(User $customer): void
    {
        abort_unless($customer->user_type === 'customer', 404);
    }

    private function availableOpenShifts()
    {
        $user = auth()->user();

        return CashierShift::query()
            ->with('cashbox')
            ->where('status', 'open')
            ->when(
                $user->user_type !== 'admin'
                    && $user->hasRole('cashier')
                    && ! $user->hasAnyRole(['owner_general_manager', 'store_admin', 'accountant']),
                fn ($query) => $query->where('opened_by', $user->id)
            )
            ->latest('opened_at')
            ->get();
    }
}
