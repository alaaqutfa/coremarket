<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductStockBranchBalance;
use App\Models\StoreBranch;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CoreMarketBranchInventoryService
{
    public const SETTING = 'inventory.branch_inventory_enabled';

    public function __construct(
        private CoreMarketBranchService $branches,
        private CoreMarketInventoryPolicyService $inventoryPolicy
    ) {
    }

    public function branchInventoryEnabled(): bool
    {
        return filter_var(
            get_setting(self::SETTING, config('coremarket.inventory.branch_inventory_enabled', false)),
            FILTER_VALIDATE_BOOL
        );
    }

    public function defaultBranch(): StoreBranch
    {
        return $this->branches->resolveBranch();
    }

    public function resolveBranchForUser(?User $user): StoreBranch
    {
        if (! $user || $this->userHasAllBranchAccess($user)) {
            return $this->defaultBranch();
        }

        return $user->branches()
            ->where('store_branches.is_active', true)
            ->orderByDesc('staff_branch_assignments.is_primary')
            ->first() ?? $this->defaultBranch();
    }

    public function resolveBranchForOperation(int|string|null $branchId, ?User $actor): StoreBranch
    {
        $branch = ($branchId === null || $branchId === '')
            ? $this->resolveBranchForUser($actor)
            : $this->branches->resolveBranch($branchId);

        if (
            $actor
            && ! $this->userHasAllBranchAccess($actor)
            && $actor->branches()->exists()
            && ! $actor->branches()->where('store_branches.id', $branch->id)->exists()
        ) {
            throw new DomainException('This staff account is not assigned to the selected branch.');
        }

        return $branch;
    }

    public function visibleBranches(?User $user): Collection
    {
        if (! $user || $this->userHasAllBranchAccess($user) || ! $user->branches()->exists()) {
            return $this->branches->activeBranches();
        }

        return $user->branches()
            ->where('store_branches.is_active', true)
            ->orderByDesc('staff_branch_assignments.is_primary')
            ->orderBy('store_branches.name')
            ->get();
    }

    public function getBranchBalance(ProductStock $stock, StoreBranch $branch): ProductStockBranchBalance
    {
        return ProductStockBranchBalance::query()
            ->where('product_stock_id', $stock->id)
            ->where('store_branch_id', $branch->id)
            ->first() ?? new ProductStockBranchBalance([
                'product_id' => $stock->product_id,
                'product_stock_id' => $stock->id,
                'store_branch_id' => $branch->id,
                'quantity' => 0,
                'reserved_quantity' => 0,
            ]);
    }

    public function availableQuantity(ProductStock $stock, StoreBranch $branch): float
    {
        if (! $this->branchInventoryEnabled()) {
            return (float) $stock->qty;
        }

        $balance = $this->getBranchBalance($stock, $branch);

        return (float) $balance->quantity - (float) $balance->reserved_quantity;
    }

    public function increaseBranchStock(
        ProductStock $stock,
        StoreBranch $branch,
        float $quantity,
        string $source,
        array $metadata = []
    ): ProductStockBranchBalance {
        if ($quantity <= 0) {
            throw new DomainException('Branch stock increase must be greater than zero.');
        }

        return $this->mutate($stock, $branch, $quantity, $source, $metadata);
    }

    public function decreaseBranchStock(
        ProductStock $stock,
        StoreBranch $branch,
        float $quantity,
        string $source,
        array $metadata = []
    ): ProductStockBranchBalance {
        if ($quantity <= 0) {
            throw new DomainException('Branch stock decrease must be greater than zero.');
        }

        return $this->mutate($stock, $branch, -$quantity, $source, $metadata);
    }

    public function syncAggregateProductStock(ProductStock $stock): float
    {
        if (! $this->branchInventoryEnabled()) {
            return (float) $stock->fresh()->qty;
        }

        $aggregate = (float) ProductStockBranchBalance::query()
            ->where('product_stock_id', $stock->id)
            ->sum('quantity');
        ProductStock::query()->whereKey($stock->id)->update(['qty' => $aggregate]);
        $productTotal = (float) ProductStock::query()
            ->where('product_id', $stock->product_id)
            ->sum('qty');
        Product::query()->whereKey($stock->product_id)->update(['current_stock' => $productTotal]);

        return $aggregate;
    }

    public function initializeDefaultBranchBalances(
        bool $apply = false,
        ?int $branchId = null
    ): array {
        $branch = $this->branches->resolveBranch($branchId);
        $result = ['scanned' => 0, 'created' => 0, 'skipped' => 0, 'differences' => 0];

        ProductStock::query()->orderBy('id')->chunkById(250, function ($stocks) use ($branch, $apply, &$result) {
            foreach ($stocks as $stock) {
                $result['scanned']++;
                $exists = ProductStockBranchBalance::query()
                    ->where('product_stock_id', $stock->id)
                    ->exists();
                if ($exists) {
                    $result['skipped']++;
                    $branchTotal = (float) ProductStockBranchBalance::query()
                        ->where('product_stock_id', $stock->id)
                        ->sum('quantity');
                    if (abs($branchTotal - (float) $stock->qty) > 0.000001) {
                        $result['differences']++;
                    }
                    continue;
                }

                $result['created']++;
                if ($apply) {
                    ProductStockBranchBalance::query()->create([
                        'product_id' => $stock->product_id,
                        'product_stock_id' => $stock->id,
                        'store_branch_id' => $branch->id,
                        'quantity' => $stock->qty,
                        'reserved_quantity' => 0,
                        'last_movement_at' => now(),
                        'metadata' => ['source' => 'branch_inventory_initialize'],
                    ]);
                }
            }
        });

        return array_merge($result, [
            'branch_id' => $branch->id,
            'branch_name' => $branch->name,
            'applied' => $apply,
        ]);
    }

    public function userHasAllBranchAccess(User $user): bool
    {
        return $user->user_type === 'admin'
            || $user->hasAnyRole(['owner_general_manager', 'store_admin']);
    }

    private function mutate(
        ProductStock $stock,
        StoreBranch $branch,
        float $change,
        string $source,
        array $metadata
    ): ProductStockBranchBalance {
        return DB::transaction(function () use ($stock, $branch, $change, $source, $metadata) {
            $lockedStock = ProductStock::query()->lockForUpdate()->findOrFail($stock->id);
            Product::query()->lockForUpdate()->findOrFail($lockedStock->product_id);

            if (! $this->branchInventoryEnabled()) {
                $before = (float) $lockedStock->qty;
                $stockTotalBefore = (float) ProductStock::query()
                    ->where('product_id', $lockedStock->product_id)
                    ->sum('qty');
                $product = Product::query()->findOrFail($lockedStock->product_id);
                if ($change < 0) {
                    $this->inventoryPolicy->assertCanDecreaseStock(
                        $lockedStock,
                        abs($change),
                        $source
                    );
                }
                $lockedStock->update(['qty' => $before + $change]);
                if (abs((float) $product->current_stock - $stockTotalBefore) < 0.000001) {
                    $product->update(['current_stock' => $stockTotalBefore + $change]);
                }

                return new ProductStockBranchBalance([
                    'product_id' => $lockedStock->product_id,
                    'product_stock_id' => $lockedStock->id,
                    'store_branch_id' => $branch->id,
                    'quantity' => $before + $change,
                    'reserved_quantity' => 0,
                    'metadata' => ['feature_disabled' => true],
                ]);
            }

            ProductStockBranchBalance::query()->firstOrCreate(
                [
                    'product_stock_id' => $lockedStock->id,
                    'store_branch_id' => $branch->id,
                ],
                [
                    'product_id' => $lockedStock->product_id,
                    'quantity' => 0,
                    'reserved_quantity' => 0,
                ]
            );
            $balance = ProductStockBranchBalance::query()
                ->where('product_stock_id', $lockedStock->id)
                ->where('store_branch_id', $branch->id)
                ->lockForUpdate()
                ->firstOrFail();
            $after = (float) $balance->quantity + $change;
            if (! $this->inventoryPolicy->allowNegativeStock() && $after < -0.000001) {
                throw new DomainException(
                    "Insufficient stock in {$branch->name} for {$source}."
                );
            }

            $balance->update([
                'quantity' => $after,
                'last_movement_at' => now(),
                'metadata' => array_merge($balance->metadata ?? [], $metadata, [
                    'last_source' => $source,
                ]),
            ]);
            $this->syncAggregateProductStock($lockedStock);

            return $balance->fresh();
        });
    }
}
