<?php
namespace App\Models;

class StockAdjustment extends BaseModel
{
    public function product() { return $this->belongsTo(Product::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
}
