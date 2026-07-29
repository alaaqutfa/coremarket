<?php

namespace Tests\Feature;

use App\Models\InventoryAdjustmentDocument;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockCount;
use App\Models\User;
use App\Services\CoreMarketInventoryAdjustmentService;
use App\Services\CoreMarketInventoryPolicyService;
use App\Services\CoreMarketStockCountService;
use App\Services\InventoryProService;
use App\Services\ProductStockService;
use Database\Seeders\OperationsPermissionSeeder;
use Database\Seeders\StaffRolePresetSeeder;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class InventoryGovernanceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('coremarket_testing', DB::getDatabaseName());
        foreach (['inventory_adjustment_documents', 'inventory_adjustment_items', 'stock_counts', 'stock_count_items'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing {$table} test table.");
        }
        config()->set('coremarket.features.inventory_pro', true);
        $this->seed(OperationsPermissionSeeder::class);
        $this->seed(StaffRolePresetSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->setPolicyDefaults();
    }

    public function test_product_stock_creation_starts_at_zero_and_direct_adjustment_is_blocked(): void
    {
        $this->setting(CoreMarketInventoryPolicyService::STRICT_MODE_SETTING, true);
        $product = $this->product(0);
        app(ProductStockService::class)->store([
            'product_id' => $product->id,
            'unit_price' => 15,
            'current_stock' => 9,
            'sku' => 'GOV-ZERO-'.uniqid(),
        ], $product);
        $stock = $product->stocks()->firstOrFail();

        $this->assertSame(0.0, (float) $stock->qty);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Direct stock adjustments are disabled');
        app(InventoryProService::class)->adjustStock($stock, [
            'adjustment_type' => 'increase',
            'quantity' => 1,
            'reason' => 'Unsafe edit',
        ]);
    }

    public function test_product_edit_cannot_remove_a_variant_that_still_has_stock(): void
    {
        $product = $this->product(4);
        ProductStock::query()->create([
            'product_id' => $product->id,
            'variant' => 'Legacy Variant',
            'sku' => 'GOV-VARIANT-'.uniqid(),
            'price' => 15,
            'qty' => 4,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('A variant with stock cannot be removed');
        app(ProductStockService::class)->assertVariantChangesDoNotDiscardStock([
            'choice_no' => [],
            'colors' => [],
        ], $product);
    }

    public function test_opening_stock_draft_does_not_change_stock_and_post_creates_one_movement(): void
    {
        $manager = $this->staff('opening-manager', 'owner_general_manager');
        [, $stock] = $this->productWithStock(0);
        $service = app(CoreMarketInventoryAdjustmentService::class);
        $document = $service->createOpeningStockDocument([
            'reason' => 'Initial setup',
            'idempotency_key' => 'opening-'.uniqid(),
            'items' => [[
                'product_stock_id' => $stock->id,
                'quantity_change' => 12,
                'unit_cost' => 4,
            ]],
        ], $manager);

        $this->assertSame('draft', $document->status);
        $this->assertSame(0.0, (float) $stock->fresh()->qty);
        $service->submitForApproval($document, $manager);
        $this->assertSame(0.0, (float) $stock->fresh()->qty);
        $service->approve($document->fresh(), $manager);
        $service->post($document->fresh(), $manager);
        $service->post($document->fresh(), $manager);

        $this->assertSame(12.0, (float) $stock->fresh()->qty);
        $this->assertSame(1, InventoryMovement::query()
            ->where('movement_type', 'opening_stock')
            ->where('product_stock_id', $stock->id)
            ->count());
    }

    public function test_legacy_order_restock_is_documented_and_idempotent(): void
    {
        [$product, $stock] = $this->productWithStock(3);
        $product->update(['num_of_sale' => 2, 'current_stock' => 3]);
        $now = now();
        $orderId = DB::table('orders')->insertGetId([
            'shipping_type' => 'home_delivery',
            'date' => $now->timestamp,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $detailId = DB::table('order_details')->insertGetId([
            'order_id' => $orderId,
            'product_id' => $product->id,
            'variation' => '',
            'price' => 30,
            'quantity' => 2,
            'delivery_status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $detail = \App\Models\OrderDetail::query()->findOrFail($detailId);

        product_restock($detail);
        product_restock($detail->fresh());

        $this->assertSame(5.0, (float) $stock->fresh()->qty);
        $this->assertSame(5.0, (float) $product->fresh()->current_stock);
        $this->assertSame(1, InventoryMovement::query()
            ->where('order_detail_id', $detailId)
            ->where('movement_type', 'sale_reversal')
            ->count());
    }

    public function test_adjustment_requires_approval_rejects_invalid_states_and_prevents_double_post(): void
    {
        $manager = $this->staff('adjustment-manager', 'owner_general_manager');
        [, $stock] = $this->productWithStock(5);
        $service = app(CoreMarketInventoryAdjustmentService::class);
        $document = $this->adjustment($service, $manager, $stock, -2);

        $this->expectDomainException(fn () => $service->post($document, $manager), 'approved');
        $service->submitForApproval($document, $manager);
        $this->expectDomainException(fn () => $service->post($document->fresh(), $manager), 'approved');
        $service->approve($document->fresh(), $manager);
        $service->post($document->fresh(), $manager);
        $service->post($document->fresh(), $manager);

        $this->assertSame(3.0, (float) $stock->fresh()->qty);
        $this->assertSame(1, InventoryMovement::query()
            ->where('reference_type', \App\Models\InventoryAdjustmentItem::class)
            ->where('movement_type', 'stock_adjustment')
            ->count());

        $rejected = $this->adjustment($service, $manager, $stock->fresh(), 1);
        $service->submitForApproval($rejected, $manager);
        $service->reject($rejected->fresh(), $manager, 'Not justified');
        $this->expectDomainException(fn () => $service->post($rejected->fresh(), $manager), 'approved');
        $this->assertSame(3.0, (float) $stock->fresh()->qty);
    }

    public function test_negative_stock_policy_is_enforced_at_posting(): void
    {
        $manager = $this->staff('negative-manager', 'owner_general_manager');
        [, $stock] = $this->productWithStock(2);
        $service = app(CoreMarketInventoryAdjustmentService::class);
        $blocked = $this->approveAdjustment($service, $manager, $stock, -3);

        $this->expectDomainException(fn () => $service->post($blocked, $manager), 'negative inventory');
        $this->assertSame(2.0, (float) $stock->fresh()->qty);

        $this->setting(CoreMarketInventoryPolicyService::NEGATIVE_STOCK_SETTING, true);
        $service->post($blocked->fresh(), $manager);
        $this->assertSame(-1.0, (float) $stock->fresh()->qty);
    }

    public function test_stock_count_posts_variance_through_adjustment_and_is_idempotent(): void
    {
        $manager = $this->staff('count-manager', 'owner_general_manager');
        [, $stock] = $this->productWithStock(10);
        $counts = app(CoreMarketStockCountService::class);
        $count = $counts->createStockCount([
            'notes' => 'Cycle count',
            'items' => [[
                'product_stock_id' => $stock->id,
                'counted_quantity' => 7,
            ]],
        ], $manager);

        $this->assertSame(-3.0, (float) $count->items->first()->variance_quantity);
        $this->assertSame(10.0, (float) $stock->fresh()->qty);
        $counts->submitForApproval($count, $manager);
        $counts->approve($count->fresh(), $manager);
        $counts->postVarianceAsAdjustment($count->fresh(), $manager);
        $counts->postVarianceAsAdjustment($count->fresh(), $manager);

        $this->assertSame(7.0, (float) $stock->fresh()->qty);
        $this->assertSame(1, InventoryMovement::query()->where('movement_type', 'stock_count_variance')->count());
        $this->assertSame('posted', StockCount::query()->findOrFail($count->id)->status);
    }

    public function test_permissions_and_branch_are_governance_context_only(): void
    {
        $warehouse = $this->staff('governance-warehouse', 'warehouse_keeper');
        $cashier = $this->staff('governance-cashier', 'cashier');
        $delivery = $this->staff('governance-delivery', 'delivery_distribution');
        $manager = $this->staff('governance-owner', 'owner_general_manager');
        [, $stock] = $this->productWithStock(1);
        $branchId = DB::table('store_branches')->where('is_default', 1)->value('id');

        $this->assertTrue($warehouse->can('inventory.adjustments.create'));
        $this->assertFalse($warehouse->can('inventory.adjustments.approve'));
        $this->assertFalse($warehouse->can('inventory.adjustments.emergency'));
        $this->assertFalse($cashier->can('inventory.adjustments.view'));
        $this->assertFalse($delivery->can('inventory.stock_counts.view'));
        $this->actingAs($cashier)->get(route('operations.inventory.adjustments.index'))->assertForbidden();
        $this->actingAs($delivery)->get(route('operations.inventory.stock-counts.index'))->assertForbidden();
        $this->actingAs($warehouse)->get(route('operations.inventory.adjustments.index'))->assertOk()->assertSee('Inventory Adjustments');
        $this->actingAs($warehouse)->get(route('operations.inventory.adjustments.create'))->assertOk()->assertSee('Create Stock Adjustment');
        $this->actingAs($warehouse)->get(route('operations.inventory.stock-counts.index'))->assertOk()->assertSee('Stock Counts');
        $this->actingAs($warehouse)->get(route('operations.inventory.stock-counts.create'))->assertOk()->assertSee('Create Stock Count');

        $document = app(CoreMarketInventoryAdjustmentService::class)->createAdjustmentDocument([
            'adjustment_type' => 'correction',
            'branch_id' => $branchId,
            'reason' => 'Branch context test',
            'idempotency_key' => 'branch-context-'.uniqid(),
            'items' => [['product_stock_id' => $stock->id, 'quantity_change' => 1]],
        ], $manager);
        $this->assertSame((int) $branchId, (int) $document->branch_id);
        $this->assertTrue($document->metadata['branch_context_only']);
        $this->assertFalse(Schema::hasColumn('product_stocks', 'branch_id'));
        $this->actingAs($warehouse)->get(route('operations.inventory.adjustments.show', $document))->assertOk()->assertSee($document->reference_no);
    }

    private function adjustment(
        CoreMarketInventoryAdjustmentService $service,
        User $actor,
        ProductStock $stock,
        float $change
    ): InventoryAdjustmentDocument {
        return $service->createAdjustmentDocument([
            'adjustment_type' => 'stock_adjustment',
            'reason' => 'Governance test',
            'idempotency_key' => 'adjustment-'.uniqid(),
            'items' => [['product_stock_id' => $stock->id, 'quantity_change' => $change]],
        ], $actor);
    }

    private function approveAdjustment(
        CoreMarketInventoryAdjustmentService $service,
        User $actor,
        ProductStock $stock,
        float $change
    ): InventoryAdjustmentDocument {
        $document = $this->adjustment($service, $actor, $stock, $change);
        $service->submitForApproval($document, $actor);

        return $service->approve($document->fresh(), $actor);
    }

    private function expectDomainException(callable $callback, string $message): void
    {
        try {
            $callback();
            $this->fail('Expected a domain exception.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function productWithStock(float $qty): array
    {
        $product = $this->product($qty);
        $stock = ProductStock::query()->create([
            'product_id' => $product->id,
            'variant' => '',
            'sku' => 'GOV-'.uniqid(),
            'barcode' => 'GOV-BAR-'.uniqid(),
            'price' => 15,
            'qty' => $qty,
        ]);

        return [$product, $stock];
    }

    private function product(float $qty): Product
    {
        return Product::query()->create([
            'name' => 'Governance Product '.uniqid(),
            'user_id' => User::query()->where('user_type', 'admin')->value('id') ?: 1,
            'category_id' => DB::table('categories')->value('id') ?: 1,
            'unit_price' => 15,
            'purchase_price' => 5,
            'current_stock' => $qty,
            'slug' => 'governance-'.uniqid(),
            'published' => 1,
            'approved' => 1,
        ]);
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

    private function setPolicyDefaults(): void
    {
        foreach ([
            CoreMarketInventoryPolicyService::STRICT_MODE_SETTING => false,
            CoreMarketInventoryPolicyService::NEGATIVE_STOCK_SETTING => false,
            CoreMarketInventoryPolicyService::SETUP_MODE_SETTING => true,
            CoreMarketInventoryPolicyService::OPENING_STOCK_SETTING => true,
            CoreMarketInventoryPolicyService::ADJUSTMENTS_SETTING => true,
            CoreMarketInventoryPolicyService::ADJUSTMENT_APPROVAL_SETTING => true,
            CoreMarketInventoryPolicyService::STOCK_COUNTS_SETTING => true,
            CoreMarketInventoryPolicyService::EMERGENCY_ADJUSTMENT_SETTING => false,
        ] as $key => $value) {
            $this->setting($key, $value);
        }
    }

    private function setting(string $key, bool $value): void
    {
        DB::table('business_settings')->updateOrInsert(
            ['type' => $key, 'lang' => null],
            ['value' => $value ? '1' : '0']
        );
        Cache::forget('business_settings');
    }
}
