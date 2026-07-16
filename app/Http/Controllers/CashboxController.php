<?php

namespace App\Http\Controllers;

use App\Models\Cashbox;
use App\Models\CashierShift;
use App\Models\User;
use App\Services\CashboxService;
use App\Services\CoreMarketFeatureAccessService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CashboxController extends Controller
{
    public function __construct(private CoreMarketFeatureAccessService $features)
    {
    }

    public function dashboard(CashboxService $cashboxes): View
    {
        $this->authorizeCashbox('cashboxes.view');

        return view('backend.operations.cashbox.dashboard', [
            'stats' => $cashboxes->dashboardStats(),
        ]);
    }

    public function cashboxes(Request $request): View
    {
        $this->authorizeCashbox('cashboxes.view');

        $cashboxes = Cashbox::query()
            ->with(['assignedUser', 'shifts' => fn ($query) => $query->where('status', 'open')])
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = trim((string) $request->input('search'));
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhere('location', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->input('status')))
            ->when($request->filled('assigned_user_id'), fn ($query) => $query->where('assigned_user_id', $request->integer('assigned_user_id')))
            ->latest()
            ->paginate(30)
            ->withQueryString();

        return view('backend.operations.cashbox.boxes', [
            'cashboxes' => $cashboxes,
            'users' => $this->cashboxUsers(),
            'canCreateCashbox' => $this->can('cashboxes.create'),
            'canEditCashbox' => $this->can('cashboxes.edit'),
            'canOpenShift' => $this->can('cash_shifts.open'),
        ]);
    }

    public function createCashbox(): View
    {
        $this->authorizeCashbox('cashboxes.create');

        return view('backend.operations.cashbox.boxes.create', [
            'users' => $this->cashboxUsers(),
        ]);
    }

    public function storeCashbox(Request $request, CashboxService $cashboxes): RedirectResponse
    {
        $this->authorizeCashbox('cashboxes.create');
        $cashbox = $cashboxes->createCashbox($this->cashboxPayload($request));

        return redirect()->route('operations.cashboxes.show', $cashbox)
            ->with('success', translate('Cashbox created successfully'));
    }

    public function showCashbox(Cashbox $cashbox): View
    {
        $this->authorizeCashbox('cashboxes.view');
        $cashbox->load([
            'assignedUser',
            'shifts' => fn ($query) => $query->with(['opener', 'closer'])->latest('opened_at')->limit(10),
            'movements' => fn ($query) => $query->with(['shift', 'creator'])->latest('occurred_at')->limit(10),
        ]);
        $openShift = CashierShift::query()
            ->where('cashbox_id', $cashbox->id)
            ->where('status', 'open')
            ->first();

        return view('backend.operations.cashbox.boxes.show', [
            'cashbox' => $cashbox,
            'openShift' => $openShift,
            'canEditCashbox' => $this->can('cashboxes.edit'),
            'canOpenShift' => $this->can('cash_shifts.open'),
        ]);
    }

    public function editCashbox(Cashbox $cashbox): View
    {
        $this->authorizeCashbox('cashboxes.edit');

        return view('backend.operations.cashbox.boxes.edit', [
            'cashbox' => $cashbox,
            'users' => $this->cashboxUsers(),
        ]);
    }

    public function updateCashbox(Request $request, Cashbox $cashbox, CashboxService $cashboxes): RedirectResponse
    {
        $this->authorizeCashbox('cashboxes.edit');
        $cashboxes->updateCashbox($cashbox, $this->cashboxPayload($request, $cashbox));

        return redirect()->route('operations.cashboxes.show', $cashbox)
            ->with('success', translate('Cashbox updated successfully'));
    }

    public function shifts(Request $request, CashboxService $cashboxes): View
    {
        $this->authorizeCashbox('cash_shifts.view');

        return view('backend.operations.cashbox.shifts', [
            'shifts' => $cashboxes->shiftRows($request->only([
                'status', 'cashbox_id', 'opened_by', 'date_from', 'date_to', 'open_only', 'has_difference',
            ]))->paginate(30)->withQueryString(),
            'cashboxes' => Cashbox::query()->orderBy('name')->get(['id', 'name', 'code']),
            'users' => $this->cashboxUsers(),
            'canCloseShift' => $this->can('cash_shifts.close'),
        ]);
    }

    public function showShift(CashierShift $shift, CashboxService $cashboxes): View
    {
        $this->authorizeCashbox('cash_shifts.view');
        $shift->load(['cashbox', 'opener', 'closer', 'movements.creator']);

        return view('backend.operations.cashbox.shifts.show', [
            'shift' => $shift,
            'calculatedExpectedCash' => $cashboxes->calculateExpectedCash($shift),
            'canCreateMovement' => $this->can('cash_movements.create'),
            'canCloseShift' => $this->can('cash_shifts.close'),
        ]);
    }

    public function openShiftForm(Cashbox $cashbox): View
    {
        $this->authorizeCashbox('cash_shifts.open');

        return view('backend.operations.cashbox.shifts.open', compact('cashbox'));
    }

    public function openShift(Request $request, Cashbox $cashbox, CashboxService $cashboxes): RedirectResponse
    {
        $this->authorizeCashbox('cash_shifts.open');
        $data = $request->validate([
            'opening_balance' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:2000',
        ]);

        try {
            $shift = $cashboxes->openShift($cashbox, auth()->user(), $data['opening_balance'], $data['notes'] ?? null);
        } catch (DomainException $exception) {
            return back()->withErrors(['shift' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('operations.cash-shifts.show', $shift)
            ->with('success', translate('Cashier shift opened successfully'));
    }

    public function createMovement(CashierShift $shift): View
    {
        $this->authorizeCashbox('cash_movements.create');

        if (! $shift->isOpen()) {
            abort(409);
        }

        $shift->load('cashbox');

        return view('backend.operations.cashbox.shifts.movement-create', compact('shift'));
    }

    public function storeMovement(Request $request, CashierShift $shift, CashboxService $cashboxes): RedirectResponse
    {
        $this->authorizeCashbox('cash_movements.create');
        $data = $request->validate([
            'movement_type' => 'required|in:cash_in,cash_out,adjustment',
            'direction' => 'required|in:in,out,neutral',
            'amount' => 'required|numeric|min:0.000001',
            'description' => 'required|string|max:1000',
            'occurred_at' => 'nullable|date',
        ]);

        try {
            $cashboxes->addCashMovement(
                $shift,
                $data['movement_type'],
                $data['direction'],
                $data['amount'],
                $data['description'],
                $data['occurred_at'] ?? null,
                auth()->user()
            );
        } catch (DomainException $exception) {
            return back()->withErrors(['movement' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('operations.cash-shifts.show', $shift)
            ->with('success', translate('Cash movement recorded successfully'));
    }

    public function closeShiftForm(CashierShift $shift, CashboxService $cashboxes): View
    {
        $this->authorizeCashbox('cash_shifts.close');

        if (! $shift->isOpen()) {
            abort(409);
        }

        $shift->load('cashbox');

        return view('backend.operations.cashbox.shifts.close', [
            'shift' => $shift,
            'expectedCash' => $cashboxes->calculateExpectedCash($shift),
        ]);
    }

    public function closeShift(Request $request, CashierShift $shift, CashboxService $cashboxes): RedirectResponse
    {
        $this->authorizeCashbox('cash_shifts.close');
        $data = $request->validate([
            'actual_cash' => 'required|numeric|min:0',
            'close_notes' => 'nullable|string|max:2000',
        ]);

        try {
            $cashboxes->closeShift($shift, $data['actual_cash'], $data['close_notes'] ?? null, auth()->user());
        } catch (DomainException $exception) {
            return back()->withErrors(['shift' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('operations.cash-shifts.show', $shift)
            ->with('success', translate('Cashier shift closed successfully'));
    }

    public function movements(Request $request, CashboxService $cashboxes): View
    {
        $this->authorizeCashbox('cash_movements.view');

        return view('backend.operations.cashbox.movements', [
            'movements' => $cashboxes->movementRows($request->only([
                'cashbox_id', 'cashier_shift_id', 'movement_type', 'direction', 'date_from', 'date_to', 'created_by',
            ]))->paginate(30)->withQueryString(),
            'cashboxes' => Cashbox::query()->orderBy('name')->get(['id', 'name', 'code']),
            'shifts' => CashierShift::query()->latest('opened_at')->limit(100)->get(['id', 'cashbox_id', 'opened_at', 'status']),
            'users' => $this->cashboxUsers(),
        ]);
    }

    private function authorizeCashbox(string $permission): void
    {
        $user = auth()->user();

        if (! $user || ($user->user_type !== 'admin' && ! $user->can($permission))) {
            abort(403);
        }

        if ($user->user_type !== 'admin' && ! $this->features->enabled('cashbox_shifts')) {
            abort(404);
        }
    }

    private function can(string $permission): bool
    {
        $user = auth()->user();

        return $user && ($user->user_type === 'admin' || $user->can($permission));
    }

    private function cashboxPayload(Request $request, ?Cashbox $cashbox = null): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:100|unique:cashboxes,code' . ($cashbox ? ',' . $cashbox->id : ''),
            'location' => 'nullable|string|max:255',
            'currency' => 'nullable|string|max:10',
            'status' => 'required|in:active,inactive',
            'assigned_user_id' => 'nullable|exists:users,id',
        ]);
    }

    private function cashboxUsers()
    {
        return User::query()->orderBy('name')->limit(200)->get(['id', 'name', 'email']);
    }
}
