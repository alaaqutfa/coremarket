# CoreMarket Branch and Staff Governance

## Branch Foundation

- `store_branches` is an operational company branch, separate from legacy marketplace `shops`.
- Every installation receives one active default `Main Branch`.
- Staff can belong to one or more branches through `staff_branch_assignments`, with one primary assignment.
- `owner_general_manager` is treated as all-branch access. Other roles use explicit assignments.
- Cashboxes still use their existing text location. Orders, purchases, product stock, and prices are not rewritten per branch in this foundation.

## Branch Policies

- `branches.enabled`: defaults to `false`.
- `branches.price_policy`: `unified` by default; `branch_specific_future` is a marker only.
- `branches.inventory_policy`: `unified` by default; `branch_specific_future` is a marker only.

Branch-specific stock and price resolution require a later implementation. Selecting a future policy today does not change operational calculations.

## Staff Governance

- Client administrators assign only approved staff presets.
- Client administrators cannot create arbitrary roles or edit raw permission checkboxes.
- `Super Admin` and raw role management are reserved for the platform administrator (`user_type=admin`).
- Client administrators can suspend/reactivate another staff account using the existing `users.banned` state.
- Client administrators cannot permanently delete staff. Hard delete remains platform-only.
- New staff creation uses the active plan's existing `staff_limit`; null means unlimited.

## Pricing Flags

- `pricing.price_lists_enabled`: defaults to `false`. Disabled client access falls back to sale price, then regular price, without deleting lists.
- `pricing.flexible_selling_price_enabled`: defaults to `false`. The pricing service rejects manual override unless enabled.
- Current POS has no manual override input. A later implementation must store resolved price, override, actor, and variance for reporting.

## Safe Demo Migration

```powershell
$env:DB_DATABASE="coremarket_demo"
php artisan migrate --force --path=database/migrations/2026_07_27_000001_create_store_branches_and_staff_assignments.php
php artisan db:seed --class=Database\\Seeders\\StaffRolePresetSeeder
Remove-Item Env:DB_DATABASE
```

Never run these commands against `coremarket_runtime`.
