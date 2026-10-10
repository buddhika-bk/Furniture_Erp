<?php
namespace App\Models;

class SalesReturn extends BaseModel
{
    public function invoice() { return $this->belongsTo(Invoice::class); }
    public function items() { return $this->hasMany(SalesReturnItem::class); }
}
