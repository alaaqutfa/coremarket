<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\StoreBranch;
use App\Models\User;
use App\Services\CoreMarketBranchService;
use App\Services\CoreMarketDeliveryService;
use Database\Seeders\OperationsPermissionSeeder;
use Database\Seeders\StaffRolePresetSeeder;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DeliveryWorkflowTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('coremarket_testing', DB::getDatabaseName());
        $this->seed(OperationsPermissionSeeder::class);
        $this->seed(StaffRolePresetSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_delivery_record_is_ensured_once_and_cod_is_operational_only(): void
    {
        $order = $this->order('cash_on_delivery', 44.125);
        $service = app(CoreMarketDeliveryService::class);

        $first = $service->ensureDeliveryForOrder($order);
        $second = $service->ensureDeliveryForOrder($order);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('pending_assignment', $first->status);
        $this->assertSame('pending', $first->cod_collection_status);
        $this->assertSame('44.130000', $first->cod_amount);
        $this->assertSame(1, $first->events()->count());
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    public function test_manager_assigns_delivery_user_and_valid_status_transitions_are_audited(): void
    {
        $manager = $this->staff('manager-'.uniqid().'@example.test', 'owner_general_manager');
        $driver = $this->staff('driver-'.uniqid().'@example.test', 'delivery_distribution');
        $order = $this->order();
        $service = app(CoreMarketDeliveryService::class);
        $delivery = $service->assignDeliveryUser($order, $driver, $manager);

        $this->assertSame('assigned', $delivery->status);
        $this->assertSame($driver->id, $delivery->delivery_user_id);

        foreach (['picked_up', 'out_for_delivery', 'delivered'] as $status) {
            $delivery = $service->updateStatus($delivery, $status, "Moved to {$status}", $driver);
        }

        $this->assertSame('delivered', $delivery->status);
        $this->assertSame('delivered', $order->fresh()->delivery_status);
        $this->assertSame(5, $delivery->events()->count());
    }

    public function test_invalid_status_transition_is_rejected(): void
    {
        $delivery = app(CoreMarketDeliveryService::class)->ensureDeliveryForOrder($this->order());

        $this->expectException(DomainException::class);
        app(CoreMarketDeliveryService::class)->updateStatus($delivery, 'delivered');
    }

    public function test_cod_can_be_recorded_without_changing_order_payment_or_cashbox(): void
    {
        $order = $this->order('cash_on_delivery', 100);
        $delivery = app(CoreMarketDeliveryService::class)->ensureDeliveryForOrder($order);

        $partial = app(CoreMarketDeliveryService::class)->collectCod($delivery, 40);
        $complete = app(CoreMarketDeliveryService::class)->collectCod($partial, 100);

        $this->assertSame('partially_collected', $partial->cod_collection_status);
        $this->assertSame('collected', $complete->cod_collection_status);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
        $this->assertNull($order->fresh()->cashbox_id);
    }

    public function test_delivery_employee_sees_only_assigned_records_and_cashier_is_denied(): void
    {
        $driver = $this->staff('assigned-driver-'.uniqid().'@example.test', 'delivery_distribution');
        $otherDriver = $this->staff('other-driver-'.uniqid().'@example.test', 'delivery_distribution');
        $cashier = $this->staff('delivery-cashier-'.uniqid().'@example.test', 'cashier');
        $service = app(CoreMarketDeliveryService::class);
        $assigned = $service->assignDeliveryUser($this->order(code: 'ASSIGNED-ORDER'), $driver);
        $other = $service->assignDeliveryUser($this->order(code: 'OTHER-ORDER'), $otherDriver);

        $this->actingAs($driver)
            ->get(route('operations.deliveries.index'))
            ->assertOk()
            ->assertSee('ASSIGNED-ORDER')
            ->assertDontSee('OTHER-ORDER')
            ->assertDontSee('Supplier Balance')
            ->assertDontSee('Gross Profit');

        $this->actingAs($driver)
            ->get(route('operations.deliveries.show', $assigned))
            ->assertOk()
            ->assertSee('Amount to collect')
            ->assertSee('intentionally excludes product cost');

        $this->actingAs($driver)
            ->patch(route('operations.deliveries.status', $assigned), ['status' => 'cancelled'])
            ->assertForbidden();

        $this->actingAs($driver)
            ->get(route('operations.deliveries.show', $other))
            ->assertForbidden();

        $this->actingAs($cashier)
            ->get(route('operations.deliveries.index'))
            ->assertForbidden();
    }

    public function test_accountant_has_safe_delivery_visibility_and_driver_assignment_respects_branch(): void
    {
        $accountant = $this->staff('delivery-accountant-'.uniqid().'@example.test', 'accountant');
        $driver = $this->staff('branch-driver-'.uniqid().'@example.test', 'delivery_distribution');
        $branch = StoreBranch::query()->create([
            'name' => 'Delivery Branch',
            'code' => 'DEL-'.uniqid(),
            'is_active' => true,
        ]);
        app(CoreMarketBranchService::class)->assignStaff($driver, [$branch->id], $branch->id);
        $delivery = app(CoreMarketDeliveryService::class)->ensureDeliveryForOrder($this->order());
        $delivery->update(['branch_id' => $branch->id]);

        $this->assertTrue(app(CoreMarketDeliveryService::class)->availableDeliveryUsers($branch)->contains('id', $driver->id));
        $this->actingAs($accountant)
            ->get(route('operations.deliveries.show', $delivery))
            ->assertOk()
            ->assertDontSee('Supplier Balance')
            ->assertDontSee('Gross Profit');
    }

    public function test_delivery_permissions_are_mapped_to_bounded_role_presets(): void
    {
        $driver = $this->staff('permission-driver-'.uniqid().'@example.test', 'delivery_distribution');
        $cashier = $this->staff('permission-cashier-'.uniqid().'@example.test', 'cashier');
        $manager = $this->staff('permission-manager-'.uniqid().'@example.test', 'owner_general_manager');

        $this->assertTrue($driver->can('deliveries.view_assigned'));
        $this->assertTrue($driver->can('deliveries.update_status'));
        $this->assertFalse($driver->can('deliveries.view_all'));
        $this->assertFalse($driver->can('deliveries.collect_cod'));
        $this->assertFalse($driver->can('view_inhouse_orders'));
        $this->assertFalse($driver->can('view_order_details'));
        $this->assertFalse($cashier->can('deliveries.view_assigned'));
        $this->assertTrue($manager->can('deliveries.assign'));
        $this->assertTrue($manager->can('deliveries.view_all'));
    }

    private function order(string $paymentType = 'cash_on_delivery', float $total = 25, ?string $code = null): Order
    {
        $order = new Order();
        $order->forceFill([
            'shipping_address' => json_encode([
                'name' => 'Demo Customer',
                'phone' => '+961000000',
                'address' => 'Demo Street',
                'city' => 'Beirut',
                'country' => 'Lebanon',
            ]),
            'shipping_type' => 'home_delivery',
            'order_from' => 'web',
            'delivery_status' => 'pending',
            'payment_type' => $paymentType,
            'payment_status' => 'unpaid',
            'grand_total' => $total,
            'code' => $code ?: 'DELIVERY-'.uniqid(),
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
}
