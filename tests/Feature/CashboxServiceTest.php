<?php

namespace Tests\Feature;

use App\Models\CashierShift;
use App\Models\User;
use App\Services\CashboxService;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CashboxServiceTest extends TestCase
{
    use DatabaseTransactions;

    private CashboxService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('coremarket_testing', DB::getDatabaseName());
        $this->assertTrue(Schema::hasTable('cashboxes'));
        $this->assertTrue(Schema::hasTable('cashier_shifts'));
        $this->assertTrue(Schema::hasTable('cash_movements'));

        $this->service = app(CashboxService::class);
    }

    public function test_can_create_cashbox(): void
    {
        $cashbox = $this->cashbox();

        $this->assertDatabaseHas('cashboxes', [
            'id' => $cashbox->id,
            'name' => 'Main Cashbox',
            'status' => 'active',
        ]);
    }

    public function test_can_update_cashbox(): void
    {
        $cashbox = $this->cashbox();
        $updated = $this->service->updateCashbox($cashbox, [
            'name' => 'Front Counter',
            'status' => 'inactive',
            'location' => 'Beirut',
        ]);

        $this->assertSame('Front Counter', $updated->name);
        $this->assertSame('inactive', $updated->status);
        $this->assertSame('Beirut', $updated->location);
    }

    public function test_cannot_open_shift_on_inactive_cashbox(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Cashbox is inactive.');

        $this->service->openShift($this->cashbox('inactive'), $this->user(), 10);
    }

    public function test_can_open_shift_with_opening_balance(): void
    {
        $shift = $this->openShift(25);

        $this->assertSame('open', $shift->status);
        $this->assertSame('25.000000', $shift->opening_balance);
        $this->assertSame('25.000000', $shift->expected_cash);
    }

    public function test_opening_shift_creates_opening_movement(): void
    {
        $shift = $this->openShift(25);

        $this->assertDatabaseHas('cash_movements', [
            'cashier_shift_id' => $shift->id,
            'movement_type' => 'opening',
            'direction' => 'in',
            'amount' => '25.000000',
        ]);
    }

    public function test_cannot_open_two_shifts_for_same_cashbox(): void
    {
        $cashbox = $this->cashbox();
        $this->service->openShift($cashbox, $this->user(), 10);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('This cashbox already has an open shift.');

        $this->service->openShift($cashbox, $this->user(), 10);
    }

    public function test_cannot_open_second_shift_for_same_user(): void
    {
        $user = $this->user();
        $this->service->openShift($this->cashbox(), $user, 10);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('This user already has an open shift.');

        $this->service->openShift($this->cashbox(), $user, 10);
    }

    public function test_expected_cash_is_calculated_from_movements(): void
    {
        $shift = $this->openShift(100);
        $this->service->addCashMovement($shift, 'cash_in', 'in', 30, 'Float added', null, $this->user());
        $this->service->addCashMovement($shift, 'cash_out', 'out', 20, 'Petty cash', null, $this->user());

        $this->assertSame(110.0, $this->service->calculateExpectedCash($shift));
        $this->assertSame('110.000000', $shift->fresh()->expected_cash);
    }

    public function test_can_add_cash_in_movement_without_changing_other_domains(): void
    {
        $shift = $this->openShift(10);
        $journalCount = DB::table('journal_entries')->count();
        $inventoryCount = DB::table('inventory_movements')->count();
        $orderCount = DB::table('orders')->count();

        $movement = $this->service->addCashMovement($shift, 'cash_in', 'in', 5, 'Cash added', null, $this->user());

        $this->assertSame('cash_in', $movement->movement_type);
        $this->assertSame('in', $movement->direction);
        $this->assertSame('15.000000', $shift->fresh()->expected_cash);
        $this->assertSame($journalCount, DB::table('journal_entries')->count());
        $this->assertSame($inventoryCount, DB::table('inventory_movements')->count());
        $this->assertSame($orderCount, DB::table('orders')->count());
    }

    public function test_can_add_cash_out_movement(): void
    {
        $shift = $this->openShift(20);

        $this->service->addCashMovement($shift, 'cash_out', 'out', 5, 'Petty cash', null, $this->user());

        $this->assertSame('15.000000', $shift->fresh()->expected_cash);
    }

    public function test_adjustment_in_affects_expected_cash(): void
    {
        $shift = $this->openShift(20);

        $this->service->addCashMovement($shift, 'adjustment', 'in', 5, 'Adjustment', null, $this->user());

        $this->assertSame('25.000000', $shift->fresh()->expected_cash);
    }

    public function test_adjustment_out_affects_expected_cash(): void
    {
        $shift = $this->openShift(20);

        $this->service->addCashMovement($shift, 'adjustment', 'out', 5, 'Adjustment', null, $this->user());

        $this->assertSame('15.000000', $shift->fresh()->expected_cash);
    }

    public function test_neutral_adjustment_does_not_affect_expected_cash(): void
    {
        $shift = $this->openShift(20);

        $this->service->addCashMovement($shift, 'adjustment', 'neutral', 5, 'Count note', null, $this->user());

        $this->assertSame('20.000000', $shift->fresh()->expected_cash);
    }

    public function test_cannot_cash_out_more_than_expected_cash(): void
    {
        $shift = $this->openShift(10);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Cash out cannot exceed expected cash.');

        $this->service->addCashMovement($shift, 'cash_out', 'out', 11, 'Too much', null, $this->user());
    }

    public function test_cannot_add_movement_to_closed_shift(): void
    {
        $shift = $this->openShift(10);
        $this->service->closeShift($shift, 10, null, $this->user());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Shift is already closed.');

        $this->service->addCashMovement($shift, 'cash_in', 'in', 1, 'Too late', null, $this->user());
    }

    public function test_can_close_shift_with_actual_cash(): void
    {
        $shift = $this->openShift(10);

        $closed = $this->service->closeShift($shift, 10, 'Counted', $this->user());

        $this->assertSame('closed', $closed->status);
        $this->assertSame('10.000000', $closed->actual_cash);
        $this->assertSame('0.000000', $closed->cash_difference);
    }

    public function test_closing_calculates_cash_difference(): void
    {
        $shift = $this->openShift(10);

        $closed = $this->service->closeShift($shift, 7, null, $this->user());

        $this->assertSame('10.000000', $closed->expected_cash);
        $this->assertSame('-3.000000', $closed->cash_difference);
    }

    public function test_closing_difference_creates_movement_when_non_zero(): void
    {
        $shift = $this->openShift(10);
        $this->service->closeShift($shift, 8, null, $this->user());

        $this->assertDatabaseHas('cash_movements', [
            'cashier_shift_id' => $shift->id,
            'movement_type' => 'closing_difference',
            'direction' => 'neutral',
            'amount' => '2.000000',
        ]);
    }

    public function test_closing_with_zero_difference_does_not_create_closing_difference_movement(): void
    {
        $shift = $this->openShift(10);
        $this->service->closeShift($shift, 10, null, $this->user());

        $this->assertSame(0, $shift->movements()->where('movement_type', 'closing_difference')->count());
    }

    public function test_cannot_close_shift_twice(): void
    {
        $shift = $this->openShift(10);
        $this->service->closeShift($shift, 10, null, $this->user());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Cannot close shift twice.');

        $this->service->closeShift($shift, 10, null, $this->user());
    }

    public function test_closed_shift_is_read_only(): void
    {
        $shift = $this->openShift(10);
        $this->service->closeShift($shift, 10, null, $this->user());

        $this->expectException(DomainException::class);
        $this->service->addCashMovement($shift, 'adjustment', 'neutral', 1, 'After close', null, $this->user());
    }

    private function cashbox(string $status = 'active')
    {
        return $this->service->createCashbox([
            'name' => 'Main Cashbox',
            'code' => 'CASH-' . uniqid(),
            'currency' => 'USD',
            'status' => $status,
        ]);
    }

    private function user(): User
    {
        return User::query()->create([
            'name' => 'Cashbox Tester',
            'email' => 'cashbox-' . uniqid() . '@example.test',
            'password' => 'testing-password',
        ]);
    }

    private function openShift(float $openingBalance): CashierShift
    {
        return $this->service->openShift($this->cashbox(), $this->user(), $openingBalance);
    }
}
