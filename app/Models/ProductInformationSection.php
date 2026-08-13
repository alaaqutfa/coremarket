<?php

namespace App\Models;

use App;
use App\Traits\PreventDemoModeChanges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ProductInformationSection extends Model
{
    use PreventDemoModeChanges;

    protected $with = ['translations'];

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function translations()
    {
        return $this->hasMany(ProductInformationSectionTranslation::class);
    }

    public function getTranslation(string $field, $lang = false): ?string
    {
        $lang = $lang ?: App::getLocale();
        $translation = $this->translations->firstWhere('lang', $lang)
            ?: $this->translations->firstWhere('lang', env('DEFAULT_LANGUAGE'));

        return $translation?->{$field};
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
