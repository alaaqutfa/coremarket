<?php

namespace App\Services;

use App\Models\AccountingAccount;
use App\Models\AccountingEvent;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\OrderDetail;
use App\Models\SalesReturnItem;
use App\Models\TaxRate;
use App\Models\TaxSnapshot;
use Illuminate\Database\Eloquent\Builder;

class AccountingReportService
{
    public function dashboardStats(): array
    {
        $summary = app(AccountingSummaryService::class)->summary();

        return array_merge($summary, [
            'accounts' => AccountingAccount::count(),
            'posted_journals' => JournalEntry::query()->where('status', 'posted')->count(),
            'draft_journals' => JournalEntry::query()->where('status', 'draft')->count(),
            'unbalanced_journals' => JournalEntry::query()->whereRaw('ABS(total_debit - total_credit) > 0.00001')->count(),
            'events_without_journal' => AccountingEvent::query()->whereNull('journal_entry_id')->count(),
            'vat_snapshots' => TaxSnapshot::count(),
        ]);
    }

    public function journalRows(array $filters = []): Builder
    {
        $query = JournalEntry::query()->with('lines.account')->latest('entry_date')->latest('id');
        foreach (['status', 'source_type'] as $filter) if (! empty($filters[$filter])) $query->where($filter, $filters[$filter]);
        if (! empty($filters['from'])) $query->whereDate('entry_date', '>=', $filters['from']);
        if (! empty($filters['to'])) $query->whereDate('entry_date', '<=', $filters['to']);
        if (! empty($filters['account_id'])) $query->whereHas('lines', fn (Builder $line) => $line->where('accounting_account_id', $filters['account_id']));
        if (! empty($filters['unbalanced'])) $query->whereRaw('ABS(total_debit - total_credit) > 0.00001');
        return $query;
    }

    public function eventRows(array $filters = []): Builder
    {
        $query = AccountingEvent::query()->latest('occurred_at')->latest('id');
        foreach (['event_type', 'journal_posting_status'] as $filter) if (! empty($filters[$filter])) $query->where($filter, $filters[$filter]);
        if (! empty($filters['without_journal'])) $query->whereNull('journal_entry_id');
        if (! empty($filters['from'])) $query->whereDate('occurred_at', '>=', $filters['from']);
        if (! empty($filters['to'])) $query->whereDate('occurred_at', '<=', $filters['to']);
        return $query;
    }

    public function generalLedger(?int $accountId, array $filters = []): array
    {
        $account = $accountId ? AccountingAccount::findOrFail($accountId) : null;
        $lines = JournalEntryLine::query()->with(['account', 'journalEntry'])
            ->whereHas('journalEntry', fn (Builder $journal) => $journal->where('status', 'posted'))
            ->when($account, fn (Builder $line) => $line->where('accounting_account_id', $account->id));
        if (! empty($filters['from'])) $lines->whereHas('journalEntry', fn (Builder $journal) => $journal->whereDate('entry_date', '>=', $filters['from']));
        if (! empty($filters['to'])) $lines->whereHas('journalEntry', fn (Builder $journal) => $journal->whereDate('entry_date', '<=', $filters['to']));
        $rows = $lines->get()->sortBy(fn ($line) => $line->journalEntry->entry_date->format('Y-m-d').'-'.$line->id)->values();
        $balance = 0.0;
        $rows->each(function ($line) use (&$balance) { $balance += (float) $line->debit - (float) $line->credit; $line->running_balance = $balance; });
        return compact('account', 'rows', 'balance');
    }

    public function trialBalance(array $filters = []): array
    {
        $lines = JournalEntryLine::query()->with('account')->whereHas('journalEntry', fn (Builder $journal) => $journal->where('status', 'posted'));
        if (! empty($filters['from'])) $lines->whereHas('journalEntry', fn (Builder $journal) => $journal->whereDate('entry_date', '>=', $filters['from']));
        if (! empty($filters['to'])) $lines->whereHas('journalEntry', fn (Builder $journal) => $journal->whereDate('entry_date', '<=', $filters['to']));
        $rows = $lines->get()->groupBy('accounting_account_id')->map(function ($items) {
            $debit = (float) $items->sum('debit'); $credit = (float) $items->sum('credit'); $account = $items->first()->account;
            return compact('account', 'debit', 'credit');
        })->values();
        return ['rows' => $rows, 'total_debit' => $rows->sum('debit'), 'total_credit' => $rows->sum('credit')];
    }

    public function profitLoss(array $filters = []): array
    {
        $trial = $this->trialBalance($filters);
        $amount = fn (string $code) => optional($trial['rows']->first(fn ($row) => $row['account']?->code === $code));
        $revenue = (float) ($amount('4000')->credit ?? 0) - (float) ($amount('4000')->debit ?? 0);
        $cogs = (float) ($amount('5000')->debit ?? 0) - (float) ($amount('5000')->credit ?? 0);
        $expenses = (float) ($amount('6000')->debit ?? 0) - (float) ($amount('6000')->credit ?? 0);
        return compact('revenue', 'cogs', 'expenses') + ['gross_profit' => $revenue - $cogs, 'net_profit' => $revenue - $cogs - $expenses, 'journals_complete' => $trial['rows']->isNotEmpty()];
    }

    public function vatSnapshotRows(array $filters = []): Builder
    {
        $query = TaxSnapshot::query()->latest();
        foreach (['tax_type', 'tax_rate_id', 'source_type', 'price_mode'] as $filter) if (! empty($filters[$filter])) $query->where($filter, $filters[$filter]);
        if (! empty($filters['from'])) $query->whereDate('created_at', '>=', $filters['from']);
        if (! empty($filters['to'])) $query->whereDate('created_at', '<=', $filters['to']);
        return $query;
    }

    public function vatAuditSummary(): array
    {
        $snapshotted = TaxSnapshot::query()->where('source_type', OrderDetail::class)->pluck('source_id');
        return [
            'rates' => TaxRate::count(),
            'default_price_mode' => config('coremarket.vat.default_price_mode', 'exclusive'),
            'order_details_missing_snapshot' => OrderDetail::query()->where('tax', '!=', 0)->whereNotIn('id', $snapshotted)->count(),
            'return_tax_rows' => SalesReturnItem::query()->where('tax_amount', '!=', 0)->count(),
            'snapshots' => TaxSnapshot::count(),
        ];
    }
}
