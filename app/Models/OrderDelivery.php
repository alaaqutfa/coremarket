<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderDelivery extends Model
{
    protected $guarded = [];

    protected $casts = [
        'cod_amount' => 'decimal:6',
        'cod_collected_amount' => 'decimal:6',
        'assigned_at' => 'datetime',
        'picked_up_at' => 'datetime',
        'out_for_delivery_at' => 'datetime',
        'delivered_at' => 'datetime',
        'failed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function deliveryUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivery_user_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(StoreBranch::class, 'branch_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderDeliveryEvent::class)->orderByDesc('created_at')->orderByDesc('id');
    }
}
