<?php

use App\Http\Controllers\AddonController;
use App\Http\Controllers\Admin\Report\EarningReportController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\SellerRequestController;
use App\Http\Controllers\AizUploadController;
use App\Http\Controllers\AttributeController;
use App\Http\Controllers\BlogCategoryController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\BrandBulkUploadController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\BusinessSettingsController;
use App\Http\Controllers\CarrierController;
use App\Http\Controllers\CashboxController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CityController;
use App\Http\Controllers\CommissionController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\CountryController;
use App\Http\Controllers\CouponController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\CustomAlertController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerPackageController;
use App\Http\Controllers\CustomerProductController;
use App\Http\Controllers\DigitalProductController;
use App\Http\Controllers\DynamicPopupController;
use App\Http\Controllers\FlashDealController;
use App\Http\Controllers\LanguageController;
use App\Http\Controllers\LoyaltyPointsController;
use App\Http\Controllers\MeasurementPointsController;
use App\Http\Controllers\NewsletterController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NotificationTypeController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PickupPointController;
use App\Http\Controllers\ProductBulkUploadController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductFamilyController;
use App\Http\Controllers\ProductQueryController;
use App\Http\Controllers\PriceListController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SellerController;
use App\Http\Controllers\SellerWithdrawRequestController;
use App\Http\Controllers\SizeChartController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\StateController;
use App\Http\Controllers\SubscriberController;
use App\Http\Controllers\SupportTicketController;
use App\Http\Controllers\TaxController;
use App\Http\Controllers\UpdateController;
use App\Http\Controllers\WebsiteController;
use App\Http\Controllers\WebPosController;
use App\Http\Controllers\ZoneController;

/*
  |--------------------------------------------------------------------------
  | Admin Routes
  |--------------------------------------------------------------------------
  |
  | Here is where you can register admin routes for your application. These
  | routes are loaded by the RouteServiceProvider within a group which
  | contains the "web" middleware group. Now create something great!
  |
 */
//Update Routes
Route::controller(UpdateController::class)->group(function () {
    Route::post('/update', 'step0')->name('update')->middleware(['auth', 'admin', 'prevent-back-history', 'permission:system_update', 'restrict_store_admin']);
    Route::get('/update/step1', 'step1')->name('update.step1')->middleware(['auth', 'admin', 'prevent-back-history', 'permission:system_update', 'restrict_store_admin']);
    Route::get('/update/step2', 'step2')->name('update.step2')->middleware(['auth', 'admin', 'prevent-back-history', 'permission:system_update', 'restrict_store_admin']);
    Route::get('/update/step3', 'step3')->name('update.step3')->middleware(['auth', 'admin', 'prevent-back-history', 'permission:system_update', 'restrict_store_admin']);
    Route::post('/purchase_code', 'purchase_code')->name('update.code')->middleware(['auth', 'admin', 'prevent-back-history', 'permission:system_update', 'restrict_store_admin']);
});

Route::controller(OperationsController::class)->middleware(['auth', 'admin', 'restrict_store_admin'])->group(function () {
    Route::get('/operations', 'overview')->name('operations.overview');
    Route::get('/operations/inventory-movements', 'inventoryMovements')->name('operations.inventory-movements');
    Route::get('/operations/inventory', 'inventoryDashboard')->name('operations.inventory.dashboard');
    Route::get('/operations/inventory/stock', 'inventoryStock')->name('operations.inventory.stock');
    Route::get('/operations/inventory/barcode-lookup', 'barcodeLookup')->name('operations.inventory.barcode-lookup');
    Route::get('/operations/inventory/low-stock', 'lowStock')->name('operations.inventory.low-stock');
    Route::get('/operations/inventory/audit', 'inventoryAudit')->name('operations.inventory.audit');
    Route::get('/operations/inventory/policy', 'inventoryPolicy')->name('operations.inventory.policy');
    Route::post('/operations/inventory/policy', 'updateInventoryPolicy')->name('operations.inventory.policy.update');
    Route::get('/operations/inventory/stock/{productStock}/adjust', 'adjustStockForm')->name('operations.inventory.stock.adjust');
    Route::post('/operations/inventory/stock/{productStock}/adjust', 'adjustStock')->name('operations.inventory.stock.adjust.store');
    Route::get('/operations/suppliers', 'suppliers')->name('operations.suppliers');
    Route::get('/operations/suppliers/create', 'createSupplier')->name('operations.suppliers.create');
    Route::post('/operations/suppliers', 'storeSupplier')->name('operations.suppliers.store');
    Route::get('/operations/suppliers/{supplier}', 'showSupplier')->name('operations.suppliers.show');
    Route::get('/operations/suppliers/{supplier}/statement/pdf', 'supplierStatementPdf')->name('operations.suppliers.statement.pdf');
    Route::post('/operations/suppliers/{supplier}/payments', 'storeSupplierPayment')->name('operations.suppliers.payments.store');
    Route::get('/operations/suppliers/{supplier}/edit', 'editSupplier')->name('operations.suppliers.edit');
    Route::put('/operations/suppliers/{supplier}', 'updateSupplier')->name('operations.suppliers.update');
    Route::get('/operations/purchase-orders', 'purchaseOrders')->name('operations.purchase-orders');
    Route::get('/operations/purchase-orders/create', 'createPurchaseOrder')->name('operations.purchase-orders.create');
    Route::get('/operations/purchase-orders/product-lookup', 'purchaseOrderProductLookup')->name('operations.purchase-orders.product-lookup');
    Route::post('/operations/purchase-orders', 'storePurchaseOrder')->name('operations.purchase-orders.store');
    Route::get('/operations/purchase-orders/{purchaseOrder}', 'showPurchaseOrder')->name('operations.purchase-orders.show');
    Route::get('/operations/purchase-orders/{purchaseOrder}/pdf', 'purchaseOrderPdf')->name('operations.purchase-orders.pdf');
    Route::post('/operations/purchase-orders/{purchaseOrder}/receive', 'receivePurchaseOrder')->name('operations.purchase-orders.receive');
    Route::get('/operations/purchase-receipts', 'purchaseReceipts')->name('operations.purchase-receipts');
    Route::get('/operations/purchase-receipts/{purchaseReceipt}', 'showPurchaseReceipt')->name('operations.purchase-receipts.show');
    Route::get('/operations/purchase-receipts/{purchaseReceipt}/pdf', 'purchaseReceiptPdf')->name('operations.purchase-receipts.pdf');
    Route::get('/operations/purchase-returns', 'purchaseReturns')->name('operations.purchase-returns');
    Route::get('/operations/purchase-returns/create', 'createPurchaseReturn')->name('operations.purchase-returns.create');
    Route::post('/operations/purchase-returns', 'storePurchaseReturn')->name('operations.purchase-returns.store');
    Route::get('/operations/purchase-returns/{purchaseReturn}', 'showPurchaseReturn')->name('operations.purchase-returns.show');
    Route::post('/operations/purchase-returns/{purchaseReturn}/complete', 'completePurchaseReturn')->name('operations.purchase-returns.complete');
    Route::post('/operations/purchase-returns/{purchaseReturn}/cancel', 'cancelPurchaseReturn')->name('operations.purchase-returns.cancel');
    Route::get('/operations/sales-returns', 'salesReturns')->name('operations.sales-returns');
    Route::get('/operations/sales-returns/create', 'createSalesReturn')->name('operations.sales-returns.create');
    Route::post('/operations/sales-returns', 'storeSalesReturn')->name('operations.sales-returns.store');
    Route::get('/operations/sales-returns/{salesReturn}', 'showSalesReturn')->name('operations.sales-returns.show');
    Route::post('/operations/sales-returns/{salesReturn}/complete', 'completeSalesReturn')->name('operations.sales-returns.complete');
    Route::get('/operations/expenses', 'expenses')->name('operations.expenses');
    Route::get('/operations/expenses/create', 'createExpense')->name('operations.expenses.create');
    Route::post('/operations/expenses', 'storeExpense')->name('operations.expenses.store');
    Route::get('/operations/expenses/{expense}', 'showExpense')->name('operations.expenses.show');
    Route::post('/operations/expenses/{expense}/approve', 'approveExpense')->name('operations.expenses.approve');
    Route::get('/operations/accounting-summary', 'accountingSummary')->name('operations.accounting-summary');
    Route::get('/operations/accounting', 'accountingDashboard')->name('operations.accounting.dashboard');
    Route::get('/operations/accounting/core', 'accountingDashboard')->name('operations.accounting.core');
    Route::get('/operations/accounting/accounts', 'accountingAccounts')->name('operations.accounting.accounts');
    Route::get('/operations/accounting/accounts/{account}', 'showAccountingAccount')->name('operations.accounting.accounts.show');
    Route::get('/operations/accounting/journals', 'journals')->name('operations.accounting.journals');
    Route::get('/operations/accounting/journals/{journalEntry}', 'showJournal')->name('operations.accounting.journals.show');
    Route::post('/operations/accounting/journals/{journalEntry}/post', 'postJournal')->name('operations.accounting.journals.post');
    Route::get('/operations/accounting/events', 'accountingEvents')->name('operations.accounting.events');
    Route::post('/operations/accounting/events/{event}/post', 'postAccountingEvent')->name('operations.accounting.events.post');
    Route::get('/operations/accounting/general-ledger', 'generalLedger')->name('operations.accounting.general-ledger');
    Route::get('/operations/accounting/trial-balance', 'trialBalance')->name('operations.accounting.trial-balance');
    Route::get('/operations/accounting/profit-loss', 'profitLoss')->name('operations.accounting.profit-loss');
    Route::get('/operations/accounting/vat-snapshots', 'vatSnapshots')->name('operations.accounting.vat-snapshots');
    Route::get('/operations/accounting/vat-audit', 'vatAudit')->name('operations.accounting.vat-audit');
});

Route::controller(ProductFamilyController::class)->middleware(['auth', 'admin', 'restrict_store_admin'])->group(function () {
    Route::get('/operations/inventory/families', 'index')->name('operations.inventory.families.index');
    Route::get('/operations/inventory/families/create', 'create')->name('operations.inventory.families.create');
    Route::post('/operations/inventory/families', 'store')->name('operations.inventory.families.store');
    Route::get('/operations/inventory/families/{productFamily}/edit', 'edit')->name('operations.inventory.families.edit');
    Route::put('/operations/inventory/families/{productFamily}', 'update')->name('operations.inventory.families.update');
    Route::patch('/operations/inventory/families/{productFamily}/toggle', 'toggle')->name('operations.inventory.families.toggle');
});
Route::controller(CashboxController::class)->middleware(['auth', 'admin', 'restrict_store_admin'])->group(function () {
    Route::get('/operations/cashbox', 'dashboard')->name('operations.cashbox.dashboard');

    Route::get('/operations/cashboxes', 'cashboxes')->name('operations.cashboxes');
    Route::get('/operations/cashboxes/create', 'createCashbox')->name('operations.cashboxes.create');
    Route::post('/operations/cashboxes', 'storeCashbox')->name('operations.cashboxes.store');
    Route::get('/operations/cashboxes/{cashbox}', 'showCashbox')->name('operations.cashboxes.show');
    Route::get('/operations/cashboxes/{cashbox}/edit', 'editCashbox')->name('operations.cashboxes.edit');
    Route::put('/operations/cashboxes/{cashbox}', 'updateCashbox')->name('operations.cashboxes.update');

    Route::get('/operations/cash-shifts', 'shifts')->name('operations.cash-shifts');
    Route::get('/operations/cash-shifts/{shift}', 'showShift')->name('operations.cash-shifts.show');
    Route::get('/operations/cashboxes/{cashbox}/open-shift', 'openShiftForm')->name('operations.cash-shifts.open.form');
    Route::post('/operations/cashboxes/{cashbox}/open-shift', 'openShift')->name('operations.cash-shifts.open');
    Route::get('/operations/cash-shifts/{shift}/movements/create', 'createMovement')->name('operations.cash-movements.create');
    Route::post('/operations/cash-shifts/{shift}/movements', 'storeMovement')->name('operations.cash-movements.store');
    Route::get('/operations/cash-shifts/{shift}/close', 'closeShiftForm')->name('operations.cash-shifts.close.form');
    Route::post('/operations/cash-shifts/{shift}/close', 'closeShift')->name('operations.cash-shifts.close');

    Route::get('/operations/cash-movements', 'movements')->name('operations.cash-movements');
});

Route::controller(WebPosController::class)->middleware(['auth', 'admin', 'restrict_store_admin'])->group(function () {
    Route::get('/operations/pos', 'index')->name('operations.pos');
    Route::get('/operations/pos/search', 'search')->name('operations.pos.search');
    Route::get('/operations/pos/customers/search', 'customersSearch')->name('operations.pos.customers.search');
    Route::post('/operations/pos/checkout', 'checkout')->name('operations.pos.checkout');
    Route::get('/operations/pos/orders/{order}/receipt', 'receipt')->name('operations.pos.receipt');
});

Route::controller(PriceListController::class)->middleware(['auth', 'admin', 'restrict_store_admin'])->group(function () {
    Route::get('/operations/pricing/price-lists', 'index')->name('operations.price-lists.index');
    Route::get('/operations/pricing/price-lists/create', 'create')->name('operations.price-lists.create');
    Route::post('/operations/pricing/price-lists', 'store')->name('operations.price-lists.store');
    Route::get('/operations/pricing/price-lists/{priceList}', 'show')->name('operations.price-lists.show');
    Route::get('/operations/pricing/price-lists/{priceList}/edit', 'edit')->name('operations.price-lists.edit');
    Route::put('/operations/pricing/price-lists/{priceList}', 'update')->name('operations.price-lists.update');
    Route::post('/operations/pricing/price-lists/{priceList}/items', 'storeItem')->name('operations.price-lists.items.store');
    Route::delete('/operations/pricing/price-lists/{priceList}/items/{item}', 'destroyItem')->name('operations.price-lists.items.destroy');
    Route::post('/operations/pricing/price-lists/{priceList}/customers', 'assignCustomer')->name('operations.price-lists.customers.assign');
});

Route::controller(LoyaltyPointsController::class)->middleware(['auth', 'admin', 'restrict_store_admin'])->group(function () {
    Route::get('/operations/loyalty', 'dashboard')->name('operations.loyalty.dashboard');
    Route::get('/operations/loyalty/rules', 'rules')->name('operations.loyalty.rules');
    Route::post('/operations/loyalty/rules', 'storeRule')->name('operations.loyalty.rules.store');
    Route::get('/operations/loyalty/accounts', 'accounts')->name('operations.loyalty.accounts.index');
    Route::get('/operations/loyalty/accounts/{account}', 'showAccount')->name('operations.loyalty.accounts.show');
    Route::get('/operations/loyalty/movements', 'movements')->name('operations.loyalty.movements.index');
    Route::post('/operations/loyalty/accounts/{account}/adjust', 'adjust')->name('operations.loyalty.adjust');
    Route::get('/operations/loyalty/orders/{order}', 'orderTrace')->name('operations.loyalty.orders.show');
});

Route::get('/admin', [AdminController::class, 'admin_dashboard'])->name('admin.dashboard')->middleware(['auth', 'admin', 'prevent-back-history']);
Route::group(['prefix' => 'admin', 'middleware' => ['auth', 'admin', 'prevent-back-history', 'restrict_store_admin']], function () {

    // category
    Route::resource('categories', CategoryController::class);
    Route::controller(CategoryController::class)->group(function () {
        Route::get('/categories/edit/{id}', 'edit')->name('categories.edit');
        Route::get('/categories/destroy/{id}', 'destroy')->name('categories.destroy');
        Route::post('/categories/activated', 'updateActivated')->name('categories.activated');
        Route::post('/categories/featured', 'updateFeatured')->name('categories.featured');
        Route::post('/categories/categoriesByType', 'categoriesByType')->name('categories.categories-by-type');

        // category-wise discount set
        Route::get('/categories-wise-product-discount', 'categoriesWiseProductDiscount')->name('categories_wise_product_discount');
    });

    // Brand
    Route::resource('brands', BrandController::class);
    Route::controller(BrandController::class)->group(function () {
        Route::get('/brands/edit/{id}', 'edit')->name('brands.edit');
        Route::get('/brands/destroy/{id}', 'destroy')->name('brands.destroy');
    });

    Route::controller(BrandBulkUploadController::class)->group(function () {
        Route::get('/brand-bulk-upload', 'index')->name('brand_bulk_upload.index');
        Route::post('/brand-bulk-upload/store', 'bulk_upload')->name('brand_bulk_upload');
    });

    Route::controller(AdminController::class)->group(function () {
        Route::post('/dashboard/top-category-products-section', 'top_category_products_section')->name('dashboard.top_category_products_section');
        Route::post('/dashboard/inhouse-top-brands', 'inhouse_top_brands')->name('dashboard.inhouse_top_brands');
        Route::post('/dashboard/inhouse-top-categories', 'inhouse_top_categories')->name('dashboard.inhouse_top_categories');
        Route::post('/dashboard/top-sellers-products-section', 'top_sellers_products_section')->name('dashboard.top_sellers_products_section');
        Route::post('/dashboard/top-brands-products-section', 'top_brands_products_section')->name('dashboard.top_brands_products_section');
    });

    // Products
    Route::controller(ProductController::class)->group(function () {
        Route::get('/products/admin', 'admin_products')->name('products.admin');
        Route::get('/products/seller/{product_type}', 'seller_products')->name('products.seller')->middleware('coremarket_feature:sellers,0');
        Route::get('/products/all', 'all_products')->name('products.all');
        Route::get('/products/create', 'create')->name('products.create');
        Route::post('/products/store/', 'store')->name('products.store')->middleware('coremarket_license:manage_store');
        Route::get('/products/admin/{id}/edit', 'admin_product_edit')->name('products.admin.edit');
        Route::get('/products/seller/{id}/edit', 'seller_product_edit')->name('products.seller.edit')->middleware('coremarket_feature:sellers,0');
        Route::post('/products/update/{product}', 'update')->name('products.update')->middleware('coremarket_license:manage_store');
        Route::post('/products/todays_deal', 'updateTodaysDeal')->name('products.todays_deal');
        Route::post('/products/featured', 'updateFeatured')->name('products.featured');
        Route::post('/products/published', 'updatePublished')->name('products.published')->middleware('coremarket_license:manage_store');
        Route::post('/products/approved', 'updateProductApproval')->name('products.approved');
        Route::post('/products/get_products_by_subcategory', 'get_products_by_subcategory')->name('products.get_products_by_subcategory');
        Route::get('/products/duplicate/{id}', 'duplicate')->name('products.duplicate');
        Route::get('/products/destroy/{id}', 'destroy')->name('products.destroy');
        Route::post('/bulk-product-delete', 'bulk_product_delete')->name('bulk-product-delete');

        Route::post('/products/sku_combination', 'sku_combination')->name('products.sku_combination');
        Route::post('/products/sku_combination_edit', 'sku_combination_edit')->name('products.sku_combination_edit');
        Route::post('/products/add-more-choice-option', 'add_more_choice_option')->name('products.add-more-choice-option');
        Route::post('/product-search', 'product_search')->name('product.search');
        Route::post('/get-selected-products', 'get_selected_products')->name('get-selected-products');
        Route::post('/set-product-discount', 'setProductDiscount')->name('set_product_discount');
    });

    // Digital Product
    Route::resource('digitalproducts', DigitalProductController::class);
    Route::controller(DigitalProductController::class)->group(function () {
        Route::get('/digitalproducts/edit/{id}', 'edit')->name('digitalproducts.edit');
        Route::get('/digitalproducts/destroy/{id}', 'destroy')->name('digitalproducts.destroy');
        Route::get('/digitalproducts/download/{id}', 'download')->name('digitalproducts.download');
    });

    Route::controller(ProductBulkUploadController::class)->group(function () {
        //Product Export
        Route::get('/product-bulk-export', 'export')->name('product_bulk_export.index');

        //Product Bulk Upload
        Route::get('/product-bulk-upload/index', 'index')->name('product_bulk_upload.index');
        Route::post('/bulk-product-upload', 'bulk_upload')->name('bulk_product_upload')->middleware('coremarket_license:manage_store');
        Route::get('/product-csv-download/{type}', 'import_product')->name('product_csv.download');
        Route::get('/vendor-product-csv-download/{id}', 'import_vendor_product')->name('import_vendor_product.download');
        Route::group(['prefix' => 'bulk-upload/download'], function () {
            Route::get('/category', 'pdf_download_category')->name('pdf.download_category');
            Route::get('/brand', 'pdf_download_brand')->name('pdf.download_brand');
            Route::get('/seller', 'pdf_download_seller')->name('pdf.download_seller');
        });
    });

    // Seller
    Route::resource('sellers', SellerController::class)->middleware('coremarket_feature:sellers,0');
    Route::controller(SellerController::class)->middleware('coremarket_feature:sellers,0')->group(function () {
        Route::get('sellers_ban/{id}', 'ban')->name('sellers.ban');
        Route::get('/sellers/destroy/{id}', 'destroy')->name('sellers.destroy');
        Route::post('/bulk-seller-delete', 'bulk_seller_delete')->name('bulk-seller-delete');
        Route::get('/sellers/view/{id}/verification', 'show_verification_request')->name('sellers.show_verification_request');
        Route::get('/sellers/approve/{id}', 'approve_seller')->name('sellers.approve');
        Route::get('/sellers/reject/{id}', 'reject_seller')->name('sellers.reject');
        Route::get('/sellers/login/{id}', 'login')->name('sellers.login');
        Route::post('/sellers/payment_modal', 'payment_modal')->name('sellers.payment_modal');
        Route::post('/sellers/profile_modal', 'profile_modal')->name('sellers.profile_modal');
        Route::post('/sellers/approved', 'updateApproved')->name('sellers.approved');
    });

    // Seller Payment
    Route::controller(PaymentController::class)->middleware('coremarket_feature:sellers,0')->group(function () {
        Route::get('/seller/payments', 'payment_histories')->name('sellers.payment_histories');
        Route::get('/seller/payments/show/{id}', 'show')->name('sellers.payment_history');
    });

    // Seller Requests
    Route::controller(SellerRequestController::class)->middleware('coremarket_feature:sellers,0')->group(function () {
        Route::get('/seller/requests', 'index')->name('sellers.requests.index');
        Route::post('/seller/request/approved', 'updateApproval')->name('seller.requests.approved');
        Route::get('/seller/requests/{id}', 'destroy')->name('sellers.request.destroy');
    });

    // Seller Withdraw Request
    Route::resource('/withdraw_requests', SellerWithdrawRequestController::class)->middleware('coremarket_feature:sellers,0');
    Route::controller(SellerWithdrawRequestController::class)->middleware('coremarket_feature:sellers,0')->group(function () {
        Route::get('/withdraw_requests_all', 'index')->name('withdraw_requests_all');
        Route::post('/withdraw_request/payment_modal', 'payment_modal')->name('withdraw_request.payment_modal');
        Route::post('/withdraw_request/message_modal', 'message_modal')->name('withdraw_request.message_modal');
    });

    // Customer
    Route::resource('customers', CustomerController::class);
    Route::controller(CustomerController::class)->group(function () {
        Route::get('customers_ban/{customer}', 'ban')->name('customers.ban');
        Route::get('/customers/login/{id}', 'login')->name('customers.login');
        Route::get('/customers/destroy/{id}', 'destroy')->name('customers.destroy');
        Route::post('/bulk-customer-delete', 'bulk_customer_delete')->name('bulk-customer-delete');
    });

    // Newsletter
    Route::controller(NewsletterController::class)->group(function () {
        Route::get('/newsletter', 'index')->name('newsletters.index');
        Route::post('/newsletter/send', 'send')->name('newsletters.send');
        Route::post('/newsletter/test/smtp', 'testEmail')->name('test.smtp');
    });

    // Dynamic Popup
    Route::resource('dynamic-popups', DynamicPopupController::class);
    Route::controller(DynamicPopupController::class)->group(function () {
        Route::get('/dynamic-popups/destroy/{id}', 'destroy')->name('dynamic-popups.destroy');
        Route::post('/bulk-dynamic-popup-delete', 'bulk_dynamic_popup_delete')->name('bulk-dynamic-popup-delete');
        Route::post('/dynamic-popups-update-status', 'update_status')->name('dynamic-popups.update-status');
    });

    // Custom Alert
    Route::resource('custom-alerts', CustomAlertController::class);
    Route::controller(CustomAlertController::class)->group(function () {
        Route::get('/custom-alerts/destroy/{id}', 'destroy')->name('custom-alerts.destroy');
        Route::post('/bulk-custom-alerts-delete', 'bulk_custom_alerts_delete')->name('bulk-custom-alerts-delete');
        Route::post('/custom-alerts-update-status', 'update_status')->name('custom-alerts.update-status');
    });

    Route::resource('profile', ProfileController::class);

    // Business Settings
    Route::controller(BusinessSettingsController::class)->group(function () {
        Route::post('/business-settings/update', 'update')->name('business_settings.update');
        Route::post('/business-settings/update/activation', 'updateActivationSettings')->name('business_settings.update.activation');
        Route::post('/payment-activation', 'updatePaymentActivationSettings')->name('payment.activation');
        Route::get('/general-setting', 'general_setting')->name('general_setting.index');
        Route::get('/activation', 'activation')->name('activation.index');
        Route::get('/my-subscription', 'subscriptionOverview')->name('subscription.index')->middleware('coremarket_feature:subscription_page,0');
        Route::get('/payment-method', 'payment_method')->name('payment_method.index');
        Route::get('/file_system', 'file_system')->name('file_system.index');
        Route::get('/social-login', 'social_login')->name('social_login.index');
        Route::get('/smtp-settings', 'smtp_settings')->name('smtp_settings.index');
        Route::get('/google-analytics', 'google_analytics')->name('google_analytics.index');
        Route::get('/google-recaptcha', 'google_recaptcha')->name('google_recaptcha.index');
        Route::get('/google-map', 'google_map')->name('google-map.index');
        Route::get('/google-firebase', 'google_firebase')->name('google-firebase.index');

        //Facebook Settings
        Route::get('/facebook-chat', 'facebook_chat')->name('facebook_chat.index');
        Route::post('/facebook_chat', 'facebook_chat_update')->name('facebook_chat.update');
        Route::get('/facebook-comment', 'facebook_comment')->name('facebook-comment');
        Route::post('/facebook-comment', 'facebook_comment_update')->name('facebook-comment.update');
        Route::post('/facebook_pixel', 'facebook_pixel_update')->name('facebook_pixel.update');

        Route::post('/env_key_update', 'env_key_update')->name('env_key_update.update');
        Route::post('/payment_method_update', 'payment_method_update')->name('payment_method.update');
        Route::post('/google_analytics', 'google_analytics_update')->name('google_analytics.update');
        Route::post('/google_recaptcha', 'google_recaptcha_update')->name('google_recaptcha.update');
        Route::post('/google-map', 'google_map_update')->name('google-map.update');
        Route::post('/google-firebase', 'google_firebase_update')->name('google-firebase.update');

        Route::get('/verification/form', 'seller_verification_form')->name('seller_verification_form.index');
        Route::post('/verification/form', 'seller_verification_form_update')->name('seller_verification_form.update');
        Route::get('/vendor_commission', 'vendor_commission')->name('business_settings.vendor_commission');
        Route::post('/vendor_commission_update', 'vendor_commission_update')->name('business_settings.vendor_commission.update');

        //Shipping Configuration
        Route::get('/shipping_configuration', 'shipping_configuration')->name('shipping_configuration.index');
        Route::post('/shipping_configuration/update', 'shipping_configuration_update')->name('shipping_configuration.update');

        // Order Configuration
        Route::get('/order-configuration', 'order_configuration')->name('order_configuration.index');
    });


    //Currency
    Route::controller(CurrencyController::class)->group(function () {
        Route::get('/currency', 'currency')->name('currency.index');
        Route::post('/currency/update', 'updateCurrency')->name('currency.update');
        Route::post('/your-currency/update', 'updateYourCurrency')->name('your_currency.update');
        Route::get('/currency/create', 'create')->name('currency.create');
        Route::post('/currency/store', 'store')->name('currency.store');
        Route::post('/currency/currency_edit', 'edit')->name('currency.edit');
        Route::post('/currency/update_status', 'update_status')->name('currency.update_status');
    });

    //Tax
    Route::resource('tax', TaxController::class);
    Route::controller(TaxController::class)->group(function () {
        Route::get('/tax/edit/{id}', 'edit')->name('tax.edit');
        Route::get('/tax/destroy/{id}', 'destroy')->name('tax.destroy');
        Route::post('tax-status', 'change_tax_status')->name('taxes.tax-status');
    });

    // Language
    Route::resource('/languages', LanguageController::class);
    Route::controller(LanguageController::class)->group(function () {
        Route::post('/languages/{id}/update', 'update')->name('languages.update');
        Route::get('/languages/destroy/{id}', 'destroy')->name('languages.destroy');
        Route::post('/languages/update_rtl_status', 'update_rtl_status')->name('languages.update_rtl_status');
        Route::post('/languages/update-status', 'update_status')->name('languages.update-status');
        Route::post('/languages/key_value_store', 'key_value_store')->name('languages.key_value_store');

        //App Trasnlation
        Route::post('/languages/app-translations/import', 'importEnglishFile')->name('app-translations.import');
        Route::get('/languages/app-translations/show/{id}', 'showAppTranlsationView')->name('app-translations.show');
        Route::post('/languages/app-translations/key_value_store', 'storeAppTranlsation')->name('app-translations.store');
        Route::get('/languages/app-translations/export/{id}', 'exportARBFile')->name('app-translations.export');
    });


    // website setting
    Route::group(['prefix' => 'website'], function () {
        Route::controller(WebsiteController::class)->group(function () {
            Route::get('/footer', 'footer')->name('website.footer');
            Route::get('/header', 'header')->name('website.header');
            Route::get('/appearance', 'appearance')->name('website.appearance')->middleware('coremarket_feature:website_appearance,1');
            Route::get('/select-homepage', 'select_homepage')->name('website.select-homepage');
            Route::get('/authentication-layout-settings', 'authentication_layout_settings')->name('website.authentication-layout-settings');
            Route::get('/pages', 'pages')->name('website.pages');
        });

        Route::controller(LanguageController::class)->middleware('coremarket_feature:translations_limited,0')->group(function () {
            Route::get('/translations', 'limitedIndex')->name('website.translations.index');
            Route::get('/translations/{language}', 'limitedShow')->name('website.translations.show');
            Route::post('/translations/update', 'limitedKeyValueStore')->name('website.translations.update');
        });

        Route::controller(CurrencyController::class)->middleware('coremarket_feature:currencies_limited,0')->group(function () {
            Route::get('/currency-rates', 'limitedIndex')->name('website.currency-rates.index');
            Route::post('/currency-rates/update', 'limitedUpdate')->name('website.currency-rates.update');
        });

        // Custom Page
        Route::resource('custom-pages', PageController::class);
        Route::controller(PageController::class)->group(function () {
            Route::get('/custom-pages/edit/{id}', 'edit')->name('custom-pages.edit');
            Route::get('/custom-pages/destroy/{id}', 'destroy')->name('custom-pages.destroy');
        });
    });

    // Staff Roles
    Route::resource('roles', RoleController::class)->middleware('coremarket_feature:staff_management,1');
    Route::controller(RoleController::class)->middleware('coremarket_feature:staff_management,1')->group(function () {
        Route::get('/roles/edit/{id}', 'edit')->name('roles.edit');
        Route::get('/roles/destroy/{id}', 'destroy')->name('roles.destroy');

        // Add Permissiom
        Route::post('/roles/add_permission', 'add_permission')->name('roles.permission');
    });

    // Staff
    Route::resource('staffs', StaffController::class)->middleware('coremarket_feature:staff_management,1');
    Route::get('/staffs/destroy/{id}', [StaffController::class, 'destroy'])->name('staffs.destroy')->middleware('coremarket_feature:staff_management,1');

    // Flash Deal
    Route::resource('flash_deals', FlashDealController::class)->middleware('coremarket_feature:marketing_basic,0');
    Route::controller(FlashDealController::class)->middleware('coremarket_feature:marketing_basic,0')->group(function () {
        Route::get('/flash_deals/edit/{id}', 'edit')->name('flash_deals.edit');
        Route::get('/flash_deals/destroy/{id}', 'destroy')->name('flash_deals.destroy');
        Route::post('/flash_deals/update_status', 'update_status')->name('flash_deals.update_status');
        Route::post('/flash_deals/update_featured', 'update_featured')->name('flash_deals.update_featured');
        Route::post('/flash_deals/product_discount', 'product_discount')->name('flash_deals.product_discount');
        Route::post('/flash_deals/product_discount_edit', 'product_discount_edit')->name('flash_deals.product_discount_edit');
    });

    //Subscribers
    Route::controller(SubscriberController::class)->middleware('coremarket_feature:marketing_basic,0')->group(function () {
        Route::get('/subscribers', 'index')->name('subscribers.index');
        Route::get('/subscribers/destroy/{id}', 'destroy')->name('subscriber.destroy');
    });

    // Order
    Route::resource('orders', OrderController::class);
    Route::controller(OrderController::class)->group(function () {
        // All Orders
        Route::get('/all_orders', 'all_orders')->name('all_orders.index');
        Route::get('/inhouse-orders', 'all_orders')->name('inhouse_orders.index');
        Route::get('/seller_orders', 'all_orders')->name('seller_orders.index')->middleware('coremarket_feature:sellers,0');
        Route::get('orders_by_pickup_point', 'all_orders')->name('pick_up_point.index');

        Route::get('/orders/{id}/show', 'show')->name('all_orders.show');
        Route::get('/inhouse-orders/{id}/show', 'show')->name('inhouse_orders.show');
        Route::get('/seller_orders/{id}/show', 'show')->name('seller_orders.show')->middleware('coremarket_feature:sellers,0');
        Route::get('/orders_by_pickup_point/{id}/show', 'show')->name('pick_up_point.order_show');

        Route::post('/bulk-order-status', 'bulk_order_status')->name('bulk-order-status');

        Route::get('/orders/destroy/{id}', 'destroy')->name('orders.destroy');
        Route::post('/bulk-order-delete', 'bulk_order_delete')->name('bulk-order-delete');

        Route::get('/orders/destroy/{id}', 'destroy')->name('orders.destroy');
        Route::post('/orders/details', 'order_details')->name('orders.details');
        Route::post('/orders/update_delivery_status', 'update_delivery_status')->name('orders.update_delivery_status');
        Route::post('/orders/update_payment_status', 'update_payment_status')->name('orders.update_payment_status');
        Route::post('/orders/update_tracking_code', 'update_tracking_code')->name('orders.update_tracking_code');

        //Delivery Boy Assign
        Route::post('/orders/delivery-boy-assign', 'assign_delivery_boy')->name('orders.delivery-boy-assign');

        // Order bulk export
        Route::get('/order-bulk-export', 'orderBulkExport')->name('order-bulk-export');
    });

    Route::post('/pay_to_seller', [CommissionController::class, 'pay_to_seller'])->name('commissions.pay_to_seller');

    //Reports
    Route::controller(ReportController::class)->group(function () {
        Route::get('/in_house_sale_report', 'in_house_sale_report')->name('in_house_sale_report.index');
        Route::get('/seller_sale_report', 'seller_sale_report')->name('seller_sale_report.index')->middleware('coremarket_feature:reports_advanced,0');
        Route::get('/stock_report', 'stock_report')->name('stock_report.index');
        Route::get('/wish_report', 'wish_report')->name('wish_report.index')->middleware('coremarket_feature:reports_advanced,0');
        Route::get('/user_search_report', 'user_search_report')->name('user_search_report.index')->middleware('coremarket_feature:reports_advanced,0');
        Route::get('/commission-log', 'commission_history')->name('commission-log.index')->middleware('coremarket_feature:reports_advanced,0');
        Route::get('/wallet-history', 'wallet_transaction_history')->name('wallet-history.index')->middleware('coremarket_feature:reports_advanced,0');
    });

    // Earning Report
    Route::group(['prefix' => 'reports'], function () {
        Route::get('/earning-payout-report', [EarningReportController::class, 'index'])->name('earning_payout_report.index')->middleware('coremarket_feature:reports_basic,0');
        Route::post('/earning-payout-report/net-sales', [EarningReportController::class, 'net_sales'])->middleware('coremarket_feature:reports_basic,0');
        Route::post('/earning-payout-report/payouts', [EarningReportController::class, 'payouts'])->middleware('coremarket_feature:reports_basic,0');
        Route::post('/earning-payout-report/sale-analytic', [EarningReportController::class, 'sale_analytic'])->middleware('coremarket_feature:reports_basic,0');
        Route::post('/earning-payout-report/payout-analytic', [EarningReportController::class, 'payout_analytic'])->middleware('coremarket_feature:reports_basic,0');
    });

    //Blog Section
    //Blog cateory
    Route::resource('blog-category', BlogCategoryController::class)->middleware('coremarket_feature:blog,0');
    Route::get('/blog-category/destroy/{id}', [BlogCategoryController::class, 'destroy'])->name('blog-category.destroy')->middleware('coremarket_feature:blog,0');

    // Blog
    Route::resource('blog', BlogController::class)->middleware('coremarket_feature:blog,0');
    Route::controller(BlogController::class)->middleware('coremarket_feature:blog,0')->group(function () {
        Route::get('/blog/destroy/{id}', 'destroy')->name('blog.destroy');
        Route::post('/blog/change-status', 'change_status')->name('blog.change-status');
    });

    //Coupons
    Route::resource('coupon', CouponController::class)->middleware('coremarket_feature:marketing_basic,0');
    Route::controller(CouponController::class)->middleware('coremarket_feature:marketing_basic,0')->group(function () {
        Route::post('/coupon/update-status', 'updateStatus')->name('coupon.update_status');
        Route::get('/coupon/destroy/{id}', 'destroy')->name('coupon.destroy');

        //Coupon Form
        Route::post('/coupon/get_form', 'get_coupon_form')->name('coupon.get_coupon_form');
        Route::post('/coupon/get_form_edit', 'get_coupon_form_edit')->name('coupon.get_coupon_form_edit');
    });

    //Reviews
    Route::controller(ReviewController::class)->group(function () {
        Route::get('/reviews', 'index')->name('reviews.index');
        Route::post('/reviews/published', 'updatePublished')->name('reviews.published');
    });

    //Support_Ticket
    Route::controller(SupportTicketController::class)->middleware('coremarket_feature:support_basic,0')->group(function () {
        Route::get('support_ticket/', 'admin_index')->name('support_ticket.admin_index');
        Route::get('support_ticket/{id}/show', 'admin_show')->name('support_ticket.admin_show');
        Route::post('support_ticket/reply', 'admin_store')->name('support_ticket.admin_store');
    });

    //Pickup_Points
    Route::resource('pick_up_points', PickupPointController::class);
    Route::controller(PickupPointController::class)->group(function () {
        Route::get('/pick_up_points/edit/{id}', 'edit')->name('pick_up_points.edit');
        Route::get('/pick_up_points/destroy/{id}', 'destroy')->name('pick_up_points.destroy');
    });

    //conversation of seller customer
    Route::controller(ConversationController::class)->middleware('coremarket_feature:support_basic,0')->group(function () {
        Route::get('conversations', 'admin_index')->name('conversations.admin_index');
        Route::get('conversations/{id}/show', 'admin_show')->name('conversations.admin_show');
    });

    // product Queries show on Admin panel
    Route::controller(ProductQueryController::class)->middleware('coremarket_feature:support_advanced,0')->group(function () {
        Route::get('/product-queries', 'index')->name('product_query.index');
        Route::get('/product-queries/{id}', 'show')->name('product_query.show');
        Route::put('/product-queries/{id}', 'reply')->name('product_query.reply');
    });

    // Product Attribute
    Route::resource('attributes', AttributeController::class);
    Route::controller(AttributeController::class)->group(function () {
        Route::get('/attributes/edit/{id}', 'edit')->name('attributes.edit');
        Route::get('/attributes/destroy/{id}', 'destroy')->name('attributes.destroy');

        //Attribute Value
        Route::post('/store-attribute-value', 'store_attribute_value')->name('store-attribute-value');
        Route::get('/edit-attribute-value/{id}', 'edit_attribute_value')->name('edit-attribute-value');
        Route::post('/update-attribute-value/{id}', 'update_attribute_value')->name('update-attribute-value');
        Route::get('/destroy-attribute-value/{id}', 'destroy_attribute_value')->name('destroy-attribute-value');

        //Colors
        Route::get('/colors', 'colors')->name('colors');
        Route::post('/colors/store', 'store_color')->name('colors.store');
        Route::get('/colors/edit/{id}', 'edit_color')->name('colors.edit');
        Route::post('/colors/update/{id}', 'update_color')->name('colors.update');
        Route::get('/colors/destroy/{id}', 'destroy_color')->name('colors.destroy');
    });

    // Size Chart
    Route::resource('size-charts', SizeChartController::class);
    Route::get('/size-charts/destroy/{id}',  [SizeChartController::class, 'destroy'])->name('size-charts.destroy');
    Route::post('size-charts/get-combination',   [SizeChartController::class, 'get_combination'])->name('size-charts.get-combination');

    // Measurement Points
    Route::resource('measurement-points', MeasurementPointsController::class);
    Route::get('/measurement-points/destroy/{id}',  [MeasurementPointsController::class, 'destroy'])->name('measurement-points.destroy');

    // Addon
    Route::post('/addons/request', [AddonController::class, 'requestActivation'])->name('addons.request')->middleware('coremarket_feature:addon_requests,1');
    Route::resource('addons', AddonController::class)->middleware('coremarket_feature:addon_requests,1');
    Route::post('/addons/activation', [AddonController::class, 'activation'])->name('addons.activation')->middleware('coremarket_feature:addon_requests,1');

    //Customer Package
    Route::resource('customer_packages', CustomerPackageController::class);
    Route::controller(CustomerPackageController::class)->group(function () {
        Route::get('/customer_packages/edit/{id}', 'edit')->name('customer_packages.edit');
        Route::get('/customer_packages/destroy/{id}', 'destroy')->name('customer_packages.destroy');
    });

    //Classified Products
    Route::controller(CustomerProductController::class)->group(function () {
        Route::get('/classified_products', 'customer_product_index')->name('classified_products');
        Route::post('/classified_products/published', 'updatePublished')->name('classified_products.published');
        Route::get('/classified_products/destroy/{id}', 'destroy_by_admin')->name('classified_products.destroy');
    });

    // Countries
    Route::resource('countries', CountryController::class);
    Route::post('/countries/status', [CountryController::class, 'updateStatus'])->name('countries.status');

    // States
    Route::resource('states', StateController::class);
    Route::post('/states/status', [StateController::class, 'updateStatus'])->name('states.status');

    // Carriers
    Route::resource('carriers', CarrierController::class);
    Route::controller(CarrierController::class)->group(function () {
        Route::get('/carriers/destroy/{id}', 'destroy')->name('carriers.destroy');
        Route::post('/carriers/update_status', 'updateStatus')->name('carriers.update_status');
    });


    // Zones
    Route::resource('zones', ZoneController::class);
    Route::get('/zones/destroy/{id}', [ZoneController::class, 'destroy'])->name('zones.destroy');

    Route::resource('cities', CityController::class);
    Route::controller(CityController::class)->group(function () {
        Route::get('/cities/edit/{id}', 'edit')->name('cities.edit');
        Route::get('/cities/destroy/{id}', 'destroy')->name('cities.destroy');
        Route::post('/cities/status', 'updateStatus')->name('cities.status');
    });

    Route::get('/system/update', function () {
        abort_unless(filter_var(env('ENABLE_LEGACY_MAINTENANCE_ROUTES', false), FILTER_VALIDATE_BOOL), 404);
        return view('backend.system.update');
    })->name('system_update');
    Route::view('/system/server-status', 'backend.system.server_status')->name('system_server');
    Route::get('/system/import-demo-data', function () {
        abort_unless(filter_var(env('ENABLE_LEGACY_MAINTENANCE_ROUTES', false), FILTER_VALIDATE_BOOL), 404);
        return view('backend.system.import_demo_data');
    })->name('import_demo_data');

    Route::post('/import-data', [BusinessSettingsController::class, 'import_data'])->name('import_data');

    // uploaded files
    Route::resource('/uploaded-files', AizUploadController::class)->middleware('coremarket_feature:uploads_manager,1');
    Route::controller(AizUploadController::class)->middleware('coremarket_feature:uploads_manager,1')->group(function () {
        Route::any('/uploaded-files/file-info', 'file_info')->name('uploaded-files.info');
        Route::get('/uploaded-files/destroy/{id}', 'destroy')->name('uploaded-files.destroy');
        Route::post('/bulk-uploaded-files-delete', 'bulk_uploaded_files_delete')->name('bulk-uploaded-files-delete');
        Route::get('/all-file', 'all_file');
    });

    Route::controller(NotificationController::class)->group(function () {
        Route::get('/all-notifications', 'adminIndex')->name('admin.all-notifications');
        Route::get('/notification-settings', 'notificationSettings')->name('notification.settings')->middleware('coremarket_feature:marketing_advanced,0');

        Route::post('/notifications/bulk-delete', 'bulkDeleteAdmin')->name('admin.notifications.bulk_delete');
        Route::get('/notification/read-and-redirect/{id}', 'readAndRedirect')->name('admin.notification.read-and-redirect');

        Route::get('/custom-notification', 'customNotification')->name('custom_notification')->middleware('coremarket_feature:marketing_advanced,0');
        Route::post('/custom-notification/send', 'sendCustomNotification')->name('custom_notification.send')->middleware('coremarket_feature:marketing_advanced,0');

        Route::get('/custom-notification/history', 'customNotificationHistory')->name('custom_notification.history')->middleware('coremarket_feature:marketing_advanced,0');
        Route::get('/custom-notifications.delete/{identifier}', 'customNotificationSingleDelete')->name('custom-notifications.delete')->middleware('coremarket_feature:marketing_advanced,0');
        Route::post('/custom-notifications.bulk_delete', 'customNotificationBulkDelete')->name('custom-notifications.bulk_delete')->middleware('coremarket_feature:marketing_advanced,0');
        Route::post('/custom-notified-customers-list', 'customNotifiedCustomersList')->name('custom_notified_customers_list')->middleware('coremarket_feature:marketing_advanced,0');

    });

    Route::resource('notification-type', NotificationTypeController::class)->middleware('coremarket_feature:marketing_advanced,0');
    Route::controller(NotificationTypeController::class)->middleware('coremarket_feature:marketing_advanced,0')->group(function () {
        Route::get('/notification-type/edit/{id}', 'edit')->name('notification-type.edit');
        Route::post('/notification-type/update-status', 'updateStatus')->name('notification-type.update-status');
        Route::get('/notification-type/destroy/{id}', 'destroy')->name('notification-type.destroy');
        Route::post('/notification-type/bulk_delete', 'bulkDelete')->name('notifications-type.bulk_delete');
        Route::post('/notification-type.get-default-text', 'getDefaulText')->name('notification_type.get_default_text');
    });

    Route::get('/clear-cache', [AdminController::class, 'clearCache'])->name('cache.clear');

    Route::get('/admin-permissions', [RoleController::class, 'create_admin_permissions']);
});
