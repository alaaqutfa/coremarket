<?php

namespace Tests\Feature;

use App\Services\ProductIdentityLookupService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductIdentityLookupServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_product_and_variant_barcodes_and_skus_are_lookupable(): void
    {
        [$simpleProductId, $simpleStockId] = $this->makeProduct('PID-PRODUCT-' . uniqid(), 'SKU-SIMPLE-' . uniqid(), null, '');
        [$variantProductId, $variantStockId] = $this->makeProduct(null, 'SKU-VARIANT-' . uniqid(), 'PID-VARIANT-' . uniqid(), 'Red');
        $service = app(ProductIdentityLookupService::class);

        $productResult = $service->find((string) DB::table('products')->where('id', $simpleProductId)->value('barcode'));
        $variantResult = $service->find((string) DB::table('product_stocks')->where('id', $variantStockId)->value('barcode'));
        $skuResult = $service->find((string) DB::table('product_stocks')->where('id', $simpleStockId)->value('sku'));

        $this->assertSame($simpleProductId, $productResult['product']->id);
        $this->assertSame($simpleStockId, $productResult['product_stock']->id);
        $this->assertSame('product_barcode', $productResult['matched_by']);
        $this->assertSame($variantProductId, $variantResult['product']->id);
        $this->assertSame($variantStockId, $variantResult['product_stock']->id);
        $this->assertSame('variant_barcode', $variantResult['matched_by']);
        $this->assertSame('sku', $skuResult['matched_by']);
    }

    public function test_duplicate_product_and_variant_barcodes_are_rejected(): void
    {
        [$productId] = $this->makeProduct('PID-DUPLICATE-PRODUCT', 'SKU-DUPLICATE-PRODUCT', null, '');
        $this->makeProduct(null, 'SKU-DUPLICATE-VARIANT', 'PID-DUPLICATE-VARIANT', 'Blue');
        $service = app(ProductIdentityLookupService::class);

        $productErrors = $service->validationErrors('PID-DUPLICATE-PRODUCT', [], null);
        $variantErrors = $service->validationErrors(null, [[
            'barcode' => 'PID-DUPLICATE-VARIANT',
            'barcode_key' => 'barcode_Blue',
            'sku' => 'SKU-NEW',
            'sku_key' => 'sku_Blue',
        ]], null);
        $ownProductErrors = $service->validationErrors('PID-DUPLICATE-PRODUCT', [], $productId);
        $crossIdentityErrors = $service->validationErrors(null, [[
            'barcode' => 'PID-DUPLICATE-PRODUCT',
            'barcode_key' => 'barcode_Red',
            'sku' => 'SKU-DUPLICATE-PRODUCT',
            'sku_key' => 'sku_Red',
        ]], null);

        $this->assertArrayHasKey('barcode', $productErrors);
        $this->assertArrayHasKey('barcode_Blue', $variantErrors);
        $this->assertSame([], $ownProductErrors);
        $this->assertArrayHasKey('barcode_Red', $crossIdentityErrors);
        $this->assertArrayHasKey('sku_Red', $crossIdentityErrors);
    }

    public function test_missing_barcodes_and_read_only_audit_do_not_break_product_identity(): void
    {
        $service = app(ProductIdentityLookupService::class);

        $this->assertNull($service->find(''));
        $this->assertSame([], $service->validationErrors(null, [['barcode' => null, 'sku' => null]], null));
        $this->assertSame(0, Artisan::call('coremarket:stock-identity-audit'));
        $this->assertStringContainsString('read-only', Artisan::output());
    }

    private function makeProduct(?string $productBarcode, string $sku, ?string $variantBarcode, string $variant): array
    {
        $this->assertTrue(Schema::hasColumn('product_stocks', 'barcode'));
        $now = now();
        $productId = DB::table('products')->insertGetId([
            'name' => 'Product Identity Test ' . uniqid(),
            'user_id' => 1,
            'category_id' => 1,
            'unit_price' => 20,
            'purchase_price' => 10,
            'current_stock' => 5,
            'barcode' => $productBarcode,
            'slug' => 'product-identity-' . uniqid(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $stockId = DB::table('product_stocks')->insertGetId([
            'product_id' => $productId,
            'variant' => $variant,
            'sku' => $sku,
            'barcode' => $variantBarcode,
            'price' => 20,
            'qty' => 5,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$productId, $stockId];
    }
}
