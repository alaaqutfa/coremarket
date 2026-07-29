<?php

namespace App\Services;

use App\Models\StoreBranch;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CoreMarketBranchService
{
    public function branchesEnabled(): bool
    {
        return $this->settingBool('branches.enabled', false);
    }

    public function pricePolicy(): string
    {
        return $this->policy('branches.price_policy');
    }

    public function inventoryPolicy(): string
    {
        return $this->policy('branches.inventory_policy');
    }

    public function defaultBranch(): ?StoreBranch
    {
        return StoreBranch::query()->where('is_default', true)->first()
            ?? StoreBranch::query()->where('is_active', true)->oldest('id')->first();
    }

    public function activeBranches(): Collection
    {
        return StoreBranch::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get();
    }

    public function resolveBranch(int|string|null $id = null): StoreBranch
    {
        if ($id !== null && $id !== '') {
            return StoreBranch::query()->whereKey($id)->where('is_active', true)->firstOrFail();
        }

        return $this->defaultBranch() ?? $this->ensureDefaultBranch();
    }

    public function ensureDefaultBranch(): StoreBranch
    {
        return DB::transaction(function () {
            $branch = $this->defaultBranch();
            if ($branch) {
                if (! $branch->is_default) {
                    $branch->forceFill(['is_default' => true])->save();
                }

                return $branch;
            }

            return StoreBranch::query()->create([
                'name' => 'Main Branch',
                'code' => 'MAIN',
                'is_default' => true,
                'is_active' => true,
                'metadata' => ['source' => 'coremarket_branch_foundation'],
            ]);
        });
    }

    public function setDefault(StoreBranch $branch): void
    {
        if (! $branch->is_active) {
            throw new DomainException('The default branch must be active.');
        }

        DB::transaction(function () use ($branch) {
            StoreBranch::query()->where('id', '!=', $branch->id)->update(['is_default' => false]);
            $branch->forceFill(['is_default' => true])->save();
        });
    }

    public function assignStaff(User $user, array $branchIds, ?int $primaryBranchId = null): void
    {
        $branchIds = array_values(array_unique(array_map('intval', $branchIds)));
        if ($branchIds === []) {
            $branchIds = [$this->resolveBranch()->id];
        }
        if ($primaryBranchId === null || ! in_array($primaryBranchId, $branchIds, true)) {
            $primaryBranchId = $branchIds[0];
        }

        $activeIds = StoreBranch::query()->whereIn('id', $branchIds)->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (count($activeIds) !== count($branchIds)) {
            throw new DomainException('Staff can only be assigned to active branches.');
        }

        $user->branches()->sync(collect($branchIds)->mapWithKeys(fn (int $id) => [
            $id => ['is_primary' => $id === $primaryBranchId],
        ])->all());
    }

    public function userHasAllBranches(User $user): bool
    {
        return $user->hasRole('owner_general_manager');
    }

    public function branchSnapshot(StoreBranch $branch): array
    {
        return [
            'id' => $branch->id,
            'code' => $branch->code,
            'name' => $branch->name,
            'is_default' => $branch->is_default,
            'is_active' => $branch->is_active,
        ];
    }

    private function policy(string $key): string
    {
        $configKey = Str::after($key, 'branches.');
        $value = (string) get_setting($key, config("coremarket.branch.{$configKey}", 'unified'));

        return in_array($value, ['unified', 'branch_specific', 'branch_specific_future'], true)
            ? $value
            : 'unified';
    }

    private function settingBool(string $key, bool $default): bool
    {
        return filter_var(get_setting($key, $default), FILTER_VALIDATE_BOOL);
    }
}
