<?php
namespace App\Console\Commands;
use App\Models\AccountingEvent;
use App\Models\OrderDetail;
use App\Models\SalesReturnItem;
use App\Models\TaxRate;
use App\Models\TaxSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
class CoreMarketVatAudit extends Command
{
    protected $signature='coremarket:vat-audit'; protected $description='Read-only VAT and tax snapshot audit';
    public function handle(): int
    {
        if (!Schema::hasTable('tax_snapshots') || !Schema::hasTable('tax_rates')) { $this->warn('VAT Core schema is not installed on this database. Legacy tax rows were not changed.'); return self::SUCCESS; }
        $details=OrderDetail::query()->where('tax','!=',0)->get(['id','tax']); $snapshotted=TaxSnapshot::query()->where('source_type', OrderDetail::class)->pluck('source_id');
        $returns=SalesReturnItem::query()->where('tax_amount','!=',0)->count();
        $this->info('CoreMarket VAT audit'); $this->table(['Check','Result'], [['Configured tax rates',TaxRate::count()],['Default price mode',config('coremarket.vat.default_price_mode','exclusive')],['Order details with tax but no snapshot',$details->whereNotIn('id',$snapshotted)->count()],['Sale events with tax but no snapshot',AccountingEvent::query()->where('event_type','sale')->where('tax_amount','!=',0)->whereNull('journal_entry_id')->count()],['Sales return tax reversals',$returns],['Purchase receipt events with tax',AccountingEvent::query()->where('event_type','purchase_receipt')->where('tax_amount','!=',0)->count()],['Expense events with tax',AccountingEvent::query()->where('event_type','expense')->where('tax_amount','!=',0)->count()]]);
        $this->line('Read-only: legacy product_taxes and checkout calculations were not changed.'); return self::SUCCESS;
    }
}
