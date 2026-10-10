<?php
namespace App\Models;

class CashierSession extends BaseModel
{
    public function branch() { return $this->belongsTo(Branch::class); }
    public function user() { return $this->belongsTo(User::class); }
}
