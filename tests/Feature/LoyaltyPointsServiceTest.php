<?php

namespace Tests\Feature;

use App\Models\LoyaltyAccount;
use App\Models\LoyaltyPointMovement;
use App\Models\LoyaltyRule;
use App\Models\Order;
use App\Models\User;
use App\Services\LoyaltyPointsService;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LoyaltyPointsServiceTest extends TestCase
{
    use DatabaseTransactions;

    private LoyaltyPointsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('coremarket_testing', DB::getDatabaseName());
        $this->assertTrue(Schema::hasTable('loyalty_accounts'));
        $this->assertTrue(Schema::hasTable('loyalty_point_movements'));
        $this->assertTrue(Schema::hasTable('loyalty_rules'));

        $this->service = app(LoyaltyPointsService::class);
    }

    public function test_creates_loyalty_account_for_customer(): void
    {
        $customer = $this->customer();
        $account = $this->service->accountForCustomer($customer);

        $this->assertSame(0, $account->points_balance);
        $this->assertDatabaseHas('loyalty_accounts', ['user_id' => $customer->id, 'status' => 'active']);
    }

    public function test_rejects_non_customer_loyalty_accounts(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Loyalty accounts are only available for customers.');

        $this->service->accountForCustomer($this->user('staff'));
    }

    public function test_previews_earn_points_from_the_matching_active_rule(): void
    {
        $this->rule(['earn_rate_amount' => 10, 'earn_rate_points' => 3, 'min_order_amount' => 20]);
        $order = $this->eligibleOrder(['grand_total' => 49]);

        $this->assertSame(14, $this->service->previewEarnForOrder($order));
    }

    public function test_does_not_earn_without_a_rule_or_for_ineligible_orders(): void
    {
        $this->assertSame(0, $this->service->previewEarnForOrder($this->eligibleOrder()));

        $this->rule();
        $this->assertNull($this->service->earnForOrder($this->order(['user_id' => null, 'guest_id' => 10])));
        $this->assertNull($this->service->earnForOrder($this->eligibleOrder(['payment_status' => 'unpaid'])));
        $this->assertNull($this->service->earnForOrder($this->eligibleOrder(['delivery_status' => 'pending'])));
    }

    public function test_earns_points_idempotently_with_a_correct_ledger_balance(): void
    {
        $this->rule(['earn_rate_amount' => 10, 'earn_rate_points' => 2]);
        $order = $this->eligibleOrder(['grand_total' => 35]);
        $journals = DB::table('journal_entries')->count();
        $wallets = DB::table('wallets')->count();

        $first = $this->service->earnForOrder($order);
        $second = $this->service->earnForOrder($order);

        $this->assertNotNull($first);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(7, $first->points);
        $this->assertSame(7, $first->balance_after);
        $this->assertSame('in', $first->direction);
        $this->assertSame(7, $this->service->getBalance($order->user));
        $this->assertSame(1, LoyaltyPointMovement::query()->where('reference_id', $order->id)->where('movement_type', 'earn')->count());
        $this->assertSame($journals, DB::table('journal_entries')->count());
        $this->assertSame($wallets, DB::table('wallets')->count());
    }

    public function test_manual_adjustments_change_balance_without_allowing_negative_values(): void
    {
        $customer = $this->customer();
        $admin = $this->user('admin');

        $positive = $this->service->adjustPoints($customer, 9, 'Welcome correction', $admin);
        $negative = $this->service->adjustPoints($customer, -4, 'Correction reversal', $admin);

        $this->assertSame('in', $positive->direction);
        $this->assertSame(9, $positive->balance_after);
        $this->assertSame('out', $negative->direction);
        $this->assertSame(5, $negative->balance_after);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Loyalty adjustment cannot make balance negative.');
        $this->service->adjustPoints($customer, -6, 'Too much', $admin);
    }

    public function test_reverses_earned_points_idempotently_without_negative_balance(): void
    {
        $this->rule(['earn_rate_amount' => 10, 'earn_rate_points' => 2]);
        $order = $this->eligibleOrder(['grand_total' => 30]);
        $earned = $this->service->earnForOrder($order);

        $reverse = $this->service->reverseForOrder($order, 'Completed sales return', $this->user('admin'));
        $again = $this->service->reverseForOrder($order, 'Completed sales return');

        $this->assertSame('out', $reverse->direction);
        $this->assertSame($earned->points, $reverse->points);
        $this->assertSame(0, $reverse->balance_after);
        $this->assertFalse($reverse->metadata['partial_reverse']);
        $this->assertSame($reverse->id, $again->id);
        $this->assertSame(0, $this->service->getBalance($order->user));
    }

    public function test_reverse_uses_only_available_balance_when_points_were_adjusted_away(): void
    {
        $this->rule(['earn_rate_amount' => 10, 'earn_rate_points' => 2]);
        $customer = $this->customer();
        $order = $this->order(['user_id' => $customer->id, 'grand_total' => 30]);
        $earned = $this->service->earnForOrder($order);
        $this->service->adjustPoints($customer, -4, 'Points used outside loyalty', $this->user('admin'));

        $reverse = $this->service->reverseForOrder($order, 'Partial return');

        $this->assertSame(2, $reverse->points);
        $this->assertTrue($reverse->metadata['partial_reverse']);
        $this->assertSame(0, $this->service->getBalance($customer));
        $this->assertSame(6, $earned->points);
    }

    private function rule(array $attributes = []): LoyaltyRule
    {
        return LoyaltyRule::query()->create(array_merge([
            'name' => 'Default earn rule ' . uniqid(),
            'is_active' => true,
            'earn_rate_amount' => 10,
            'earn_rate_points' => 1,
            'min_order_amount' => 0,
            'currency' => 'USD',
        ], $attributes));
    }

    private function eligibleOrder(array $attributes = []): Order
    {
        $customer = $this->customer();

        return $this->order(array_merge(['user_id' => $customer->id], $attributes));
    }

    private function order(array $attributes = []): Order
    {
        $id = DB::table('orders')->insertGetId(array_merge([
            'shipping_type' => 'home_delivery',
            'order_from' => 'web',
            'delivery_status' => 'delivered',
            'payment_type' => 'cash_on_delivery',
            'payment_status' => 'paid',
            'grand_total' => 20,
            'coupon_discount' => 0,
            'code' => 'LOY-' . uniqid(),
            'date' => now()->timestamp,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));

        return Order::query()->with('user')->findOrFail($id);
    }

    private function customer(): User
    {
        return $this->user('customer');
    }

    private function user(string $type): User
    {
        $user = new User();
        $user->forceFill([
            'name' => 'Loyalty ' . ucfirst($type),
            'email' => uniqid('loyalty-' . $type) . '@example.test',
            'password' => bcrypt('Temporary123!'),
            'user_type' => $type,
            'email_verified_at' => now(),
        ])->save();

        return $user;
    }
}
