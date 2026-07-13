<?php
namespace App\Services;
use App\Models\AccountingEvent;
class AccountingSummaryService
{
    public function summary(): array
    {
        $events = AccountingEvent::query()->where('status','posted');
        $sum = fn(string $type, string $column) => (float) (clone $events)->where('event_type',$type)->sum($column);
        $salesRevenue=$sum('sale','amount'); $cogs=$sum('sale','cost_amount'); $gross=$sum('sale','profit_amount'); $returns=$sum('sale_return','profit_amount'); $expenses=$sum('expense','amount');
        return ['sales_revenue'=>$salesRevenue,'cogs'=>$cogs,'gross_profit'=>$gross,'sales_returns_impact'=>$returns,'purchase_cost_received'=>$sum('purchase_receipt','cost_amount'),'expenses'=>$expenses,'net_lite_profit'=>$gross-$returns-$expenses,'unknown_cost_events'=>(clone $events)->where('event_type','sale')->whereNull('cost_amount')->count()];
    }
}
