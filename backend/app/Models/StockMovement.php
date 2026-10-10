<?php
namespace App\Models;

class StockMovement extends LedgerModel
{
    const UPDATED_AT = null;
    public function product() { return $this->belongsTo(Product::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
}
