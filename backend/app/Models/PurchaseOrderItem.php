<?php
namespace App\Models;

class PurchaseOrderItem extends BaseModel
{
    public $timestamps = false;
    public function product() { return $this->belongsTo(Product::class); }
}
