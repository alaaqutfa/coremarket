<?php

namespace App\Services;

use App\Models\CashierShift;
use App\Models\LoyaltyPointMovement;
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
        private LoyaltyPointsService $loyalty,
        private CoreMarketFeatureAccessService $features,
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

    public function searchCustomers(string $query, int $limit = 10): Collection
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return collect();
        }

        $limit = max(1, min($limit, 10));

        return User::query()
            ->where('user_type', 'customer')
            ->where('banned', 0)
            ->where(function ($customers) use ($query) {
                $customers->where('name', 'like', '%' . $query . '%')
                    ->orWhere('phone', 'like', '%' . $query . '%')
                    ->orWhere('email', 'like', '%' . $query . '%');
            })
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (User $customer) => $this->customerPayload($customer));
    }

    public function validatePosCustomer(?int $customerId): ?User
    {
        if ($customerId === null) {
            return null;
        }

        $customer = User::query()->find($customerId);
        if (! $customer || $customer->user_type !== 'customer' || $customer->banned) {
            throw new DomainException('Selected POS customer is unavailable.');
        }

        return $customer;
    }

    public function customerPayload(User $customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'masked_email' => $this->maskedEmail($customer->email),
            'loyalty_balance' => $this->features->enabled('loyalty_points')
                ? $this->loyalty->balanceForCustomerWithoutCreatingAccount($customer)
                : null,
        ];
    }

    public function loyaltySummaryForCustomer(?User $customer): array
    {
        $enabled = $this->features->enabled('loyalty_points');

        return [
            'enabled' => $enabled,
            'points_balance' => $enabled && $customer
                ? $this->loyalty->balanceForCustomerWithoutCreatingAccount($customer)
                : null,
        ];
    }

    public function currentSessionPayload(User $user): array
    {
        $shift = $this->cashboxes->currentOpenShiftForUser($user);

        if (! $shift) {
            return [
                'has_open_shift' => false,
                'shift' => null,
                'cashbox' => null,
                'opened_at' => null,
                'expected_cash' => null,
            ];
        }

        return [
            'has_open_shift' => true,
            'shift' => [
                'id' => $shift->id,
                'status' => $shift->status,
                'opening_balance' => (float) $shift->opening_balance,
            ],
            'cashbox' => $shift->cashbox ? [
                'id' => $shift->cashbox->id,
                'name' => $shift->cashbox->name,
                'code' => $shift->cashbox->code,
                'currency' => $shift->cashbox->currency,
            ] : null,
            'opened_at' => $shift->opened_at?->toIso8601String(),
            'expected_cash' => $this->cashboxes->calculateExpectedCash($shift),
        ];
    }

    public function searchPayload(Collection $items): array
    {
        return $items->map(fn (array $item) => [
            'product_id' => $item['product_id'],
            'product_stock_id' => $item['product_stock_id'],
            'name' => $item['name'],
            'sku' => $item['sku'],
            'barcode' => $item['barcode'],
            'variation' => $item['variation'],
            'available_stock' => $item['available_stock'],
            'price' => $item['price'],
            'tax' => $item['taxes'],
            'image' => $item['image'] ?? null,
        ])->values()->all();
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

        $customerId = $this->customerIdFromPayment($payment);
        $pointsToRedeem = $this->pointsToRedeemFromPayment($payment);

        try {
            return DB::transaction(function () use ($cart, $payment, $user, $requestKey, $customerId, $pointsToRedeem) {
                $existing = Order::query()->where('pos_request_key', $requestKey)->lockForUpdate()->first();
                if ($existing) {
                    if ((int) ($existing->user_id ?? 0) !== (int) ($customerId ?? 0)) {
                        throw new DomainException('POS request key is already associated with a different customer.');
                    }
                    if ((int) $existing->loyalty_points_redeemed !== $pointsToRedeem) {
                        throw new DomainException('POS request key is already associated with a different loyalty redemption.');
                    }

                    return $existing;
                }

                $customer = $this->validatePosCustomer($customerId);
                $shift = $this->openShiftForUser($user, true);
                $lines = $this->normalizeCart($cart, true);
                $this->assertStockIsAvailable($lines);
                $totals = $this->totalsForLines($lines);
                $redemptionPreview = $this->previewRedemptionForCheckout($customer, $pointsToRedeem, $totals['grand_total']);
                $finalTotal = $redemptionPreview['final_total'] ?? $totals['grand_total'];
                $paidAmount = $this->validateCashPayment($payment, $finalTotal);

                $order = $this->createOrder($lines, $shift, $user, $customer, $requestKey, $totals, $paidAmount, $finalTotal);
                if ($pointsToRedeem > 0) {
                    $this->loyalty->redeemForOrder($order, $customer, $pointsToRedeem, $user);
                    $order->refresh();
                }

                foreach ($lines as $line) {
                    $detail = $this->createOrderDetail($order, $line);
                    $this->deductStock($line);
                    $this->inventory->recordSale($detail, $line['stock'], $user->id);
                }

                $this->cashboxes->recordSaleMovementForOrder($order, $shift, $user);
                $this->maybeAwardLoyaltyForPosOrder($order);

                return $order->fresh(['orderDetails', 'cashierShift', 'cashbox', 'cashier', 'user']);
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

    public function checkoutSummaryPayload(Order $order): array
    {
        $order->loadMissing('user');
        $finalTotal = (float) $order->grand_total;
        $grossTotal = $this->round($finalTotal + (float) ($order->loyalty_redemption_discount ?? 0));

        return [
            'order_id' => $order->id,
            'code' => $order->code,
            'receipt_number' => $order->pos_receipt_number,
            'gross_total' => $grossTotal,
            'grand_total' => $finalTotal,
            'final_total' => $finalTotal,
            'paid_amount' => (float) $order->paid_amount,
            'change_amount' => (float) $order->change_amount,
            'customer' => $order->user ? $this->customerPayload($order->user) : null,
            'loyalty' => $this->loyaltySummaryForOrder($order),
        ];
    }

    public function receiptPayload(Order $order): array
    {
        $order->loadMissing(['orderDetails.product.stocks', 'cashier', 'cashbox', 'cashierShift', 'user']);
        $finalTotal = (float) $order->grand_total;
        $grossTotal = $this->round($finalTotal + (float) ($order->loyalty_redemption_discount ?? 0));

        $items = $order->orderDetails->map(function (OrderDetail $detail) {
            $product = $detail->product;
            $stock = $product?->stocks->firstWhere('variant', $detail->variation ?? '');
            $price = (float) $detail->price;
            $tax = (float) $detail->tax;

            return [
                'name' => $product?->name,
                'sku' => $stock?->sku,
                'barcode' => $stock?->barcode ?? $product?->barcode,
                'quantity' => (float) $detail->quantity,
                'price' => $price,
                'tax' => $tax,
                'total' => $this->round($price + $tax),
            ];
        })->values()->all();

        return [
            'order_id' => $order->id,
            'code' => $order->code,
            'receipt_number' => $order->pos_receipt_number,
            'cashier' => $order->cashier ? [
                'id' => $order->cashier->id,
                'name' => $order->cashier->name,
            ] : null,
            'cashbox' => $order->cashbox ? [
                'id' => $order->cashbox->id,
                'name' => $order->cashbox->name,
            ] : null,
            'shift_id' => $order->cashier_shift_id,
            'customer' => $order->user ? $this->customerPayload($order->user) : null,
            'loyalty' => $this->loyaltySummaryForOrder($order),
            'items' => $items,
            'subtotal' => $this->round($order->orderDetails->sum('price')),
            'tax' => $this->round($order->orderDetails->sum('tax')),
            'gross_total' => $grossTotal,
            'grand_total' => $finalTotal,
            'final_total' => $finalTotal,
            'paid_amount' => (float) $order->paid_amount,
            'change_amount' => (float) $order->change_amount,
            'created_at' => $order->created_at?->toIso8601String(),
        ];
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

    private function customerIdFromPayment(array $payment): ?int
    {
        $customerId = $payment['customer_id'] ?? null;
        if ($customerId === null || $customerId === '') {
            return null;
        }

        if (filter_var($customerId, FILTER_VALIDATE_INT) === false || (int) $customerId < 1) {
            throw new DomainException('Selected POS customer is unavailable.');
        }

        return (int) $customerId;
    }

    private function pointsToRedeemFromPayment(array $payment): int
    {
        $points = $payment['points_to_redeem'] ?? 0;
        if ($points === null || $points === '') {
            return 0;
        }

        if (filter_var($points, FILTER_VALIDATE_INT) === false || (int) $points < 0) {
            throw new DomainException('Loyalty redemption points must be a non-negative integer.');
        }

        return (int) $points;
    }

    private function maskedEmail(?string $email): ?string
    {
        if (! $email || ! str_contains($email, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', $email, 2);

        return Str::substr($local, 0, 1) . '***@' . $domain;
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

    private function previewRedemptionForCheckout(?User $customer, int $pointsToRedeem, float $grossTotal): ?array
    {
        if ($pointsToRedeem <= 0) {
            return null;
        }
        if (! $customer) {
            throw new DomainException('Loyalty redemption requires a customer.');
        }
        if (! $this->features->enabled('loyalty_points')) {
            throw new DomainException('Loyalty redemption is disabled.');
        }

        return $this->loyalty->previewRedeemForCustomer($customer, $pointsToRedeem, $grossTotal, 'pos');
    }

    public function maybeAwardLoyaltyForPosOrder(Order $order): ?LoyaltyPointMovement
    {
        if (! $order->isPosOrder() || ! $order->user_id || ! $this->features->enabled('loyalty_points')) {
            return null;
        }

        return $this->loyalty->attemptEarnForOrder($order);
    }

    private function loyaltySummaryForOrder(Order $order): ?array
    {
        $enabled = $this->features->enabled('loyalty_points');
        if (! $enabled) {
            return ['enabled' => false, 'points_redeemed' => 0, 'redemption_discount' => 0.0, 'points_earned' => 0, 'balance_before' => null, 'balance_after' => null, 'final_balance' => null];
        }

        $customer = $order->user;
        if (! $customer) {
            return null;
        }

        $redeemed = $this->loyalty->pointsRedeemedForOrder($order);
        $earned = $this->loyalty->pointsEarnedForOrder($order);
        if (! $redeemed && ! $earned) {
            $balance = $this->loyalty->balanceForCustomerWithoutCreatingAccount($customer);

            return ['enabled' => true, 'points_redeemed' => 0, 'redemption_discount' => 0.0, 'points_earned' => 0, 'balance_before' => $balance, 'balance_after' => $balance, 'final_balance' => $balance];
        }

        $balanceBefore = $redeemed
            ? (int) $redeemed->balance_after + (int) $redeemed->points
            : (int) $earned->balance_after - (int) $earned->points;
        $finalBalance = $earned
            ? (int) $earned->balance_after
            : (int) $redeemed->balance_after;

        return [
            'enabled' => true,
            'points_redeemed' => (int) ($redeemed?->points ?? 0),
            'redemption_discount' => (float) ($order->loyalty_redemption_discount ?? 0),
            'points_earned' => (int) ($earned?->points ?? 0),
            'balance_before' => $balanceBefore,
            'balance_after' => $finalBalance,
            'final_balance' => $finalBalance,
        ];
    }

    private function createOrder(array $lines, CashierShift $shift, User $user, ?User $customer, string $requestKey, array $totals, float $paidAmount, float $finalTotal): Order
    {
        $sellerIds = collect($lines)->pluck('product.user_id')->filter()->unique();
        if ($sellerIds->count() > 1) {
            throw new DomainException('A POS cart cannot contain products from multiple sellers.');
        }

        $receipt = $this->generateReceiptNumber();
        $order = new Order();
        $order->user_id = $customer?->id;
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
        $order->change_amount = $this->round($paidAmount - $finalTotal);
        $order->pos_receipt_number = $receipt;
        $order->pos_request_key = $requestKey;
        $order->pos_metadata = [
            'tax_source' => 'legacy_product_taxes',
            'cashier_shift_id' => $shift->id,
            'cashbox_id' => $shift->cashbox_id,
            'customer_id' => $customer?->id,
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
