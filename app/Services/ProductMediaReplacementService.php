<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Upload;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Replaces catalog media without deleting an upload that is still attached to
 * another catalog record.  Upload IDs are deliberately allowed to be shared
 * (for example via @thumbnail), so deletion is deferred until after the new
 * references have been saved.
 */
class ProductMediaReplacementService
{
    /** @return array<int, int> */
    public function productMediaIds(Product $product): array
    {
        return collect([
            $product->thumbnail_img,
            $product->meta_img,
            ...explode(',', (string) $product->photos),
            ...$product->stocks()->pluck('image')->all(),
        ])->filter(fn ($id) => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** @param array<int, int> $candidateIds */
    public function deleteUnreferenced(array $candidateIds): array
    {
        $deleted = 0;
        $retained = 0;

        foreach (array_unique(array_filter($candidateIds)) as $id) {
            if ($this->isReferenced((int) $id)) {
                $retained++;
                continue;
            }

            $upload = Upload::find($id);
            if (! $upload) {
                continue;
            }

            $this->deleteFile($upload->file_name);
            $upload->delete();
            $deleted++;
        }

        return compact('deleted', 'retained');
    }

    private function isReferenced(int $uploadId): bool
    {
        $id = (string) $uploadId;

        if (Product::query()->where('thumbnail_img', $id)->orWhere('meta_img', $id)->get()
            ->contains(fn (Product $product) => in_array($uploadId, $this->productMediaIds($product), true))) {
            return true;
        }

        if (Product::query()->whereNotNull('photos')->get()
            ->contains(fn (Product $product) => in_array($uploadId, $this->productMediaIds($product), true))) {
            return true;
        }

        if (ProductStock::query()->where('image', $id)->exists()) {
            return true;
        }

        foreach ([
            ['categories', ['cover_image', 'banner', 'icon']],
            ['brands', ['logo']],
            ['shops', ['logo', 'sliders']],
        ] as [$table, $columns]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column) && \DB::table($table)->where($column, $id)->exists()) {
                    return true;
                }
            }
        }

        return false;
    }

    private function deleteFile(?string $path): void
    {
        if (blank($path)) {
            return;
        }

        if (env('FILESYSTEM_DRIVER') !== 'local') {
            Storage::disk(env('FILESYSTEM_DRIVER'))->delete($path);
        }

        $local = public_path($path);
        if (File::exists($local)) {
            File::delete($local);
        }
    }
}
