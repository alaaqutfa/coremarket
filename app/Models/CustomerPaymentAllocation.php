<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerPaymentAllocation extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:6',
    ];

    public function payment() { return $this->belongsTo(CustomerPayment::class, 'customer_payment_id'); }
    public function ledgerEntry() { return $this->belongsTo(CustomerLedgerEntry::class, 'customer_ledger_entry_id'); }
    public function order() { return $this->belongsTo(Order::class); }
}
