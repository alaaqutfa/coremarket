<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseReceiptItem extends Model
{
    protected $guarded = [];

    protected $casts = ['quantity_received' => 'decimal:6', 'unit_cost' => 'decimal:6', 'total_cost' => 'decimal:6'];

    public function receipt() { return $this->belongsTo(PurchaseReceipt::class, 'purchase_receipt_id'); }
    public function purchaseOrderItem() { return $this->belongsTo(PurchaseOrderItem::class); }
    public function accountingEvents() { return $this->morphMany(AccountingEvent::class, 'reference'); }
}
