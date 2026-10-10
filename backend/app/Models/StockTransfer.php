<?php
namespace App\Models;

class StockTransfer extends BaseModel
{
    public function items() { return $this->hasMany(StockTransferItem::class); }
    public function from() { return $this->belongsTo(Warehouse::class, "from_warehouse_id"); }
    public function to() { return $this->belongsTo(Warehouse::class, "to_warehouse_id"); }
}
