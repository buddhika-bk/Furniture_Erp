<?php
namespace App\Models;

class Product extends BaseModel
{
    public function warranty() { return $this->belongsTo(Warranty::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function stockBalances() { return $this->hasMany(StockBalance::class); }
    public function category() { return $this->belongsTo(Category::class); }
    public function unit() { return $this->belongsTo(Unit::class); }
}
