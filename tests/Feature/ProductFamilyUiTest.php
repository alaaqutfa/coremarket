<?php

namespace Tests\Feature;

use App\Models\ProductFamily;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductFamilyUiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_admin_can_manage_families_and_sub_families(): void
    {
        $this->assertSame('coremarket_testing', DB::getDatabaseName());
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('operations.inventory.families.store'), [
            'name' => 'Food',
            'code' => 'food',
            'level' => 'family',
            'is_active' => 1,
        ])->assertRedirect(route('operations.inventory.families.index'))
            ->assertSessionHasNoErrors();

        $family = ProductFamily::query()->where('code', 'FOOD')->firstOrFail();
        $this->actingAs($admin)->post(route('operations.inventory.families.store'), [
            'name' => 'Snacks',
            'code' => 'snacks',
            'level' => 'sub_family',
            'parent_id' => $family->id,
            'is_active' => 1,
        ])->assertRedirect(route('operations.inventory.families.index'))
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)->get(route('operations.inventory.families.index'))
            ->assertOk()
            ->assertSee('Food')
            ->assertSee('Snacks');
    }

    public function test_sub_family_cannot_be_saved_under_another_sub_family(): void
    {
        $admin = $this->admin();
        $family = ProductFamily::query()->create([
            'name' => 'Food',
            'code' => 'FOOD-' . strtoupper(uniqid()),
            'level' => 'family',
            'is_active' => true,
        ]);
        $subFamily = ProductFamily::query()->create([
            'parent_id' => $family->id,
            'name' => 'Snacks',
            'code' => 'SNACKS-' . strtoupper(uniqid()),
            'level' => 'sub_family',
            'is_active' => true,
        ]);

        $this->actingAs($admin)->from(route('operations.inventory.families.create'))
            ->post(route('operations.inventory.families.store'), [
                'name' => 'Invalid Child',
                'code' => 'invalid-child',
                'level' => 'sub_family',
                'parent_id' => $subFamily->id,
                'is_active' => 1,
            ])
            ->assertRedirect(route('operations.inventory.families.create'))
            ->assertSessionHasErrors('parent_id');
    }

    private function admin(): User
    {
        $admin = new User();
        $admin->forceFill([
            'name' => 'Product Family Admin',
            'email' => 'product-family-' . uniqid() . '@example.test',
            'password' => 'testing-password',
            'user_type' => 'admin',
        ])->save();

        return $admin;
    }
}
