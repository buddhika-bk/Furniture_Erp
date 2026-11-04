<?php
namespace App\Models;

class Payment extends BaseModel
{
    public function invoice() { return $this->belongsTo(Invoice::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function account() { return $this->belongsTo(Account::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function getPaymentDateAttribute($value): ?string {
        return $value ?: $this->created_at?->copy()->timezone('Asia/Colombo')->toDateString();
    }
}
