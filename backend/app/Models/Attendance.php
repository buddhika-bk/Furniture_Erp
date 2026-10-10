<?php
namespace App\Models;

class Attendance extends BaseModel
{
    protected $table = "attendance";
    public function employee() { return $this->belongsTo(Employee::class); }
}
