<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerLedgerEntry extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:6',
        'exchange_rate' => 'decimal:6',
        'occurred_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function customer() { return $this->belongsTo(User::class, 'customer_id'); }
    public function order() { return $this->belongsTo(Order::class); }
    public function salesReturn() { return $this->belongsTo(SalesReturn::class); }
    public function payment() { return $this->belongsTo(CustomerPayment::class, 'customer_payment_id'); }
    public function allocations() { return $this->hasMany(CustomerPaymentAllocation::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
