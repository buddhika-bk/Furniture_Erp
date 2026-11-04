<?php
namespace App\Models;
class Quotation extends BaseModel {
    protected $casts = ['customer_snapshot' => 'array', 'shop_snapshot' => 'array'];
    public function items() { return $this->hasMany(QuotationItem::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
}
