<?php
namespace App\Models;

class InvoiceItem extends BaseModel
{
    protected $casts = ['warranty_snapshot'=>'array','special_bom'=>'boolean'];
    public $timestamps = false;
    public function product() { return $this->belongsTo(Product::class); }
}
