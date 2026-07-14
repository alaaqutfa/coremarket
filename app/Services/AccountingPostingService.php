<?php
namespace App\Services;
use App\Models\AccountingAccount;
use App\Models\AccountingEvent;
use App\Models\JournalEntry;
use App\Models\TaxRate;
use DomainException;
use Illuminate\Support\Facades\DB;

class AccountingPostingService
{
    public function post(AccountingEvent $event, ?int $postedBy = null): JournalEntry
    {
        if ($event->journal_entry_id && ($journal = JournalEntry::find($event->journal_entry_id))) return $journal;
        return match ($event->event_type) {
            'sale' => $this->postSaleFromAccountingEvent($event, $postedBy), 'sale_return' => $this->postSaleReturnFromAccountingEvent($event, $postedBy),
            'purchase_receipt' => $this->postPurchaseReceiptFromAccountingEvent($event, $postedBy), 'expense' => $this->postExpenseFromAccountingEvent($event, $postedBy),
            default => throw new DomainException('Unsupported accounting event type.'),
        };
    }
    public function postSaleFromAccountingEvent(AccountingEvent $event, ?int $postedBy = null): JournalEntry
    {
        $amount=(float)$event->amount; $tax=(float)$event->tax_amount; $cost=(float)$event->cost_amount;
        return $this->createBalancedJournalEntry($event, "Sale event #{$event->id}", [
            ['1100',$amount+$tax,0], ['4000',0,$amount], ...($tax>0 ? [['2100',0,$tax]] : []), ...($cost>0 ? [['5000',$cost,0],['1200',0,$cost]] : []),
        ], $postedBy);
    }
    public function postSaleReturnFromAccountingEvent(AccountingEvent $event, ?int $postedBy = null): JournalEntry
    {
        $amount=(float)$event->amount; $tax=(float)$event->tax_amount; $cost=(float)$event->cost_amount;
        return $this->createBalancedJournalEntry($event, "Sales return event #{$event->id}", [
            ['4000',$amount,0], ...($tax>0 ? [['2100',$tax,0]] : []), ['9100',0,$amount+$tax], ...($cost>0 ? [['1200',$cost,0],['5000',0,$cost]] : []),
        ], $postedBy);
    }
    public function postPurchaseReceiptFromAccountingEvent(AccountingEvent $event, ?int $postedBy = null): JournalEntry
    {
        $cost=(float)($event->cost_amount ?? $event->amount); $tax=(float)$event->tax_amount;
        return $this->createBalancedJournalEntry($event, "Purchase receipt event #{$event->id}", [['1200',$cost,0], ...($tax>0 ? [['1300',$tax,0]] : []), ['2000',0,$cost+$tax]], $postedBy);
    }
    public function postExpenseFromAccountingEvent(AccountingEvent $event, ?int $postedBy = null): JournalEntry
    {
        $amount=(float)$event->amount; $tax=(float)$event->tax_amount;
        return $this->createBalancedJournalEntry($event, "Expense event #{$event->id}", [['6000',$amount,0], ...($tax>0 ? [['1300',$tax,0]] : []), ['9000',0,$amount+$tax]], $postedBy);
    }
    public function createBalancedJournalEntry(AccountingEvent $event, string $description, array $lines, ?int $postedBy = null): JournalEntry
    {
        return DB::transaction(function () use ($event, $description, $lines, $postedBy) {
            if ($existing=JournalEntry::query()->where('source_type', AccountingEvent::class)->where('source_id', $event->id)->first()) return $existing;
            $debit=round(collect($lines)->sum(fn($line)=>(float)$line[1]),2); $credit=round(collect($lines)->sum(fn($line)=>(float)$line[2]),2);
            if (abs($debit-$credit)>0.00001) throw new DomainException('Journal entry must be balanced before posting.');
            $entry=JournalEntry::query()->create(['source_type'=>AccountingEvent::class,'source_id'=>$event->id,'source_event_id'=>$event->id,'status'=>'posted','entry_date'=>optional($event->occurred_at)->toDateString() ?? now()->toDateString(),'description'=>$description,'currency'=>$event->currency,'total_debit'=>$debit,'total_credit'=>$credit,'posted_at'=>now(),'posted_by'=>$postedBy]);
            $entry->entry_number='JE-'.str_pad((string)$entry->id,8,'0',STR_PAD_LEFT); $entry->save();
            foreach ($lines as [$code,$lineDebit,$lineCredit]) { $account=AccountingAccount::query()->where('code',$code)->first(); if (!$account) throw new DomainException("Missing system account {$code}."); $entry->lines()->create(['accounting_account_id'=>$account->id,'debit'=>$lineDebit,'credit'=>$lineCredit,'currency'=>$event->currency,'reference_type'=>$event->reference_type,'reference_id'=>$event->reference_id]); }
            $event->update(['journal_entry_id'=>$entry->id,'journal_posted_at'=>now(),'journal_posting_status'=>'posted']);
            if ((float)$event->tax_amount !== 0) app(TaxCalculationService::class)->createSnapshot($event, null, $event->amount ?? 0, $event->currency, (float)$event->tax_amount);
            return $entry->load('lines.account');
        });
    }
}
