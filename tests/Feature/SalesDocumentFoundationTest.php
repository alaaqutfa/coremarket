<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\SalesReturn;
use App\Models\User;
use App\Services\CoreMarketDeliveryService;
use App\Services\CoreMarketDocumentTemplateService;
use App\Services\OperationsPdfService;
use Database\Seeders\DocumentTemplateSeeder;
use Database\Seeders\OperationsPermissionSeeder;
use Database\Seeders\StaffRolePresetSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SalesDocumentFoundationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('coremarket_testing', DB::getDatabaseName());
        $this->assertTrue(Schema::hasTable('document_templates'));
        $this->seed(OperationsPermissionSeeder::class);
        $this->seed(StaffRolePresetSeeder::class);
        $this->seed(DocumentTemplateSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_sales_document_defaults_are_idempotent_and_previewable(): void
    {
        $count = DB::table('document_templates')->count();
        $this->seed(DocumentTemplateSeeder::class);

        $this->assertSame(11, $count);
        $this->assertSame($count, DB::table('document_templates')->count());

        $templates = app(CoreMarketDocumentTemplateService::class);
        foreach (['sales_invoice', 'customer_statement', 'delivery_note', 'packing_slip'] as $type) {
            $this->assertSame($type, $templates->defaultTemplate($type)->template_type);
        }
        $this->assertSame('Sample Customer', $templates->renderPreviewData('sales_invoice')['party_name']);
    }

    public function test_sales_invoice_uses_stored_order_values_without_cost_or_other_price_lists(): void
    {
        $manager = $this->staff('sales-doc-manager-'.uniqid().'@example.test', 'owner_general_manager');
        [$order] = $this->order(customer: $this->customer('invoice-customer'));
        $data = app(OperationsPdfService::class)->salesInvoice($order);

        $this->assertSame('sales_invoice', $data['template']['template_type']);
        $this->assertSame(25.0, $data['rows']->first()['unit_price']);
        $this->assertSame(55.0, $data['totals']['total']);
        $this->assertSame(55.0, $data['totals']['paid']);
        $this->assertArrayNotHasKey('cost_price', $data['rows']->first());
        $this->assertArrayNotHasKey('profit_amount', $data['rows']->first());
        $this->assertArrayNotHasKey('price_list_id', $data['rows']->first());

        $this->actingAs($manager)
            ->get(route('operations.orders.invoice.pdf', $order))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function testCustomerStatementIsOperationalFilteredAndCustomerIsolated(): void
    {
        $accountant = $this->staff('statement-accountant-'.uniqid().'@example.test', 'accountant');
        $customer = $this->customer('statement-customer');
        $otherCustomer = $this->customer('other-customer');
        [$order] = $this->order($customer, 'CUSTOMER-ORDER', 'unpaid', 20);
        [$otherOrder] = $this->order($otherCustomer, 'OTHER-CUSTOMER-ORDER', 'unpaid', 0);
        $order->forceFill(['created_at' => '2026-07-10 10:00:00', 'updated_at' => '2026-07-10 10:00:00'])->save();
        $otherOrder->forceFill(['created_at' => '2026-07-10 11:00:00', 'updated_at' => '2026-07-10 11:00:00'])->save();
        SalesReturn::query()->create([
            'return_number' => 'RETURN-'.uniqid(),
            'order_id' => $order->id,
            'user_id' => $customer->id,
            'customer_id' => $customer->id,
            'status' => 'completed',
            'return_type' => 'customer_return',
            'total_amount' => 10,
            'completed_at' => '2026-07-12 10:00:00',
        ]);

        $data = app(OperationsPdfService::class)->customerStatement($customer, '2026-07-01', '2026-07-31');

        $this->assertTrue($data['isOperationalStatement']);
        $this->assertSame('customer_statement', $data['template']['template_type']);
        $this->assertSame(['CUSTOMER-ORDER'], $data['rows']->where('entry_type', 'order')->pluck('reference')->all());
        $this->assertNotContains('OTHER-CUSTOMER-ORDER', $data['rows']->pluck('reference')->all());
        $this->assertSame(25.0, $data['totals']['closingBalance']);

        $this->actingAs($accountant)
            ->get(route('operations.customers.statement.pdf', [
                'customer' => $customer,
                'date_from' => '2026-07-01',
                'date_to' => '2026-07-31',
            ]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_delivery_documents_hide_financial_details_and_are_assignment_scoped(): void
    {
        $driver = $this->staff('sales-doc-driver-'.uniqid().'@example.test', 'delivery_distribution');
        $otherDriver = $this->staff('sales-doc-other-driver-'.uniqid().'@example.test', 'delivery_distribution');
        [$assignedOrder] = $this->order(customer: $this->customer('delivery-customer'));
        [$otherOrder] = $this->order(customer: $this->customer('other-delivery-customer'));
        app(CoreMarketDeliveryService::class)->assignDeliveryUser($assignedOrder, $driver);
        app(CoreMarketDeliveryService::class)->assignDeliveryUser($otherOrder, $otherDriver);

        $data = app(OperationsPdfService::class)->deliveryDocument($assignedOrder);
        $this->assertSame('delivery_note', $data['template']['template_type']);
        $this->assertArrayNotHasKey('unit_price', $data['rows']->first());
        $this->assertArrayNotHasKey('cost_price', $data['rows']->first());
        $this->assertArrayNotHasKey('profit_amount', $data['rows']->first());

        $this->actingAs($driver)
            ->get(route('operations.orders.delivery-note.pdf', $assignedOrder))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->actingAs($driver)
            ->get(route('operations.orders.packing-slip.pdf', $otherOrder))
            ->assertForbidden();
    }

    public function test_order_alias_redirects_and_document_permissions_are_bounded(): void
    {
        $manager = $this->staff('route-manager-'.uniqid().'@example.test', 'owner_general_manager');
        $cashier = $this->staff('document-cashier-'.uniqid().'@example.test', 'cashier');
        $accountant = $this->staff('document-accountant-'.uniqid().'@example.test', 'accountant');
        $designer = $this->staff('document-designer-'.uniqid().'@example.test', 'designer_content');
        $customer = $this->customer('permission-customer');
        [$ownOrder] = $this->order($customer, cashier: $cashier);
        [$otherOrder] = $this->order($customer);

        $this->actingAs($manager)
            ->get(route('orders.index'))
            ->assertRedirect(route('all_orders.index'));
        $this->actingAs($manager)
            ->get(route('all_orders.index'))
            ->assertOk();
        $this->actingAs($cashier)
            ->get(route('operations.orders.invoice.pdf', $ownOrder))
            ->assertOk();
        $this->actingAs($cashier)
            ->get(route('operations.orders.invoice.pdf', $otherOrder))
            ->assertForbidden();
        $this->actingAs($cashier)
            ->get(route('operations.customers.statement.pdf', $customer))
            ->assertForbidden();
        $this->actingAs($accountant)
            ->get(route('operations.customers.statement.pdf', $customer))
            ->assertOk();
        $this->actingAs($designer)
            ->get(route('operations.document-templates.index'))
            ->assertOk();
        $this->actingAs($designer)
            ->get(route('operations.customers.statement.pdf', $customer))
            ->assertForbidden();
    }

    private function order(
        ?User $customer = null,
        ?string $code = null,
        string $paymentStatus = 'paid',
        float $paidAmount = 55,
        ?User $cashier = null
    ): array {
        $customer ??= $this->customer('sales-doc-customer');
        $now = now();
        $productId = DB::table('products')->insertGetId([
            'name' => 'Sales Document Product',
            'user_id' => 1,
            'category_id' => 1,
            'unit_price' => 25,
            'purchase_price' => 9,
            'slug' => 'sales-document-product-'.uniqid(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('product_stocks')->insert([
            'product_id' => $productId,
            'variant' => '',
            'sku' => 'SALES-DOC-'.uniqid(),
            'barcode' => 'BAR-'.uniqid(),
            'price' => 25,
            'qty' => 10,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $order = new Order();
        $order->forceFill([
            'user_id' => $customer->id,
            'cashier_id' => $cashier?->id,
            'shipping_address' => json_encode([
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'address' => 'Customer Street',
                'city' => 'Beirut',
                'country' => 'Lebanon',
            ]),
            'shipping_type' => 'home_delivery',
            'order_from' => $cashier ? 'pos' : 'web',
            'delivery_status' => 'pending',
            'payment_type' => 'cash',
            'payment_status' => $paymentStatus,
            'paid_amount' => $paidAmount,
            'grand_total' => 55,
            'coupon_discount' => 2,
            'code' => $code ?: 'SALES-DOC-'.uniqid(),
            'date' => time(),
            'viewed' => 0,
            'delivery_viewed' => 1,
            'commission_calculated' => 0,
            'notified' => 0,
        ])->save();

        $detail = new OrderDetail();
        $detail->forceFill([
            'order_id' => $order->id,
            'seller_id' => 1,
            'product_id' => $productId,
            'variation' => '',
            'price' => 50,
            'tax' => 5,
            'shipping_cost' => 2,
            'quantity' => 2,
            'payment_status' => $paymentStatus,
            'delivery_status' => 'pending',
            'cost_price' => 9,
            'total_cost' => 18,
            'profit_amount' => 32,
        ])->save();

        return [$order->fresh(), $detail];
    }

    private function customer(string $prefix): User
    {
        $user = User::query()->create([
            'name' => ucwords(str_replace('-', ' ', $prefix)),
            'email' => $prefix.'-'.uniqid().'@example.test',
            'password' => bcrypt('Temporary123!'),
        ]);
        $user->forceFill([
            'user_type' => 'customer',
            'phone' => '+961000000',
            'address' => 'Customer Street',
            'city' => 'Beirut',
            'country' => 'Lebanon',
            'banned' => 0,
        ])->save();

        return $user;
    }

    private function staff(string $email, string $role): User
    {
        $user = User::query()->create([
            'name' => str_replace('_', ' ', ucfirst($role)),
            'email' => $email,
            'password' => bcrypt('Temporary123!'),
        ]);
        $user->forceFill(['user_type' => 'staff', 'banned' => 0])->save();
        $user->syncRoles($role);

        return $user->fresh();
    }
}
