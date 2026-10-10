<?php
namespace App\Models;

class PayrollPeriod extends BaseModel
{
    public function payslips() { return $this->hasMany(Payslip::class); }
}
