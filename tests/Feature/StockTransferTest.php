<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StoreBranch;
use App\Models\User;
use App\Services\CoreMarketBranchInventoryService;
use App\Services\CoreMarketInventoryPolicyService;
use App\Services\CoreMarketStockTransferService;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StockTransferTest extends TestCase
{
    use DatabaseTransactions;

    private CoreMarketBranchInventoryService $inventory;

    private CoreMarketStockTransferService $transfers;

    private User $admin;

    private StoreBranch $from;

    private StoreBranch $to;

    private ProductStock $stock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('coremarket_testing', DB::getDatabaseName());
        $this->assertTrue(Schema::hasTable('stock_transfers'));
        $this->setting(CoreMarketBranchInventoryService::SETTING, true);
        $this->setting(CoreMarketInventoryPolicyService::NEGATIVE_STOCK_SETTING, false);
        $this->inventory = app(CoreMarketBranchInventoryService::class);
        $this->transfers = app(CoreMarketStockTransferService::class);
        $this->admin = User::query()->where('user_type', 'admin')->firstOrFail();
        $this->from = $this->inventory->defaultBranch();
        $this->to = StoreBranch::query()->create([
            'name' => 'Transfer Destination',
            'code' => 'TRF-'.uniqid(),
            'is_active' => true,
        ]);
        $this->stock = $this->productStock();
        $this->inventory->increaseBranchStock($this->stock, $this->from, 10, 'transfer test seed');
    }

    public function test_transfer_draft_and_approval_do_not_change_stock(): void
    {
        $transfer = $this->createTransfer();
        $this->assertSame('draft', $transfer->status);
        $this->assertBalances(10, 0, 10);

        $this->transfers->submitForApproval($transfer, $this->admin);
        $this->transfers->approve($transfer->fresh(), $this->admin);
        $this->assertBalances(10, 0, 10);
    }

    public function test_ship_and_receive_create_movements_and_restore_aggregate(): void
    {
        $transfer = $this->approvedTransfer();
        $shipped = $this->transfers->ship($transfer, $this->admin);
        $this->assertSame('shipped', $shipped->status);
        $this->assertBalances(7, 0, 7);
        $this->assertDatabaseHas('inventory_movements', [
            'reference_id' => $transfer->items->first()->id,
            'movement_type' => 'transfer_out',
            'direction' => 'out',
        ]);

        $received = $this->transfers->receive($shipped, $this->admin);
        $this->assertSame('received', $received->status);
        $this->assertBalances(7, 3, 10);
        $this->assertDatabaseHas('inventory_movements', [
            'reference_id' => $transfer->items->first()->id,
            'movement_type' => 'transfer_in',
            'direction' => 'in',
        ]);
    }

    public function test_ship_and_receive_are_idempotent(): void
    {
        $transfer = $this->approvedTransfer();
        $firstShip = $this->transfers->ship($transfer, $this->admin);
        $this->transfers->ship($firstShip, $this->admin);
        $firstReceive = $this->transfers->receive($firstShip->fresh(), $this->admin);
        $this->transfers->receive($firstReceive, $this->admin);

        $this->assertBalances(7, 3, 10);
        $this->assertSame(1, DB::table('inventory_movements')->where('movement_type', 'transfer_out')->count());
        $this->assertSame(1, DB::table('inventory_movements')->where('movement_type', 'transfer_in')->count());
    }

    public function test_same_branch_and_insufficient_source_are_rejected(): void
    {
        try {
            $this->transfers->createTransfer([
                'from_branch_id' => $this->from->id,
                'to_branch_id' => $this->from->id,
                'items' => [['product_stock_id' => $this->stock->id, 'quantity' => 1]],
            ], $this->admin);
            $this->fail('Expected same-branch transfer rejection.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('different', $exception->getMessage());
        }

        $transfer = $this->approvedTransfer(11);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Insufficient stock');
        $this->transfers->ship($transfer, $this->admin);
    }

    public function test_rejected_or_cancelled_transfer_cannot_ship(): void
    {
        $rejected = $this->createTransfer();
        $this->transfers->submitForApproval($rejected, $this->admin);
        $this->transfers->reject($rejected->fresh(), $this->admin, 'Not approved');
        try {
            $this->transfers->ship($rejected->fresh(), $this->admin);
            $this->fail('Expected rejected transfer to be blocked.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('approved', $exception->getMessage());
        }

        $cancelled = $this->transfers->cancel($this->createTransfer(), $this->admin);
        $this->expectException(DomainException::class);
        $this->transfers->ship($cancelled, $this->admin);
    }

    private function createTransfer(float $quantity = 3)
    {
        return $this->transfers->createTransfer([
            'from_branch_id' => $this->from->id,
            'to_branch_id' => $this->to->id,
            'idempotency_key' => 'transfer-'.uniqid(),
            'items' => [[
                'product_stock_id' => $this->stock->id,
                'quantity' => $quantity,
            ]],
        ], $this->admin);
    }

    private function approvedTransfer(float $quantity = 3)
    {
        $transfer = $this->createTransfer($quantity);
        $this->transfers->submitForApproval($transfer, $this->admin);

        return $this->transfers->approve($transfer->fresh(), $this->admin);
    }

    private function assertBalances(float $from, float $to, float $aggregate): void
    {
        $this->assertSame($from, (float) $this->inventory->getBranchBalance($this->stock, $this->from)->quantity);
        $this->assertSame($to, (float) $this->inventory->getBranchBalance($this->stock, $this->to)->quantity);
        $this->assertSame($aggregate, (float) $this->stock->fresh()->qty);
    }

    private function productStock(): ProductStock
    {
        $product = Product::query()->create([
            'name' => 'Transfer Product '.uniqid(),
            'user_id' => $this->admin->id,
            'category_id' => DB::table('categories')->value('id') ?: 1,
            'unit_price' => 20,
            'purchase_price' => 5,
            'current_stock' => 0,
            'slug' => 'transfer-product-'.uniqid(),
            'published' => 1,
            'approved' => 1,
        ]);

        return ProductStock::query()->create([
            'product_id' => $product->id,
            'variant' => '',
            'sku' => 'TRF-'.uniqid(),
            'price' => 20,
            'qty' => 0,
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
