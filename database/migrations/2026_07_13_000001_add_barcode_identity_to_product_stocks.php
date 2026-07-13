<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('product_stocks', function (Blueprint $table) {
            $table->string('barcode', 255)->nullable()->after('sku');
        });

        // Older client databases can contain duplicate identities. Validation still
        // protects new writes when a legacy duplicate prevents an index from being added.
        $this->addUniqueIndexWhenSafe('product_stocks', 'barcode', 'product_stocks_barcode_unique');
        $this->addUniqueIndexWhenSafe('product_stocks', 'sku', 'product_stocks_sku_unique');
        $this->addUniqueIndexWhenSafe('products', 'barcode', 'products_barcode_unique');
    }

    public function down(): void
    {
        $this->dropIndexIfPresent('products', 'products_barcode_unique');

        $this->dropIndexIfPresent('product_stocks', 'product_stocks_barcode_unique');
        $this->dropIndexIfPresent('product_stocks', 'product_stocks_sku_unique');

        Schema::table('product_stocks', function (Blueprint $table) {
            $table->dropColumn('barcode');
        });
    }

    private function addUniqueIndexWhenSafe(string $table, string $column, string $index): void
    {
        $hasDuplicates = DB::table($table)
            ->select($column)
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->groupBy($column)
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if (! $hasDuplicates) {
            Schema::table($table, function (Blueprint $schema) use ($column, $index) {
                $schema->unique($column, $index);
            });
        }
    }

    private function dropIndexIfPresent(string $table, string $index): void
    {
        $exists = collect(DB::select("SHOW INDEX FROM `{$table}`"))
            ->contains(fn ($row) => ($row->Key_name ?? null) === $index);

        if ($exists) {
            Schema::table($table, function (Blueprint $schema) use ($index) {
                $schema->dropUnique($index);
            });
        }
    }
};
