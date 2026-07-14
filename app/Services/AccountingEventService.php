<?php
namespace App\Services;
use App\Models\AccountingEvent;
use App\Models\Expense;
use App\Models\OrderDetail;
use App\Models\PurchaseReceiptItem;
use App\Models\SalesReturnItem;

class AccountingEventService
{
    public function recordSale(OrderDetail $detail, ?int $createdBy = null): AccountingEvent { return $this->record('sale', 'income', OrderDetail::class, $detail->id, ['order_id'=>$detail->order_id,'order_detail_id'=>$detail->id,'amount'=>$detail->price,'cost_amount'=>$detail->total_cost,'tax_amount'=>$detail->tax,'profit_amount'=>$detail->profit_amount,'occurred_at'=>$detail->created_at,'created_by'=>$createdBy,'metadata'=>['cost_source'=>$detail->cost_source]]); }
    public function recordSalesReturn(SalesReturnItem $item, ?int $createdBy = null): AccountingEvent { return $this->record('sale_return', 'expense', SalesReturnItem::class, $item->id, ['order_id'=>$item->order_id,'order_detail_id'=>$item->order_detail_id,'sales_return_id'=>$item->sales_return_id,'sales_return_item_id'=>$item->id,'amount'=>(float)$item->unit_price*(float)$item->quantity,'cost_amount'=>$item->total_cost,'tax_amount'=>$item->tax_amount,'profit_amount'=>$item->profit_reversal_amount,'occurred_at'=>$item->updated_at,'created_by'=>$createdBy,'metadata'=>$item->metadata]); }
    public function recordPurchaseReceipt(PurchaseReceiptItem $item, ?int $createdBy = null): AccountingEvent { return $this->record('purchase_receipt', 'expense', PurchaseReceiptItem::class, $item->id, ['purchase_order_id'=>$item->purchaseOrderItem?->purchase_order_id,'purchase_receipt_id'=>$item->purchase_receipt_id,'inventory_movement_id'=>$item->inventory_movement_id,'amount'=>$item->total_cost,'cost_amount'=>$item->total_cost,'tax_amount'=>$item->purchaseOrderItem?->tax_amount,'occurred_at'=>$item->created_at,'created_by'=>$createdBy]); }
    public function recordExpense(Expense $expense, ?int $createdBy = null): AccountingEvent { return $this->record('expense', 'expense', Expense::class, $expense->id, ['amount'=>$expense->amount,'currency'=>$expense->currency,'occurred_at'=>$expense->expense_date ?? $expense->updated_at,'created_by'=>$createdBy,'metadata'=>['expense_category_id'=>$expense->expense_category_id,'status'=>$expense->status]]); }
    public function approveExpense(Expense $expense, ?int $approvedBy = null): AccountingEvent { $expense->status='approved'; $expense->approved_by=$approvedBy; $expense->save(); return $this->recordExpense($expense, $approvedBy); }
    private function record(string $type, string $direction, string $referenceType, int $referenceId, array $values): AccountingEvent { return AccountingEvent::query()->firstOrCreate(['reference_type'=>$referenceType,'reference_id'=>$referenceId,'event_type'=>$type], array_merge(['direction'=>$direction,'status'=>'posted','posted_at'=>now()], $values)); }
}
