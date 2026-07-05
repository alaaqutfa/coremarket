<?php

namespace App\Services;

use App\Models\Address;
use App\Models\BusinessSetting;
use App\Models\Category;
use App\Models\CategoryTranslation;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductStock;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role as SpatieRole;

class CoreMarketQaStoreSeedService
{
    public function buildPlan(array $options = []): array
    {
        $customerConfig = config('coremarket.qa_seed.customer');
        $storeAdminConfig = config('coremarket.qa_seed.store_admin');
        $categoryConfig = config('coremarket.qa_seed.category');
        $productConfig = config('coremarket.qa_seed.product');
        $settingsConfig = config('coremarket.qa_seed.settings', []);

        $adminUser = User::query()->where('user_type', 'admin')->first();
        $storeAdminRole = SpatieRole::query()
            ->where('name', config('coremarket.access.store_admin_role', 'store_admin'))
            ->where('guard_name', 'web')
            ->first();

        $customer = User::query()->where('email', $customerConfig['email'])->first();
        $storeAdmin = User::query()->where('email', $storeAdminConfig['email'])->first();
        $category = Category::query()->withoutGlobalScopes()->where('slug', $categoryConfig['slug'])->first();
        $product = Product::query()->where('slug', $productConfig['slug'])->first();
        $addressDefaults = $this->resolveAddressDefaults();

        return [
            'dry_run' => ! (bool) ($options['apply'] ?? false),
            'apply_requested' => (bool) ($options['apply'] ?? false),
            'confirmed' => (bool) ($options['confirm_qa_seed'] ?? false),
            'default_password' => $options['password'] ?: config('coremarket.qa_seed.default_password'),
            'admin_user' => $adminUser,
            'store_admin_role' => $storeAdminRole,
            'address_defaults' => $addressDefaults,
            'settings' => collect($settingsConfig)->map(function ($value, $key) {
                $existing = BusinessSetting::query()->where('type', $key)->whereNull('lang')->first();

                return [
                    'key' => $key,
                    'target_value' => (string) $value,
                    'current_value' => $existing?->value,
                    'action' => $existing ? 'update' : 'create',
                ];
            })->values()->all(),
            'resources' => [
                [
                    'resource' => 'QA Customer',
                    'identifier' => $customerConfig['email'],
                    'action' => $customer ? 'update' : 'create',
                    'note' => 'Local-only customer account for checkout testing.',
                ],
                [
                    'resource' => 'QA Store Admin',
                    'identifier' => $storeAdminConfig['email'],
                    'action' => $storeAdmin ? 'update' : 'create',
                    'note' => $storeAdminRole
                        ? 'Local-only store admin account for order visibility testing.'
                        : 'store_admin role is missing; account creation will be skipped until the role exists.',
                ],
                [
                    'resource' => 'QA Category',
                    'identifier' => $categoryConfig['slug'],
                    'action' => $category ? 'update' : 'create',
                    'note' => 'Single visible category for storefront QA.',
                ],
                [
                    'resource' => 'QA Product',
                    'identifier' => $productConfig['slug'],
                    'action' => $product ? 'update' : 'create',
                    'note' => 'Published in-house product with COD enabled and stock attached.',
                ],
            ],
            'flow' => [
                'Home',
                'Product',
                'Cart',
                'Login',
                'Checkout',
                'COD Order',
                'Admin Order View',
            ],
        ];
    }

    public function validateApplyRequirements(array $plan): array
    {
        $errors = [];

        if (! $plan['apply_requested']) {
            return $errors;
        }

        if (! $plan['confirmed']) {
            $errors[] = 'Apply mode requires --confirm-qa-seed.';
        }

        if (! $plan['admin_user']) {
            $errors[] = 'No admin user is available to own the QA product.';
        }

        if (! $plan['store_admin_role']) {
            $errors[] = 'The store_admin role is missing in the current database.';
        }

        return $errors;
    }

    public function applySeed(array $plan): array
    {
        $results = [];

        foreach ($plan['settings'] as $setting) {
            $existing = BusinessSetting::query()->where('type', $setting['key'])->whereNull('lang')->first();

            if ($existing) {
                $existing->value = $setting['target_value'];
                $existing->save();
                $results[] = [
                    'resource' => 'setting',
                    'identifier' => $setting['key'],
                    'status' => 'updated',
                ];
            } else {
                $businessSetting = new BusinessSetting();
                $businessSetting->type = $setting['key'];
                $businessSetting->lang = null;
                $businessSetting->value = $setting['target_value'];
                $businessSetting->save();
                $results[] = [
                    'resource' => 'setting',
                    'identifier' => $setting['key'],
                    'status' => 'created',
                ];
            }
        }

        $customer = $this->upsertCustomer($plan);
        $results[] = [
            'resource' => 'QA Customer',
            'identifier' => $customer->email,
            'status' => 'saved',
        ];

        $storeAdmin = $this->upsertStoreAdmin($plan);
        $results[] = [
            'resource' => 'QA Store Admin',
            'identifier' => $storeAdmin->email,
            'status' => 'saved',
        ];

        $category = $this->upsertCategory();
        $results[] = [
            'resource' => 'QA Category',
            'identifier' => $category->slug,
            'status' => 'saved',
        ];

        $product = $this->upsertProduct($plan, $category);
        $results[] = [
            'resource' => 'QA Product',
            'identifier' => $product->slug,
            'status' => 'saved',
        ];

        return $results;
    }

    protected function upsertCustomer(array $plan): User
    {
        $customerConfig = config('coremarket.qa_seed.customer');

        $customer = User::query()->where('email', $customerConfig['email'])->first() ?: new User();
        $customer->email = $customerConfig['email'];
        $customer->user_type = 'customer';
        $customer->name = $customerConfig['name'];
        $customer->password = Hash::make($plan['default_password']);
        $customer->phone = $customerConfig['phone'];
        $customer->country = $plan['address_defaults']['country_name'] ?? 'QA CoreMarket Country';
        $customer->state = $plan['address_defaults']['state_name'] ?? 'QA CoreMarket State';
        $customer->city = $plan['address_defaults']['city_name'] ?? 'QA CoreMarket City';
        $customer->address = 'QA CoreMarket Address';
        $customer->country_code = 'QA';
        $customer->postal_code = '00000';
        $customer->email_verified_at = now();
        $customer->banned = 0;
        $customer->save();

        $address = Address::query()
            ->where('user_id', $customer->id)
            ->where('address', 'QA CoreMarket Address')
            ->first() ?: new Address();
        $address->user_id = $customer->id;
        $address->address = 'QA CoreMarket Address';
        $address->country_id = $plan['address_defaults']['country_id'];
        $address->state_id = $plan['address_defaults']['state_id'];
        $address->city_id = $plan['address_defaults']['city_id'];
        $address->phone = $customerConfig['phone'];
        $address->postal_code = '00000';
        $address->set_default = 1;
        $address->save();

        return $customer;
    }

    protected function upsertStoreAdmin(array $plan): User
    {
        $storeAdminConfig = config('coremarket.qa_seed.store_admin');
        $role = $plan['store_admin_role'];

        $storeAdmin = User::query()->where('email', $storeAdminConfig['email'])->first() ?: new User();
        $storeAdmin->email = $storeAdminConfig['email'];
        $storeAdmin->user_type = 'staff';
        $storeAdmin->name = $storeAdminConfig['name'];
        $storeAdmin->password = Hash::make($plan['default_password']);
        $storeAdmin->phone = $storeAdminConfig['phone'];
        $storeAdmin->email_verified_at = now();
        $storeAdmin->banned = 0;
        $storeAdmin->save();

        $storeAdmin->syncRoles([$role]);

        $staff = Staff::query()->where('user_id', $storeAdmin->id)->first() ?: new Staff();
        $staff->user_id = $storeAdmin->id;
        $staff->role_id = $role->id;
        $staff->save();

        return $storeAdmin;
    }

    protected function upsertCategory(): Category
    {
        $categoryConfig = config('coremarket.qa_seed.category');

        $category = Category::query()->withoutGlobalScopes()->where('slug', $categoryConfig['slug'])->first() ?: new Category();
        $category->slug = $categoryConfig['slug'];
        $category->parent_id = 0;
        $category->level = 0;
        $category->name = $categoryConfig['name'];
        $category->order_level = 0;
        $category->commision_rate = 0;
        $category->banner = null;
        $category->icon = null;
        $category->cover_image = null;
        $category->featured = 0;
        $category->active = 1;
        $category->top = 0;
        $category->digital = 0;
        $category->meta_title = $categoryConfig['name'];
        $category->meta_description = 'QA CoreMarket category for local storefront order testing.';
        $category->save();

        CategoryTranslation::query()->updateOrCreate(
            [
                'category_id' => $category->id,
                'lang' => 'en',
            ],
            [
                'name' => $categoryConfig['name'],
            ]
        );

        return $category;
    }

    protected function upsertProduct(array $plan, Category $category): Product
    {
        $productConfig = config('coremarket.qa_seed.product');

        $product = Product::query()->where('slug', $productConfig['slug'])->first() ?: new Product();
        $product->slug = $productConfig['slug'];
        $product->name = $productConfig['name'];
        $product->added_by = 'admin';
        $product->user_id = $plan['admin_user']->id;
        $product->category_id = $category->id;
        $product->brand_id = null;
        $product->photos = '';
        $product->thumbnail_img = null;
        $product->video_provider = 'youtube';
        $product->video_link = '';
        $product->tags = 'qa,coremarket';
        $product->description = $productConfig['description'];
        $product->unit_price = $productConfig['unit_price'];
        $product->wholesale_price = $productConfig['unit_price'];
        $product->purchase_price = $productConfig['unit_price'];
        $product->variant_product = 0;
        $product->attributes = '[]';
        $product->choice_options = '[]';
        $product->colors = '[]';
        $product->variations = '[]';
        $product->todays_deal = 0;
        $product->published = 1;
        $product->approved = 1;
        $product->stock_visibility_state = 'quantity';
        $product->cash_on_delivery = 1;
        $product->featured = 0;
        $product->seller_featured = 0;
        $product->current_stock = $productConfig['current_stock'];
        $product->unit = $productConfig['unit'];
        $product->weight = 0;
        $product->min_qty = 1;
        $product->low_stock_quantity = 1;
        $product->discount = 0;
        $product->discount_type = 'flat';
        $product->discount_start_date = null;
        $product->discount_end_date = null;
        $product->tax = 0;
        $product->tax_type = 'flat';
        $product->shipping_type = 'flat_rate';
        $product->shipping_cost = 0;
        $product->is_quantity_multiplied = 0;
        $product->est_shipping_days = 1;
        $product->num_of_sale = 0;
        $product->meta_title = $productConfig['name'];
        $product->meta_description = $productConfig['description'];
        $product->meta_img = null;
        $product->pdf = null;
        $product->rating = 0;
        $product->barcode = 'QA-COREMARKET-001';
        $product->digital = 0;
        $product->auction_product = 0;
        $product->file_name = null;
        $product->file_path = null;
        $product->external_link = '';
        $product->external_link_btn = '';
        $product->wholesale_product = 0;
        $product->frequently_bought_selection_type = 'product';
        $product->save();

        DB::table('product_categories')->updateOrInsert(
            [
                'product_id' => $product->id,
                'category_id' => $category->id,
            ],
            []
        );

        ProductStock::query()->updateOrCreate(
            [
                'product_id' => $product->id,
                'variant' => '',
            ],
            [
                'sku' => 'QA-COREMARKET-SKU',
                'price' => $productConfig['unit_price'],
                'qty' => $productConfig['current_stock'],
                'image' => null,
            ]
        );

        return $product;
    }

    protected function resolveAddressDefaults(): array
    {
        $country = DB::table('countries')->where('status', 1)->orderBy('id')->first()
            ?: DB::table('countries')->orderBy('id')->first();
        $state = $country
            ? DB::table('states')->where('country_id', $country->id)->where('status', 1)->orderBy('id')->first()
                ?: DB::table('states')->where('country_id', $country->id)->orderBy('id')->first()
            : null;
        $city = $state
            ? DB::table('cities')->where('state_id', $state->id)->where('status', 1)->orderBy('id')->first()
                ?: DB::table('cities')->where('state_id', $state->id)->orderBy('id')->first()
            : null;

        return [
            'country_id' => $country->id ?? null,
            'country_name' => $country->name ?? null,
            'state_id' => $state->id ?? null,
            'state_name' => $state->name ?? null,
            'city_id' => $city->id ?? null,
            'city_name' => $city->name ?? null,
        ];
    }
}
