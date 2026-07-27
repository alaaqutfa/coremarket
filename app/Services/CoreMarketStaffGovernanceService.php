<?php

namespace App\Services;

use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;

class CoreMarketStaffGovernanceService
{
    public function __construct(private CoreMarketFeatureAccessService $features)
    {
    }

    public function staffLimit(): ?int
    {
        $limit = $this->features->limit('staff_limit');

        return $limit === null || $limit === '' ? null : max(0, (int) $limit);
    }

    public function currentStaffCount(): int
    {
        return Staff::query()->whereHas('user', fn ($query) => $query->where('user_type', 'staff'))->count();
    }

    public function canCreateStaff(?User $actor = null): bool
    {
        if ($actor?->user_type === 'admin') {
            return true;
        }

        $limit = $this->staffLimit();

        return $limit === null || $this->currentStaffCount() < $limit;
    }

    public function assertCanCreateStaff(?User $actor = null): void
    {
        if (! $this->canCreateStaff($actor)) {
            throw new DomainException('The staff limit for the current plan has been reached.');
        }
    }

    public function allowedRolePresetsForClient(): Collection
    {
        return Role::query()
            ->whereIn('name', config('coremarket.access.client_assignable_staff_roles', []))
            ->orderBy('name')
            ->get();
    }

    public function rolesAssignableBy(?User $actor): Collection
    {
        if ($actor?->user_type === 'admin') {
            return Role::query()->where('name', '!=', 'Super Admin')->orderBy('name')->get();
        }

        return $this->allowedRolePresetsForClient();
    }

    public function assignPresetToUser(User $user, int $roleId, ?User $actor): Role
    {
        $role = $this->rolesAssignableBy($actor)->firstWhere('id', $roleId);
        if (! $role) {
            throw new DomainException('This role preset cannot be assigned by a client administrator.');
        }

        $user->syncRoles($role->name);

        return $role;
    }

    public function canManageRawPermissions(?User $actor): bool
    {
        return $actor?->user_type === 'admin';
    }

    public function canSuspendStaff(?User $actor, User $staffUser): bool
    {
        return $actor !== null
            && $staffUser->user_type === 'staff'
            && $actor->id !== $staffUser->id
            && (
                $actor->user_type === 'admin'
                || $actor->hasAnyRole(['owner_general_manager', config('coremarket.access.store_admin_role', 'store_admin')])
                || $actor->can('edit_staff')
            );
    }

    public function canDeleteStaff(?User $actor): bool
    {
        return $actor?->user_type === 'admin';
    }
}
