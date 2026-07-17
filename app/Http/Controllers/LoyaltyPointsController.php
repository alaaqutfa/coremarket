<?php

namespace App\Http\Controllers;

use App\Models\LoyaltyAccount;
use App\Models\LoyaltyRule;
use App\Models\Order;
use App\Models\User;
use App\Services\CoreMarketFeatureAccessService;
use App\Services\LoyaltyPointsService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LoyaltyPointsController extends Controller
{
    public function __construct(private CoreMarketFeatureAccessService $features)
    {
    }

    public function dashboard(LoyaltyPointsService $loyalty): View
    {
        $this->authorizeLoyalty('loyalty.view');

        return view('backend.operations.loyalty.dashboard', [
            'stats' => [
                'accounts' => LoyaltyAccount::query()->count(),
                'points_balance' => LoyaltyAccount::query()->sum('points_balance'),
                'lifetime_earned' => LoyaltyAccount::query()->sum('lifetime_points_earned'),
            ],
            'activeRule' => $loyalty->activeRuleForOrder(),
            'movements' => $loyalty->movementRows()->limit(10)->get(),
        ]);
    }

    public function rules(Request $request): View
    {
        $this->authorizeLoyalty('loyalty.rules.manage');

        return view('backend.operations.loyalty.rules', [
            'rules' => LoyaltyRule::query()->latest()->get(),
            'editingRule' => $request->filled('edit')
                ? LoyaltyRule::query()->findOrFail($request->integer('edit'))
                : null,
        ]);
    }

    public function storeRule(Request $request, LoyaltyPointsService $loyalty): RedirectResponse
    {
        $this->authorizeLoyalty('loyalty.rules.manage');
        $data = $request->validate([
            'rule_id' => 'nullable|integer|exists:loyalty_rules,id',
            'name' => 'required|string|max:191',
            'earn_rate_amount' => 'required|numeric|min:0.000001',
            'earn_rate_points' => 'required|integer|min:1',
            'min_order_amount' => 'required|numeric|min:0',
            'currency' => 'nullable|string|max:10',
            'applies_to_order_from' => 'nullable|string|max:50',
        ]);

        $rule = ! empty($data['rule_id']) ? LoyaltyRule::query()->findOrFail($data['rule_id']) : null;
        unset($data['rule_id']);
        $data['is_active'] = $request->boolean('is_active');
        $loyalty->saveRule($data, $rule);

        return redirect()->route('operations.loyalty.rules')
            ->with('success', translate($rule ? 'Loyalty rule updated successfully' : 'Loyalty rule created successfully'));
    }

    public function accounts(Request $request): View
    {
        $this->authorizeLoyalty('loyalty.view');
        $accounts = LoyaltyAccount::query()
            ->with('user')
            ->withMax('movements', 'created_at')
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = trim((string) $request->input('search'));
                $query->whereHas('user', fn ($users) => $users->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->input('status')))
            ->latest()
            ->paginate(30)
            ->withQueryString();

        return view('backend.operations.loyalty.accounts', compact('accounts'));
    }

    public function showAccount(LoyaltyAccount $account, LoyaltyPointsService $loyalty): View
    {
        $this->authorizeLoyalty('loyalty.view');
        $account->load('user');

        return view('backend.operations.loyalty.account_show', [
            'account' => $account,
            'movements' => $loyalty->movementRows(['user_id' => $account->user_id])->paginate(30),
            'canAdjust' => $this->can('loyalty.adjust'),
        ]);
    }

    public function movements(Request $request, LoyaltyPointsService $loyalty): View
    {
        $this->authorizeLoyalty('loyalty.movements.view');

        return view('backend.operations.loyalty.movements', [
            'movements' => $loyalty->movementRows($request->only(['user_id', 'movement_type', 'direction', 'reference_type', 'reference_id', 'date_from', 'date_to']))->paginate(30)->withQueryString(),
            'customers' => User::query()->where('user_type', 'customer')->orderBy('name')->limit(200)->get(['id', 'name', 'email']),
        ]);
    }

    public function adjust(Request $request, LoyaltyAccount $account, LoyaltyPointsService $loyalty): RedirectResponse
    {
        $this->authorizeLoyalty('loyalty.adjust');
        $account->load('user');
        if (! $account->user) abort(404);

        $data = $request->validate(['points' => 'required|integer|not_in:0', 'reason' => 'required|string|max:500']);

        try {
            $loyalty->adjustPoints($account->user, $data['points'], $data['reason'], auth()->user());
        } catch (DomainException $exception) {
            return back()->withErrors(['points' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('operations.loyalty.accounts.show', $account)->with('success', translate('Loyalty points adjusted successfully'));
    }

    public function orderTrace(Order $order, LoyaltyPointsService $loyalty): View
    {
        $this->authorizeAnyLoyalty(['loyalty.view', 'loyalty.movements.view']);
        $order->load('user');

        return view('backend.operations.loyalty.order_trace', [
            'order' => $order,
            'movements' => $loyalty->movementRows(['reference_type' => Order::class, 'reference_id' => $order->id])->get(),
        ]);
    }

    private function authorizeLoyalty(string $permission): void
    {
        $this->authorizeAnyLoyalty([$permission]);
    }

    private function authorizeAnyLoyalty(array $permissions): void
    {
        if (! $this->features->enabled('loyalty_points')) {
            abort(404);
        }

        if (! $this->canAny($permissions)) {
            abort(403);
        }
    }

    private function can(string $permission): bool
    {
        return $this->canAny([$permission]);
    }

    private function canAny(array $permissions): bool
    {
        $user = auth()->user();
        return $user && ($user->user_type === 'admin' || $user->hasAnyPermission($permissions));
    }
}
