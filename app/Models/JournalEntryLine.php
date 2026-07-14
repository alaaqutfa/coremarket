<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class JournalEntryLine extends Model { protected $guarded = []; protected $casts = ['debit'=>'decimal:6','credit'=>'decimal:6','metadata'=>'array']; public function account(){ return $this->belongsTo(AccountingAccount::class, 'accounting_account_id'); } public function journalEntry(){ return $this->belongsTo(JournalEntry::class); } }
