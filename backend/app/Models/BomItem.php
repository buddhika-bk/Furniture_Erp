<?php
namespace App\Models;

class BomItem extends BaseModel
{
    public $timestamps = false;
    public function product() { return $this->belongsTo(Product::class); }
}
