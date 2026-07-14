<?php
namespace App\Console\Commands;
use App\Models\AccountingAccount;
use App\Models\AccountingEvent;
use App\Models\JournalEntry;
use App\Models\TaxSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
class CoreMarketAccountingCoreAudit extends Command
{
    protected $signature='coremarket:accounting-core-audit {--apply} {--confirm}'; protected $description='Read-only audit of CoreMarket accounting events and journal entries';
    public function handle(): int
    {
        if ($this->option('apply')) { $this->error('Apply mode is intentionally unavailable; this command never posts or backfills runtime data.'); return self::FAILURE; }
        if (!Schema::hasTable('accounting_accounts') || !Schema::hasTable('journal_entries')) { $this->warn('Accounting Core schema is not installed on this database. No data was changed.'); return self::SUCCESS; }
        $eventsWithoutJournal=AccountingEvent::query()->whereNull('journal_entry_id')->count();
        $unbalanced=JournalEntry::query()->whereRaw('ABS(total_debit - total_credit) > 0.00001')->count();
        $duplicates=JournalEntry::query()->whereNotNull('source_type')->whereNotNull('source_id')->selectRaw('source_type, source_id, COUNT(*) count')->groupBy('source_type','source_id')->having('count','>',1)->count();
        $missingAccounts=collect(['1000','1100','1200','1300','2000','2100','4000','5000','6000','9000','9100'])->filter(fn($code)=>!AccountingAccount::query()->where('code',$code)->exists())->implode(', ');
        $this->info('CoreMarket accounting core audit'); $this->table(['Check','Result'], [['Accounting events without journal',$eventsWithoutJournal],['Unbalanced journals',$unbalanced],['Duplicate source journals',$duplicates],['Tax snapshots',TaxSnapshot::count()],['Missing system accounts',$missingAccounts ?: 'none']]);
        $this->line('Read-only: no events, journals, tax snapshots, or runtime data were changed.'); return self::SUCCESS;
    }
}
