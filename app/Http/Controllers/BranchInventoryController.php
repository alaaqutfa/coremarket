<?php

namespace App\Http\Controllers;

use App\Models\ProductStock;
use App\Models\ProductStockBranchBalance;
use App\Models\StockTransfer;
use App\Services\CoreMarketBranchInventoryService;
use App\Services\CoreMarketStockTransferService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Support\Str;

class BranchInventoryController extends Controller
{
    public function branchStock(
        Request $request,
        CoreMarketBranchInventoryService $inventory
    ): View {
        $this->authorizeInventory('inventory.branch_stock.view', $inventory);
        $branch = $inventory->resolveBranchForOperation(
            $request->integer('branch_id') ?: null,
            auth()->user()
        );
        $search = trim((string) $request->input('q'));
        $balances = ProductStockBranchBalance::query()
            ->with(['product', 'productStock'])
            ->where('store_branch_id', $branch->id)
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($searchQuery) use ($search) {
                    $searchQuery
                        ->whereHas('product', fn ($product) => $product->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('productStock', fn ($stock) => $stock
                            ->where('sku', 'like', "%{$search}%")
                            ->orWhere('barcode', 'like', "%{$search}%"));
                });
            })
            ->orderBy('product_id')
            ->paginate(50)
            ->withQueryString();

        return view('backend.operations.inventory.branch-stock', [
            'branch' => $branch,
            'branches' => $inventory->visibleBranches(auth()->user()),
            'balances' => $balances,
        ]);
    }

    public function transfers(
        Request $request,
        CoreMarketBranchInventoryService $inventory
    ): View {
        $this->authorizeInventory('inventory.stock_transfers.view', $inventory);
        $visibleIds = $inventory->visibleBranches(auth()->user())->pluck('id');
        $query = StockTransfer::query()
            ->with(['fromBranch', 'toBranch', 'requester'])
            ->where(fn ($scope) => $scope
                ->whereIn('from_branch_id', $visibleIds)
                ->orWhereIn('to_branch_id', $visibleIds))
            ->when($request->filled('status'), fn ($scope) => $scope->where('status', $request->input('status')))
            ->latest();

        return view('backend.operations.inventory.stock-transfers.index', [
            'transfers' => $query->paginate(30)->withQueryString(),
        ]);
    }

    public function createTransfer(CoreMarketBranchInventoryService $inventory): View
    {
        $this->authorizeInventory('inventory.stock_transfers.create', $inventory);

        return view('backend.operations.inventory.stock-transfers.form', [
            'branches' => $inventory->visibleBranches(auth()->user()),
            'stocks' => ProductStock::query()->with('product')->orderBy('product_id')->limit(1000)->get(),
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function storeTransfer(
        Request $request,
        CoreMarketBranchInventoryService $inventory,
        CoreMarketStockTransferService $transfers
    ): RedirectResponse {
        $this->authorizeInventory('inventory.stock_transfers.create', $inventory);
        $data = $request->validate([
            'from_branch_id' => 'required|integer|exists:store_branches,id|different:to_branch_id',
            'to_branch_id' => 'required|integer|exists:store_branches,id',
            'product_stock_id' => 'required|integer|exists:product_stocks,id',
            'quantity' => 'required|numeric|min:0.000001',
            'notes' => 'nullable|string|max:2000',
            'idempotency_key' => 'required|string|max:150',
        ]);

        try {
            $transfer = $transfers->createTransfer([
                'from_branch_id' => $data['from_branch_id'],
                'to_branch_id' => $data['to_branch_id'],
                'notes' => $data['notes'] ?? null,
                'idempotency_key' => $data['idempotency_key'],
                'items' => [[
                    'product_stock_id' => $data['product_stock_id'],
                    'quantity' => $data['quantity'],
                ]],
            ], auth()->user());
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['stock_transfer' => $exception->getMessage()]);
        }

        return redirect()->route('operations.inventory.stock-transfers.show', $transfer)
            ->with('success', translate('Stock transfer created.'));
    }

    public function showTransfer(
        StockTransfer $transfer,
        CoreMarketBranchInventoryService $inventory
    ): View {
        $this->authorizeInventory('inventory.stock_transfers.view', $inventory);
        $this->assertTransferVisible($transfer, $inventory);

        return view('backend.operations.inventory.stock-transfers.show', [
            'transfer' => $transfer->load(['items.productStock', 'fromBranch', 'toBranch', 'requester', 'approver']),
        ]);
    }

    public function submit(
        StockTransfer $transfer,
        CoreMarketBranchInventoryService $inventory,
        CoreMarketStockTransferService $transfers
    ): RedirectResponse {
        $this->authorizeInventory('inventory.stock_transfers.create', $inventory);
        $this->assertTransferVisible($transfer, $inventory);

        return $this->run(fn () => $transfers->submitForApproval($transfer, auth()->user()));
    }

    public function approve(
        StockTransfer $transfer,
        CoreMarketBranchInventoryService $inventory,
        CoreMarketStockTransferService $transfers
    ): RedirectResponse {
        $this->authorizeInventory('inventory.stock_transfers.approve', $inventory);
        $this->assertTransferVisible($transfer, $inventory);

        return $this->run(fn () => $transfers->approve($transfer, auth()->user()));
    }

    public function reject(
        Request $request,
        StockTransfer $transfer,
        CoreMarketBranchInventoryService $inventory,
        CoreMarketStockTransferService $transfers
    ): RedirectResponse {
        $this->authorizeInventory('inventory.stock_transfers.approve', $inventory);
        $this->assertTransferVisible($transfer, $inventory);
        $data = $request->validate(['reason' => 'nullable|string|max:2000']);

        return $this->run(fn () => $transfers->reject($transfer, auth()->user(), $data['reason'] ?? null));
    }

    public function ship(
        StockTransfer $transfer,
        CoreMarketBranchInventoryService $inventory,
        CoreMarketStockTransferService $transfers
    ): RedirectResponse {
        $this->authorizeInventory('inventory.stock_transfers.ship', $inventory);
        $this->assertTransferVisible($transfer, $inventory);

        return $this->run(fn () => $transfers->ship($transfer, auth()->user()));
    }

    public function receive(
        StockTransfer $transfer,
        CoreMarketBranchInventoryService $inventory,
        CoreMarketStockTransferService $transfers
    ): RedirectResponse {
        $this->authorizeInventory('inventory.stock_transfers.receive', $inventory);
        $this->assertTransferVisible($transfer, $inventory);

        return $this->run(fn () => $transfers->receive($transfer, auth()->user()));
    }

    public function cancel(
        StockTransfer $transfer,
        CoreMarketBranchInventoryService $inventory,
        CoreMarketStockTransferService $transfers
    ): RedirectResponse {
        $this->authorizeInventory('inventory.stock_transfers.cancel', $inventory);
        $this->assertTransferVisible($transfer, $inventory);

        return $this->run(fn () => $transfers->cancel($transfer, auth()->user()));
    }

    private function authorizeInventory(
        string $permission,
        CoreMarketBranchInventoryService $inventory
    ): void {
        $user = auth()->user();
        if (! $inventory->branchInventoryEnabled()) {
            abort(404);
        }
        if (! $user || ($user->user_type !== 'admin' && ! $user->can($permission))) {
            abort(403);
        }
        if (! coremarket_feature_enabled('inventory_pro')) {
            abort(404);
        }
    }

    private function assertTransferVisible(
        StockTransfer $transfer,
        CoreMarketBranchInventoryService $inventory
    ): void {
        if ($inventory->userHasAllBranchAccess(auth()->user())) {
            return;
        }
        $visible = $inventory->visibleBranches(auth()->user())->pluck('id');
        if (! $visible->contains($transfer->from_branch_id) && ! $visible->contains($transfer->to_branch_id)) {
            abort(403);
        }
    }

    private function run(callable $action): RedirectResponse
    {
        try {
            $action();
        } catch (DomainException $exception) {
            return back()->withErrors(['stock_transfer' => $exception->getMessage()]);
        }

        return back()->with('success', translate('Stock transfer updated.'));
    }
}
