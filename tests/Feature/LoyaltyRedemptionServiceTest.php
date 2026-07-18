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

class LoyaltyRedemptionServiceTest extends TestCase
{
    use DatabaseTransactions;

    private LoyaltyPointsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('coremarket_testing', DB::getDatabaseName());
        $this->assertTrue(Schema::hasColumn('orders', 'loyalty_points_redeemed'));
        $this->assertTrue(Schema::hasColumn('loyalty_rules', 'redeem_points'));
        $this->service = app(LoyaltyPointsService::class);
    }

    public function test_preview_rejects_non_customer_banned_customer_and_missing_rule_without_creating_an_account(): void
    {
        $staff = $this->user('staff');
        $customer = $this->user('customer', ['banned' => 1]);
        $available = $this->user('customer');

        $this->expectDomainException(fn () => $this->service->previewRedeemForCustomer($staff, 10, 100), 'Loyalty accounts are only available for customers.');
        $this->expectDomainException(fn () => $this->service->previewRedeemForCustomer($customer, 10, 100), 'Selected loyalty customer is unavailable.');
        $this->expectDomainException(fn () => $this->service->previewRedeemForCustomer($available, 10, 100), 'Loyalty redemption is disabled.');
        $this->assertSame(0, LoyaltyAccount::query()->where('user_id', $available->id)->count());
    }

    public function test_preview_enforces_balance_minimum_caps_and_percentage(): void
    {
        $customer = $this->fundedCustomer(40);
        $this->rule([
            'min_redeem_points' => 20,
            'max_redeem_points_per_order' => 30,
            'max_redeem_percent' => 10,
        ]);

        $this->expectDomainException(fn () => $this->service->previewRedeemForCustomer($customer, 10, 100), 'Redemption points are below the minimum.');
        $this->expectDomainException(fn () => $this->service->previewRedeemForCustomer($customer, 35, 100), 'Redemption points exceed the per-order cap.');
        $this->expectDomainException(fn () => $this->service->previewRedeemForCustomer($customer, 30, 20), 'Redemption discount exceeds the allowed percentage.');
        $this->expectDomainException(fn () => $this->service->previewRedeemForCustomer($customer, 50, 100), 'Insufficient loyalty points balance.');
    }

    public function test_preview_calculates_conversion_and_is_read_only(): void
    {
        $customer = $this->fundedCustomer(50);
        $this->rule(['redeem_points' => 10, 'redeem_value' => 2.5, 'min_redeem_points' => 10]);
        $accounts = LoyaltyAccount::query()->count();

        $preview = $this->service->previewRedeemForCustomer($customer, 29, 100);

        $this->assertSame(20, $preview['used_points']);
        $this->assertSame(5.0, $preview['discount_amount']);
        $this->assertSame(95.0, $preview['final_total']);
        $this->assertSame(30, $preview['balance_after']);
        $this->assertSame($accounts, LoyaltyAccount::query()->count());
    }

    public function test_redeem_updates_order_and_ledger_idempotently_without_coupon_wallet_or_journal_side_effects(): void
    {
        $customer = $this->fundedCustomer(60);
        $actor = $this->user('staff');
        $this->rule(['redeem_points' => 10, 'redeem_value' => 2]);
        $order = $this->order($customer, 100);
        $wallets = DB::table('wallets')->count();
        $journals = DB::table('journal_entries')->count();

        $first = $this->service->redeemForOrder($order, $customer, 20, $actor);
        $second = $this->service->redeemForOrder($order, $customer, 20, $actor);
        $updated = $order->fresh();

        $this->assertSame($first->id, $second->id);
        $this->assertSame('redeem', $first->movement_type);
        $this->assertSame('out', $first->direction);
        $this->assertSame(20, $first->points);
        $this->assertSame(40, $first->balance_after);
        $this->assertSame(20, $updated->loyalty_points_redeemed);
        $this->assertSame('4.000000', $updated->loyalty_redemption_discount);
        $this->assertSame(96.0, (float) $updated->grand_total);
        $this->assertSame(0.0, (float) $updated->coupon_discount);
        $this->assertSame(40, (int) $this->service->getBalance($customer));
        $this->assertSame(20, (int) LoyaltyAccount::query()->where('user_id', $customer->id)->value('lifetime_points_redeemed'));
        $this->assertSame(1, LoyaltyPointMovement::query()->where('reference_id', $order->id)->where('movement_type', 'redeem')->count());
        $this->assertSame($wallets, DB::table('wallets')->count());
        $this->assertSame($journals, DB::table('journal_entries')->count());

        $this->expectDomainException(fn () => $this->service->redeemForOrder($updated, $customer, 10), 'Order already has a different loyalty redemption.');
    }

    public function test_points_redeemed_lookup_and_restore_are_idempotent(): void
    {
        $customer = $this->fundedCustomer(30);
        $this->rule();
        $order = $this->order($customer, 50);
        $redeem = $this->service->redeemForOrder($order, $customer, 10);

        $this->assertSame($redeem->id, $this->service->pointsRedeemedForOrder($order)->id);
        $restore = $this->service->restoreRedeemedForOrder($order, 'Completed sales return', $this->user('admin'));
        $again = $this->service->restoreRedeemedForOrder($order, 'Completed sales return');

        $this->assertSame($restore->id, $again->id);
        $this->assertSame('redeem_restore', $restore->movement_type);
        $this->assertSame('in', $restore->direction);
        $this->assertSame($redeem->points, $restore->points);
        $this->assertSame(30, $restore->balance_after);
        $this->assertSame('full_restore_on_first_completed_return', $restore->metadata['policy']);
    }

    public function test_restore_returns_null_when_order_has_no_redemption(): void
    {
        $customer = $this->user('customer');

        $this->assertNull($this->service->restoreRedeemedForOrder($this->order($customer, 25)));
    }

    private function fundedCustomer(int $points): User
    {
        $customer = $this->user('customer');
        $account = $this->service->accountForCustomer($customer);
        $account->points_balance = $points;
        $account->save();

        return $customer;
    }

    private function rule(array $attributes = []): LoyaltyRule
    {
        return LoyaltyRule::query()->create(array_merge([
            'name' => 'Redemption rule ' . uniqid(),
            'is_active' => true,
            'earn_rate_amount' => 10,
            'earn_rate_points' => 1,
            'min_order_amount' => 0,
            'redeem_points' => 10,
            'redeem_value' => 1,
            'min_redeem_points' => 0,
            'allow_pos_redeem' => true,
            'allow_storefront_redeem' => false,
            'currency' => 'USD',
        ], $attributes));
    }

    private function order(User $customer, float $grandTotal): Order
    {
        $id = DB::table('orders')->insertGetId([
            'user_id' => $customer->id,
            'shipping_type' => 'pos',
            'order_from' => 'pos',
            'delivery_status' => 'delivered',
            'payment_type' => 'cash',
            'payment_status' => 'paid',
            'grand_total' => $grandTotal,
            'coupon_discount' => 0,
            'code' => 'REDEEM-' . uniqid(),
            'date' => now()->timestamp,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Order::query()->findOrFail($id);
    }

    private function user(string $type, array $attributes = []): User
    {
        $user = new User();
        $user->forceFill(array_merge([
            'name' => 'Redemption ' . ucfirst($type),
            'email' => uniqid('redemption-' . $type) . '@example.test',
            'password' => bcrypt('Temporary123!'),
            'user_type' => $type,
            'banned' => 0,
            'email_verified_at' => now(),
        ], $attributes))->save();

        return $user;
    }

    private function expectDomainException(callable $callback, string $message): void
    {
        try {
            $callback();
            $this->fail('Expected DomainException was not thrown.');
        } catch (DomainException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }
}
