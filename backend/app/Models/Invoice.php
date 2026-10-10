<?php
namespace App\Models;

class Invoice extends BaseModel
{
    public function items() { return $this->hasMany(InvoiceItem::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function salesperson() { return $this->belongsTo(Employee::class, "salesperson_id"); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function payments() { return $this->hasMany(Payment::class); }
}
