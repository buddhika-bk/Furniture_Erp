<?php
namespace App\Models;

class PurchaseOrder extends BaseModel
{
    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function items() { return $this->hasMany(PurchaseOrderItem::class); }
}
