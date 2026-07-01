<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\PreventDemoModeChanges;

class SellerRequest extends Model
{
  use PreventDemoModeChanges;
  protected $fillable = ['seller_id', 'kind', 'name', 'approved'];
  public function seller(){
    return $this->belongsTo(User::class);
}

}