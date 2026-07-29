<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductStockBranchBalance;
use App\Models\StoreBranch;
use App\Models\User;
use App\Services\CashboxService;
use App\Services\CoreMarketBranchInventoryService;
use App\Services\CoreMarketInventoryAdjustmentService;
use App\Services\CoreMarketInventoryPolicyService;
use App\Services\PurchaseReceivingService;
use App\Services\WebPosService;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BranchInventoryTest extends TestCase
{
    use DatabaseTransactions;

    private CoreMarketBranchInventoryService $inventory;

    private StoreBranch $main;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('coremarket_testing', DB::getDatabaseName());
        $this->assertTrue(Schema::hasTable('product_stock_branch_balances'));
        $this->inventory = app(CoreMarketBranchInventoryService::class);
        $this->main = $this->inventory->defaultBranch();
        $this->setting(CoreMarketInventoryPolicyService::NEGATIVE_STOCK_SETTING, false);
        $this->setting(CoreMarketBranchInventoryService::SETTING, true);
    }

    public function test_feature_disabled_keeps_legacy_aggregate_behavior(): void
    {
        $this->setting(CoreMarketBranchInventoryService::SETTING, false);
        [, $stock] = $this->productWithStock(2);

        $this->inventory->increaseBranchStock($stock, $this->main, 3, 'disabled feature test');

        $this->assertSame(5.0, (float) $stock->fresh()->qty);
        $this->assertDatabaseMissing('product_stock_branch_balances', [
            'product_stock_id' => $stock->id,
        ]);
    }

    public function test_initialize_command_is_dry_run_first_and_apply_is_idempotent(): void
    {
        [, $stock] = $this->productWithStock(7);

        $this->artisan('coremarket:branch-inventory-initialize')->assertSuccessful();
        $this->assertDatabaseMissing('product_stock_branch_balances', [
            'product_stock_id' => $stock->id,
        ]);

        $this->artisan('coremarket:branch-inventory-initialize', [
            '--apply' => true,
            '--confirm-branch-inventory' => true,
        ])->assertSuccessful();
        $this->artisan('coremarket:branch-inventory-initialize', [
            '--apply' => true,
            '--confirm-branch-inventory' => true,
        ])->assertSuccessful();

        $this->assertSame(1, ProductStockBranchBalance::query()
            ->where('product_stock_id', $stock->id)->count());
        $this->assertSame(7.0, (float) $this->inventory->getBranchBalance($stock, $this->main)->quantity);
    }

    public function test_branch_mutations_sync_aggregate_and_respect_negative_policy(): void
    {
        [$product, $stock] = $this->productWithStock(0);
        $second = $this->branch('SECOND');

        $this->inventory->increaseBranchStock($stock, $this->main, 5, 'test');
        $this->inventory->increaseBranchStock($stock, $second, 2, 'test');
        $this->assertSame(7.0, (float) $stock->fresh()->qty);
        $this->assertSame(7.0, (float) $product->fresh()->current_stock);

        $this->inventory->decreaseBranchStock($stock, $this->main, 3, 'test');
        $this->assertSame(4.0, (float) $stock->fresh()->qty);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Insufficient stock');
        $this->inventory->decreaseBranchStock($stock, $second, 3, 'negative blocked');
    }

    public function test_purchase_receipt_and_governed_adjustment_post_to_selected_branch(): void
    {
        $actor = $this->admin();
        [$product, $stock] = $this->productWithStock(0);
        $second = $this->branch('RECEIVE');
        $purchase = app(PurchaseReceivingService::class);
        $order = $purchase->createPurchaseOrder(['status' => 'ordered'], [[
            'product_id' => $product->id,
            'product_stock_id' => $stock->id,
            'quantity_ordered' => 5,
            'unit_cost' => 4,
        ]], $actor->id);
        $purchase->receive($order, [[
            'purchase_order_item_id' => $order->items->first()->id,
            'quantity_received' => 2,
        ]], [
            'receipt_key' => 'branch-receipt-'.uniqid(),
            'branch_id' => $second->id,
        ], $actor->id);

        $this->assertSame(2.0, (float) $this->inventory->getBranchBalance($stock, $second)->quantity);
        $movement = DB::table('inventory_movements')->where('movement_type', 'purchase')->latest('id')->first();
        $this->assertSame($second->id, (int) json_decode($movement->metadata, true)['store_branch_id']);

        $adjustments = app(CoreMarketInventoryAdjustmentService::class);
        $document = $adjustments->createAdjustmentDocument([
            'adjustment_type' => 'correction',
            'branch_id' => $second->id,
            'reason' => 'Branch correction',
            'items' => [['product_stock_id' => $stock->id, 'quantity_change' => 1]],
        ], $actor);
        $adjustments->submitForApproval($document, $actor);
        $adjustments->approve($document->fresh(), $actor);
        $adjustments->post($document->fresh(), $actor);

        $this->assertSame(3.0, (float) $this->inventory->getBranchBalance($stock, $second)->quantity);
        $this->assertSame(3.0, (float) $stock->fresh()->qty);
    }

    public function test_web_pos_uses_default_branch_and_blocks_another_branch_aggregate(): void
    {
        $cashier = $this->staff();
        app(\App\Services\CoreMarketBranchService::class)->assignStaff($cashier, [$this->main->id], $this->main->id);
        $cashbox = app(CashboxService::class)->createCashbox([
            'name' => 'Branch POS',
            'code' => 'BR-POS-'.uniqid(),
            'currency' => 'USD',
            'status' => 'active',
        ]);
        app(CashboxService::class)->openShift($cashbox, $cashier, 0);
        [, $stock] = $this->productWithStock(0, $cashier);
        $other = $this->branch('OTHER');
        $this->inventory->increaseBranchStock($stock, $this->main, 1, 'seed');
        $this->inventory->increaseBranchStock($stock, $other, 9, 'seed');

        $order = app(WebPosService::class)->createPosOrder(
            [['product_stock_id' => $stock->id, 'quantity' => 1]],
            ['payment_type' => 'cash', 'paid_amount' => 20],
            $cashier,
            'branch-pos-'.uniqid()
        );
        $this->assertSame(0.0, (float) $this->inventory->getBranchBalance($stock, $this->main)->quantity);
        $this->assertSame($this->main->id, (int) $order->pos_metadata['store_branch_id']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Insufficient stock');
        app(WebPosService::class)->createPosOrder(
            [['product_stock_id' => $stock->id, 'quantity' => 1]],
            ['payment_type' => 'cash', 'paid_amount' => 20],
            $cashier,
            'branch-pos-blocked-'.uniqid()
        );
    }

    public function test_staff_visibility_is_limited_to_assigned_branch(): void
    {
        $warehouse = $this->staff();
        $other = $this->branch('HIDDEN');
        app(\App\Services\CoreMarketBranchService::class)->assignStaff($warehouse, [$this->main->id], $this->main->id);

        $this->assertTrue($this->inventory->visibleBranches($warehouse)->contains('id', $this->main->id));
        $this->assertFalse($this->inventory->visibleBranches($warehouse)->contains('id', $other->id));
        $this->expectException(DomainException::class);
        $this->inventory->resolveBranchForOperation($other->id, $warehouse);
    }

    private function productWithStock(float $qty, ?User $owner = null): array
    {
        $product = Product::query()->create([
            'name' => 'Branch Product '.uniqid(),
            'user_id' => $owner?->id ?? $this->admin()->id,
            'category_id' => DB::table('categories')->value('id') ?: 1,
            'unit_price' => 20,
            'purchase_price' => 5,
            'current_stock' => $qty,
            'slug' => 'branch-product-'.uniqid(),
            'published' => 1,
            'approved' => 1,
        ]);
        $stock = ProductStock::query()->create([
            'product_id' => $product->id,
            'variant' => '',
            'sku' => 'BR-'.uniqid(),
            'barcode' => 'BR-BAR-'.uniqid(),
            'price' => 20,
            'qty' => $qty,
        ]);

        return [$product, $stock];
    }

    private function branch(string $prefix): StoreBranch
    {
        return StoreBranch::query()->create([
            'name' => "{$prefix} Branch",
            'code' => $prefix.'-'.uniqid(),
            'is_active' => true,
        ]);
    }

    private function admin(): User
    {
        return User::query()->where('user_type', 'admin')->firstOrFail();
    }

    private function staff(): User
    {
        return User::query()->create([
            'name' => 'Branch Staff',
            'email' => 'branch-staff-'.uniqid().'@example.test',
            'password' => bcrypt('Temporary123!'),
            'user_type' => 'staff',
            'banned' => 0,
        ]);
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
