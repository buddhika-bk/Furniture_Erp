<?php
namespace App\Models;

class StockBalance extends LedgerModel
{
    public function product() { return $this->belongsTo(Product::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
}
