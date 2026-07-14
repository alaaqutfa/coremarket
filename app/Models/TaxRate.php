<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class TaxRate extends Model { protected $guarded = []; protected $casts = ['rate'=>'decimal:4','is_active'=>'boolean','starts_at'=>'date','ends_at'=>'date','metadata'=>'array']; }
