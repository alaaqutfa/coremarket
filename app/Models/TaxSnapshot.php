<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class TaxSnapshot extends Model { protected $guarded = []; protected $casts = ['rate'=>'decimal:4','taxable_amount'=>'decimal:6','tax_amount'=>'decimal:6','total_with_tax'=>'decimal:6','metadata'=>'array']; }
