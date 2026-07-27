<?php

namespace Tests\Feature;

use App\Models\CashierShift;
use App\Models\DeliveryCodSettlement;
use App\Models\Order;
use App\Models\User;
use App\Services\CashboxService;
use App\Services\CoreMarketAccountingReportService;
use App\Services\CoreMarketCodSettlementService;
use App\Services\CoreMarketDeliveryService;
use Database\Seeders\OperationsPermissionSeeder;
use Database\Seeders\StaffRolePresetSeeder;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CodSettlementFoundationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('coremarket_testing', DB::getDatabaseName());
        $this->assertTrue(Schema::hasTable('delivery_cod_settlements'));
        $this->seed(OperationsPermissionSeeder::class);
        $this->seed(StaffRolePresetSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_assigned_delivery_user_records_collection_but_cannot_settle_or_see_controls(): void
    {
        $driver = $this->staff('cod-driver-'.uniqid().'@example.test', 'delivery_distribution');
        $delivery = app(CoreMarketDeliveryService::class)
            ->assignDeliveryUser($this->order(75), $driver);

        $this->actingAs($driver)
            ->post(route('operations.deliveries.cod', $delivery), ['cod_collected_amount' => 75])
            ->assertRedirect();

        $delivery->refresh();
        $this->assertSame('collected', $delivery->cod_collection_status);
        $this->assertFalse($driver->can('deliveries.settle_cod'));

        $this->actingAs($driver)
            ->get(route('operations.deliveries.show', $delivery))
            ->assertOk()
            ->assertSee('Recorded collection')
            ->assertDontSee('Receive COD / Settle COD')
            ->assertDontSee('Open cashbox shift');
    }

    public function test_manager_settlement_creates_one_cash_movement_and_repeated_key_is_idempotent(): void
    {
        $manager = $this->staff('cod-manager-'.uniqid().'@example.test', 'owner_general_manager');
        $delivery = $this->collectedDelivery(100);
        $shift = $this->openShift($manager);
        $service = app(CoreMarketCodSettlementService::class);
        $paymentStatus = $delivery->order->payment_status;

        $first = $service->settle($delivery, 100, $manager, $shift, 'cod-key-'.uniqid());
        $second = $service->settle(
            $delivery,
            100,
            $manager,
            $shift,
            $first->idempotency_key
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DeliveryCodSettlement::query()->where('order_delivery_id', $delivery->id)->count());
        $this->assertDatabaseHas('cash_movements', [
            'id' => $first->cash_movement_id,
            'cashier_shift_id' => $shift->id,
            'movement_type' => 'delivery_cod_settlement',
            'direction' => 'in',
            'amount' => '100.000000',
        ]);
        $this->assertSame('100.000000', $shift->fresh()->expected_cash);
        $this->assertSame($paymentStatus, $delivery->order->fresh()->payment_status);
    }

    public function test_partial_settlement_tracks_remaining_and_rejects_over_settlement(): void
    {
        $accountant = $this->staff('cod-accountant-'.uniqid().'@example.test', 'accountant');
        $delivery = $this->collectedDelivery(100);
        $shift = $this->openShift($accountant);
        $service = app(CoreMarketCodSettlementService::class);

        $service->settle($delivery, 35, $accountant, $shift, 'partial-a-'.uniqid());
        $snapshot = $service->settlementSnapshot($delivery);

        $this->assertSame(35.0, $snapshot['settled_amount']);
        $this->assertSame(65.0, $snapshot['remaining_amount']);
        $this->assertSame('partially_settled', $snapshot['status']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('cannot exceed');
        $service->settle($delivery, 66, $accountant, $shift, 'partial-b-'.uniqid());
    }

    public function test_cashier_can_use_only_own_open_shift_and_needs_permission(): void
    {
        $cashier = $this->staff('cod-cashier-'.uniqid().'@example.test', 'cashier');
        $other = $this->staff('cod-other-'.uniqid().'@example.test', 'owner_general_manager');
        $delivery = $this->collectedDelivery(50);
        $ownShift = $this->openShift($cashier);
        $otherShift = $this->openShift($other);
        $service = app(CoreMarketCodSettlementService::class);

        $this->assertTrue($cashier->can('deliveries.settle_cod'));
        $this->assertSame([$ownShift->id], $service->availableOpenShifts($cashier)->pluck('id')->all());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('their own open shift');
        $service->settle($delivery, 10, $cashier, $otherShift, 'cashier-wrong-'.uniqid());
    }

    public function test_cod_without_collected_funds_or_with_failed_status_cannot_be_settled(): void
    {
        $manager = $this->staff('cod-failed-manager-'.uniqid().'@example.test', 'owner_general_manager');
        $delivery = app(CoreMarketDeliveryService::class)->ensureDeliveryForOrder($this->order(40));
        $delivery->update(['cod_collection_status' => 'failed']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Only collected COD funds');
        app(CoreMarketCodSettlementService::class)->settle(
            $delivery,
            10,
            $manager,
            $this->openShift($manager),
            'failed-'.uniqid()
        );
    }

    public function test_no_open_shift_is_clear_and_cod_report_uses_operational_settlements(): void
    {
        $manager = $this->staff('cod-report-manager-'.uniqid().'@example.test', 'owner_general_manager');
        $delivery = $this->collectedDelivery(80);
        $service = app(CoreMarketCodSettlementService::class);

        $this->assertTrue($service->availableOpenShifts($manager)->isEmpty());
        $this->actingAs($manager)
            ->get(route('operations.deliveries.show', $delivery))
            ->assertOk()
            ->assertSee('No open cashbox shift available.');

        $shift = $this->openShift($manager);
        $service->settle($delivery, 30, $manager, $shift, 'report-'.uniqid());
        $report = app(CoreMarketAccountingReportService::class)->report();

        $this->assertGreaterThanOrEqual(80.0, $report['cod']['collected']);
        $this->assertGreaterThanOrEqual(30.0, $report['cod']['settled']);
        $this->assertGreaterThanOrEqual(50.0, $report['cod']['pending_settlement']);
    }

    private function collectedDelivery(float $amount)
    {
        $delivery = app(CoreMarketDeliveryService::class)->ensureDeliveryForOrder($this->order($amount));

        return app(CoreMarketDeliveryService::class)->collectCod($delivery, $amount);
    }

    private function order(float $total): Order
    {
        $order = new Order();
        $order->forceFill([
            'shipping_address' => json_encode([
                'name' => 'COD Customer',
                'phone' => '+961000000',
                'address' => 'COD Street',
                'city' => 'Beirut',
                'country' => 'Lebanon',
            ]),
            'shipping_type' => 'home_delivery',
            'order_from' => 'web',
            'delivery_status' => 'pending',
            'payment_type' => 'cash_on_delivery',
            'payment_status' => 'unpaid',
            'grand_total' => $total,
            'code' => 'COD-'.uniqid(),
            'date' => time(),
            'viewed' => 0,
            'delivery_viewed' => 1,
            'commission_calculated' => 0,
            'notified' => 0,
        ])->save();

        return $order;
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

    private function openShift(User $user): CashierShift
    {
        $cashbox = app(CashboxService::class)->createCashbox([
            'name' => 'COD Cashbox',
            'code' => 'COD-CASH-'.uniqid(),
            'currency' => 'USD',
            'status' => 'active',
        ]);

        return app(CashboxService::class)->openShift($cashbox, $user, 0);
    }
}
