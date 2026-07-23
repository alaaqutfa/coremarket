<?php

namespace App\Services;

use AizPackages\CombinationGenerate\Services\CombinationService;
use App\Models\ProductStock;
use App\Utility\ProductUtility;

class ProductStockService
{
    private CoreMarketInventoryPolicyService $inventoryPolicy;

    public function __construct(?CoreMarketInventoryPolicyService $inventoryPolicy = null)
    {
        $this->inventoryPolicy = $inventoryPolicy ?? app(CoreMarketInventoryPolicyService::class);
    }

    public function store(array $data, $product, array $preservedQuantities = [])
    {
        $data = $this->inventoryPolicy->validateProductStockInput($data);
        $collection = collect($data);

        $options = ProductUtility::get_attribute_options($collection);

        //Generates the combinations of customer choice options
        $combinations = (new CombinationService())->generate_combination($options);

        $variant = '';
        if (count($combinations) > 0) {
            $product->variant_product = 1;
            $product->save();
            foreach ($combinations as $key => $combination) {
                $str = ProductUtility::get_combination_string($combination, $collection);
                $product_stock = new ProductStock();
                $product_stock->product_id = $product->id;
                $product_stock->variant = $str;
                $product_stock->wholesale_price = $product->wholesale_price;
                $product_stock->price = request()['price_' . str_replace('.', '_', $str)];
                $product_stock->sku = request()['sku_' . str_replace('.', '_', $str)];
                $product_stock->barcode = request()['barcode_' . str_replace('.', '_', $str)] ?? null;
                $requestedQty = request()['qty_' . str_replace('.', '_', $str)] ?? 0;
                $product_stock->qty = array_key_exists($str, $preservedQuantities)
                    ? $preservedQuantities[$str]
                    : ($this->inventoryPolicy->canCreateOpeningStock() ? $requestedQty : 0);
                $product_stock->image = request()['img_' . str_replace('.', '_', $str)];
                $product_stock->save();
            }
        } else {
            unset($collection['colors_active'], $collection['colors'], $collection['choice_no']);
            $qty = array_key_exists('', $preservedQuantities)
                ? $preservedQuantities['']
                : ($collection['current_stock'] ?? 0);
            $price = $collection['unit_price'];
            unset($collection['current_stock']);

            $data = $collection->merge(compact('variant', 'qty', 'price'))->toArray();

            ProductStock::create($data);
        }
    }

    public function product_duplicate_store($product_stocks , $product_new)
    {
        foreach ($product_stocks as $key => $stock) {
            $product_stock              = new ProductStock;
            $product_stock->product_id  = $product_new->id;
            $product_stock->variant     = $stock->variant;
            $product_stock->price       = $stock->price;
            $product_stock->sku         = null;
            $product_stock->barcode     = null;
            $product_stock->qty         = $this->inventoryPolicy->canCreateOpeningStock() ? $stock->qty : 0;
            $product_stock->save();
        }
    }
}
