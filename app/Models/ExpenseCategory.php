<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ExpenseCategory extends Model { protected $guarded = []; protected $casts = ['is_active'=>'boolean','metadata'=>'array']; public function expenses(){ return $this->hasMany(Expense::class); } }
