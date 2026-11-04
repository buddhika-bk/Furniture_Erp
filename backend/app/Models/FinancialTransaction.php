<?php
namespace App\Models;

class FinancialTransaction extends LedgerModel
{
    const UPDATED_AT = null;
    public function account() { return $this->belongsTo(Account::class); }
    public function payment() { return $this->belongsTo(Payment::class); }
    public function user() { return $this->belongsTo(User::class); }
}
