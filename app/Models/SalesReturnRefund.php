<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesReturnRefund extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:6',
        'metadata' => 'array',
    ];

    public function salesReturn() { return $this->belongsTo(SalesReturn::class); }
    public function order() { return $this->belongsTo(Order::class); }
    public function customer() { return $this->belongsTo(User::class, 'customer_id'); }
    public function refundedBy() { return $this->belongsTo(User::class, 'refunded_by_user_id'); }
    public function cashbox() { return $this->belongsTo(Cashbox::class); }
    public function cashierShift() { return $this->belongsTo(CashierShift::class); }
    public function cashMovement() { return $this->belongsTo(CashMovement::class); }
    public function customerLedgerEntry() { return $this->belongsTo(CustomerLedgerEntry::class); }
}
