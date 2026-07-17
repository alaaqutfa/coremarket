<?php

namespace App\Services;

use App\Models\CashierShift;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WebPosService
{
    public function __construct(
        private CashboxService $cashboxes,
        private InventoryMovementService $inventory,
        private ProductIdentityLookupService $identityLookup,
    ) {
    }

    public function requireOpenShift(User $user): CashierShift
    {
        return $this->openShiftForUser($user);
    }

    public function searchProducts(string $query): Collection
    {
        $query = trim($query);
        if ($query === '') {
            return collect();
        }

        if ($identity = $this->identityLookup->find($query)) {
            return collect([$this->lineForProductStock($identity['product'], $identity['product_stock'], $identity['matched_by'])]);
        }

        return Product::query()
            ->with(['stocks', 'taxes'])
            ->where('name', 'like', '%' . $query . '%')
            ->orderBy('name')
            ->limit(30)
            ->get()
            ->flatMap(function (Product $product) {
                $stocks = $product->stocks;

                if ($stocks->isEmpty()) {
                    return [$this->lineForProductStock($product, null, 'name')];
                }

                return $stocks->map(fn (ProductStock $stock) => $this->lineForProductStock($product, $stock, 'name'));
            })
            ->values();
    }

    public function buildCartLine(Product|ProductStock $subject, mixed $quantity): array
    {
        $stock = $subject instanceof ProductStock
            ? $subject->loadMissing('product.taxes')
            : $subject->stocks()->orderBy('id')->first();
        $product = $subject instanceof ProductStock ? $stock->product : $subject->loadMissing('taxes');

        if (! $product) {
            throw new DomainException('Product stock does not have a product.');
        }

        $quantity = $this->normalizeQuantity($quantity);
        $unitPrice = $this->unitPrice($product, $stock);
        $unitTax = $this->legacyTaxForUnit($product, $unitPrice);

        return [
            'product_id' => $product->id,
            'product_stock_id' => $stock?->id,
            'variation' => $stock?->variant ?? '',
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_price' => $this->round($unitPrice * $quantity),
            'tax' => $this->round($unitTax * $quantity),
            'available_stock' => $stock ? (float) $stock->qty : (float) $product->current_stock,
            'tax_source' => 'legacy_product_taxes',
        ];
    }

    public function validateCartStock(array $cart): array
    {
        $lines = $this->normalizeCart($cart);

        foreach ($lines as $line) {
            if (! $line['product']->digital && $line['quantity'] > (float) $line['stock']->qty) {
                throw new DomainException('Requested quantity exceeds available stock.');
            }
        }

        return $lines;
    }

    public function calculateTotals(array $cart): array
    {
        $lines = $this->normalizeCart($cart);

        return [
            'subtotal' => $this->round(collect($lines)->sum('line_price')),
            'tax' => $this->round(collect($lines)->sum('tax')),
            'discount' => 0.0,
            'grand_total' => $this->round(collect($lines)->sum(fn (array $line) => $line['line_price'] + $line['tax'])),
        ];
    }

    public function createPosOrder(array $cart, array $payment, User $user, ?string $requestKey = null): Order
    {
        $requestKey = trim((string) ($requestKey ?: Str::uuid()));
        if ($requestKey === '') {
            throw new DomainException('POS request key is required.');
        }

        try {
            return DB::transaction(function () use ($cart, $payment, $user, $requestKey) {
                $existing = Order::query()->where('pos_request_key', $requestKey)->lockForUpdate()->first();
                if ($existing) {
                    return $existing;
                }

                $shift = $this->openShiftForUser($user, true);
                $lines = $this->normalizeCart($cart, true);
                $this->assertStockIsAvailable($lines);
                $totals = $this->totalsForLines($lines);
                $paidAmount = $this->validateCashPayment($payment, $totals['grand_total']);

                $order = $this->createOrder($lines, $shift, $user, $requestKey, $totals, $paidAmount);

                foreach ($lines as $line) {
                    $detail = $this->createOrderDetail($order, $line);
                    $this->deductStock($line);
                    $this->inventory->recordSale($detail, $line['stock'], $user->id);
                }

                $this->cashboxes->recordSaleMovementForOrder($order, $shift, $user);

                return $order->fresh(['orderDetails', 'cashierShift', 'cashbox', 'cashier']);
            });
        } catch (QueryException $exception) {
            $existing = Order::query()->where('pos_request_key', $requestKey)->first();

            if ($existing) {
                return $existing;
            }

            throw $exception;
        }
    }

    public function generateReceiptNumber(): string
    {
        do {
            $receipt = 'POS-' . now()->format('Ymd-His') . '-' . Str::upper(Str::random(8));
        } while (Order::query()->where('pos_receipt_number', $receipt)->exists());

        return $receipt;
    }

    private function openShiftForUser(User $user, bool $lock = false): CashierShift
    {
        $query = CashierShift::query()
            ->where('opened_by', $user->id)
            ->where('status', 'open')
            ->latest('opened_at');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first() ?? throw new DomainException('An open cashier shift is required for POS sales.');
    }

    private function normalizeCart(array $cart, bool $lockStocks = false): array
    {
        if ($cart === []) {
            throw new DomainException('POS cart cannot be empty.');
        }

        $requested = collect($cart)->map(function (array $line) {
            $stockId = $line['product_stock_id'] ?? null;
            if (! $stockId) {
                throw new DomainException('POS cart line requires a product stock.');
            }

            return ['product_stock_id' => (int) $stockId, 'quantity' => $this->normalizeQuantity($line['quantity'] ?? null)];
        });

        $quantities = $requested->groupBy('product_stock_id')->map(fn (Collection $lines) => $lines->sum('quantity'));
        $stocksQuery = ProductStock::query()->with('product.taxes')->whereIn('id', $quantities->keys()->all())->orderBy('id');
        if ($lockStocks) {
            $stocksQuery->lockForUpdate();
        }
        $stocks = $stocksQuery->get()->keyBy('id');

        if ($stocks->count() !== $quantities->count()) {
            throw new DomainException('One or more POS products are unavailable.');
        }

        $productIds = $stocks->pluck('product_id')->unique()->values();
        if ($lockStocks) {
            // Lock every variant of involved products before deciding whether current_stock is a mirror.
            ProductStock::query()->whereIn('product_id', $productIds)->lockForUpdate()->get();
            Product::query()->whereIn('id', $productIds)->lockForUpdate()->get();
        }

        return $quantities->map(function (float $quantity, int $stockId) use ($stocks) {
            $stock = $stocks->get($stockId);
            $product = $stock->product;
            $unitPrice = $this->unitPrice($product, $stock);
            $unitTax = $this->legacyTaxForUnit($product, $unitPrice);

            return [
                'product' => $product,
                'stock' => $stock,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_price' => $this->round($unitPrice * $quantity),
                'tax' => $this->round($unitTax * $quantity),
                'variation' => $stock->variant ?? '',
            ];
        })->values()->all();
    }

    private function assertStockIsAvailable(array $lines): void
    {
        foreach ($lines as $line) {
            if (! $line['product']->digital && $line['quantity'] > (float) $line['stock']->qty) {
                throw new DomainException('Requested quantity exceeds available stock.');
            }
        }
    }

    private function totalsForLines(array $lines): array
    {
        return [
            'subtotal' => $this->round(collect($lines)->sum('line_price')),
            'tax' => $this->round(collect($lines)->sum('tax')),
            'discount' => 0.0,
            'grand_total' => $this->round(collect($lines)->sum(fn (array $line) => $line['line_price'] + $line['tax'])),
        ];
    }

    private function validateCashPayment(array $payment, float $grandTotal): float
    {
        if (($payment['payment_type'] ?? 'cash') !== 'cash') {
            throw new DomainException('Web POS foundation supports cash payments only.');
        }

        if ($grandTotal <= 0) {
            throw new DomainException('POS total must be greater than zero for a cash sale.');
        }

        $paidAmount = $payment['paid_amount'] ?? null;
        if (! is_numeric($paidAmount) || (float) $paidAmount < $grandTotal) {
            throw new DomainException('Paid cash must cover the POS total.');
        }

        return $this->round($paidAmount);
    }

    private function createOrder(array $lines, CashierShift $shift, User $user, string $requestKey, array $totals, float $paidAmount): Order
    {
        $sellerIds = collect($lines)->pluck('product.user_id')->filter()->unique();
        if ($sellerIds->count() > 1) {
            throw new DomainException('A POS cart cannot contain products from multiple sellers.');
        }

        $receipt = $this->generateReceiptNumber();
        $order = new Order();
        $order->user_id = null;
        $order->seller_id = $sellerIds->first();
        $order->shipping_type = 'pos';
        $order->order_from = 'pos';
        $order->delivery_status = 'delivered';
        $order->payment_type = 'cash';
        $order->payment_status = 'paid';
        $order->payment_details = json_encode(['method' => 'cash', 'paid_amount' => $paidAmount]);
        $order->grand_total = $totals['grand_total'];
        $order->coupon_discount = 0;
        $order->code = $receipt;
        $order->date = now()->timestamp;
        $order->cashier_shift_id = $shift->id;
        $order->cashbox_id = $shift->cashbox_id;
        $order->cashier_id = $user->id;
        $order->paid_amount = $paidAmount;
        $order->change_amount = $this->round($paidAmount - $totals['grand_total']);
        $order->pos_receipt_number = $receipt;
        $order->pos_request_key = $requestKey;
        $order->pos_metadata = [
            'tax_source' => 'legacy_product_taxes',
            'cashier_shift_id' => $shift->id,
            'cashbox_id' => $shift->cashbox_id,
        ];
        $order->save();

        return $order;
    }

    private function createOrderDetail(Order $order, array $line): OrderDetail
    {
        $product = $line['product'];
        $costPrice = is_numeric($product->purchase_price) ? $this->round($product->purchase_price) : null;
        $totalCost = $costPrice === null ? null : $this->round($costPrice * $line['quantity']);

        $detail = new OrderDetail();
        $detail->order_id = $order->id;
        $detail->seller_id = $product->user_id;
        $detail->product_id = $product->id;
        $detail->variation = $line['variation'];
        $detail->price = $line['line_price'];
        $detail->tax = $line['tax'];
        $detail->shipping_cost = 0;
        $detail->quantity = $line['quantity'];
        $detail->payment_status = 'paid';
        $detail->delivery_status = 'delivered';
        $detail->shipping_type = 'pos';
        $detail->cost_price = $costPrice;
        $detail->cost_source = $costPrice === null ? 'missing' : 'product_purchase_price';
        $detail->total_cost = $totalCost;
        $detail->profit_amount = $totalCost === null ? null : $this->round($line['line_price'] - $totalCost);
        $detail->profit_calculated_at = now();
        $detail->save();

        return $detail;
    }

    private function deductStock(array $line): void
    {
        $product = $line['product'];
        if ($product->digital) {
            return;
        }

        $stock = $line['stock'];
        $stockTotalBefore = (float) ProductStock::query()->where('product_id', $product->id)->sum('qty');
        $stock->decrement('qty', $line['quantity']);

        if ((float) $product->current_stock === $stockTotalBefore) {
            $product->decrement('current_stock', $line['quantity']);
        }
    }

    private function lineForProductStock(Product $product, ?ProductStock $stock, string $matchedBy): array
    {
        return [
            'product_id' => $product->id,
            'product_stock_id' => $stock?->id,
            'name' => $product->name,
            'variation' => $stock?->variant ?? '',
            'sku' => $stock?->sku,
            'barcode' => $stock?->barcode ?? $product->barcode,
            'available_stock' => $stock ? (float) $stock->qty : (float) $product->current_stock,
            'price' => $this->unitPrice($product, $stock),
            'taxes' => $product->taxes->map(fn ($tax) => ['type' => $tax->tax_type, 'value' => (float) $tax->tax])->values()->all(),
            'matched_by' => $matchedBy,
        ];
    }

    private function unitPrice(Product $product, ?ProductStock $stock): float
    {
        $price = $stock?->price;
        if (! is_numeric($price)) {
            $price = $product->unit_price;
        }

        if (! is_numeric($price) || (float) $price < 0) {
            throw new DomainException('Product price is invalid for POS sale.');
        }

        return $this->round($price);
    }

    private function legacyTaxForUnit(Product $product, float $price): float
    {
        return $this->round($product->taxes->sum(function ($tax) use ($price) {
            return match ($tax->tax_type) {
                'percent' => $price * ((float) $tax->tax / 100),
                'amount' => (float) $tax->tax,
                default => 0,
            };
        }));
    }

    private function normalizeQuantity(mixed $quantity): int
    {
        if (! is_numeric($quantity) || (float) $quantity <= 0 || floor((float) $quantity) !== (float) $quantity) {
            throw new DomainException('POS quantity must be greater than zero.');
        }

        return (int) $quantity;
    }

    private function round(mixed $amount): float
    {
        return round((float) $amount, 6);
    }
}
