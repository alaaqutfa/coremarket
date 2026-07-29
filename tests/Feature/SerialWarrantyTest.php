<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Order;
use App\Models\ProductSerialUnit;
use App\Models\ProductStock;
use App\Models\ProductWarrantyPolicy;
use App\Models\StoreBranch;
use App\Models\User;
use App\Models\WarrantyClaim;
use App\Services\CashboxService;
use App\Services\CoreMarketSerialInventoryService;
use App\Services\CoreMarketWarrantyService;
use App\Services\PurchaseReceivingService;
use App\Services\SalesReturnService;
use App\Services\WebPosService;
use Database\Seeders\OperationsPermissionSeeder;
use Database\Seeders\StaffRolePresetSeeder;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SerialWarrantyTest extends TestCase
{
    use DatabaseTransactions;

    private StoreBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('coremarket_testing', DB::getDatabaseName());
        $this->assertTrue(Schema::hasTable('product_serial_units'));
        $this->seed(OperationsPermissionSeeder::class);
        $this->seed(StaffRolePresetSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->setting('inventory.serial_tracking_enabled', true);
        $this->setting('inventory.imei_tracking_enabled', true);
        $this->setting('inventory.warranty_tracking_enabled', true);
        $this->branch = StoreBranch::query()->where('is_default', true)->firstOrFail();
    }

    protected function tearDown(): void
    {
        Cache::forget('business_settings');
        parent::tearDown();
    }

    public function test_purchase_receipt_creates_unique_serial_and_imei_units_in_branch_context(): void
    {
        [$stock, $actor] = $this->trackedStock();
        $service = app(PurchaseReceivingService::class);
        $order = $service->createPurchaseOrder(['status' => 'ordered'], [[
            'product_id' => $stock->product_id,
            'product_stock_id' => $stock->id,
            'quantity_ordered' => 2,
            'unit_cost' => 10,
        ]], $actor->id);

        $receipt = $service->receive($order, [[
            'purchase_order_item_id' => $order->items->first()->id,
            'quantity_received' => 2,
            'serials' => [
                ['serial_number' => 'SER-'.uniqid(), 'imei_1' => 'IMEI-'.uniqid()],
                ['serial_number' => 'SER-'.uniqid(), 'imei_1' => 'IMEI-'.uniqid()],
            ],
        ]], ['receipt_key' => 'serial-receipt-'.uniqid()], $actor->id);

        $units = ProductSerialUnit::query()->where('purchase_receipt_id', $receipt->id)->get();
        $this->assertCount(2, $units);
        $this->assertSame([$this->branch->id], $units->pluck('store_branch_id')->unique()->values()->all());

        $duplicate = $units->first();
        $secondOrder = $service->createPurchaseOrder(['status' => 'ordered'], [[
            'product_id' => $stock->product_id,
            'product_stock_id' => $stock->id,
            'quantity_ordered' => 1,
            'unit_cost' => 10,
        ]], $actor->id);
        $this->expectException(DomainException::class);
        $service->receive($secondOrder, [[
            'purchase_order_item_id' => $secondOrder->items->first()->id,
            'quantity_received' => 1,
            'serials' => [['serial_number' => $duplicate->serial_number, 'imei_1' => 'IMEI-'.uniqid()]],
        ]], ['receipt_key' => 'duplicate-receipt-'.uniqid()], $actor->id);
    }

    public function test_serial_tracked_pos_sale_requires_available_unit_and_marks_it_sold(): void
    {
        [$stock, $cashier] = $this->trackedStock('cashier');
        $unit = $this->unit($stock);
        $this->openShift($cashier);

        try {
            $this->posSale($stock, $cashier, []);
            $this->fail('Serialized sale without a unit should fail.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('serial unit count', strtolower($exception->getMessage()));
        }

        $order = $this->posSale($stock, $cashier, [$unit->id]);
        $unit->refresh();
        $this->assertSame('sold', $unit->status);
        $this->assertSame($order->id, $unit->order_id);
        $this->assertSame($order->orderDetails->sole()->id, $unit->order_detail_id);

        $this->expectException(DomainException::class);
        $this->posSale($stock, $cashier, [$unit->id]);
    }

    public function test_sales_return_requires_original_serial_and_restores_it_on_completion(): void
    {
        [$stock, $cashier] = $this->trackedStock('cashier');
        $unit = $this->unit($stock);
        $other = $this->unit($stock);
        $this->openShift($cashier);
        $order = $this->posSale($stock, $cashier, [$unit->id]);
        $detail = $order->orderDetails->sole();

        try {
            app(SalesReturnService::class)->create($order, [[
                'order_detail_id' => $detail->id,
                'quantity' => 1,
                'serial_unit_ids' => [$other->id],
            ]], [], $cashier->id);
            $this->fail('A unit not sold on this order item should fail.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('original order item', $exception->getMessage());
        }

        $return = app(SalesReturnService::class)->create($order, [[
            'order_detail_id' => $detail->id,
            'quantity' => 1,
            'serial_unit_ids' => [$unit->id],
        ]], [], $cashier->id);
        $this->assertSame('sold', $unit->fresh()->status);

        app(SalesReturnService::class)->complete($return, $cashier->id);
        $this->assertSame('in_stock', $unit->fresh()->status);
        $this->assertSame($return->id, $unit->fresh()->sales_return_id);
    }

    public function test_warranty_policy_sets_sale_expiry_and_claim_does_not_change_stock(): void
    {
        [$stock, $cashier] = $this->trackedStock('cashier');
        ProductWarrantyPolicy::query()->create([
            'product_id' => $stock->product_id,
            'product_stock_id' => $stock->id,
            'name' => 'Twelve Month Warranty',
            'warranty_months' => 12,
            'status' => 'active',
        ]);
        $unit = $this->unit($stock);
        $this->openShift($cashier);
        $order = $this->posSale($stock, $cashier, [$unit->id]);
        $unit->refresh();

        $this->assertNotNull($unit->warranty_expires_at);
        $this->assertSame(
            $order->created_at->copy()->addMonthsNoOverflow(12)->format('Y-m-d'),
            $unit->warranty_expires_at->format('Y-m-d')
        );
        $stockQuantity = (float) $stock->fresh()->qty;
        $claim = app(CoreMarketWarrantyService::class)->createClaim([
            'product_serial_unit_id' => $unit->id,
            'issue_description' => 'Screen issue',
        ], $cashier);

        $this->assertSame('received', $claim->status);
        $this->assertSame('warranty_claim', $unit->fresh()->status);
        $this->assertSame($stockQuantity, (float) $stock->fresh()->qty);
    }

    public function test_web_checkout_blocks_only_serial_tracked_variant(): void
    {
        [$tracked] = $this->trackedStock();
        [$normal] = $this->trackedStock();
        $normal->forceFill(['serial_tracking_enabled' => false, 'imei_tracking_enabled' => false])->save();
        $serials = app(CoreMarketSerialInventoryService::class);

        $serials->assertWebCheckoutAllowed(collect([
            new Cart(['product_id' => $normal->product_id, 'variation' => $normal->variant]),
        ]));
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('assisted POS');
        $serials->assertWebCheckoutAllowed(collect([
            new Cart(['product_id' => $tracked->product_id, 'variation' => $tracked->variant]),
        ]));
    }

    public function test_serial_and_warranty_permissions_follow_staff_presets(): void
    {
        $owner = $this->staff('owner', 'owner_general_manager');
        $warehouse = $this->staff('warehouse', 'warehouse_keeper');
        $cashier = $this->staff('cashier', 'cashier');
        $accountant = $this->staff('accountant', 'accountant');
        $driver = $this->staff('driver', 'delivery_distribution');
        $marketing = $this->staff('marketing', 'marketing_employee');

        $this->assertTrue($owner->can('warranty.claims.manage'));
        $this->assertTrue($warehouse->can('inventory.serials.receive'));
        $this->assertFalse($warehouse->can('warranty.claims.manage'));
        $this->assertTrue($cashier->can('inventory.serials.sell'));
        $this->assertTrue($accountant->can('warranty.claims.view'));
        $this->assertFalse($driver->can('warranty.claims.view'));
        $this->assertFalse($marketing->can('inventory.serials.view'));

        $this->actingAs($driver)->get(route('operations.warranty.index'))->assertForbidden();
        $this->actingAs($accountant)->get(route('operations.warranty.index'))->assertOk();
    }

    private function trackedStock(string $role = 'owner_general_manager'): array
    {
        $actor = $this->staff('serial-actor', $role);
        $now = now();
        $productId = DB::table('products')->insertGetId([
            'name' => 'Serialized Product '.uniqid(),
            'user_id' => $actor->id,
            'category_id' => 1,
            'unit_price' => 100,
            'purchase_price' => 50,
            'current_stock' => 5,
            'slug' => 'serialized-'.uniqid(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $stockId = DB::table('product_stocks')->insertGetId([
            'product_id' => $productId,
            'variant' => 'Black-128GB',
            'sku' => 'SERIAL-SKU-'.uniqid(),
            'barcode' => 'SERIAL-BC-'.uniqid(),
            'price' => 100,
            'qty' => 5,
            'serial_tracking_enabled' => true,
            'imei_tracking_enabled' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [ProductStock::query()->with('product')->findOrFail($stockId), $actor];
    }

    private function unit(ProductStock $stock): ProductSerialUnit
    {
        return ProductSerialUnit::query()->create([
            'product_id' => $stock->product_id,
            'product_stock_id' => $stock->id,
            'store_branch_id' => $this->branch->id,
            'serial_number' => 'UNIT-'.uniqid(),
            'imei_1' => 'IMEI-'.uniqid(),
            'status' => 'in_stock',
        ]);
    }

    private function posSale(ProductStock $stock, User $cashier, array $serialUnitIds): Order
    {
        return app(WebPosService::class)->createPosOrder([[
            'product_stock_id' => $stock->id,
            'quantity' => 1,
            'serial_unit_ids' => $serialUnitIds,
        ]], [
            'payment_type' => 'cash',
            'paid_amount' => 150,
        ], $cashier, 'serial-pos-'.uniqid());
    }

    private function openShift(User $cashier): void
    {
        $cashboxes = app(CashboxService::class);
        $cashbox = $cashboxes->createCashbox([
            'name' => 'Serial Register',
            'code' => 'SERIAL-CASH-'.uniqid(),
            'currency' => 'USD',
            'status' => 'active',
        ]);
        $cashboxes->openShift($cashbox, $cashier, 0);
    }

    private function staff(string $prefix, string $role): User
    {
        $user = User::query()->create([
            'name' => ucwords(str_replace('_', ' ', $role)),
            'email' => $prefix.'-'.uniqid().'@example.test',
            'password' => bcrypt('Temporary123!'),
        ]);
        $user->forceFill(['user_type' => 'staff', 'banned' => 0])->save();
        $user->syncRoles($role);

        return $user->fresh();
    }

    private function setting(string $type, bool $enabled): void
    {
        DB::table('business_settings')->updateOrInsert(
            ['type' => $type, 'lang' => null],
            ['value' => $enabled ? '1' : '0']
        );
        Cache::forget('business_settings');
    }
}
