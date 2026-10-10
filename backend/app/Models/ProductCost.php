<?php
namespace App\Models;

class ProductCost extends BaseModel
{
    public function product() { return $this->belongsTo(Product::class); }
}
