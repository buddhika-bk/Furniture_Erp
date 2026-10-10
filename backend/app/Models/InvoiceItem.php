<?php
namespace App\Models;

class InvoiceItem extends BaseModel
{
    public $timestamps = false;
    public function product() { return $this->belongsTo(Product::class); }
}
