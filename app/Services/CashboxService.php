<?php

namespace App\Services;

use App\Models\Cashbox;
use App\Models\CashierShift;
use App\Models\CashMovement;
use App\Models\Order;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class CashboxService
{
    private const MANUAL_MOVEMENT_TYPES = [
        'cash_in',
        'cash_out',
        'adjustment',
    ];

    private const DIRECTIONS = [
        'in',
        'out',
        'neutral',
    ];

    public function createCashbox(array $payload): Cashbox
    {
        return Cashbox::create($this->normalizeCashboxPayload($payload));
    }

    public function updateCashbox(Cashbox $cashbox, array $payload): Cashbox
    {
        $cashbox->update($this->normalizeCashboxPayload($payload));

        return $cashbox->fresh();
    }

    public function currentOpenShiftForUser(User|int $user): ?CashierShift
    {
        $user = $this->resolveUser($user);

        return CashierShift::query()
            ->with('cashbox')
            ->where('opened_by', $user->id)
            ->where('status', 'open')
            ->latest('opened_at')
            ->first();
    }

    public function openShift(
        Cashbox $cashbox,
        User|int $user,
        mixed $openingBalance,
        ?string $notes = null
    ): CashierShift {
        $user = $this->resolveUser($user);
        $openingBalance = $this->normalizeNonNegativeAmount($openingBalance, 'Opening balance must not be negative.');

        return DB::transaction(function () use ($cashbox, $user, $openingBalance, $notes) {
            // Locking the cashbox and user serializes concurrent open-shift requests.
            $lockedCashbox = Cashbox::query()->lockForUpdate()->findOrFail($cashbox->getKey());
            User::query()->lockForUpdate()->findOrFail($user->getKey());

            if ($lockedCashbox->isInactive()) {
                throw new DomainException('Cashbox is inactive.');
            }

            if (CashierShift::query()
                ->where('cashbox_id', $lockedCashbox->id)
                ->where('status', 'open')
                ->lockForUpdate()
                ->exists()) {
                throw new DomainException('This cashbox already has an open shift.');
            }

            if (CashierShift::query()
                ->where('opened_by', $user->id)
                ->where('status', 'open')
                ->lockForUpdate()
                ->exists()) {
                throw new DomainException('This user already has an open shift.');
            }

            $shift = CashierShift::create([
                'cashbox_id' => $lockedCashbox->id,
                'opened_by' => $user->id,
                'status' => 'open',
                'opened_at' => now(),
                'opening_balance' => $openingBalance,
                'expected_cash' => 0,
                'notes' => $notes,
            ]);

            $this->createMovement(
                $shift,
                'opening',
                'in',
                $openingBalance,
                'Opening balance',
                now(),
                $user,
                [],
                true
            );

            $this->refreshExpectedCash($shift);

            return $shift->fresh('movements');
        });
    }

    public function addCashMovement(
        CashierShift $shift,
        string $type,
        string $direction,
        mixed $amount,
        ?string $description = null,
        mixed $occurredAt = null,
        User|int|null $user = null,
        array $metadata = []
    ): CashMovement {
        $user = $user === null ? null : $this->resolveUser($user);
        $amount = $this->normalizePositiveAmount($amount);

        return DB::transaction(function () use ($shift, $type, $direction, $amount, $description, $occurredAt, $user, $metadata) {
            $lockedShift = CashierShift::query()->lockForUpdate()->findOrFail($shift->getKey());

            $this->ensureShiftIsOpen($lockedShift);
            $this->assertAllowedManualMovement($type, $direction);
            $this->assertCashOutDoesNotGoNegative($lockedShift, $direction, $amount);

            $movement = $this->createMovement(
                $lockedShift,
                $type,
                $direction,
                $amount,
                $description,
                $occurredAt ?: now(),
                $user,
                array_replace(['accounting_pending' => true], $metadata)
            );

            $this->refreshExpectedCash($lockedShift);

            return $movement;
        });
    }

    /**
     * Records the cash side of a completed POS sale. Manual cash movement APIs
     * intentionally cannot create this reserved movement type.
     */
    public function recordSaleMovementForOrder(Order $order, CashierShift $shift, User|int $user): CashMovement
    {
        $user = $this->resolveUser($user);

        return DB::transaction(function () use ($order, $shift, $user) {
            $lockedShift = CashierShift::query()->lockForUpdate()->findOrFail($shift->getKey());

            $this->ensureShiftIsOpen($lockedShift);

            if (! $order->isPosOrder() || (int) $order->cashier_shift_id !== (int) $lockedShift->id) {
                throw new DomainException('Order is not assigned to this POS shift.');
            }

            if ((int) $order->cashbox_id !== (int) $lockedShift->cashbox_id) {
                throw new DomainException('Order cashbox does not match the POS shift.');
            }

            $existing = CashMovement::query()
                ->where('reference_type', Order::class)
                ->where('reference_id', $order->id)
                ->where('movement_type', 'sale')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $amount = $this->normalizePositiveAmount($order->grand_total);
            $movement = $this->createMovement(
                $lockedShift,
                'sale',
                'in',
                $amount,
                'POS sale ' . ($order->pos_receipt_number ?: $order->code),
                now(),
                $user,
                [
                    'pos_receipt_number' => $order->pos_receipt_number,
                    'pos_request_key' => $order->pos_request_key,
                    'payment_type' => 'cash',
                    'accounting_pending' => true,
                ],
                false,
                Order::class,
                $order->id
            );

            $this->refreshExpectedCash($lockedShift);

            return $movement;
        });
    }

    public function calculateExpectedCash(CashierShift $shift): float
    {
        return (float) CashMovement::query()
            ->where('cashier_shift_id', $shift->id)
            ->get(['direction', 'amount'])
            ->sum(function (CashMovement $movement) {
                return match ($movement->direction) {
                    'in' => (float) $movement->amount,
                    'out' => -1 * (float) $movement->amount,
                    default => 0,
                };
            });
    }

    public function closeShift(
        CashierShift $shift,
        mixed $actualCash,
        ?string $closeNotes,
        User|int $user
    ): CashierShift {
        $user = $this->resolveUser($user);
        $actualCash = $this->normalizeNonNegativeAmount($actualCash, 'Actual cash must not be negative.');

        return DB::transaction(function () use ($shift, $actualCash, $closeNotes, $user) {
            $lockedShift = CashierShift::query()->lockForUpdate()->findOrFail($shift->getKey());

            if ($lockedShift->isClosed()) {
                throw new DomainException('Cannot close shift twice.');
            }

            $this->ensureShiftIsOpen($lockedShift);

            $expectedCash = $this->calculateExpectedCash($lockedShift);
            $difference = round($actualCash - $expectedCash, 6);

            $lockedShift->update([
                'status' => 'closed',
                'closed_by' => $user->id,
                'closed_at' => now(),
                'expected_cash' => $expectedCash,
                'actual_cash' => $actualCash,
                'cash_difference' => $difference,
                'close_notes' => $closeNotes,
            ]);

            if (abs($difference) > 0.000001) {
                $this->createMovement(
                    $lockedShift,
                    'closing_difference',
                    'neutral',
                    abs($difference),
                    'Cash closing difference',
                    now(),
                    $user,
                    [
                        'accounting_pending' => true,
                        'difference_sign' => $difference > 0 ? 'positive' : 'negative',
                    ],
                    true
                );
            }

            return $lockedShift->fresh('movements');
        });
    }

    public function dashboardStats(): array
    {
        $openShifts = CashierShift::query()->where('status', 'open');

        return [
            'active_cashboxes' => Cashbox::query()->where('status', 'active')->count(),
            'open_shifts' => (clone $openShifts)->count(),
            'closed_shifts_today' => CashierShift::query()
                ->where('status', 'closed')
                ->whereDate('closed_at', today())
                ->count(),
            'expected_cash_total' => (float) (clone $openShifts)->sum('expected_cash'),
            'latest_movements' => CashMovement::query()
                ->with(['cashbox', 'shift'])
                ->latest('occurred_at')
                ->limit(10)
                ->get(),
        ];
    }

    public function shiftRows(array $filters = []): Builder
    {
        return CashierShift::query()
            ->with(['cashbox', 'opener', 'closer'])
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['cashbox_id'] ?? null, fn (Builder $query, mixed $cashboxId) => $query->where('cashbox_id', $cashboxId))
            ->when($filters['opened_by'] ?? null, fn (Builder $query, mixed $openedBy) => $query->where('opened_by', $openedBy))
            ->when($filters['date_from'] ?? null, fn (Builder $query, mixed $dateFrom) => $query->whereDate('opened_at', '>=', $dateFrom))
            ->when($filters['date_to'] ?? null, fn (Builder $query, mixed $dateTo) => $query->whereDate('opened_at', '<=', $dateTo))
            ->when($filters['open_only'] ?? false, fn (Builder $query) => $query->where('status', 'open'))
            ->when($filters['has_difference'] ?? false, fn (Builder $query) => $query->whereNotNull('cash_difference')->where('cash_difference', '!=', 0))
            ->latest('opened_at');
    }

    public function movementRows(array $filters = []): Builder
    {
        return CashMovement::query()
            ->with(['cashbox', 'shift', 'creator'])
            ->when($filters['cashbox_id'] ?? null, fn (Builder $query, mixed $cashboxId) => $query->where('cashbox_id', $cashboxId))
            ->when($filters['cashier_shift_id'] ?? null, fn (Builder $query, mixed $shiftId) => $query->where('cashier_shift_id', $shiftId))
            ->when($filters['movement_type'] ?? null, fn (Builder $query, string $type) => $query->where('movement_type', $type))
            ->when($filters['direction'] ?? null, fn (Builder $query, string $direction) => $query->where('direction', $direction))
            ->when($filters['date_from'] ?? null, fn (Builder $query, mixed $dateFrom) => $query->whereDate('occurred_at', '>=', $dateFrom))
            ->when($filters['date_to'] ?? null, fn (Builder $query, mixed $dateTo) => $query->whereDate('occurred_at', '<=', $dateTo))
            ->when($filters['created_by'] ?? null, fn (Builder $query, mixed $createdBy) => $query->where('created_by', $createdBy))
            ->latest('occurred_at');
    }

    // Compatibility wrappers for the current WIP controller. They can be removed when it uses the explicit API.
    public function movement(
        CashierShift $shift,
        string $type,
        string $direction,
        mixed $amount,
        ?string $description,
        User|int|null $user = null
    ): CashMovement {
        return $this->addCashMovement($shift, $type, $direction, $amount, $description, null, $user);
    }

    public function expected(CashierShift $shift): float
    {
        return $this->calculateExpectedCash($shift);
    }

    public function close(
        CashierShift $shift,
        mixed $actualCash,
        ?string $closeNotes,
        User|int $user
    ): CashierShift {
        return $this->closeShift($shift, $actualCash, $closeNotes, $user);
    }

    private function normalizeCashboxPayload(array $payload): array
    {
        $allowed = ['name', 'code', 'location', 'currency', 'status', 'assigned_user_id', 'metadata'];
        $payload = array_intersect_key($payload, array_flip($allowed));

        if (isset($payload['status']) && ! in_array($payload['status'], ['active', 'inactive'], true)) {
            throw new DomainException('Cashbox status must be active or inactive.');
        }

        return $payload;
    }

    private function normalizeNonNegativeAmount(mixed $amount, string $message): float
    {
        if (! is_numeric($amount) || (float) $amount < 0) {
            throw new DomainException($message);
        }

        return round((float) $amount, 6);
    }

    private function normalizePositiveAmount(mixed $amount): float
    {
        if (! is_numeric($amount) || (float) $amount <= 0) {
            throw new DomainException('Cash movement amount must be greater than zero.');
        }

        return round((float) $amount, 6);
    }

    private function ensureShiftIsOpen(CashierShift $shift): void
    {
        if (! $shift->isOpen()) {
            throw new DomainException('Shift is already closed.');
        }
    }

    private function assertAllowedManualMovement(string $type, string $direction): void
    {
        if (! in_array($type, self::MANUAL_MOVEMENT_TYPES, true)) {
            throw new DomainException('This cash movement type cannot be created manually.');
        }

        if (! in_array($direction, self::DIRECTIONS, true)) {
            throw new DomainException('Cash movement direction is invalid.');
        }

        if (($type === 'cash_in' && $direction !== 'in') || ($type === 'cash_out' && $direction !== 'out')) {
            throw new DomainException('Cash movement direction does not match its type.');
        }
    }

    private function assertCashOutDoesNotGoNegative(CashierShift $shift, string $direction, float $amount): void
    {
        if ($direction === 'out' && $amount > $this->calculateExpectedCash($shift)) {
            throw new DomainException('Cash out cannot exceed expected cash.');
        }
    }

    private function refreshExpectedCash(CashierShift $shift): float
    {
        $expectedCash = $this->calculateExpectedCash($shift);
        $shift->update(['expected_cash' => $expectedCash]);

        return $expectedCash;
    }

    private function createMovement(
        CashierShift $shift,
        string $type,
        string $direction,
        float $amount,
        ?string $description,
        mixed $occurredAt,
        ?User $user,
        array $metadata = [],
        bool $allowZeroAmount = false,
        ?string $referenceType = null,
        ?int $referenceId = null
    ): CashMovement {
        if ($amount < 0 || (! $allowZeroAmount && $amount === 0.0)) {
            throw new DomainException('Cash movement amount must be greater than zero.');
        }

        return CashMovement::create([
            'cashbox_id' => $shift->cashbox_id,
            'cashier_shift_id' => $shift->id,
            'movement_type' => $type,
            'direction' => $direction,
            'amount' => $amount,
            'currency' => $shift->cashbox()->value('currency'),
            'description' => $description,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'created_by' => $user?->id,
            'occurred_at' => $occurredAt,
            'metadata' => $metadata,
        ]);
    }

    private function resolveUser(User|int $user): User
    {
        return $user instanceof User ? $user : User::query()->findOrFail($user);
    }
}
