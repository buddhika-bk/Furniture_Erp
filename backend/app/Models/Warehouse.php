<?php
namespace App\Models;

class Warehouse extends BaseModel
{
    public function branch() { return $this->belongsTo(Branch::class); }
}
