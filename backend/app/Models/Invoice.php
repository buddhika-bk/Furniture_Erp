<?php
namespace App\Models;

class Invoice extends BaseModel
{
    protected $appends = ['payment_status'];
    public function getPaymentStatusAttribute(): string {
        return (float) ($this->is_order ? $this->balance_due : $this->credit_amount) > 0 ? 'pending' : 'completed';
    }
    protected $casts = ['loyalty_rule_snapshot'=>'array','service_charges'=>'array','customer_snapshot' => 'array', 'shop_snapshot' => 'array', 'is_order' => 'boolean', 'is_special' => 'boolean'];
    public function manufacturingRequests() { return $this->hasMany(ManufacturingRequest::class); }
    public function items() { return $this->hasMany(InvoiceItem::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function salesperson() { return $this->belongsTo(Employee::class, "salesperson_id"); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function payments() { return $this->hasMany(Payment::class); }
}
