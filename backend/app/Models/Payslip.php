<?php
namespace App\Models;

class Payslip extends BaseModel
{
    protected $casts = ['attendance_snapshot' => 'array'];
    public function employee() { return $this->belongsTo(Employee::class); }
}
