<?php
namespace App\Models;

class Leave extends BaseModel
{
    public function employee() { return $this->belongsTo(Employee::class); }
}
