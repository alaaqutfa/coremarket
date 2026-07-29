<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerAccountProfile extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_credit_allowed' => 'boolean',
        'credit_limit' => 'decimal:6',
        'payment_terms_days' => 'integer',
        'metadata' => 'array',
        'last_reviewed_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
