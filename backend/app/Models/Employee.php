<?php
namespace App\Models;

class Employee extends BaseModel
{
    public function department() { return $this->belongsTo(Department::class); }
    public function position() { return $this->belongsTo(Position::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
}
