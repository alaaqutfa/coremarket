<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'loyalty_points_redeemed')) {
                $table->unsignedInteger('loyalty_points_redeemed')->default(0);
            }

            if (! Schema::hasColumn('orders', 'loyalty_redemption_discount')) {
                $table->decimal('loyalty_redemption_discount', 20, 6)->default(0);
            }
        });

        Schema::table('loyalty_rules', function (Blueprint $table) {
            if (! Schema::hasColumn('loyalty_rules', 'redeem_points')) {
                $table->unsignedInteger('redeem_points')->nullable();
            }

            if (! Schema::hasColumn('loyalty_rules', 'redeem_value')) {
                $table->decimal('redeem_value', 20, 6)->nullable();
            }

            if (! Schema::hasColumn('loyalty_rules', 'min_redeem_points')) {
                $table->unsignedInteger('min_redeem_points')->default(0);
            }

            if (! Schema::hasColumn('loyalty_rules', 'max_redeem_points_per_order')) {
                $table->unsignedInteger('max_redeem_points_per_order')->nullable();
            }

            if (! Schema::hasColumn('loyalty_rules', 'max_redeem_percent')) {
                $table->decimal('max_redeem_percent', 8, 4)->nullable();
            }

            if (! Schema::hasColumn('loyalty_rules', 'allow_pos_redeem')) {
                $table->boolean('allow_pos_redeem')->default(true);
            }

            if (! Schema::hasColumn('loyalty_rules', 'allow_storefront_redeem')) {
                $table->boolean('allow_storefront_redeem')->default(false);
            }
        });
    }

    public function down(): void
    {
        $this->dropColumnsIfPresent('orders', [
            'loyalty_points_redeemed',
            'loyalty_redemption_discount',
        ]);

        $this->dropColumnsIfPresent('loyalty_rules', [
            'redeem_points',
            'redeem_value',
            'min_redeem_points',
            'max_redeem_points_per_order',
            'max_redeem_percent',
            'allow_pos_redeem',
            'allow_storefront_redeem',
        ]);
    }

    private function dropColumnsIfPresent(string $tableName, array $columns): void
    {
        $columns = array_values(array_filter($columns, fn (string $column) => Schema::hasColumn($tableName, $column)));

        if ($columns !== []) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
