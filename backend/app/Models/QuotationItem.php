<?php
namespace App\Models;
class QuotationItem extends BaseModel {
    public $timestamps = false;
    public function product() { return $this->belongsTo(Product::class); }
}
