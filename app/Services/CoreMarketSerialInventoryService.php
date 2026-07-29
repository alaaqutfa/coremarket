<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\ProductSerialUnit;
use App\Models\ProductStock;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptItem;
use App\Models\SalesReturn;
use App\Models\StoreBranch;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CoreMarketSerialInventoryService
{
    public function __construct(private CoreMarketWarrantyService $warranties)
    {
    }

    public function serialTrackingEnabled(): bool
    {
        return $this->setting('inventory.serial_tracking_enabled');
    }

    public function imeiTrackingEnabled(): bool
    {
        return $this->serialTrackingEnabled() && $this->setting('inventory.imei_tracking_enabled');
    }

    public function advancedVariantsEnabled(): bool
    {
        return $this->setting('catalog.advanced_variants_enabled');
    }

    public function stockRequiresSerial(ProductStock $stock): bool
    {
        return $this->serialTrackingEnabled() && (bool) $stock->serial_tracking_enabled;
    }

    public function createSerialUnitsFromPurchase(
        PurchaseReceipt $receipt,
        PurchaseReceiptItem $receiptItem,
        ProductStock $stock,
        StoreBranch $branch,
        array $units
    ): Collection {
        if (! $this->stockRequiresSerial($stock)) {
            if ($units !== []) {
                throw new DomainException('Serial units can be received only for a serial-tracked variant.');
            }

            return collect();
        }

        $quantity = (float) $receiptItem->quantity_received;
        if (floor($quantity) !== $quantity || count($units) !== (int) $quantity) {
            throw new DomainException('Serial unit count must equal the received quantity.');
        }

        return DB::transaction(function () use ($receipt, $receiptItem, $stock, $branch, $units) {
            return collect($units)->map(function (array|string $payload) use ($receipt, $receiptItem, $stock, $branch) {
                $payload = is_string($payload) ? ['serial_number' => $payload] : $payload;
                $identity = $this->normalizedIdentity($payload, (bool) $stock->imei_tracking_enabled);
                $this->preventDuplicateSerial($identity);

                return ProductSerialUnit::query()->create([
                    'product_id' => $stock->product_id,
                    'product_stock_id' => $stock->id,
                    'store_branch_id' => $branch->id,
                    ...$identity,
                    'status' => 'in_stock',
                    'purchase_order_id' => $receipt->purchase_order_id,
                    'purchase_receipt_id' => $receipt->id,
                    'metadata' => ['purchase_receipt_item_id' => $receiptItem->id],
                ]);
            });
        });
    }

    public function validateSerialAvailability(ProductStock $stock, array $serialUnitIds, int|float $quantity, StoreBranch $branch): Collection
    {
        if (! $this->stockRequiresSerial($stock)) {
            if ($serialUnitIds !== []) {
                throw new DomainException('Serial units were supplied for a variant that is not serial tracked.');
            }

            return collect();
        }
        if (floor((float) $quantity) !== (float) $quantity || count(array_unique($serialUnitIds)) !== (int) $quantity) {
            throw new DomainException('Selected serial unit count must equal the sale quantity.');
        }

        $units = ProductSerialUnit::query()
            ->whereIn('id', $serialUnitIds)
            ->lockForUpdate()
            ->get();
        if ($units->count() !== count($serialUnitIds)) {
            throw new DomainException('One or more selected serial units do not exist.');
        }
        foreach ($units as $unit) {
            if (
                (int) $unit->product_stock_id !== (int) $stock->id
                || $unit->status !== 'in_stock'
                || ($unit->store_branch_id && (int) $unit->store_branch_id !== (int) $branch->id)
            ) {
                throw new DomainException('Selected serial unit is not available for this variant and branch.');
            }
        }

        return $units;
    }

    public function reserveSerialForSale(ProductStock $stock, array $serialUnitIds, int|float $quantity, StoreBranch $branch): Collection
    {
        $units = $this->validateSerialAvailability($stock, $serialUnitIds, $quantity, $branch);
        $units->each(fn (ProductSerialUnit $unit) => $unit->forceFill(['status' => 'reserved'])->save());

        return $units;
    }

    public function markSerialSold(Collection $units, Order $order, OrderDetail $detail): void
    {
        foreach ($units as $unit) {
            if ($unit->status !== 'reserved') {
                throw new DomainException('Only a reserved serial unit can be sold.');
            }
            $expiry = $this->warranties->warrantyExpiryForSale($unit->product_id, $unit->product_stock_id, $order->created_at);
            $unit->forceFill([
                'status' => 'sold',
                'order_id' => $order->id,
                'order_detail_id' => $detail->id,
                'warranty_expires_at' => $expiry,
            ])->save();
        }
    }

    public function markSerialReturned(SalesReturn $salesReturn, OrderDetail $detail, array $serialUnitIds): Collection
    {
        $quantity = (int) $salesReturn->items()->where('order_detail_id', $detail->id)->sum('quantity');
        if (count(array_unique($serialUnitIds)) !== $quantity) {
            throw new DomainException('Returned serial unit count must equal the serialized return quantity.');
        }
        $units = ProductSerialUnit::query()->whereIn('id', $serialUnitIds)->lockForUpdate()->get();
        if ($units->count() !== count($serialUnitIds)) {
            throw new DomainException('One or more returned serial units do not exist.');
        }
        foreach ($units as $unit) {
            if ((int) $unit->order_detail_id !== (int) $detail->id || $unit->status !== 'sold') {
                throw new DomainException('Returned serial unit is not linked to the original order item.');
            }
            $unit->forceFill([
                'status' => 'in_stock',
                'sales_return_id' => $salesReturn->id,
                'order_id' => null,
                'order_detail_id' => null,
            ])->save();
        }

        return $units;
    }

    public function validateSerialReturn(OrderDetail $detail, array $serialUnitIds, int|float $quantity): Collection
    {
        $stock = ProductStock::query()
            ->where('product_id', $detail->product_id)
            ->where('variant', $detail->variation ?? '')
            ->first();
        if (! $stock || ! $this->stockRequiresSerial($stock)) {
            if ($serialUnitIds !== []) {
                throw new DomainException('Serial units were supplied for an item that is not serial tracked.');
            }

            return collect();
        }
        if (floor((float) $quantity) !== (float) $quantity || count(array_unique($serialUnitIds)) !== (int) $quantity) {
            throw new DomainException('Returned serial unit count must equal the serialized return quantity.');
        }

        $units = ProductSerialUnit::query()->whereIn('id', $serialUnitIds)->lockForUpdate()->get();
        if ($units->count() !== count($serialUnitIds)) {
            throw new DomainException('One or more returned serial units do not exist.');
        }
        foreach ($units as $unit) {
            if ((int) $unit->order_detail_id !== (int) $detail->id || $unit->status !== 'sold') {
                throw new DomainException('Returned serial unit is not linked to the original order item.');
            }
        }

        return $units;
    }

    public function findBySerialOrImei(string $identity): ?ProductSerialUnit
    {
        $identity = trim($identity);
        return ProductSerialUnit::query()
            ->where('serial_number', $identity)
            ->orWhere('imei_1', $identity)
            ->orWhere('imei_2', $identity)
            ->first();
    }

    public function preventDuplicateSerial(array $identity): void
    {
        foreach (['serial_number', 'imei_1', 'imei_2'] as $field) {
            if ($identity[$field] && ProductSerialUnit::query()->where($field, $identity[$field])->exists()) {
                throw new DomainException(ucwords(str_replace('_', ' ', $field)).' is already registered.');
            }
        }
    }

    public function warrantySnapshot(ProductSerialUnit $unit): array
    {
        return $this->warranties->claimSnapshot($unit);
    }

    public function assertWebCheckoutAllowed(Collection $carts): void
    {
        if (! $this->serialTrackingEnabled()) {
            return;
        }

        foreach ($carts as $cart) {
            $stock = ProductStock::query()
                ->where('product_id', $cart->product_id)
                ->when(
                    filled($cart->variation),
                    fn ($query) => $query->where('variant', $cart->variation),
                    fn ($query) => $query->where(fn ($nested) => $nested->whereNull('variant')->orWhere('variant', ''))
                )
                ->first();
            if ($stock && $this->stockRequiresSerial($stock)) {
                throw new DomainException('Serialized products currently require an assisted POS sale.');
            }
        }
    }

    private function normalizedIdentity(array $payload, bool $stockRequiresImei): array
    {
        $identity = [
            'serial_number' => $this->nullable($payload['serial_number'] ?? null),
            'imei_1' => $this->nullable($payload['imei_1'] ?? null),
            'imei_2' => $this->nullable($payload['imei_2'] ?? null),
            'barcode' => $this->nullable($payload['barcode'] ?? null),
        ];
        if (! $identity['serial_number'] && ! $identity['imei_1'] && ! $identity['imei_2']) {
            throw new DomainException('A serial number or IMEI is required for each serialized unit.');
        }
        if ($this->imeiTrackingEnabled() && $stockRequiresImei && ! $identity['imei_1']) {
            throw new DomainException('IMEI 1 is required while IMEI tracking is enabled.');
        }

        return $identity;
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function setting(string $key): bool
    {
        return filter_var(get_setting($key, config('coremarket.'.$key, false)), FILTER_VALIDATE_BOOL);
    }
}
