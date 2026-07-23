<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseReceivingService;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\InteractsWithCoreMarketTestSchema;
use Tests\TestCase;

class SupplierAccountingUiTest extends TestCase
{
    use InteractsWithCoreMarketTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('coremarket.runtime_snapshot.connection', 'mysql');
        config()->set('coremarket.features.purchasing_suppliers', true);
        $this->ensureBusinessSettingsTable();
        $this->ensurePermissionTables();
        $this->ensureLegacyUserColumns();
        $this->ensureAdminSupportTables();
    }

    public function test_supplier_statement_payment_and_purchase_return_flow_render_and_complete(): void
    {
        DB::beginTransaction();
        try {
            [$supplier, $order] = $this->receivedPurchase();
            $user = $this->user([
                'supplier_ledger.view',
                'supplier_payments.create',
                'purchase_returns.view',
                'purchase_returns.create',
                'purchase_returns.complete',
                'purchase_returns.cancel',
            ]);

            $this->actingAs($user)->get(route('operations.suppliers.show', $supplier))
                ->assertOk()
                ->assertSee('Supplier Balance')
                ->assertSee('Record Supplier Payment');

            $this->actingAs($user)->post(route('operations.suppliers.payments.store', $supplier), [
                'payment_key' => 'ui-payment-'.uniqid(),
                'purchase_order_id' => $order->id,
                'amount' => 5,
                'currency' => 'USD',
                'exchange_rate' => 1,
                'payment_method' => 'cash',
                'paid_at' => now()->format('Y-m-d H:i:s'),
            ])->assertSessionHas('success');

            $this->actingAs($user)->get(route('operations.purchase-returns.create', ['purchase_order_id' => $order->id]))
                ->assertOk()
                ->assertSee($order->items->first()->product->name);

            $this->actingAs($user)->post(route('operations.purchase-returns.store'), [
                'purchase_order_id' => $order->id,
                'return_date' => now()->toDateString(),
                'reason' => 'UI return',
                'items' => [[
                    'purchase_order_item_id' => $order->items->first()->id,
                    'quantity' => 1,
                ]],
            ])->assertRedirect();

            $returnId = DB::table('purchase_returns')->where('purchase_order_id', $order->id)->latest('id')->value('id');
            $this->actingAs($user)->get(route('operations.purchase-returns.show', $returnId))
                ->assertOk()
                ->assertSee('Complete Return');
            $this->actingAs($user)->post(route('operations.purchase-returns.complete', $returnId))
                ->assertSessionHas('success');
            $this->assertDatabaseHas('purchase_returns', ['id' => $returnId, 'status' => 'completed']);
        } finally {
            DB::rollBack();
        }
    }

    private function receivedPurchase(): array
    {
        $now = now();
        $supplier = Supplier::query()->create(['name' => 'Supplier UI '.uniqid(), 'is_active' => true]);
        $productId = DB::table('products')->insertGetId([
            'name' => 'Supplier UI Product',
            'user_id' => 1,
            'category_id' => 1,
            'unit_price' => 20,
            'purchase_price' => 8,
            'current_stock' => 2,
            'slug' => 'supplier-ui-'.uniqid(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $stockId = DB::table('product_stocks')->insertGetId([
            'product_id' => $productId,
            'variant' => '',
            'sku' => 'SUPPLIER-UI-'.uniqid(),
            'price' => 20,
            'qty' => 2,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $receiving = app(PurchaseReceivingService::class);
        $order = $receiving->createPurchaseOrder([
            'supplier_id' => $supplier->id,
            'status' => 'ordered',
            'currency' => 'USD',
        ], [[
            'product_id' => $productId,
            'product_stock_id' => $stockId,
            'quantity_ordered' => 2,
            'unit_cost' => 8,
        ]]);
        $receiving->receive($order, [[
            'purchase_order_item_id' => $order->items->first()->id,
            'quantity_received' => 2,
        ]], ['receipt_key' => 'supplier-ui-receipt-'.uniqid()]);

        return [$supplier, $order->fresh('items.product')];
    }

    private function user(array $permissions): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::query()->firstOrCreate(['name' => 'Supplier UI '.uniqid(), 'guard_name' => 'web']);
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }
        $user = new User();
        $user->forceFill([
            'name' => 'Supplier UI QA',
            'email' => uniqid('supplier-ui').'@example.test',
            'password' => bcrypt('Temporary123!'),
            'user_type' => 'staff',
            'email_verified_at' => now(),
        ])->save();
        $user->assignRole($role);

        return $user;
    }
}
