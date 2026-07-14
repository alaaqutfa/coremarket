<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AccountingAccount extends Model { protected $guarded = []; protected $casts = ['is_system'=>'boolean','is_active'=>'boolean','metadata'=>'array']; }
