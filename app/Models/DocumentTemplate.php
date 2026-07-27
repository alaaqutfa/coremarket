<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DocumentTemplate extends Model
{
    protected $guarded = [];

    protected $casts = [
        'width_mm' => 'decimal:2',
        'height_mm' => 'decimal:2',
        'margin_top_mm' => 'decimal:2',
        'margin_right_mm' => 'decimal:2',
        'margin_bottom_mm' => 'decimal:2',
        'margin_left_mm' => 'decimal:2',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'settings' => 'array',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('template_type', $type);
    }
}
