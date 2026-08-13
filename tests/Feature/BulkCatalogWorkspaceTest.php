<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BulkCatalogWorkspaceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_super_admin_can_use_the_single_bulk_catalog_workspace(): void
    {
        $superAdmin = $this->user('admin');
        $superAdmin->syncRoles([Role::query()->firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web'])]);

        $this->actingAs($superAdmin)
            ->get(route('bulk-catalog.index'))
            ->assertOk()
            ->assertSee('Bulk Catalog');

        $this->actingAs($superAdmin)
            ->get(route('bulk-catalog.template', 'products'))
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=products-bulk-import-template.xlsx');

        $this->actingAs($superAdmin)
            ->get(route('bulk-catalog.products.export'))
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=bulk-catalog-products.xlsx');
    }

    public function test_staff_and_seller_are_denied_and_legacy_admin_bookmarks_redirect(): void
    {
        $staff = $this->user('staff');
        $seller = $this->user('seller');
        $superAdmin = $this->user('admin');
        $superAdmin->syncRoles([Role::query()->firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web'])]);

        $this->actingAs($staff)->get(route('bulk-catalog.index'))->assertForbidden();
        $this->actingAs($seller)->get(route('bulk-catalog.index'))->assertNotFound();
        $this->actingAs($seller)->get('/seller/product-bulk-upload/index')->assertNotFound();

        $this->actingAs($superAdmin)
            ->get(route('product_bulk_upload.index'))
            ->assertRedirect(route('bulk-catalog.index'));
        $this->actingAs($superAdmin)
            ->get(route('brand_bulk_upload.index'))
            ->assertRedirect(route('bulk-catalog.index'));
        $this->actingAs($superAdmin)
            ->get(route('product_bulk_export.index'))
            ->assertRedirect(route('bulk-catalog.index'));
    }

    private function user(string $userType): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Bulk Catalog Test '.Str::random(8),
            'email' => Str::lower(Str::random(16)).'@example.test',
            'password' => Hash::make('BulkCatalogPassword123!'),
            'user_type' => $userType,
            'banned' => 0,
            'email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::query()->findOrFail($id);
    }
}
