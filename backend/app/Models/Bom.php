<?php
namespace App\Models;

class Bom extends BaseModel
{
    public function product() { return $this->belongsTo(Product::class); }
    public function items() { return $this->hasMany(BomItem::class); }
}
