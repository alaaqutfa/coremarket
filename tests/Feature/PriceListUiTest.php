<?php

namespace Tests\Feature;

use App\Models\PriceList;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PriceListUiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_admin_can_create_and_view_a_customer_price_list(): void
    {
        $this->assertSame('coremarket_testing', DB::getDatabaseName());
        $admin = new User();
        $admin->forceFill([
            'name' => 'Pricing Admin',
            'email' => 'pricing-admin-' . uniqid() . '@example.test',
            'password' => 'testing-password',
            'user_type' => 'admin',
        ])->save();

        $response = $this->actingAs($admin)->post(route('operations.price-lists.store'), [
            'name' => 'Wholesale A',
            'code' => 'wholesale-a-test',
            'type' => 'wholesale',
            'pricing_method' => 'fixed_price',
            'currency' => 'usd',
            'is_active' => 1,
        ]);
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('price_lists', ['name' => 'Wholesale A']);

        $list = PriceList::query()->where('code', 'WHOLESALE-A-TEST')->firstOrFail();
        $response->assertRedirect(route('operations.price-lists.show', $list));
        $this->actingAs($admin)->get(route('operations.price-lists.show', $list))
            ->assertOk()
            ->assertSee('Wholesale A')
            ->assertSee('Assign Customer');
    }
}
