<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\Supplier;
use App\Models\User;
use App\Services\OperationsPdfService;
use App\Services\PurchaseReceivingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\InteractsWithCoreMarketTestSchema;
use Tests\TestCase;

class PurchasePdfTest extends TestCase
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

    public function test_purchase_order_and_receipt_pdf_use_saved_pricing_and_tax_data(): void
    {
        DB::beginTransaction();
        try {
            [$order, $receipt] = $this->purchase();
            $data = app(OperationsPdfService::class)->purchaseDocument($order);
            $receiptData = app(OperationsPdfService::class)->purchaseDocument($order, $receipt);

            $this->assertSame(12.34, $data['rows']->first()['unit_cost']);
            $this->assertSame(20.0, $data['rows']->first()['regular_price']);
            $this->assertSame(18.0, $data['rows']->first()['sale_price']);
            $this->assertSame(11.0, $data['rows']->first()['tax_rate']);
            $this->assertSame((float) $order->total_amount, $data['totals']['total']);
            $this->assertSame(1.0, $receiptData['rows']->first()['quantity']);
            $this->assertSame('12.34 USD', coremarket_money($data['rows']->first()['unit_cost'], 'USD'));

            $user = $this->user(['purchase_orders.view']);
            $this->actingAs($user)->get(route('operations.purchase-orders.show', $order))
                ->assertOk()
                ->assertSee(route('operations.purchase-orders.pdf', $order), false);
            $this->actingAs($user)->get(route('operations.purchase-receipts.show', $receipt))
                ->assertOk()
                ->assertSee(route('operations.purchase-receipts.pdf', $receipt), false);
            $this->actingAs($user)->get(route('operations.purchase-orders.pdf', $order))
                ->assertOk()
                ->assertHeader('content-type', 'application/pdf');
            $this->actingAs($user)->get(route('operations.purchase-receipts.pdf', $receipt))
                ->assertOk()
                ->assertHeader('content-type', 'application/pdf');
        } finally {
            DB::rollBack();
        }
    }

    public function test_missing_logo_does_not_break_purchase_pdf(): void
    {
        DB::beginTransaction();
        try {
            [$order] = $this->purchase();
            $setting = BusinessSetting::query()
                ->where('type', 'header_logo')
                ->whereNull('lang')
                ->first() ?: new BusinessSetting();
            $setting->forceFill([
                'type' => 'header_logo',
                'lang' => null,
                'value' => '999999999',
            ])->save();
            Cache::forget('business_settings');

            $data = app(OperationsPdfService::class)->purchaseDocument($order);
            $this->assertNull($data['branding']['logo_path']);

            $this->actingAs($this->user(['purchase_orders.view']))
                ->get(route('operations.purchase-orders.pdf', $order))
                ->assertOk()
                ->assertHeader('content-type', 'application/pdf');
        } finally {
            DB::rollBack();
            Cache::forget('business_settings');
        }
    }

    private function purchase(): array
    {
        $now = now();
        $supplier = Supplier::query()->create([
            'name' => 'PDF Supplier '.uniqid(),
            'email' => 'supplier@example.test',
            'is_active' => true,
        ]);
        $productId = DB::table('products')->insertGetId([
            'name' => 'PDF Purchase Product',
            'user_id' => 1,
            'category_id' => 1,
            'unit_price' => 20,
            'purchase_price' => 12.34,
            'current_stock' => 2,
            'slug' => 'pdf-purchase-'.uniqid(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $stockId = DB::table('product_stocks')->insertGetId([
            'product_id' => $productId,
            'variant' => '',
            'sku' => 'PDF-'.uniqid(),
            'barcode' => 'PDF-BAR-'.uniqid(),
            'price' => 20,
            'qty' => 2,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $receiving = app(PurchaseReceivingService::class);
        $order = $receiving->createPurchaseOrder([
            'supplier_id' => $supplier->id,
            'status' => 'ordered',
            'ordered_at' => $now,
            'currency' => 'USD',
            'metadata' => ['supplier_invoice_number' => 'SUP-INV-54'],
        ], [[
            'product_id' => $productId,
            'product_stock_id' => $stockId,
            'quantity_ordered' => 2,
            'unit_cost' => 12.344,
            'regular_price' => 20,
            'sale_price' => 18,
            'tax_enabled' => true,
            'tax_rate' => 11,
        ]]);
        $receipt = $receiving->receive($order, [[
            'purchase_order_item_id' => $order->items->first()->id,
            'quantity_received' => 1,
            'unit_cost' => 12.344,
        ]], ['receipt_key' => 'pdf-receipt-'.uniqid()]);

        return [$order->fresh(), $receipt->fresh()];
    }

    private function user(array $permissions): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::query()->firstOrCreate(['name' => 'Purchase PDF '.uniqid(), 'guard_name' => 'web']);
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::query()->firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]));
        }
        $user = new User();
        $user->forceFill([
            'name' => 'Purchase PDF QA',
            'email' => uniqid('purchase-pdf').'@example.test',
            'password' => bcrypt('Temporary123!'),
            'user_type' => 'staff',
            'email_verified_at' => now(),
        ])->save();
        $user->assignRole($role);

        return $user;
    }
}
