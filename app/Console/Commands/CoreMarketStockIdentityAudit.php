<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CoreMarketStockIdentityAudit extends Command
{
    protected $signature = 'coremarket:stock-identity-audit';

    protected $description = 'Run a read-only barcode, SKU, and stock consistency audit';

    public function handle(): int
    {
        if (! Schema::hasTable('products') || ! Schema::hasTable('product_stocks')) {
            $this->error('Product identity tables are unavailable.');

            return self::FAILURE;
        }

        $rows = [
            ['Products without barcode', DB::table('products')->whereNull('barcode')->orWhere('barcode', '')->count()],
            ['Variants without barcode', DB::table('product_stocks')->where('variant', '!=', '')->where(fn ($query) => $query->whereNull('barcode')->orWhere('barcode', ''))->count()],
            ['Duplicate product barcodes', $this->duplicateCount('products', 'barcode')],
            ['Duplicate variant barcodes', Schema::hasColumn('product_stocks', 'barcode') ? $this->duplicateCount('product_stocks', 'barcode') : '[column missing]'],
            ['Duplicate variant SKUs', $this->duplicateCount('product_stocks', 'sku')],
            ['Current stock mismatches', $this->stockMismatchCount()],
            ['Movements linked to missing stock', $this->movementsWithMissingStockCount()],
        ];

        $this->info('CoreMarket stock identity audit (read-only)');
        $this->newLine();
        $this->table(['Check', 'Count'], $rows);
        $this->line('No product, stock, order, or movement rows were changed.');

        return self::SUCCESS;
    }

    private function duplicateCount(string $table, string $column): int
    {
        return DB::table($table)
            ->select($column)
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->groupBy($column)
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();
    }

    private function stockMismatchCount(): int
    {
        return DB::table('products')
            ->leftJoin('product_stocks', 'product_stocks.product_id', '=', 'products.id')
            ->select('products.id')
            ->groupBy('products.id', 'products.current_stock')
            ->havingRaw('products.current_stock <> COALESCE(SUM(product_stocks.qty), 0)')
            ->get()
            ->count();
    }

    private function movementsWithMissingStockCount(): int
    {
        if (! Schema::hasTable('inventory_movements')) {
            return 0;
        }

        return DB::table('inventory_movements')
            ->leftJoin('product_stocks', 'product_stocks.id', '=', 'inventory_movements.product_stock_id')
            ->whereNotNull('inventory_movements.product_stock_id')
            ->whereNull('product_stocks.id')
            ->count();
    }
}
