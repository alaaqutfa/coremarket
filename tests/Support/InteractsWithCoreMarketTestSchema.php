<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait InteractsWithCoreMarketTestSchema
{
    protected static bool $coreMarketBusinessSettingsEnsured = false;
    protected static bool $coreMarketPermissionTablesEnsured = false;
    protected static bool $coreMarketLegacyUserColumnsEnsured = false;
    protected static bool $coreMarketSupportTablesEnsured = false;

    protected function ensureBusinessSettingsTable(): void
    {
        if (self::$coreMarketBusinessSettingsEnsured && Schema::hasTable('business_settings')) {
            return;
        }

        if (Schema::hasTable('business_settings')) {
            self::$coreMarketBusinessSettingsEnsured = true;
            return;
        }

        try {
            Schema::create('business_settings', function (Blueprint $table) {
                $table->id();
                $table->string('type')->nullable();
                $table->string('lang')->nullable();
                $table->longText('value')->nullable();
                $table->timestamps();
            });
        } catch (\Throwable $exception) {
            if (! Schema::hasTable('business_settings')) {
                throw $exception;
            }
        }

        self::$coreMarketBusinessSettingsEnsured = true;
    }

    protected function ensurePermissionTables(): void
    {
        if (
            self::$coreMarketPermissionTablesEnsured
            && Schema::hasTable('permissions')
            && Schema::hasTable('roles')
            && Schema::hasTable('model_has_permissions')
            && Schema::hasTable('model_has_roles')
            && Schema::hasTable('role_has_permissions')
        ) {
            return;
        }

        if (! Schema::hasTable('permissions')) {
            try {
                Schema::create('permissions', function (Blueprint $table) {
                    $table->bigIncrements('id');
                    $table->string('name');
                    $table->string('guard_name');
                    $table->timestamps();
                    $table->unique(['name', 'guard_name']);
                });
            } catch (\Throwable $exception) {
                if (! Schema::hasTable('permissions')) {
                    throw $exception;
                }
            }
        }

        if (! Schema::hasTable('roles')) {
            try {
                Schema::create('roles', function (Blueprint $table) {
                    $table->bigIncrements('id');
                    $table->string('name');
                    $table->string('guard_name');
                    $table->timestamps();
                    $table->unique(['name', 'guard_name']);
                });
            } catch (\Throwable $exception) {
                if (! Schema::hasTable('roles')) {
                    throw $exception;
                }
            }
        }

        if (! Schema::hasTable('model_has_permissions')) {
            try {
                Schema::create('model_has_permissions', function (Blueprint $table) {
                    $table->unsignedBigInteger('permission_id');
                    $table->string('model_type');
                    $table->unsignedBigInteger('model_id');
                    $table->index(['model_id', 'model_type'], 'model_has_permissions_model_id_model_type_index');
                    $table->primary(['permission_id', 'model_id', 'model_type'], 'model_has_permissions_permission_model_type_primary');
                });
            } catch (\Throwable $exception) {
                if (! Schema::hasTable('model_has_permissions')) {
                    throw $exception;
                }
            }
        }

        if (! Schema::hasTable('model_has_roles')) {
            try {
                Schema::create('model_has_roles', function (Blueprint $table) {
                    $table->unsignedBigInteger('role_id');
                    $table->string('model_type');
                    $table->unsignedBigInteger('model_id');
                    $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');
                    $table->primary(['role_id', 'model_id', 'model_type'], 'model_has_roles_role_model_type_primary');
                });
            } catch (\Throwable $exception) {
                if (! Schema::hasTable('model_has_roles')) {
                    throw $exception;
                }
            }
        }

        if (! Schema::hasTable('role_has_permissions')) {
            try {
                Schema::create('role_has_permissions', function (Blueprint $table) {
                    $table->unsignedBigInteger('permission_id');
                    $table->unsignedBigInteger('role_id');
                    $table->primary(['permission_id', 'role_id'], 'role_has_permissions_permission_id_role_id_primary');
                });
            } catch (\Throwable $exception) {
                if (! Schema::hasTable('role_has_permissions')) {
                    throw $exception;
                }
            }
        }

        self::$coreMarketPermissionTablesEnsured = true;
    }

    protected function ensureLegacyUserColumns(): void
    {
        if (self::$coreMarketLegacyUserColumnsEnsured && Schema::hasTable('users') && Schema::hasColumn('users', 'user_type')) {
            return;
        }

        if (! Schema::hasTable('users')) {
            return;
        }

        if (! Schema::hasColumn('users', 'user_type')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('user_type')->nullable();
            });
        }

        self::$coreMarketLegacyUserColumnsEnsured = true;
    }

    protected function ensureAdminSupportTables(): void
    {
        if (
            self::$coreMarketSupportTablesEnsured
            && Schema::hasTable('translations')
            && Schema::hasTable('products')
            && Schema::hasTable('categories')
            && Schema::hasTable('category_translations')
            && Schema::hasTable('orders')
            && Schema::hasTable('carts')
            && Schema::hasTable('uploads')
            && Schema::hasTable('addons')
            && Schema::hasTable('languages')
            && Schema::hasTable('currencies')
            && Schema::hasTable('countries')
            && Schema::hasTable('tickets')
            && Schema::hasTable('conversations')
            && Schema::hasTable('custom_alerts')
            && Schema::hasTable('shops')
        ) {
            return;
        }

        if (! Schema::hasTable('translations')) {
            try {
                Schema::create('translations', function (Blueprint $table) {
                    $table->id();
                    $table->string('lang')->nullable();
                    $table->string('lang_key')->nullable();
                    $table->longText('lang_value')->nullable();
                    $table->timestamps();
                });
            } catch (\Throwable $exception) {
                if (! Schema::hasTable('translations')) {
                    throw $exception;
                }
            }
        }

        if (! Schema::hasTable('products')) {
            try {
                Schema::create('products', function (Blueprint $table) {
                    $table->id();
                    $table->boolean('auction_product')->default(0);
                    $table->boolean('wholesale_product')->default(0);
                    $table->boolean('published')->default(0);
                    $table->timestamps();
                });
            } catch (\Throwable $exception) {
                if (! Schema::hasTable('products')) {
                    throw $exception;
                }
            }
        }

        if (! Schema::hasTable('categories')) {
            try {
                Schema::create('categories', function (Blueprint $table) {
                    $table->id();
                    $table->string('name')->nullable();
                    $table->string('slug')->nullable();
                    $table->unsignedBigInteger('parent_id')->default(0);
                    $table->integer('level')->default(0);
                    $table->integer('order_level')->default(0);
                    $table->boolean('active')->default(1);
                    $table->unsignedBigInteger('cover_image')->nullable();
                    $table->unsignedBigInteger('icon')->nullable();
                    $table->unsignedBigInteger('banner')->nullable();
                    $table->timestamps();
                });
            } catch (\Throwable $exception) {
                if (! Schema::hasTable('categories')) {
                    throw $exception;
                }
            }
        }

        if (! Schema::hasTable('category_translations')) {
            try {
                Schema::create('category_translations', function (Blueprint $table) {
                    $table->id();
                    $table->unsignedBigInteger('category_id');
                    $table->string('lang')->nullable();
                    $table->string('name')->nullable();
                    $table->timestamps();
                });
            } catch (\Throwable $exception) {
                if (! Schema::hasTable('category_translations')) {
                    throw $exception;
                }
            }
        }

        if (! Schema::hasTable('orders')) {
            try {
                Schema::create('orders', function (Blueprint $table) {
                    $table->id();
                    $table->timestamps();
                });
            } catch (\Throwable $exception) {
                if (! Schema::hasTable('orders')) {
                    throw $exception;
                }
            }
        }

        if (! Schema::hasTable('carts')) {
            try {
                Schema::create('carts', function (Blueprint $table) {
                    $table->id();
                    $table->unsignedBigInteger('user_id')->nullable();
                    $table->string('temp_user_id')->nullable();
                    $table->unsignedBigInteger('owner_id')->nullable();
                    $table->unsignedBigInteger('product_id')->nullable();
                    $table->unsignedBigInteger('address_id')->nullable();
                    $table->string('variation')->nullable();
                    $table->integer('quantity')->default(1);
                    $table->decimal('price', 20, 2)->default(0);
                    $table->decimal('tax', 20, 2)->default(0);
                    $table->decimal('shipping_cost', 20, 2)->default(0);
                    $table->decimal('discount', 20, 2)->default(0);
                    $table->string('coupon_code')->nullable();
                    $table->boolean('coupon_applied')->default(0);
                    $table->boolean('status')->default(1);
                    $table->timestamps();
                });
            } catch (\Throwable $exception) {
                if (! Schema::hasTable('carts')) {
                    throw $exception;
                }
            }
        }

        if (! Schema::hasTable('uploads')) {
            try {
                Schema::create('uploads', function (Blueprint $table) {
                    $table->id();
                    $table->softDeletes();
                    $table->timestamps();
                });
            } catch (\Throwable $exception) {
                if (! Schema::hasTable('uploads')) {
                    throw $exception;
                }
            }
        }

        if (! Schema::hasTable('tickets')) {
            try {
                Schema::create('tickets', function (Blueprint $table) {
                    $table->id();
                    $table->unsignedBigInteger('user_id')->nullable();
                    $table->boolean('viewed')->default(1);
                    $table->timestamps();
                });
            } catch (\Throwable $exception) {
                if (! Schema::hasTable('tickets')) {
                    throw $exception;
                }
            }
        }

        if (! Schema::hasTable('conversations')) {
            try {
                Schema::create('conversations', function (Blueprint $table) {
                    $table->id();
                    $table->unsignedBigInteger('receiver_id')->nullable();
                    $table->boolean('receiver_viewed')->default(1);
                    $table->timestamps();
                });
            } catch (\Throwable $exception) {
                if (! Schema::hasTable('conversations')) {
                    throw $exception;
                }
            }
        }

        if (! Schema::hasTable('custom_alerts')) {
            try {
                Schema::create('custom_alerts', function (Blueprint $table) {
                    $table->id();
                    $table->boolean('status')->default(0);
                    $table->string('background_color')->nullable();
                    $table->string('text_color')->nullable();
                    $table->longText('description')->nullable();
                    $table->timestamps();
                });
            } catch (\Throwable $exception) {
                if (! Schema::hasTable('custom_alerts')) {
                    throw $exception;
                }
            }
        }

        if (! Schema::hasTable('shops')) {
            try {
                Schema::create('shops', function (Blueprint $table) {
                    $table->id();
                    $table->unsignedBigInteger('user_id')->nullable();
                    $table->string('name')->nullable();
                    $table->string('slug')->nullable();
                    $table->boolean('verification_status')->default(1);
                    $table->longText('verification_info')->nullable();
                    $table->decimal('num_of_sale', 20, 2)->default(0);
                    $table->timestamps();
                });
            } catch (\Throwable $exception) {
                if (! Schema::hasTable('shops')) {
                    throw $exception;
                }
            }
        }

        if (! Schema::hasTable('addons')) {
            try {
                Schema::create('addons', function (Blueprint $table) {
                    $table->id();
                    $table->string('name')->nullable();
                    $table->timestamps();
                });
            } catch (\Throwable $exception) {
                if (! Schema::hasTable('addons')) {
                    throw $exception;
                }
            }
        }

        if (! Schema::hasTable('languages')) {
            try {
                Schema::create('languages', function (Blueprint $table) {
                    $table->id();
                    $table->string('name')->nullable();
                    $table->string('code')->nullable();
                    $table->string('app_lang_code')->nullable();
                    $table->boolean('rtl')->default(0);
                    $table->boolean('status')->default(1);
                    $table->timestamps();
                });
            } catch (\Throwable $exception) {
                if (! Schema::hasTable('languages')) {
                    throw $exception;
                }
            }
        }

        if (! Schema::hasTable('currencies')) {
            try {
                Schema::create('currencies', function (Blueprint $table) {
                    $table->id();
                    $table->string('name')->nullable();
                    $table->string('symbol')->nullable();
                    $table->string('code')->nullable();
                    $table->decimal('exchange_rate', 20, 10)->default(1);
                    $table->boolean('status')->default(1);
                    $table->timestamps();
                });
            } catch (\Throwable $exception) {
                if (! Schema::hasTable('currencies')) {
                    throw $exception;
                }
            }
        }

        if (! Schema::hasTable('countries')) {
            try {
                Schema::create('countries', function (Blueprint $table) {
                    $table->id();
                    $table->string('name')->nullable();
                    $table->string('code')->nullable();
                    $table->boolean('status')->default(1);
                    $table->timestamps();
                });
            } catch (\Throwable $exception) {
                if (! Schema::hasTable('countries')) {
                    throw $exception;
                }
            }
        }

        if (Schema::hasTable('languages') && ! DB::table('languages')->where('code', 'en')->exists()) {
            DB::table('languages')->insert([
                'name' => 'English',
                'code' => 'en',
                'app_lang_code' => 'en',
                'rtl' => 0,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (Schema::hasTable('currencies') && ! DB::table('currencies')->where('code', 'USD')->exists()) {
            DB::table('currencies')->insert([
                'name' => 'US Dollar',
                'symbol' => '$',
                'code' => 'USD',
                'exchange_rate' => 1,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (Schema::hasTable('countries') && ! DB::table('countries')->where('code', 'US')->exists()) {
            DB::table('countries')->insert([
                'name' => 'United States',
                'code' => 'US',
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (Schema::hasTable('categories') && ! DB::table('categories')->where('slug', 'demo-category')->exists()) {
            $categoryId = DB::table('categories')->insertGetId([
                'name' => 'Demo Category',
                'slug' => 'demo-category',
                'parent_id' => 0,
                'level' => 0,
                'order_level' => 1,
                'active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (Schema::hasTable('category_translations')) {
                DB::table('category_translations')->insert([
                    'category_id' => $categoryId,
                    'lang' => 'en',
                    'name' => 'Demo Category',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        if (Schema::hasTable('business_settings')) {
            $defaultCurrencyId = DB::table('currencies')->where('code', 'USD')->value('id');

            if ($defaultCurrencyId !== null) {
                DB::table('business_settings')->updateOrInsert(
                    ['type' => 'system_default_currency'],
                    [
                        'value' => (string) $defaultCurrencyId,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }

            foreach ([
                'homepage_select' => 'metro',
                'site_name' => 'CoreMarket',
                'website_name' => 'CoreMarket',
            ] as $type => $value) {
                DB::table('business_settings')->updateOrInsert(
                    ['type' => $type],
                    [
                        'value' => $value,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }

        self::$coreMarketSupportTablesEnsured = true;
    }
}
