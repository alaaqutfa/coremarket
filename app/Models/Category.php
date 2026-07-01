<?php

namespace App\Models;

use App;
use App\Traits\PreventDemoModeChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;

class Category extends Model
{
    use PreventDemoModeChanges;

    protected $with = ['category_translations','childrenCategories'];

    public function getTranslation($field = '', $lang = false)
    {
        $lang = $lang == false ? App::getLocale() : $lang;
        $category_translation = $this->category_translations->where('lang', $lang)->first();
        return $category_translation != null ? $category_translation->$field : $this->$field;
    }

    public function category_translations()
    {
        return $this->hasMany(CategoryTranslation::class);
    }

    public function coverImage()
    {
        return $this->belongsTo(Upload::class, 'cover_image');
    }

    public function catIcon()
    {
        return $this->belongsTo(Upload::class, 'icon');
    }

    public function products()
    {
        return $this->belongsToMany(Product::class, 'product_categories');
    }

    public function bannerImage()
    {
        return $this->belongsTo(Upload::class, 'banner');
    }

    public function classified_products()
    {
        return $this->hasMany(CustomerProduct::class);
    }

    public function categories()
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function childrenCategories()
    {
        return $this->hasMany(Category::class, 'parent_id')->with('categories');
    }

    public function parentCategory()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function attributes()
    {
        return $this->belongsToMany(Attribute::class);
    }

    public function sizeChart()
    {
        return $this->belongsTo(SizeChart::class, 'id', 'category_id');
    }
    protected static function booted()
    {
        if (!self::isAdminRoute()) {
            static::addGlobalScope('active', function (Builder $builder) {
                $builder->where('active', 1);
            });
        }
    }

    protected static function isAdminRoute()
    {
        return str_contains(url()->current(), 'admin') || str_contains(url()->current(), 'seller') || str_contains(url()->current(), 'shops');
    }
}
