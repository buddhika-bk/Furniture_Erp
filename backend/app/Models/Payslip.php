<?php
namespace App\Models;

class Payslip extends BaseModel
{
    public function employee() { return $this->belongsTo(Employee::class); }
}
