<?php
namespace App\Models;

class SupplierInvoice extends BaseModel
{
    public function supplier() { return $this->belongsTo(Supplier::class); }
}
