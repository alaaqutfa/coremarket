<?php

namespace Tests\Feature;

use App\Models\LoyaltyAccount;
use App\Models\LoyaltyPointMovement;
use App\Models\LoyaltyRule;
use App\Models\Order;
use App\Models\User;
use App\Services\LoyaltyPointsService;
use App\Services\SalesReturnService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SalesReturnLoyaltyRedemptionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('coremarket_testing', DB::getDatabaseName());
        $this->assertTrue(Schema::hasColumn('orders', 'loyalty_points_redeemed'));
        $this->assertTrue(Schema::hasTable('sales_returns'));
    }

    public function test_completed_return_reverses_earned_points_and_restores_redeemed_points_once_without_changing_order_snapshots(): void
    {
        $customer = $this->user('customer');
        $actor = $this->user('admin');
        [$order, $detail] = $this->returnableOrder($customer, $actor);
        LoyaltyAccount::query()->create([
            'user_id' => $customer->id,
            'points_balance' => 50,
            'status' => 'active',
        ]);
        $this->rule();
        $loyalty = app(LoyaltyPointsService::class);
        $loyalty->redeemForOrder($order, $customer, 10, $actor);
        $order->refresh();
        $loyalty->earnForOrder($order);
        $account = LoyaltyAccount::query()->where('user_id', $customer->id)->firstOrFail();
        $this->assertSame(41, (int) $account->points_balance);

        $grandTotal = $order->grand_total;
        $redeemedPoints = $order->loyalty_points_redeemed;
        $redemptionDiscount = $order->loyalty_redemption_discount;
        $walletsBefore = DB::table('wallets')->count();
        $userBalanceBefore = (float) $customer->balance;
        $journalsBefore = DB::table('journal_entries')->count();
        $returns = app(SalesReturnService::class);
        $salesReturn = $returns->create($order, [[
            'order_detail_id' => $detail->id,
            'quantity' => 1,
        ]]);

        $completed = $returns->complete($salesReturn, $actor->id);
        $returns->complete($completed, $actor->id);

        $restore = LoyaltyPointMovement::query()
            ->where('reference_type', Order::class)
            ->where('reference_id', $order->id)
            ->where('movement_type', 'redeem_restore')
            ->firstOrFail();
        $this->assertSame('in', $restore->direction);
        $this->assertSame(10, (int) $restore->points);
        $this->assertSame('sales_return_completed', $restore->reason);
        $this->assertSame('full_restore_on_first_completed_return', $restore->metadata['policy']);
        $this->assertSame($actor->id, $restore->created_by);
        $this->assertSame(1, LoyaltyPointMovement::query()->where('reference_id', $order->id)->where('movement_type', 'redeem_restore')->count());
        $this->assertSame(1, LoyaltyPointMovement::query()->where('reference_id', $order->id)->where('movement_type', 'reverse')->count());
        $this->assertSame(50, (int) $account->fresh()->points_balance);

        $order->refresh();
        $this->assertSame($grandTotal, $order->grand_total);
        $this->assertSame($redeemedPoints, $order->loyalty_points_redeemed);
        $this->assertSame($redemptionDiscount, $order->loyalty_redemption_discount);
        $this->assertSame($walletsBefore, DB::table('wallets')->count());
        $this->assertSame($userBalanceBefore, (float) $customer->fresh()->balance);
        $this->assertSame($journalsBefore, DB::table('journal_entries')->count());
    }

    public function test_completed_return_without_redemption_does_not_create_restore_movement(): void
    {
        $customer = $this->user('customer');
        $actor = $this->user('admin');
        [$order, $detail] = $this->returnableOrder($customer, $actor);
        $returns = app(SalesReturnService::class);
        $salesReturn = $returns->create($order, [[
            'order_detail_id' => $detail->id,
            'quantity' => 1,
        ]]);

        $completed = $returns->complete($salesReturn, $actor->id);

        $this->assertSame('completed', $completed->status);
        $this->assertDatabaseMissing('loyalty_point_movements', [
            'reference_type' => Order::class,
            'reference_id' => $order->id,
            'movement_type' => 'redeem_restore',
        ]);
    }

    private function returnableOrder(User $customer, User $owner): array
    {
        $now = now();
        $productId = DB::table('products')->insertGetId([
            'name' => 'Sales Return Redemption Product ' . uniqid(),
            'user_id' => $owner->id,
            'category_id' => 1,
            'unit_price' => 20,
            'purchase_price' => 10,
            'current_stock' => 1,
            'slug' => 'sales-return-redemption-' . uniqid(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('product_stocks')->insert([
            'product_id' => $productId,
            'variant' => '',
            'sku' => 'RETURN-REDEMPTION-' . uniqid(),
            'price' => 20,
            'qty' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $orderId = DB::table('orders')->insertGetId([
            'user_id' => $customer->id,
            'seller_id' => $owner->id,
            'shipping_type' => 'pos',
            'order_from' => 'pos',
            'delivery_status' => 'delivered',
            'payment_type' => 'cash',
            'payment_status' => 'paid',
            'grand_total' => 20,
            'coupon_discount' => 0,
            'code' => 'RETURN-REDEMPTION-' . uniqid(),
            'date' => $now->timestamp,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $detailId = DB::table('order_details')->insertGetId([
            'order_id' => $orderId,
            'seller_id' => $owner->id,
            'product_id' => $productId,
            'variation' => '',
            'price' => 20,
            'tax' => 0,
            'shipping_cost' => 0,
            'quantity' => 1,
            'payment_status' => 'paid',
            'delivery_status' => 'delivered',
            'shipping_type' => 'pos',
            'cost_price' => 10,
            'cost_source' => 'product_purchase_price',
            'total_cost' => 10,
            'profit_amount' => 10,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            Order::query()->findOrFail($orderId),
            DB::table('order_details')->where('id', $detailId)->first(),
        ];
    }

    private function rule(): LoyaltyRule
    {
        return LoyaltyRule::query()->create([
            'name' => 'Sales Return Redemption Rule ' . uniqid(),
            'is_active' => true,
            'earn_rate_amount' => 10,
            'earn_rate_points' => 1,
            'min_order_amount' => 0,
            'applies_to_order_from' => 'pos',
            'redeem_points' => 10,
            'redeem_value' => 1,
            'min_redeem_points' => 0,
            'allow_pos_redeem' => true,
            'allow_storefront_redeem' => false,
            'currency' => 'USD',
        ]);
    }

    private function user(string $type): User
    {
        $user = new User();
        $user->forceFill([
            'name' => 'Sales Return Redemption ' . ucfirst($type),
            'email' => uniqid('sales-return-redemption-' . $type) . '@example.test',
            'password' => bcrypt('Temporary123!'),
            'user_type' => $type,
            'banned' => 0,
            'email_verified_at' => now(),
        ])->save();

        return $user;
    }
}
