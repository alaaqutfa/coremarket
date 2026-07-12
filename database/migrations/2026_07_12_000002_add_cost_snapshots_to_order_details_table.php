<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('order_details', function (Blueprint $table) {
            $table->decimal('cost_price', 20, 6)->nullable()->after('price');
            $table->string('cost_source', 40)->nullable()->after('cost_price');
            $table->decimal('total_cost', 20, 6)->nullable()->after('cost_source');
            $table->decimal('profit_amount', 20, 6)->nullable()->after('total_cost');
            $table->timestamp('profit_calculated_at')->nullable()->after('profit_amount');
        });
    }

    public function down(): void
    {
        Schema::table('order_details', function (Blueprint $table) {
            $table->dropColumn([
                'cost_price',
                'cost_source',
                'total_cost',
                'profit_amount',
                'profit_calculated_at',
            ]);
        });
    }
};
