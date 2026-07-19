<?php

namespace App\Services\Demo;

use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\ProductStock;
use App\Models\PurchaseReceipt;
use App\Models\SalesReturnItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

class CoreMarketDemoSeeder
{
    public const BLOCKED_DATABASES = [
        'coremarket_runtime',
        'coremarket_testing',
        'coremarket',
        'core_market',
        'syrian_souq',
    ];

    public const SAMPLE_PROFILES = ['standard', 'large'];

    private const DEMO_PASSWORD = 'Demo@2026!';

    public function __construct(protected ?string $databaseNameOverride = null)
    {
    }

    public function buildPlan(array $options = []): array
    {
        $database = $this->databaseNameOverride ?: (string) DB::connection()->getDatabaseName();
        $sampleProfile = (string) ($options['with_samples'] ?? 'standard');
        $applyRequested = (bool) ($options['apply'] ?? false);

        return [
            'database' => $database,
            'mode' => $applyRequested ? 'apply' : 'dry-run',
            'apply_requested' => $applyRequested,
            'dry_run_requested' => (bool) ($options['dry_run'] ?? false),
            'confirmed' => (bool) ($options['confirm_demo_seed'] ?? false),
            'reset_requested' => (bool) ($options['reset'] ?? false),
            'sample_profile' => $sampleProfile,
            'planned_records' => $this->plannedRecords($sampleProfile),
            'dataset_structure' => $this->datasetStructure(),
        ];
    }

    public function validateSafety(array $plan): array
    {
        $errors = [];
        $database = strtolower(trim((string) ($plan['database'] ?? '')));

        if ($database === '') {
            $errors[] = 'The active database name could not be determined.';
        }
        if (in_array($database, self::BLOCKED_DATABASES, true)) {
            $errors[] = "Database [{$database}] is explicitly blocked from demo seeding.";
        }
        if (! str_ends_with($database, '_demo')) {
            $errors[] = 'Demo seeding is allowed only when the database name ends with [_demo].';
        }
        if (! in_array($plan['sample_profile'] ?? null, self::SAMPLE_PROFILES, true)) {
            $errors[] = 'The --with-samples option must be [standard] or [large].';
        }
        if (($plan['apply_requested'] ?? false) && ($plan['dry_run_requested'] ?? false)) {
            $errors[] = 'Use either --apply or --dry-run, not both.';
        }

        return array_values(array_unique($errors));
    }

    public function validateApplyRequirements(array $plan): array
    {
        $errors = $this->validateSafety($plan);

        if (! ($plan['apply_requested'] ?? false)) {
            $errors[] = 'Execution requires --apply.';
        }
        if (! ($plan['confirmed'] ?? false)) {
            $errors[] = 'Apply mode requires --confirm-demo-seed.';
        }

        return array_values(array_unique($errors));
    }

    public function execute(array $plan): array
    {
        if ($this->validateApplyRequirements($plan) !== []) {
            return ['status' => 'refused', 'records_written' => 0, 'reset_performed' => false, 'counts' => []];
        }

        if ((string) DB::connection()->getDatabaseName() !== (string) ($plan['database'] ?? '')) {
            if (! app()->environment('testing') || $this->databaseNameOverride === null) {
                throw new RuntimeException('The active database changed after the demo seed safety check.');
            }
        }

        $before = $this->importantCounts();

        DB::transaction(function () use ($plan) {
            if ($plan['reset_requested']) {
                $this->resetDemoRows();
            }

            $users = $this->seedUsers();
            $catalog = $this->seedCatalog($users['admin']);
            $operations = $this->seedOperations($users, $catalog);
            $this->seedLoyalty($users, $operations['orders']);
            $this->seedReturns($users, $operations['orders']);
            $this->seedPurchasing($users, $catalog);
            $this->seedExpenses($users);
            $this->seedSettings();
        });

        $counts = $this->importantCounts();

        return [
            'status' => 'seeded',
            'records_written' => array_sum($counts) - array_sum($before),
            'reset_performed' => (bool) $plan['reset_requested'],
            'counts' => $counts,
        ];
    }

    public function importantCounts(): array
    {
        return collect([
            'users', 'categories', 'brands', 'products', 'product_stocks', 'cashboxes',
            'cashier_shifts', 'cash_movements', 'orders', 'order_details', 'inventory_movements',
            'loyalty_accounts', 'loyalty_point_movements', 'sales_returns', 'suppliers',
            'purchase_orders', 'purchase_receipts', 'expenses',
        ])->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all();
    }

    private function seedUsers(): array
    {
        $staff = [
            'admin' => ['CoreMarket Demo Admin', 'admin@coremarket.demo', 'admin', 'Super Admin'],
            'cashier' => ['Maya Demo Cashier', 'cashier@coremarket.demo', 'staff', 'demo_cashier'],
            'inventory' => ['Omar Demo Inventory', 'inventory@coremarket.demo', 'staff', 'demo_inventory_manager'],
            'accountant' => ['Lina Demo Accountant', 'accountant@coremarket.demo', 'staff', 'demo_accountant'],
        ];
        $result = [];

        foreach ($staff as $key => [$name, $email, $type, $roleName]) {
            $roleId = $this->upsertId('roles', ['name' => $roleName, 'guard_name' => 'web'], []);
            $userId = $this->upsertUser($name, $email, $type, '+96170000' . str_pad((string) count($result), 2, '0', STR_PAD_LEFT));
            DB::table('model_has_roles')->updateOrInsert([
                'role_id' => $roleId,
                'model_type' => User::class,
                'model_id' => $userId,
            ], []);
            DB::table('staff')->updateOrInsert(['user_id' => $userId], ['role_id' => $roleId, 'updated_at' => now()]);
            $result[$key] = $userId;
        }

        $rolePermissions = [
            'demo_cashier' => [
                'pos.view', 'pos.sell', 'pos.receipts.view', 'pos.redeem_loyalty',
                'cashboxes.view', 'cash_shifts.view', 'cash_shifts.open', 'cash_shifts.close',
                'cash_movements.view',
            ],
            'demo_inventory_manager' => [
                'operations.view', 'inventory_movements.view',
                'inventory.view', 'inventory.dashboard.view', 'inventory.stock.view',
                'inventory.stock.adjust', 'inventory.stock.audit', 'inventory.low_stock.view',
                'inventory.barcode_lookup.view',
                'suppliers.view', 'suppliers.create', 'suppliers.edit',
                'purchase_orders.view', 'purchase_orders.create', 'purchase_orders.receive',
            ],
            'demo_accountant' => [
                'operations.view', 'expenses.view', 'expenses.create', 'expenses.approve',
                'accounting_summary.view', 'accounting.core.view', 'accounting.accounts.view',
                'accounting.journals.view', 'accounting.tax.view', 'accounting.tax.audit',
                'accounting.general_ledger.view', 'accounting.trial_balance.view',
                'accounting.profit_loss.view', 'accounting.events.view',
            ],
        ];

        foreach ($rolePermissions as $roleName => $permissions) {
            $roleId = DB::table('roles')->where('name', $roleName)->where('guard_name', 'web')->value('id');
            DB::table('role_has_permissions')->where('role_id', $roleId)->delete();

            foreach (DB::table('permissions')->whereIn('name', $permissions)->pluck('id') as $permissionId) {
                DB::table('role_has_permissions')->insert([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $customerNames = ['Nour Haddad', 'Karim Saleh', 'Dana Nasser', 'Tariq Mansour', 'Rana Khalil', 'Samir Fadel', 'Lea Saad', 'Hadi Younes', 'Mira Daher', 'Ziad Habib'];
        foreach ($customerNames as $index => $name) {
            $result['customers'][] = $this->upsertUser(
                $name,
                'customer' . ($index + 1) . '@coremarket.demo',
                'customer',
                '+9617100' . str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT)
            );
        }

        return $result;
    }

    private function upsertUser(string $name, string $email, string $type, string $phone): int
    {
        return $this->upsertId('users', ['email' => $email], [
            'name' => $name,
            'user_type' => $type,
            'phone' => $phone,
            'password' => Hash::make(self::DEMO_PASSWORD),
            'email_verified_at' => now(),
            'balance' => 0,
            'banned' => 0,
            'country' => 'Demo Country',
            'city' => 'Demo City',
            'address' => 'CoreMarket Demo District',
        ]);
    }

    private function seedCatalog(int $adminId): array
    {
        $categoryNames = ['Fresh Food', 'Beverages', 'Pantry', 'Home Care', 'Personal Care', 'Office', 'Mobile Accessories', 'Small Electronics', 'Kitchen', 'Seasonal'];
        $brandNames = ['Demo Daily', 'Demo Fresh', 'Demo Home', 'Demo Tech', 'Demo Select'];
        $categoryIds = [];
        $brandIds = [];

        foreach ($categoryNames as $index => $name) {
            $categoryIds[] = $this->upsertId('categories', ['slug' => 'demo-' . str($name)->slug()], [
                'parent_id' => 0, 'level' => 0, 'name' => $name, 'order_level' => $index,
                'commision_rate' => 0, 'featured' => $index < 4 ? 1 : 0, 'active' => 1,
                'top' => 0, 'digital' => 0, 'meta_title' => $name,
                'meta_description' => 'Synthetic CoreMarket demo category.',
            ]);
        }
        foreach ($brandNames as $name) {
            $brandIds[] = $this->upsertId('brands', ['slug' => 'demo-' . str($name)->slug()], [
                'name' => $name, 'top' => 0, 'meta_title' => $name,
                'meta_description' => 'Synthetic CoreMarket demo brand.',
            ]);
        }

        $products = [
            ['Organic Apples 1kg', 3.80, 2.10], ['Bananas 1kg', 2.40, 1.30], ['Fresh Milk 1L', 1.90, 1.10],
            ['Mineral Water 6 Pack', 3.60, 2.20], ['Orange Juice 1L', 2.90, 1.70], ['Ground Coffee 250g', 5.80, 3.60],
            ['Basmati Rice 2kg', 6.40, 4.10], ['Olive Oil 750ml', 8.90, 6.20], ['Pasta 500g', 1.70, 0.90],
            ['Tomato Sauce 400g', 1.50, 0.80], ['Laundry Detergent', 7.50, 4.90], ['Dish Soap', 2.60, 1.40],
            ['Paper Towels 4 Pack', 4.20, 2.60], ['Shampoo 400ml', 4.80, 2.90], ['Hand Soap', 1.80, 0.90],
            ['Notebook A5', 2.20, 1.10], ['Ballpoint Pens 5 Pack', 2.80, 1.30], ['USB-C Cable', 6.90, 3.50],
            ['Fast Wall Charger', 14.90, 8.20], ['Wireless Mouse', 16.50, 9.40], ['Bluetooth Speaker', 24.00, 14.50],
            ['LED Desk Lamp', 19.50, 11.20], ['Digital Kitchen Scale', 18.90, 10.60], ['Stainless Water Bottle', 11.50, 6.40],
            ['Food Storage Set', 9.80, 5.30], ['Non-stick Frying Pan', 21.00, 12.80], ['Tea Towels 3 Pack', 5.20, 2.70],
            ['AA Batteries 8 Pack', 7.20, 4.10], ['Extension Power Strip', 13.80, 8.00], ['Reusable Shopping Bag', 2.00, 0.80],
        ];
        $productIds = [];
        $stockIds = [];

        foreach ($products as $index => [$name, $price, $cost]) {
            $sku = 'DEMO-' . str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT);
            $barcode = '629000' . str_pad((string) ($index + 1), 6, '0', STR_PAD_LEFT);
            $qty = 24 + (($index * 7) % 77);
            $productId = $this->upsertId('products', ['barcode' => $barcode], [
                'name' => $name, 'added_by' => 'admin', 'user_id' => $adminId,
                'category_id' => $categoryIds[$index % count($categoryIds)],
                'brand_id' => $brandIds[$index % count($brandIds)], 'photos' => '',
                'tags' => 'demo,coremarket', 'description' => 'Synthetic product for CoreMarket demonstrations.',
                'unit_price' => $price, 'wholesale_price' => $price, 'purchase_price' => $cost,
                'variant_product' => 0, 'attributes' => '[]', 'choice_options' => '[]', 'colors' => '[]',
                'variations' => '[]', 'published' => 1, 'approved' => 1, 'stock_visibility_state' => 'quantity',
                'cash_on_delivery' => 1, 'current_stock' => $qty, 'unit' => 'pc', 'min_qty' => 1,
                'low_stock_quantity' => 8, 'discount' => 0, 'discount_type' => 'flat', 'tax' => 0,
                'tax_type' => 'flat', 'shipping_type' => 'flat_rate', 'shipping_cost' => 0,
                'meta_title' => $name, 'meta_description' => 'Synthetic CoreMarket demo product.',
                'slug' => 'demo-' . str($name)->slug() . '-' . ($index + 1), 'digital' => 0,
                'barcode' => $barcode, 'external_link' => '', 'external_link_btn' => '',
            ]);
            $stockId = $this->upsertId('product_stocks', ['sku' => $sku], [
                'product_id' => $productId, 'variant' => '', 'barcode' => $barcode,
                'price' => $price, 'qty' => $qty, 'image' => null,
            ]);
            $this->upsertInventoryMovement(
                'demo:opening-stock:' . $stockId,
                $productId,
                $stockId,
                'initial_stock',
                'in',
                $qty,
                $cost,
                ProductStock::class,
                $stockId,
                null,
                null,
                $adminId,
                now()->subDays(30)
            );
            DB::table('product_categories')->updateOrInsert([
                'product_id' => $productId, 'category_id' => $categoryIds[$index % count($categoryIds)],
            ], []);
            $productIds[] = $productId;
            $stockIds[] = $stockId;
        }

        return ['products' => $productIds, 'stocks' => $stockIds];
    }

    private function seedOperations(array $users, array $catalog): array
    {
        $cashierId = $users['cashier'];
        $mainCashbox = $this->upsertId('cashboxes', ['code' => 'DEMO-MAIN'], [
            'name' => 'Main Register', 'location' => 'Demo Store Front', 'currency' => 'USD',
            'status' => 'active', 'assigned_user_id' => $cashierId, 'metadata' => $this->json(['demo_seed' => true]),
        ]);
        $backupCashbox = $this->upsertId('cashboxes', ['code' => 'DEMO-BACKUP'], [
            'name' => 'Backup Register', 'location' => 'Demo Store Front', 'currency' => 'USD',
            'status' => 'active', 'assigned_user_id' => null, 'metadata' => $this->json(['demo_seed' => true]),
        ]);
        $openShift = $this->upsertId('cashier_shifts', ['cashbox_id' => $mainCashbox, 'status' => 'open'], [
            'opened_by' => $cashierId, 'opened_at' => now()->startOfDay()->addHours(8),
            'opening_balance' => 150, 'expected_cash' => 150, 'notes' => 'Current demo shift',
            'metadata' => $this->json(['demo_seed' => true]),
        ]);
        $closedShift = $this->upsertId('cashier_shifts', ['cashbox_id' => $backupCashbox, 'status' => 'closed'], [
            'opened_by' => $cashierId, 'closed_by' => $cashierId,
            'opened_at' => now()->subDay()->startOfDay()->addHours(9),
            'closed_at' => now()->subDay()->startOfDay()->addHours(18),
            'opening_balance' => 100, 'expected_cash' => 286.40, 'actual_cash' => 286.40,
            'cash_difference' => 0, 'notes' => 'Previous demo shift', 'close_notes' => 'Balanced close',
            'metadata' => $this->json(['demo_seed' => true]),
        ]);
        $this->upsertCashMovement('demo:cash:open:current', $mainCashbox, $openShift, 'opening', 'in', 150, null, $cashierId, now()->startOfDay()->addHours(8));
        $this->upsertCashMovement('demo:cash:open:previous', $backupCashbox, $closedShift, 'opening', 'in', 100, null, $cashierId, now()->subDay()->startOfDay()->addHours(9));

        $orders = [];
        for ($index = 0; $index < 14; $index++) {
            $stockIndex = ($index * 2) % count($catalog['stocks']);
            $stock = DB::table('product_stocks')->where('id', $catalog['stocks'][$stockIndex])->first();
            $product = DB::table('products')->where('id', $stock->product_id)->first();
            $quantity = 1 + ($index % 3);
            $gross = round((float) $stock->price * $quantity, 2);
            $redeemedPoints = in_array($index, [4, 7, 10], true) ? 20 : 0;
            $discount = $redeemedPoints > 0 ? 2.00 : 0.00;
            $final = round($gross - $discount, 2);
            $paid = ceil($final / 5) * 5;
            $customerId = $index % 3 === 0 ? null : $users['customers'][$index % count($users['customers'])];
            if ($redeemedPoints > 0) {
                $customerId = $users['customers'][$index % count($users['customers'])];
            }
            $shiftId = $index < 4 ? $closedShift : $openShift;
            $cashboxId = $index < 4 ? $backupCashbox : $mainCashbox;
            $time = now()->subDays($index < 4 ? 1 : 0)->startOfDay()->addHours(10)->addMinutes($index * 19);
            $requestKey = 'demo:pos:order:' . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);
            $receipt = 'DEMO-POS-' . str_pad((string) ($index + 1), 5, '0', STR_PAD_LEFT);
            $orderId = $this->upsertId('orders', ['pos_request_key' => $requestKey], [
                'user_id' => $customerId, 'seller_id' => $users['admin'], 'shipping_type' => 'pos',
                'order_from' => 'pos', 'delivery_status' => 'delivered', 'payment_type' => 'cash',
                'payment_status' => 'paid', 'payment_details' => $this->json(['method' => 'cash']),
                'grand_total' => $final, 'coupon_discount' => 0, 'code' => $receipt, 'date' => $time->timestamp,
                'cashier_shift_id' => $shiftId, 'cashbox_id' => $cashboxId, 'cashier_id' => $cashierId,
                'paid_amount' => $paid, 'change_amount' => round($paid - $final, 2),
                'pos_receipt_number' => $receipt,
                'pos_metadata' => $this->json(['demo_seed' => true, 'gross_total' => $gross]),
                'loyalty_points_redeemed' => $redeemedPoints,
                'loyalty_redemption_discount' => $discount,
                'created_at' => $time, 'updated_at' => $time,
            ]);
            $detailId = $this->upsertId('order_details', ['order_id' => $orderId, 'product_id' => $product->id], [
                'seller_id' => $users['admin'], 'variation' => '', 'price' => $gross,
                'cost_price' => $product->purchase_price, 'cost_source' => 'demo_seed',
                'total_cost' => round((float) $product->purchase_price * $quantity, 2),
                'profit_amount' => round($gross - ((float) $product->purchase_price * $quantity) - $discount, 2),
                'profit_calculated_at' => $time, 'tax' => 0, 'shipping_cost' => 0, 'quantity' => $quantity,
                'payment_status' => 'paid', 'delivery_status' => 'delivered', 'shipping_type' => 'pos',
                'created_at' => $time, 'updated_at' => $time,
            ]);
            $this->upsertInventoryMovement('demo:sale:' . $orderId, $product->id, $stock->id, 'sale', 'out', $quantity, $product->purchase_price, OrderDetail::class, $detailId, $orderId, $detailId, $cashierId, $time);
            DB::table('product_stocks')->where('id', $stock->id)->decrement('qty', $quantity);
            DB::table('products')->where('id', $product->id)->decrement('current_stock', $quantity);
            $this->upsertCashMovement('demo:cash:sale:' . $orderId, $cashboxId, $shiftId, 'sale', 'in', $final, Order::class, $cashierId, $time, $orderId);
            $orders[] = ['id' => $orderId, 'detail_id' => $detailId, 'customer_id' => $customerId, 'redeemed' => $redeemedPoints, 'final' => $final, 'gross' => $gross, 'product_id' => $product->id, 'stock_id' => $stock->id, 'quantity' => $quantity, 'cost' => (float) $product->purchase_price];
        }

        $openExpected = (float) DB::table('cash_movements')->where('cashier_shift_id', $openShift)->selectRaw("SUM(CASE WHEN direction='in' THEN amount WHEN direction='out' THEN -amount ELSE 0 END) total")->value('total');
        DB::table('cashier_shifts')->where('id', $openShift)->update(['expected_cash' => $openExpected, 'updated_at' => now()]);
        $closedExpected = (float) DB::table('cash_movements')->where('cashier_shift_id', $closedShift)->selectRaw("SUM(CASE WHEN direction='in' THEN amount WHEN direction='out' THEN -amount ELSE 0 END) total")->value('total');
        DB::table('cashier_shifts')->where('id', $closedShift)->update(['expected_cash' => $closedExpected, 'actual_cash' => $closedExpected, 'cash_difference' => 0, 'updated_at' => now()]);

        return ['orders' => $orders];
    }

    private function seedLoyalty(array $users, array $orders): void
    {
        $ruleId = $this->upsertId('loyalty_rules', ['name' => 'CoreMarket Demo POS Rewards'], [
            'is_active' => 1, 'earn_rate_amount' => 10, 'earn_rate_points' => 1,
            'min_order_amount' => 5, 'currency' => 'USD', 'applies_to_order_from' => 'pos',
            'redeem_points' => 10, 'redeem_value' => 1, 'min_redeem_points' => 10,
            'max_redeem_points_per_order' => 100, 'max_redeem_percent' => 40,
            'allow_pos_redeem' => 1, 'allow_storefront_redeem' => 0,
            'metadata' => $this->json(['demo_seed' => true]),
        ]);

        foreach ($users['customers'] as $customerId) {
            $accountId = $this->upsertId('loyalty_accounts', ['user_id' => $customerId], [
                'points_balance' => 60, 'lifetime_points_earned' => 0,
                'lifetime_points_redeemed' => 0, 'status' => 'active',
                'metadata' => $this->json(['demo_seed' => true]),
            ]);
            $this->upsertLoyaltyMovement('demo:loyalty:opening:' . $customerId, $accountId, $customerId, 'adjustment', 'in', 60, 60, null, null, 'Demo opening balance', $users['admin'], ['demo_seed' => true]);
        }

        $balances = array_fill_keys($users['customers'], 60);
        $earnedTotals = array_fill_keys($users['customers'], 0);
        $redeemedTotals = array_fill_keys($users['customers'], 0);
        foreach ($orders as $order) {
            if (! $order['customer_id']) {
                continue;
            }
            $customerId = $order['customer_id'];
            $accountId = DB::table('loyalty_accounts')->where('user_id', $customerId)->value('id');
            if ($order['redeemed'] > 0) {
                $balances[$customerId] -= $order['redeemed'];
                $redeemedTotals[$customerId] += $order['redeemed'];
                $this->upsertLoyaltyMovement('demo:loyalty:redeem:' . $order['id'], $accountId, $customerId, 'redeem', 'out', $order['redeemed'], $balances[$customerId], Order::class, $order['id'], 'Redeemed on demo POS order', $users['cashier'], [
                    'demo_seed' => true, 'rule_id' => $ruleId, 'discount_amount' => 2, 'gross_total' => $order['gross'], 'final_total' => $order['final'],
                ]);
            }
            $earned = (int) floor($order['final'] / 10);
            if ($earned > 0) {
                $balances[$customerId] += $earned;
                $earnedTotals[$customerId] += $earned;
                $this->upsertLoyaltyMovement('demo:loyalty:earn:' . $order['id'], $accountId, $customerId, 'earn', 'in', $earned, $balances[$customerId], Order::class, $order['id'], 'Earned from demo POS order', $users['cashier'], [
                    'demo_seed' => true, 'rule_id' => $ruleId, 'grand_total' => $order['final'],
                ]);
            }
        }
        foreach ($users['customers'] as $customerId) {
            DB::table('loyalty_accounts')->where('user_id', $customerId)->update([
                'points_balance' => $balances[$customerId],
                'lifetime_points_earned' => $earnedTotals[$customerId],
                'lifetime_points_redeemed' => $redeemedTotals[$customerId],
                'updated_at' => now(),
            ]);
        }
    }

    private function seedReturns(array $users, array $orders): void
    {
        $returnOrders = [$orders[1], collect($orders)->first(fn (array $order) => $order['redeemed'] > 0)];
        foreach ($returnOrders as $index => $order) {
            $time = now()->subHours(4 - $index);
            $returnNumber = 'DEMO-SR-' . str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT);
            $returnId = $this->upsertId('sales_returns', ['return_number' => $returnNumber], [
                'order_id' => $order['id'], 'user_id' => $order['customer_id'], 'customer_id' => $order['customer_id'],
                'status' => 'completed', 'return_type' => 'customer_return',
                'reason' => $index === 0 ? 'Demo damaged item' : 'Demo redemption return',
                'notes' => 'Synthetic completed return for reporting.', 'subtotal_amount' => $order['gross'],
                'tax_amount' => 0, 'discount_amount' => $order['gross'] - $order['final'],
                'shipping_amount' => 0, 'total_amount' => $order['final'], 'total_cost' => $order['cost'] * $order['quantity'],
                'profit_reversal_amount' => $order['final'] - ($order['cost'] * $order['quantity']),
                'stock_reversed_at' => $time, 'completed_at' => $time,
                'approved_by' => $users['admin'], 'created_by' => $users['cashier'],
                'metadata' => $this->json(['demo_seed' => true, 'policy' => 'full_demo_return']),
                'created_at' => $time, 'updated_at' => $time,
            ]);
            $itemId = $this->upsertId('sales_return_items', ['sales_return_id' => $returnId, 'order_detail_id' => $order['detail_id']], [
                'order_id' => $order['id'], 'product_id' => $order['product_id'], 'product_stock_id' => $order['stock_id'],
                'variant' => '', 'quantity' => $order['quantity'], 'unit_price' => $order['gross'] / $order['quantity'],
                'tax_amount' => 0, 'discount_amount' => $order['gross'] - $order['final'], 'cost_price' => $order['cost'],
                'total_cost' => $order['cost'] * $order['quantity'],
                'profit_reversal_amount' => $order['final'] - ($order['cost'] * $order['quantity']),
                'stock_reversed_quantity' => $order['quantity'], 'reason' => 'Demo return item',
                'metadata' => $this->json(['demo_seed' => true]), 'created_at' => $time, 'updated_at' => $time,
            ]);
            $this->upsertInventoryMovement('demo:return:' . $returnId, $order['product_id'], $order['stock_id'], 'sale_reversal', 'in', $order['quantity'], $order['cost'], SalesReturnItem::class, $itemId, $order['id'], $order['detail_id'], $users['cashier'], $time);
            DB::table('product_stocks')->where('id', $order['stock_id'])->increment('qty', $order['quantity']);
            DB::table('products')->where('id', $order['product_id'])->increment('current_stock', $order['quantity']);

            if ($order['customer_id']) {
                $account = DB::table('loyalty_accounts')->where('user_id', $order['customer_id'])->first();
                $earn = DB::table('loyalty_point_movements')->where('idempotency_key', 'demo:loyalty:earn:' . $order['id'])->first();
                $balance = (int) $account->points_balance;
                if ($earn) {
                    $reverse = min($balance, (int) $earn->points);
                    $balance -= $reverse;
                    $this->upsertLoyaltyMovement('demo:loyalty:reverse:' . $order['id'], $account->id, $order['customer_id'], 'reverse', 'out', $reverse, $balance, Order::class, $order['id'], 'Demo sales return completed', $users['cashier'], ['demo_seed' => true, 'sales_return_id' => $returnId]);
                }
                if ($order['redeemed'] > 0) {
                    $balance += $order['redeemed'];
                    $this->upsertLoyaltyMovement('demo:loyalty:restore:' . $order['id'], $account->id, $order['customer_id'], 'redeem_restore', 'in', $order['redeemed'], $balance, Order::class, $order['id'], 'Demo redeemed points restored', $users['cashier'], [
                        'demo_seed' => true, 'sales_return_id' => $returnId, 'policy' => 'full_restore_on_first_completed_return',
                    ]);
                }
                DB::table('loyalty_accounts')->where('id', $account->id)->update(['points_balance' => $balance, 'updated_at' => now()]);
            }
        }
    }

    private function seedPurchasing(array $users, array $catalog): void
    {
        $supplierNames = ['Demo Fresh Supply', 'Demo Home Wholesale', 'Demo Tech Distribution', 'Demo Office Goods'];
        $supplierIds = [];
        foreach ($supplierNames as $index => $name) {
            $supplierIds[] = $this->upsertId('suppliers', ['email' => 'supplier' . ($index + 1) . '@coremarket.demo'], [
                'name' => $name, 'company_name' => $name, 'contact_name' => 'Demo Contact ' . ($index + 1),
                'phone' => '+9617600' . str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                'address' => 'Demo Industrial Area', 'notes' => 'Synthetic supplier.', 'is_active' => 1,
                'metadata' => $this->json(['demo_seed' => true]),
            ]);
        }

        for ($index = 0; $index < 3; $index++) {
            $received = $index < 2;
            $productId = $catalog['products'][$index * 3];
            $stockId = $catalog['stocks'][$index * 3];
            $product = DB::table('products')->where('id', $productId)->first();
            $quantity = 20 + ($index * 5);
            $total = round((float) $product->purchase_price * $quantity, 2);
            $poNumber = 'DEMO-PO-' . str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT);
            $poId = $this->upsertId('purchase_orders', ['purchase_number' => $poNumber], [
                'supplier_id' => $supplierIds[$index], 'status' => $received ? 'received' : 'approved',
                'ordered_at' => now()->subDays(14 - ($index * 3)), 'received_at' => $received ? now()->subDays(10 - ($index * 3)) : null,
                'currency' => 'USD', 'subtotal_amount' => $total, 'tax_amount' => 0, 'discount_amount' => 0,
                'shipping_amount' => 0, 'total_amount' => $total, 'notes' => 'Synthetic demo purchase order.',
                'created_by' => $users['inventory'], 'received_by' => $received ? $users['inventory'] : null,
                'metadata' => $this->json(['demo_seed' => true]),
            ]);
            $itemId = $this->upsertId('purchase_order_items', ['purchase_order_id' => $poId, 'product_id' => $productId], [
                'product_stock_id' => $stockId, 'variant' => '', 'quantity_ordered' => $quantity,
                'quantity_received' => $received ? $quantity : 0, 'unit_cost' => $product->purchase_price,
                'tax_amount' => 0, 'discount_amount' => 0, 'total_cost' => $total,
                'metadata' => $this->json(['demo_seed' => true]),
            ]);
            if ($received) {
                $receiptKey = 'DEMO-PR-' . str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT);
                $receiptId = $this->upsertId('purchase_receipts', ['receipt_key' => $receiptKey], [
                    'purchase_order_id' => $poId, 'received_at' => now()->subDays(10 - ($index * 3)),
                    'received_by' => $users['inventory'], 'notes' => 'Synthetic received stock.',
                    'metadata' => $this->json(['demo_seed' => true]),
                ]);
                $movementId = $this->upsertInventoryMovement('demo:purchase:' . $receiptId, $productId, $stockId, 'purchase_receipt', 'in', $quantity, $product->purchase_price, PurchaseReceipt::class, $receiptId, null, null, $users['inventory'], now()->subDays(10 - ($index * 3)));
                DB::table('product_stocks')->where('id', $stockId)->increment('qty', $quantity);
                DB::table('products')->where('id', $productId)->increment('current_stock', $quantity);
                $this->upsertId('purchase_receipt_items', ['purchase_receipt_id' => $receiptId, 'purchase_order_item_id' => $itemId], [
                    'product_id' => $productId, 'product_stock_id' => $stockId, 'quantity_received' => $quantity,
                    'unit_cost' => $product->purchase_price, 'total_cost' => $total, 'inventory_movement_id' => $movementId,
                ]);
            }
        }
    }

    private function seedExpenses(array $users): void
    {
        $categories = [
            'RENT' => 'Rent', 'UTILITIES' => 'Utilities', 'PACKAGING' => 'Packaging',
            'DELIVERY' => 'Delivery', 'MAINTENANCE' => 'Maintenance',
        ];
        $categoryIds = [];
        foreach ($categories as $code => $name) {
            $categoryIds[$code] = $this->upsertId('expense_categories', ['code' => 'DEMO-' . $code], [
                'name' => $name, 'description' => 'Synthetic demo expense category.', 'is_active' => 1,
                'metadata' => $this->json(['demo_seed' => true]),
            ]);
        }
        $expenses = [
            ['RENT', 'Monthly store rent', 850], ['UTILITIES', 'Electricity and water', 165],
            ['PACKAGING', 'Paper bags and labels', 74.50], ['DELIVERY', 'Local delivery services', 92],
            ['MAINTENANCE', 'Register maintenance', 48], ['UTILITIES', 'Internet subscription', 39],
            ['PACKAGING', 'Reusable demo bags', 55],
        ];
        foreach ($expenses as $index => [$code, $title, $amount]) {
            DB::table('expenses')->updateOrInsert(['reference_number' => 'DEMO-EXP-' . str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT)], [
                'expense_category_id' => $categoryIds[$code], 'title' => $title, 'amount' => $amount,
                'currency' => 'USD', 'expense_date' => now()->subDays($index * 3)->toDateString(),
                'payment_method' => 'cash', 'vendor_name' => 'Demo Vendor',
                'notes' => 'Synthetic approved expense; no journal was generated.', 'status' => 'approved',
                'approved_by' => $users['accountant'], 'created_by' => $users['accountant'],
                'metadata' => $this->json(['demo_seed' => true, 'accounting_pending' => true]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function seedSettings(): void
    {
        foreach (array_merge([
            'website_name' => 'CoreMarket Demo Store',
            'vendor_system_activation' => '0',
            'coremarket_demo_seed' => 'standard',
            'coremarket_demo_features' => $this->json([
                'pos',
                'cashbox_shifts',
                'loyalty_points',
                'inventory_pro',
                'purchasing_suppliers',
                'returns_management',
                'accounting_lite',
                'accounting_core',
            ]),
        ], $this->demoRuntimeSnapshotSettings()) as $type => $value) {
            DB::table('business_settings')->updateOrInsert(['type' => $type, 'lang' => null], [
                'value' => $value, 'updated_at' => now(),
            ]);
        }
    }

    private function demoRuntimeSnapshotSettings(): array
    {
        $plan = 'business';
        $storeMode = 'single_store';
        $features = collect(config('coremarket.runtime.feature_definitions', []))
            ->mapWithKeys(fn (array $definition, string $feature) => [
                $feature => (bool) ($definition['default'] ?? false),
            ])
            ->merge(config("coremarket.runtime.plans.{$plan}.features", []))
            ->merge(array_fill_keys([
                'pos',
                'cashbox_shifts',
                'loyalty_points',
                'inventory_pro',
                'purchasing_suppliers',
                'returns_management',
                'accounting_lite',
                'accounting_core',
            ], true))
            ->all();
        $limits = collect(config('coremarket.runtime.limit_definitions', []))
            ->mapWithKeys(fn (array $definition, string $limit) => [
                $limit => $definition['default'] ?? null,
            ])
            ->merge(config("coremarket.runtime.plans.{$plan}.limits", []))
            ->all();
        $key = fn (string $name): string => (string) config("coremarket.runtime_snapshot.setting_keys.{$name}");

        return [
            $key('status') => 'active',
            $key('applied_plan') => $plan,
            $key('store_mode') => $storeMode,
            $key('features') => $this->json($features),
            $key('limits') => $this->json($limits),
            $key('store_metadata') => $this->json([
                'instance_id' => 'coremarket-local-demo',
                'store_name' => 'CoreMarket Demo Store',
                'store_url' => 'http://localhost/coremarket-demo',
                'admin_url' => 'http://localhost/coremarket-demo/admin',
                'pos_url' => 'http://localhost/coremarket-demo/admin/operations/pos',
                'api_base_url' => 'http://localhost/coremarket-demo',
            ]),
            $key('support_metadata') => $this->json([
                'company_name' => 'CoreMarket Demo Company',
                'support_email' => 'support@coremarket.demo',
            ]),
            $key('addon_catalog') => $this->json([
                'catalog_version' => 'demo-local',
                'items' => [],
            ]),
            $key('addon_catalog_version') => 'demo-local',
            $key('addon_catalog_synced_at') => now()->toIso8601String(),
            $key('subscription_metadata') => $this->json([
                'status' => 'active',
                'billing_cycle' => 'demo',
                'starts_at' => now()->startOfYear()->toDateString(),
                'ends_at' => now()->addYear()->endOfYear()->toDateString(),
                'days_remaining' => now()->diffInDays(now()->addYear()->endOfYear()),
                'renewal_label' => 'Local demo subscription',
                'currency' => 'USD',
                'current_plan_price' => 0,
            ]),
        ];
    }

    private function resetDemoRows(): void
    {
        $demoUserIds = DB::table('users')->where('email', 'like', '%@coremarket.demo')->pluck('id');
        $demoOrderIds = DB::table('orders')->where('pos_request_key', 'like', 'demo:%')->pluck('id');
        $demoReturnIds = DB::table('sales_returns')->where('return_number', 'like', 'DEMO-SR-%')->pluck('id');
        $demoPoIds = DB::table('purchase_orders')->where('purchase_number', 'like', 'DEMO-PO-%')->pluck('id');
        $demoReceiptIds = DB::table('purchase_receipts')->where('receipt_key', 'like', 'DEMO-PR-%')->pluck('id');
        $demoProductIds = DB::table('products')->where('barcode', 'like', '629000%')->pluck('id');

        DB::table('sales_return_items')->whereIn('sales_return_id', $demoReturnIds)->delete();
        DB::table('sales_returns')->whereIn('id', $demoReturnIds)->delete();
        DB::table('loyalty_point_movements')->where('idempotency_key', 'like', 'demo:%')->delete();
        DB::table('loyalty_accounts')->whereIn('user_id', $demoUserIds)->delete();
        DB::table('loyalty_rules')->where('name', 'CoreMarket Demo POS Rewards')->delete();
        DB::table('cash_movements')->whereRaw("JSON_EXTRACT(metadata, '$.demo_seed') = true")->orWhere(function ($query) use ($demoOrderIds) {
            $query->where('reference_type', Order::class)->whereIn('reference_id', $demoOrderIds);
        })->delete();
        DB::table('inventory_movements')->whereRaw("JSON_EXTRACT(metadata, '$.demo_seed') = true")->delete();
        DB::table('order_details')->whereIn('order_id', $demoOrderIds)->delete();
        DB::table('orders')->whereIn('id', $demoOrderIds)->delete();
        DB::table('purchase_receipt_items')->whereIn('purchase_receipt_id', $demoReceiptIds)->delete();
        DB::table('purchase_receipts')->whereIn('id', $demoReceiptIds)->delete();
        DB::table('purchase_order_items')->whereIn('purchase_order_id', $demoPoIds)->delete();
        DB::table('purchase_orders')->whereIn('id', $demoPoIds)->delete();
        DB::table('suppliers')->where('email', 'like', '%@coremarket.demo')->delete();
        DB::table('expenses')->where('reference_number', 'like', 'DEMO-EXP-%')->delete();
        DB::table('expense_categories')->where('code', 'like', 'DEMO-%')->delete();
        DB::table('cashier_shifts')->whereRaw("JSON_EXTRACT(metadata, '$.demo_seed') = true")->delete();
        DB::table('cashboxes')->where('code', 'like', 'DEMO-%')->delete();
        DB::table('product_categories')->whereIn('product_id', $demoProductIds)->delete();
        DB::table('product_stocks')->whereIn('product_id', $demoProductIds)->delete();
        DB::table('products')->whereIn('id', $demoProductIds)->delete();
        DB::table('categories')->where('slug', 'like', 'demo-%')->delete();
        DB::table('brands')->where('slug', 'like', 'demo-%')->delete();
        DB::table('staff')->whereIn('user_id', $demoUserIds)->delete();
        DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $demoUserIds)->delete();
        $demoRoleIds = DB::table('roles')->where('name', 'like', 'demo_%')->pluck('id');
        DB::table('role_has_permissions')->whereIn('role_id', $demoRoleIds)->delete();
        DB::table('roles')->whereIn('id', $demoRoleIds)->delete();
        DB::table('users')->whereIn('id', $demoUserIds)->delete();
        DB::table('business_settings')->whereIn('type', array_values(array_filter(array_merge(
            ['coremarket_demo_seed', 'coremarket_demo_features'],
            config('coremarket.runtime_snapshot.setting_keys', [])
        ))))->delete();
    }

    private function upsertCashMovement(string $marker, int $cashboxId, int $shiftId, string $type, string $direction, float $amount, ?string $referenceType, int $actorId, Carbon $occurredAt, ?int $referenceId = null): int
    {
        return $this->upsertId('cash_movements', ['description' => $marker], [
            'cashbox_id' => $cashboxId, 'cashier_shift_id' => $shiftId, 'movement_type' => $type,
            'direction' => $direction, 'amount' => $amount, 'currency' => 'USD',
            'reference_type' => $referenceType, 'reference_id' => $referenceId,
            'created_by' => $actorId, 'occurred_at' => $occurredAt,
            'metadata' => $this->json(['demo_seed' => true, 'marker' => $marker]),
        ]);
    }

    private function upsertInventoryMovement(string $marker, int $productId, int $stockId, string $type, string $direction, float $quantity, float $unitCost, string $referenceType, int $referenceId, ?int $orderId, ?int $detailId, int $actorId, Carbon $createdAt): int
    {
        return $this->upsertId('inventory_movements', [
            'reference_type' => $referenceType, 'reference_id' => $referenceId, 'movement_type' => $type,
        ], [
            'product_id' => $productId, 'product_stock_id' => $stockId, 'variant' => '',
            'direction' => $direction, 'quantity' => $quantity, 'unit_cost' => $unitCost,
            'total_cost' => round($quantity * $unitCost, 6), 'order_id' => $orderId,
            'order_detail_id' => $detailId, 'notes' => $marker,
            'metadata' => $this->json(['demo_seed' => true, 'marker' => $marker]),
            'created_by' => $actorId, 'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);
    }

    private function upsertLoyaltyMovement(string $key, int $accountId, int $userId, string $type, string $direction, int $points, int $balanceAfter, ?string $referenceType, ?int $referenceId, string $reason, int $actorId, array $metadata): int
    {
        return $this->upsertId('loyalty_point_movements', ['idempotency_key' => $key], [
            'loyalty_account_id' => $accountId, 'user_id' => $userId, 'movement_type' => $type,
            'direction' => $direction, 'points' => $points, 'balance_after' => $balanceAfter,
            'reference_type' => $referenceType, 'reference_id' => $referenceId,
            'reason' => $reason, 'created_by' => $actorId, 'metadata' => $this->json($metadata),
        ]);
    }

    private function upsertId(string $table, array $identity, array $values): int
    {
        $existing = DB::table($table)->where($identity)->value('id');
        $payload = array_merge($values, ['updated_at' => $values['updated_at'] ?? now()]);

        if ($existing) {
            DB::table($table)->where('id', $existing)->update($payload);

            return (int) $existing;
        }

        return (int) DB::table($table)->insertGetId(array_merge($identity, $payload, [
            'created_at' => $values['created_at'] ?? now(),
        ]));
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES);
    }

    private function plannedRecords(string $profile): array
    {
        $large = $profile === 'large';

        return [
            ['area' => 'Users', 'records' => $large ? 35 : 14, 'marker' => '@coremarket.demo'],
            ['area' => 'Catalog', 'records' => $large ? 260 : 45, 'marker' => 'DEMO-* SKU/barcode'],
            ['area' => 'Operations', 'records' => $large ? 240 : 32, 'marker' => 'demo: POS references'],
            ['area' => 'Loyalty', 'records' => $large ? 180 : 30, 'marker' => 'demo: loyalty idempotency keys'],
            ['area' => 'Returns', 'records' => $large ? 35 : 2, 'marker' => 'DEMO-SR-*'],
            ['area' => 'Purchasing', 'records' => $large ? 120 : 11, 'marker' => 'DEMO-PO-*'],
            ['area' => 'Expenses/Accounting', 'records' => $large ? 180 : 12, 'marker' => 'DEMO-EXP-*'],
            ['area' => 'Runtime settings', 'records' => 4, 'marker' => 'local demo feature settings'],
        ];
    }

    private function datasetStructure(): array
    {
        return [
            'Users' => 'store admin, cashier, inventory manager, accountant, customers',
            'Catalog' => 'categories, brands, products, stocks, SKUs, barcodes, prices, costs',
            'Operations' => 'cashboxes, shifts, POS orders, cash and inventory movements',
            'Loyalty' => 'earn/redeem rule, accounts, earn, redeem and restore movements',
            'Returns' => 'completed sales returns with stock and loyalty reversal samples',
            'Purchasing' => 'suppliers, purchase orders and receipts',
            'Expenses/Accounting' => 'approved expenses; journals intentionally remain empty',
            'Runtime settings' => 'local demo markers only; no CorePilotOS tokens or snapshots',
        ];
    }
}
