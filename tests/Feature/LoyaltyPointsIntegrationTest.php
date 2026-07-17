<?php

namespace Tests\Feature;

use App\Models\LoyaltyPointMovement;
use App\Models\LoyaltyRule;
use App\Models\Order;
use App\Models\User;
use App\Services\LoyaltyPointsService;
use App\Services\OrderService;
use App\Services\SalesReturnService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LoyaltyPointsIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('coremarket_testing', DB::getDatabaseName());
        $this->assertTrue(Schema::hasTable('loyalty_accounts'));
        $this->assertTrue(Schema::hasTable('loyalty_point_movements'));
        $this->assertTrue(Schema::hasTable('loyalty_rules'));
    }

    public function test_delivery_completion_awards_loyalty_points_idempotently(): void
    {
        $customer = $this->customer();
        $admin = $this->user('admin');
        $this->rule();
        $order = $this->order($customer, ['delivery_status' => 'pending']);
        $walletsBefore = DB::table('wallets')->count();
        $journalsBefore = DB::table('journal_entries')->count();

        $this->actingAs($admin);
        $request = new Request(['order_id' => $order->id, 'status' => 'delivered']);
        (new OrderService())->handle_delivery_status($request);
        (new OrderService())->handle_delivery_status($request);

        $this->assertSame(1, LoyaltyPointMovement::query()
            ->where('reference_type', Order::class)
            ->where('reference_id', $order->id)
            ->where('movement_type', 'earn')
            ->count());
        $this->assertSame(2, app(LoyaltyPointsService::class)->getBalance($customer));
        $this->assertSame($walletsBefore, DB::table('wallets')->count());
        $this->assertSame($journalsBefore, DB::table('journal_entries')->count());
    }

    public function test_ineligible_orders_do_not_earn_loyalty_points(): void
    {
        $customer = $this->customer();
        $this->rule();
        $service = app(LoyaltyPointsService::class);

        $this->assertNull($service->attemptEarnForOrder($this->order($customer, ['payment_status' => 'unpaid'])));
        $this->assertNull($service->attemptEarnForOrder($this->order($customer, ['delivery_status' => 'pending'])));
        $this->assertNull($service->attemptEarnForOrder($this->order(null, ['guest_id' => 42])));
        $this->assertNull($service->attemptEarnForOrder($this->order(null, ['order_from' => 'pos'])));
        $this->assertDatabaseCount('loyalty_point_movements', 0);
    }

    public function test_completed_sales_return_reverses_earned_points_once(): void
    {
        $customer = $this->customer();
        $admin = $this->user('admin');
        $this->rule();
        [$order, $detail] = $this->returnableOrder($customer);
        $loyalty = app(LoyaltyPointsService::class);
        $earned = $loyalty->earnForOrder($order);
        $salesReturns = app(SalesReturnService::class);
        $return = $salesReturns->create($order, [['order_detail_id' => $detail->id, 'quantity' => 1]]);

        $completed = $salesReturns->complete($return, $admin->id);
        $salesReturns->complete($completed, $admin->id);

        $reverse = LoyaltyPointMovement::query()
            ->where('reference_type', Order::class)
            ->where('reference_id', $order->id)
            ->where('movement_type', 'reverse')
            ->firstOrFail();

        $this->assertSame('completed', $completed->status);
        $this->assertSame($earned->points, $reverse->points);
        $this->assertSame(0, $reverse->balance_after);
        $this->assertSame(1, LoyaltyPointMovement::query()
            ->where('reference_id', $order->id)
            ->where('movement_type', 'reverse')
            ->count());
    }

    public function test_completed_sales_return_without_earned_points_remains_successful(): void
    {
        $customer = $this->customer();
        [$order, $detail] = $this->returnableOrder($customer);
        $service = app(SalesReturnService::class);
        $return = $service->create($order, [['order_detail_id' => $detail->id, 'quantity' => 1]]);

        $completed = $service->complete($return);

        $this->assertSame('completed', $completed->status);
        $this->assertDatabaseMissing('loyalty_point_movements', [
            'reference_type' => Order::class,
            'reference_id' => $order->id,
        ]);
    }

    private function rule(): LoyaltyRule
    {
        return LoyaltyRule::query()->create([
            'name' => 'Integration earn rule ' . uniqid(),
            'is_active' => true,
            'earn_rate_amount' => 10,
            'earn_rate_points' => 1,
            'min_order_amount' => 0,
            'currency' => 'USD',
        ]);
    }

    private function order(?User $customer, array $attributes = []): Order
    {
        $now = now();
        $id = DB::table('orders')->insertGetId(array_merge([
            'user_id' => $customer?->id,
            'shipping_type' => 'home_delivery',
            'order_from' => 'web',
            'delivery_status' => 'delivered',
            'payment_type' => 'cash_on_delivery',
            'payment_status' => 'paid',
            'grand_total' => 20,
            'coupon_discount' => 0,
            'code' => 'LOY-INT-' . uniqid(),
            'date' => $now->timestamp,
            'created_at' => $now,
            'updated_at' => $now,
        ], $attributes));

        return Order::query()->findOrFail($id);
    }

    private function returnableOrder(User $customer): array
    {
        $now = now();
        $productId = DB::table('products')->insertGetId([
            'name' => 'Loyalty return product',
            'user_id' => 1,
            'category_id' => 1,
            'unit_price' => 20,
            'purchase_price' => 10,
            'current_stock' => 1,
            'slug' => 'loyalty-return-' . uniqid(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $stockId = DB::table('product_stocks')->insertGetId([
            'product_id' => $productId,
            'variant' => '',
            'sku' => 'LOY-RETURN-' . uniqid(),
            'price' => 20,
            'qty' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $order = $this->order($customer);
        $detailId = DB::table('order_details')->insertGetId([
            'order_id' => $order->id,
            'product_id' => $productId,
            'variation' => '',
            'price' => 20,
            'tax' => 0,
            'shipping_cost' => 0,
            'quantity' => 1,
            'cost_price' => 10,
            'cost_source' => 'product_purchase_price',
            'total_cost' => 10,
            'profit_amount' => 10,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$order, DB::table('order_details')->where('id', $detailId)->first(), $stockId];
    }

    private function customer(): User
    {
        return $this->user('customer');
    }

    private function user(string $type): User
    {
        $user = new User();
        $user->forceFill([
            'name' => 'Loyalty Integration ' . ucfirst($type),
            'email' => uniqid('loyalty-integration-' . $type) . '@example.test',
            'password' => bcrypt('Temporary123!'),
            'user_type' => $type,
            'email_verified_at' => now(),
        ])->save();

        return $user;
    }
}
