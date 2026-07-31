<?php

namespace App\Services\Demo;

use App\Models\InventoryAdjustmentDocument;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\ProductBranchPrice;
use App\Models\ProductSerialUnit;
use App\Models\ProductStock;
use App\Models\ProductWarrantyPolicy;
use App\Models\SalesReturn;
use App\Models\StoreBranch;
use App\Models\User;
use App\Services\CoreMarketBranchInventoryService;
use App\Services\CoreMarketCustomerCreditService;
use App\Services\CoreMarketCustomerReceivableService;
use App\Services\CoreMarketInventoryAdjustmentService;
use App\Services\CoreMarketSalesReturnRefundService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CoreMarketPilotDemoPreparer
{
    public const BLOCKED_DATABASES = [
        'coremarket_runtime',
        'coremarket_testing',
        'coremarket',
        'core_market',
        'syrian_souq',
    ];

    private const PRODUCT_BARCODE = '629999900080';
    private const STOCK_SKU = 'PILOT-PHONE-BLACK';
    private const ORDER_KEY = 'demo80:pay-on-account:phone';

    public function __construct(
        private CoreMarketInventoryAdjustmentService $adjustments,
        private CoreMarketBranchInventoryService $branchInventory,
        private CoreMarketCustomerCreditService $credit,
        private CoreMarketCustomerReceivableService $receivables,
        private CoreMarketSalesReturnRefundService $refunds
    ) {
    }

    public function plan(bool $apply = false, bool $confirmed = false): array
    {
        $database = (string) DB::connection()->getDatabaseName();
        $safe = str_ends_with(strtolower($database), '_demo')
            && ! in_array(strtolower($database), self::BLOCKED_DATABASES, true);
        $records = [
            'branch_price' => 0,
            'credit_profiles' => 0,
            'ledger_entries' => 0,
            'return_refunds' => 0,
            'serial_units' => 0,
            'warranty_policies' => 0,
        ];
        if ($safe) {
            $records = [
                'branch_price' => DB::table('product_branch_prices')->count(),
                'credit_profiles' => DB::table('customer_account_profiles')->count(),
                'ledger_entries' => DB::table('customer_ledger_entries')->count(),
                'return_refunds' => DB::table('sales_return_refunds')->count(),
                'serial_units' => DB::table('product_serial_units')->count(),
                'warranty_policies' => DB::table('product_warranty_policies')->count(),
            ];
        }

        return [
            'database' => $database,
            'apply' => $apply,
            'confirmed' => $confirmed,
            'safe' => $safe,
            'records' => $records,
        ];
    }

    public function execute(array $plan): array
    {
        if (! ($plan['safe'] ?? false) || ! ($plan['apply'] ?? false) || ! ($plan['confirmed'] ?? false)) {
            throw new RuntimeException('Pilot demo preparation was refused by the database safety guard.');
        }
        if ((string) DB::connection()->getDatabaseName() !== (string) $plan['database']) {
            throw new RuntimeException('The active database changed after the pilot demo safety check.');
        }

        return DB::transaction(function () {
            $actor = User::query()->where('email', 'admin@coremarket.demo')->firstOrFail();
            $customer = User::query()->where('email', 'customer2@coremarket.demo')->firstOrFail();
            $branch = StoreBranch::query()->where('is_default', true)->first()
                ?: StoreBranch::query()->firstOrFail();

            [$productId, $stock] = $this->ensureSerializedProduct($actor, $branch);
            $this->ensureBranchPrice($productId, $stock, $branch);
            $this->ensureWarrantyAndSerials($productId, $stock, $branch);
            $this->credit->updateProfile($customer, [
                'is_credit_allowed' => true,
                'credit_limit' => 5000,
                'payment_terms_days' => 30,
                'account_status' => 'active',
                'default_payment_method' => 'pay_on_account',
                'notes' => 'CoreMarket Pilot B2B customer account.',
            ], $actor);

            $order = $this->ensureAccountOrder($actor, $customer, $branch, $productId, $stock);
            $this->receivables->createInvoiceEntryFromOrder($order, $actor, [
                'idempotency_key' => 'order:'.$order->id.':pay_on_account',
                'source' => 'demo80_pilot_prepare',
                'payment_method' => 'pay_on_account',
                'branch_id' => $branch->id,
            ]);
            $this->ensureReturnCredit($customer, $actor);

            return $this->plan(true, true)['records'];
        });
    }

    private function ensureSerializedProduct(User $actor, StoreBranch $branch): array
    {
        $productId = DB::table('products')->where('barcode', self::PRODUCT_BARCODE)->value('id');
        if (! $productId) {
            $productId = DB::table('products')->insertGetId([
                'name' => 'CoreMarket Pilot Smartphone',
                'added_by' => 'admin',
                'user_id' => $actor->id,
                'category_id' => DB::table('categories')->value('id'),
                'brand_id' => DB::table('brands')->value('id'),
                'photos' => '',
                'tags' => 'demo,pilot,smartphone',
                'description' => 'Serialized Pilot product for IMEI and warranty demonstrations.',
                'unit_price' => 699,
                'wholesale_price' => 675,
                'purchase_price' => 500,
                'variant_product' => 1,
                'attributes' => '[]',
                'choice_options' => '[]',
                'colors' => '[]',
                'variations' => '[]',
                'published' => 1,
                'approved' => 1,
                'stock_visibility_state' => 'quantity',
                'cash_on_delivery' => 1,
                'current_stock' => 0,
                'unit' => 'pc',
                'min_qty' => 1,
                'low_stock_quantity' => 1,
                'discount' => 0,
                'discount_type' => 'flat',
                'tax' => 0,
                'tax_type' => 'flat',
                'shipping_type' => 'flat_rate',
                'shipping_cost' => 0,
                'meta_title' => 'CoreMarket Pilot Smartphone',
                'meta_description' => 'Synthetic serialized Pilot product.',
                'slug' => 'coremarket-pilot-smartphone',
                'digital' => 0,
                'barcode' => self::PRODUCT_BARCODE,
                'external_link' => '',
                'external_link_btn' => '',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('product_categories')->updateOrInsert([
                'product_id' => $productId,
                'category_id' => DB::table('products')->where('id', $productId)->value('category_id'),
            ], []);
        }

        $stock = ProductStock::query()->firstOrCreate(
            ['sku' => self::STOCK_SKU],
            [
                'product_id' => $productId,
                'variant' => 'Color: Black / Storage: 128GB',
                'barcode' => self::PRODUCT_BARCODE,
                'serial_tracking_enabled' => true,
                'imei_tracking_enabled' => true,
                'price' => 699,
                'qty' => 0,
            ]
        );
        $stock->forceFill([
            'serial_tracking_enabled' => true,
            'imei_tracking_enabled' => true,
        ])->save();

        if ((float) $this->branchInventory->getBranchBalance($stock, $branch)->quantity === 0.0) {
            $document = $this->adjustments->createOpeningStockDocument([
                'branch_id' => $branch->id,
                'idempotency_key' => 'demo80:opening-stock:pilot-phone',
                'reason' => 'Pilot serialized product opening stock',
                'notes' => 'Prepared by coremarket:demo-pilot-prepare.',
                'items' => [[
                    'product_stock_id' => $stock->id,
                    'quantity_change' => 2,
                    'unit_cost' => 500,
                ]],
            ], $actor);
            $document = $this->advanceAdjustment($document, $actor);
            if ($document->status !== 'posted') {
                throw new RuntimeException('Pilot opening stock could not be posted.');
            }
        }

        return [$productId, $stock->fresh()];
    }

    private function advanceAdjustment(InventoryAdjustmentDocument $document, User $actor): InventoryAdjustmentDocument
    {
        if ($document->status === 'draft') {
            $document = $this->adjustments->submitForApproval($document, $actor);
        }
        if ($document->status === 'pending_approval') {
            $document = $this->adjustments->approve($document, $actor);
        }
        if ($document->status === 'approved') {
            $document = $this->adjustments->post($document, $actor);
        }

        return $document;
    }

    private function ensureBranchPrice(int $productId, ProductStock $stock, StoreBranch $branch): void
    {
        ProductBranchPrice::query()->updateOrCreate(
            ['store_branch_id' => $branch->id, 'product_stock_id' => $stock->id],
            [
                'product_id' => $productId,
                'price' => 679,
                'compare_at_price' => 699,
                'is_active' => true,
                'metadata' => ['demo_pilot' => true],
            ]
        );
    }

    private function ensureWarrantyAndSerials(int $productId, ProductStock $stock, StoreBranch $branch): void
    {
        ProductWarrantyPolicy::query()->updateOrCreate(
            ['product_stock_id' => $stock->id],
            [
                'product_id' => $productId,
                'name' => 'Pilot Smartphone 12-Month Warranty',
                'warranty_months' => 12,
                'coverage_notes' => 'Demo manufacturer warranty; physical damage excluded.',
                'status' => 'active',
                'metadata' => ['demo_pilot' => true],
            ]
        );

        foreach ([1, 2] as $number) {
            ProductSerialUnit::query()->updateOrCreate(
                ['serial_number' => 'CMPILOT-SN-000'.$number],
                [
                    'product_id' => $productId,
                    'product_stock_id' => $stock->id,
                    'store_branch_id' => $branch->id,
                    'imei_1' => '35999999000000'.$number,
                    'barcode' => 'CMPILOT000'.$number,
                    'status' => $number === 1 ? 'sold' : 'in_stock',
                    'metadata' => ['demo_pilot' => true, 'source' => 'opening_stock'],
                ]
            );
        }
    }

    private function ensureAccountOrder(
        User $actor,
        User $customer,
        StoreBranch $branch,
        int $productId,
        ProductStock $stock
    ): Order {
        $order = Order::query()->where('pos_request_key', self::ORDER_KEY)->first();
        if ($order) {
            return $order;
        }

        $orderId = DB::table('orders')->insertGetId([
            'user_id' => $customer->id,
            'seller_id' => $actor->id,
            'shipping_type' => 'pos',
            'order_from' => 'pos',
            'delivery_status' => 'delivered',
            'payment_type' => 'pay_on_account',
            'payment_status' => 'unpaid',
            'payment_details' => json_encode(['method' => 'pay_on_account']),
            'grand_total' => 679,
            'coupon_discount' => 0,
            'code' => 'DEMO-ACCOUNT-00001',
            'date' => now()->timestamp,
            'paid_amount' => 0,
            'change_amount' => 0,
            'pos_receipt_number' => 'DEMO-ACCOUNT-00001',
            'pos_request_key' => self::ORDER_KEY,
            'pos_metadata' => json_encode(['demo_pilot' => true, 'branch_id' => $branch->id]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $detailId = DB::table('order_details')->insertGetId([
            'order_id' => $orderId,
            'seller_id' => $actor->id,
            'product_id' => $productId,
            'variation' => $stock->variant,
            'price' => 679,
            'cost_price' => 500,
            'cost_source' => 'demo_pilot',
            'total_cost' => 500,
            'profit_amount' => 179,
            'profit_calculated_at' => now(),
            'tax' => 0,
            'shipping_cost' => 0,
            'quantity' => 1,
            'payment_status' => 'unpaid',
            'delivery_status' => 'delivered',
            'shipping_type' => 'pos',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->branchInventory->decreaseBranchStock(
            $stock,
            $branch,
            1,
            'sale',
            ['order_id' => $orderId, 'order_detail_id' => $detailId, 'demo_pilot' => true]
        );
        DB::table('inventory_movements')->insert([
            'product_id' => $productId,
            'product_stock_id' => $stock->id,
            'variant' => $stock->variant,
            'movement_type' => 'sale',
            'direction' => 'out',
            'quantity' => 1,
            'unit_cost' => 500,
            'total_cost' => 500,
            'reference_type' => OrderDetail::class,
            'reference_id' => $detailId,
            'order_id' => $orderId,
            'order_detail_id' => $detailId,
            'created_by' => $actor->id,
            'notes' => 'CoreMarket Pilot pay-on-account serialized sale.',
            'metadata' => json_encode(['store_branch_id' => $branch->id, 'demo_pilot' => true]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        ProductSerialUnit::query()->where('serial_number', 'CMPILOT-SN-0001')->update([
            'status' => 'sold',
            'order_id' => $orderId,
            'order_detail_id' => $detailId,
            'warranty_expires_at' => now()->addMonthsNoOverflow(12),
            'updated_at' => now(),
        ]);

        return Order::query()->findOrFail($orderId);
    }

    private function ensureReturnCredit(User $customer, User $actor): void
    {
        $return = SalesReturn::query()
            ->where('status', 'completed')
            ->whereHas('order', fn ($query) => $query->where('user_id', $customer->id))
            ->first();
        if ($return) {
            $this->refunds->creditCustomerAccount(
                $return,
                min(1, (float) $return->total_amount),
                $actor,
                'demo80:return-credit:'.$return->id,
                'Pilot customer account credit example.'
            );
        }
    }
}
