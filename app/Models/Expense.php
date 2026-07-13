<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Expense extends Model { protected $guarded = []; protected $casts = ['amount'=>'decimal:6','expense_date'=>'date','metadata'=>'array']; public function category(){ return $this->belongsTo(ExpenseCategory::class, 'expense_category_id'); } public function accountingEvents(){ return $this->morphMany(AccountingEvent::class, 'reference'); } }
