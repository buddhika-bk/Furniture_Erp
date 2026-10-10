<?php
namespace App\Models;

class Commission extends BaseModel
{
    public function employee() { return $this->belongsTo(Employee::class); }
    public function invoice() { return $this->belongsTo(Invoice::class); }
}
