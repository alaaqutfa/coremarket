<?php

namespace App\Models;

use App\Traits\PreventDemoModeChanges;
use Illuminate\Database\Eloquent\Model;

class ProductInformationSectionTranslation extends Model
{
    use PreventDemoModeChanges;

    protected $fillable = [
        'product_information_section_id',
        'lang',
        'title',
        'content',
    ];

    public function section()
    {
        return $this->belongsTo(ProductInformationSection::class, 'product_information_section_id');
    }
}
