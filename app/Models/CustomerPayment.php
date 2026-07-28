<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerPayment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:6',
        'metadata' => 'array',
    ];

    public function customer() { return $this->belongsTo(User::class, 'customer_id'); }
    public function receiver() { return $this->belongsTo(User::class, 'received_by_user_id'); }
    public function cashbox() { return $this->belongsTo(Cashbox::class); }
    public function shift() { return $this->belongsTo(CashierShift::class, 'cashier_shift_id'); }
    public function cashMovement() { return $this->belongsTo(CashMovement::class); }
    public function ledgerEntries() { return $this->hasMany(CustomerLedgerEntry::class); }
    public function allocations() { return $this->hasMany(CustomerPaymentAllocation::class); }
}
