<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AccountingEvent extends Model { protected $guarded = []; protected $casts = ['amount'=>'decimal:6','cost_amount'=>'decimal:6','tax_amount'=>'decimal:6','profit_amount'=>'decimal:6','occurred_at'=>'datetime','posted_at'=>'datetime','metadata'=>'array']; }
