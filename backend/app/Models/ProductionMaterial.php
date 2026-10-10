<?php
namespace App\Models;

class ProductionMaterial extends BaseModel
{
    public $timestamps = false;
    public function product() { return $this->belongsTo(Product::class); }
}
