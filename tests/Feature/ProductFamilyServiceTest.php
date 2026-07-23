<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductFamily;
use App\Services\CoreMarketProductClassificationService;
use App\Services\InventoryProService;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductFamilyServiceTest extends TestCase
{
    use DatabaseTransactions;

    private CoreMarketProductClassificationService $classification;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('coremarket_testing', DB::getDatabaseName());
        $this->assertTrue(Schema::hasTable('product_families'));
        $this->assertTrue(Schema::hasColumn('products', 'product_family_id'));
        $this->assertTrue(Schema::hasColumn('products', 'product_sub_family_id'));
        $this->classification = app(CoreMarketProductClassificationService::class);
    }

    public function test_family_and_sub_family_can_be_created_and_assigned_without_changing_storefront_category(): void
    {
        $family = $this->family('FOOD');
        $subFamily = $this->subFamily($family, 'SNACKS');
        $product = $this->product(categoryId: 1);

        $assigned = $this->classification->assignFamily($product, $family, $subFamily);
        $snapshot = $this->classification->classificationSnapshot($assigned);

        $this->assertSame(1, (int) $assigned->category_id);
        $this->assertSame($family->id, $assigned->product_family_id);
        $this->assertSame($subFamily->id, $assigned->product_sub_family_id);
        $this->assertStringStartsWith('FOOD-', $snapshot['family_code']);
        $this->assertStringStartsWith('SNACKS-', $snapshot['sub_family_code']);
    }

    public function test_sub_family_from_another_family_is_rejected(): void
    {
        $food = $this->family('FOOD');
        $electronics = $this->family('ELEC');
        $phones = $this->subFamily($electronics, 'PHONES');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('does not belong');
        $this->classification->assignFamily($this->product(), $food, $phones);
    }

    public function test_inactive_family_or_sub_family_cannot_be_assigned(): void
    {
        $family = $this->family('INACTIVE', false);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('unavailable');
        $this->classification->assignFamily($this->product(), $family, null);
    }

    public function test_inactive_sub_family_cannot_be_assigned(): void
    {
        $family = $this->family('ACTIVE');
        $subFamily = $this->subFamily($family, 'INACTIVE-SUB');
        $subFamily->update(['is_active' => false]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('does not belong');
        $this->classification->assignFamily($this->product(), $family, $subFamily);
    }

    public function test_family_queries_and_stock_filters_return_only_matching_products(): void
    {
        $family = $this->family('GROCERY');
        $subFamily = $this->subFamily($family, 'DRINKS');
        $matching = $this->product('Matching Product');
        $other = $this->product('Other Product');
        $this->classification->assignFamily($matching, $family, $subFamily);
        $this->stock($matching, 'FAMILY-MATCH');
        $this->stock($other, 'FAMILY-OTHER');

        $this->assertSame([$matching->id], $this->classification->productsByFamily($family->id)->pluck('id')->all());
        $this->assertSame([$matching->id], $this->classification->productsBySubFamily($subFamily->id)->pluck('id')->all());

        $familyRows = app(InventoryProService::class)->stockRows(['product_family_id' => $family->id]);
        $subFamilyRows = app(InventoryProService::class)->stockRows(['product_sub_family_id' => $subFamily->id]);

        $this->assertSame([$matching->id], $familyRows->pluck('product.id')->all());
        $this->assertSame([$matching->id], $subFamilyRows->pluck('product.id')->all());
    }

    private function family(string $code, bool $active = true): ProductFamily
    {
        return ProductFamily::query()->create([
            'name' => ucfirst(strtolower($code)),
            'code' => $code . '-' . strtoupper(uniqid()),
            'level' => 'family',
            'is_active' => $active,
        ]);
    }

    private function subFamily(ProductFamily $family, string $code): ProductFamily
    {
        return ProductFamily::query()->create([
            'parent_id' => $family->id,
            'name' => ucfirst(strtolower($code)),
            'code' => $code . '-' . strtoupper(uniqid()),
            'level' => 'sub_family',
            'is_active' => true,
        ]);
    }

    private function product(string $name = 'Classified Product', int $categoryId = 1): Product
    {
        return Product::query()->create([
            'name' => $name . ' ' . uniqid(),
            'user_id' => 1,
            'category_id' => $categoryId,
            'unit_price' => 10,
            'purchase_price' => 5,
            'current_stock' => 10,
            'slug' => 'classified-product-' . uniqid(),
        ]);
    }

    private function stock(Product $product, string $sku): void
    {
        DB::table('product_stocks')->insert([
            'product_id' => $product->id,
            'variant' => '',
            'sku' => $sku . '-' . uniqid(),
            'price' => 10,
            'qty' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
