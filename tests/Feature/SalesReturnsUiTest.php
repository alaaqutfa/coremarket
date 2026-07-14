<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\SalesReturnService;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\InteractsWithCoreMarketTestSchema;
use Tests\TestCase;

class SalesReturnsUiTest extends TestCase
{
    use InteractsWithCoreMarketTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('coremarket.runtime_snapshot.connection', 'mysql');
        $this->ensureBusinessSettingsTable();
        $this->ensurePermissionTables();
        $this->ensureLegacyUserColumns();
        $this->ensureAdminSupportTables();
        config()->set('coremarket.features.returns_management', true);
    }

    public function test_authorized_user_can_view_returns_and_returnable_order_details(): void
    {
        DB::beginTransaction();
        try {
            [$order, $detail] = $this->fixtures();
            app(SalesReturnService::class)->create($order, [['order_detail_id' => $detail->id, 'quantity' => 2]]);
            $user = $this->user(['sales_returns.view', 'sales_returns.create']);

            $this->actingAs($user)->get(route('operations.sales-returns'))->assertOk()->assertSee('Sales Returns');
            $this->actingAs($user)->get(route('operations.sales-returns.create', ['order_id' => $order->id]))
                ->assertOk()->assertSee('Previously Returned')->assertSee('Remaining')->assertSee('Cost / Profit');
        } finally { DB::rollBack(); }
    }

    public function test_partial_return_can_be_created_and_completed_once_from_ui(): void
    {
        DB::beginTransaction();
        try {
            [$order, $detail, $stockId] = $this->fixtures();
            $user = $this->user(['sales_returns.view', 'sales_returns.create', 'sales_returns.complete']);
            $response = $this->actingAs($user)->post(route('operations.sales-returns.store'), [
                'order_id' => $order->id,
                'reason' => 'Damaged',
                'items' => [['order_detail_id' => $detail->id, 'quantity' => 2, 'reason' => 'Damaged']],
            ]);
            $response->assertRedirect();
            $returnId = DB::table('sales_returns')->where('order_id', $order->id)->value('id');

            $this->actingAs($user)->get(route('operations.sales-returns.show', $returnId))
                ->assertOk()->assertSee('Stock reversal trace')->assertSee('Profit Reversal');
            $this->actingAs($user)->post(route('operations.sales-returns.complete', $returnId))->assertSessionHas('success');
            $this->actingAs($user)->post(route('operations.sales-returns.complete', $returnId))->assertSessionHas('success');

            $this->assertSame(5, (int) DB::table('product_stocks')->where('id', $stockId)->value('qty'));
            $this->assertDatabaseCount('inventory_movements', 1);
            $this->assertDatabaseHas('inventory_movements', ['movement_type' => 'sale_reversal', 'direction' => 'in', 'order_detail_id' => $detail->id]);
            $this->assertDatabaseCount('accounting_events', 1);
            $this->assertDatabaseHas('accounting_events', ['event_type' => 'sale_return', 'order_detail_id' => $detail->id]);
        } finally { DB::rollBack(); }
    }

    public function test_ui_rejects_return_quantity_above_remaining_and_feature_gate_remains_enforced(): void
    {
        DB::beginTransaction();
        try {
            [$order, $detail] = $this->fixtures();
            $user = $this->user(['sales_returns.create', 'sales_returns.view']);
            $this->actingAs($user)->post(route('operations.sales-returns.store'), [
                'order_id' => $order->id,
                'items' => [['order_detail_id' => $detail->id, 'quantity' => 6]],
            ])->assertSessionHasErrors('items');

            config()->set('coremarket.features.returns_management', false);
            $this->actingAs($user)->get(route('operations.sales-returns'))->assertNotFound();
            $this->actingAs($this->user([]))->get(route('operations.sales-returns'))->assertForbidden();
        } finally { DB::rollBack(); }
    }

    private function fixtures(): array
    {
        $now = now();
        $productId = DB::table('products')->insertGetId(['name' => 'Sales Returns UI Product', 'user_id' => 1, 'category_id' => 1, 'unit_price' => 10, 'purchase_price' => 8, 'current_stock' => 3, 'slug' => 'sales-returns-ui-'.uniqid(), 'created_at' => $now, 'updated_at' => $now]);
        $stockId = DB::table('product_stocks')->insertGetId(['product_id' => $productId, 'variant' => '', 'sku' => 'SRI-'.uniqid(), 'barcode' => 'SRI-B-'.uniqid(), 'price' => 10, 'qty' => 3, 'created_at' => $now, 'updated_at' => $now]);
        $orderId = DB::table('orders')->insertGetId(['shipping_type' => 'home_delivery', 'date' => $now->timestamp, 'created_at' => $now, 'updated_at' => $now]);
        $detailId = DB::table('order_details')->insertGetId(['order_id' => $orderId, 'product_id' => $productId, 'variation' => '', 'price' => 50, 'tax' => 5, 'shipping_cost' => 10, 'quantity' => 5, 'cost_price' => 8, 'cost_source' => 'product_purchase_price', 'total_cost' => 40, 'profit_amount' => 10, 'created_at' => $now, 'updated_at' => $now]);
        return [Order::findOrFail($orderId), DB::table('order_details')->where('id', $detailId)->first(), $stockId];
    }

    private function user(array $permissions): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::query()->firstOrCreate(['name' => 'Sales Returns UI '.uniqid(), 'guard_name' => 'web']);
        foreach ($permissions as $permission) $role->givePermissionTo(Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        $user = new User();
        $user->forceFill(['name' => 'Sales Returns QA', 'email' => uniqid('sales-return').'@example.test', 'password' => bcrypt('Temporary123!'), 'user_type' => 'staff', 'email_verified_at' => now()])->save();
        $user->assignRole($role);
        return $user;
    }
}
