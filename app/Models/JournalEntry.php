<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class JournalEntry extends Model { protected $guarded = []; protected $casts = ['entry_date'=>'date','total_debit'=>'decimal:6','total_credit'=>'decimal:6','posted_at'=>'datetime','metadata'=>'array']; public function lines(){ return $this->hasMany(JournalEntryLine::class); } }
