<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\StoreBranch;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CoreMarketDeliveryService
{
    public const STATUSES = [
        'pending_assignment',
        'assigned',
        'picked_up',
        'out_for_delivery',
        'delivered',
        'failed',
        'returned',
        'cancelled',
    ];

    private const TRANSITIONS = [
        'pending_assignment' => ['assigned', 'cancelled'],
        'assigned' => ['picked_up', 'cancelled'],
        'picked_up' => ['out_for_delivery'],
        'out_for_delivery' => ['delivered', 'failed'],
        'failed' => ['assigned', 'returned'],
        'delivered' => [],
        'returned' => [],
        'cancelled' => [],
    ];

    public function __construct(private CoreMarketBranchService $branches)
    {
    }

    public function ensureDeliveryForOrder(Order $order): OrderDelivery
    {
        return DB::transaction(function () use ($order) {
            $existing = OrderDelivery::query()->where('order_id', $order->id)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }

            $isCod = $order->payment_type === 'cash_on_delivery';
            $branch = $this->branches->defaultBranch();
            $delivery = OrderDelivery::query()->create([
                'order_id' => $order->id,
                'branch_id' => $branch?->id,
                'status' => 'pending_assignment',
                'cod_amount' => $isCod ? app(CoreMarketMoneyService::class)->normalizeMoney($order->grand_total) : null,
                'cod_collected_amount' => $isCod ? 0 : null,
                'cod_collection_status' => $isCod ? 'pending' : 'not_required',
                'metadata' => [
                    'source' => 'coremarket_delivery_foundation',
                    'payment_type_snapshot' => $order->payment_type,
                ],
            ]);
            $this->recordEvent($delivery, null, 'pending_assignment', 'Delivery record created.');

            return $delivery;
        });
    }

    public function assignDeliveryUser(Order $order, User $deliveryUser, ?User $actor = null): OrderDelivery
    {
        if (! $deliveryUser->hasRole('delivery_distribution')) {
            throw new DomainException('The selected user is not an approved delivery employee.');
        }

        $delivery = $this->ensureDeliveryForOrder($order);
        if ($this->branches->branchesEnabled() && $delivery->branch_id && ! $this->branches->userHasAllBranches($deliveryUser)) {
            $assigned = $deliveryUser->branches()->whereKey($delivery->branch_id)->exists();
            if (! $assigned) {
                throw new DomainException('The selected delivery employee is not assigned to this branch.');
            }
        }

        return DB::transaction(function () use ($delivery, $deliveryUser, $actor, $order) {
            $locked = OrderDelivery::query()->lockForUpdate()->findOrFail($delivery->id);
            $oldStatus = $locked->status;
            if (! in_array($oldStatus, ['pending_assignment', 'failed', 'assigned'], true)) {
                throw new DomainException('This delivery cannot be reassigned in its current status.');
            }

            $locked->forceFill([
                'delivery_user_id' => $deliveryUser->id,
                'status' => 'assigned',
                'assigned_at' => now(),
                'failure_reason' => null,
            ])->save();

            // Keep the legacy assignment field synchronized without invoking the incomplete add-on workflow.
            if (Schema::hasColumn('orders', 'assign_delivery_boy')) {
                $order->forceFill(['assign_delivery_boy' => $deliveryUser->id])->save();
            }
            $this->recordEvent($locked, $oldStatus, 'assigned', 'Delivery employee assigned.', $actor);

            return $locked->fresh(['deliveryUser', 'branch']);
        });
    }

    public function availableDeliveryUsers(?StoreBranch $branch = null): Collection
    {
        $query = User::query()
            ->role('delivery_distribution')
            ->where('user_type', 'staff')
            ->where('banned', 0)
            ->orderBy('name');

        if ($branch && $this->branches->branchesEnabled()) {
            $query->whereHas('branches', fn ($branchQuery) => $branchQuery->whereKey($branch->id));
        }

        return $query->get(['users.id', 'users.name', 'users.email', 'users.phone']);
    }

    public function updateStatus(OrderDelivery $delivery, string $status, ?string $notes = null, ?User $actor = null): OrderDelivery
    {
        if (! in_array($status, self::STATUSES, true)) {
            throw new DomainException('Unsupported delivery status.');
        }

        return DB::transaction(function () use ($delivery, $status, $notes, $actor) {
            $locked = OrderDelivery::query()->with('order')->lockForUpdate()->findOrFail($delivery->id);
            $oldStatus = $locked->status;
            if (! in_array($status, self::TRANSITIONS[$oldStatus] ?? [], true)) {
                throw new DomainException("Delivery status cannot move from {$oldStatus} to {$status}.");
            }

            $changes = ['status' => $status, 'notes' => $notes ?: $locked->notes];
            if ($status === 'picked_up') {
                $changes['picked_up_at'] = now();
            } elseif ($status === 'out_for_delivery') {
                $changes['out_for_delivery_at'] = now();
            } elseif ($status === 'delivered') {
                $changes['delivered_at'] = now();
            } elseif ($status === 'failed') {
                $changes['failed_at'] = now();
                $changes['failure_reason'] = $notes;
            }

            if ($status === 'delivered' && $locked->cod_collection_status === 'pending') {
                $changes['cod_collection_status'] = 'pending';
            }

            $locked->forceFill($changes)->save();
            $this->syncLegacyOrderStatus($locked->order, $status);
            $this->recordEvent($locked, $oldStatus, $status, $notes, $actor);

            return $locked->fresh(['order', 'deliveryUser', 'branch']);
        });
    }

    public function collectCod(OrderDelivery $delivery, float $amount, ?User $actor = null): OrderDelivery
    {
        return DB::transaction(function () use ($delivery, $amount, $actor) {
            $locked = OrderDelivery::query()->lockForUpdate()->findOrFail($delivery->id);
            if ($locked->cod_collection_status === 'not_required' || $locked->cod_amount === null) {
                throw new DomainException('COD collection is not required for this delivery.');
            }

            $money = app(CoreMarketMoneyService::class);
            $collected = $money->normalizeMoney($amount);
            $required = $money->normalizeMoney($locked->cod_amount);
            if ($collected < 0 || $collected > $required) {
                throw new DomainException('Collected COD must be between zero and the required COD amount.');
            }

            $status = $collected <= 0 ? 'pending' : ($collected < $required ? 'partially_collected' : 'collected');
            $locked->forceFill([
                'cod_collected_amount' => $collected,
                'cod_collection_status' => $status,
            ])->save();
            $this->recordEvent($locked, $locked->status, $locked->status, 'COD collection updated.', $actor, [
                'cod_collected_amount' => $collected,
                'cod_collection_status' => $status,
            ]);

            return $locked->fresh();
        });
    }

    public function userCanAccessDelivery(User $user, OrderDelivery $delivery): bool
    {
        if ($user->user_type === 'admin' || $user->can('deliveries.view_all')) {
            return true;
        }

        if ($user->can('deliveries.view')) {
            return true;
        }

        return $user->can('deliveries.view_assigned')
            && (int) $delivery->delivery_user_id === (int) $user->id;
    }

    public function deliverySnapshot(OrderDelivery $delivery): array
    {
        $delivery->loadMissing(['order', 'deliveryUser', 'branch']);
        $address = json_decode((string) $delivery->order->shipping_address, true) ?: [];

        return [
            'id' => $delivery->id,
            'order_id' => $delivery->order_id,
            'order_code' => $delivery->order->code ?: (string) $delivery->order_id,
            'customer_name' => $address['name'] ?? $delivery->order->user?->name ?? 'Walk-in customer',
            'customer_phone' => $address['phone'] ?? null,
            'address' => $this->shortAddress($address),
            'status' => $delivery->status,
            'delivery_user' => $delivery->deliveryUser?->name,
            'branch' => $delivery->branch?->name,
            'cod_amount' => $delivery->cod_amount,
            'cod_collected_amount' => $delivery->cod_collected_amount,
            'cod_collection_status' => $delivery->cod_collection_status,
            'updated_at' => $delivery->updated_at,
        ];
    }

    public function allowedNextStatuses(OrderDelivery $delivery): array
    {
        return self::TRANSITIONS[$delivery->status] ?? [];
    }

    private function recordEvent(
        OrderDelivery $delivery,
        ?string $oldStatus,
        string $newStatus,
        ?string $notes = null,
        ?User $actor = null,
        array $metadata = []
    ): void {
        $delivery->events()->create([
            'user_id' => $actor?->id,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'notes' => $notes,
            'metadata' => $metadata ?: null,
            'created_at' => now(),
        ]);
    }

    private function syncLegacyOrderStatus(Order $order, string $status): void
    {
        $legacyStatus = match ($status) {
            'picked_up' => 'picked_up',
            'out_for_delivery' => 'on_the_way',
            'delivered' => 'delivered',
            'cancelled' => 'cancelled',
            default => null,
        };

        if ($legacyStatus !== null) {
            $order->forceFill(['delivery_status' => $legacyStatus])->save();
        }
    }

    private function shortAddress(array $address): ?string
    {
        $parts = array_filter([
            $address['address'] ?? null,
            $address['city'] ?? null,
            $address['state'] ?? null,
            $address['country'] ?? null,
        ], fn ($value) => filled($value));

        return $parts === [] ? null : implode(', ', $parts);
    }
}
