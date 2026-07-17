<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'cashier_shift_id')) {
                $table->unsignedBigInteger('cashier_shift_id')->nullable()->index();
            }

            if (! Schema::hasColumn('orders', 'cashbox_id')) {
                $table->unsignedBigInteger('cashbox_id')->nullable()->index();
            }

            if (! Schema::hasColumn('orders', 'cashier_id')) {
                $table->unsignedBigInteger('cashier_id')->nullable()->index();
            }

            if (! Schema::hasColumn('orders', 'paid_amount')) {
                $table->decimal('paid_amount', 20, 6)->nullable();
            }

            if (! Schema::hasColumn('orders', 'change_amount')) {
                $table->decimal('change_amount', 20, 6)->nullable();
            }

            if (! Schema::hasColumn('orders', 'pos_receipt_number')) {
                $table->string('pos_receipt_number')->nullable()->unique();
            }

            if (! Schema::hasColumn('orders', 'pos_request_key')) {
                $table->string('pos_request_key')->nullable()->unique();
            }

            if (! Schema::hasColumn('orders', 'pos_metadata')) {
                $table->json('pos_metadata')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        $columns = [
            'cashier_shift_id',
            'cashbox_id',
            'cashier_id',
            'paid_amount',
            'change_amount',
            'pos_receipt_number',
            'pos_request_key',
            'pos_metadata',
        ];

        Schema::table('orders', function (Blueprint $table) use ($columns) {
            foreach ($columns as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
